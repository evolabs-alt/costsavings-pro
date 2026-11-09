<?php

require_once __DIR__ . '/pro_log.php';

use CostSavings\AiService;
use CostSavings\CategoryService;
use CostSavings\CsvImport;
use CostSavings\ExportService;
use CostSavings\MappedCsvImport;
use CostSavings\OrgRole;
use CostSavings\ProjectService;
use CostSavings\QboService;
use CostSavings\RoleContext;
use CostSavings\VendorChatService;
use CostSavings\VendorPurposeService;
use CostSavings\VendorService;

function normalizeUserEmail($email) {
    return strtolower(trim($email));
}

/**
 * Refresh org and project roles from the database into the session.
 */
function syncSessionOrgRoleFromDatabase(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    try {
        $pdo = getDBConnection();
        $userId = (int) $_SESSION['user_id'];
        $orgId = (int) ($_SESSION['org_id'] ?? 0);
        if ($orgId < 1) {
            $orgId = RoleContext::resolveDefaultOrgId($pdo, $userId);
        }
        if ($orgId < 1) {
            return;
        }
        $projectId = isset($_SESSION['active_project_id']) ? (int) $_SESSION['active_project_id'] : null;
        RoleContext::syncSession($pdo, $userId, $orgId, $projectId);
        $_SESSION['user_orgs'] = RoleContext::listUserOrganizations($pdo, $userId);
    } catch (\PDOException $e) {
        error_log('syncSessionOrgRoleFromDatabase: ' . $e->getMessage());
    }
}

function sessionOrgRole(): string
{
    return RoleContext::sessionOrgRole();
}

function sessionProjectRole(): string
{
    return RoleContext::sessionProjectRole();
}

/** Org role drives vendor grid load/save (project roles removed). */
function effectiveVendorGridRole(): string
{
    return sessionOrgRole();
}

/**
 * Absolute base URL for links into public/ (invite emails, etc.).
 * Uses BASE_URL when set; otherwise derives from the current request (HTTPS, proxy headers, SCRIPT_NAME).
 */
function publicAppBaseUrl(): string {
    if (defined('BASE_URL')) {
        $u = trim((string) BASE_URL);
        if ($u !== '' && $u !== '/') {
            return rtrim($u, '/') . '/';
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (strpos($host, ',') !== false) {
        $host = trim(explode(',', $host)[0]);
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '.') {
        $path = '';
    } else {
        $path = rtrim($dir, '/');
    }

    return $scheme . '://' . $host . $path . '/';
}

/**
 * Members portal origin for invite emails (no trailing slash).
 */
function membersAppUrl(): string
{
    if (defined('MEMBERS_APP_URL')) {
        $u = trim((string) MEMBERS_APP_URL);
        if ($u !== '') {
            return rtrim($u, '/');
        }
    }
    return 'https://members.savvycfo.com';
}

/**
 * Consume a pending Saver org invitation for this email (SSO / members-first flow).
 * Returns true when membership was added or already present for the invited org.
 */
function acceptPendingInviteForEmail(PDO $pdo, int $userId, string $email): bool
{
    $email = normalizeUserEmail($email);
    if ($userId < 1 || $email === '') {
        return false;
    }

    $st = $pdo->prepare(
        'SELECT * FROM invitations
         WHERE email = ? AND consumed_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC
         LIMIT 1'
    );
    $st->execute([$email]);
    $inv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
        return false;
    }

    $orgId = (int) ($inv['org_id'] ?? 0);
    if ($orgId < 1) {
        return false;
    }

    $already = $pdo->prepare('SELECT 1 FROM user_organizations WHERE user_id = ? AND org_id = ? LIMIT 1');
    $already->execute([$userId, $orgId]);
    if ($already->fetchColumn()) {
        $pdo->prepare('UPDATE invitations SET consumed_at = NOW() WHERE id = ?')->execute([(int) $inv['id']]);
        markUserJoinedViaInvite($pdo, $userId);
        return true;
    }

    if (getOrganizationMemberCount($pdo, $orgId) >= getOrganizationMaxUsers($pdo, $orgId)) {
        error_log('acceptPendingInviteForEmail: org at capacity org_id=' . $orgId . ' email=' . $email);
        return false;
    }

    $desiredRole = OrgRole::resolveInviteRole($inv);

    RoleContext::upsertOrgMembership($pdo, $userId, $orgId, $desiredRole);
    $pdo->prepare('UPDATE invitations SET consumed_at = NOW() WHERE id = ?')->execute([(int) $inv['id']]);
    markUserJoinedViaInvite($pdo, $userId);
    RoleContext::persistLastOrgId($pdo, $userId, $orgId);
    logInviteEvent('sso_auto_accepted', [
        'invitation_id' => (int) $inv['id'],
        'org_id' => $orgId,
        'email' => $email,
        'user_id' => $userId,
        'invite_role' => $desiredRole,
    ]);

    return true;
}

function logInviteEvent(string $event, array $context = []): void {
    $safe = [];
    foreach ($context as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $safe[$k] = $v;
        }
    }
    error_log('[invite] ' . $event . ' ' . json_encode($safe));
    proLog('[invite] ' . $event, $safe);
}

function normalizeWebhookUrl($raw): string
{
    return trim((string) $raw);
}

function loadOrganizationWebhookUrl(PDO $pdo, int $orgId): string
{
    try {
        $st = $pdo->prepare('SELECT notification_webhook_url FROM organizations WHERE id = ?');
        $st->execute([$orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return '';
        }
        return trim((string) ($row['notification_webhook_url'] ?? ''));
    } catch (PDOException $e) {
        error_log('loadOrganizationWebhookUrl: ' . $e->getMessage());
        return '';
    }
}

function resolveProjectName(PDO $pdo, int $orgId, int $projectId): string
{
    try {
        $st = $pdo->prepare('SELECT name FROM projects WHERE id = ? AND org_id = ? LIMIT 1');
        $st->execute([$projectId, $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['name'])) {
            return trim((string) $row['name']);
        }
    } catch (PDOException $e) {
        error_log('resolveProjectName: ' . $e->getMessage());
    }

    return 'Project #' . $projectId;
}

function postWebhookJson(string $url, array $payload): bool
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        error_log('postWebhookJson: failed to encode payload');
        return false;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 4,
        ]);
        curl_exec($ch);
        $errNo = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($errNo !== 0) {
            error_log('postWebhookJson curl error: ' . $err);
            return false;
        }
        if ($http >= 400 || $http === 0) {
            error_log('postWebhookJson HTTP status: ' . $http);
            return false;
        }
        return true;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 4,
            'ignore_errors' => true,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        error_log('postWebhookJson stream request failed');
        return false;
    }
    return true;
}

/**
 * Ensure users.org_id refers to a real organization. If missing or invalid, creates a new
 * dedicated organization for this user (never assigns shared org 1 implicitly — that would
 * let admins see every other tenant's projects scoped to that org).
 *
 * Invite-only accounts never get an auto-created personal workspace.
 */
