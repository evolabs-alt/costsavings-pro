<?php
/**
 * QuickBooks Online OAuth page handlers (connect redirect + callback).
 */

use CostSavings\OrgRole;
use CostSavings\ProjectService;
use CostSavings\QboService;
use CostSavings\RoleContext;

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
        $resume = [
            'resume_wizard' => isset($_GET['resume_wizard']) && (string) $_GET['resume_wizard'] === '1',
            'project_id' => isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0,
            'project_name' => isset($_GET['project_name']) ? trim((string) $_GET['project_name']) : '',
        ];
        $started = $svc->beginOAuth(
            (int) $_SESSION['org_id'],
            (int) ($_SESSION['user_id'] ?? 0),
            $resume
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

    $base = function_exists('publicAppBaseUrl') ? publicAppBaseUrl() : '/';
    $error = $_GET['error'] ?? '';
    if ($error !== '') {
        $desc = (string) ($_GET['error_description'] ?? $error);
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['error'] = 'QuickBooks authorization failed: ' . $desc;
            header('Location: ' . $base . 'index.php');
            exit;
        }
        header('Location: ' . $base . 'index.php?qbo=denied');
        exit;
    }

    $code = (string) ($_GET['code'] ?? '');
    $realmId = (string) ($_GET['realmId'] ?? ($_GET['realm_id'] ?? ''));
    $state = (string) ($_GET['state'] ?? '');

    try {
        $pdo = getDBConnection();
        $svc = new QboService($pdo);
        $result = $svc->handleCallback($code, $realmId, $state);
        $orgId = (int) ($result['org_id'] ?? 0);
        $userId = (int) ($result['user_id'] ?? 0);
        $resumeWizard = !empty($result['resume_wizard']);
        $projectId = (int) ($result['project_id'] ?? 0);
        $projectName = (string) ($result['project_name'] ?? '');

        if (empty($_SESSION['user_id']) && $userId > 0) {
            $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && empty($row['is_disabled']) && function_exists('establishUserSession')) {
                establishUserSession($pdo, $row);
            }
        }

        if (!empty($_SESSION['user_id']) && $orgId > 0) {
            $uid = (int) $_SESSION['user_id'];
            RoleContext::persistLastOrgId($pdo, $uid, $orgId);
            $orgRole = RoleContext::orgRole($pdo, $uid, $orgId) ?? OrgRole::ROLE_MEMBER;
            $activeProjectId = null;
            if ($projectId > 0 && ProjectService::canAccessProject($pdo, $projectId, $orgId, $uid, $orgRole)) {
                $activeProjectId = $projectId;
            } else {
                $activeProjectId = ProjectService::resolveActiveProjectId($pdo, $orgId, $uid, $orgRole, null);
            }
            RoleContext::syncSession($pdo, $uid, $orgId, $activeProjectId);
            $_SESSION['user_orgs'] = RoleContext::listUserOrganizations($pdo, $uid);
            if ($activeProjectId !== null) {
                ProjectService::backfillNullProjectRows($pdo, $orgId, $activeProjectId);
            }
            if ($resumeWizard && $projectId > 0) {
                $_SESSION['qbo_resume_wizard'] = [
                    'project_id' => $projectId,
                    'project_name' => $projectName,
                ];
            }
            $_SESSION['message'] = 'QuickBooks Online connected successfully.';
            $qs = $resumeWizard ? '?qbo=connected&resume_qbo_sync=1' : '?qbo=connected';
            header('Location: ' . $base . 'index.php' . $qs);
            exit;
        }

        // Connected but could not restore a user session.
        header('Location: ' . $base . 'index.php?qbo=connected');
        exit;
    } catch (Throwable $e) {
        error_log('QBO callback: ' . $e->getMessage());
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['error'] = $e->getMessage();
            header('Location: ' . $base . 'index.php');
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
