<?php
/**
 * QuickBooks Online OAuth page handlers (connect redirect + callback).
 */

use CostSavings\OrgRole;
use CostSavings\QboService;

function csQboRequireAdminSession(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        header('Location: ' . (function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/') . 'index.php');
        exit;
    }
    $role = (string) ($_SESSION['role'] ?? '');
    if (!OrgRole::isPrivileged($role)) {
        $_SESSION['error'] = 'Only admins can connect QuickBooks.';
        header('Location: ' . (function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/') . 'index.php');
        exit;
    }
}

function csQboConnect(): void
{
    csQboRequireAdminSession();
    try {
        $pdo = getDBConnection();
        $svc = new QboService($pdo);
        $started = $svc->beginOAuth((int) $_SESSION['org_id']);
        header('Location: ' . $started['auth_url']);
        exit;
    } catch (Throwable $e) {
        error_log('QBO connect: ' . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . (function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/') . 'index.php');
        exit;
    }
}

function csQboCallback(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>QuickBooks OAuth</h1><p>Please sign in, then try Connect to QuickBooks again.</p>';
        exit;
    }

    $error = $_GET['error'] ?? '';
    if ($error !== '') {
        $desc = $_GET['error_description'] ?? $error;
        $_SESSION['error'] = 'QuickBooks authorization failed: ' . $desc;
        header('Location: ' . (function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/') . 'index.php');
        exit;
    }

    $code = (string) ($_GET['code'] ?? '');
    $realmId = (string) ($_GET['realmId'] ?? ($_GET['realm_id'] ?? ''));
    $state = (string) ($_GET['state'] ?? '');

    try {
        $pdo = getDBConnection();
        $svc = new QboService($pdo);
        $svc->handleCallback($code, $realmId, $state);
        $_SESSION['message'] = 'QuickBooks Online connected successfully.';
    } catch (Throwable $e) {
        error_log('QBO callback: ' . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: ' . (function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/') . 'index.php');
    exit;
}