function ensureUserOrganizationId(PDO $pdo, int $userId): int {
    if ($userId < 1) {
        return 0;
    }
    backfillUserOrganizationsFromLegacyUsers($pdo);
    $orgId = RoleContext::resolveDefaultOrgId($pdo, $userId);
    if ($orgId >= 1) {
        return $orgId;
    }

    if (userJoinedViaInvite($pdo, $userId)) {
        return 0;
    }

    $st = $pdo->prepare('SELECT email, username, display_name FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    $label = trim((string) ($row['display_name'] ?? ''));
    if ($label === '') {
        $label = trim((string) ($row['username'] ?? ''));
    }
    if ($label === '') {
        $label = trim((string) ($row['email'] ?? ''));
    }
    if ($label === '') {
        $label = 'Organization';
    }
    $orgName = $label . ' workspace';
    if (strlen($orgName) > 255) {
        $orgName = substr($orgName, 0, 252) . '...';
    }
    try {
        $ins = $pdo->prepare('INSERT INTO organizations (name, max_users) VALUES (?, 20)');
        $ins->execute([$orgName]);
        $newOrgId = (int) $pdo->lastInsertId();
        if ($newOrgId < 1) {
            error_log('ensureUserOrganizationId: lastInsertId invalid for user ' . $userId);
            return 0;
        }
        RoleContext::upsertOrgMembership($pdo, $userId, $newOrgId, OrgRole::ROLE_ORG_ADMIN);
        RoleContext::persistLastOrgId($pdo, $userId, $newOrgId);
        return $newOrgId;
    } catch (PDOException $e) {
        error_log('ensureUserOrganizationId: ' . $e->getMessage());
        return 0;
    }
}

function handleLogin() {
    // Local password login is disabled — open Savvy Saver from Members.
    $_SESSION['error'] = 'Sign in through the Savvy CFO Members Area, then open Savvy Saver.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Members-area SSO: GET ?action=sso_consume&token=...
 */
function handleSsoConsume(): void
{
    require_once __DIR__ . '/SsoToken.php';
    $secret = defined('SSO_SHARED_SECRET') ? (string) SSO_SHARED_SECRET : '';
    $token = (string) ($_GET['token'] ?? '');
    if ($secret === '' || $token === '') {
        $_SESSION['error'] = 'SSO is not configured or the link is invalid.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $claims = SsoToken::verify($token, $secret, 'savvy-saver');
    if (!$claims) {
        $_SESSION['error'] = 'SSO link is invalid or expired.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1');
    $stmt->execute([$claims['email']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $email = $claims['email'];
        $local = preg_replace('/[^a-zA-Z0-9._-]/', '', explode('@', $email)[0] ?? 'user');
        if ($local === '') {
            $local = 'user';
        }
        $username = $local;
        $suffix = 0;
        while (true) {
            $chk = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
            $chk->execute([$username]);
            if (!$chk->fetchColumn()) {
                break;
            }
            $suffix++;
            $username = $local . $suffix;
        }
        $display = $local;
        try {
            $ins = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, display_name)
                 VALUES (?, ?, NULL, ?)'
            );
            $ins->execute([$username, $email, $display]);
            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$newId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('SSO provision Saver: ' . $e->getMessage());
            $row = false;
        }
        if (!$row) {
            $_SESSION['error'] = 'Could not create a Savvy Saver account for this email.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    if (!empty($row['is_disabled'])) {
        $_SESSION['error'] = 'Your account has been disabled. Contact your administrator.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $userId = (int) $row['id'];
    $email = normalizeUserEmail((string) ($row['email'] ?? $claims['email'] ?? ''));
    acceptPendingInviteForEmail($pdo, $userId, $email);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fresh) {
        $row = $fresh;
    }
    establishUserSession($pdo, $row);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Shared post-auth session bootstrap (password login + SSO).
 */
function establishUserSession(PDO $pdo, array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['username'] = $row['username'] ?? '';
    $_SESSION['user_email'] = normalizeUserEmail($row['email']);
    $userId = (int) $row['id'];
    backfillUserOrganizationsFromLegacyUsers($pdo);
    $_SESSION['joined_via_invite'] = userJoinedViaInvite($pdo, $userId) ? 1 : 0;
    $orgId = ensureUserOrganizationId($pdo, $userId);
    if ($orgId < 1) {
        if (!empty($_SESSION['joined_via_invite'])) {
            $_SESSION['error'] = 'Use your invitation link to join an organization before signing in.';
        } else {
            $_SESSION['error'] = 'Your account organization could not be set up. Please contact support.';
        }
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['user_email'], $_SESSION['joined_via_invite']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    RoleContext::persistLastOrgId($pdo, $userId, $orgId);
    $orgRole = RoleContext::orgRole($pdo, $userId, $orgId) ?? OrgRole::ROLE_EXECUTIVE;
    $projectCount = ProjectService::orgProjectCount($pdo, $orgId);
    $_SESSION['project_onboarding_required'] = OrgRole::canCreateProject((string) $orgRole) && $projectCount === 0;
    $preferredProjectId = RoleContext::readLastProjectId($pdo, $userId);
    $activeProjectId = ProjectService::resolveActiveProjectId(
        $pdo,
        $orgId,
        $userId,
        $orgRole,
        $preferredProjectId > 0 ? $preferredProjectId : null
    );
    RoleContext::syncSession($pdo, $userId, $orgId, $activeProjectId);
    $_SESSION['user_orgs'] = RoleContext::listUserOrganizations($pdo, $userId);
    if ($activeProjectId !== null) {
        RoleContext::persistLastProjectId($pdo, $userId, $activeProjectId);
        ProjectService::backfillNullProjectRows($pdo, $orgId, $activeProjectId);
    }
}

function requireActiveProjectId(PDO $pdo): ?int
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $userId = (int) $_SESSION['user_id'];
    $orgId = (int) ($_SESSION['org_id'] ?? 0);
    $projectId = isset($_SESSION['active_project_id']) ? (int) $_SESSION['active_project_id'] : 0;
    if ($projectId < 1) {
        $projectId = RoleContext::readLastProjectId($pdo, $userId);
    }
    $resolved = ProjectService::resolveActiveProjectId(
        $pdo,
        $orgId,
        $userId,
        sessionProjectRole(),
        $projectId > 0 ? $projectId : null
    );
    if ($resolved !== null) {
        RoleContext::syncSession($pdo, $userId, $orgId, $resolved);
        RoleContext::persistLastProjectId($pdo, $userId, $resolved);
        ProjectService::backfillNullProjectRows($pdo, $orgId, $resolved);
    } else {
        RoleContext::syncSession($pdo, $userId, $orgId, null);
    }

    return $resolved;
}

function parseMemberIds($raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('intval', $raw), function ($v) {
            return $v > 0;
        }));
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_filter(array_map('intval', $decoded), function ($v) {
        return $v > 0;
    }));
}

function parseMemberRoles($raw): array
{
    if (is_array($raw)) {
        $out = [];
        foreach ($raw as $userId => $role) {
            $uid = (int) $userId;
            if ($uid > 0) {
                $out[$uid] = (string) $role;
            }
        }

        return $out;
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $userId => $role) {
        $uid = (int) $userId;
        if ($uid > 0) {
            $out[$uid] = (string) $role;
        }
    }

    return $out;
}

function handleInviteMember() {
    $redir = function () {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    };
    if (empty($_SESSION['user_id']) || (int) ($_SESSION['org_id'] ?? 0) < 1) {
        $_SESSION['error'] = 'You must be signed in to invite managers.';
        $redir();
    }
    $email = normalizeUserEmail($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email.';
        $redir();
    }
    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];

    $actorRole = sessionOrgRole();
    if (!OrgRole::canInvite($actorRole)) {
        $_SESSION['error'] = 'You do not have permission to invite users.';
        $redir();
    }
    $rawInviteRole = strtolower(trim((string) ($_POST['invite_role'] ?? OrgRole::ROLE_COST_OWNER)));
    $inviteRole = OrgRole::normalize($rawInviteRole);
    if (!OrgRole::canAssign($actorRole, $inviteRole)) {
        $_SESSION['error'] = 'You cannot invite users with that role.';
        $redir();
    }

    logInviteEvent('request_received', [
        'admin_user_id' => (int) $_SESSION['user_id'],
        'org_id' => $orgId,
        'email' => $email,
        'invite_role' => $inviteRole,
    ]);
    $maxUsers = getOrganizationMaxUsers($pdo, $orgId);
    $c = getOrganizationMemberCount($pdo, $orgId);
    if ($c >= $maxUsers) {
        logInviteEvent('blocked_org_limit', ['org_id' => $orgId, 'users' => $c, 'max_users' => $maxUsers]);
        $_SESSION['error'] = 'Organization is at the maximum of ' . $maxUsers . ' users.';
        $redir();
    }
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    $existingUser = $exists->fetch(PDO::FETCH_ASSOC);
    if ($existingUser) {
        $existingUserId = (int) ($existingUser['id'] ?? 0);
        $alreadyMember = $pdo->prepare('SELECT 1 FROM user_organizations WHERE user_id = ? AND org_id = ? LIMIT 1');
        $alreadyMember->execute([$existingUserId, $orgId]);
        if ($alreadyMember->fetchColumn()) {
            logInviteEvent('blocked_existing_member', ['org_id' => $orgId, 'email' => $email]);
            $_SESSION['error'] = 'This user is already a manager of your organization.';
            $redir();
        }
    }
    $pending = $pdo->prepare(
        'SELECT id FROM invitations WHERE org_id = ? AND email = ? AND consumed_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $pending->execute([$orgId, $email]);
    if ($pending->fetch()) {
        logInviteEvent('blocked_pending_invite', ['org_id' => $orgId, 'email' => $email]);
        $_SESSION['error'] = 'A pending invitation already exists for this email. The recipient can use the link from that email, or wait until it expires and send a new one.';
        $redir();
    }
    $plain = bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $exp = (new DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        'INSERT INTO invitations (org_id, email, token_hash, invited_by_user_id, expires_at, invite_role) VALUES (?,?,?,?,?,?)'
    );
    try {
        $ins->execute([$orgId, $email, $hash, (int) $_SESSION['user_id'], $exp, $inviteRole]);
    } catch (PDOException $e) {
        error_log('handleInviteMember insert: ' . $e->getMessage());
        logInviteEvent('insert_failed', ['org_id' => $orgId, 'email' => $email]);
        $_SESSION['error'] = 'Could not save invitation. Please try again.';
        $redir();
    }
    $invId = (int) $pdo->lastInsertId();
    logInviteEvent('invite_saved', ['invitation_id' => $invId, 'org_id' => $orgId, 'email' => $email, 'invite_role' => $inviteRole]);

    // Tag immediately so Members login unlocks Savvy Saver before they open Saver.
    if (function_exists('csTagScorecardProMember')) {
        csTagScorecardProMember(['email' => $email]);
    }

    $link = membersAppUrl() . '/register?email=' . rawurlencode($email);
    $inviteBody = '<p>You have been invited to Savvy Saver (Cost Savings).</p>'
        . '<p>Create your Savvy CFO Members account (or sign in), then open <strong>Savvy Saver</strong> from your products list.</p>';
    $body = csEmailHtmlWrap(
        'Your invitation — Savvy Saver',
        $inviteBody,
        'Continue on Members',
        $link,
        null,
        'You have been invited to Savvy Saver'
    );
    $mailResult = sendInviteEmail($email, 'Your invitation — Savvy Saver', $body);
    if ($mailResult !== true) {
        $mailDetail = '';
        if (is_array($mailResult)) {
            $em = trim((string) ($mailResult['error_message'] ?? ''));
            $ei = trim((string) ($mailResult['error_info'] ?? ''));
            if ($em !== '' && $ei !== '' && $ei !== $em) {
                $mailDetail = $em . ' (' . $ei . ')';
            } elseif ($em !== '') {
                $mailDetail = $em;
            } elseif ($ei !== '') {
                $mailDetail = $ei;
            }
            error_log(
                'handleInviteMember mail: '
                . ($mailResult['error_message'] ?? '')
                . ' '
                . ($mailResult['error_info'] ?? '')
            );
        }
        $detailForLog = $mailDetail !== ''
            ? (function_exists('mb_substr') ? mb_substr($mailDetail, 0, 500) : substr($mailDetail, 0, 500))
            : '';
        logInviteEvent('mail_failed', [
            'invitation_id' => $invId,
            'org_id' => $orgId,
            'email' => $email,
            'mail_detail' => $detailForLog,
        ]);
        // Keep the invitation row so the token stays valid; admin can share the link manually.
        $hint = 'Complete Gmail OAuth setup, ensure SMTP_FROM_EMAIL matches the authorized mailbox, and check pro.logs for sendInviteEmail_fail.';
        if ($mailDetail !== '') {
            $short = function_exists('mb_substr') ? mb_substr($mailDetail, 0, 450) : substr($mailDetail, 0, 450);
            $hint = $short . ' See pro.logs for details. ' . $hint;
        }
        $_SESSION['error'] = 'Could not send invitation email. ' . $hint
            . ' You can share this registration link manually: ' . $link;
        $redir();
    }
    logInviteEvent('mail_sent', ['invitation_id' => $invId, 'org_id' => $orgId, 'email' => $email]);
    $_SESSION['message'] = 'Invitation sent. If the recipient does not see it within a few minutes, ask them to check spam/junk and promotions tabs.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * @return array{success:bool, raw?:string, error?:string}
 */
function readUploadedCsvFile(): array {
    if (empty($_SESSION['user_id'])) {
        return ['success' => false, 'error' => 'Not logged in'];
    }
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed'];
    }
    $raw = file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($raw === false || strlen($raw) > 5_000_000) {
        return ['success' => false, 'error' => 'File too large or unreadable'];
    }

    return ['success' => true, 'raw' => $raw];
}

/**
 * @return array<int, string>
 */
function parseSelectedAccountsFromPost(): array {
    $selected = [];
    if (isset($_POST['selected_accounts']) && is_string($_POST['selected_accounts'])) {
        $decoded = json_decode($_POST['selected_accounts'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $name) {
                $trimmed = trim((string) $name);
                if ($trimmed !== '') {
                    $selected[] = $trimmed;
                }
            }
        }
    }
    if (count($selected) === 0 && isset($_POST['selected_accounts']) && is_array($_POST['selected_accounts'])) {
        foreach ($_POST['selected_accounts'] as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed !== '') {
                $selected[] = $trimmed;
            }
        }
    }

    return $selected;
}

/**
 * @return array<string, string|null>
 */
function parseColumnMappingFromPost(): array {
    if (!isset($_POST['column_mapping']) || !is_string($_POST['column_mapping'])) {
        return [];
    }
    $decoded = json_decode($_POST['column_mapping'], true);
    if (!is_array($decoded)) {
        return [];
    }

    $mapping = [];
    foreach ($decoded as $key => $value) {
        $fieldKey = trim((string) $key);
        if ($fieldKey === '') {
            continue;
        }
        if ($value === null) {
            $mapping[$fieldKey] = null;
            continue;
        }
        $mapping[$fieldKey] = trim((string) $value);
    }

    return $mapping;
}

/**
 * 0-based header row index from optional 1-based POST value; null triggers auto-detect.
 */
function parseHeaderRowFromPost(): ?int {
    if (!isset($_POST['header_row'])) {
        return null;
    }
    $raw = $_POST['header_row'];
    if (!is_numeric($raw)) {
        return null;
    }
    $oneBased = (int) $raw;
    if ($oneBased < 1) {
        return null;
    }

    return $oneBased - 1;
}

/**
 * @param array<int, array<string, mixed>> $summaryRows
 * @param array<int, array<string, mixed>> $rawRows
 * @return array<string, mixed>
 */
