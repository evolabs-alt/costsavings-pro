<?php
/*
 * Copy this file to config.php and set real values. config.php is gitignored.
 */

define('CACHE_DIR', __DIR__ . '/cache/');

define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USERNAME', 'user@example.com');
define('SMTP_PASSWORD', 'your-smtp-password');
define('SMTP_FROM_EMAIL', 'user@example.com');
define('SMTP_FROM_NAME', 'Savvy CFO Portal');

define('GHL_API_KEY', 'your-ghl-private-integration-token');
define('GHL_LOCATION_ID', 'your-location-id');
define('GHL_API_URL', 'https://services.leadconnectorhq.com');
define('GHL_API_VERSION', '2021-07-28');

define('DB_HOST', 'localhost');
define('DB_NAME', 'costsavings_db');
define('DB_USER', 'db_user');
define('DB_PASS', 'db_password');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');

/** Base URL for invite links (include trailing slash), e.g. https://yourdomain.com/public/ */
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/public/');
}

/** Seed admin when no user has a password (local testing). Leave password empty in production. */
define('SEED_ADMIN_USERNAME', 'testadmin');
define('SEED_ADMIN_EMAIL', 'admin@example.com');
define('SEED_ADMIN_PASSWORD', getenv('SEED_ADMIN_PASSWORD') ?: '');

define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
define('AI_MONTHLY_LIMIT', 50);

/** Optional: protect cron_reminders.php when called via HTTP */
if (!defined('CRON_SECRET')) {
    define('CRON_SECRET', getenv('CRON_SECRET') ?: '');
}

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}
