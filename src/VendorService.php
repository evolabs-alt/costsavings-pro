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
    public const STATUS_RENEGOTIATE = 'mark_for_renegotiation';
    public const STATUS_RENEGOTIATED = 'renegotiated';

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
            self::STATUS_RENEGOTIATE,
            self::STATUS_RENEGOTIATED,
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
            case self::STATUS_RENEGOTIATE:
            case 'mark_renegotiation':
            case 'renegotiate':
                return self::STATUS_RENEGOTIATE;
            case self::STATUS_RENEGOTIATED:
                return self::STATUS_RENEGOTIATED;
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
            case self::STATUS_RENEGOTIATE:
                return 'Mark for Renegotiation';
            case self::STATUS_RENEGOTIATED:
                return 'Renegotiated';
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
        if (!OrgRole::usesAssignedVisibilityFilter($role)) {
            $sql = 'SELECT cci.*, pc.name AS category_name
                FROM cost_calculator_items cci
                LEFT JOIN project_categories pc ON pc.id = cci.category_id AND pc.project_id = cci.project_id
                WHERE cci.org_id = :oid AND cci.project_id = :pid ORDER BY cci.id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':oid' => $orgId, ':pid' => $projectId]);
        } else {
            // Cost Owner: public rows plus confidential rows they manage.
            $sql = 'SELECT cci.*, pc.name AS category_name
                FROM cost_calculator_items cci
                LEFT JOIN project_categories pc ON pc.id = cci.category_id AND pc.project_id = cci.project_id
                WHERE cci.org_id = :oid AND cci.project_id = :pid AND (
                cci.visibility = \'public\' OR (cci.visibility = \'confidential\' AND cci.manager_user_id = :uid)
            ) ORDER BY cci.id ASC';
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

    /** @param mixed $v */
    private static function jsonSafeFloat($v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_string($v) && !is_numeric($v)) {
            return 0.0;
        }
        $f = (float) $v;

        return is_finite($f) ? $f : 0.0;
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

        $amountMin = null;
        $amountMax = null;
        if (isset($row['amount_min']) && $row['amount_min'] !== null && $row['amount_min'] !== '') {
            $amountMin = self::jsonSafeFloat($row['amount_min']);
        }
        if (isset($row['amount_max']) && $row['amount_max'] !== null && $row['amount_max'] !== '') {
            $amountMax = self::jsonSafeFloat($row['amount_max']);
        }

        return [
            'id' => (int) $row['id'],
            'vendor_name' => $row['vendor_name'],
            'cost_per_period' => self::jsonSafeFloat($row['cost_per_period'] ?? 0),
            'amount_min' => $amountMin,
            'amount_max' => $amountMax,
            'frequency' => self::normalizeFrequencyValue($row['frequency'] ?? ''),
            'annual_cost' => self::jsonSafeFloat($row['annual_cost'] ?? 0),
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'cancel_keep' => $legacy['cancel_keep'],
            'cancelled_status' => $legacy['cancelled_status'],
            'notes' => $purpose,
            'purpose_of_subscription' => $purpose,
            'visibility' => $row['visibility'] ?? 'public',
            'manager_user_id' => (isset($row['manager_user_id']) && $row['manager_user_id'] !== null && $row['manager_user_id'] !== '')
                ? (int) $row['manager_user_id'] : null,
            'category_id' => (isset($row['category_id']) && $row['category_id'] !== null && $row['category_id'] !== '')
                ? (int) $row['category_id'] : null,
            'category_name' => isset($row['category_name']) && $row['category_name'] !== null && $row['category_name'] !== ''
                ? (string) $row['category_name'] : null,
            'account' => isset($row['account']) && $row['account'] !== null && trim((string) $row['account']) !== ''
                ? trim((string) $row['account']) : null,
            'cancellation_deadline' => $row['cancellation_deadline'] ?? null,
            'negotiation_target_date' => $row['negotiation_target_date'] ?? null,
            'renegotiated_annual_savings' => isset($row['renegotiated_annual_savings']) && $row['renegotiated_annual_savings'] !== null && $row['renegotiated_annual_savings'] !== ''
                ? self::jsonSafeFloat($row['renegotiated_annual_savings'])
                : null,
            'last_payment_date' => $row['last_payment_date'] ?? null,
        ];
    }

    public static function normalizeFrequencyValue($frequency): string
    {
        $f = trim((string) $frequency);
        if ($f === 'one_off') {
            return 'irregular';
        }
        if ($f === 'semi_monthly') {
            return 'bi_weekly';
        }

        return $f;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{0:?float,1:?float}
     */
    private static function resolveAmountRangeFromItem(array $item): array
    {
        $min = null;
        $max = null;
        if (array_key_exists('amount_min', $item) && $item['amount_min'] !== null && $item['amount_min'] !== '') {
            $min = self::jsonSafeFloat($item['amount_min']);
        }
        if (array_key_exists('amount_max', $item) && $item['amount_max'] !== null && $item['amount_max'] !== '') {
            $max = self::jsonSafeFloat($item['amount_max']);
        }

        return [$min, $max];
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
     * Manager on admin save: explicit null / empty / 0 in payload clears assignment.
     * If `manager_user_id` is omitted (legacy clients), default to the acting admin user.
     * If a non-empty id is not an active org member, keep the existing manager (do not silently clear).
     *
     * @param array<string, mixed> $item
     */
    private static function resolveManagerUserIdForAdminSave(
        PDO $pdo,
        int $orgId,
        array $item,
        int $adminUserId,
        ?int $existingManagerId = null
    ): ?int {
        if (!array_key_exists('manager_user_id', $item)) {
            return $adminUserId > 0 ? $adminUserId : null;
        }
        $raw = $item['manager_user_id'];
        if ($raw === null || $raw === '') {
            return null;
        }
        $mgr = (int) $raw;
        if ($mgr <= 0) {
            return null;
        }
        if (!self::userInOrg($pdo, $mgr, $orgId)) {
            return $existingManagerId;
        }

        return $mgr;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool, error?:string, cancelKeep?:string, saved_ids?:array<int, int>}
     */
    public static function saveAdmin(PDO $pdo, int $orgId, int $projectId, int $adminUserId, string $actingRole, array $items, bool $fullSync = false): array
    {
        $items = self::normalizeMarkForCancellationDeadlines($items);
        $v = self::validateItems($items);
        if (!$v['success']) {
            return $v;
        }
        if (count($items) === 0 && !$fullSync) {
            return ['success' => true, 'cancelKeep' => '', 'saved_ids' => []];
        }

        $pdo->beginTransaction();
        try {
            if (!OrgRole::canManageVendorStructure($actingRole)) {
                $pdo->rollBack();

                return ['success' => false, 'error' => 'Save failed'];
            }
            $existing = $pdo->prepare(
                'SELECT id, vendor_name, manager_user_id FROM cost_calculator_items WHERE org_id = ? AND project_id = ?'
            );
            $existing->execute([$orgId, $projectId]);
            $allowedIds = [];
            $existingVendorNames = [];
            $existingManagers = [];
            while ($r = $existing->fetch(PDO::FETCH_ASSOC)) {
                $id = (int) $r['id'];
                $allowedIds[$id] = true;
                $existingVendorNames[$id] = trim((string) ($r['vendor_name'] ?? ''));
                $existingMgr = isset($r['manager_user_id']) ? (int) $r['manager_user_id'] : 0;
                $existingManagers[$id] = $existingMgr > 0 ? $existingMgr : null;
            }

            $payloadIds = [];
            $savedIds = [];

            $ins = $pdo->prepare(
                'INSERT INTO cost_calculator_items (org_id, project_id, user_id, user_email, manager_user_id, category_id, vendor_name, cost_per_period, amount_min, amount_max, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date, negotiation_target_date, renegotiated_annual_savings)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $upd = $pdo->prepare(
                'UPDATE cost_calculator_items SET user_id=?, user_email=?, manager_user_id=?, category_id=?, vendor_name=?, cost_per_period=?, amount_min=?, amount_max=?, frequency=?, annual_cost=?, status=?, cancel_keep=?, cancelled_status=?, visibility=?, purpose_of_subscription=?, cancellation_deadline=?, last_payment_date=?, negotiation_target_date=?, renegotiated_annual_savings=?
                 WHERE id=? AND org_id=? AND project_id=?'
            );

            $email = self::getUserEmail($pdo, $adminUserId);
            $cc = '';

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $savedIds[] = 0;
                    continue;
                }
                $status = self::resolveStatusFromItem($item);
                $legacy = self::statusToLegacy($status);
                $cc .= '-' . $legacy['cancel_keep'];
                $purpose = $item['purpose_of_subscription'] ?? $item['notes'] ?? '';
                $vis = ($item['visibility'] ?? 'public') === 'confidential' ? 'confidential' : 'public';
                $rowId = isset($item['id']) ? (int) $item['id'] : 0;
                $existingMgr = ($rowId > 0 && array_key_exists($rowId, $existingManagers))
                    ? $existingManagers[$rowId]
                    : null;
                $mgr = self::resolveManagerUserIdForAdminSave($pdo, $orgId, $item, $adminUserId, $existingMgr);
                $categoryId = CategoryService::resolveCategoryIdFromItem($pdo, $orgId, $projectId, $item);
                $deadline = self::normDate($item['cancellation_deadline'] ?? null);
                $lastPay = self::normDate($item['last_payment_date'] ?? null);
                [$negotiationDate, $renegotiatedSavings] = self::resolveRenegotiationFields($item);
                [$amountMin, $amountMax] = self::resolveAmountRangeFromItem($item);
                $frequency = self::normalizeFrequencyValue($item['frequency'] ?? '');

                if ($rowId > 0) {
                    if (!isset($allowedIds[$rowId])) {
                        $savedIds[] = 0;
                        continue;
                    }
                    $payloadIds[$rowId] = true;
                    $vendorName = trim((string) ($item['vendor_name'] ?? ''));
                    if ($vendorName === ''
                        && isset($existingVendorNames[$rowId])
                        && $existingVendorNames[$rowId] !== '') {
                        $vendorName = $existingVendorNames[$rowId];
                    }
                    $upd->execute([
                        $adminUserId,
                        $email,
                        $mgr,
                        $categoryId,
                        $vendorName,
                        (float) ($item['cost_per_period'] ?? 0),
                        $amountMin,
                        $amountMax,
                        $frequency,
                        (float) ($item['annual_cost'] ?? 0),
                        $status,
                        self::cancelKeepToDb($legacy['cancel_keep']),
                        $legacy['cancelled_status'],
                        $vis,
                        $purpose,
                        $deadline,
                        $lastPay,
                        $negotiationDate,
                        $renegotiatedSavings,
                        $rowId,
                        $orgId,
                        $projectId,
                    ]);
                    $savedIds[] = $rowId;
                } else {
                    $ins->execute([
                        $orgId,
                        $projectId,
                        $adminUserId,
                        $email,
                        $mgr,
                        $categoryId,
                        $item['vendor_name'] ?? '',
                        (float) ($item['cost_per_period'] ?? 0),
                        $amountMin,
                        $amountMax,
                        $frequency,
                        (float) ($item['annual_cost'] ?? 0),
                        $status,
                        self::cancelKeepToDb($legacy['cancel_keep']),
                        $legacy['cancelled_status'],
                        $vis,
                        $purpose,
                        $deadline,
                        $lastPay,
                        $negotiationDate,
                        $renegotiatedSavings,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    if ($newId > 0) {
                        $payloadIds[$newId] = true;
                        $savedIds[] = $newId;
                    } else {
                        $savedIds[] = 0;
                    }
                }
            }

            // Only delete missing rows on explicit full_sync (e.g. bulk delete).
            // Partial/delta saves must never wipe vendors absent from the payload.
            if ($fullSync && count($allowedIds) > 0) {
                $del = $pdo->prepare('DELETE FROM cost_calculator_items WHERE id = ? AND org_id = ? AND project_id = ?');
                foreach ($allowedIds as $id => $_unused) {
                    if (!isset($payloadIds[$id])) {
                        $del->execute([(int) $id, $orgId, $projectId]);
                    }
                }
            }

            $pdo->commit();

            return ['success' => true, 'cancelKeep' => $cc, 'saved_ids' => $savedIds];
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('VendorService::saveAdmin: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Save failed'];
        }
    }

    /**
     * Cost Owner save: edit spend/manager/visibility/status/frequency on public rows and
     * confidential rows they manage. Cannot add, delete, or rename vendor rows.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool, error?:string, cancelKeep?:string, saved_ids?:array<int, int>}
     */
    public static function saveMember(PDO $pdo, int $orgId, int $projectId, int $userId, array $items, bool $fullSync = false): array
    {
        $items = self::normalizeMarkForCancellationDeadlines($items);
        $v = self::validateItems($items);
        if (!$v['success']) {
            return $v;
        }
        if (count($items) === 0 && !$fullSync) {
            return ['success' => true, 'cancelKeep' => '', 'saved_ids' => []];
        }

        $pdo->beginTransaction();
        try {
            $email = self::getUserEmail($pdo, $userId);

            $existing = $pdo->prepare(
                'SELECT id, vendor_name, manager_user_id
                 FROM cost_calculator_items
                 WHERE org_id = ? AND project_id = ?
                   AND (visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = ?))'
            );
            $existing->execute([$orgId, $projectId, $userId]);
            $allowedIds = [];
            $existingVendorNames = [];
            $existingManagers = [];
            while ($r = $existing->fetch(PDO::FETCH_ASSOC)) {
                $id = (int) $r['id'];
                $allowedIds[$id] = true;
                $existingVendorNames[$id] = trim((string) ($r['vendor_name'] ?? ''));
                $existingMgr = isset($r['manager_user_id']) ? (int) $r['manager_user_id'] : 0;
                $existingManagers[$id] = $existingMgr > 0 ? $existingMgr : null;
            }

            $savedIds = [];
            $cc = '';

            $upd = $pdo->prepare(
                'UPDATE cost_calculator_items SET user_id=?, user_email=?, manager_user_id=?, category_id=?, vendor_name=?, cost_per_period=?, amount_min=?, amount_max=?, frequency=?, annual_cost=?, status=?, cancel_keep=?, cancelled_status=?, visibility=?, purpose_of_subscription=?, cancellation_deadline=?, last_payment_date=?, negotiation_target_date=?, renegotiated_annual_savings=?
                 WHERE id=? AND org_id=? AND project_id=?'
            );

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $savedIds[] = 0;
                    continue;
                }
                $status = self::resolveStatusFromItem($item);
                $legacy = self::statusToLegacy($status);
                $cc .= '-' . $legacy['cancel_keep'];
                $purpose = $item['purpose_of_subscription'] ?? $item['notes'] ?? '';
                $vis = ($item['visibility'] ?? 'public') === 'confidential' ? 'confidential' : 'public';
                $rowId = isset($item['id']) ? (int) $item['id'] : 0;
                if ($rowId <= 0 || !isset($allowedIds[$rowId])) {
                    $savedIds[] = 0;
                    continue;
                }
                $existingMgr = array_key_exists($rowId, $existingManagers)
                    ? $existingManagers[$rowId]
                    : null;
                $mgr = self::resolveManagerUserIdForAdminSave($pdo, $orgId, $item, $userId, $existingMgr);
                $categoryId = CategoryService::resolveCategoryIdFromItem($pdo, $orgId, $projectId, $item);
                $deadline = self::normDate($item['cancellation_deadline'] ?? null);
                $lastPay = self::normDate($item['last_payment_date'] ?? null);
                [$negotiationDate, $renegotiatedSavings] = self::resolveRenegotiationFields($item);
                [$amountMin, $amountMax] = self::resolveAmountRangeFromItem($item);
                $frequency = self::normalizeFrequencyValue($item['frequency'] ?? '');
                // Cost Owner cannot rename vendors — keep existing name.
                $vendorName = isset($existingVendorNames[$rowId]) && $existingVendorNames[$rowId] !== ''
                    ? $existingVendorNames[$rowId]
                    : trim((string) ($item['vendor_name'] ?? ''));
                $upd->execute([
                    $userId,
                    $email,
                    $mgr,
                    $categoryId,
                    $vendorName,
                    (float) ($item['cost_per_period'] ?? 0),
                    $amountMin,
                    $amountMax,
                    $frequency,
                    (float) ($item['annual_cost'] ?? 0),
                    $status,
                    self::cancelKeepToDb($legacy['cancel_keep']),
                    $legacy['cancelled_status'],
                    $vis,
                    $purpose,
                    $deadline,
                    $lastPay,
                    $negotiationDate,
                    $renegotiatedSavings,
                    $rowId,
                    $orgId,
                    $projectId,
                ]);
                $savedIds[] = $rowId;
            }

            $pdo->commit();

            return ['success' => true, 'cancelKeep' => $cc, 'saved_ids' => $savedIds];
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
     * @param array<string, mixed> $item
     * @return array{0:?string,1:?float}
     */
    private static function resolveRenegotiationFields(array $item): array
    {
        return [
            self::normDate($item['negotiation_target_date'] ?? null),
            self::normSavingsAmount($item['renegotiated_annual_savings'] ?? null),
        ];
    }

    /**
     * @param mixed $v
     */
    private static function normSavingsAmount($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace([',', '$', ' '], '', $v);
        }
        if (!is_numeric($v)) {
            return null;
        }
        $n = round((float) $v, 2);

        return $n < 0 ? 0.0 : $n;
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
            $negotiationDate = trim((string) ($item['negotiation_target_date'] ?? ''));
            if ($status === self::STATUS_RENEGOTIATE && $negotiationDate === '') {
                $items[$i]['negotiation_target_date'] = $defaultDeadline;
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
    /**
     * @return array{success:bool, inserted?:int, inserted_ids?:array<int,int>, inserted_meta?:array<int, array{id:int,frequency:string,amount_min:?float,amount_max:?float,amount_irregular:bool}>, error?:string}
     */
    public static function appendImportedRows(PDO $pdo, int $orgId, int $projectId, int $userId, array $items): array
    {
        $email = self::getUserEmail($pdo, $userId);
        $ins = $pdo->prepare(
            'INSERT INTO cost_calculator_items (org_id, project_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, amount_min, amount_max, frequency, annual_cost, status, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date, negotiation_target_date, renegotiated_annual_savings, account)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $n = 0;
        $insertedIds = [];
        $insertedMeta = [];
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
                $mgrRaw = array_key_exists('manager_user_id', $item) ? $item['manager_user_id'] : null;
                if ($mgrRaw === null || $mgrRaw === '') {
                    $mgr = null;
                } else {
                    $mgrId = (int) $mgrRaw;
                    $mgr = ($mgrId > 0 && self::userInOrg($pdo, $mgrId, $orgId)) ? $mgrId : null;
                }
                $deadline = self::normDate($item['cancellation_deadline'] ?? null);
                $lastPay = self::normDate($item['last_payment_date'] ?? null);
                [$negotiationDate, $renegotiatedSavings] = self::resolveRenegotiationFields($item);
                $account = trim((string) ($item['account'] ?? ''));
                if ($account === '(No account)') {
                    $account = '';
                }
                [$amountMin, $amountMax] = self::resolveAmountRangeFromItem($item);
                $frequency = self::normalizeFrequencyValue($item['frequency'] ?? '');
                $amountIrregular = !empty($item['amount_irregular'])
                    || ($amountMin !== null && $amountMax !== null && $amountMin !== $amountMax);
                $ins->execute([
                    $orgId,
                    $projectId,
                    $userId,
                    $email,
                    $mgr,
                    $item['vendor_name'] ?? '',
                    (float) ($item['cost_per_period'] ?? 0),
                    $amountMin,
                    $amountMax,
                    $frequency,
                    (float) ($item['annual_cost'] ?? 0),
                    $status,
                    self::cancelKeepToDb($legacy['cancel_keep']),
                    $legacy['cancelled_status'],
                    $vis,
                    $purpose,
                    $deadline,
                    $lastPay,
                    $negotiationDate,
                    $renegotiatedSavings,
                    $account !== '' ? $account : null,
                ]);
                $newId = (int) $pdo->lastInsertId();
                if ($newId > 0) {
                    $insertedIds[] = $newId;
                    $insertedMeta[] = [
                        'id' => $newId,
                        'frequency' => $frequency,
                        'amount_min' => $amountMin,
                        'amount_max' => $amountMax,
                        'amount_irregular' => $amountIrregular,
                    ];
                }
                ++$n;
            }

            return [
                'success' => true,
                'inserted' => $n,
                'inserted_ids' => $insertedIds,
                'inserted_meta' => $insertedMeta,
            ];
        } catch (PDOException $e) {
            error_log('VendorService::appendImportedRows: ' . $e->getMessage());

            return ['success' => false, 'error' => 'Import failed'];
        }
    }

    private static function userInOrg(PDO $pdo, int $userId, int $orgId): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM user_organizations WHERE user_id = ? AND org_id = ? AND is_disabled = 0 LIMIT 1'
        );
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
        if (!OrgRole::usesAssignedVisibilityFilter($role)) {
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

        // Member: public rows or confidential rows they manage.
        $stmt = $pdo->prepare(
            'UPDATE cost_calculator_items
             SET purpose_of_subscription = ?
             WHERE id = ? AND org_id = ? AND project_id = ?
               AND (visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = ?))'
        );
        foreach ($updates as $u) {
            $rowId = (int) ($u['id'] ?? 0);
            if ($rowId <= 0) {
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
        return StringUtil::strtolower($vendorName);
    }

    public static function projectHasVendors(PDO $pdo, int $orgId, int $projectId): bool
    {
        $st = $pdo->prepare(
            'SELECT 1 FROM cost_calculator_items WHERE org_id = ? AND project_id = ? LIMIT 1'
        );
        $st->execute([$orgId, $projectId]);

        return (bool) $st->fetchColumn();
    }

    /**
     * @return array<string, true> Map of normalized vendor name => true
     */
    public static function existingNormalizedVendorNames(PDO $pdo, int $orgId, int $projectId): array
    {
        $st = $pdo->prepare(
            'SELECT vendor_name FROM cost_calculator_items WHERE org_id = ? AND project_id = ?'
        );
        $st->execute([$orgId, $projectId]);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $norm = self::normalizeVendorName(trim((string) ($row['vendor_name'] ?? '')));
            if ($norm !== '') {
                $map[$norm] = true;
            }
        }

        return $map;
    }

    /**
     * Fingerprint used to dedupe raw transactions within a project.
     */
    public static function rawTransactionFingerprint(
        string $vendorNorm,
        string $date,
        float $amount,
        string $type,
        string $account,
        string $memo
    ): string {
        return implode('|', [
            $vendorNorm,
            $date,
            number_format($amount, 2, '.', ''),
            $type,
            $account,
            $memo,
        ]);
    }

    /**
     * @return array<string, true>
     */
    public static function existingRawTransactionFingerprints(PDO $pdo, int $orgId, int $projectId): array
    {
        $st = $pdo->prepare(
            'SELECT vendor_name_normalized, transaction_date, amount, transaction_type, account, memo
             FROM vendor_raw_transactions
             WHERE org_id = ? AND project_id = ?'
        );
        $st->execute([$orgId, $projectId]);
        $map = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $fp = self::rawTransactionFingerprint(
                (string) ($row['vendor_name_normalized'] ?? ''),
                (string) ($row['transaction_date'] ?? ''),
                (float) ($row['amount'] ?? 0),
                trim((string) ($row['transaction_type'] ?? '')),
                trim((string) ($row['account'] ?? '')),
                trim((string) ($row['memo'] ?? ''))
            );
            $map[$fp] = true;
        }

        return $map;
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
        return self::appendRawTransactionsInternal(
            $pdo,
            $orgId,
            $projectId,
            $uploadedByUserId,
            $uploadBatchId,
            $rows,
            false
        );
    }

    /**
     * Append raw transactions, skipping rows that already exist for the project
     * (matched on vendor_name_normalized, date, amount, type, account, memo).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{success:bool, inserted?:int, skipped?:int, error?:string}
     */
    public static function appendRawTransactionsDeduped(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $uploadedByUserId,
        string $uploadBatchId,
        array $rows
    ): array {
        return self::appendRawTransactionsInternal(
            $pdo,
            $orgId,
            $projectId,
            $uploadedByUserId,
            $uploadBatchId,
            $rows,
            true
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{success:bool, inserted?:int, skipped?:int, error?:string}
     */
    private static function appendRawTransactionsInternal(
        PDO $pdo,
        int $orgId,
        int $projectId,
        int $uploadedByUserId,
        string $uploadBatchId,
        array $rows,
        bool $dedupe
    ): array {
        $stmt = $pdo->prepare(
            'INSERT INTO vendor_raw_transactions
            (org_id, project_id, uploaded_by_user_id, upload_batch_id, vendor_name, vendor_name_normalized, transaction_date, amount, transaction_type, account, memo)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $existing = $dedupe ? self::existingRawTransactionFingerprints($pdo, $orgId, $projectId) : [];
        $inserted = 0;
        $skipped = 0;
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
                $amount = (float) ($row['amount'] ?? 0);
                $type = trim((string) ($row['transaction_type'] ?? ''));
                $account = trim((string) ($row['account'] ?? ''));
                $memo = trim((string) ($row['memo'] ?? ''));
                $norm = self::normalizeVendorName($vendorName);
                if ($dedupe) {
                    $fp = self::rawTransactionFingerprint($norm, $date, $amount, $type, $account, $memo);
                    if (isset($existing[$fp])) {
                        ++$skipped;
                        continue;
                    }
                    $existing[$fp] = true;
                }
                $stmt->execute([
                    $orgId,
                    $projectId,
                    $uploadedByUserId,
                    $uploadBatchId,
                    $vendorName,
                    $norm,
                    $date,
                    $amount,
                    $type,
                    $account,
                    $memo,
                ]);
                ++$inserted;
            }

            $out = ['success' => true, 'inserted' => $inserted];
            if ($dedupe) {
                $out['skipped'] = $skipped;
            }

            return $out;
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
        if (OrgRole::usesAssignedVisibilityFilter($role)) {
            $check = $pdo->prepare(
                'SELECT 1
                 FROM cost_calculator_items
                 WHERE org_id = :oid
                   AND project_id = :pid
                   AND LOWER(TRIM(vendor_name)) = :v
                   AND (visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = :uid))
                 LIMIT 1'
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