function importParsedVendorCsvData(array $summaryRows, array $rawRows, ?int $skippedRows = null): array {
    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $uid = (int) $_SESSION['user_id'];
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        return ['success' => false, 'error' => 'No active project selected'];
    }
    $purposeMap = ProjectService::purposeMapFromProject($pdo, $orgId, $activeProjectId);
    $items = [];
    foreach ($summaryRows as $row) {
        $items[] = [
            'vendor_name' => $row['vendor_name'],
            'cost_per_period' => $row['cost_per_period'],
            'amount_min' => $row['amount_min'] ?? null,
            'amount_max' => $row['amount_max'] ?? null,
            'amount_irregular' => !empty($row['amount_irregular']),
            'frequency' => $row['frequency'],
            'annual_cost' => $row['annual_cost'],
            'status' => VendorService::STATUS_PENDING,
            'cancel_keep' => 'Keep',
            'cancelled_status' => 0,
            'purpose_of_subscription' => $purposeMap[VendorService::normalizeVendorName((string) $row['vendor_name'])] ?? '',
            'notes' => '',
            'visibility' => 'confidential',
            'manager_user_id' => null,
            'cancellation_deadline' => null,
            'last_payment_date' => $row['last_payment_date'],
            'account' => $row['account'] ?? '',
        ];
    }
    $res = VendorService::appendImportedRows($pdo, $orgId, $activeProjectId, $uid, $items);
    if (!($res['success'] ?? false)) {
        return $res;
    }
    $batchId = bin2hex(random_bytes(12));
    $rawRes = VendorService::appendRawTransactions($pdo, $orgId, $activeProjectId, $uid, $batchId, $rawRows);
    if (!($rawRes['success'] ?? false)) {
        return $rawRes;
    }
    backfillCostCalculatorAccountsFromRaw($pdo);
    postImportFrequencyReviewNotes($pdo, $orgId, $activeProjectId, $res['inserted_meta'] ?? []);
    $res['raw_inserted'] = (int) ($rawRes['inserted'] ?? 0);
    $res['upload_batch_id'] = $batchId;
    if ($skippedRows !== null) {
        $res['skipped_rows'] = $skippedRows;
    }

    return $res;
}

/**
 * Additive import for revisit sync: insert only new vendor rows; append only new raw txs.
 * Existing vendor amount/frequency are left untouched.
 *
 * @param array<int, array<string, mixed>> $summaryRows
 * @param array<int, array<string, mixed>> $rawRows
 * @return array{success:bool, inserted?:int, vendors_skipped?:int, raw_inserted?:int, raw_skipped?:int, upload_batch_id?:string, error?:string}
 */
function importIncrementalVendorData(array $summaryRows, array $rawRows): array
{
    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $uid = (int) $_SESSION['user_id'];
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        return ['success' => false, 'error' => 'No active project selected'];
    }
    $existing = VendorService::existingNormalizedVendorNames($pdo, $orgId, $activeProjectId);
    $purposeMap = ProjectService::purposeMapFromProject($pdo, $orgId, $activeProjectId);
    $items = [];
    $vendorsSkipped = 0;
    foreach ($summaryRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = VendorService::normalizeVendorName((string) ($row['vendor_name'] ?? ''));
        if ($norm === '' || isset($existing[$norm])) {
            if ($norm !== '' && isset($existing[$norm])) {
                ++$vendorsSkipped;
            }
            continue;
        }
        $items[] = [
            'vendor_name' => $row['vendor_name'],
            'cost_per_period' => $row['cost_per_period'],
            'amount_min' => $row['amount_min'] ?? null,
            'amount_max' => $row['amount_max'] ?? null,
            'amount_irregular' => !empty($row['amount_irregular']),
            'frequency' => $row['frequency'],
            'annual_cost' => $row['annual_cost'],
            'status' => VendorService::STATUS_PENDING,
            'cancel_keep' => 'Keep',
            'cancelled_status' => 0,
            'purpose_of_subscription' => $purposeMap[$norm] ?? '',
            'notes' => '',
            'visibility' => 'confidential',
            'manager_user_id' => null,
            'cancellation_deadline' => null,
            'last_payment_date' => $row['last_payment_date'] ?? null,
            'account' => $row['account'] ?? '',
        ];
        $existing[$norm] = true;
    }
    $res = ['success' => true, 'inserted' => 0];
    if (count($items) > 0) {
        $res = VendorService::appendImportedRows($pdo, $orgId, $activeProjectId, $uid, $items);
        if (!($res['success'] ?? false)) {
            return $res;
        }
        postImportFrequencyReviewNotes($pdo, $orgId, $activeProjectId, $res['inserted_meta'] ?? []);
    }
    $batchId = bin2hex(random_bytes(12));
    $rawRes = VendorService::appendRawTransactionsDeduped(
        $pdo,
        $orgId,
        $activeProjectId,
        $uid,
        $batchId,
        $rawRows
    );
    if (!($rawRes['success'] ?? false)) {
        return $rawRes;
    }
    backfillCostCalculatorAccountsFromRaw($pdo);
    $res['vendors_skipped'] = $vendorsSkipped;
    $res['raw_inserted'] = (int) ($rawRes['inserted'] ?? 0);
    $res['raw_skipped'] = (int) ($rawRes['skipped'] ?? 0);
    $res['upload_batch_id'] = $batchId;

    return $res;
}

/**
 * @param array<int, array{id:int,frequency:string,amount_min:?float,amount_max:?float,amount_irregular:bool}> $insertedMeta
 */
function postImportFrequencyReviewNotes(PDO $pdo, int $orgId, int $projectId, array $insertedMeta): void
{
    foreach ($insertedMeta as $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $id = (int) ($meta['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $frequency = VendorService::normalizeFrequencyValue($meta['frequency'] ?? '');
        $amountIrregular = !empty($meta['amount_irregular']);
        $amountMin = isset($meta['amount_min']) && $meta['amount_min'] !== null
            ? (float) $meta['amount_min'] : null;
        $amountMax = isset($meta['amount_max']) && $meta['amount_max'] !== null
            ? (float) $meta['amount_max'] : null;

        if ($frequency === 'irregular') {
            VendorChatService::appendSystemReviewNote(
                $pdo,
                $orgId,
                $projectId,
                $id,
                'Payment timing does not match a regular cadence (weekly, bi-weekly, monthly, quarterly, bi-annual, or annual). Amount and annual cost are left blank, and this vendor is excluded from potential and confirmed savings until frequency and amount are confirmed.'
            );
        }

        if ($amountIrregular && $amountMin !== null && $amountMax !== null) {
            $minLabel = number_format($amountMin, 2, '.', ',');
            $maxLabel = number_format($amountMax, 2, '.', ',');
            VendorChatService::appendSystemReviewNote(
                $pdo,
                $orgId,
                $projectId,
                $id,
                'Payment amounts vary between $' . $minLabel . ' and $' . $maxLabel
                . '. Enter the expected amount per period to calculate annual cost. This vendor is excluded from savings totals until an amount is entered.'
            );
        }
    }
}

function handlePreviewCsvImport() {
    header('Content-Type: application/json');
    $upload = readUploadedCsvFile();
    if (!($upload['success'] ?? false)) {
        echo json_encode($upload);
        exit;
    }
    $raw = (string) ($upload['raw'] ?? '');
    $format = CsvImport::detectFormat($raw);
    if ($format === 'unknown') {
        echo json_encode(['success' => false, 'error' => 'Unrecognized CSV format']);
        exit;
    }
    if ($format === 'vendor') {
        echo json_encode(['success' => true, 'format' => 'vendor', 'accounts' => []]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'format' => 'account',
        'accounts' => CsvImport::listAccounts($raw),
    ]);
    exit;
}

function handleImportVendorCsv() {
    header('Content-Type: application/json');
    $upload = readUploadedCsvFile();
    if (!($upload['success'] ?? false)) {
        echo json_encode($upload);
        exit;
    }
    $raw = (string) ($upload['raw'] ?? '');
    $format = CsvImport::detectFormat($raw);
    if ($format === 'unknown') {
        echo json_encode(['success' => false, 'error' => 'Unrecognized CSV format']);
        exit;
    }
    if ($format === 'account') {
        $selectedAccounts = parseSelectedAccountsFromPost();
        if (count($selectedAccounts) === 0) {
            echo json_encode(['success' => false, 'error' => 'No accounts selected']);
            exit;
        }
        $parsed = CsvImport::parse($raw, $selectedAccounts);
    } else {
        $parsed = CsvImport::parse($raw);
    }
    $summaryRows = isset($parsed['summary']) && is_array($parsed['summary']) ? $parsed['summary'] : [];
    $rawRows = isset($parsed['raw']) && is_array($parsed['raw']) ? $parsed['raw'] : [];
    if (count($summaryRows) === 0) {
        echo json_encode(['success' => false, 'error' => 'No vendor blocks parsed']);
        exit;
    }
    echo json_encode(importParsedVendorCsvData($summaryRows, $rawRows));
    exit;
}

function handlePreviewMappedCsvImport() {
    header('Content-Type: application/json');
    $upload = readUploadedCsvFile();
    if (!($upload['success'] ?? false)) {
        echo json_encode($upload);
        exit;
    }
    $raw = (string) ($upload['raw'] ?? '');
    $headerRowIndex = parseHeaderRowFromPost();
    echo json_encode(MappedCsvImport::readPreview($raw, $headerRowIndex));
    exit;
}

function handleListMappedCsvAccounts() {
    header('Content-Type: application/json');
    $upload = readUploadedCsvFile();
    if (!($upload['success'] ?? false)) {
        echo json_encode($upload);
        exit;
    }
    $raw = (string) ($upload['raw'] ?? '');
    $headerRowIndex = parseHeaderRowFromPost();
    $preview = MappedCsvImport::readPreview($raw, $headerRowIndex);
    if (!($preview['success'] ?? false)) {
        echo json_encode($preview);
        exit;
    }
    $columns = isset($preview['columns']) && is_array($preview['columns']) ? $preview['columns'] : [];
    $mapping = parseColumnMappingFromPost();
    $mappingError = MappedCsvImport::validateMapping($columns, $mapping);
    if ($mappingError !== null) {
        echo json_encode(['success' => false, 'error' => $mappingError]);
        exit;
    }
    if (!MappedCsvImport::isAccountMapped($mapping)) {
        echo json_encode(['success' => false, 'error' => 'Account column is not mapped']);
        exit;
    }
    $accounts = MappedCsvImport::listAccounts($raw, $mapping, $headerRowIndex);
    if (count($accounts) === 0) {
        echo json_encode(['success' => false, 'error' => 'No account values found']);
        exit;
    }
    echo json_encode(['success' => true, 'accounts' => $accounts]);
    exit;
}

function handleImportMappedVendorCsv() {
    header('Content-Type: application/json');
    $upload = readUploadedCsvFile();
    if (!($upload['success'] ?? false)) {
        echo json_encode($upload);
        exit;
    }
    $raw = (string) ($upload['raw'] ?? '');
    $headerRowIndex = parseHeaderRowFromPost();
    $preview = MappedCsvImport::readPreview($raw, $headerRowIndex);
    if (!($preview['success'] ?? false)) {
        echo json_encode($preview);
        exit;
    }
    $columns = isset($preview['columns']) && is_array($preview['columns']) ? $preview['columns'] : [];
    $mapping = parseColumnMappingFromPost();
    $mappingError = MappedCsvImport::validateMapping($columns, $mapping);
    if ($mappingError !== null) {
        echo json_encode(['success' => false, 'error' => $mappingError]);
        exit;
    }
    if (MappedCsvImport::isAccountMapped($mapping)) {
        $selectedAccounts = parseSelectedAccountsFromPost();
        if (count($selectedAccounts) === 0) {
            echo json_encode(['success' => false, 'error' => 'No accounts selected']);
            exit;
        }
        $parsed = MappedCsvImport::parse($raw, $mapping, $selectedAccounts, $headerRowIndex);
    } else {
        $parsed = MappedCsvImport::parse($raw, $mapping, null, $headerRowIndex);
    }
    $summaryRows = isset($parsed['summary']) && is_array($parsed['summary']) ? $parsed['summary'] : [];
    $rawRows = isset($parsed['raw']) && is_array($parsed['raw']) ? $parsed['raw'] : [];
    if (count($summaryRows) === 0) {
        echo json_encode(['success' => false, 'error' => 'No valid rows parsed']);
        exit;
    }
    $skippedRows = isset($parsed['skipped_rows']) ? (int) $parsed['skipped_rows'] : 0;
    echo json_encode(importParsedVendorCsvData($summaryRows, $rawRows, $skippedRows));
    exit;
}

function handleExportVendors() {
    if (empty($_SESSION['user_id'])) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    $pdo = getDBConnection();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        header('HTTP/1.0 400 Bad Request');
        echo 'No active project selected.';
        exit;
    }
    $items = VendorService::loadVisibleItems(
        $pdo,
        (int) $_SESSION['user_id'],
        (int) $_SESSION['org_id'],
        $activeProjectId,
        sessionProjectRole()
    );
    $fmt = $_GET['format'] ?? $_POST['format'] ?? 'xlsx';
    if ($fmt === 'xlsx') {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            header('Content-Type: text/plain');
            echo 'PhpSpreadsheet not installed. Run composer install in the project root.';
            exit;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="vendors.xlsx"');
        echo ExportService::spreadsheetBytes($items);
        exit;
    }
    if ($fmt === 'pdf') {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            header('Content-Type: text/plain');
            echo 'Dompdf not installed. Run composer install.';
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="vendors.pdf"');
        echo ExportService::htmlToPdfBytes(ExportService::pdfVendorListHtml($items));
        exit;
    }
    if ($fmt === 'summary_pdf') {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            header('Content-Type: text/plain');
            echo 'Dompdf not installed. Run composer install.';
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="executive-summary.pdf"');
        echo ExportService::htmlToPdfBytes(ExportService::executiveSummaryHtml($items));
        exit;
    }
    header('HTTP/1.0 400 Bad Request');
    exit;
}

function handleAiAsk() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    if (!OrgRole::canUseAi(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to use the AI assistant.']);
        exit;
    }
    $pdo = getDBConnection();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
    }
    $q = trim((string) ($_POST['question'] ?? ''));
    $preset = isset($_POST['preset']) ? (string) $_POST['preset'] : null;
    $focusItemId = isset($_POST['focus_item_id']) ? (int) $_POST['focus_item_id'] : 0;
    if ($preset === '') {
        $preset = null;
    }
    if ($q === '' && $preset === null) {
        echo json_encode(['success' => false, 'error' => 'Enter a question or choose a preset.']);
        exit;
    }
    $items = VendorService::loadVisibleItems(
        $pdo,
        (int) $_SESSION['user_id'],
        (int) $_SESSION['org_id'],
        $activeProjectId,
        sessionProjectRole()
    );
    if ($focusItemId > 0) {
        $focused = array_values(array_filter($items, static function ($item) use ($focusItemId) {
            return is_array($item) && (int) ($item['id'] ?? 0) === $focusItemId;
        }));
        if (!$focused) {
            echo json_encode(['success' => false, 'error' => 'Selected vendor row is not available to your account.']);
            exit;
        }
        $items = $focused;
    }
    $question = $q !== '' ? $q : (AiService::PRESETS[$preset] ?? 'Summarize vendor spend.');
    $prompt = AiService::buildPrompt($question, $preset, $items);
    $out = AiService::ask($pdo, (int) $_SESSION['user_id'], $prompt);
    echo json_encode($out);
    exit;
}

