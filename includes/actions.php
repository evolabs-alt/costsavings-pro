<?php

require_once __DIR__ . '/pro_log.php';

use CostSavings\AiService;
use CostSavings\CsvImport;
use CostSavings\ExportService;
use CostSavings\ProjectService;
use CostSavings\VendorChatService;
use CostSavings\VendorPurposeService;
use CostSavings\VendorService;

function normalizeUserEmail($email) {
    return strtolower(trim($email));
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
 */
function ensureUserOrganizationId(PDO $pdo, int $userId): int {
    if ($userId < 1) {
        return 0;
    }
    $st = $pdo->prepare('SELECT org_id, email, username, display_name FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    $current = isset($row['org_id']) && $row['org_id'] !== null && $row['org_id'] !== '' ? (int) $row['org_id'] : 0;
    if ($current >= 1) {
        $chk = $pdo->prepare('SELECT 1 FROM organizations WHERE id = ?');
        $chk->execute([$current]);
        if ($chk->fetchColumn()) {
            return $current;
        }
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
        $ins = $pdo->prepare('INSERT INTO organizations (name, max_users) VALUES (?, 10)');
        $ins->execute([$orgName]);
        $newOrgId = (int) $pdo->lastInsertId();
        if ($newOrgId < 1) {
            error_log('ensureUserOrganizationId: lastInsertId invalid for user ' . $userId);
            return 0;
        }
        $upd = $pdo->prepare('UPDATE users SET org_id = ? WHERE id = ?');
        $upd->execute([$newOrgId, $userId]);
        return $newOrgId;
    } catch (PDOException $e) {
        error_log('ensureUserOrganizationId: ' . $e->getMessage());
        return 0;
    }
}

function handleLogin() {
    $pdo = getDBConnection();
    $u = trim($_POST['username'] ?? '');
    $p = (string) ($_POST['password'] ?? '');
    if ($u === '' || $p === '') {
        $_SESSION['error'] = 'Enter username and password.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$u, strtolower($u)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['password_hash']) || !password_verify($p, $row['password_hash'])) {
        $_SESSION['error'] = 'Invalid credentials.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    if (!empty($row['is_disabled'])) {
        $_SESSION['error'] = 'Your account has been disabled. Contact your administrator.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['role'] = $row['role'];
    $_SESSION['username'] = $row['username'] ?? '';
    $_SESSION['user_email'] = normalizeUserEmail($row['email']);
    $userId = (int) $row['id'];
    $orgId = ensureUserOrganizationId($pdo, $userId);
    if ($orgId < 1) {
        $_SESSION['error'] = 'Your account organization could not be set up. Please contact support.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    $_SESSION['org_id'] = $orgId;
    $role = (string) ($row['role'] ?? 'member');
    $projectCount = ProjectService::orgProjectCount($pdo, $orgId);
    $_SESSION['project_onboarding_required'] = ($role === 'admin' && $projectCount === 0);
    $activeProjectId = ProjectService::resolveActiveProjectId($pdo, $orgId, $userId, $role, null);
    if ($activeProjectId !== null) {
        $_SESSION['active_project_id'] = $activeProjectId;
        ProjectService::backfillNullProjectRows($pdo, $orgId, $activeProjectId);
    } else {
        unset($_SESSION['active_project_id']);
    }
    loadUserResponses($_SESSION['user_email']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function requireActiveProjectId(PDO $pdo): ?int
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $projectId = isset($_SESSION['active_project_id']) ? (int) $_SESSION['active_project_id'] : null;
    $resolved = ProjectService::resolveActiveProjectId(
        $pdo,
        (int) $_SESSION['org_id'],
        (int) $_SESSION['user_id'],
        (string) ($_SESSION['role'] ?? 'member'),
        $projectId
    );
    if ($resolved !== null) {
        $_SESSION['active_project_id'] = $resolved;
        ProjectService::backfillNullProjectRows($pdo, (int) $_SESSION['org_id'], $resolved);
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

function handleInviteMember() {
    $redir = function () {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    };
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        $_SESSION['error'] = 'Only admins can invite members.';
        $redir();
    }
    $email = normalizeUserEmail($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email.';
        $redir();
    }
    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    logInviteEvent('request_received', [
        'admin_user_id' => (int) $_SESSION['user_id'],
        'org_id' => $orgId,
        'email' => $email,
    ]);
    $maxUsers = getOrganizationMaxUsers($pdo, $orgId);
    $c = (int) $pdo->query('SELECT COUNT(*) AS c FROM users WHERE org_id = ' . (int) $orgId)->fetch()['c'];
    if ($c >= $maxUsers) {
        logInviteEvent('blocked_org_limit', ['org_id' => $orgId, 'users' => $c, 'max_users' => $maxUsers]);
        $_SESSION['error'] = 'Organization is at the maximum of ' . $maxUsers . ' users.';
        $redir();
    }
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        logInviteEvent('blocked_existing_user', ['org_id' => $orgId, 'email' => $email]);
        $_SESSION['error'] = 'A user with this email already exists.';
        $redir();
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
        'INSERT INTO invitations (org_id, email, token_hash, invited_by_user_id, expires_at) VALUES (?,?,?,?,?)'
    );
    try {
        $ins->execute([$orgId, $email, $hash, (int) $_SESSION['user_id'], $exp]);
    } catch (PDOException $e) {
        error_log('handleInviteMember insert: ' . $e->getMessage());
        logInviteEvent('insert_failed', ['org_id' => $orgId, 'email' => $email]);
        $_SESSION['error'] = 'Could not save invitation. Please try again.';
        $redir();
    }
    $invId = (int) $pdo->lastInsertId();
    logInviteEvent('invite_saved', ['invitation_id' => $invId, 'org_id' => $orgId, 'email' => $email]);

    $link = publicAppBaseUrl() . 'register.php?token=' . urlencode($plain);
    $body = '<p>You have been invited to join the Savvy CFO Cost Savings tool.</p>'
        . '<p><a href="' . htmlspecialchars($link) . '">Complete registration</a></p>'
        . '<p style="font-size:13px;color:#555;">If the link above does not work, copy and paste this address into your browser:<br>'
        . htmlspecialchars($link) . '</p>';
    $mailResult = sendEmail($email, 'Your invitation — Savvy Expense Optimizer', $body);
    if ($mailResult !== true) {
        if (is_array($mailResult)) {
            error_log(
                'handleInviteMember mail: '
                . ($mailResult['error_message'] ?? '')
                . ' '
                . ($mailResult['error_info'] ?? '')
            );
            if (!empty($mailResult['smtp_debug']) && defined('POSTMARK_DEBUG') && POSTMARK_DEBUG) {
                $_SESSION['smtp_debug_transcript'] = (string) $mailResult['smtp_debug'];
            }
        }
        logInviteEvent('mail_failed', ['invitation_id' => $invId, 'org_id' => $orgId, 'email' => $email]);
        // Keep the invitation row so the token stays valid; admin can share the link manually.
        $_SESSION['error'] = 'Could not send invitation email. Check POSTMARK_SERVER_TOKEN, that SMTP_FROM_EMAIL matches a verified Postmark sender, and Postmark account limits. '
            . 'You can share this registration link manually: ' . $link;
        $redir();
    }
    logInviteEvent('mail_sent', ['invitation_id' => $invId, 'org_id' => $orgId, 'email' => $email]);
    $_SESSION['message'] = 'Invitation sent. If the recipient does not see it within a few minutes, ask them to check spam/junk and promotions tabs.';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function handleImportVendorCsv() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit;
    }
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
        exit;
    }
    $raw = file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($raw === false || strlen($raw) > 5_000_000) {
        echo json_encode(['success' => false, 'error' => 'File too large or unreadable']);
        exit;
    }
    $parsed = CsvImport::parse($raw);
    $summaryRows = isset($parsed['summary']) && is_array($parsed['summary']) ? $parsed['summary'] : [];
    $rawRows = isset($parsed['raw']) && is_array($parsed['raw']) ? $parsed['raw'] : [];
    if (count($summaryRows) === 0) {
        echo json_encode(['success' => false, 'error' => 'No vendor blocks parsed']);
        exit;
    }
    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $uid = (int) $_SESSION['user_id'];
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
    }
    $purposeMap = ProjectService::purposeMapFromProject($pdo, $orgId, $activeProjectId);
    $items = [];
    foreach ($summaryRows as $row) {
        $items[] = [
            'vendor_name' => $row['vendor_name'],
            'cost_per_period' => $row['cost_per_period'],
            'frequency' => $row['frequency'],
            'annual_cost' => $row['annual_cost'],
            'status' => 'pending',
            'cancel_keep' => 'Keep',
            'cancelled_status' => 0,
            'purpose_of_subscription' => $purposeMap[mb_strtolower(trim((string) $row['vendor_name']), 'UTF-8')] ?? '',
            'notes' => '',
            'visibility' => 'confidential',
            'manager_user_id' => $uid,
            'cancellation_deadline' => null,
            'last_payment_date' => $row['last_payment_date'],
        ];
    }
    $res = VendorService::appendImportedRows($pdo, $orgId, $activeProjectId, $uid, $items);
    if (!($res['success'] ?? false)) {
        echo json_encode($res);
        exit;
    }
    $batchId = bin2hex(random_bytes(12));
    $rawRes = VendorService::appendRawTransactions($pdo, $orgId, $activeProjectId, $uid, $batchId, $rawRows);
    if (!($rawRes['success'] ?? false)) {
        echo json_encode($rawRes);
        exit;
    }
    $res['raw_inserted'] = (int) ($rawRes['inserted'] ?? 0);
    $res['upload_batch_id'] = $batchId;
    echo json_encode($res);
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
        $_SESSION['role'] ?? 'member'
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
        $_SESSION['role'] ?? 'member'
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
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
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
    $role = (string) ($_SESSION['role'] ?? 'member');
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
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
        if ($id <= 0 || $purpose === '') {
            continue;
        }
        $updates[] = ['id' => $id, 'purpose' => $purpose];
    }
    $apply = VendorService::updatePurposesForVisibleRows($pdo, $orgId, $activeProjectId, $userId, $role, $updates);
    echo json_encode([
        'success' => true,
        'updated' => $apply['updated'] ?? 0,
        'applied' => $apply['applied'] ?? 0,
        'applied_ids' => $apply['applied_ids'] ?? [],
        'resolved' => $resolved['resolved'] ?? [],
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
    $st = $pdo->prepare('SELECT id, username, display_name, email, role, is_disabled FROM users WHERE org_id = ? ORDER BY username, email');
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
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        $_SESSION['error'] = 'Only admins can manage member status.';
        $redir();
    }
    $memberId = (int) ($_POST['member_id'] ?? 0);
    $disable = (int) ($_POST['disable'] ?? 0) === 1 ? 1 : 0;
    if ($memberId <= 0) {
        $_SESSION['error'] = 'Invalid member selected.';
        $redir();
    }

    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $lookup = $pdo->prepare('SELECT id, role FROM users WHERE id = ? AND org_id = ? LIMIT 1');
    $lookup->execute([$memberId, $orgId]);
    $member = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        $_SESSION['error'] = 'Member not found.';
        $redir();
    }
    if (($member['role'] ?? '') !== 'member') {
        $_SESSION['error'] = 'Only members can be disabled or enabled.';
        $redir();
    }

    $upd = $pdo->prepare('UPDATE users SET is_disabled = ? WHERE id = ? AND org_id = ?');
    $upd->execute([$disable, $memberId, $orgId]);
    $_SESSION['message'] = $disable === 1 ? 'Member disabled successfully.' : 'Member enabled successfully.';
    $redir();
}

function handleSaveOrgReminders() {
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        $_SESSION['error'] = 'Only admins can change organization settings.';

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
    $isAdmin = (($_SESSION['role'] ?? '') === 'admin');

    if ($isAdmin) {
        $orgOn = isset($_POST['deadline_reminders_enabled']) && $_POST['deadline_reminders_enabled'] === '1';
        $webhookUrl = normalizeWebhookUrl($_POST['notification_webhook_url'] ?? '');
        if ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $_SESSION['error'] = 'Please enter a valid webhook URL.';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $stOrg = $pdo->prepare('UPDATE organizations SET deadline_reminders_enabled = ?, notification_webhook_url = ? WHERE id = ?');
        $stOrg->execute([$orgOn ? 1 : 0, ($webhookUrl !== '' ? $webhookUrl : null), (int) $_SESSION['org_id']]);
    }

    $userOn = isset($_POST['user_deadline_reminders']) && $_POST['user_deadline_reminders'] === '1';
    $stUser = $pdo->prepare('UPDATE users SET deadline_reminders_enabled = ? WHERE id = ?');
    $stUser->execute([$userOn ? 1 : 0, (int) $_SESSION['user_id']]);

    $_SESSION['message'] = $isAdmin ? 'Settings saved.' : 'Reminder preference saved.';
    header('Location: ' . $_SERVER['PHP_SELF']);
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

    $pdo = getDBConnection();
    $orgId = (int) $_SESSION['org_id'];
    $uid = (int) $_SESSION['user_id'];
    $role = $_SESSION['role'] ?? 'member';
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => false, 'error' => 'No active project selected']);
        exit;
    }

    $webhookUrl = loadOrganizationWebhookUrl($pdo, $orgId);
    $previousStatusById = [];
    $previousStatusByVendor = [];
    if ($webhookUrl !== '') {
        $beforeItems = VendorService::loadVisibleItems($pdo, $uid, $orgId, $activeProjectId, (string) $role);
        foreach ($beforeItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $oldStatus = VendorService::resolveStatusFromItem($it);
            $oldId = isset($it['id']) ? (int) $it['id'] : 0;
            if ($oldId > 0) {
                $previousStatusById[$oldId] = $oldStatus;
            }
            $oldVendorKey = strtolower(trim((string) ($it['vendor_name'] ?? '')));
            if ($oldVendorKey !== '') {
                if (!isset($previousStatusByVendor[$oldVendorKey]) || $oldStatus === VendorService::STATUS_MARK) {
                    $previousStatusByVendor[$oldVendorKey] = $oldStatus;
                }
            }
        }
    }

    if ($role === 'admin') {
        $result = VendorService::saveAdmin($pdo, $orgId, $activeProjectId, $uid, $items);
    } else {
        $result = VendorService::saveMember($pdo, $orgId, $activeProjectId, $uid, $items);
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
    echo json_encode($result);
    exit;
}

