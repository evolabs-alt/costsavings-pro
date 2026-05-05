<?php

namespace CostSavings;

use PDO;
use PDOException;

class VendorService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUESTION = 'question';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_KEEP = 'keep';
    public const STATUS_MARK = 'mark_for_cancellation';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function allStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_QUESTION,
            self::STATUS_UNKNOWN,
            self::STATUS_KEEP,
            self::STATUS_MARK,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Normalize an arbitrary status-ish input (canonical token, legacy
     * cancel_keep value, or human label) to one of the canonical tokens.
     * Defaults to 'pending' for missing/unknown input.
     */
    public static function normalizeStatus($value): string
    {
        if ($value === null || $value === '') {
            return self::STATUS_PENDING;
        }
        $s = strtolower(trim((string) $value));
        $s = str_replace([' ', '-'], '_', $s);
        switch ($s) {
            case self::STATUS_PENDING:
                return self::STATUS_PENDING;
            case self::STATUS_QUESTION:
            case 'question_mark':
                return self::STATUS_QUESTION;
            case self::STATUS_UNKNOWN:
                return self::STATUS_UNKNOWN;
            case self::STATUS_KEEP:
            case '1':
                return self::STATUS_KEEP;
            case self::STATUS_MARK:
            case 'mark':
            case 'cancel':
            case 'mark_cancellation':
            case 'mark_for_cancel':
            case '0':
                return self::STATUS_MARK;
            case self::STATUS_CANCELLED:
            case 'cancelled_confirmed':
            case 'confirmed_cancelled':
                return self::STATUS_CANCELLED;
        }
        return self::STATUS_PENDING;
    }

    /**
     * Convert canonical status to the legacy (cancel_keep, cancelled_status)
     * pair so older readers (reminders, exports) stay correct.
     *
     * @return array{cancel_keep:string, cancelled_status:int}
     */
    public static function statusToLegacy(string $status): array
    {
        $s = self::normalizeStatus($status);
        if ($s === self::STATUS_CANCELLED) {
            return ['cancel_keep' => 'Cancel', 'cancelled_status' => 1];
        }
        if ($s === self::STATUS_MARK) {
            return ['cancel_keep' => 'Cancel', 'cancelled_status' => 0];
        }
        return ['cancel_keep' => 'Keep', 'cancelled_status' => 0];
    }

    /**
     * Resolve the canonical status for a stored row. Prefers the new column
     * and falls back to the legacy pair if `status` is missing/blank.
     *
     * @param array<string, mixed> $row
     */
    public static function resolveStatusFromRow(array $row): string
    {
        $raw = $row['status'] ?? null;
        if ($raw !== null && $raw !== '') {
            return self::normalizeStatus($raw);
        }
        $ck = self::normalizeCancelKeep($row['cancel_keep'] ?? 'Keep');
        $confirmed = isset($row['cancelled_status']) ? (int) $row['cancelled_status'] : 0;
        if ($ck === 'Cancel' && $confirmed === 1) {
            return self::STATUS_CANCELLED;
        }
        if ($ck === 'Cancel') {
            return self::STATUS_MARK;
        }
        return self::STATUS_KEEP;
    }

    /**
     * Resolve the canonical status from an inbound payload item which may
     * carry either the new `status` field or the legacy pair.
     *
     * @param array<string, mixed> $item
     */
    public static function resolveStatusFromItem(array $item): string
    {
        if (isset($item['status']) && $item['status'] !== '') {
            return self::normalizeStatus($item['status']);
        }
        $ck = self::normalizeCancelKeep($item['cancel_keep'] ?? 'Keep');
        $confirmed = isset($item['cancelled_status']) ? (int) $item['cancelled_status'] : 0;
        if ($ck === 'Cancel' && $confirmed === 1) {
            return self::STATUS_CANCELLED;
        }
        if ($ck === 'Cancel') {
            return self::STATUS_MARK;
        }
        // No explicit status and legacy says Keep — treat as 'keep' for
        // existing/edited rows; new rows should arrive with status='pending'.
        return self::STATUS_KEEP;
    }

    /**
     * Human-readable label for a status (used in exports / AI prompts).
     */
    public static function statusLabel(string $status): string
    {
        switch (self::normalizeStatus($status)) {
            case self::STATUS_PENDING:
                return 'Pending';
            case self::STATUS_QUESTION:
                return 'Question';
            case self::STATUS_UNKNOWN:
                return 'Unknown';
            case self::STATUS_KEEP:
                return 'Keep';
            case self::STATUS_MARK:
                return 'Mark for Cancellation';
            case self::STATUS_CANCELLED:
                return 'Cancelled';
        }
        return 'Pending';
    }

    public static function normalizeCancelKeep($value): string
    {
        if ($value === null || $value === '') {
            return 'Keep';
        }
        $s = trim((string) $value);
        if (strcasecmp($s, 'Cancel') === 0 || $s === '0') {
            return 'Cancel';
        }
        return 'Keep';
    }

    public static function cancelKeepToDb(string $v): string
    {
        return $v === 'Cancel' ? '0' : '1';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function loadVisibleItems(PDO $pdo, int $userId, int $orgId, int $projectId, string $role): array
    {
        if ($role === 'admin') {
            $sql = 'SELECT * FROM cost_calculator_items WHERE org_id = :oid AND project_id = :pid ORDER BY id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':oid' => $orgId, ':pid' => $projectId]);
        } else {
            $sql = 'SELECT * FROM cost_calculator_items WHERE org_id = :oid AND project_id = :pid AND (
                visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = :uid)
            ) ORDER BY id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':oid' => $orgId, ':pid' => $projectId, ':uid' => $userId]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::rowToItem($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function rowToItem(array $row): array
    {
        $status = self::resolveStatusFromRow($row);
        $legacy = self::statusToLegacy($status);
        $purpose = $row['purpose_of_subscription'] ?? ($row['notes'] ?? '');

        return [
            'id' => (int) $row['id'],
            'vendor_name' => $row['vendor_name'],
            'cost_per_period' => (float) $row['cost_per_period'],
            'frequency' => $row['frequency'],
            'annual_cost' => (float) $row['annual_cost'],
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'cancel_keep' => $legacy['cancel_keep'],
            'cancelled_status' => $legacy['cancelled_status'],
            'notes' => $purpose,
            'purpose_of_subscription' => $purpose,
            'visibility' => $row['visibility'] ?? 'public',
            'manager_user_id' => (isset($row['manager_user_id']) && $row['manager_user_id'] !== null && $row['manager_user_id'] !== '')
                ? (int) $row['manager_user_id'] : null,
            'cancellation_deadline' => $row['cancellation_deadline'] ?? null,
            'last_payment_date' => $row['last_payment_date'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool, error?:string}
     */
    public static function validateItems(array $items): array
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $status = self::resolveStatusFromItem($item);
            $deadline = trim((string) ($item['cancellation_deadline'] ?? ''));

            if ($status === self::STATUS_MARK && $deadline === '') {
                return [
                    'success' => false,
                    'error' => 'Cancellation deadline is required when status is Mark for Cancellation.',
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool, error?:string, cancelKeep?:string}
     */
    public static function saveAdmin(PDO $pdo, int $orgId, int $projectId, int $adminUserId, array $items): array
    {
        $items = self::normalizeMarkForCancellationDeadlines($items);
        $v = self::validateItems($items);
        if (!$v['success']) {
            return $v;
        }

        $pdo->beginTransaction();
        try {
            $existing = $pdo->prepare(
                'SELECT id FROM cost_calculator_items WHERE org_id = ? AND project_id = ?'
            );
            $existing->execute([$orgId, $projectId]);
            $allowedIds = [];
            while ($r = $existing->fetch(PDO::FETCH_ASSOC)) {
                $allowedIds[(int) $r['id']] = true;
            }

            $payloadIds = [];

            $ins = $pdo->prepare(
                'INSERT INTO cost_calculator_items (org_id, project_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $upd = $pdo->prepare(
                'UPDATE cost_calculator_items SET user_id=?, user_email=?, manager_user_id=?, vendor_name=?, cost_per_period=?, frequency=?, annual_cost=?, status=?, cancel_keep=?, cancelled_status=?, visibility=?, purpose_of_subscription=?, cancellation_deadline=?, last_payment_date=?
                 WHERE id=? AND org_id=? AND project_id=?'
            );

            $email = self::getUserEmail($pdo, $adminUserId);
            $cc = '';

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $status = self::resolveStatusFromItem($item);
                $legacy = self::statusToLegacy($status);
                $cc .= '-' . $legacy['cancel_keep'];
                $purpose = $item['purpose_of_subscription'] ?? $item['notes'] ?? '';
                $vis = ($item['visibility'] ?? 'public') === 'confidential' ? 'confidential' : 'public';
                $mgr = isset($item['manager_user_id']) ? (int) $item['manager_user_id'] : null;
                if ($mgr !== null && $mgr > 0 && !self::userInOrg($pdo, $mgr, $orgId)) {
                    $mgr = null;
                }
                if ($mgr === null || $mgr <= 0) {
                    $mgr = $adminUserId;
                }
                $deadline = self::normDate($item['cancellation_deadline'] ?? null);
                $lastPay = self::normDate($item['last_payment_date'] ?? null);
                $rowId = isset($item['id']) ? (int) $item['id'] : 0;

                if ($rowId > 0 && isset($allowedIds[$rowId])) {
                    $payloadIds[$rowId] = true;
                    $upd->execute([
                        $adminUserId,
                        $email,
                        $mgr,
                        $item['vendor_name'] ?? '',
                        (float) ($item['cost_per_period'] ?? 0),
                        $item['frequency'] ?? '',
                        (float) ($item['annual_cost'] ?? 0),
                        $status,
                        self::cancelKeepToDb($legacy['cancel_keep']),
                        $legacy['cancelled_status'],
                        $vis,
                        $purpose,
                        $deadline,
                        $lastPay,
                        $rowId,
                        $orgId,
                        $projectId,
                    ]);
                } else {
                    $ins->execute([
                        $orgId,
                        $projectId,
                        $adminUserId,
                        $email,
                        $mgr,
                        $item['vendor_name'] ?? '',
                        (float) ($item['cost_per_period'] ?? 0),
                        $item['frequency'] ?? '',
                        (float) ($item['annual_cost'] ?? 0),
                        $status,
                        self::cancelKeepToDb($legacy['cancel_keep']),
                        $legacy['cancelled_status'],
                        $vis,
                        $purpose,
                        $deadline,
                        $lastPay,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    if ($newId > 0) {
                        $payloadIds[$newId] = true;
                    }
                }
            }

            if (count($allowedIds) > 0) {
                $del = $pdo->prepare('DELETE FROM cost_calculator_items WHERE id = ? AND org_id = ? AND project_id = ?');
                foreach ($allowedIds as $id => $_unused) {
                    if (!isset($payloadIds[$id])) {
                        $del->execute([(int) $id, $orgId, $projectId]);
                    }
                }
            }

            $pdo->commit();

            return ['success' => true, 'cancelKeep' => $cc];
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('VendorService::saveAdmin: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Save failed'];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool, error?:string, cancelKeep?:string}
     */
    public static function saveMember(PDO $pdo, int $orgId, int $projectId, int $userId, array $items): array
    {
        $items = self::normalizeMarkForCancellationDeadlines($items);
        $v = self::validateItems($items);
        if (!$v['success']) {
            return $v;
        }

        $pdo->beginTransaction();
        try {
            $email = self::getUserEmail($pdo, $userId);

            $existing = $pdo->prepare(
                'SELECT id FROM cost_calculator_items WHERE org_id = ? AND project_id = ? AND manager_user_id = ?'
            );
            $existing->execute([$orgId, $projectId, $userId]);
            $allowedIds = [];
            while ($r = $existing->fetch(PDO::FETCH_ASSOC)) {
                $allowedIds[(int) $r['id']] = true;
            }

            $payloadIds = [];
            $cc = '';

            $upd = $pdo->prepare(
                'UPDATE cost_calculator_items SET vendor_name=?, cost_per_period=?, frequency=?, annual_cost=?, status=?, cancel_keep=?, cancelled_status=?, visibility=?, purpose_of_subscription=?, cancellation_deadline=?, last_payment_date=?, user_email=?, user_id=?
                 WHERE id=? AND org_id=? AND project_id=? AND manager_user_id=?'
            );

            $ins = $pdo->prepare(
                'INSERT INTO cost_calculator_items (org_id, project_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $status = self::resolveStatusFromItem($item);
                $legacy = self::statusToLegacy($status);
                $cc .= '-' . $legacy['cancel_keep'];
                $purpose = $item['purpose_of_subscription'] ?? $item['notes'] ?? '';
                $vis = ($item['visibility'] ?? 'public') === 'confidential' ? 'confidential' : 'public';
                $deadline = self::normDate($item['cancellation_deadline'] ?? null);
                $lastPay = self::normDate($item['last_payment_date'] ?? null);
                $rowId = isset($item['id']) ? (int) $item['id'] : 0;

                if ($rowId > 0) {
                    if (!isset($allowedIds[$rowId])) {
                        continue;
                    }
                    $payloadIds[$rowId] = true;
                    $upd->execute([
                        $item['vendor_name'] ?? '',
                        (float) ($item['cost_per_period'] ?? 0),
                        $item['frequency'] ?? '',
                        (float) ($item['annual_cost'] ?? 0),
                        $status,
                        self::cancelKeepToDb($legacy['cancel_keep']),
                        $legacy['cancelled_status'],
                        $vis,
                        $purpose,
                        $deadline,
                        $lastPay,
                        $email,
                        $userId,
                        $rowId,
                        $orgId,
                        $projectId,
                        $userId,
                    ]);
                } else {
                    $ins->execute([
                        $orgId,
                        $projectId,
                        $userId,
                        $email,
                        $userId,
                        $item['vendor_name'] ?? '',
                        (float) ($item['cost_per_period'] ?? 0),
                        $item['frequency'] ?? '',
                        (float) ($item['annual_cost'] ?? 0),
                        $status,
                        self::cancelKeepToDb($legacy['cancel_keep']),
                        $legacy['cancelled_status'],
                        $vis,
                        $purpose,
                        $deadline,
                        $lastPay,
                    ]);
                }
            }

            foreach (array_keys($allowedIds) as $aid) {
                if (!isset($payloadIds[$aid])) {
                    $del = $pdo->prepare('DELETE FROM cost_calculator_items WHERE id = ? AND org_id = ? AND project_id = ? AND manager_user_id = ?');
                    $del->execute([$aid, $orgId, $projectId, $userId]);
                }
            }

            $pdo->commit();

            return ['success' => true, 'cancelKeep' => $cc];
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('VendorService::saveMember: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Save failed'];
        }
    }

    private static function normDate($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeMarkForCancellationDeadlines(array $items): array
    {
        $defaultDeadline = self::currentMonthEndIsoDate();
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $status = self::resolveStatusFromItem($item);
            $deadline = trim((string) ($item['cancellation_deadline'] ?? ''));
            if ($status === self::STATUS_MARK && $deadline === '') {
                $items[$i]['cancellation_deadline'] = $defaultDeadline;
            }
        }
        return $items;
    }

    private static function currentMonthEndIsoDate(): string
    {
        return (new \DateTimeImmutable('now'))->modify('last day of this month')->format('Y-m-d');
    }

    private static function getUserEmail(PDO $pdo, int $userId): string
    {
        $st = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        return $r ? (string) $r['email'] : '';
    }

    /**
     * Append imported vendor rows without deleting existing data.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool, inserted?:int, error?:string}
     */
    public static function appendImportedRows(PDO $pdo, int $orgId, int $projectId, int $userId, array $items): array
    {
        $email = self::getUserEmail($pdo, $userId);
        $ins = $pdo->prepare(
            'INSERT INTO cost_calculator_items (org_id, project_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $n = 0;
        try {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                // Imports default to 'pending' unless caller specifies otherwise.
                $status = isset($item['status']) && $item['status'] !== ''
                    ? self::normalizeStatus($item['status'])
                    : self::STATUS_PENDING;
                $legacy = self::statusToLegacy($status);
                $purpose = $item['purpose_of_subscription'] ?? $item['notes'] ?? '';
                $vis = ($item['visibility'] ?? 'public') === 'confidential' ? 'confidential' : 'public';
                $mgr = isset($item['manager_user_id']) ? (int) $item['manager_user_id'] : $userId;
                if (!self::userInOrg($pdo, $mgr, $orgId)) {
                    $mgr = $userId;
                }
                $deadline = self::normDate($item['cancellation_deadline'] ?? null);
                $lastPay = self::normDate($item['last_payment_date'] ?? null);
                $ins->execute([
                    $orgId,
                    $projectId,
                    $userId,
                    $email,
                    $mgr,
                    $item['vendor_name'] ?? '',
                    (float) ($item['cost_per_period'] ?? 0),
                    $item['frequency'] ?? '',
                    (float) ($item['annual_cost'] ?? 0),
                    $status,
                    self::cancelKeepToDb($legacy['cancel_keep']),
                    $legacy['cancelled_status'],
                    $vis,
                    $purpose,
                    $deadline,
                    $lastPay,
                ]);
                ++$n;
            }

            return ['success' => true, 'inserted' => $n];
        } catch (PDOException $e) {
            error_log('VendorService::appendImportedRows: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Import failed'];
        }
    }

    private static function userInOrg(PDO $pdo, int $userId, int $orgId): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND org_id = ?');
        $st->execute([$userId, $orgId]);

        return (bool) $st->fetchColumn();
    }

    /**
     * @param array<int, array{id:int, purpose:string}> $updates
     * @return array{updated:int,applied:int,applied_ids:array<int,int>}
     */
    public static function updatePurposesForVisibleRows(PDO $pdo, int $orgId, int $projectId, int $userId, string $role, array $updates): array
    {
        if (count($updates) === 0) {
            return ['updated' => 0, 'applied' => 0, 'applied_ids' => []];
        }
        $updated = 0;
        $applied = 0;
        $appliedIds = [];
        if ($role === 'admin') {
            $stmt = $pdo->prepare(
                'UPDATE cost_calculator_items
                 SET purpose_of_subscription = ?
                 WHERE id = ? AND org_id = ? AND project_id = ?'
            );
            foreach ($updates as $u) {
                $rowId = (int) ($u['id'] ?? 0);
                if ($rowId <= 0) {
                    continue;
                }
                $stmt->execute([(string) ($u['purpose'] ?? ''), $rowId, $orgId, $projectId]);
                $updated += $stmt->rowCount();
                ++$applied;
                $appliedIds[] = $rowId;
            }

            return ['updated' => $updated, 'applied' => $applied, 'applied_ids' => $appliedIds];
        }

        $allowed = [];
        $allowedStmt = $pdo->prepare(
            'SELECT id FROM cost_calculator_items
             WHERE id = ? AND org_id = ? AND project_id = ? AND manager_user_id = ?'
        );
        foreach ($updates as $u) {
            $rowId = (int) ($u['id'] ?? 0);
            if ($rowId <= 0 || isset($allowed[$rowId])) {
                continue;
            }
            $allowedStmt->execute([$rowId, $orgId, $projectId, $userId]);
            if ($allowedStmt->fetchColumn()) {
                $allowed[$rowId] = true;
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE cost_calculator_items
             SET purpose_of_subscription = ?
             WHERE id = ? AND org_id = ? AND project_id = ? AND manager_user_id = ?'
        );
        foreach ($updates as $u) {
            $rowId = (int) ($u['id'] ?? 0);
            if ($rowId <= 0 || !isset($allowed[$rowId])) {
                continue;
            }
            $stmt->execute([(string) ($u['purpose'] ?? ''), $rowId, $orgId, $projectId, $userId]);
            $updated += $stmt->rowCount();
            ++$applied;
            $appliedIds[] = $rowId;
        }

        return ['updated' => $updated, 'applied' => $applied, 'applied_ids' => $appliedIds];
    }

    public static function normalizeVendorName(string $vendorName): string
    {
        return mb_strtolower(trim($vendorName), 'UTF-8');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{success:bool, inserted?:int, error?:string}
     */
    public static function appendRawTransactions(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $uploadedByUserId,
        string $uploadBatchId,
        array $rows
    ): array {
        $stmt = $pdo->prepare(
            'INSERT INTO vendor_raw_transactions
            (org_id, project_id, uploaded_by_user_id, upload_batch_id, vendor_name, vendor_name_normalized, transaction_date, amount, transaction_type, account, memo)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $inserted = 0;
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $vendorName = trim((string) ($row['vendor_name'] ?? ''));
                $date = trim((string) ($row['transaction_date'] ?? ''));
                if ($vendorName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }
                $stmt->execute([
                    $orgId,
                    $projectId,
                    $uploadedByUserId,
                    $uploadBatchId,
                    $vendorName,
                    self::normalizeVendorName($vendorName),
                    $date,
                    (float) ($row['amount'] ?? 0),
                    trim((string) ($row['transaction_type'] ?? '')),
                    trim((string) ($row['account'] ?? '')),
                    trim((string) ($row['memo'] ?? '')),
                ]);
                ++$inserted;
            }

            return ['success' => true, 'inserted' => $inserted];
        } catch (PDOException $e) {
            error_log('VendorService::appendRawTransactions: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Raw transaction import failed'];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function loadRawTransactionsForVisibleVendor(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $userId,
        string $role,
        string $vendorName
    ): array {
        $vendorNorm = self::normalizeVendorName($vendorName);
        if ($vendorNorm === '') {
            return [];
        }
        if ($role !== 'admin') {
            $check = $pdo->prepare(
                "SELECT 1
                 FROM cost_calculator_items
                 WHERE org_id = :oid
                   AND project_id = :pid
                   AND LOWER(TRIM(vendor_name)) = :v
                   AND (visibility = 'public' OR (visibility = 'confidential' AND manager_user_id = :uid))
                 LIMIT 1"
            );
            $check->execute([':oid' => $orgId, ':pid' => $projectId, ':v' => $vendorNorm, ':uid' => $userId]);
            if (!$check->fetchColumn()) {
                return [];
            }
        }
        $stmt = $pdo->prepare(
            'SELECT vendor_name, transaction_date, amount, transaction_type, account, memo, upload_batch_id, created_at
             FROM vendor_raw_transactions
             WHERE org_id = :oid AND project_id = :pid AND vendor_name_normalized = :v
             ORDER BY transaction_date DESC, id DESC'
        );
        $stmt->execute([':oid' => $orgId, ':pid' => $projectId, ':v' => $vendorNorm]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }
}
