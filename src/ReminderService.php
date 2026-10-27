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

    private const LIST_CAP = 10;

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
                $ok = $r === true;
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

            $html = self::buildApproachingRenewalsHtml($hits, $today);
            $subj = 'Approaching renewals — ' . $today->format('M j, Y');
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
                    $annual = (float) ($row['annual_cost'] ?? 0);
                    $vendor = (string) ($row['vendor_name'] ?? 'Vendor');
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
     * Clear dedup rows so --force-resend can re-deliver.
     *
     * @return array{renewal_reminder_sent:int, non_monthly_charges:int, cancel_progress:int}
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

        return [
            'renewal_reminder_sent' => $delRenewal->rowCount(),
            'non_monthly_charges' => $delCharges->rowCount(),
            'cancel_progress' => $delCancel->rowCount(),
        ];
    }

    /**
     * @return array<int, array{id:int, email:string}>
     */
    private static function eligibleReminderUsers(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT u.id, u.email
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
            'SELECT pm.project_id, pm.role, p.org_id, p.name AS project_name,
                    o.deadline_reminders_enabled AS org_reminders
             FROM project_members pm
             INNER JOIN projects p ON p.id = pm.project_id
             INNER JOIN organizations o ON o.id = p.org_id
             WHERE pm.user_id = ?'
        );
        $memberships->execute([$userId]);
        $out = [];
        while ($mem = $memberships->fetch(PDO::FETCH_ASSOC)) {
            $projectId = (int) ($mem['project_id'] ?? 0);
            $orgId = (int) ($mem['org_id'] ?? 0);
            $role = (string) ($mem['role'] ?? OrgRole::ROLE_MEMBER);
            if ($projectId <= 0 || $orgId <= 0) {
                continue;
            }
            if (OrgRole::isSuperAdmin($role)) {
                $q = $pdo->prepare('SELECT * FROM cost_calculator_items WHERE org_id = ? AND project_id = ?');
                $q->execute([$orgId, $projectId]);
            } else {
                $q = $pdo->prepare(
                    'SELECT * FROM cost_calculator_items WHERE org_id = ? AND project_id = ? AND (
                        visibility = \'public\' OR (visibility = \'confidential\' AND manager_user_id = ?)
                    )'
                );
                $q->execute([$orgId, $projectId, $userId]);
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
    private static function buildApproachingRenewalsHtml(array $hits, DateTimeImmutable $today): string
    {
        $byFreq = [
            'annually' => [],
            'semi_annual' => [],
            'quarterly' => [],
        ];
        foreach ($hits as $hit) {
            $freq = (string) ($hit['frequency'] ?? '');
            if (!isset($byFreq[$freq])) {
                continue;
            }
            $byFreq[$freq][] = $hit;
        }

        $html = '<p>These non-monthly renewals are approaching. Review each vendor and cancel before the renewal if appropriate.</p>';
        $labels = [
            'annually' => 'Annual',
            'semi_annual' => 'Semi-annual',
            'quarterly' => 'Quarterly',
        ];
        foreach ($labels as $freq => $label) {
            if ($byFreq[$freq] === []) {
                continue;
            }
            $html .= '<h3>' . htmlspecialchars($label) . '</h3>';
            $byProject = [];
            foreach ($byFreq[$freq] as $hit) {
                $byProject[(string) $hit['project_name']][] = $hit;
            }
            foreach ($byProject as $projectName => $projectHits) {
                $html .= '<p><strong>' . htmlspecialchars($projectName) . '</strong></p><ul>';
                foreach ($projectHits as $hit) {
                    /** @var DateTimeImmutable $renews */
                    $renews = $hit['renews_on'];
                    $cost = number_format((float) $hit['cost_per_period'], 2);
                    $html .= '<li>'
                        . htmlspecialchars((string) $hit['vendor_name'])
                        . ' — renews ' . htmlspecialchars($renews->format('M j, Y'))
                        . ' (in ' . (int) $hit['days_until'] . ' days)'
                        . ' — $' . $cost . ' / ' . htmlspecialchars($freq)
                        . '</li>';
                }
                $html .= '</ul>';
            }
        }
        $html .= '<p>Open Savvy Saver to review these vendors.</p>';
        $html .= '<p style="color:#6b7280;font-size:12px;">Generated ' . htmlspecialchars($today->format('Y-m-d')) . '</p>';

        return $html;
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
        $html = '<p>Non-monthly recurring charges recorded in <strong>'
            . htmlspecialchars($monthLabel)
            . '</strong> (' . $totalCount . ' charge'
            . ($totalCount === 1 ? '' : 's')
            . ', $' . number_format($totalAmount, 2) . ' total).</p>';
        foreach ($linesByProject as $projectName => $lines) {
            $html .= '<h3>' . htmlspecialchars($projectName) . '</h3><ul>';
            foreach ($lines as $line) {
                $html .= '<li>'
                    . htmlspecialchars((string) $line['vendor_name'])
                    . ' — ' . htmlspecialchars((string) $line['transaction_date'])
                    . ' — $' . number_format((float) $line['amount'], 2)
                    . ' (' . htmlspecialchars((string) $line['frequency']) . ')'
                    . ' — annual cost $' . number_format((float) $line['annual_cost'], 2)
                    . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<p>Open Savvy Saver to review these vendors.</p>';

        return $html;
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
        $html = '<p>Cancellation progress as of <strong>' . htmlspecialchars($weekLabel) . '</strong>.</p>';
        $html .= '<p><strong>Across your projects:</strong> '
            . $grandPendingCount . ' pending ($' . number_format($grandPendingSum, 2) . ' potential annual)'
            . '; '
            . $grandConfirmedCount . ' cancelled ($' . number_format($grandConfirmedSum, 2) . ' confirmed annual)'
            . '.</p>';

        foreach ($projectSections as $section) {
            $html .= '<h3>' . htmlspecialchars((string) $section['project_name']) . '</h3>';
            $pending = $section['pending'];
            $confirmed = $section['confirmed'];
            $html .= '<p>Pending (mark for cancellation): <strong>'
                . count($pending) . '</strong> — $'
                . number_format((float) $section['pending_sum'], 2) . ' annual</p>';
            $html .= self::buildCappedVendorList($pending);
            $html .= '<p>Confirmed cancelled: <strong>'
                . count($confirmed) . '</strong> — $'
                . number_format((float) $section['confirmed_sum'], 2) . ' annual</p>';
            $html .= self::buildCappedVendorList($confirmed);
        }
        $html .= '<p>Open Savvy Saver to continue cancellations or confirm completed ones.</p>';

        return $html;
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
        $freq = $row['frequency'] ?? 'monthly';
        switch ($freq) {
            case 'weekly':
                return $base->modify('+7 days');
            case 'bi_weekly':
                return $base->modify('+14 days');
            case 'semi_monthly':
                return $base->modify('+15 days');
            case 'monthly':
                return $base->modify('+1 month');
            case 'quarterly':
                return $base->modify('+3 months');
            case 'semi_annual':
                return $base->modify('+6 months');
            case 'annually':
                return $base->modify('+1 year');
            case 'one_off':
                return null;
            default:
                return $base->modify('+1 month');
        }
    }
}
