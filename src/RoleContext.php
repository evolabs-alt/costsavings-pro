<?php

declare(strict_types=1);

namespace CostSavings;

use PDO;

final class RoleContext
{
    /**
     * @return array<int, array{id:int, name:string, role:string}>
     */
    public static function listUserOrganizations(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $st = $pdo->prepare(
            'SELECT o.id, o.name, uo.role
             FROM user_organizations uo
             INNER JOIN organizations o ON o.id = uo.org_id
             WHERE uo.user_id = ? AND uo.is_disabled = 0
             ORDER BY o.name ASC, o.id ASC'
        );
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, int>
     */
    public static function userOrgIds(PDO $pdo, int $userId): array
    {
        $orgs = self::listUserOrganizations($pdo, $userId);
        $ids = [];
        foreach ($orgs as $org) {
            $ids[] = (int) ($org['id'] ?? 0);
        }

        return array_values(array_filter($ids, static function (int $id): bool {
            return $id > 0;
        }));
    }

    public static function orgRole(PDO $pdo, int $userId, int $orgId): ?string
    {
        if ($userId <= 0 || $orgId <= 0) {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT role FROM user_organizations WHERE user_id = ? AND org_id = ? AND is_disabled = 0 LIMIT 1'
        );
        $st->execute([$userId, $orgId]);
        $role = $st->fetchColumn();

        return is_string($role) && $role !== '' ? $role : null;
    }

    public static function projectRole(PDO $pdo, int $userId, int $projectId): ?string
    {
        if ($userId <= 0 || $projectId <= 0) {
            return null;
        }
        $st = $pdo->prepare(
            'SELECT pm.role
             FROM project_members pm
             WHERE pm.user_id = ? AND pm.project_id = ?
             LIMIT 1'
        );
        $st->execute([$userId, $projectId]);
        $role = $st->fetchColumn();

        return is_string($role) && $role !== '' ? $role : null;
    }

    public static function requireOrgPrivileged(PDO $pdo, int $userId, int $orgId): bool
    {
        $role = self::orgRole($pdo, $userId, $orgId);

        return $role !== null && OrgRole::isPrivileged($role);
    }

    public static function requireProjectAccess(PDO $pdo, int $userId, int $projectId): ?string
    {
        return self::projectRole($pdo, $userId, $projectId);
    }

    public static function isOrgMemberDisabled(PDO $pdo, int $userId, int $orgId): bool
    {
        if ($userId <= 0 || $orgId <= 0) {
            return true;
        }
        $st = $pdo->prepare(
            'SELECT is_disabled FROM user_organizations WHERE user_id = ? AND org_id = ? LIMIT 1'
        );
        $st->execute([$userId, $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }

        return (int) ($row['is_disabled'] ?? 0) === 1;
    }

    /**
     * Resolve default org for a user (last used, or first membership).
     */
    public static function resolveDefaultOrgId(PDO $pdo, int $userId): int
    {
        $orgIds = self::userOrgIds($pdo, $userId);
        if (count($orgIds) === 0) {
            return 0;
        }
        $st = $pdo->prepare('SELECT last_org_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $lastOrgId = (int) ($st->fetchColumn() ?: 0);
        if ($lastOrgId > 0 && in_array($lastOrgId, $orgIds, true)) {
            return $lastOrgId;
        }

        return $orgIds[0];
    }

    public static function persistLastOrgId(PDO $pdo, int $userId, int $orgId): void
    {
        if ($userId <= 0 || $orgId <= 0) {
            return;
        }
        $st = $pdo->prepare('UPDATE users SET last_org_id = ? WHERE id = ?');
        $st->execute([$orgId, $userId]);
    }

    /**
     * Write org_role, project_role, and role (alias) into session.
     */
    public static function syncSession(PDO $pdo, int $userId, int $orgId, ?int $projectId): void
    {
        $orgRole = self::orgRole($pdo, $userId, $orgId);
        $_SESSION['org_id'] = $orgId;
        $_SESSION['org_role'] = $orgRole ?? 'member';

        $projectRole = null;
        if ($projectId !== null && $projectId > 0) {
            $projectRole = self::projectRole($pdo, $userId, $projectId);
        }
        if ($projectRole !== null) {
            $_SESSION['active_project_id'] = $projectId;
            $_SESSION['project_role'] = $projectRole;
            $_SESSION['role'] = $projectRole;
        } else {
            unset($_SESSION['active_project_id'], $_SESSION['project_role']);
            $_SESSION['role'] = $orgRole ?? 'member';
        }
    }

    public static function sessionOrgRole(): string
    {
        return (string) ($_SESSION['org_role'] ?? $_SESSION['role'] ?? 'member');
    }

    public static function sessionProjectRole(): string
    {
        return (string) ($_SESSION['project_role'] ?? $_SESSION['role'] ?? 'member');
    }

    /**
     * Upsert org membership (invite accept, SSO, etc.).
     */
    public static function upsertOrgMembership(
        PDO $pdo,
        int $userId,
        int $orgId,
        string $role,
        int $isDisabled = 0
    ): void {
        $role = strtolower(trim($role));
        if (!in_array($role, [OrgRole::ROLE_SUPER_ADMIN, OrgRole::ROLE_ADMIN, OrgRole::ROLE_MEMBER], true)) {
            $role = OrgRole::ROLE_MEMBER;
        }
        $st = $pdo->prepare(
            'INSERT INTO user_organizations (user_id, org_id, role, is_disabled)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), is_disabled = VALUES(is_disabled)'
        );
        $st->execute([$userId, $orgId, $role, $isDisabled]);
    }
}