function handleExportAiReplyPdf() {
    if (empty($_SESSION['user_id'])) {
        header('HTTP/1.0 403 Forbidden');
        echo 'Not logged in';
        exit;
    }
    $question = trim((string) ($_POST['question'] ?? ''));
    $replyRaw = (string) ($_POST['reply_html'] ?? '');
    if (strlen($replyRaw) > 65536) {
        header('HTTP/1.0 413 Payload Too Large');
        echo 'Reply too large to export.';
        exit;
    }
    $replyHtml = AiService::sanitizeReplyHtml($replyRaw);
    if ($replyHtml === '') {
        header('HTTP/1.0 400 Bad Request');
        echo 'Nothing to export.';
        exit;
    }
    if (!class_exists(\Dompdf\Dompdf::class)) {
        header('HTTP/1.0 503 Service Unavailable');
        echo 'Dompdf not installed. Run composer install.';
        exit;
    }
    $filename = 'ai-assistant-report-' . date('Y-m-d') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo ExportService::htmlToPdfBytes(ExportService::aiReplyPdfHtml($question, $replyHtml));
    exit;
}

function handleAiUsageStats() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $pdo = getDBConnection();
    $stats = AiService::getMonthlyUsageStats($pdo, (int) $_SESSION['user_id']);
    echo json_encode(array_merge(['success' => true], $stats));
    exit;
}

function handleAutoPopulatePurpose() {
    header('Content-Type: application/json');
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    if (!OrgRole::canUseAi(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to generate vendor purposes.']);
        exit;
    }
    $rowsRaw = $_POST['rows'] ?? '[]';
    $rows = is_array($rowsRaw) ? $rowsRaw : json_decode((string) $rowsRaw, true);
    if (!is_array($rows)) {
        echo json_encode(['success' => false, 'error' => 'Invalid rows payload']);
        exit;
    }
    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $userId = (int) $_SESSION['user_id'];
    $role = sessionProjectRole();
    $projectId = (int) ($_POST['project_id'] ?? 0);
    if ($projectId <= 0) {
        $activeProjectId = requireActiveProjectId($pdo);
        $projectId = $activeProjectId ?? 0;
    }
    if ($projectId <= 0) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
    }
    if (!ProjectService::canAccessProject($pdo, $projectId, $orgId, $userId, $role)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to this project.']);
        exit;
    }

    if (count($rows) === 0) {
        $st = $pdo->prepare(
            'SELECT id, vendor_name FROM cost_calculator_items
             WHERE org_id = ? AND project_id = ? AND TRIM(vendor_name) <> \'\''
        );
        $st->execute([$orgId, $projectId]);
        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['id'] ?? 0);
            $vendorName = trim((string) ($row['vendor_name'] ?? ''));
            if ($id > 0 && $vendorName !== '') {
                $rows[] = ['id' => $id, 'vendor_name' => $vendorName];
            }
        }
    }

    $resolved = VendorPurposeService::resolveForVisibleRows($pdo, $orgId, $rows);
    if (!$resolved['success']) {
        echo json_encode([
            'success' => false,
            'error' => (string) ($resolved['error'] ?? 'Purpose lookup failed'),
            'resolved' => $resolved['resolved'] ?? [],
            'unresolved' => $resolved['unresolved'] ?? [],
        ]);
        exit;
    }

    $updates = [];
    foreach (($resolved['resolved'] ?? []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $id = (int) ($r['id'] ?? 0);
        $purpose = trim((string) ($r['purpose'] ?? ''));
        $source = (string) ($r['source'] ?? '');
        if ($id <= 0 || $purpose === '' || VendorPurposeService::isUnusablePurposeText($purpose)) {
            continue;
        }
        $stored = VendorPurposeService::formatAutoPopulatedPurposeForStorage($purpose, $source);
        if ($stored === '') {
            continue;
        }
        $updates[] = ['id' => $id, 'purpose' => $stored];
    }

    $previousPurposeById = [];
    if (count($updates) > 0) {
        $ids = [];
        foreach ($updates as $u) {
            $rid = (int) ($u['id'] ?? 0);
            if ($rid > 0) {
                $ids[] = $rid;
            }
        }
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare(
                'SELECT id, purpose_of_subscription FROM cost_calculator_items
                 WHERE org_id = ? AND project_id = ? AND id IN (' . $placeholders . ')'
            );
            $st->execute(array_merge([$orgId, $projectId], $ids));
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $rid = (int) ($row['id'] ?? 0);
                if ($rid > 0) {
                    $previousPurposeById[$rid] = (string) ($row['purpose_of_subscription'] ?? '');
                }
            }
        }
    }

    $apply = VendorService::updatePurposesForVisibleRows($pdo, $orgId, $projectId, $userId, $role, $updates);
    $updateById = [];
    foreach ($updates as $u) {
        $updateById[(int) $u['id']] = $u['purpose'];
    }

    foreach ($updates as $u) {
        $rowId = (int) ($u['id'] ?? 0);
        if ($rowId <= 0) {
            continue;
        }
        $newPurpose = (string) ($u['purpose'] ?? '');
        $oldPurpose = $previousPurposeById[$rowId] ?? '';
        if ($newPurpose === $oldPurpose) {
            continue;
        }
        VendorChatService::appendActionLogEntry(
            $pdo,
            $orgId,
            $projectId,
            $rowId,
            0,
            'AI',
            VendorChatService::formatPurposeChangeMessage($oldPurpose, $newPurpose, 'Updated')
        );
    }
    $resolvedForClient = [];
    foreach (($resolved['resolved'] ?? []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $rid = (int) ($r['id'] ?? 0);
        if ($rid <= 0 || !isset($updateById[$rid])) {
            continue;
        }
        $copy = $r;
        $copy['purpose'] = $updateById[$rid];
        $resolvedForClient[] = $copy;
    }
    echo json_encode([
        'success' => true,
        'updated' => $apply['updated'] ?? 0,
        'applied' => $apply['applied'] ?? 0,
        'applied_ids' => $apply['applied_ids'] ?? [],
        'resolved' => $resolvedForClient,
        'unresolved' => $resolved['unresolved'] ?? [],
    ]);
    exit;
}

function handleLoadTeamMembers() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'members' => []]);
        exit;
    }
    $pdo = getDBConnection();
    $st = $pdo->prepare(
        'SELECT u.id, u.username, u.display_name, u.email, uo.role, uo.is_disabled
         FROM user_organizations uo
         INNER JOIN users u ON u.id = uo.user_id
         WHERE uo.org_id = ?
         ORDER BY u.username, u.email'
    );
    $st->execute([(int) $_SESSION['org_id']]);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'members' => $members]);
    exit;
}

function handleToggleMemberDisabled(): void
{
    $redir = function () {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    };
    if (empty($_SESSION['user_id']) || (int) ($_SESSION['org_id'] ?? 0) < 1) {
        $_SESSION['error'] = 'You must be signed in to manage user status.';
        $redir();
    }
    $actorRole = sessionOrgRole();
    if (!OrgRole::canDisableUser($actorRole)) {
        $_SESSION['error'] = 'You do not have permission to disable users.';
        $redir();
    }
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $disable = (int) ($_POST['disable'] ?? 0) === 1 ? 1 : 0;
    if ($memberId <= 0) {
        $_SESSION['error'] = 'Invalid user selected.';
        $redir();
    }
    if ($memberId === (int) $_SESSION['user_id']) {
        $_SESSION['error'] = 'You cannot disable your own account.';
        $redir();
    }

    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $lookup = $pdo->prepare('SELECT role FROM user_organizations WHERE user_id = ? AND org_id = ? LIMIT 1');
    $lookup->execute([$memberId, $orgId]);
    $member = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        $_SESSION['error'] = 'User not found.';
        $redir();
    }
    $targetRole = OrgRole::normalize((string) ($member['role'] ?? ''));
    if (!OrgRole::canAssign($actorRole, $targetRole)) {
        $_SESSION['error'] = 'You cannot disable a user with that role.';
        $redir();
    }
    if (OrgRole::isOrgAdmin($targetRole)) {
        $countSt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_organizations WHERE org_id = ? AND role = ? AND is_disabled = 0'
        );
        $countSt->execute([$orgId, OrgRole::ROLE_ORG_ADMIN]);
        if ((int) $countSt->fetchColumn() <= 1 && $disable === 1) {
            $_SESSION['error'] = 'Cannot disable the last Org Admin.';
            $redir();
        }
    }

    $upd = $pdo->prepare('UPDATE user_organizations SET is_disabled = ? WHERE user_id = ? AND org_id = ?');
    $upd->execute([$disable, $memberId, $orgId]);
    $_SESSION['message'] = $disable === 1 ? 'User disabled successfully.' : 'User enabled successfully.';
    $redir();
}

