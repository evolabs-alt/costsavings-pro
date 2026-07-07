<?php

namespace CostSavings;

use PDO;
use PDOException;

class CategoryService
{
    /**
     * @return array<int, array{id:int, name:string}>
     */
    public static function listForProject(PDO $pdo, int $orgId, int $projectId): array
    {
        if ($projectId <= 0 || $orgId < 1) {
            return [];
        }
        $st = $pdo->prepare(
            'SELECT id, name FROM project_categories
             WHERE org_id = ? AND project_id = ?
             ORDER BY name ASC, id ASC'
        );
        $st->execute([$orgId, $projectId]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{id:int, name:string, created:bool}|null
     */
    public static function findOrCreateByName(PDO $pdo, int $orgId, int $projectId, string $name): ?array
    {
        $trimmed = trim($name);
        if ($trimmed === '' || $projectId <= 0 || $orgId < 1) {
            return null;
        }
        if (mb_strlen($trimmed) > 255) {
            $trimmed = mb_substr($trimmed, 0, 255);
        }

        $st = $pdo->prepare(
            'SELECT id, name FROM project_categories
             WHERE org_id = ? AND project_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?))
             LIMIT 1'
        );
        $st->execute([$orgId, $projectId, $trimmed]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return [
                'id' => (int) ($existing['id'] ?? 0),
                'name' => (string) ($existing['name'] ?? $trimmed),
                'created' => false,
            ];
        }

        try {
            $ins = $pdo->prepare(
                'INSERT INTO project_categories (org_id, project_id, name) VALUES (?, ?, ?)'
            );
            $ins->execute([$orgId, $projectId, $trimmed]);
            $id = (int) $pdo->lastInsertId();
            if ($id <= 0) {
                return null;
            }

            return ['id' => $id, 'name' => $trimmed, 'created' => true];
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
                $st->execute([$orgId, $projectId, $trimmed]);
                $existing = $st->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    return [
                        'id' => (int) ($existing['id'] ?? 0),
                        'name' => (string) ($existing['name'] ?? $trimmed),
                        'created' => false,
                    ];
                }
            }
            error_log('CategoryService::findOrCreateByName: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function resolveCategoryIdFromItem(PDO $pdo, int $orgId, int $projectId, array $item): ?int
    {
        if (isset($item['category_name']) && is_string($item['category_name'])) {
            $name = trim($item['category_name']);
            if ($name !== '') {
                $created = self::findOrCreateByName($pdo, $orgId, $projectId, $name);
                return $created ? $created['id'] : null;
            }
        }

        if (!array_key_exists('category_id', $item) || $item['category_id'] === '' || $item['category_id'] === null) {
            return null;
        }

        $id = (int) $item['category_id'];
        if ($id <= 0) {
            return null;
        }

        $st = $pdo->prepare(
            'SELECT id FROM project_categories WHERE id = ? AND org_id = ? AND project_id = ? LIMIT 1'
        );
        $st->execute([$id, $orgId, $projectId]);

        return $st->fetchColumn() ? $id : null;
    }

    /**
     * @return array<string, string> normalized vendor name => category name
     */
    public static function categoryAssignmentMapFromProject(PDO $pdo, int $orgId, int $projectId, string $role): array
    {
        if ($projectId <= 0) {
            return [];
        }
        $visibilityFilter = OrgRole::isSuperAdmin($role) ? '' : ' AND cci.visibility = \'public\'';
        $sql = 'SELECT cci.vendor_name, pc.name AS category_name
                FROM cost_calculator_items cci
                INNER JOIN project_categories pc ON pc.id = cci.category_id AND pc.project_id = cci.project_id
                WHERE cci.org_id = ? AND cci.project_id = ? AND cci.category_id IS NOT NULL' . $visibilityFilter;
        $st = $pdo->prepare($sql);
        $st->execute([$orgId, $projectId]);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = VendorService::normalizeVendorName((string) ($row['vendor_name'] ?? ''));
            if ($norm === '') {
                continue;
            }
            $catName = trim((string) ($row['category_name'] ?? ''));
            if ($catName !== '') {
                $map[$norm] = $catName;
            }
        }

        return $map;
    }

    /**
     * Copies vendor category assignments from source to target project, matched by vendor name.
     * Creates category records in the target project only when needed for an assignment.
     *
     * @return array{success:bool, updated?:int, matched?:int, skipped_no_category?:int, error?:string}
     */
    public static function copyAssignmentsBetweenProjects(
        PDO $pdo,
        int $orgId,
        int $fromProjectId,
        int $toProjectId,
        int $userId,
        string $role
    ): array {
        if ($fromProjectId <= 0 || $toProjectId <= 0 || $fromProjectId === $toProjectId) {
            return ['success' => false, 'error' => 'Invalid source or target project.'];
        }

        $categoryMap = self::categoryAssignmentMapFromProject($pdo, $orgId, $fromProjectId, $role);
        if (count($categoryMap) === 0) {
            return ['success' => true, 'updated' => 0, 'matched' => 0, 'skipped_no_category' => 0];
        }

        $st = $pdo->prepare(
            'SELECT id, vendor_name FROM cost_calculator_items WHERE org_id = ? AND project_id = ?'
        );
        $st->execute([$orgId, $toProjectId]);
        $updates = [];
        $matched = 0;
        $skippedNoCategory = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = VendorService::normalizeVendorName((string) ($row['vendor_name'] ?? ''));
            if ($norm === '') {
                continue;
            }
            if (!isset($categoryMap[$norm])) {
                ++$skippedNoCategory;
                continue;
            }
            $cat = self::findOrCreateByName($pdo, $orgId, $toProjectId, $categoryMap[$norm]);
            if (!$cat) {
                continue;
            }
            $updates[] = [
                'id' => (int) ($row['id'] ?? 0),
                'category_id' => $cat['id'],
            ];
            ++$matched;
        }

        if (count($updates) === 0) {
            return [
                'success' => true,
                'updated' => 0,
                'matched' => 0,
                'skipped_no_category' => $skippedNoCategory,
            ];
        }

        $updated = self::updateCategoriesForVisibleRows($pdo, $orgId, $toProjectId, $userId, $role, $updates);

        return [
            'success' => true,
            'updated' => $updated,
            'matched' => $matched,
            'skipped_no_category' => $skippedNoCategory,
        ];
    }

    /**
     * @param array<int, array{id:int, category_id:int|null}> $updates
     */
    public static function updateCategoriesForVisibleRows(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $userId,
        string $role,
        array $updates
    ): int {
        if (count($updates) === 0) {
            return 0;
        }
        $visible = VendorService::loadVisibleItems($pdo, $userId, $orgId, $projectId, $role);
        $allowedIds = [];
        foreach ($visible as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (int) ($it['id'] ?? 0);
            if ($id > 0) {
                $allowedIds[$id] = true;
            }
        }

        $upd = $pdo->prepare(
            'UPDATE cost_calculator_items SET category_id = ? WHERE id = ? AND org_id = ? AND project_id = ?'
        );
        $count = 0;
        foreach ($updates as $u) {
            $id = (int) ($u['id'] ?? 0);
            if ($id <= 0 || !isset($allowedIds[$id])) {
                continue;
            }
            $catId = isset($u['category_id']) && $u['category_id'] !== null ? (int) $u['category_id'] : null;
            $upd->execute([$catId, $id, $orgId, $projectId]);
            $count += $upd->rowCount();
        }

        return $count;
    }
}
