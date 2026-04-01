<?php

namespace CostSavings;

use PDO;
use PDOException;

class VendorService
{
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
    public static function loadVisibleItems(PDO $pdo, int $userId, int $orgId, string $role): array
    {
        if ($role === 'admin') {
            $sql = 'SELECT * FROM cost_calculator_items WHERE org_id = :oid ORDER BY id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':oid' => $orgId]);
        } else {
            $sql = 'SELECT * FROM cost_calculator_items WHERE org_id = :oid AND (
                visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = :uid)
            ) ORDER BY id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':oid' => $orgId, ':uid' => $userId]);
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
        $ck = self::normalizeCancelKeep($row['cancel_keep'] ?? 'Keep');
        $purpose = $row['purpose_of_subscription'] ?? ($row['notes'] ?? '');

        return [
            'id' => (int) $row['id'],
            'vendor_name' => $row['vendor_name'],
            'cost_per_period' => (float) $row['cost_per_period'],
            'frequency' => $row['frequency'],
            'annual_cost' => (float) $row['annual_cost'],
            'cancel_keep' => $ck,
            'cancelled_status' => isset($row['cancelled_status']) ? (int) $row['cancelled_status'] : 0,
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
            $ck = self::normalizeCancelKeep($item['cancel_keep'] ?? 'Keep');
            $deadline = trim((string) ($item['cancellation_deadline'] ?? ''));

            if ($ck === 'Cancel' && $deadline === '') {
                return ['success' => false, 'error' => 'Cancellation deadline is required when Cancel is selected.'];
            }
        }

        return ['success' => true];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{success:bool, error?:string, cancelKeep?:string}
     */
    public static function saveAdmin(PDO $pdo, int $orgId, int $adminUserId, array $items): array
    {
        $v = self::validateItems($items);
        if (!$v['success']) {
            return $v;
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM cost_calculator_items WHERE org_id = ?');
            $del->execute([$orgId]);

            $ins = $pdo->prepare(
                'INSERT INTO cost_calculator_items (org_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            $email = self::getUserEmail($pdo, $adminUserId);
            $cc = '';

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $ck = self::normalizeCancelKeep($item['cancel_keep'] ?? 'Keep');
                $cc .= '-' . $ck;
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

                $ins->execute([
                    $orgId,
                    $adminUserId,
                    $email,
                    $mgr,
                    $item['vendor_name'] ?? '',
                    (float) ($item['cost_per_period'] ?? 0),
                    $item['frequency'] ?? '',
                    (float) ($item['annual_cost'] ?? 0),
                    self::cancelKeepToDb($ck),
                    isset($item['cancelled_status']) ? (int) $item['cancelled_status'] : 0,
                    $vis,
                    $purpose,
                    $deadline,
                    $lastPay,
                ]);
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
    public static function saveMember(PDO $pdo, int $orgId, int $userId, array $items): array
    {
        $v = self::validateItems($items);
        if (!$v['success']) {
            return $v;
        }

        $pdo->beginTransaction();
        try {
            $email = self::getUserEmail($pdo, $userId);

            $existing = $pdo->prepare(
                'SELECT id FROM cost_calculator_items WHERE org_id = ? AND manager_user_id = ?'
            );
            $existing->execute([$orgId, $userId]);
            $allowedIds = [];
            while ($r = $existing->fetch(PDO::FETCH_ASSOC)) {
                $allowedIds[(int) $r['id']] = true;
            }

            $payloadIds = [];
            $cc = '';

            $upd = $pdo->prepare(
                'UPDATE cost_calculator_items SET vendor_name=?, cost_per_period=?, frequency=?, annual_cost=?, cancel_keep=?, cancelled_status=?, visibility=?, purpose_of_subscription=?, cancellation_deadline=?, last_payment_date=?, user_email=?, user_id=?
                 WHERE id=? AND org_id=? AND manager_user_id=?'
            );

            $ins = $pdo->prepare(
                'INSERT INTO cost_calculator_items (org_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $ck = self::normalizeCancelKeep($item['cancel_keep'] ?? 'Keep');
                $cc .= '-' . $ck;
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
                        self::cancelKeepToDb($ck),
                        isset($item['cancelled_status']) ? (int) $item['cancelled_status'] : 0,
                        $vis,
                        $purpose,
                        $deadline,
                        $lastPay,
                        $email,
                        $userId,
                        $rowId,
                        $orgId,
                        $userId,
                    ]);
                } else {
                    $ins->execute([
                        $orgId,
                        $userId,
                        $email,
                        $userId,
                        $item['vendor_name'] ?? '',
                        (float) ($item['cost_per_period'] ?? 0),
                        $item['frequency'] ?? '',
                        (float) ($item['annual_cost'] ?? 0),
                        self::cancelKeepToDb($ck),
                        isset($item['cancelled_status']) ? (int) $item['cancelled_status'] : 0,
                        $vis,
                        $purpose,
                        $deadline,
                        $lastPay,
                    ]);
                }
            }

            foreach (array_keys($allowedIds) as $aid) {
                if (!isset($payloadIds[$aid])) {
                    $del = $pdo->prepare('DELETE FROM cost_calculator_items WHERE id = ? AND org_id = ? AND manager_user_id = ?');
                    $del->execute([$aid, $orgId, $userId]);
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
    public static function appendImportedRows(PDO $pdo, int $orgId, int $userId, array $items): array
    {
        $email = self::getUserEmail($pdo, $userId);
        $ins = $pdo->prepare(
            'INSERT INTO cost_calculator_items (org_id, user_id, user_email, manager_user_id, vendor_name, cost_per_period, frequency, annual_cost, cancel_keep, cancelled_status, visibility, purpose_of_subscription, cancellation_deadline, last_payment_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $n = 0;
        try {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $ck = self::normalizeCancelKeep($item['cancel_keep'] ?? 'Keep');
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
                    $userId,
                    $email,
                    $mgr,
                    $item['vendor_name'] ?? '',
                    (float) ($item['cost_per_period'] ?? 0),
                    $item['frequency'] ?? '',
                    (float) ($item['annual_cost'] ?? 0),
                    self::cancelKeepToDb($ck),
                    isset($item['cancelled_status']) ? (int) $item['cancelled_status'] : 0,
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
}
