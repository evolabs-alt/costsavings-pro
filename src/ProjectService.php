<?php

namespace CostSavings;

use DateTimeImmutable;
use PDO;
use PDOException;

class ProjectService
{
    /**
     * @return array<int, array<string,mixed>>
     */
    public static function listForUser(PDO $pdo, int $orgId, int $userId, string $role): array
    {
        $st = $pdo->prepare(
            'SELECT p.id, p.name, p.start_date, p.end_date, p.created_at, pm.role AS member_role
             FROM projects p
             INNER JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
             WHERE p.org_id = ?
             ORDER BY p.created_at ASC, p.id ASC'
        );
        $st->execute([$userId, $orgId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    public static function orgProjectCount(PDO $pdo, int $orgId): int
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM projects WHERE org_id = ?');
        $st->execute([$orgId]);
        return (int) $st->fetchColumn();
    }

    public static function canAccessProject(PDO $pdo, int $projectId, int $orgId, int $userId, string $role): bool
    {
        if ($projectId <= 0 || $userId <= 0) {
            return false;
        }
        $st = $pdo->prepare(
            'SELECT 1
             FROM projects p
             INNER JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
             WHERE p.id = ? AND p.org_id = ?
             LIMIT 1'
        );
        $st->execute([$userId, $projectId, $orgId]);

        return (bool) $st->fetchColumn();
    }

    /**
     * @param array<int,int> $memberIds
     * @param array<int,string> $memberRoles user_id => role
     * @return array{success:bool,project_id?:int,error?:string}
     */
    public static function createProject(
        PDO $pdo,
        int $orgId,
        int $createdByUserId,
        string $projectName,
        string $startDate,
        ?string $endDate,
        array $memberIds,
        array $memberRoles = []
    ): array {
        $name = trim($projectName);
        if ($name === '') {
            return ['success' => false, 'error' => 'Project name is required.'];
        }
        if (!self::isDate($startDate)) {
            return ['success' => false, 'error' => 'Start date must be YYYY-MM-DD.'];
        }
        if ($endDate !== null && $endDate !== '' && !self::isDate($endDate)) {
            return ['success' => false, 'error' => 'End date must be YYYY-MM-DD.'];
        }
        if ($endDate !== null && $endDate !== '' && $endDate < $startDate) {
            return ['success' => false, 'error' => 'End date cannot be before start date.'];
        }
        $memberIds = self::filterOrgUsers($pdo, $orgId, $memberIds);
        if (!in_array($createdByUserId, $memberIds, true)) {
            $memberIds[] = $createdByUserId;
        }
        $memberRoles[$createdByUserId] = OrgRole::ROLE_SUPER_ADMIN;

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO projects (org_id, name, start_date, end_date, created_by)
                 VALUES (:org_id, :name, :start_date, :end_date, :created_by)'
            );
            $st->execute([
                ':org_id' => $orgId,
                ':name' => $name,
                ':start_date' => $startDate,
                ':end_date' => ($endDate === null || $endDate === '') ? null : $endDate,
                ':created_by' => $createdByUserId,
            ]);
            $projectId = (int) $pdo->lastInsertId();
            self::assignMembers($pdo, $projectId, $createdByUserId, $memberIds, $memberRoles);
            $pdo->commit();
            return ['success' => true, 'project_id' => $projectId];
        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = $e->getMessage();
            error_log('ProjectService::createProject: ' . $msg);
            if (stripos($msg, 'uk_projects_org_name') !== false
                || (stripos($msg, '1062') !== false && stripos($msg, 'Duplicate') !== false)) {
                return ['success' => false, 'error' => 'A project with this name already exists.'];
            }
            if (stripos($msg, '1452') !== false || stripos($msg, 'foreign key constraint') !== false) {
                return ['success' => false, 'error' => 'Could not create project: check that your account is assigned to an organization.'];
            }
            return ['success' => false, 'error' => 'Could not create project.'];
        }
    }

