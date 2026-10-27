<?php
/**
 * Run daily via Task Scheduler: php cron_reminders.php
 * Optional: set CRON_SECRET in config.php and call ?key=...
 *
 * Jobs (same process):
 *   - Cancellation deadline reminders (T−7 / day / T+7)
 *   - Approaching non-monthly renewals (annual / semi-annual / quarterly lead days)
 *   - Non-monthly charges digest (previous calendar month; sends on days 1–3)
 *   - Cancellation progress digest (weekly on Monday)
 *
 * CLI flags:
 *   --debug         Verbose per-user / GHL response output
 *   --force-resend  Clear today's renewal + current digest period dedup, then send
 *                   (also bypasses day/weekday gates for digests)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/mail.php';

$cliArgs = php_sapi_name() === 'cli' ? ($argv ?? []) : [];
$debug = in_array('--debug', $cliArgs, true)
    || (isset($_GET['debug']) && (string) $_GET['debug'] === '1');
$forceResend = in_array('--force-resend', $cliArgs, true)
    || (isset($_GET['force_resend']) && (string) $_GET['force_resend'] === '1');

if (php_sapi_name() !== 'cli') {
    $secret = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
    if ($secret === '' || ($_GET['key'] ?? '') !== $secret) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Forbidden';
        exit;
    }
}

$pdo = getDBConnection();

if ($forceResend) {
    $cleared = \CostSavings\ReminderService::clearForceResendDedup($pdo);
    if ($debug || php_sapi_name() === 'cli') {
        echo 'force-resend: cleared renewal_reminder_sent today=' . $cleared['renewal_reminder_sent']
            . ', non_monthly_charges=' . $cleared['non_monthly_charges']
            . ', cancel_progress=' . $cleared['cancel_progress'] . "\n";
    }
}

$send = function ($to, $subject, $body) use ($debug) {
    if ($debug) {
        echo "--- sendReminderEmail ---\n";
        echo "  to: {$to}\n";
        echo "  subject: {$subject}\n";
        echo "  body_len: " . strlen((string) $body) . "\n";
        $ghlKey = defined('GHL_API_KEY') ? trim((string) GHL_API_KEY) : '';
        $ghlTok = defined('GHL_API_TOKEN') ? trim((string) GHL_API_TOKEN) : '';
        $loc = defined('GHL_LOCATION_ID') ? trim((string) GHL_LOCATION_ID) : '';
        $from = defined('GHL_FROM_EMAIL') ? trim((string) GHL_FROM_EMAIL) : '';
        echo "  ghl_key_set: " . ($ghlKey !== '' || $ghlTok !== '' ? 'yes' : 'no') . "\n";
        echo "  ghl_location_set: " . ($loc !== '' ? 'yes' : 'no') . "\n";
        echo "  ghl_from: " . ($from !== '' ? $from : '(default no-reply@savvycfo.com)') . "\n";
    }

    $r = sendReminderEmail($to, $subject, $body);

    if ($debug) {
        if ($r === true) {
            echo "  result: OK (true)\n";
        } elseif (is_array($r)) {
            echo "  result: FAIL\n";
            echo "  error_message: " . ($r['error_message'] ?? '') . "\n";
            echo "  error_info: " . ($r['error_info'] ?? '') . "\n";
        } else {
            echo "  result: unexpected " . gettype($r) . "\n";
        }
        if (function_exists('csGhlLastResponse')) {
            $last = csGhlLastResponse();
            if (is_array($last)) {
                echo "  ghl_http: " . ($last['status'] ?? '') . "\n";
                echo "  ghl_path: " . ($last['path'] ?? '') . "\n";
                $snippet = (string) ($last['body'] ?? '');
                if (strlen($snippet) > 500) {
                    $snippet = substr($snippet, 0, 500) . '...';
                }
                echo "  ghl_body: {$snippet}\n";
            } else {
                echo "  ghl_last: (none — request may not have reached GHL)\n";
            }
        }
    }

    return $r;
};

$r1 = \CostSavings\ReminderService::runDeadlineReminders($pdo, $send);
$r2 = \CostSavings\ReminderService::runApproachingRenewalReminders($pdo, $send);
$r3 = \CostSavings\ReminderService::runNonMonthlyChargesDigest($pdo, $send, $forceResend);
$r4 = \CostSavings\ReminderService::runCancelProgressDigest($pdo, $send, $forceResend);

if (php_sapi_name() === 'cli') {
    echo 'Deadline reminders sent: ' . $r1['sent'] . "\n";
    if (!empty($r1['errors'])) {
        echo "Deadline errors:\n";
        foreach ($r1['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }

    echo 'Approaching renewal emails sent: ' . $r2['sent'] . "\n";
    if (!empty($r2['errors'])) {
        echo "Approaching renewal errors:\n";
        foreach ($r2['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }
    if ($debug && !empty($r2['debug'])) {
        echo "Approaching renewal debug:\n";
        foreach ($r2['debug'] as $row) {
            $action = $row['action'] ?? '';
            $email = $row['email'] ?? '';
            $lines = $row['lines'] ?? 0;
            echo "  [{$action}] {$email} lines={$lines}";
            if (!empty($row['send_result'])) {
                echo ' send=' . json_encode($row['send_result']);
            }
            echo "\n";
        }
    }

    $chargesGate = !empty($r3['skipped_day_gate']) ? ' (skipped: day gate)' : '';
    echo 'Non-monthly charges emails sent: ' . $r3['sent']
        . ' period=' . ($r3['period_key'] ?? '') . $chargesGate . "\n";
    if (!empty($r3['errors'])) {
        echo "Non-monthly charges errors:\n";
        foreach ($r3['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }
    if ($debug && !empty($r3['debug'])) {
        echo "Non-monthly charges debug:\n";
        foreach ($r3['debug'] as $row) {
            $action = $row['action'] ?? '';
            $email = $row['email'] ?? '';
            $lines = $row['lines'] ?? 0;
            echo "  [{$action}] {$email} lines={$lines}";
            if (!empty($row['send_result'])) {
                echo ' send=' . json_encode($row['send_result']);
            }
            echo "\n";
        }
    }

    $cancelGate = !empty($r4['skipped_weekday_gate']) ? ' (skipped: weekday gate)' : '';
    echo 'Cancel progress emails sent: ' . $r4['sent']
        . ' period=' . ($r4['period_key'] ?? '') . $cancelGate . "\n";
    if (!empty($r4['errors'])) {
        echo "Cancel progress errors:\n";
        foreach ($r4['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }
    if ($debug && !empty($r4['debug'])) {
        echo "Cancel progress debug:\n";
        foreach ($r4['debug'] as $row) {
            $action = $row['action'] ?? '';
            $email = $row['email'] ?? '';
            $projects = $row['projects'] ?? 0;
            echo "  [{$action}] {$email} projects={$projects}";
            if (!empty($row['send_result'])) {
                echo ' send=' . json_encode($row['send_result']);
            }
            echo "\n";
        }
    }
}