function handleLoadCostCalculator() {
    header('Content-Type: application/json');

    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in', 'items' => []]);
        exit;
    }

    $pdo = getDBConnection();
    $activeProjectId = requireActiveProjectId($pdo);
    if ($activeProjectId === null) {
        echo json_encode(['success' => true, 'items' => []]);
        exit;
    }
    $items = VendorService::loadVisibleItems(
        $pdo,
        (int) $_SESSION['user_id'],
        (int) $_SESSION['org_id'],
        $activeProjectId,
        $_SESSION['role'] ?? 'member'
    );

    echo json_encode(['success' => true, 'items' => $items]);
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
        (string) ($_SESSION['role'] ?? 'member'),
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
        (string) ($_SESSION['role'] ?? 'member')
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
        (string) ($_SESSION['role'] ?? 'member')
    );
    echo json_encode($result);
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
        (string) ($_SESSION['role'] ?? 'member')
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
        (string) ($_SESSION['role'] ?? 'member')
    )) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to this project.']);
        exit;
    }
    $_SESSION['active_project_id'] = $projectId;
    echo json_encode(['success' => true, 'active_project_id' => $projectId]);
    exit;
}

function handleProjectCreate() {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id']) || (string) ($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Only admins can create projects.']);
        exit;
    }
    $projectName = trim((string) ($_POST['project_name'] ?? ''));
    $startDate = trim((string) ($_POST['start_date'] ?? ''));
    if ($startDate === '') {
        $startDate = date('Y-m-d');
    }
    $endDate = trim((string) ($_POST['end_date'] ?? ''));
    $memberIds = parseMemberIds($_POST['member_ids'] ?? []);
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
        $memberIds
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
            ProjectService::copyProjectData($pdo, $orgId, $sourceProjectId, $newProjectId, $userId);
        }
    }

    $_SESSION['active_project_id'] = $newProjectId;
    $_SESSION['project_onboarding_required'] = false;
    echo json_encode(['success' => true, 'project_id' => $newProjectId]);
    exit;
}
