<?php
/**
 * Run daily via Task Scheduler: php cron_reminders.php
 * Optional: set CRON_SECRET in config.php and call ?key=...
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/mail.php';

if (php_sapi_name() !== 'cli') {
    $secret = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
    if ($secret === '' || ($_GET['key'] ?? '') !== $secret) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Forbidden';
        exit;
    }
}

$pdo = getDBConnection();
$send = function ($to, $subject, $body) {
    return sendEmail($to, $subject, $body);
};

$r1 = \CostSavings\ReminderService::runDeadlineReminders($pdo, $send);
$r2 = \CostSavings\ReminderService::runMonthlyRenewalSummaries($pdo, $send);

if (php_sapi_name() === 'cli') {
    echo 'Deadline reminders sent: ' . $r1['sent'] . "\n";
    echo 'Monthly renewal emails sent: ' . $r2['sent'] . "\n";
}