    /**
     * @param array<int,int> $memberIds
     * @param array<int,string> $memberRoles user_id => role
     */
    public static function assignMembers(PDO $pdo, int $projectId, int $assignedBy, array $memberIds, array $memberRoles = []): void
    {
        $ins = $pdo->prepare(
            'INSERT INTO project_members (project_id, user_id, role, assigned_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), assigned_by = VALUES(assigned_by)'
        );
        foreach ($memberIds as $userId) {
            if ((int) $userId <= 0) {
                continue;
            }
            $uid = (int) $userId;
            $role = strtolower(trim((string) ($memberRoles[$uid] ?? OrgRole::ROLE_MEMBER)));
            if (!in_array($role, [OrgRole::ROLE_SUPER_ADMIN, OrgRole::ROLE_ADMIN, OrgRole::ROLE_MEMBER], true)) {
                $role = OrgRole::ROLE_MEMBER;
            }
            $ins->execute([$projectId, $uid, $role, $assignedBy]);
        }
    }

    public static function resolveActiveProjectId(PDO $pdo, int $orgId, int $userId, string $role, ?int $sessionProjectId): ?int
    {
        if ($sessionProjectId !== null && self::canAccessProject($pdo, $sessionProjectId, $orgId, $userId, $role)) {
            return $sessionProjectId;
        }
        $st = $pdo->prepare(
            'SELECT p.id
             FROM projects p
             INNER JOIN project_members pm ON pm.project_id = p.id AND pm.user_id = ?
             WHERE p.org_id = ?
             ORDER BY p.created_at ASC, p.id ASC
             LIMIT 1'
        );
        $st->execute([$userId, $orgId]);
        $id = (int) $st->fetchColumn();

        return $id > 0 ? $id : null;
    }

