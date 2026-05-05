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
        $st = $pdo->prepare('SELECT id, name, start_date, end_date, created_at FROM projects WHERE org_id = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$orgId]);
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
        if ($projectId <= 0) {
            return false;
        }
        $st = $pdo->prepare('SELECT 1 FROM projects WHERE id = ? AND org_id = ?');
        $st->execute([$projectId, $orgId]);
        return (bool) $st->fetchColumn();
    }

    /**
     * @param array<int,int> $memberIds
     * @return array{success:bool,project_id?:int,error?:string}
     */
    public static function createProject(
        PDO $pdo,
        int $orgId,
        int $createdByUserId,
        string $projectName,
        string $startDate,
        ?string $endDate,
        array $memberIds
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
            self::assignMembers($pdo, $projectId, $createdByUserId, $memberIds);
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
     */
    public static function assignMembers(PDO $pdo, int $projectId, int $assignedBy, array $memberIds): void
    {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO project_members (project_id, user_id, assigned_by)
             VALUES (?,?,?)'
        );
        foreach ($memberIds as $userId) {
            if ((int) $userId <= 0) {
                continue;
            }
            $ins->execute([$projectId, (int) $userId, $assignedBy]);
        }
    }

    public static function resolveActiveProjectId(PDO $pdo, int $orgId, int $userId, string $role, ?int $sessionProjectId): ?int
    {
        if ($sessionProjectId !== null && self::canAccessProject($pdo, $sessionProjectId, $orgId, $userId, $role)) {
            return $sessionProjectId;
        }
        $st = $pdo->prepare('SELECT id FROM projects WHERE org_id = ? ORDER BY created_at ASC, id ASC LIMIT 1');
        $st->execute([$orgId]);
        $id = (int) $st->fetchColumn();
        return $id > 0 ? $id : null;
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
     * @return array<int,string>
     */
    public static function purposeMapFromProject(PDO $pdo, int $orgId, int $projectId): array
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
            $name = mb_strtolower(trim((string) ($row['vendor_name'] ?? '')), 'UTF-8');
            $purpose = trim((string) ($row['purpose_of_subscription'] ?? ''));
            if ($name !== '' && $purpose !== '') {
                $map[$name] = $purpose;
            }
        }
        return $map;
    }

    public static function copyProjectData(PDO $pdo, int $orgId, int $fromProjectId, int $toProjectId, int $actingUserId): array
    {
        if ($fromProjectId <= 0 || $toProjectId <= 0 || $fromProjectId === $toProjectId) {
            return ['success' => false, 'error' => 'Invalid source or target project.'];
        }
        $email = self::userEmail($pdo, $actingUserId);
        $sql = 'INSERT INTO cost_calculator_items
            (org_id, project_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date)
            SELECT org_id, :to_project_id, :acting_user_id, :acting_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date
            FROM cost_calculator_items
            WHERE org_id = :org_id AND project_id = :from_project_id';
        try {
            $st = $pdo->prepare($sql);
            $st->execute([
                ':to_project_id' => $toProjectId,
                ':acting_user_id' => $actingUserId,
                ':acting_email' => $email,
                ':org_id' => $orgId,
                ':from_project_id' => $fromProjectId,
            ]);
            return ['success' => true, 'copied' => $st->rowCount()];
        } catch (PDOException $e) {
            error_log('ProjectService::copyProjectData: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not copy project data.'];
        }
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
        $st = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND id IN ($in)");
        $st->execute($params);
        $valid = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $valid[] = (int) $row['id'];
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
