<?php
/*
 * Copy this file to config.php and set real values. config.php is gitignored.
 */

define('CACHE_DIR', __DIR__ . '/cache/');

/** Base URL for invite links (include trailing slash), e.g. https://yourdomain.com/public/ */
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/public/');
}

/**
 * QuickBooks Online OAuth callback (optional override).
 * Default: {BASE_URL}index.php?page=qbo-callback — register the same URI in your Intuit app.
 * Per-org Client ID / Secret are stored in Admin → Settings (not here).
 */
if (!defined('QBO_REDIRECT_URI')) {
    define(
        'QBO_REDIRECT_URI',
        getenv('QBO_REDIRECT_URI') ?: (rtrim(BASE_URL, '/') . '/index.php?page=qbo-callback')
    );
}

/**
 * AES-256 key material for encrypting QBO client secrets + refresh tokens at rest.
 * Prefer a long random string via env. Access tokens are never persisted (memory only).
 */
if (!defined('QBO_TOKEN_ENCRYPTION_KEY')) {
    define('QBO_TOKEN_ENCRYPTION_KEY', getenv('QBO_TOKEN_ENCRYPTION_KEY') ?: '');
}

/**
 * Gmail API OAuth (invites). Prefer env vars in production.
 * From address must match the OAuth-authorized Workspace mailbox.
 */
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define(
    'GOOGLE_REDIRECT_URI',
    getenv('GOOGLE_REDIRECT_URI') ?: (rtrim(BASE_URL, '/') . '/index.php?page=gmail-callback')
);
define('GMAIL_OAUTH_SETUP_KEY', getenv('GMAIL_OAUTH_SETUP_KEY') ?: '');
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'contactus@savvycfo.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Savvy CFO Portal');

/**
 * GoHighLevel Conversations API (deadline + monthly renewal reminders).
 * Use the same GHL_LOCATION_ID as Scorecard Pro.
 */
define('GHL_API_KEY', getenv('GHL_API_KEY') ?: getenv('GHL_API_TOKEN') ?: '');
define('GHL_LOCATION_ID', getenv('GHL_LOCATION_ID') ?: '');
define('GHL_API_URL', getenv('GHL_API_URL') ?: 'https://services.leadconnectorhq.com');
define('GHL_API_VERSION', getenv('GHL_API_VERSION') ?: '2021-07-28');
define('GHL_FROM_EMAIL', getenv('GHL_FROM_EMAIL') ?: 'no-reply@savvycfo.com');
define('GHL_FROM_NAME', getenv('GHL_FROM_NAME') ?: 'Savvy CFO Cost Savings');

define('DB_HOST', 'localhost');
define('DB_NAME', 'costsavings_db');
define('DB_USER', 'db_user');
define('DB_PASS', 'db_password');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');

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

/** Members-area SSO shared secret (must match savvy-cfo-portfolio api/config.php). */
if (!defined('SSO_SHARED_SECRET')) {
    define('SSO_SHARED_SECRET', getenv('SSO_SHARED_SECRET') ?: '');
}

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}