function handleSaveOrgMemberRole(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || (int) ($_SESSION['org_id'] ?? 0) < 1) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $actorRole = sessionOrgRole();
    if (!OrgRole::canChangeRole($actorRole)) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to change user roles.']);
        exit;
    }
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $desiredRole = OrgRole::normalize(strtolower(trim((string) ($_POST['role'] ?? ''))));
    if ($memberId <= 0 || !OrgRole::isValid($desiredRole)) {
        echo json_encode(['success' => false, 'error' => 'Invalid member or role.']);
        exit;
    }
    if (!OrgRole::canAssign($actorRole, $desiredRole)) {
        echo json_encode(['success' => false, 'error' => 'You cannot assign that role.']);
        exit;
    }
    $actorId = (int) $_SESSION['user_id'];
    if ($memberId === $actorId) {
        echo json_encode(['success' => false, 'error' => 'You cannot change your own organization role.']);
        exit;
    }

    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $lookup = $pdo->prepare(
        'SELECT role, is_disabled FROM user_organizations WHERE user_id = ? AND org_id = ? LIMIT 1'
    );
    $lookup->execute([$memberId, $orgId]);
    $member = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        echo json_encode(['success' => false, 'error' => 'Member not found in this organization.']);
        exit;
    }

    $currentRole = OrgRole::normalize((string) ($member['role'] ?? OrgRole::ROLE_EXECUTIVE));
    if (!OrgRole::canAssign($actorRole, $currentRole)) {
        echo json_encode(['success' => false, 'error' => 'You cannot change the role of this user.']);
        exit;
    }
    if ($currentRole === $desiredRole) {
        echo json_encode([
            'success' => true,
            'member_id' => $memberId,
            'role' => $desiredRole,
            'role_label' => OrgRole::orgLabel($desiredRole),
        ]);
        exit;
    }

    if (OrgRole::isOrgAdmin($currentRole) && !OrgRole::isOrgAdmin($desiredRole)) {
        $countSt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_organizations WHERE org_id = ? AND role = ? AND is_disabled = 0'
        );
        $countSt->execute([$orgId, OrgRole::ROLE_ORG_ADMIN]);
        $adminCount = (int) $countSt->fetchColumn();
        if ($adminCount <= 1) {
            echo json_encode(['success' => false, 'error' => 'Cannot demote the last Org Admin.']);
            exit;
        }
    }

    $isDisabled = (int) ($member['is_disabled'] ?? 0);
    RoleContext::upsertOrgMembership($pdo, $memberId, $orgId, $desiredRole, $isDisabled);

    echo json_encode([
        'success' => true,
        'member_id' => $memberId,
        'role' => $desiredRole,
        'role_label' => OrgRole::orgLabel($desiredRole),
    ]);
    exit;
}

function handleSaveOrgReminders() {
    if (empty($_SESSION['user_id']) || (int) ($_SESSION['org_id'] ?? 0) < 1) {
        $_SESSION['error'] = 'You must be signed in to change organization settings.';

        return;
    }
    $on = isset($_POST['deadline_reminders_enabled']) && $_POST['deadline_reminders_enabled'] === '1';
    $pdo = getDBConnection();
    $st = $pdo->prepare('UPDATE organizations SET deadline_reminders_enabled = ? WHERE id = ?');
    $st->execute([$on ? 1 : 0, (int) $_SESSION['org_id']]);
    $_SESSION['message'] = 'Organization reminder settings saved.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function handleSaveUserReminderPref() {
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $on = isset($_POST['user_deadline_reminders']) && $_POST['user_deadline_reminders'] === '1';
    $pdo = getDBConnection();
    $st = $pdo->prepare('UPDATE users SET deadline_reminders_enabled = ? WHERE id = ?');
    $st->execute([$on ? 1 : 0, (int) $_SESSION['user_id']]);
    $_SESSION['message'] = 'Reminder preference saved.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function handleSaveReminderSettings() {
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $pdo = getDBConnection();
    $orgId = (int) ($_SESSION['org_id'] ?? 0);
    $canEditOrgSettings = $orgId > 0 && OrgRole::canInvite(sessionOrgRole());
    $scope = strtolower(trim((string) ($_POST['settings_scope'] ?? 'email')));
    if ($scope !== 'qbo' && $scope !== 'email') {
        $scope = 'email';
    }

    if ($scope === 'qbo') {
        if ($orgId > 0 && OrgRole::canConnectQbo(sessionOrgRole())) {
            $promptQbo = isset($_POST['prompt_qbo_sync_on_revisit']) && $_POST['prompt_qbo_sync_on_revisit'] === '1' ? 1 : 0;
            $stOrg = $pdo->prepare('UPDATE organizations SET prompt_qbo_sync_on_revisit = ? WHERE id = ?');
            $stOrg->execute([$promptQbo, $orgId]);
            $_SESSION['message'] = 'Settings saved.';
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($canEditOrgSettings) {
        $orgOn = isset($_POST['deadline_reminders_enabled']) && $_POST['deadline_reminders_enabled'] === '1';
        $webhookUrl = normalizeWebhookUrl($_POST['notification_webhook_url'] ?? '');
        if ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $_SESSION['error'] = 'Please enter a valid webhook URL.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $stOrg = $pdo->prepare('UPDATE organizations SET deadline_reminders_enabled = ?, notification_webhook_url = ? WHERE id = ?');
        $stOrg->execute([$orgOn ? 1 : 0, ($webhookUrl !== '' ? $webhookUrl : null), $orgId]);
    }

    $userOn = isset($_POST['user_deadline_reminders']) && $_POST['user_deadline_reminders'] === '1';
    $stUser = $pdo->prepare('UPDATE users SET deadline_reminders_enabled = ? WHERE id = ?');
    $stUser->execute([$userOn ? 1 : 0, (int) $_SESSION['user_id']]);

    $_SESSION['message'] = $canEditOrgSettings ? 'Email settings saved.' : 'Reminder preference saved.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function handleQboConnectionStatus(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    try {
        $pdo = getDBConnection();
        $svc = new QboService($pdo);
        $status = $svc->connectionStatus((int) $_SESSION['org_id']);
        echo json_encode(array_merge(['success' => true], $status));
    } catch (Throwable $e) {
        error_log('handleQboConnectionStatus: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not load QuickBooks status']);
    }
    exit;
}

function handleQboDisconnect(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    if (!OrgRole::canConnectQbo(sessionOrgRole())) {
        $_SESSION['error'] = 'Only an Org Admin or Finance Admin can disconnect QuickBooks.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    try {
        $pdo = getDBConnection();
        $svc = new QboService($pdo);
        $svc->disconnect((int) $_SESSION['org_id'], (int) $_SESSION['user_id'], true);
        $_SESSION['message'] = 'QuickBooks company disconnected for this organization.';
    } catch (Throwable $e) {
        error_log('handleQboDisconnect: ' . $e->getMessage());
        $_SESSION['error'] = 'Could not disconnect QuickBooks.';
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function handlePreviewQboSync(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!OrgRole::canConnectQbo(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'Only an Org Admin or Finance Admin can sync QuickBooks.']);
        exit;
    }
    $startDate = trim((string) ($_POST['start_date'] ?? ''));
    $endDate = trim((string) ($_POST['end_date'] ?? ''));
    try {
        QboService::assertValidDateRange($startDate, $endDate);
        $pdo = getDBConnection();
        $activeProjectId = requireActiveProjectId($pdo);
        if ($activeProjectId === null) {
            echo json_encode(['success' => false, 'error' => 'No active project selected']);
            exit;
        }
        $svc = new QboService($pdo);
        if (!$svc->isConnected((int) $_SESSION['org_id'])) {
            echo json_encode([
                'success' => false,
                'error' => 'QuickBooks is not connected. Open Settings to connect.',
                'needs_setup' => true,
            ]);
            exit;
        }
        $rows = $svc->fetchTransactionRows((int) $_SESSION['org_id'], $startDate, $endDate);
        if (count($rows) === 0) {
            echo json_encode([
                'success' => false,
                'error' => 'No transactions found for the selected date range. '
                    . 'Confirm the company has activity in that period, try a wider range, '
                    . 'and match QBO_ENVIRONMENT (sandbox vs production) to the books you connected.',
            ]);
            exit;
        }
        $accountTypeMap = [];
        try {
            $accountTypeMap = $svc->fetchAccountTypeMap((int) $_SESSION['org_id']);
        } catch (Throwable $e) {
            error_log('handlePreviewQboSync account types: ' . $e->getMessage());
        }
        $accounts = QboService::listAccountsFromRows($rows, $accountTypeMap);
        $cacheKey = QboService::writeSyncCache(
            (int) $_SESSION['org_id'],
            (int) $_SESSION['user_id'],
            $rows,
            $startDate,
            $endDate
        );
        $_SESSION['pending_qbo_sync_key'] = $cacheKey;
        echo json_encode([
            'success' => true,
            'accounts' => $accounts,
            'transaction_count' => count($rows),
            'cache_key' => $cacheKey,
        ]);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('handlePreviewQboSync: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function handleImportQboSync(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!OrgRole::canConnectQbo(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'Only an Org Admin or Finance Admin can import QuickBooks data.']);
        exit;
    }
    $cacheKey = trim((string) ($_POST['cache_key'] ?? ($_SESSION['pending_qbo_sync_key'] ?? '')));
    if ($cacheKey === '') {
        echo json_encode(['success' => false, 'error' => 'Sync session expired. Pull from QuickBooks again.']);
        exit;
    }
    $selectedAccounts = parseSelectedAccountsFromPost();
    if (count($selectedAccounts) === 0) {
        echo json_encode(['success' => false, 'error' => 'No accounts selected']);
        exit;
    }
    try {
        $cached = QboService::readSyncCache(
            $cacheKey,
            (int) $_SESSION['org_id'],
            (int) $_SESSION['user_id']
        );
        if ($cached === null) {
            unset($_SESSION['pending_qbo_sync_key']);
            echo json_encode(['success' => false, 'error' => 'Sync session expired. Pull from QuickBooks again.']);
            exit;
        }
        $filtered = QboService::filterRowsByAccounts($cached['rows'], $selectedAccounts);
        if (count($filtered) === 0) {
            echo json_encode(['success' => false, 'error' => 'No transactions match the selected accounts']);
            exit;
        }
        $payload = QboService::buildImportPayload($filtered);
        $summaryRows = $payload['summary'];
        $rawRows = $payload['raw'];
        if (count($summaryRows) === 0) {
            echo json_encode(['success' => false, 'error' => 'No valid vendor rows to import']);
            exit;
        }
        $result = importParsedVendorCsvData($summaryRows, $rawRows);
        if (!empty($result['success'])) {
            QboService::deleteSyncCache($cacheKey);
            unset($_SESSION['pending_qbo_sync_key']);
        }
        echo json_encode($result);
    } catch (Throwable $e) {
        error_log('handleImportQboSync: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function handleQboWeeklyImportConsentStatus(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!OrgRole::canConnectQbo(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'Only an Org Admin or Finance Admin can manage QuickBooks consent.']);
        exit;
    }
    try {
        $pdo = getDBConnection();
        $activeProjectId = requireActiveProjectId($pdo);
        if ($activeProjectId === null) {
            echo json_encode(['success' => false, 'error' => 'No active project selected']);
            exit;
        }
        $agreed = ProjectService::hasQboWeeklyImportConsent(
            $pdo,
            (int) $_SESSION['org_id'],
            $activeProjectId
        );
        echo json_encode(['success' => true, 'agreed' => $agreed]);
    } catch (Throwable $e) {
        error_log('handleQboWeeklyImportConsentStatus: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not load consent status']);
    }
    exit;
}

function handleSaveQboWeeklyImportConsent(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!OrgRole::canConnectQbo(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'Only an Org Admin or Finance Admin can save QuickBooks consent.']);
        exit;
    }
    $agreed = isset($_POST['agreed']) && (string) $_POST['agreed'] === '1';
    if (!$agreed) {
        echo json_encode(['success' => true, 'saved' => false]);
        exit;
    }
    try {
        $pdo = getDBConnection();
        $activeProjectId = requireActiveProjectId($pdo);
        if ($activeProjectId === null) {
            echo json_encode(['success' => false, 'error' => 'No active project selected']);
            exit;
        }
        $result = ProjectService::recordQboWeeklyImportConsent(
            $pdo,
            (int) $_SESSION['org_id'],
            $activeProjectId,
            (int) $_SESSION['user_id']
        );
        if (empty($result['success'])) {
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Could not save consent']);
            exit;
        }
        echo json_encode(['success' => true, 'saved' => true]);
    } catch (Throwable $e) {
        error_log('handleSaveQboWeeklyImportConsent: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not save consent']);
    }
    exit;
}

