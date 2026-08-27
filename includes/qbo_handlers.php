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
    $role = function_exists('sessionOrgRole') ? sessionOrgRole() : (string) ($_SESSION['org_role'] ?? $_SESSION['role'] ?? '');
    if (!OrgRole::isSuperAdmin($role)) {
        $_SESSION['error'] = 'Only a super admin can connect QuickBooks.';
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
        $started = $svc->beginOAuth(
            (int) $_SESSION['org_id'],
            (int) ($_SESSION['user_id'] ?? 0)
        );
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
    // Intuit redirect often arrives without the original PHP session cookie.
    // Pending OAuth state is stored under CACHE_DIR so we can finish token exchange.

    $error = $_GET['error'] ?? '';
    if ($error !== '') {
        $desc = (string) ($_GET['error_description'] ?? $error);
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['error'] = 'QuickBooks authorization failed: ' . $desc;
        }
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
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['message'] = 'QuickBooks Online connected successfully.';
            header('Location: ' . (function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/') . 'index.php');
            exit;
        }
        // Session gone: still connected — send user to login with a clear status.
        header(
            'Location: ' . (function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/')
            . 'index.php?qbo=connected'
        );
        exit;
    } catch (Throwable $e) {
        error_log('QBO callback: ' . $e->getMessage());
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: ' . (function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/') . 'index.php');
            exit;
        }
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>QuickBooks OAuth</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p><a href="index.php">Sign in to Saver</a>, then try Connect from Settings again if needed.</p>';
        exit;
    }
}
