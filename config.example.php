<?php
/*
 * Copy this file to config.php and set real values. config.php is gitignored.
 */

define('CACHE_DIR', __DIR__ . '/cache/');

/**
 * Postmark (https://postmarkapp.com): Server API token from your Postmark server.
 * Prefer setting POSTMARK_SERVER_TOKEN in the environment in production.
 * The From address below must match a verified Sender Signature or domain in Postmark.
 */
define('POSTMARK_SERVER_TOKEN', getenv('POSTMARK_SERVER_TOKEN') ?: '');
define('SMTP_FROM_EMAIL', 'user@example.com');
define('SMTP_FROM_NAME', 'Savvy CFO Portal');
/** Optional: set true temporarily to print Postmark HTTP debug detail in the browser console after invite failures. */
define('POSTMARK_DEBUG', false);

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
define('PERPLEXITY_API_KEY', getenv('PERPLEXITY_API_KEY') ?: '');
define('PERPLEXITY_API_URL', getenv('PERPLEXITY_API_URL') ?: 'https://api.perplexity.ai/chat/completions');
define('AI_MODEL', getenv('AI_MODEL') ?: 'sonar');
define('AI_MAX_TOKENS', (int) (getenv('AI_MAX_TOKENS') ?: '1200'));
define('AI_TEMPERATURE', (float) (getenv('AI_TEMPERATURE') ?: '0.7'));
define('AI_MONTHLY_LIMIT', 50);

/** Optional: protect cron_reminders.php when called via HTTP */
if (!defined('CRON_SECRET')) {
    define('CRON_SECRET', getenv('CRON_SECRET') ?: '');
}

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}