function handleQboRevisitPromptStatus(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!OrgRole::canConnectQbo(sessionOrgRole())) {
        echo json_encode([
            'success' => true,
            'show' => false,
            'connected' => false,
            'prompt_enabled' => false,
        ]);
        exit;
    }
    try {
        $pdo = getDBConnection();
        $orgId = (int) $_SESSION['org_id'];
        $activeProjectId = requireActiveProjectId($pdo);
        $promptEnabled = false;
        $st = $pdo->prepare('SELECT prompt_qbo_sync_on_revisit FROM organizations WHERE id = ? LIMIT 1');
        $st->execute([$orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && array_key_exists('prompt_qbo_sync_on_revisit', $row)) {
            $promptEnabled = (bool) $row['prompt_qbo_sync_on_revisit'];
        }
        $svc = new QboService($pdo);
        $connected = $svc->isConnected($orgId);
        $hasVendors = $activeProjectId !== null
            && VendorService::projectHasVendors($pdo, $orgId, $activeProjectId);
        $onboarding = !empty($_SESSION['project_onboarding_required']);
        $show = $promptEnabled && $connected && $hasVendors && !$onboarding;
        echo json_encode([
            'success' => true,
            'show' => $show,
            'connected' => $connected,
            'prompt_enabled' => $promptEnabled,
            'has_vendors' => $hasVendors,
            'active_project_id' => $activeProjectId,
        ]);
    } catch (Throwable $e) {
        error_log('handleQboRevisitPromptStatus: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not load revisit prompt status']);
    }
    exit;
}

function handleSaveQboRevisitPromptPref(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!OrgRole::canConnectQbo(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'Only an Org Admin or Finance Admin can change this setting.']);
        exit;
    }
    $enabled = isset($_POST['prompt_enabled']) && (string) $_POST['prompt_enabled'] === '1';
    try {
        $pdo = getDBConnection();
        $st = $pdo->prepare('UPDATE organizations SET prompt_qbo_sync_on_revisit = ? WHERE id = ?');
        $st->execute([$enabled ? 1 : 0, (int) $_SESSION['org_id']]);
        echo json_encode(['success' => true, 'prompt_enabled' => $enabled]);
    } catch (Throwable $e) {
        error_log('handleSaveQboRevisitPromptPref: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not save preference']);
    }
    exit;
}

function handleQboIncrementalSync(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || empty($_SESSION['org_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!OrgRole::canConnectQbo(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'Only an Org Admin or Finance Admin can sync QuickBooks.']);
        exit;
    }
    $startDate = trim((string) ($_POST['start_date'] ?? ''));
    $endDate = trim((string) ($_POST['end_date'] ?? ''));
    try {
        if ($startDate === '' || $endDate === '') {
            $end = new DateTimeImmutable('today');
            $start = $end->modify('-1 year');
            $startDate = $start->format('Y-m-d');
            $endDate = $end->format('Y-m-d');
        }
        QboService::assertValidDateRange($startDate, $endDate);
        $pdo = getDBConnection();
        $activeProjectId = requireActiveProjectId($pdo);
        if ($activeProjectId === null) {
            echo json_encode(['success' => false, 'error' => 'No active project selected']);
            exit;
        }
        $svc = new QboService($pdo);
        if (!$svc->isConnected((int) $_SESSION['org_id'])) {
            echo json_encode([
                'success' => false,
                'error' => 'QuickBooks is not connected. Open Settings to connect.',
                'needs_setup' => true,
            ]);
            exit;
        }
        $rows = $svc->fetchTransactionRows((int) $_SESSION['org_id'], $startDate, $endDate);
        if (count($rows) === 0) {
            echo json_encode([
                'success' => true,
                'inserted' => 0,
                'vendors_skipped' => 0,
                'raw_inserted' => 0,
                'raw_skipped' => 0,
                'message' => 'No transactions found for the selected date range.',
            ]);
            exit;
        }
        $payload = QboService::buildImportPayload($rows);
        $summaryRows = $payload['summary'] ?? [];
        $rawRows = $payload['raw'] ?? [];
        if (!is_array($summaryRows)) {
            $summaryRows = [];
        }
        if (!is_array($rawRows)) {
            $rawRows = [];
        }
        $result = importIncrementalVendorData($summaryRows, $rawRows);
        echo json_encode($result);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('handleQboIncrementalSync: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function handleSaveCostCalculator() {
    header('Content-Type: application/json');

    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in']);
        exit;
    }

    $itemsRaw = $_POST['items'] ?? '[]';
    if (is_array($itemsRaw)) {
        $items = $itemsRaw;
    } else {
        $items = json_decode((string) $itemsRaw, true);
    }
    if (!is_array($items)) {
        echo json_encode(['success' => false, 'error' => 'Invalid items data']);
        exit;
    }
    $fullSync = !empty($_POST['full_sync']) || !empty($_POST['fullSync']);

    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $uid = (int) $_SESSION['user_id'];
    $role = sessionProjectRole();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
    }

    $webhookUrl = loadOrganizationWebhookUrl($pdo, $orgId);
    $previousStatusById = [];
    $previousStatusByVendor = [];
    $previousPurposeById = [];
    $gridRole = effectiveVendorGridRole();
    $beforeItems = VendorService::loadVisibleItems($pdo, $uid, $orgId, $activeProjectId, $gridRole);
    foreach ($beforeItems as $it) {
        if (!is_array($it)) {
            continue;
        }
        $oldStatus = VendorService::resolveStatusFromItem($it);
        $oldId = isset($it['id']) ? (int) $it['id'] : 0;
        if ($oldId > 0) {
            $previousStatusById[$oldId] = $oldStatus;
            $previousPurposeById[$oldId] = trim((string) ($it['purpose_of_subscription'] ?? $it['notes'] ?? ''));
        }
        if ($webhookUrl !== '') {
            $oldVendorKey = strtolower(trim((string) ($it['vendor_name'] ?? '')));
            if ($oldVendorKey !== '') {
                if (!isset($previousStatusByVendor[$oldVendorKey]) || $oldStatus === VendorService::STATUS_MARK) {
                    $previousStatusByVendor[$oldVendorKey] = $oldStatus;
                }
            }
        }
    }

    if (OrgRole::canManageVendorStructure($gridRole)) {
        $result = VendorService::saveAdmin($pdo, $orgId, $activeProjectId, $uid, $gridRole, $items, $fullSync);
    } elseif (OrgRole::canEditVendorFields($gridRole)) {
        $result = VendorService::saveMember($pdo, $orgId, $activeProjectId, $uid, $items, $fullSync);
    } else {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to edit vendor rows.']);
        exit;
    }

    if (($result['success'] ?? false) && $webhookUrl !== '') {
        $projectName = resolveProjectName($pdo, $orgId, $activeProjectId);
        $sentKeys = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $newStatus = VendorService::resolveStatusFromItem($item);
            if ($newStatus !== VendorService::STATUS_MARK) {
                continue;
            }

            $rowId = isset($item['id']) ? (int) $item['id'] : 0;
            $vendorName = trim((string) ($item['vendor_name'] ?? ''));
            $vendorKey = strtolower($vendorName);

            $prevStatus = null;
            if ($rowId > 0 && isset($previousStatusById[$rowId])) {
                $prevStatus = $previousStatusById[$rowId];
            } elseif ($vendorKey !== '' && isset($previousStatusByVendor[$vendorKey])) {
                $prevStatus = $previousStatusByVendor[$vendorKey];
            }
            if ($prevStatus === VendorService::STATUS_MARK) {
                continue;
            }

            $uniqueKey = ($rowId > 0 ? ('id:' . $rowId) : ('vendor:' . $vendorKey));
            if (isset($sentKeys[$uniqueKey])) {
                continue;
            }
            $sentKeys[$uniqueKey] = true;

            $payload = [
                'event' => 'vendor_mark_for_cancellation',
                'project_name' => $projectName,
                'vendor_name' => $vendorName,
                'vendor_item_id' => ($rowId > 0 ? $rowId : null),
                'status' => $newStatus,
                'triggered_at' => date('c'),
                'org_id' => $orgId,
            ];
            postWebhookJson($webhookUrl, $payload);
        }
    }

    if ($result['success'] ?? false) {
        $actorName = trim((string) ($_SESSION['username'] ?? ''));
        if ($actorName === '') {
            $actorName = trim((string) ($_SESSION['user_email'] ?? ''));
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rowId = isset($item['id']) ? (int) $item['id'] : 0;
            if ($rowId <= 0 || !isset($previousStatusById[$rowId])) {
                continue;
            }

            $newStatus = VendorService::resolveStatusFromItem($item);
            $oldStatus = $previousStatusById[$rowId];
            if ($newStatus !== $oldStatus) {
                VendorChatService::appendActionLogEntry(
                    $pdo,
                    $orgId,
                    $activeProjectId,
                    $rowId,
                    $uid,
                    $actorName,
                    VendorChatService::formatStatusChangeMessage($oldStatus, $newStatus)
                );
            }

            $newPurpose = trim((string) ($item['purpose_of_subscription'] ?? $item['notes'] ?? ''));
            $oldPurpose = $previousPurposeById[$rowId] ?? '';
            if ($newPurpose !== $oldPurpose) {
                VendorChatService::appendActionLogEntry(
                    $pdo,
                    $orgId,
                    $activeProjectId,
                    $rowId,
                    $uid,
                    $actorName,
                    VendorChatService::formatPurposeChangeMessage($oldPurpose, $newPurpose)
                );
            }
        }
    }

    echo json_encode($result);
    exit;
}

