<?php

namespace CostSavings;

use PDO;
use PDOException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Durable QBO import snapshots and side-by-side compare vs the live cost grid.
 */
class ImportSnapshotService
{
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_ADDED = 'added';

    /**
     * Freeze newly inserted grid rows as a QBO import snapshot batch.
     *
     * @param array<int, int> $sourceItemIds cost_calculator_items.id values just inserted
     * @return array{success:bool, snapshot_id?:int, item_count?:int, error?:string}
     */
    public static function createFromItemIds(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $userId,
        array $sourceItemIds,
        ?string $uploadBatchId = null,
        ?string $rangeStart = null,
        ?string $rangeEnd = null
    ): array {
        $ids = [];
        foreach ($sourceItemIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (count($ids) === 0) {
            return ['success' => true, 'snapshot_id' => 0, 'item_count' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$orgId, $projectId], $ids);
        try {
            $st = $pdo->prepare(
                "SELECT id, vendor_name, cost_per_period, frequency, last_payment_date, account
                 FROM cost_calculator_items
                 WHERE org_id = ? AND project_id = ? AND id IN ($placeholders)
                 ORDER BY id ASC"
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 0) {
                return ['success' => true, 'snapshot_id' => 0, 'item_count' => 0];
            }

            $importedAt = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $batchId = $uploadBatchId !== null && trim($uploadBatchId) !== ''
                ? trim($uploadBatchId)
                : null;
            $rangeStartNorm = self::normDate($rangeStart);
            $rangeEndNorm = self::normDate($rangeEnd);

            $insSnap = $pdo->prepare(
                'INSERT INTO project_qbo_import_snapshots
                    (org_id, project_id, imported_by_user_id, upload_batch_id, imported_at, source, range_start, range_end, item_count)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $insSnap->execute([
                $orgId,
                $projectId,
                $userId > 0 ? $userId : null,
                $batchId,
                $importedAt,
                'qbo',
                $rangeStartNorm,
                $rangeEndNorm,
                count($rows),
            ]);
            $snapshotId = (int) $pdo->lastInsertId();
            if ($snapshotId <= 0) {
                return ['success' => false, 'error' => 'Failed to create import snapshot'];
            }

            $insItem = $pdo->prepare(
                'INSERT INTO project_qbo_import_snapshot_items
                    (snapshot_id, org_id, project_id, source_item_id, imported_at, last_payment_date, account, vendor_name, cost_per_period, frequency)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($rows as $row) {
                $account = isset($row['account']) && $row['account'] !== null
                    ? trim((string) $row['account'])
                    : '';
                $insItem->execute([
                    $snapshotId,
                    $orgId,
                    $projectId,
                    (int) $row['id'],
                    $importedAt,
                    self::normDate($row['last_payment_date'] ?? null),
                    $account !== '' ? $account : null,
                    (string) ($row['vendor_name'] ?? ''),
                    (float) ($row['cost_per_period'] ?? 0),
                    VendorService::normalizeFrequencyValue($row['frequency'] ?? ''),
                ]);
            }

            return [
                'success' => true,
                'snapshot_id' => $snapshotId,
                'item_count' => count($rows),
            ];
        } catch (PDOException $e) {
            error_log('ImportSnapshotService::createFromItemIds: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Failed to save import snapshot'];
        }
    }

    /**
     * @return array<int, array{id:int, imported_at:string, item_count:int, range_start:?string, range_end:?string}>
     */
    public static function listBatches(PDO $pdo, int $orgId, int $projectId): array
    {
        $st = $pdo->prepare(
            'SELECT id, imported_at, item_count, range_start, range_end
             FROM project_qbo_import_snapshots
             WHERE org_id = ? AND project_id = ?
             ORDER BY imported_at DESC, id DESC'
        );
        $st->execute([$orgId, $projectId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'imported_at' => (string) ($row['imported_at'] ?? ''),
                'item_count' => (int) ($row['item_count'] ?? 0),
                'range_start' => isset($row['range_start']) && $row['range_start'] !== null && $row['range_start'] !== ''
                    ? (string) $row['range_start'] : null,
                'range_end' => isset($row['range_end']) && $row['range_end'] !== null && $row['range_end'] !== ''
                    ? (string) $row['range_end'] : null,
            ];
        }

        return $out;
    }

    public static function hasAnySnapshot(PDO $pdo, int $orgId, int $projectId): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM project_qbo_import_snapshots WHERE org_id = ? AND project_id = ? LIMIT 1'
        );
        $st->execute([$orgId, $projectId]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Build side-by-side compare rows for the active project.
     *
     * @return array{
     *   batches: array<int, array<string, mixed>>,
     *   rows: array<int, array<string, mixed>>,
     *   counts: array{added:int, updated:int, deleted:int, unchanged:int, total:int}
     * }
     */
    public static function buildCompare(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $userId,
        string $role,
        ?int $snapshotId = null
    ): array {
        $batches = self::listBatches($pdo, $orgId, $projectId);
        $snapshotItems = self::loadSnapshotItems($pdo, $orgId, $projectId, $snapshotId);
        $liveItems = VendorService::loadVisibleItems($pdo, $userId, $orgId, $projectId, $role);
        $liveById = [];
        foreach ($liveItems as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $liveById[$id] = $item;
            }
        }

        $existingIds = self::existingItemIds($pdo, $orgId, $projectId);
        $snapshotSourceIds = [];
        $rows = [];

        foreach ($snapshotItems as $snap) {
            $sourceId = isset($snap['source_item_id']) && $snap['source_item_id'] !== null
                ? (int) $snap['source_item_id']
                : 0;
            if ($sourceId > 0) {
                $snapshotSourceIds[$sourceId] = true;
            }

            if ($sourceId > 0 && isset($liveById[$sourceId])) {
                $live = $liveById[$sourceId];
                $changed = self::diffFields($snap, $live);
                $status = count($changed) > 0 ? self::STATUS_UPDATED : self::STATUS_UNCHANGED;
                $rows[] = [
                    'status' => $status,
                    'source_item_id' => $sourceId,
                    'changed_fields' => $changed,
                    'snapshot' => self::formatSnapshotSide($snap),
                    'current' => self::formatCurrentSide($live),
                ];
                continue;
            }

            if ($sourceId > 0 && isset($existingIds[$sourceId])) {
                // Row exists but is not visible to this user — omit.
                continue;
            }

            $rows[] = [
                'status' => self::STATUS_DELETED,
                'source_item_id' => $sourceId > 0 ? $sourceId : null,
                'changed_fields' => [],
                'snapshot' => self::formatSnapshotSide($snap),
                'current' => null,
            ];
        }

        // "Added" rows only for the cumulative (all imports) view. A single-batch
        // filter focuses on the fate of that import's rows (matched/updated/deleted).
        if ($snapshotId === null || $snapshotId <= 0) {
            foreach ($liveById as $id => $live) {
                if (isset($snapshotSourceIds[$id])) {
                    continue;
                }
                $rows[] = [
                    'status' => self::STATUS_ADDED,
                    'source_item_id' => $id,
                    'changed_fields' => [],
                    'snapshot' => null,
                    'current' => self::formatCurrentSide($live),
                ];
            }
        }

        usort($rows, [self::class, 'compareRowSort']);

        $counts = [
            'added' => 0,
            'updated' => 0,
            'deleted' => 0,
            'unchanged' => 0,
            'total' => count($rows),
        ];
        foreach ($rows as $r) {
            $s = (string) ($r['status'] ?? '');
            if (isset($counts[$s])) {
                ++$counts[$s];
            }
        }

        return [
            'batches' => $batches,
            'rows' => $rows,
            'counts' => $counts,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $compareRows
     */
    public static function compareSpreadsheetBytes(array $compareRows): string
    {
        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('Compare');
        $rows = [[
            'Status',
            'Snapshot Date/Time',
            'Snapshot Account',
            'Snapshot Vendor',
            'Snapshot Cost',
            'Snapshot Frequency',
            'Current Date/Time',
            'Current Account',
            'Current Vendor',
            'Current Cost',
            'Current Frequency',
        ]];
        foreach ($compareRows as $row) {
            $status = (string) ($row['status'] ?? '');
            $snap = is_array($row['snapshot'] ?? null) ? $row['snapshot'] : null;
            $cur = is_array($row['current'] ?? null) ? $row['current'] : null;
            if ($status === self::STATUS_ADDED) {
                $rows[] = [
                    $status,
                    'added >',
                    'added >',
                    'added >',
                    'added >',
                    'added >',
                    $cur['date_time'] ?? '',
                    $cur['account'] ?? '',
                    $cur['vendor'] ?? '',
                    $cur['cost'] ?? '',
                    $cur['frequency'] ?? '',
                ];
            } elseif ($status === self::STATUS_DELETED) {
                $rows[] = [
                    $status,
                    $snap['date_time'] ?? '',
                    $snap['account'] ?? '',
                    $snap['vendor'] ?? '',
                    $snap['cost'] ?? '',
                    $snap['frequency'] ?? '',
                    '< deleted',
                    '< deleted',
                    '< deleted',
                    '< deleted',
                    '< deleted',
                ];
            } else {
                $rows[] = [
                    $status,
                    $snap['date_time'] ?? '',
                    $snap['account'] ?? '',
                    $snap['vendor'] ?? '',
                    $snap['cost'] ?? '',
                    $snap['frequency'] ?? '',
                    $cur['date_time'] ?? '',
                    $cur['account'] ?? '',
                    $cur['vendor'] ?? '',
                    $cur['cost'] ?? '',
                    $cur['frequency'] ?? '',
                ];
            }
        }
        $sh->fromArray($rows, null, 'A1');

        ob_start();
        $w = new Xlsx($ss);
        $w->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadSnapshotItems(PDO $pdo, int $orgId, int $projectId, ?int $snapshotId): array
    {
        if ($snapshotId !== null && $snapshotId > 0) {
            $st = $pdo->prepare(
                'SELECT id, snapshot_id, source_item_id, imported_at, last_payment_date, account, vendor_name, cost_per_period, frequency
                 FROM project_qbo_import_snapshot_items
                 WHERE org_id = ? AND project_id = ? AND snapshot_id = ?
                 ORDER BY id ASC'
            );
            $st->execute([$orgId, $projectId, $snapshotId]);
        } else {
            $st = $pdo->prepare(
                'SELECT id, snapshot_id, source_item_id, imported_at, last_payment_date, account, vendor_name, cost_per_period, frequency
                 FROM project_qbo_import_snapshot_items
                 WHERE org_id = ? AND project_id = ?
                 ORDER BY imported_at ASC, id ASC'
            );
            $st->execute([$orgId, $projectId]);
        }

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, true>
     */
    private static function existingItemIds(PDO $pdo, int $orgId, int $projectId): array
    {
        $st = $pdo->prepare(
            'SELECT id FROM cost_calculator_items WHERE org_id = ? AND project_id = ?'
        );
        $st->execute([$orgId, $projectId]);
        $out = [];
        while ($id = $st->fetchColumn()) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $snap
     * @param array<string, mixed> $live
     * @return array<int, string>
     */
    private static function diffFields(array $snap, array $live): array
    {
        $changed = [];
        $snapVendor = trim((string) ($snap['vendor_name'] ?? ''));
        $liveVendor = trim((string) ($live['vendor_name'] ?? ''));
        if (strcasecmp($snapVendor, $liveVendor) !== 0) {
            $changed[] = 'vendor';
        }

        $snapAccount = trim((string) ($snap['account'] ?? ''));
        $liveAccount = trim((string) ($live['account'] ?? ''));
        if (strcasecmp($snapAccount, $liveAccount) !== 0) {
            $changed[] = 'account';
        }

        $snapFreq = VendorService::normalizeFrequencyValue($snap['frequency'] ?? '');
        $liveFreq = VendorService::normalizeFrequencyValue($live['frequency'] ?? '');
        if ($snapFreq !== $liveFreq) {
            $changed[] = 'frequency';
        }

        $snapCost = round((float) ($snap['cost_per_period'] ?? 0), 2);
        $liveCost = round((float) ($live['cost_per_period'] ?? 0), 2);
        if ($snapCost !== $liveCost) {
            $changed[] = 'cost';
        }

        $snapPay = self::normDate($snap['last_payment_date'] ?? null) ?? '';
        $livePay = self::normDate($live['last_payment_date'] ?? null) ?? '';
        if ($snapPay !== $livePay) {
            $changed[] = 'date_time';
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $snap
     * @return array{date_time:string, account:string, vendor:string, cost:float, frequency:string, last_payment_date:?string}
     */
    private static function formatSnapshotSide(array $snap): array
    {
        return [
            'date_time' => (string) ($snap['imported_at'] ?? ''),
            'account' => trim((string) ($snap['account'] ?? '')),
            'vendor' => (string) ($snap['vendor_name'] ?? ''),
            'cost' => round((float) ($snap['cost_per_period'] ?? 0), 2),
            'frequency' => VendorService::normalizeFrequencyValue($snap['frequency'] ?? ''),
            'last_payment_date' => self::normDate($snap['last_payment_date'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $live
     * @return array{date_time:string, account:string, vendor:string, cost:float, frequency:string, last_payment_date:?string}
     */
    private static function formatCurrentSide(array $live): array
    {
        $pay = self::normDate($live['last_payment_date'] ?? null);

        return [
            'date_time' => $pay ?? '',
            'account' => trim((string) ($live['account'] ?? '')),
            'vendor' => (string) ($live['vendor_name'] ?? ''),
            'cost' => round((float) ($live['cost_per_period'] ?? 0), 2),
            'frequency' => VendorService::normalizeFrequencyValue($live['frequency'] ?? ''),
            'last_payment_date' => $pay,
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function compareRowSort(array $a, array $b): int
    {
        $order = [
            self::STATUS_DELETED => 0,
            self::STATUS_UPDATED => 1,
            self::STATUS_ADDED => 2,
            self::STATUS_UNCHANGED => 3,
        ];
        $sa = $order[(string) ($a['status'] ?? '')] ?? 9;
        $sb = $order[(string) ($b['status'] ?? '')] ?? 9;
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        $va = strtolower((string) (
            ($a['snapshot']['vendor'] ?? null)
            ?? ($a['current']['vendor'] ?? '')
        ));
        $vb = strtolower((string) (
            ($b['snapshot']['vendor'] ?? null)
            ?? ($b['current']['vendor'] ?? '')
        ));

        return $va <=> $vb;
    }

    /** @param mixed $v */
    private static function normDate($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return $m[1];
        }

        return null;
    }
}
