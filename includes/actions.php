<?php

require_once __DIR__ . '/pro_log.php';

use CostSavings\AiService;
use CostSavings\CsvImport;
use CostSavings\ExportService;
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
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['org_id'] = (int) $row['org_id'];
    $_SESSION['role'] = $row['role'];
    $_SESSION['username'] = $row['username'] ?? '';
    $_SESSION['user_email'] = normalizeUserEmail($row['email']);
    loadUserResponses($_SESSION['user_email']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
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
    $mailResult = sendEmail($email, 'Your invitation — Cost Savings Pro Tool', $body);
    if ($mailResult !== true) {
        if (is_array($mailResult)) {
            error_log(
                'handleInviteMember mail: '
                . ($mailResult['error_message'] ?? '')
                . ' '
                . ($mailResult['error_info'] ?? '')
            );
            if (!empty($mailResult['smtp_debug']) && defined('SMTP_BROWSER_DEBUG') && SMTP_BROWSER_DEBUG) {
                $_SESSION['smtp_debug_transcript'] = (string) $mailResult['smtp_debug'];
            }
        }
        logInviteEvent('mail_failed', ['invitation_id' => $invId, 'org_id' => $orgId, 'email' => $email]);
        // Keep the invitation row so the token stays valid; admin can share the link manually.
        $_SESSION['error'] = 'Could not send invitation email. Check SMTP_HOST, SMTP_PORT, SMTP_SECURE (ssl vs tls), and credentials in config.php. '
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
    $items = [];
    foreach ($summaryRows as $row) {
        $items[] = [
            'vendor_name' => $row['vendor_name'],
            'cost_per_period' => $row['cost_per_period'],
            'frequency' => $row['frequency'],
            'annual_cost' => $row['annual_cost'],
            'cancel_keep' => 'Keep',
            'cancelled_status' => 0,
            'purpose_of_subscription' => '',
            'notes' => '',
            'visibility' => 'confidential',
            'manager_user_id' => $uid,
            'cancellation_deadline' => null,
            'last_payment_date' => $row['last_payment_date'],
        ];
    }
    $res = VendorService::appendImportedRows($pdo, $orgId, $uid, $items);
    if (!($res['success'] ?? false)) {
        echo json_encode($res);
        exit;
    }
    $batchId = bin2hex(random_bytes(12));
    $rawRes = VendorService::appendRawTransactions($pdo, $orgId, $uid, $batchId, $rawRows);
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
    $items = VendorService::loadVisibleItems(
        $pdo,
        (int) $_SESSION['user_id'],
        (int) $_SESSION['org_id'],
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
    $q = trim((string) ($_POST['question'] ?? ''));
    $preset = isset($_POST['preset']) ? (string) $_POST['preset'] : null;
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
        $_SESSION['role'] ?? 'member'
    );
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
    $apply = VendorService::updatePurposesForVisibleRows($pdo, $orgId, $userId, $role, $updates);
    echo json_encode([
        'success' => true,
        'updated' => $apply['updated'] ?? 0,
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
    $st = $pdo->prepare('SELECT id, username, display_name, email, role FROM users WHERE org_id = ? ORDER BY username, email');
    $st->execute([(int) $_SESSION['org_id']]);
    $members = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'members' => $members]);
    exit;
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
        $stOrg = $pdo->prepare('UPDATE organizations SET deadline_reminders_enabled = ? WHERE id = ?');
        $stOrg->execute([$orgOn ? 1 : 0, (int) $_SESSION['org_id']]);
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

    if ($role === 'admin') {
        $result = VendorService::saveAdmin($pdo, $orgId, $uid, $items);
    } else {
        $result = VendorService::saveMember($pdo, $orgId, $uid, $items);
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
    $items = VendorService::loadVisibleItems(
        $pdo,
        (int) $_SESSION['user_id'],
        (int) $_SESSION['org_id'],
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
    $rows = VendorService::loadRawTransactionsForVisibleVendor(
        $pdo,
        (int) $_SESSION['org_id'],
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