function handleLoadCostCalculator() {
    header('Content-Type: application/json; charset=UTF-8');

    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in', 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = getDBConnection();
        $activeProjectId = requireActiveProjectId($pdo);
        if ($activeProjectId === null) {
            echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $gridRole = effectiveVendorGridRole();
        $items = VendorService::loadVisibleItems(
            $pdo,
            (int) $_SESSION['user_id'],
            (int) $_SESSION['org_id'],
            $activeProjectId,
            $gridRole
        );
        $uid = (int) $_SESSION['user_id'];
        $counts = VendorChatService::unreadCountsForUserProject(
            $pdo,
            (int) $_SESSION['org_id'],
            $activeProjectId,
            $uid,
        sessionProjectRole()
        );
        $tagged = VendorChatService::taggedCountsForUserProject(
            $pdo,
            (int) $_SESSION['org_id'],
            $activeProjectId,
            $uid,
        sessionProjectRole()
        );
        foreach ($items as &$it) {
            if (!is_array($it)) {
                continue;
            }
            $iid = (int) ($it['id'] ?? 0);
            $it['vendor_chat_unread'] = $counts[$iid] ?? 0;
            $it['vendor_chat_tagged'] = $tagged[$iid] ?? 0;
        }
        unset($it);

        $payload = ['success' => true, 'items' => $items];
        $payload['categories'] = CategoryService::listForProject(
            $pdo,
            (int) $_SESSION['org_id'],
            $activeProjectId
        );
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode($payload, $flags);
        if ($json === false) {
            error_log('handleLoadCostCalculator json_encode failed: ' . json_last_error_msg());
            echo json_encode(
                ['success' => false, 'error' => 'Could not encode vendor data for load.', 'items' => []],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        echo $json;
    } catch (Throwable $e) {
        error_log('handleLoadCostCalculator: ' . $e->getMessage());
        echo json_encode(
            ['success' => false, 'error' => 'Could not load calculator data.', 'items' => []],
            JSON_UNESCAPED_UNICODE
        );
    }
    exit;
}

function handleLoadVendorRawData() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in', 'transactions' => []]);
        exit;
    }
    $vendorName = trim((string) ($_POST['vendor_name'] ?? ''));
    if ($vendorName === '') {
        echo json_encode(['success' => false, 'error' => 'Vendor name is required', 'transactions' => []]);
        exit;
    }
    $pdo = getDBConnection();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected', 'transactions' => []]);
        exit;
    }
    $rows = VendorService::loadRawTransactionsForVisibleVendor(
        $pdo,
        (int) $_SESSION['org_id'],
        $activeProjectId,
        (int) $_SESSION['user_id'],
        sessionProjectRole(),
        $vendorName
    );
    echo json_encode([
        'success' => true,
        'vendor_name' => $vendorName,
        'transactions' => $rows,
    ]);
    exit;
}

function handleLoadVendorChatMessages() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in', 'messages' => []]);
        exit;
    }

    $vendorItemId = (int) ($_POST['vendor_item_id'] ?? 0);
    if ($vendorItemId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Vendor row id is required', 'messages' => []]);
        exit;
    }

    $pdo = getDBConnection();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected', 'messages' => []]);
        exit;
    }

    $result = VendorChatService::loadMessages(
        $pdo,
        (int) $_SESSION['org_id'],
        $activeProjectId,
        $vendorItemId,
        (int) $_SESSION['user_id'],
        sessionProjectRole()
    );
    echo json_encode($result);
    exit;
}

function handleAddVendorChatMessage() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in']);
        exit;
    }

    $vendorItemId = (int) ($_POST['vendor_item_id'] ?? 0);
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($vendorItemId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Vendor row id is required']);
        exit;
    }
    if ($message === '') {
        echo json_encode(['success' => false, 'error' => 'Message is required']);
        exit;
    }

    $pdo = getDBConnection();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
    }

    $username = trim((string) ($_SESSION['username'] ?? ''));
    if ($username === '') {
        $username = trim((string) ($_SESSION['user_email'] ?? ''));
    }

    $result = VendorChatService::addMessage(
        $pdo,
        (int) $_SESSION['org_id'],
        $activeProjectId,
        $vendorItemId,
        (int) $_SESSION['user_id'],
        $username,
        $message,
        sessionProjectRole()
    );
    echo json_encode($result);
    exit;
}

function handleEditVendorChatMessage() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in']);
        exit;
    }

    $vendorItemId = (int) ($_POST['vendor_item_id'] ?? 0);
    $messageId = (int) ($_POST['message_id'] ?? 0);
    $message = trim((string) ($_POST['message'] ?? ''));
    if ($vendorItemId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Vendor row id is required']);
        exit;
    }
    if ($messageId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Message id is required']);
        exit;
    }
    if ($message === '') {
        echo json_encode(['success' => false, 'error' => 'Message is required']);
        exit;
    }

    $pdo = getDBConnection();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
    }

    $result = VendorChatService::editMessage(
        $pdo,
        (int) $_SESSION['org_id'],
        $activeProjectId,
        $vendorItemId,
        $messageId,
        (int) $_SESSION['user_id'],
        sessionProjectRole(),
        $message
    );
    echo json_encode($result);
    exit;
}

function handleVendorChatUnreadCounts() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in', 'counts' => [], 'tagged' => []]);
        exit;
    }

    $pdo = getDBConnection();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => true, 'counts' => [], 'tagged' => []]);
        exit;
    }

    $uid = (int) $_SESSION['user_id'];
    $counts = VendorChatService::unreadCountsForUserProject(
        $pdo,
        (int) $_SESSION['org_id'],
        $activeProjectId,
        $uid,
        sessionProjectRole()
    );
    $tagged = VendorChatService::taggedCountsForUserProject(
        $pdo,
        (int) $_SESSION['org_id'],
        $activeProjectId,
        $uid,
        sessionProjectRole()
    );
    $sparse = [];
    foreach ($counts as $vid => $n) {
        if ($n > 0) {
            $sparse[(string) $vid] = $n;
        }
    }
    $sparseTagged = [];
    foreach ($tagged as $vid => $n) {
        if ($n > 0) {
            $sparseTagged[(string) $vid] = $n;
        }
    }

    echo json_encode(['success' => true, 'counts' => $sparse, 'tagged' => $sparseTagged]);
    exit;
}

function handleProjectList() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'projects' => []]);
        exit;
    }
    $pdo = getDBConnection();
    $projects = ProjectService::listForUser(
        $pdo,
        (int) $_SESSION['org_id'],
        (int) $_SESSION['user_id'],
        sessionProjectRole()
    );
    $activeProjectId = requireActiveProjectId($pdo);
    echo json_encode([
        'success' => true,
        'projects' => $projects,
        'active_project_id' => $activeProjectId,
        'onboarding_required' => !empty($_SESSION['project_onboarding_required']),
    ]);
    exit;
}

function handleProjectSetActive() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $pdo = getDBConnection();
    if (!ProjectService::canAccessProject(
        $pdo,
        $projectId,
        (int) $_SESSION['org_id'],
        (int) $_SESSION['user_id'],
        sessionProjectRole()
    )) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to this project.']);
        exit;
    }
    $_SESSION['active_project_id'] = $projectId;
    RoleContext::syncSession($pdo, (int) $_SESSION['user_id'], (int) $_SESSION['org_id'], $projectId);
    RoleContext::persistLastProjectId($pdo, (int) $_SESSION['user_id'], $projectId);
    echo json_encode(['success' => true, 'active_project_id' => $projectId, 'project_role' => sessionProjectRole()]);
    exit;
}

function handleProjectCreate() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please sign in again.']);
        exit;
    }
    if (!OrgRole::canCreateProject(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to create projects.']);
        exit;
    }
    $projectName = trim((string) ($_POST['project_name'] ?? ''));
    $startDate = trim((string) ($_POST['start_date'] ?? ''));
    if ($startDate === '') {
        $startDate = date('Y-m-d');
    }
    $endDate = trim((string) ($_POST['end_date'] ?? ''));
    $memberIds = parseMemberIds($_POST['member_ids'] ?? []);
    $memberRoles = parseMemberRoles($_POST['member_roles'] ?? []);
    $copyFromActive = isset($_POST['copy_from_active']) && (string) $_POST['copy_from_active'] === '1';
    $sourceProjectId = isset($_POST['source_project_id']) ? (int) $_POST['source_project_id'] : 0;

    $pdo = getDBConnection();
    $userId = (int) $_SESSION['user_id'];
    $orgId = ensureUserOrganizationId($pdo, $userId);
    if ($orgId < 1) {
        echo json_encode(['success' => false, 'error' => 'Your account organization could not be loaded. Try logging in again.']);
        exit;
    }
    $_SESSION['org_id'] = $orgId;
    $create = ProjectService::createProject(
        $pdo,
        $orgId,
        $userId,
        $projectName,
        $startDate,
        $endDate === '' ? null : $endDate,
        $memberIds,
        $memberRoles
    );
    if (!($create['success'] ?? false)) {
        echo json_encode($create);
        exit;
    }
    $newProjectId = (int) $create['project_id'];

    if ($copyFromActive) {
        if ($sourceProjectId <= 0) {
            $sourceProjectId = isset($_SESSION['active_project_id']) ? (int) $_SESSION['active_project_id'] : 0;
        }
        if ($sourceProjectId > 0) {
            ProjectService::copyProjectData($pdo, $orgId, $sourceProjectId, $newProjectId, $userId, sessionProjectRole());
        }
    }

    $_SESSION['active_project_id'] = $newProjectId;
    $_SESSION['project_onboarding_required'] = false;
    RoleContext::syncSession($pdo, $userId, $orgId, $newProjectId);
    RoleContext::persistLastProjectId($pdo, $userId, $newProjectId);
    echo json_encode(['success' => true, 'project_id' => $newProjectId]);
    exit;
}

function handleProjectRename(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please sign in again.']);
        exit;
    }
    if (!OrgRole::canRenameProject(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to rename projects.']);
        exit;
    }
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $projectName = trim((string) ($_POST['project_name'] ?? ''));
    if ($projectId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid project.']);
        exit;
    }
    if ($projectName === '') {
        echo json_encode(['success' => false, 'error' => 'Project name is required.']);
        exit;
    }
    if (strlen($projectName) > 255) {
        $projectName = substr($projectName, 0, 255);
    }

    $pdo = getDBConnection();
    $orgId = (int) ($_SESSION['org_id'] ?? 0);
    $userId = (int) $_SESSION['user_id'];
    $role = sessionOrgRole();
    if ($orgId < 1 || !ProjectService::canAccessProject($pdo, $projectId, $orgId, $userId, $role)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to this project.']);
        exit;
    }

    try {
        $st = $pdo->prepare('UPDATE projects SET name = ? WHERE id = ? AND org_id = ?');
        $st->execute([$projectName, $projectId, $orgId]);
        if ($st->rowCount() < 1) {
            // Name may be unchanged — verify the project still exists.
            $chk = $pdo->prepare('SELECT name FROM projects WHERE id = ? AND org_id = ? LIMIT 1');
            $chk->execute([$projectId, $orgId]);
            $existing = $chk->fetchColumn();
            if ($existing === false || $existing === null) {
                echo json_encode(['success' => false, 'error' => 'Project not found.']);
                exit;
            }
        }
        echo json_encode([
            'success' => true,
            'project_id' => $projectId,
            'project_name' => $projectName,
        ]);
    } catch (PDOException $e) {
        error_log('handleProjectRename: ' . $e->getMessage());
        $msg = $e->getMessage();
        if (stripos($msg, 'uk_projects_org_name') !== false
            || (stripos($msg, '1062') !== false && stripos($msg, 'Duplicate') !== false)) {
            echo json_encode(['success' => false, 'error' => 'A project with this name already exists.']);
            exit;
        }
        echo json_encode(['success' => false, 'error' => 'Could not rename project.']);
    }
    exit;
}

