<?php

namespace CostSavings;

use DateTimeImmutable;
use PDO;
use PDOException;

class ReminderService
{
    /**
     * Send deadline reminders (T-7, T, T+7) for pending cancellations.
     *
     * @param callable(string,string,string):bool|array $sendEmail fn($to, $subject, $htmlBody)
     * @return array{sent:int, errors:array<int, string>}
     */
    public static function runDeadlineReminders(PDO $pdo, callable $sendEmail): array
    {
        $sent = 0;
        $errors = [];

        $orgs = $pdo->query('SELECT id, deadline_reminders_enabled FROM organizations')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orgs as $org) {
            if (empty($org['deadline_reminders_enabled'])) {
                continue;
            }
            $oid = (int) $org['id'];
            $sql = "SELECT cci.*, u.email AS mgr_email, u.deadline_reminders_enabled AS u_rem
                    FROM cost_calculator_items cci
                    LEFT JOIN users u ON u.id = cci.manager_user_id
                    WHERE cci.org_id = ?
                      AND (
                        cci.status = 'mark_for_cancellation'
                        OR (
                          cci.cancel_keep IN ('0','Cancel')
                          AND (cci.cancelled_status = 0 OR cci.cancelled_status IS NULL)
                        )
                      )
                      AND cci.cancellation_deadline IS NOT NULL";
            $st = $pdo->prepare($sql);
            $st->execute([$oid]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['u_rem']) && (int) $row['u_rem'] === 0) {
                    continue;
                }
                $deadline = $row['cancellation_deadline'];
                if (!$deadline) {
                    continue;
                }
                $d = new DateTimeImmutable($deadline);
                $today = new DateTimeImmutable('today');
                $diff = (int) $today->diff($d)->format('%r%a');

                $type = null;
                if ($diff === -7) {
                    $type = 't_minus_7';
                } elseif ($diff === 0) {
                    $type = 'deadline_day';
                } elseif ($diff === 7) {
                    $type = 't_plus_7';
                }
                if ($type === null) {
                    continue;
                }

                $vid = (int) $row['id'];
                $check = $pdo->prepare(
                    'SELECT 1 FROM reminder_sent WHERE vendor_item_id = ? AND reminder_type = ? AND sent_on_date = CURDATE()'
                );
                $check->execute([$vid, $type]);
                if ($check->fetchColumn()) {
                    continue;
                }

                $to = $row['mgr_email'] ?? '';
                if ($to === '') {
                    continue;
                }

                $vendor = $row['vendor_name'] ?? 'Vendor';
                $subj = 'Cost savings: cancellation reminder — ' . $vendor;
                $body = '<p>Reminder regarding cancellation deadline for <strong>' . htmlspecialchars($vendor) . '</strong>.</p>';
                $body .= '<p>Deadline: ' . htmlspecialchars($deadline) . '</p>';
                if ($type === 't_minus_7') {
                    $body .= '<p>One week until your committed cancellation date.</p>';
                } elseif ($type === 'deadline_day') {
                    $body .= '<p>Today is the cancellation deadline you committed to.</p>';
                } else {
                    $body .= '<p>One week past your cancellation deadline — please confirm if still pending.</p>';
                }

                $r = $sendEmail($to, $subj, $body);
                $ok = $r === true || $r === [];
                if ($ok) {
                    try {
                        $ins = $pdo->prepare(
                            'INSERT IGNORE INTO reminder_sent (vendor_item_id, reminder_type, sent_on_date) VALUES (?,?, CURDATE())'
                        );
                        $ins->execute([$vid, $type]);
                        ++$sent;
                    } catch (PDOException $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    /**
     * Monthly renewal summary (vendors on Keep with next renewal in the coming calendar month).
     *
     * @param callable(string,string,string):mixed $sendEmail
     * @return array{sent:int}
     */
    public static function runMonthlyRenewalSummaries(PDO $pdo, callable $sendEmail): array
    {
        $sent = 0;
        $now = new DateTimeImmutable('first day of this month');
        $nextMonth = $now->modify('+1 month');
        $ym = $nextMonth->format('Y-m');

        $users = $pdo->query('SELECT id, email, org_id, role FROM users WHERE email IS NOT NULL AND email <> \'\'')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $chk = $pdo->prepare('SELECT 1 FROM monthly_renewal_sent WHERE user_id = ? AND year_month = ?');
            $chk->execute([$uid, $ym]);
            if ($chk->fetchColumn()) {
                continue;
            }

            $role = $u['role'] ?? 'member';
            $orgId = (int) $u['org_id'];
            if ($role === 'admin') {
                $q = $pdo->prepare('SELECT * FROM cost_calculator_items WHERE org_id = ?');
                $q->execute([$orgId]);
            } else {
                $q = $pdo->prepare(
                    'SELECT * FROM cost_calculator_items WHERE org_id = ? AND (
                        visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = ?)
                    )'
                );
                $q->execute([$orgId, $uid]);
            }
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $lines = [];
            foreach ($rows as $row) {
                $status = VendorService::resolveStatusFromRow($row);
                // Only include items the user is keeping (or has not classified).
                if (!in_array($status, [
                    VendorService::STATUS_KEEP,
                    VendorService::STATUS_PENDING,
                    VendorService::STATUS_QUESTION,
                    VendorService::STATUS_UNKNOWN,
                ], true)) {
                    continue;
                }
                $next = self::estimateNextRenewal($row);
                if ($next === null) {
                    continue;
                }
                if ($next->format('Y-m') !== $ym) {
                    continue;
                }
                $lines[] = ($row['vendor_name'] ?? '') . ' — renews ~' . $next->format('M j, Y');
            }
            if (count($lines) === 0) {
                continue;
            }

            $html = '<p>Pending renewals in ' . htmlspecialchars($nextMonth->format('F Y')) . ':</p><ul>';
            foreach ($lines as $l) {
                $html .= '<li>' . htmlspecialchars($l) . '</li>';
            }
            $html .= '</ul>';
            $r = $sendEmail((string) $u['email'], 'Monthly renewal summary — ' . $nextMonth->format('F Y'), $html);
            if ($r === true || $r === []) {
                $ins = $pdo->prepare('INSERT INTO monthly_renewal_sent (user_id, year_month, sent_at) VALUES (?,?,NOW())');
                $ins->execute([$uid, $ym]);
                ++$sent;
            }
        }

        return ['sent' => $sent];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function estimateNextRenewal(array $row): ?DateTimeImmutable
    {
        $last = $row['last_payment_date'] ?? null;
        if (!$last) {
            return null;
        }
        try {
            $base = new DateTimeImmutable($last);
        } catch (\Exception $e) {
            return null;
        }
        $freq = $row['frequency'] ?? 'monthly';
        switch ($freq) {
            case 'weekly':
                return $base->modify('+7 days');
            case 'monthly':
                return $base->modify('+1 month');
            case 'quarterly':
                return $base->modify('+3 months');
            case 'semi_annual':
                return $base->modify('+6 months');
            case 'annually':
                return $base->modify('+1 year');
            case 'one_off':
                // One-off purchases do not recur, so there is no next renewal.
                return null;
            default:
                return $base->modify('+1 month');
        }
    }
}
