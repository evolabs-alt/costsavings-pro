<?php
/**
 * Gmail OAuth setup handlers (one-time admin flow).
 */

function csGmailRequireSetupKey(): void
{
    $key = $_GET['key'] ?? $_POST['key'] ?? '';
    if (!defined('GMAIL_OAUTH_SETUP_KEY') || GMAIL_OAUTH_SETUP_KEY === '' || !hash_equals((string) GMAIL_OAUTH_SETUP_KEY, (string) $key)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
}

function csGmailAuthenticate(): void
{
    csGmailRequireSetupKey();
    $_SESSION['gmail_oauth_setup'] = true;

    if (!defined('GOOGLE_CLIENT_ID') || GOOGLE_CLIENT_ID === '' || !defined('GOOGLE_CLIENT_SECRET') || GOOGLE_CLIENT_SECRET === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Google OAuth is not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.';
        exit;
    }

    require_once __DIR__ . '/GmailService.php';
    $gmail = new GmailService();
    header('Location: ' . $gmail->getAuthUrl());
    exit;
}

function csGmailCallback(): void
{
    if (empty($_SESSION['gmail_oauth_setup'])) {
        csGmailRequireSetupKey();
    }
    unset($_SESSION['gmail_oauth_setup']);

    $code = $_GET['code'] ?? '';
    if ($code === '') {
        $error = $_GET['error'] ?? 'Authorization code not found.';
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Gmail OAuth failed</h1><p>' . htmlspecialchars((string) $error) . '</p>';
        exit;
    }

    try {
        require_once __DIR__ . '/GmailService.php';
        $gmail = new GmailService();
        $token = $gmail->fetchAccessToken($code);
        $scope = $token['scope'] ?? '';
        $hasSendScope = str_contains($scope, 'mail.google.com');

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Gmail OAuth connected</h1>';
        if ($hasSendScope) {
            echo '<p>Token saved successfully. Send scope (<code>mail.google.com</code>) is granted.</p>';
        } else {
            echo '<p>Token saved, but <strong>send scope is missing</strong>. Revoke app access at '
                . '<a href="https://myaccount.google.com/permissions">Google Account Permissions</a> '
                . 'and re-run OAuth setup.</p>';
        }
        echo '<p>You can close this window.</p>';
    } catch (Throwable $e) {
        error_log('Gmail OAuth callback failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Gmail OAuth failed</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    exit;
}