function handleListOrganizations(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'organizations' => []]);
        exit;
    }
    $pdo = getDBConnection();
    $orgs = RoleContext::listUserOrganizations($pdo, (int) $_SESSION['user_id']);
    echo json_encode([
        'success' => true,
        'organizations' => $orgs,
        'active_org_id' => (int) ($_SESSION['org_id'] ?? 0),
    ]);
    exit;
}

function handleOrgSetActive(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $orgId = (int) ($_POST['org_id'] ?? 0);
    $userId = (int) $_SESSION['user_id'];
    $pdo = getDBConnection();
    $orgRole = RoleContext::orgRole($pdo, $userId, $orgId);
    if ($orgRole === null) {
        echo json_encode(['success' => false, 'error' => 'You are not a manager of that organization.']);
        exit;
    }
    RoleContext::persistLastOrgId($pdo, $userId, $orgId);
    $projectCount = ProjectService::orgProjectCount($pdo, $orgId);
    $_SESSION['project_onboarding_required'] = OrgRole::canCreateProject((string) $orgRole) && $projectCount === 0;
    $preferredProjectId = RoleContext::readLastProjectId($pdo, $userId);
    $activeProjectId = ProjectService::resolveActiveProjectId(
        $pdo,
        $orgId,
        $userId,
        $orgRole,
        $preferredProjectId > 0 ? $preferredProjectId : null
    );
    RoleContext::syncSession($pdo, $userId, $orgId, $activeProjectId);
    $_SESSION['user_orgs'] = RoleContext::listUserOrganizations($pdo, $userId);
    if ($activeProjectId !== null) {
        RoleContext::persistLastProjectId($pdo, $userId, $activeProjectId);
        ProjectService::backfillNullProjectRows($pdo, $orgId, $activeProjectId);
    }
    echo json_encode([
        'success' => true,
        'org_id' => $orgId,
        'org_role' => sessionOrgRole(),
        'active_project_id' => $activeProjectId,
        'project_role' => sessionProjectRole(),
    ]);
    exit;
}

function handleOrgCreate(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    $userId = (int) $_SESSION['user_id'];
    $pdo = getDBConnection();
    if (userJoinedViaInvite($pdo, $userId)) {
        echo json_encode([
            'success' => false,
            'error' => 'Invited managers can only access the organization they were invited to.',
        ]);
        exit;
    }
    $orgName = trim((string) ($_POST['org_name'] ?? ''));
    if ($orgName === '') {
        echo json_encode(['success' => false, 'error' => 'Organization name is required.']);
        exit;
    }
    if (strlen($orgName) > 255) {
        $orgName = substr($orgName, 0, 255);
    }
    try {
        $ins = $pdo->prepare('INSERT INTO organizations (name, max_users) VALUES (?, 20)');
        $ins->execute([$orgName]);
        $newOrgId = (int) $pdo->lastInsertId();
        if ($newOrgId < 1) {
            echo json_encode(['success' => false, 'error' => 'Could not create organization.']);
            exit;
        }
        RoleContext::upsertOrgMembership($pdo, $userId, $newOrgId, OrgRole::ROLE_ORG_ADMIN);
        RoleContext::persistLastOrgId($pdo, $userId, $newOrgId);
        $_SESSION['project_onboarding_required'] = true;
        RoleContext::syncSession($pdo, $userId, $newOrgId, null);
        $_SESSION['user_orgs'] = RoleContext::listUserOrganizations($pdo, $userId);
        echo json_encode([
            'success' => true,
            'org_id' => $newOrgId,
            'org_role' => OrgRole::ROLE_ORG_ADMIN,
        ]);
    } catch (PDOException $e) {
        error_log('handleOrgCreate: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not create organization.']);
    }
    exit;
}

function handleOrgRename(): void
{
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || (int) ($_SESSION['org_id'] ?? 0) < 1) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    if (!OrgRole::canRenameOrg(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'Only an Org Admin can rename the organization.']);
        exit;
    }
    $orgId = (int) $_SESSION['org_id'];
    $orgName = trim((string) ($_POST['org_name'] ?? ''));
    if ($orgName === '') {
        echo json_encode(['success' => false, 'error' => 'Organization name is required.']);
        exit;
    }
    if (strlen($orgName) > 255) {
        $orgName = substr($orgName, 0, 255);
    }
    try {
        $pdo = getDBConnection();
        $upd = $pdo->prepare('UPDATE organizations SET name = ? WHERE id = ?');
        $upd->execute([$orgName, $orgId]);
        $_SESSION['user_orgs'] = RoleContext::listUserOrganizations($pdo, (int) $_SESSION['user_id']);
        echo json_encode([
            'success' => true,
            'org_id' => $orgId,
            'org_name' => $orgName,
        ]);
    } catch (PDOException $e) {
        error_log('handleOrgRename: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Could not rename organization.']);
    }
    exit;
}

function handleCopyProjectPurposes() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please sign in again.']);
        exit;
    }
    if (!OrgRole::canManageVendorStructure(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to copy project purposes.']);
        exit;
    }
    $fromProjectId = (int) ($_POST['from_project_id'] ?? 0);
    $toProjectId = (int) ($_POST['to_project_id'] ?? 0);
    if ($fromProjectId <= 0 || $toProjectId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Source and target projects are required.']);
        exit;
    }
    if ($fromProjectId === $toProjectId) {
        echo json_encode(['success' => false, 'error' => 'Source and target projects must be different.']);
        exit;
    }
    $pdo = getDBConnection();
    $userId = (int) $_SESSION['user_id'];
    $orgId = (int) ($_SESSION['org_id'] ?? 0);
    $role = sessionProjectRole();
    if ($orgId < 1) {
        echo json_encode(['success' => false, 'error' => 'Organization could not be loaded.']);
        exit;
    }
    if (!ProjectService::canAccessProject($pdo, $fromProjectId, $orgId, $userId, $role)
        || !ProjectService::canAccessProject($pdo, $toProjectId, $orgId, $userId, $role)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to one or both projects.']);
        exit;
    }
    $includeBlankPurposes = !empty($_POST['include_blank_purposes']);
    $result = ProjectService::copyPurposesBetweenProjects(
        $pdo,
        $orgId,
        $fromProjectId,
        $toProjectId,
        $userId,
        $role,
        $includeBlankPurposes
    );
    echo json_encode($result);
    exit;
}

function handleCopyProjectChats() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please sign in again.']);
        exit;
    }
    if (!OrgRole::canManageVendorStructure(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to copy project chats.']);
        exit;
    }
    $fromProjectId = (int) ($_POST['from_project_id'] ?? 0);
    $toProjectId = (int) ($_POST['to_project_id'] ?? 0);
    if ($fromProjectId <= 0 || $toProjectId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Source and target projects are required.']);
        exit;
    }
    if ($fromProjectId === $toProjectId) {
        echo json_encode(['success' => false, 'error' => 'Source and target projects must be different.']);
        exit;
    }
    $pdo = getDBConnection();
    $userId = (int) $_SESSION['user_id'];
    $orgId = (int) ($_SESSION['org_id'] ?? 0);
    $role = sessionProjectRole();
    if ($orgId < 1) {
        echo json_encode(['success' => false, 'error' => 'Organization could not be loaded.']);
        exit;
    }
    if (!ProjectService::canAccessProject($pdo, $fromProjectId, $orgId, $userId, $role)
        || !ProjectService::canAccessProject($pdo, $toProjectId, $orgId, $userId, $role)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to one or both projects.']);
        exit;
    }
    $result = VendorChatService::copyChatsBetweenProjects(
        $pdo,
        $orgId,
        $fromProjectId,
        $toProjectId,
        $userId,
        $role
    );
    echo json_encode($result);
    exit;
}

function handleCategoryCreate() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please sign in again.']);
        exit;
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Category name is required.']);
        exit;
    }
    $pdo = getDBConnection();
    $orgId = (int) ($_SESSION['org_id'] ?? 0);
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
    }
    $created = CategoryService::findOrCreateByName($pdo, $orgId, $activeProjectId, $name);
    if (!$created) {
        echo json_encode(['success' => false, 'error' => 'Could not create category.']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'id' => $created['id'],
        'name' => $created['name'],
        'created' => $created['created'] ?? false,
    ]);
    exit;
}

function handleCopyProjectCategories() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please sign in again.']);
        exit;
    }
    if (!OrgRole::canManageVendorStructure(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to copy project categories.']);
        exit;
    }
    $fromProjectId = (int) ($_POST['from_project_id'] ?? 0);
    $toProjectId = (int) ($_POST['to_project_id'] ?? 0);
    if ($fromProjectId <= 0 || $toProjectId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Source and target projects are required.']);
        exit;
    }
    if ($fromProjectId === $toProjectId) {
        echo json_encode(['success' => false, 'error' => 'Source and target projects must be different.']);
        exit;
    }
    $pdo = getDBConnection();
    $userId = (int) $_SESSION['user_id'];
    $orgId = (int) ($_SESSION['org_id'] ?? 0);
    $role = sessionProjectRole();
    if ($orgId < 1) {
        echo json_encode(['success' => false, 'error' => 'Organization could not be loaded.']);
        exit;
    }
    if (!ProjectService::canAccessProject($pdo, $fromProjectId, $orgId, $userId, $role)
        || !ProjectService::canAccessProject($pdo, $toProjectId, $orgId, $userId, $role)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to one or both projects.']);
        exit;
    }
    $result = CategoryService::copyAssignmentsBetweenProjects(
        $pdo,
        $orgId,
        $fromProjectId,
        $toProjectId,
        $userId,
        $role
    );
    echo json_encode($result);
    exit;
}

function handleProjectDelete() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please sign in again.']);
        exit;
    }
    if (!OrgRole::canDeleteProject(sessionOrgRole())) {
        echo json_encode(['success' => false, 'error' => 'You do not have permission to delete projects.']);
        exit;
    }
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $userId = (int) $_SESSION['user_id'];
    $role = sessionProjectRole();

    $result = ProjectService::deleteProject($pdo, $orgId, $projectId, $userId, $role);
    if (!($result['success'] ?? false)) {
        echo json_encode($result);
        exit;
    }

    $preferredAfterDelete = isset($_SESSION['active_project_id']) ? (int) $_SESSION['active_project_id'] : 0;
    if ($preferredAfterDelete === $projectId) {
        $preferredAfterDelete = RoleContext::readLastProjectId($pdo, $userId);
        if ($preferredAfterDelete === $projectId) {
            $preferredAfterDelete = 0;
        }
    }
    $newActive = ProjectService::resolveActiveProjectId(
        $pdo,
        $orgId,
        $userId,
        $role,
        $preferredAfterDelete > 0 ? $preferredAfterDelete : null
    );
    if ($newActive !== null) {
        $_SESSION['active_project_id'] = $newActive;
        RoleContext::persistLastProjectId($pdo, $userId, $newActive);
    } else {
        unset($_SESSION['active_project_id']);
    }

    echo json_encode([
        'success' => true,
        'active_project_id' => $newActive,
    ]);
    exit;
}
