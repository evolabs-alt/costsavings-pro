<?php
/**
 * Run daily via Task Scheduler: php cron_reminders.php
 * Optional: set CRON_SECRET in config.php and call ?key=...
 *
 * CLI flags:
 *   --debug         Verbose per-user / GHL response output
 *   --force-resend  Clear this month's monthly_renewal_sent rows before sending
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
    $ym = (new DateTimeImmutable('first day of this month'))->modify('+1 month')->format('Y-m');
    $del = $pdo->prepare('DELETE FROM monthly_renewal_sent WHERE `year_month` = ?');
    $del->execute([$ym]);
    if ($debug || php_sapi_name() === 'cli') {
        echo "force-resend: cleared monthly_renewal_sent for {$ym} (rows={$del->rowCount()})\n";
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
$r2 = \CostSavings\ReminderService::runMonthlyRenewalSummaries($pdo, $send);

if (php_sapi_name() === 'cli') {
    echo 'Deadline reminders sent: ' . $r1['sent'] . "\n";
    if (!empty($r1['errors'])) {
        echo "Deadline errors:\n";
        foreach ($r1['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }
    echo 'Monthly renewal emails sent: ' . $r2['sent'] . "\n";
    if (!empty($r2['errors'])) {
        echo "Monthly errors:\n";
        foreach ($r2['errors'] as $err) {
            echo "  - {$err}\n";
        }
    }
    if ($debug && !empty($r2['debug'])) {
        echo "Monthly debug (year_month=" . ($r2['year_month'] ?? '') . "):\n";
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
}