    /**
     * Permanently remove a project and all data scoped to it.
     *
     * @return array{success:bool,error?:string}
     */
    public static function deleteProject(PDO $pdo, int $orgId, int $projectId, int $userId, string $role): array
    {
        if ($projectId <= 0) {
            return ['success' => false, 'error' => 'Invalid project.'];
        }
        if (!self::canAccessProject($pdo, $projectId, $orgId, $userId, $role)) {
            return ['success' => false, 'error' => 'You do not have access to this project.'];
        }
        if (self::orgProjectCount($pdo, $orgId) <= 1) {
            return ['success' => false, 'error' => 'Cannot delete the organization\'s only project.'];
        }

        $pdo->beginTransaction();
        try {
            $idSt = $pdo->prepare('SELECT id FROM cost_calculator_items WHERE org_id = ? AND project_id = ?');
            $idSt->execute([$orgId, $projectId]);
            $vendorItemIds = [];
            while ($row = $idSt->fetch(PDO::FETCH_ASSOC)) {
                $vendorItemIds[] = (int) ($row['id'] ?? 0);
            }

            self::deleteWhereIdIn($pdo, 'reminder_sent', 'vendor_item_id', $vendorItemIds);
            self::deleteWhereIdIn($pdo, 'vendor_item_chat_reads', 'vendor_item_id', $vendorItemIds);

            $delChat = $pdo->prepare('DELETE FROM vendor_item_chat_messages WHERE org_id = ? AND project_id = ?');
            $delChat->execute([$orgId, $projectId]);

            $delRaw = $pdo->prepare('DELETE FROM vendor_raw_transactions WHERE org_id = ? AND project_id = ?');
            $delRaw->execute([$orgId, $projectId]);

            $delCc = $pdo->prepare('DELETE FROM cost_calculator_items WHERE org_id = ? AND project_id = ?');
            $delCc->execute([$orgId, $projectId]);

            $delCats = $pdo->prepare('DELETE FROM project_categories WHERE org_id = ? AND project_id = ?');
            $delCats->execute([$orgId, $projectId]);

            $delProj = $pdo->prepare('DELETE FROM projects WHERE id = ? AND org_id = ?');
            $delProj->execute([$projectId, $orgId]);
            if ($delProj->rowCount() < 1) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Project could not be deleted.'];
            }

            $pdo->commit();
            return ['success' => true];
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('ProjectService::deleteProject: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not delete project.'];
        }
    }

    /**
     * @param array<int,int|string> $ids
     */
    private static function deleteWhereIdIn(PDO $pdo, string $table, string $column, array $ids, int $chunkSize = 300): void
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
            return $v > 0;
        })));
        if (count($clean) === 0) {
            return;
        }
        for ($i = 0, $n = count($clean); $i < $n; $i += $chunkSize) {
            $chunk = array_slice($clean, $i, $chunkSize);
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ($placeholders)");
            $st->execute($chunk);
        }
    }

    /**
     * Ensures old org-scoped rows are attached to the first project when needed.
     */
    public static function backfillNullProjectRows(PDO $pdo, int $orgId, int $projectId): void
    {
        $st = $pdo->prepare('UPDATE cost_calculator_items SET project_id = ? WHERE org_id = ? AND project_id IS NULL');
        $st->execute([$projectId, $orgId]);
        $st2 = $pdo->prepare('UPDATE vendor_raw_transactions SET project_id = ? WHERE org_id = ? AND project_id IS NULL');
        $st2->execute([$projectId, $orgId]);
    }

    /**
     * @return array<string, string> normalized vendor name => purpose
     */
    public static function purposeMapFromProject(PDO $pdo, int $orgId, int $projectId, bool $includeBlankPurposes = false): array
    {
        if ($projectId <= 0) {
            return [];
        }
        $st = $pdo->prepare(
            'SELECT vendor_name, purpose_of_subscription
             FROM cost_calculator_items
             WHERE org_id = ? AND project_id = ?'
        );
        $st->execute([$orgId, $projectId]);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = VendorService::normalizeVendorName((string) ($row['vendor_name'] ?? ''));
            if ($norm === '') {
                continue;
            }
            $purpose = trim((string) ($row['purpose_of_subscription'] ?? ''));
            if ($purpose !== '' || $includeBlankPurposes) {
                $map[$norm] = $purpose;
            }
        }
        return $map;
    }

    public static function copyProjectData(PDO $pdo, int $orgId, int $fromProjectId, int $toProjectId, int $actingUserId, string $actingRole): array
    {
        if ($fromProjectId <= 0 || $toProjectId <= 0 || $fromProjectId === $toProjectId) {
            return ['success' => false, 'error' => 'Invalid source or target project.'];
        }
        $email = self::userEmail($pdo, $actingUserId);
        $visibilityFilter = OrgRole::isSuperAdmin($actingRole) ? '' : ' AND visibility = \'public\'';
        $sql = 'INSERT INTO cost_calculator_items
            (org_id, project_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date)
            SELECT org_id, :to_project_id, :acting_user_id, :acting_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date
            FROM cost_calculator_items
            WHERE org_id = :org_id AND project_id = :from_project_id' . $visibilityFilter;
        try {
            $st = $pdo->prepare($sql);
            $st->execute([
                ':to_project_id' => $toProjectId,
                ':acting_user_id' => $actingUserId,
                ':acting_email' => $email,
                ':org_id' => $orgId,
                ':from_project_id' => $fromProjectId,
            ]);
            $copied = $st->rowCount();
            $catResult = CategoryService::copyAssignmentsBetweenProjects(
                $pdo,
                $orgId,
                $fromProjectId,
                $toProjectId,
                $actingUserId,
                $actingRole
            );
            if (!($catResult['success'] ?? false)) {
                return ['success' => false, 'error' => $catResult['error'] ?? 'Could not copy category assignments.'];
            }
            return ['success' => true, 'copied' => $copied];
        } catch (PDOException $e) {
            error_log('ProjectService::copyProjectData: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not copy project data.'];
        }
    }

    /**
     * Copies purpose_of_subscription from source project rows onto target project rows with matching vendor names.
     *
     * @return array{success:bool, updated?:int, matched?:int, skipped_no_purpose?:int, error?:string}
     */
    public static function copyPurposesBetweenProjects(
        PDO $pdo,
        int $orgId,
        int $fromProjectId,
        int $toProjectId,
        int $userId,
        string $role,
        bool $includeBlankPurposes = false
    ): array {
        if ($fromProjectId <= 0 || $toProjectId <= 0 || $fromProjectId === $toProjectId) {
            return ['success' => false, 'error' => 'Invalid source or target project.'];
        }
        $purposeMap = self::purposeMapFromProject($pdo, $orgId, $fromProjectId, $includeBlankPurposes);
        if (count($purposeMap) === 0) {
            return ['success' => true, 'updated' => 0, 'matched' => 0, 'skipped_no_purpose' => 0];
        }
        $st = $pdo->prepare(
            'SELECT id, vendor_name FROM cost_calculator_items WHERE org_id = ? AND project_id = ?'
        );
        $st->execute([$orgId, $toProjectId]);
        $updates = [];
        $matched = 0;
        $skippedNoPurpose = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = VendorService::normalizeVendorName((string) ($row['vendor_name'] ?? ''));
            if ($norm === '') {
                continue;
            }
            if (!isset($purposeMap[$norm])) {
                ++$skippedNoPurpose;
                continue;
            }
            $updates[] = [
                'id' => (int) ($row['id'] ?? 0),
                'purpose' => $purposeMap[$norm],
            ];
            ++$matched;
        }
        if (count($updates) === 0) {
            return [
                'success' => true,
                'updated' => 0,
                'matched' => 0,
                'skipped_no_purpose' => $skippedNoPurpose,
            ];
        }
        $result = VendorService::updatePurposesForVisibleRows($pdo, $orgId, $toProjectId, $userId, $role, $updates);
        return [
            'success' => true,
            'updated' => (int) ($result['updated'] ?? 0),
            'matched' => $matched,
            'skipped_no_purpose' => $skippedNoPurpose,
        ];
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public static function listProjectMembers(PDO $pdo, int $projectId, int $orgId): array
    {
        $st = $pdo->prepare(
            'SELECT u.id, u.username, u.display_name, u.email, pm.role, pm.user_id
             FROM project_members pm
             INNER JOIN users u ON u.id = pm.user_id
             INNER JOIN projects p ON p.id = pm.project_id
             WHERE pm.project_id = ? AND p.org_id = ?
             ORDER BY u.username, u.email'
        );
        $st->execute([$projectId, $orgId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,int> $memberIds
     * @return array<int,int>
     */
    public static function filterOrgMemberUserIds(PDO $pdo, int $orgId, array $memberIds): array
    {
        return self::filterOrgUsers($pdo, $orgId, $memberIds);
    }

    /**
     * @param array<int,int> $memberIds
     * @return array<int,int>
     */
    private static function filterOrgUsers(PDO $pdo, int $orgId, array $memberIds): array
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $memberIds), function ($v) {
            return $v > 0;
        })));
        if (count($clean) === 0) {
            return [];
        }
        $in = implode(',', array_fill(0, count($clean), '?'));
        $params = array_merge([$orgId], $clean);
        $st = $pdo->prepare("SELECT user_id FROM user_organizations WHERE org_id = ? AND is_disabled = 0 AND user_id IN ($in)");
        $st->execute($params);
        $valid = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $valid[] = (int) $row['user_id'];
        }

        return $valid;
    }

    private static function userEmail(PDO $pdo, int $userId): string
    {
        $st = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$userId]);
        return (string) $st->fetchColumn();
    }

    private static function isDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    }
}
