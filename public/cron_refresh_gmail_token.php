<?php
/**
 * Proactive Gmail OAuth token refresh
 * Run: php public/cron_refresh_gmail_token.php
 * Schedule: every 30 minutes (cron: 0,30 * * * *)
 */
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/GmailService.php';

try {
    getDBConnection();
    $gmail = new GmailService();
    $gmail->setAccessTokenFromDB();
    echo "Gmail token refreshed or still valid.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Gmail token refresh failed: ' . $e->getMessage() . "\n");
    exit(1);
}
