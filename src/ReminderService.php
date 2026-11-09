<?php

namespace CostSavings;

use DateTimeImmutable;
use PDO;
use PDOException;

class ReminderService
{
    /** @var array<string, array<int, string>> frequency => lead-day offsets before renewal */
    private const RENEWAL_LEAD_TYPES = [
        'annually' => [
            60 => 'renewal_t_minus_60',
            30 => 'renewal_t_minus_30',
        ],
        'semi_annual' => [
            30 => 'renewal_t_minus_30',
            14 => 'renewal_t_minus_14',
        ],
        'quarterly' => [
            21 => 'renewal_t_minus_21',
            7 => 'renewal_t_minus_7',
        ],
    ];

    private const NON_MONTHLY_FREQUENCIES = ['quarterly', 'semi_annual', 'annually'];

    private const DIGEST_NON_MONTHLY = 'non_monthly_charges';
    private const DIGEST_CANCEL_PROGRESS = 'cancel_progress';
    private const DIGEST_CHAT_MENTIONS = 'chat_mentions';

    private const LIST_CAP = 10;
    private const CHAT_MENTION_SNIPPET_CAP = 10;
    private const CHAT_MENTION_SNIPPET_CHARS = 160;

    /**
     * Send deadline reminders (T-7, T, T+7) for pending cancellations.
     * Batches one email per manager email + reminder type.
     *
     * @param callable(string,string,string):bool|array $sendEmail fn($to, $subject, $htmlBody)
     * @return array{sent:int, errors:array<int, string>}
     */
    public static function runDeadlineReminders(PDO $pdo, callable $sendEmail): array
    {
        $sent = 0;
        $errors = [];
        /** @var array<string, array{email:string, first_name:string, type:string, vendors:array<int, array{id:int, name:string, deadline:string}>}> $buckets */
        $buckets = [];

        $orgs = $pdo->query('SELECT id, deadline_reminders_enabled FROM organizations')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orgs as $org) {
            if (empty($org['deadline_reminders_enabled'])) {
                continue;
            }
            $oid = (int) $org['id'];
            $sql = "SELECT cci.*, u.email AS mgr_email, u.first_name AS mgr_first_name,
                           u.deadline_reminders_enabled AS u_rem
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
                if ($diff === 7) {
                    $type = 't_minus_7';
                } elseif ($diff === 0) {
                    $type = 'deadline_day';
                } elseif ($diff === -7) {
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

                $to = trim((string) ($row['mgr_email'] ?? ''));
                if ($to === '') {
                    continue;
                }

                $key = strtolower($to) . '|' . $type;
                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'email' => $to,
                        'first_name' => trim((string) ($row['mgr_first_name'] ?? '')),
                        'type' => $type,
                        'vendors' => [],
                    ];
                }
                $buckets[$key]['vendors'][] = [
                    'id' => $vid,
                    'name' => (string) ($row['vendor_name'] ?? 'Vendor'),
                    'deadline' => (string) $deadline,
                ];
            }
        }

        foreach ($buckets as $bucket) {
            if ($bucket['vendors'] === []) {
                continue;
            }
            $type = $bucket['type'];
            $subj = self::cancellationReminderSubject($type);
            $body = self::buildCancellationReminderHtml(
                $bucket['first_name'],
                $type,
                $bucket['vendors']
            );
            $r = $sendEmail($bucket['email'], $subj, $body);
            if ($r === true) {
                try {
                    $ins = $pdo->prepare(
                        'INSERT IGNORE INTO reminder_sent (vendor_item_id, reminder_type, sent_on_date) VALUES (?,?, CURDATE())'
                    );
                    foreach ($bucket['vendors'] as $vendor) {
                        $ins->execute([(int) $vendor['id'], $type]);
                    }
                    ++$sent;
                } catch (PDOException $e) {
                    $errors[] = $e->getMessage();
                }
            } else {
                $errors[] = $bucket['email'] . ': ' . self::sendFailureMessage($r);
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    /**
     * Pre-emptive approaching renewal reminders (annual / semi-annual / quarterly lead days).
     * One email per eligible user per day when any visible vendor hits a lead window.
     *
     * @param callable(string,string,string):mixed $sendEmail
     * @return array{sent:int, errors:array<int, string>, debug:array<int, array<string, mixed>>}
     */
    public static function runApproachingRenewalReminders(PDO $pdo, callable $sendEmail): array
    {
        $sent = 0;
        $errors = [];
        $debug = [];
        $today = new DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');

        $users = self::eligibleReminderUsers($pdo);
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $email = (string) $u['email'];
            $entry = [
                'user_id' => $uid,
                'email' => $email,
                'action' => null,
                'lines' => 0,
                'send_result' => null,
            ];

            $hits = [];
            foreach (self::visibleProjectItemsForUser($pdo, $uid) as $bundle) {
                if (!$bundle['org_reminders_enabled']) {
                    continue;
                }
                $projectName = $bundle['project_name'];
                foreach ($bundle['rows'] as $row) {
                    $status = VendorService::resolveStatusFromRow($row);
                    if (!in_array($status, [
                        VendorService::STATUS_KEEP,
                        VendorService::STATUS_PENDING,
                        VendorService::STATUS_QUESTION,
                        VendorService::STATUS_UNKNOWN,
                    ], true)) {
                        continue;
                    }
                    $freq = (string) ($row['frequency'] ?? '');
                    if (!isset(self::RENEWAL_LEAD_TYPES[$freq])) {
                        continue;
                    }
                    $next = self::estimateNextRenewal($row);
                    if ($next === null) {
                        continue;
                    }
                    $daysUntil = (int) $today->diff($next)->format('%r%a');
                    if ($daysUntil < 0) {
                        continue;
                    }
                    $typeMap = self::RENEWAL_LEAD_TYPES[$freq];
                    if (!isset($typeMap[$daysUntil])) {
                        continue;
                    }
                    $reminderType = $typeMap[$daysUntil];
                    $vid = (int) ($row['id'] ?? 0);
                    if ($vid <= 0 || self::renewalReminderAlreadySent($pdo, $vid, $reminderType, $todayStr)) {
                        continue;
                    }
                    $hits[] = [
                        'vendor_item_id' => $vid,
                        'reminder_type' => $reminderType,
                        'frequency' => $freq,
                        'project_name' => $projectName,
                        'vendor_name' => (string) ($row['vendor_name'] ?? 'Vendor'),
                        'renews_on' => $next,
                        'cost_per_period' => (float) ($row['cost_per_period'] ?? 0),
                        'days_until' => $daysUntil,
                    ];
                }
            }

            $entry['lines'] = count($hits);
            if (count($hits) === 0) {
                $entry['action'] = 'skip_no_hits';
                $debug[] = $entry;
                continue;
            }

            $firstName = trim((string) ($u['first_name'] ?? ''));
            $html = self::buildApproachingRenewalsHtml($hits, $today, $firstName);
            $subj = self::approachingRenewalsSubject($hits);
            $r = $sendEmail($email, $subj, $html);
            $entry['send_result'] = self::summarizeSendResult($r);
            if ($r === true) {
                foreach ($hits as $hit) {
                    self::markRenewalReminderSent($pdo, $hit['vendor_item_id'], $hit['reminder_type'], $todayStr);
                }
                ++$sent;
                $entry['action'] = 'sent_ok';
            } else {
                $entry['action'] = 'send_failed';
                $errors[] = $email . ': ' . self::sendFailureMessage($r);
            }
            $debug[] = $entry;
        }

        return ['sent' => $sent, 'errors' => $errors, 'debug' => $debug];
    }

    /**
     * @deprecated Use runApproachingRenewalReminders. Kept for any legacy callers.
     *
     * @param callable(string,string,string):mixed $sendEmail
     * @return array{sent:int, errors:array<int, string>, debug:array<int, array<string, mixed>>}
     */
    public static function runMonthlyRenewalSummaries(PDO $pdo, callable $sendEmail): array
    {
        $r = self::runApproachingRenewalReminders($pdo, $sendEmail);
        $r['year_month'] = (new DateTimeImmutable('first day of this month'))->modify('+1 month')->format('Y-m');

        return $r;
    }

    /**
     * Monthly digest of raw charges last month for quarterly / semi-annual / annual vendors.
     * Sends on days 1–3 of the month (or whenever force clears the period and cron re-runs).
     *
     * @param callable(string,string,string):mixed $sendEmail
     * @param bool $forceIgnoreDayGate When true, skip the day-of-month gate (still respects per-user dedup unless cleared).
     * @return array{sent:int, errors:array<int, string>, debug:array<int, array<string, mixed>>, period_key:string, skipped_day_gate?:bool}
     */
    public static function runNonMonthlyChargesDigest(PDO $pdo, callable $sendEmail, bool $forceIgnoreDayGate = false): array
    {
        $sent = 0;
        $errors = [];
        $debug = [];
        $today = new DateTimeImmutable('today');
        $prevMonth = $today->modify('first day of last month');
        $periodKey = $prevMonth->format('Y-m');
        $periodStart = $prevMonth->format('Y-m-01');
        $periodEnd = $prevMonth->modify('last day of this month')->format('Y-m-d');
        $monthLabel = $prevMonth->format('F Y');

        if (!$forceIgnoreDayGate) {
            $day = (int) $today->format('j');
            if ($day < 1 || $day > 3) {
                return [
                    'sent' => 0,
                    'errors' => [],
                    'debug' => [],
                    'period_key' => $periodKey,
                    'skipped_day_gate' => true,
                ];
            }
        }

        $users = self::eligibleReminderUsers($pdo);
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $email = (string) $u['email'];
            $entry = [
                'user_id' => $uid,
                'email' => $email,
                'period_key' => $periodKey,
                'action' => null,
                'lines' => 0,
                'send_result' => null,
            ];

            if (self::digestAlreadySent($pdo, $uid, self::DIGEST_NON_MONTHLY, $periodKey)) {
                $entry['action'] = 'skip_already_sent';
                $debug[] = $entry;
                continue;
            }

            $linesByProject = [];
            $totalAmount = 0.0;
            $totalCount = 0;

            foreach (self::visibleProjectItemsForUser($pdo, $uid) as $bundle) {
                if (!$bundle['org_reminders_enabled']) {
                    continue;
                }
                $projectId = $bundle['project_id'];
                $orgId = $bundle['org_id'];
                $projectName = $bundle['project_name'];

                $freqByVendor = [];
                $annualByVendor = [];
                foreach ($bundle['rows'] as $row) {
                    $freq = (string) ($row['frequency'] ?? '');
                    if (!in_array($freq, self::NON_MONTHLY_FREQUENCIES, true)) {
                        continue;
                    }
                    $norm = VendorService::normalizeVendorName((string) ($row['vendor_name'] ?? ''));
                    if ($norm === '') {
                        continue;
                    }
                    $freqByVendor[$norm] = $freq;
                    $annualByVendor[$norm] = (float) ($row['annual_cost'] ?? 0);
                }
                if ($freqByVendor === []) {
                    continue;
                }

                $txSt = $pdo->prepare(
                    'SELECT vendor_name, vendor_name_normalized, transaction_date, amount
                     FROM vendor_raw_transactions
                     WHERE org_id = ? AND project_id = ?
                       AND transaction_date >= ? AND transaction_date <= ?
                     ORDER BY transaction_date ASC, id ASC'
                );
                $txSt->execute([$orgId, $projectId, $periodStart, $periodEnd]);
                while ($tx = $txSt->fetch(PDO::FETCH_ASSOC)) {
                    $norm = (string) ($tx['vendor_name_normalized'] ?? '');
                    if ($norm === '' || !isset($freqByVendor[$norm])) {
                        continue;
                    }
                    $amount = (float) ($tx['amount'] ?? 0);
                    $linesByProject[$projectName][] = [
                        'vendor_name' => (string) ($tx['vendor_name'] ?? ''),
                        'transaction_date' => (string) ($tx['transaction_date'] ?? ''),
                        'amount' => $amount,
                        'frequency' => $freqByVendor[$norm],
                        'annual_cost' => $annualByVendor[$norm] ?? 0.0,
                    ];
                    $totalAmount += $amount;
                    ++$totalCount;
                }
            }

            $entry['lines'] = $totalCount;
            if ($totalCount === 0) {
                $entry['action'] = 'skip_no_charges';
                $debug[] = $entry;
                continue;
            }

            $html = self::buildNonMonthlyChargesHtml($linesByProject, $monthLabel, $totalCount, $totalAmount);
            $subj = 'Non-monthly charges in ' . $monthLabel;
            $r = $sendEmail($email, $subj, $html);
            $entry['send_result'] = self::summarizeSendResult($r);
            if ($r === true) {
                self::markDigestSent($pdo, $uid, self::DIGEST_NON_MONTHLY, $periodKey);
                ++$sent;
                $entry['action'] = 'sent_ok';
            } else {
                $entry['action'] = 'send_failed';
                $errors[] = $email . ': ' . self::sendFailureMessage($r);
            }
            $debug[] = $entry;
        }

        return ['sent' => $sent, 'errors' => $errors, 'debug' => $debug, 'period_key' => $periodKey];
    }

    /**
     * Weekly cancellation progress scorecard (pending potential + confirmed cancelled).
     * Sends on Mondays (or when force clears the ISO-week period).
     *
     * @param callable(string,string,string):mixed $sendEmail
     * @param bool $forceIgnoreWeekdayGate
     * @return array{sent:int, errors:array<int, string>, debug:array<int, array<string, mixed>>, period_key:string, skipped_weekday_gate?:bool}
     */
    public static function runCancelProgressDigest(PDO $pdo, callable $sendEmail, bool $forceIgnoreWeekdayGate = false): array
    {
        $sent = 0;
        $errors = [];
        $debug = [];
        $today = new DateTimeImmutable('today');
        $periodKey = $today->format('o-\WW');
        $weekLabel = $today->format('M j, Y');

        if (!$forceIgnoreWeekdayGate && (int) $today->format('N') !== 1) {
            return [
                'sent' => 0,
                'errors' => [],
                'debug' => [],
                'period_key' => $periodKey,
                'skipped_weekday_gate' => true,
            ];
        }

        $users = self::eligibleReminderUsers($pdo);
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $email = (string) $u['email'];
            $entry = [
                'user_id' => $uid,
                'email' => $email,
                'period_key' => $periodKey,
                'action' => null,
                'projects' => 0,
                'send_result' => null,
            ];

            if (self::digestAlreadySent($pdo, $uid, self::DIGEST_CANCEL_PROGRESS, $periodKey)) {
                $entry['action'] = 'skip_already_sent';
                $debug[] = $entry;
                continue;
            }

            $projectSections = [];
            $grandPendingCount = 0;
            $grandPendingSum = 0.0;
            $grandConfirmedCount = 0;
            $grandConfirmedSum = 0.0;

            foreach (self::visibleProjectItemsForUser($pdo, $uid) as $bundle) {
                if (!$bundle['org_reminders_enabled']) {
                    continue;
                }
                $pending = [];
                $confirmed = [];
                $pendingSum = 0.0;
                $confirmedSum = 0.0;
                foreach ($bundle['rows'] as $row) {
                    $status = VendorService::resolveStatusFromRow($row);
                    $vendor = (string) ($row['vendor_name'] ?? 'Vendor');
                    if ($status === VendorService::STATUS_RENEGOTIATED) {
                        $savings = (float) ($row['renegotiated_annual_savings'] ?? 0);
                        if ($savings > 0) {
                            $confirmed[] = ['vendor_name' => $vendor, 'annual_cost' => $savings];
                            $confirmedSum += $savings;
                        }
                        continue;
                    }
                    $freq = VendorService::normalizeFrequencyValue($row['frequency'] ?? '');
                    $cost = (float) ($row['cost_per_period'] ?? 0);
                    $annual = (float) ($row['annual_cost'] ?? 0);
                    if ($freq === 'irregular' || $cost <= 0 || $annual <= 0) {
                        continue;
                    }
                    if ($status === VendorService::STATUS_MARK) {
                        $pending[] = ['vendor_name' => $vendor, 'annual_cost' => $annual];
                        $pendingSum += $annual;
                    } elseif ($status === VendorService::STATUS_CANCELLED) {
                        $confirmed[] = ['vendor_name' => $vendor, 'annual_cost' => $annual];
                        $confirmedSum += $annual;
                    }
                }
                if ($pending === [] && $confirmed === []) {
                    continue;
                }
                $projectSections[] = [
                    'project_name' => $bundle['project_name'],
                    'pending' => $pending,
                    'pending_sum' => $pendingSum,
                    'confirmed' => $confirmed,
                    'confirmed_sum' => $confirmedSum,
                ];
                $grandPendingCount += count($pending);
                $grandPendingSum += $pendingSum;
                $grandConfirmedCount += count($confirmed);
                $grandConfirmedSum += $confirmedSum;
            }

            $entry['projects'] = count($projectSections);
            if ($projectSections === []) {
                $entry['action'] = 'skip_no_items';
                $debug[] = $entry;
                continue;
            }

            $html = self::buildCancelProgressHtml(
                $projectSections,
                $weekLabel,
                $grandPendingCount,
                $grandPendingSum,
                $grandConfirmedCount,
                $grandConfirmedSum
            );
            $subj = 'Cancellation progress — week of ' . $weekLabel;
            $r = $sendEmail($email, $subj, $html);
            $entry['send_result'] = self::summarizeSendResult($r);
            if ($r === true) {
                self::markDigestSent($pdo, $uid, self::DIGEST_CANCEL_PROGRESS, $periodKey);
                ++$sent;
                $entry['action'] = 'sent_ok';
            } else {
                $entry['action'] = 'send_failed';
                $errors[] = $email . ': ' . self::sendFailureMessage($r);
            }
            $debug[] = $entry;
        }

        return ['sent' => $sent, 'errors' => $errors, 'debug' => $debug, 'period_key' => $periodKey];
    }

    /**
     * Weekly digest of @mentions in vendor chat over the last 7 days.
     * Sends on Fridays (or when force bypasses the weekday gate).
     *
     * @param callable(string,string,string):mixed $sendEmail
     * @return array{sent:int, errors:array<int, string>, debug:array<int, array<string, mixed>>, period_key:string, skipped_weekday_gate?:bool}
     */
    public static function runChatMentionsDigest(PDO $pdo, callable $sendEmail, bool $forceIgnoreWeekdayGate = false): array
    {
        $sent = 0;
        $errors = [];
        $debug = [];
        $today = new DateTimeImmutable('today');
        $periodKey = $today->format('o-\WW');
        $since = $today->modify('-7 days')->format('Y-m-d 00:00:00');

        if (!$forceIgnoreWeekdayGate && (int) $today->format('N') !== 5) {
            return [
                'sent' => 0,
                'errors' => [],
                'debug' => [],
                'period_key' => $periodKey,
                'skipped_weekday_gate' => true,
            ];
        }

        $users = self::eligibleReminderUsers($pdo);
        $eligible = [];
        foreach ($users as $u) {
            $uid = (int) ($u['id'] ?? 0);
            if ($uid > 0) {
                $eligible[$uid] = $u;
            }
        }
        if ($eligible === []) {
            return ['sent' => 0, 'errors' => [], 'debug' => [], 'period_key' => $periodKey];
        }

        try {
            $st = $pdo->prepare(
                'SELECT t.mentioned_user_id,
                        m.id AS message_id,
                        m.message,
                        m.created_at,
                        m.username_snapshot,
                        m.project_id,
                        m.vendor_item_id,
                        COALESCE(
                            NULLIF(TRIM(author.display_name), \'\'),
                            NULLIF(TRIM(author.username), \'\'),
                            NULLIF(TRIM(m.username_snapshot), \'\'),
                            \'Teammate\'
                        ) AS author_name,
                        COALESCE(NULLIF(TRIM(cci.vendor_name), \'\'), \'Vendor\') AS vendor_name,
                        COALESCE(NULLIF(TRIM(p.name), \'\'), \'Project\') AS project_name
                 FROM vendor_item_chat_mentions t
                 INNER JOIN vendor_item_chat_messages m ON m.id = t.message_id
                 LEFT JOIN cost_calculator_items cci ON cci.id = m.vendor_item_id
                 LEFT JOIN projects p ON p.id = m.project_id
                 LEFT JOIN users author ON author.id = m.user_id
                 WHERE m.created_at >= ?
                   AND m.is_action_log = 0
                   AND m.user_id <> t.mentioned_user_id
                 ORDER BY m.created_at ASC, m.id ASC'
            );
            $st->execute([$since]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [
                'sent' => 0,
                'errors' => [$e->getMessage()],
                'debug' => [],
                'period_key' => $periodKey,
            ];
        }

        /** @var array<int, list<array<string, mixed>>> $byUser */
        $byUser = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['mentioned_user_id'] ?? 0);
            if ($uid <= 0 || !isset($eligible[$uid])) {
                continue;
            }
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [];
            }
            $byUser[$uid][] = $row;
        }

        foreach ($byUser as $uid => $mentions) {
            $u = $eligible[$uid];
            $email = (string) ($u['email'] ?? '');
            $entry = [
                'user_id' => $uid,
                'email' => $email,
                'period_key' => $periodKey,
                'action' => null,
                'mentions' => count($mentions),
                'send_result' => null,
            ];

            if ($email === '') {
                $entry['action'] = 'skip_no_email';
                $debug[] = $entry;
                continue;
            }

            if (self::digestAlreadySent($pdo, $uid, self::DIGEST_CHAT_MENTIONS, $periodKey)) {
                $entry['action'] = 'skip_already_sent';
                $debug[] = $entry;
                continue;
            }

            $snippets = array_slice($mentions, 0, self::CHAT_MENTION_SNIPPET_CAP);
            $html = self::buildChatMentionsHtml(
                (string) ($u['first_name'] ?? ''),
                $snippets,
                count($mentions)
            );
            $subj = 'You were tagged in Savvy Saver chat';
            $r = $sendEmail($email, $subj, $html);
            $entry['send_result'] = self::summarizeSendResult($r);
            if ($r === true) {
                self::markDigestSent($pdo, $uid, self::DIGEST_CHAT_MENTIONS, $periodKey);
                ++$sent;
                $entry['action'] = 'sent_ok';
            } else {
                $entry['action'] = 'send_failed';
                $errors[] = $email . ': ' . self::sendFailureMessage($r);
            }
            $debug[] = $entry;
        }

        return ['sent' => $sent, 'errors' => $errors, 'debug' => $debug, 'period_key' => $periodKey];
    }

    /**
     * Clear dedup rows so --force-resend can re-deliver.
     *
     * @return array{renewal_reminder_sent:int, non_monthly_charges:int, cancel_progress:int, chat_mentions:int}
     */
    public static function clearForceResendDedup(PDO $pdo): array
    {
        $today = new DateTimeImmutable('today');
        $prevMonthKey = $today->modify('first day of last month')->format('Y-m');
        $weekKey = $today->format('o-\WW');

        $delRenewal = $pdo->prepare('DELETE FROM renewal_reminder_sent WHERE sent_on_date = ?');
        $delRenewal->execute([$today->format('Y-m-d')]);

        $delCharges = $pdo->prepare(
            'DELETE FROM email_digest_sent WHERE digest_type = ? AND period_key = ?'
        );
        $delCharges->execute([self::DIGEST_NON_MONTHLY, $prevMonthKey]);

        $delCancel = $pdo->prepare(
            'DELETE FROM email_digest_sent WHERE digest_type = ? AND period_key = ?'
        );
        $delCancel->execute([self::DIGEST_CANCEL_PROGRESS, $weekKey]);

        $delChat = $pdo->prepare(
            'DELETE FROM email_digest_sent WHERE digest_type = ? AND period_key = ?'
        );
        $delChat->execute([self::DIGEST_CHAT_MENTIONS, $weekKey]);

        return [
            'renewal_reminder_sent' => $delRenewal->rowCount(),
            'non_monthly_charges' => $delCharges->rowCount(),
            'cancel_progress' => $delCancel->rowCount(),
            'chat_mentions' => $delChat->rowCount(),
        ];
    }

    /**
     * @return array<int, array{id:int, email:string, first_name:string}>
     */
    private static function eligibleReminderUsers(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT u.id, u.email, u.first_name
             FROM users u
             WHERE u.email IS NOT NULL AND TRIM(u.email) <> \'\'
               AND (u.deadline_reminders_enabled IS NULL OR u.deadline_reminders_enabled = 1)'
        )->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array{
     *   org_id:int,
     *   project_id:int,
     *   project_name:string,
     *   org_reminders_enabled:bool,
     *   rows:array<int, array<string, mixed>>
     * }>
     */
    private static function visibleProjectItemsForUser(PDO $pdo, int $userId): array
    {
        $memberships = $pdo->prepare(
            'SELECT uo.org_id, uo.role, p.id AS project_id, p.name AS project_name,
                    o.deadline_reminders_enabled AS org_reminders
             FROM user_organizations uo
             INNER JOIN organizations o ON o.id = uo.org_id
             INNER JOIN projects p ON p.org_id = uo.org_id
             WHERE uo.user_id = ? AND uo.is_disabled = 0'
        );
        $memberships->execute([$userId]);
        $out = [];
        while ($mem = $memberships->fetch(PDO::FETCH_ASSOC)) {
            $projectId = (int) ($mem['project_id'] ?? 0);
            $orgId = (int) ($mem['org_id'] ?? 0);
            $role = OrgRole::normalize((string) ($mem['role'] ?? OrgRole::ROLE_EXECUTIVE));
            if ($projectId <= 0 || $orgId <= 0) {
                continue;
            }
            // Executive: no project emails. Cost Owner: assigned-visibility rows only.
            // Org Admin / Finance Admin / Project Manager: all rows.
            if (OrgRole::isExecutive($role)) {
                continue;
            }
            if (OrgRole::isCostOwner($role)) {
                $q = $pdo->prepare(
                    'SELECT * FROM cost_calculator_items WHERE org_id = ? AND project_id = ? AND (
                        visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = ?)
                    )'
                );
                $q->execute([$orgId, $projectId, $userId]);
            } elseif (OrgRole::canReceiveAllProjectEmails($role)) {
                $q = $pdo->prepare('SELECT * FROM cost_calculator_items WHERE org_id = ? AND project_id = ?');
                $q->execute([$orgId, $projectId]);
            } else {
                continue;
            }
            $projectRows = $q->fetchAll(PDO::FETCH_ASSOC);
            $out[] = [
                'org_id' => $orgId,
                'project_id' => $projectId,
                'project_name' => (string) ($mem['project_name'] ?? ('Project #' . $projectId)),
                'org_reminders_enabled' => !isset($mem['org_reminders']) || (int) $mem['org_reminders'] === 1,
                'rows' => is_array($projectRows) ? $projectRows : [],
            ];
        }

        return $out;
    }

    private static function renewalReminderAlreadySent(PDO $pdo, int $vendorItemId, string $type, string $date): bool
    {
        $check = $pdo->prepare(
            'SELECT 1 FROM renewal_reminder_sent WHERE vendor_item_id = ? AND reminder_type = ? AND sent_on_date = ?'
        );
        $check->execute([$vendorItemId, $type, $date]);

        return (bool) $check->fetchColumn();
    }

    private static function markRenewalReminderSent(PDO $pdo, int $vendorItemId, string $type, string $date): void
    {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO renewal_reminder_sent (vendor_item_id, reminder_type, sent_on_date) VALUES (?,?,?)'
        );
        $ins->execute([$vendorItemId, $type, $date]);
    }

    private static function digestAlreadySent(PDO $pdo, int $userId, string $digestType, string $periodKey): bool
    {
        $chk = $pdo->prepare(
            'SELECT 1 FROM email_digest_sent WHERE user_id = ? AND digest_type = ? AND period_key = ?'
        );
        $chk->execute([$userId, $digestType, $periodKey]);

        return (bool) $chk->fetchColumn();
    }

    private static function markDigestSent(PDO $pdo, int $userId, string $digestType, string $periodKey): void
    {
        $ins = $pdo->prepare(
            'INSERT INTO email_digest_sent (user_id, digest_type, period_key, sent_at) VALUES (?,?,?,NOW())'
        );
        $ins->execute([$userId, $digestType, $periodKey]);
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     */
    private static function approachingRenewalsSubject(array $hits): string
    {
        $months = [];
        foreach ($hits as $hit) {
            $renews = $hit['renews_on'] ?? null;
            if ($renews instanceof DateTimeImmutable) {
                $months[$renews->format('F')] = true;
            }
        }
        $monthKeys = array_keys($months);
        if (count($monthKeys) === 1) {
            return 'Savvy Saver heads-up: Review potential ' . $monthKeys[0] . ' charges before they hit';
        }

        return 'Savvy Saver heads-up: Review potential upcoming charges before they hit';
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     */
    private static function buildApproachingRenewalsHtml(
        array $hits,
        DateTimeImmutable $today,
        string $firstName = ''
    ): string {
        $monthPhrase = 'upcoming';
        $months = [];
        foreach ($hits as $hit) {
            $renews = $hit['renews_on'] ?? null;
            if ($renews instanceof DateTimeImmutable) {
                $months[$renews->format('F')] = true;
            }
        }
        $monthKeys = array_keys($months);
        if (count($monthKeys) === 1) {
            $monthPhrase = $monthKeys[0];
        }

        $greeting = self::emailGreeting($firstName);
        $body = $greeting;
        $body .= '<p>Savvy Saver identified potentially unexpected charges your business may see'
            . ($monthPhrase === 'upcoming' ? ' soon' : ' in ' . htmlspecialchars($monthPhrase, ENT_QUOTES, 'UTF-8'))
            . '. Here’s an early heads-up so you can review them before they happen—and avoid paying for anything you no longer need.</p>';
        $body .= '<p><strong>Your potential'
            . ($monthPhrase === 'upcoming' ? ' upcoming' : ' ' . htmlspecialchars($monthPhrase, ENT_QUOTES, 'UTF-8'))
            . ' charges:</strong></p>';

        usort($hits, static function (array $a, array $b): int {
            /** @var DateTimeImmutable $ra */
            $ra = $a['renews_on'];
            /** @var DateTimeImmutable $rb */
            $rb = $b['renews_on'];
            return $ra <=> $rb;
        });

        $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 16px 0;">';
        $body .= '<tr>'
            . '<th align="left" style="padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:13px;color:#6b7280;">Vendor</th>'
            . '<th align="left" style="padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:13px;color:#6b7280;">Estimated billing date</th>'
            . '</tr>';
        foreach ($hits as $hit) {
            /** @var DateTimeImmutable $renews */
            $renews = $hit['renews_on'];
            $body .= '<tr>'
                . '<td style="padding:10px;border-bottom:1px solid #e5e7eb;font-size:15px;">'
                . htmlspecialchars((string) $hit['vendor_name'], ENT_QUOTES, 'UTF-8')
                . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e5e7eb;font-size:15px;">'
                . htmlspecialchars($renews->format('F j, Y'), ENT_QUOTES, 'UTF-8')
                . '</td>'
                . '</tr>';
        }
        $body .= '</table>';

        $body .= '<p><strong>Your next step:</strong><br>'
            . 'Review each item and decide whether to keep it, make a change, or cancel it if it’s no longer needed. '
            . 'Before making changes, confirm what the charge covers, check the vendor’s terms, and contact them before any applicable cancellation deadline.</p>';
        $body .= '<p>If you cancel an item, update your Savvy Saver project to indicate that you no longer expect that charge. '
            . 'Updating your project does not cancel the service with the vendor.</p>';
        $body .= '<p style="color:#6b7280;font-size:13px;">These are potential charges identified by Savvy Saver, not confirmed bills. '
            . 'Actual billing dates and amounts may vary, and this list may not include every upcoming charge.</p>';
        $body .= '<p>— Your Savvy Saver team</p>';

        return \csEmailHtmlWrap(
            'Review potential charges before they hit',
            $body,
            'Log in to review upcoming charges',
            \csEmailLoginUrl(),
            null,
            'Savvy Saver heads-up: review potential charges before they hit'
        );
    }

    /**
     * @param array<int, array{id:int, name:string, deadline:string}> $vendors
     */
    private static function cancellationReminderSubject(string $type): string
    {
        if ($type === 't_minus_7') {
            return 'Savvy Saver | Cancellation deadline in 7 days';
        }
        if ($type === 't_plus_7') {
            return 'Savvy Saver | Cancellation deadline was 7 days ago';
        }

        return 'Savvy Saver | Cancellation deadline today';
    }

    /**
     * @param array<int, array{id:int, name:string, deadline:string}> $vendors
     */
    private static function buildCancellationReminderHtml(
        string $firstName,
        string $type,
        array $vendors
    ): string {
        $deadlines = [];
        foreach ($vendors as $vendor) {
            $deadlines[$vendor['deadline']] = true;
        }
        $uniqueDeadlines = array_keys($deadlines);
        $singleDeadline = count($uniqueDeadlines) === 1;
        $deadlineFormatted = '';
        if ($singleDeadline) {
            try {
                $deadlineFormatted = (new DateTimeImmutable($uniqueDeadlines[0]))->format('F j, Y');
            } catch (\Exception $e) {
                $deadlineFormatted = $uniqueDeadlines[0];
            }
        }

        $greeting = self::emailGreeting($firstName);
        $body = $greeting;

        if ($type === 'deadline_day') {
            $body .= '<p style="margin:0 0 12px 0;font-size:14px;color:#6b7280;">You set today as your cancellation deadline.</p>';
            $body .= '<p>You marked this list of vendors for cancellation in Savvy Saver'
                . ($singleDeadline
                    ? ' and set <strong>' . htmlspecialchars($deadlineFormatted, ENT_QUOTES, 'UTF-8') . '</strong> as your deadline.'
                    : '.')
                . '</p>';
        } elseif ($type === 't_minus_7') {
            $body .= '<p>You marked this list of vendors for cancellation in Savvy Saver'
                . ($singleDeadline
                    ? ' and set <strong>' . htmlspecialchars($deadlineFormatted, ENT_QUOTES, 'UTF-8') . '</strong> as your deadline (7 days away).'
                    : ' with deadlines 7 days away.')
                . '</p>';
        } else {
            $body .= '<p>You marked this list of vendors for cancellation in Savvy Saver'
                . ($singleDeadline
                    ? ' and set <strong>' . htmlspecialchars($deadlineFormatted, ENT_QUOTES, 'UTF-8') . '</strong> as your deadline (7 days ago).'
                    : ' with deadlines that passed 7 days ago.')
                . '</p>';
        }

        $body .= '<p style="margin:16px 0 8px 0;">';
        foreach ($vendors as $vendor) {
            $line = htmlspecialchars($vendor['name'], ENT_QUOTES, 'UTF-8');
            if (!$singleDeadline) {
                try {
                    $df = (new DateTimeImmutable($vendor['deadline']))->format('F j, Y');
                } catch (\Exception $e) {
                    $df = $vendor['deadline'];
                }
                $line .= ' <span style="color:#6b7280;">(' . htmlspecialchars($df, ENT_QUOTES, 'UTF-8') . ')</span>';
            }
            $body .= '<strong style="display:block;margin:0 0 6px 0;">' . $line . '</strong>';
        }
        $body .= '</p>';

        $body .= '<p>If you haven’t finished, take a moment to review the cancellation requirements and contact the vendor to complete the next step.</p>';
        $body .= '<p>If you’ve already canceled, update the vendor’s status in your project to keep your review current.</p>';
        $body .= '<p>Plans changed? You can revisit your decision or update the deadline inside your project.</p>';
        $body .= '<p>One decision. One next step. Keep your review moving.</p>';

        $heading = 'Cancellation deadline today';
        if ($type === 't_minus_7') {
            $heading = 'Cancellation deadline in 7 days';
        } elseif ($type === 't_plus_7') {
            $heading = 'Cancellation deadline was 7 days ago';
        }

        return \csEmailHtmlWrap(
            $heading,
            $body,
            'Log In to Savvy Saver',
            \csEmailLoginUrl(),
            'Savvy Saver | Smart Review Reminder',
            $heading
        );
    }

    private static function emailGreeting(string $firstName): string
    {
        $name = trim($firstName);
        if ($name === '' || strcasecmp($name, 'there') === 0) {
            return '<p>Hi,</p>';
        }

        return '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>';
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $linesByProject
     */
    private static function buildNonMonthlyChargesHtml(
        array $linesByProject,
        string $monthLabel,
        int $totalCount,
        float $totalAmount
    ): string {
        $body = '<p>Non-monthly recurring charges recorded in <strong>'
            . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8')
            . '</strong> (' . $totalCount . ' charge'
            . ($totalCount === 1 ? '' : 's')
            . ', $' . number_format($totalAmount, 2) . ' total).</p>';
        foreach ($linesByProject as $projectName => $lines) {
            $body .= '<h3 style="margin:16px 0 8px 0;font-size:16px;">'
                . htmlspecialchars((string) $projectName, ENT_QUOTES, 'UTF-8')
                . '</h3><ul>';
            foreach ($lines as $line) {
                $body .= '<li>'
                    . htmlspecialchars((string) $line['vendor_name'], ENT_QUOTES, 'UTF-8')
                    . ' — ' . htmlspecialchars((string) $line['transaction_date'], ENT_QUOTES, 'UTF-8')
                    . ' — $' . number_format((float) $line['amount'], 2)
                    . ' (' . htmlspecialchars((string) $line['frequency'], ENT_QUOTES, 'UTF-8') . ')'
                    . ' — annual cost $' . number_format((float) $line['annual_cost'], 2)
                    . '</li>';
            }
            $body .= '</ul>';
        }
        $body .= '<p>Open Savvy Saver to review these vendors.</p>';

        return \csEmailHtmlWrap(
            'Non-monthly charges in ' . $monthLabel,
            $body,
            'Log In to Savvy Saver',
            \csEmailLoginUrl()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $projectSections
     */
    private static function buildCancelProgressHtml(
        array $projectSections,
        string $weekLabel,
        int $grandPendingCount,
        float $grandPendingSum,
        int $grandConfirmedCount,
        float $grandConfirmedSum
    ): string {
        $body = '<p>Cancellation progress as of <strong>' . htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
        $body .= '<p><strong>Across your projects:</strong> '
            . $grandPendingCount . ' pending ($' . number_format($grandPendingSum, 2) . ' potential annual)'
            . '; '
            . $grandConfirmedCount . ' confirmed ($' . number_format($grandConfirmedSum, 2) . ' confirmed annual)'
            . '.</p>';

        foreach ($projectSections as $section) {
            $body .= '<h3 style="margin:16px 0 8px 0;font-size:16px;">'
                . htmlspecialchars((string) $section['project_name'], ENT_QUOTES, 'UTF-8')
                . '</h3>';
            $pending = $section['pending'];
            $confirmed = $section['confirmed'];
            $body .= '<p>Pending (mark for cancellation): <strong>'
                . count($pending) . '</strong> — $'
                . number_format((float) $section['pending_sum'], 2) . ' annual</p>';
            $body .= self::buildCappedVendorList($pending);
            $body .= '<p>Confirmed savings: <strong>'
                . count($confirmed) . '</strong> — $'
                . number_format((float) $section['confirmed_sum'], 2) . ' annual</p>';
            $body .= self::buildCappedVendorList($confirmed);
        }
        $body .= '<p>Open Savvy Saver to continue cancellations or confirm completed ones.</p>';

        return \csEmailHtmlWrap(
            'Cancellation progress — week of ' . $weekLabel,
            $body,
            'Log In to Savvy Saver',
            \csEmailLoginUrl()
        );
    }

    /**
     * @param list<array<string, mixed>> $snippets
     */
    private static function buildChatMentionsHtml(string $firstName, array $snippets, int $totalCount): string
    {
        $greeting = self::emailGreeting($firstName);
        $body = $greeting;
        $body .= '<p>You were tagged in vendor chat in Savvy Saver over the past week'
            . ($totalCount > 0
                ? ' (<strong>' . (int) $totalCount . '</strong> mention'
                    . ($totalCount === 1 ? '' : 's') . ').'
                : '.')
            . '</p>';

        if ($totalCount > self::CHAT_MENTION_SNIPPET_CAP) {
            $body .= '<p style="margin:0 0 12px 0;font-size:14px;color:#6b7280;">Showing the first '
                . self::CHAT_MENTION_SNIPPET_CAP
                . ' mentions. Open Savvy Saver to see the rest.</p>';
        }

        /** @var array<string, array<string, list<array<string, mixed>>>> $grouped */
        $grouped = [];
        foreach ($snippets as $snippet) {
            $project = (string) ($snippet['project_name'] ?? 'Project');
            $vendor = (string) ($snippet['vendor_name'] ?? 'Vendor');
            if (!isset($grouped[$project])) {
                $grouped[$project] = [];
            }
            if (!isset($grouped[$project][$vendor])) {
                $grouped[$project][$vendor] = [];
            }
            $grouped[$project][$vendor][] = $snippet;
        }

        foreach ($grouped as $projectName => $vendors) {
            $body .= '<h3 style="margin:16px 0 8px 0;font-size:16px;">'
                . htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8')
                . '</h3>';
            foreach ($vendors as $vendorName => $items) {
                $body .= '<p style="margin:10px 0 6px 0;font-weight:700;">'
                    . htmlspecialchars($vendorName, ENT_QUOTES, 'UTF-8')
                    . '</p>';
                foreach ($items as $item) {
                    $author = trim((string) ($item['author_name'] ?? 'Teammate'));
                    $when = '';
                    try {
                        $when = (new DateTimeImmutable((string) ($item['created_at'] ?? '')))->format('M j, Y g:i A');
                    } catch (\Exception $e) {
                        $when = (string) ($item['created_at'] ?? '');
                    }
                    $text = self::truncateChatSnippet((string) ($item['message'] ?? ''));
                    $body .= '<p style="margin:0 0 10px 0;padding:10px 12px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;">'
                        . '<span style="display:block;font-size:12px;color:#6b7280;margin:0 0 4px 0;">'
                        . htmlspecialchars($author, ENT_QUOTES, 'UTF-8')
                        . ($when !== '' ? ' · ' . htmlspecialchars($when, ENT_QUOTES, 'UTF-8') : '')
                        . '</span>'
                        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
                        . '</p>';
                }
            }
        }

        $body .= '<p>Open Savvy Saver to reply in the vendor chat thread.</p>';

        return \csEmailHtmlWrap(
            'You were tagged in Savvy Saver chat',
            $body,
            'Log In to Savvy Saver',
            \csEmailLoginUrl(),
            'Savvy Saver | Chat mention digest',
            'You were tagged in vendor chat this week'
        );
    }

    private static function truncateChatSnippet(string $message): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
        if ($message === '') {
            return '(no message text)';
        }
        $limit = self::CHAT_MENTION_SNIPPET_CHARS;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($message, 'UTF-8') <= $limit) {
                return $message;
            }

            return rtrim(mb_substr($message, 0, $limit, 'UTF-8')) . '…';
        }
        if (strlen($message) <= $limit) {
            return $message;
        }

        return rtrim(substr($message, 0, $limit)) . '…';
    }

    /**
     * @param array<int, array{vendor_name:string, annual_cost:float}> $items
     */
    private static function buildCappedVendorList(array $items): string
    {
        if ($items === []) {
            return '<p style="color:#6b7280;">None</p>';
        }
        $html = '<ul>';
        $shown = array_slice($items, 0, self::LIST_CAP);
        foreach ($shown as $item) {
            $html .= '<li>'
                . htmlspecialchars($item['vendor_name'])
                . ' — $' . number_format((float) $item['annual_cost'], 2) . '/yr'
                . '</li>';
        }
        $more = count($items) - count($shown);
        if ($more > 0) {
            $html .= '<li>+' . $more . ' more</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * @param mixed $r
     * @return array<string, mixed>
     */
    private static function summarizeSendResult($r): array
    {
        if ($r === true) {
            return ['ok' => true];
        }
        if (is_array($r)) {
            return [
                'ok' => false,
                'error_message' => (string) ($r['error_message'] ?? ''),
                'error_info' => (string) ($r['error_info'] ?? ''),
                'keys' => array_keys($r),
            ];
        }
        return ['ok' => false, 'type' => gettype($r), 'value' => is_scalar($r) ? $r : null];
    }

    /**
     * @param mixed $r
     */
    private static function sendFailureMessage($r): string
    {
        if (is_array($r)) {
            return (($r['error_message'] ?? 'send failed') . ' | ' . ($r['error_info'] ?? ''));
        }

        return 'unexpected return: ' . gettype($r);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function estimateNextRenewal(array $row): ?DateTimeImmutable
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
        $freq = VendorService::normalizeFrequencyValue($row['frequency'] ?? 'monthly');
        switch ($freq) {
            case 'weekly':
                return $base->modify('+7 days');
            case 'bi_weekly':
                return $base->modify('+14 days');
            case 'monthly':
                return $base->modify('+1 month');
            case 'quarterly':
                return $base->modify('+3 months');
            case 'semi_annual':
                return $base->modify('+6 months');
            case 'annually':
                return $base->modify('+1 year');
            case 'irregular':
                return null;
            default:
                return $base->modify('+1 month');
        }
    }
}
