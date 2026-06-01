<?php
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/actions.php';

if (isset($_SESSION['user_email'])) {
    $_SESSION['user_email'] = normalizeUserEmail($_SESSION['user_email']);
}

try {
    getDBConnection();
    syncSessionOrgRoleFromDatabase();
} catch (Exception $e) {
    // Handlers and rendering below may retry; avoid fatal page when DB is unreachable during bootstrap.
}

$user_role_options = [
    'Business owner',
    'Financial professional (book keeper, CPA, fractional CFO, accountant, etc)',
    'Aspiring business owner.',
    'Employee of a small/medium-size business.',
    'Other'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // fetch() with Content-Type: application/json does not populate $_POST; merge body for action + calculator saves
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            $jsonBody = json_decode($raw, true);
            if (is_array($jsonBody)) {
                foreach ($jsonBody as $key => $val) {
                    if ($key === 'items') {
                        $_POST['items'] = is_array($val) ? json_encode($val) : (string)$val;
                    } elseif (!isset($_POST[$key])) {
                        $_POST[$key] = $val;
                    }
                }
            }
        }
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'login':
                handleLogin();
                break;
            case 'save_user_role':
                handleSaveUserRole();
                break;
            case 'logout':
                handleLogout();
                break;
            case 'save_cost_calculator':
                handleSaveCostCalculator();
                break;
            case 'load_cost_calculator':
                handleLoadCostCalculator();
                break;
            case 'load_vendor_raw_data':
                handleLoadVendorRawData();
                break;
            case 'load_vendor_chat_messages':
                handleLoadVendorChatMessages();
                break;
            case 'add_vendor_chat_message':
                handleAddVendorChatMessage();
                break;
            case 'vendor_chat_unread_counts':
                handleVendorChatUnreadCounts();
                break;
            case 'invite_member':
                handleInviteMember();
                break;
            case 'preview_csv_import':
                handlePreviewCsvImport();
                break;
            case 'import_vendor_csv':
                handleImportVendorCsv();
                break;
            case 'ai_ask':
                handleAiAsk();
                break;
            case 'ai_usage_stats':
                handleAiUsageStats();
                break;
            case 'auto_populate_purpose':
                handleAutoPopulatePurpose();
                break;
            case 'load_team_members':
                handleLoadTeamMembers();
                break;
            case 'toggle_member_disabled':
                handleToggleMemberDisabled();
                break;
            case 'save_org_reminders':
                handleSaveOrgReminders();
                break;
            case 'save_user_reminder_pref':
                handleSaveUserReminderPref();
                break;
            case 'save_reminder_settings':
                handleSaveReminderSettings();
                break;
            case 'project_list':
                handleProjectList();
                break;
            case 'project_create':
                handleProjectCreate();
                break;
            case 'project_set_active':
                handleProjectSetActive();
                break;
            case 'project_delete':
                handleProjectDelete();
                break;
            case 'copy_project_purposes':
                handleCopyProjectPurposes();
                break;
            case 'copy_project_chats':
                handleCopyProjectChats();
                break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'export_vendors') {
    handleExportVendors();
}

// Functions
function handleSaveUserRole() {
    if (empty($_SESSION['user_email'])) {
        $_SESSION['error'] = 'Please log in first.';
        return;
    }

    global $user_role_options;

    $role = $_POST['user_role'] ?? '';
    $email = $_SESSION['user_email'];

    if (!in_array($role, $user_role_options, true)) {
        $_SESSION['error'] = 'Please select an option to continue.';
        return;
    }

    $existing_role = migrateUserRoleFromJsonIfNeeded($email);
    if ($existing_role !== null) {
        $_SESSION['user_role'] = $existing_role;
        unset($_SESSION['awaiting_role'], $_SESSION['pending_next_chapter']);
        return;
    }

    try {
        saveUserRoleToDB($email, $role, null, null);
    } catch (Exception $e) {
        error_log('handleSaveUserRole: ' . $e->getMessage());
        $_SESSION['error'] = 'Could not save your selection. Please try again.';
        return;
    }
    $_SESSION['user_role'] = $role;
    unset($_SESSION['awaiting_role'], $_SESSION['pending_next_chapter']);

    syncContactToGHL($email, $role);
}

function handleLogout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}






/**
 * DB is authoritative for user_role; one-time backfill from legacy JSON cache.
 *
 * @param string $email Normalized email
 * @return string|null
 */
function migrateUserRoleFromJsonIfNeeded($email) {
    try {
        $role = getUserRoleFromDB($email);
        if ($role !== null) {
            return $role;
        }
        $fromFile = getUserRoleFromFile($email);
        if ($fromFile === null || $fromFile === '') {
            return null;
        }
        try {
            saveUserRoleToDB($email, $fromFile, null, null);
        } catch (Exception $e) {
            error_log('migrateUserRoleFromJsonIfNeeded save: ' . $e->getMessage());
            return null;
        }
        return getUserRoleFromDB($email);
    } catch (Exception $e) {
        error_log('migrateUserRoleFromJsonIfNeeded: ' . $e->getMessage());
        return null;
    }
}

function loadUserResponses($email) {
    $role = migrateUserRoleFromJsonIfNeeded($email);
    if ($role !== null) {
        $_SESSION['user_role'] = $role;
    } else {
        unset($_SESSION['user_role']);
    }
}

// GoHighLevel (GHL) API Functions
function createGHLContact($email, $firstName = '', $lastName = '', $phone = '', $tags = []) {
    $url = GHL_API_URL . '/contacts/';
    
    // Parse name if full name is provided but first/last are not
    if (empty($firstName) && empty($lastName) && !empty($email)) {
        $emailParts = explode('@', $email);
        $namePart = $emailParts[0];
        $nameParts = explode('.', $namePart);
        if (count($nameParts) >= 2) {
            $firstName = ucfirst($nameParts[0]);
            $lastName = ucfirst($nameParts[1]);
        } else {
            $firstName = ucfirst($namePart);
            $lastName = '';
        }
    }
    
    $name = trim($firstName . ' ' . $lastName);
    if (empty($name)) {
        $name = $email;
    }
    
    $data = [
        'email' => $email,
        'firstName' => $firstName,
        'lastName' => $lastName,
        'name' => $name,
        'locationId' => GHL_LOCATION_ID,
    ];
    
    if (!empty($phone)) {
        $data['phone'] = $phone;
    }
    
    if (!empty($tags)) {
        $data['tags'] = $tags;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . GHL_API_KEY,
        'Version: ' . GHL_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    error_log("GHL Create Contact - HTTP Code: " . $http_code);
    error_log("GHL Create Contact - Response: " . substr($response, 0, 500));
    if ($curl_error) {
        error_log("GHL Create Contact - cURL Error: " . $curl_error);
    }
    
    if ($http_code === 200 || $http_code === 201) {
        $result = json_decode($response, true);
        return [
            'success' => true,
            'contact' => $result
        ];
    }
    
    return [
        'success' => false,
        'error' => $response,
        'http_code' => $http_code
    ];
}

function createGHLTag($tagName) {
    $url = GHL_API_URL . '/locations/' . GHL_LOCATION_ID . '/tags';
    
    $data = [
        'name' => $tagName
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . GHL_API_KEY,
        'Version: ' . GHL_API_VERSION
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    error_log("GHL Create Tag - HTTP Code: " . $http_code);
    error_log("GHL Create Tag - Response: " . substr($response, 0, 500));
    if ($curl_error) {
        error_log("GHL Create Tag - cURL Error: " . $curl_error);
    }
    
    // Tag creation might return 409 if tag already exists, which is OK
    if ($http_code === 200 || $http_code === 201 || $http_code === 409) {
        $result = json_decode($response, true);
        return [
            'success' => true,
            'tag' => $result
        ];
    }
    
    return [
        'success' => false,
        'error' => $response,
        'http_code' => $http_code
    ];
}



function syncContactToGHL($email, $role) {
    // Map user roles to simplified tag names
    $roleTagMap = [
        'Business owner' => 'cost savings pro tool business owner',
        'Financial professional (book keeper, CPA, fractional CFO, accountant, etc)' => 'cost savings pro tool financial professional',
        'Aspiring business owner.' => 'cost savings pro tool aspiring business owner',
        'Employee of a small/medium-size business.' => 'cost savings pro tool employee of smb',
        'Other' => 'cost savings pro tool other'
    ];
    
    // Get the tag name for this role, default to 'cost savings pro tool other' if not found
    $roleTagName = $roleTagMap[$role] ?? 'cost savings pro tool other';
    
    // Tags to apply: role-specific tag + general registration tag
    $tags = [$roleTagName, 'cost savings pro tool registered'];
    
    // Create all tags first
    foreach ($tags as $tagName) {
        $tagResult = createGHLTag($tagName);
        if (!$tagResult['success']) {
            error_log("GHL Tag creation failed for: " . $tagName);
        }
    }
    
    // Create contact with tags
    $contactResult = createGHLContact($email, '', '', '', $tags);
    
    if ($contactResult['success']) {
        error_log("GHL Contact created/updated successfully for: " . $email . " with tags: " . implode(', ', $tags));
        return true;
    } else {
        error_log("GHL Contact creation failed for: " . $email . " - " . print_r($contactResult, true));
        return false;
    }
}

function getUserRoleFromFile($email) {
    $cache_dir = __DIR__ . '/../cache';
    $file = $cache_dir . '/resp_' . md5($email) . '.json';

    if (!file_exists($file)) {
        return null;
    }

    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || empty($data['user_role'])) {
        return null;
    }

    return $data['user_role'];
}

















try {
    getDBConnection();
} catch (Exception $e) {
    // Schema migration runs on successful connection
}

$current_view = 'login';
$is_logged_in = !empty($_SESSION['user_id']) || !empty($_SESSION['user_email']);
if ($is_logged_in) {
    if (!empty($_SESSION['project_onboarding_required']) && \CostSavings\OrgRole::isSuperAdmin((string) ($_SESSION['role'] ?? ''))) {
        unset($_SESSION['awaiting_role']);
        $current_view = 'placeholder';
    } else {
    if (empty($_SESSION['user_role'])) {
        $email = $_SESSION['user_email'] ?? '';
        if ($email !== '') {
            loadUserResponses($email);
        }
        if (empty($_SESSION['user_role'])) {
            $_SESSION['awaiting_role'] = true;
        }
    }

    if (!empty($_SESSION['awaiting_role'])) {
        $current_view = 'login';
    } else {
        $current_view = 'placeholder';
    }
    }
}

$is_admin = ($is_logged_in && \CostSavings\OrgRole::isPrivileged((string) ($_SESSION['role'] ?? '')));
$can_create_projects = ($is_logged_in && \CostSavings\OrgRole::isSuperAdmin((string) ($_SESSION['role'] ?? '')));
$invite_can_choose_org_role = $can_create_projects;

$deadline_reminders_org = true;
$deadline_reminders_user = true;
$notification_webhook_url = '';
if ($is_logged_in && !empty($_SESSION['org_id'])) {
    try {
        $pdoView = getDBConnection();
        $st = $pdoView->prepare('SELECT deadline_reminders_enabled, notification_webhook_url FROM organizations WHERE id = ?');
        $st->execute([(int) $_SESSION['org_id']]);
        $or = $st->fetch(PDO::FETCH_ASSOC);
        if ($or && isset($or['deadline_reminders_enabled'])) {
            $deadline_reminders_org = (bool) $or['deadline_reminders_enabled'];
        }
        if ($or && isset($or['notification_webhook_url'])) {
            $notification_webhook_url = trim((string) $or['notification_webhook_url']);
        }
        if (!empty($_SESSION['user_id'])) {
            $st2 = $pdoView->prepare('SELECT deadline_reminders_enabled FROM users WHERE id = ?');
            $st2->execute([(int) $_SESSION['user_id']]);
            $ur = $st2->fetch(PDO::FETCH_ASSOC);
            if ($ur && isset($ur['deadline_reminders_enabled'])) {
                $deadline_reminders_user = (bool) $ur['deadline_reminders_enabled'];
            }
        }
    } catch (Exception $e) {
        // ignore
    }
}

$team_members_rows = [];
$team_members_json = '[]';
$team_members_count = 0;
$team_members_max = 20;
if ($is_logged_in && $current_view === 'placeholder' && !empty($_SESSION['org_id'])) {
    try {
        $pdoTeam = getDBConnection();
        $stTeam = $pdoTeam->prepare('SELECT id, username, display_name, email, role, is_disabled FROM users WHERE org_id = ? ORDER BY username, email');
        $stTeam->execute([(int) $_SESSION['org_id']]);
        $team_members_rows = $stTeam->fetchAll(PDO::FETCH_ASSOC);
        $team_members_json = json_encode($team_members_rows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $team_members_count = count($team_members_rows);
        $team_members_max = getOrganizationMaxUsers($pdoTeam, (int) $_SESSION['org_id']);
    } catch (Exception $e) {
        $team_members_rows = [];
        $team_members_json = '[]';
        $team_members_count = 0;
        $team_members_max = 20;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savvy Saver</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-0Z8U4b0JvoQ9QP9N9Pn+a7piklQNoRxwGBUpzUgtjtY+2a9pYNHeT0ZWhhFodS0xsJD6ODwbF8vvZ57D7x6Grg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        /* Complete CSS Reset */
        *, *::before, *::after { 
            box-sizing: border-box; 
            margin: 0; 
            padding: 0; 
        }
        
        html {
            margin: 0;
            padding: 0;
            height: 100%;
        }
        
        :root {
            --color-primary: #0B58A3;
            --color-primary-hover: #0A4B8E;
            --color-secondary: #25A8E0;
            --color-accent: #6ECCDB;
            --color-bg: #F7FAFC;
            --color-surface: #FFFFFF;
            --color-text-primary: #1F2937;
            --color-text-secondary: #4B5563;
            --color-border: #DCE3EA;
            --color-success: #16A34A;
            --color-warning: #F59E0B;
            --color-error: #DC2626;
            --color-info: #0EA5E9;
        }

        body { 
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif; 
            background: linear-gradient(0deg, rgba(7,110,147,1) 0%, rgba(8,54,96,1) 100%);
            margin: 0;
            padding: 20px 20px;
            min-height: 100vh;
            line-height: 1.6;
            position: relative;
            color: var(--color-text-primary);
        }
        
        .container-wrapper {
            position: relative;
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
        }
        
        /* Container for placeholder/cost savings tool */
        .placeholder-container-wrapper {
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
        }
        
        .container {
            width: 100%;
            max-width: 100%;
            margin: 0 auto; 
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.97) 0%, rgba(247, 250, 252, 0.96) 100%);
            backdrop-filter: blur(12px);
            border-radius: 20px; 
            box-shadow: 0 24px 56px rgba(11, 88, 163, 0.12), 0 0 0 1px rgba(220, 227, 234, 0.92);
            padding: 0; 
            position: relative;
            overflow: visible;
            z-index: 8;
        }
        
        /* Wider container for placeholder/cost savings tool */
        .placeholder-container-wrapper .container {
            width: 100%;
            max-width: 100%;
        }

        .container.project-onboarding-hidden {
            display: none;
        }
        
        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        h1, h2 { 
            text-align: center; 
            color: var(--color-text-primary); 
            font-weight: 700;
            margin-bottom: 30px;
        }
        
        .subtitle {
            text-align: center;
            color: var(--color-text-secondary);
            font-size: 16px;
            margin: -15px 0 30px 0;
            line-height: 1.5;
            font-weight: 400;
        }
        
        h1 { 
            font-family: 'Cormorant Garamond', Georgia, 'Times New Roman', serif;
            font-size: 2.35em; 
            font-weight: 700;
            letter-spacing: 0.02em;
            background: linear-gradient(135deg, var(--color-primary-hover) 0%, var(--color-primary) 45%, var(--color-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Logo styling */
        .logo-container { 
            text-align: center; 
            margin-bottom: 30px; 
            padding: 20px 0; 
        }
        .login-logo { 
            max-width: 320px; 
            width: 100%; 
            height: auto; 
            margin: 0 auto; 
            display: block; 
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15)) contrast(1.1);
            transition: all 0.3s ease;
            border-radius: 8px;
        }
        .login-logo:hover {
            transform: scale(1.02);
            filter: drop-shadow(0 3px 6px rgba(0, 0, 0, 0.2)) contrast(1.15);
        }
        
        /* eBook promotion section */
        .ebook-promotion {
            margin-top: 40px;
            padding: 25px;
            background: linear-gradient(135deg, rgba(37, 168, 224, 0.08), rgba(110, 204, 219, 0.10));
            border: 1px solid rgba(37, 168, 224, 0.25);
            border-radius: 12px;
            text-align: center;
            font-size: 14px;
            color: var(--color-text-secondary);
            line-height: 1.6;
        }
        
        .ebook-promotion .ebook-title {
            font-weight: 600;
            color: var(--color-text-primary);
            font-style: italic;
        }
        
        .ebook-promotion .ebook-link {
            color: var(--color-primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .ebook-promotion .ebook-link:hover {
            color: var(--color-primary-hover);
            text-decoration: underline;
        }

        /* Placeholder / Cost Savings Pro Tool link styles */
        .cost-calculator-link {
            display: inline-block;
            padding: 15px 40px;
            background: var(--color-primary);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            transition: background 0.3s, transform 0.2s;
            box-shadow: 0 4px 6px rgba(11, 88, 163, 0.28);
        }

        .cost-calculator-link:hover {
            background: var(--color-primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(10, 75, 142, 0.35);
        }

        .cost-calculator-link:active {
            transform: translateY(0);
        }

        .placeholder-content {
            text-align: center;
            padding: 40px 20px;
            max-width: 600px;
            margin: 0 auto;
        }

        .placeholder-content p a {
            color: var(--color-primary);
            text-decoration: underline;
        }

        .placeholder-content p a:hover {
            color: var(--color-primary-hover);
        }

        /* Cost Savings Pro Tool grid */
        .cost-calculator-table-wrapper {
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            margin: 20px 0;
            width: 100%;
            max-width: 100%;
            position: relative;
        }
        
        .cost-calculator-table-wrapper::-webkit-scrollbar {
            height: 8px;
        }
        
        .cost-calculator-table-wrapper::-webkit-scrollbar-track {
            background: #edf2f7;
            border-radius: 4px;
        }
        
        .cost-calculator-table-wrapper::-webkit-scrollbar-thumb {
            background: var(--color-primary);
            border-radius: 4px;
        }
        
        .cost-calculator-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: var(--color-primary-hover);
        }

        .cost-calculator-grid {
            width: 100%;
            min-width: 1020px;
            border-collapse: collapse;
            margin: 0;
            background: var(--color-surface);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .cost-calculator-grid thead {
            background: var(--color-primary);
            color: white;
        }

        .cost-calculator-grid th {
            padding: 8px 6px;
            text-align: left;
            font-weight: 600;
            border: 1px solid var(--color-primary-hover);
            font-size: 13px;
            white-space: nowrap;
        }

        .cost-calculator-grid td {
            padding: 6px;
            border: 1px solid var(--color-border);
        }

        .cost-calculator-grid tbody tr:hover {
            background: #f3f8fc;
        }

        .cost-calculator-grid input[type="text"],
        .cost-calculator-grid input[type="number"],
        .cost-calculator-grid select,
        .cost-calculator-grid textarea {
            width: 100%;
            padding: 5px;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            font-size: 13px;
            box-sizing: border-box;
        }

        .cost-calculator-grid input[type="text"]:focus,
        .cost-calculator-grid input[type="number"]:focus,
        .cost-calculator-grid select:focus,
        .cost-calculator-grid textarea:focus {
            outline: none;
            border-color: var(--color-secondary);
            box-shadow: 0 0 0 2px rgba(37, 168, 224, 0.2);
        }

        .cost-calculator-grid .item-number {
            text-align: center;
            font-weight: 600;
            width: 52px;
        }

        .cost-calculator-grid .select-row,
        .cost-calculator-grid .select-row-cell {
            width: 38px;
            text-align: center;
        }

        .cost-calculator-grid .select-row input[type="checkbox"],
        .cost-calculator-grid .select-row-cell input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .cost-calculator-grid .vendor-name {
            min-width: 160px;
        }

        .cost-calculator-grid .vendor-cell-wrap {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .cost-calculator-grid .vendor-cell-wrap input[type="text"] {
            flex: 1 1 auto;
            min-width: 0;
        }

        .cost-calculator-grid .vendor-raw-btn {
            border: 1px solid #d1d5db;
            background: #fff;
            color: #4a3f6b;
            border-radius: 6px;
            width: 30px;
            height: 30px;
            padding: 0;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .cost-calculator-grid .vendor-raw-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .cost-calculator-grid .vendor-raw-icon {
            font-size: 18px;
            color: var(--color-primary-hover);
        }

        .cost-calculator-grid .vendor-chat-col {
            width: 64px;
            min-width: 64px;
            text-align: center;
        }

        .cost-calculator-grid th.vendor-chat-col.th-with-filter {
            min-width: 108px;
            width: auto;
        }

        .cost-calculator-grid th.vendor-chat-col.th-with-filter .th-with-filter-inner {
            justify-content: center;
            gap: 6px;
        }

        .cost-calculator-grid .vendor-chat-btn {
            position: relative;
            width: 34px;
            height: 34px;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            background: linear-gradient(135deg, #ffffff 0%, #f3f4f6 100%);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .cost-calculator-grid .vendor-chat-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            border-color: #8b78be;
            box-shadow: 0 6px 16px rgba(75, 63, 107, 0.22);
        }

        .cost-calculator-grid .vendor-chat-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            box-shadow: none;
        }

        .cost-calculator-grid .material-symbols-outlined {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 20;
            line-height: 1;
            user-select: none;
        }

        .cost-calculator-grid .vendor-chat-icon {
            font-size: 18px;
            color: var(--color-primary);
        }

        .cost-calculator-grid .vendor-chat-unread-badge {
            position: absolute;
            top: 0;
            right: 0;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: #e11d48;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.95);
            pointer-events: none;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.15s ease;
        }

        .cost-calculator-grid .vendor-chat-unread-badge.is-visible {
            opacity: 1;
            visibility: visible;
        }

        .vendor-raw-results {
            overflow-x: auto;
        }

        .vendor-raw-results table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .vendor-raw-results th,
        .vendor-raw-results td {
            border-bottom: 1px solid #e5e7eb;
            padding: 8px 10px;
            text-align: left;
            vertical-align: top;
        }

        .vendor-raw-results th {
            background: #f8fafc;
            position: sticky;
            top: 0;
        }

        .vendor-raw-results th.amount-col,
        .vendor-raw-results td.amount-col {
            text-align: right;
        }

        .cost-calculator-grid .cost-per-period {
            min-width: 100px;
        }

        .cost-calculator-grid .cost-input {
            text-align: right;
        }

        .cost-calculator-grid .frequency {
            min-width: 95px;
        }

        .cost-calculator-grid .annual-cost {
            min-width: 100px;
            text-align: right;
            font-weight: 600;
        }

        .cost-calculator-grid .annual-cost-display {
            font-size: 13px;
        }

        .cost-calculator-grid .manager-col {
            min-width: 90px;
        }

        .cost-calculator-grid .visibility-col {
            min-width: 90px;
        }

        .cost-calculator-grid .row-status {
            min-width: 150px;
        }

        .cost-calculator-grid th.th-with-filter {
            position: relative;
            vertical-align: middle;
        }

        .th-with-filter-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            min-width: 0;
        }

        .vendor-col-filter-btn {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            padding: 0;
            margin: 0;
            border: 1px solid var(--color-border, #d7dce6);
            border-radius: 6px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            cursor: pointer;
            color: var(--color-primary, #0b58a3);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .vendor-col-filter-btn .material-symbols-outlined {
            font-size: 17px;
            line-height: 1;
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }

        .vendor-col-filter-btn:hover {
            border-color: #93c5fd;
            box-shadow: 0 2px 6px rgba(11, 88, 163, 0.12);
        }

        .vendor-col-filter-btn.is-active {
            background: rgba(11, 88, 163, 0.1);
            border-color: var(--color-primary, #0b58a3);
        }

        /* Header sits on primary-blue thead: use light button + white icon; active state = white pill + blue icon (avoids blue-on-blue). */
        .cost-calculator-grid thead .vendor-col-filter-btn {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.5);
            color: #ffffff;
        }

        .cost-calculator-grid thead .vendor-col-filter-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.75);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        }

        .cost-calculator-grid thead .vendor-col-filter-btn.is-active {
            background: #ffffff;
            border-color: #ffffff;
            color: var(--color-primary, #0b58a3);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
        }

        .vendor-col-filter-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            left: auto;
            min-width: 200px;
            max-width: min(280px, 92vw);
            max-height: 260px;
            overflow: auto;
            z-index: 40;
            margin: 0;
            padding: 8px;
            border: 1px solid var(--color-border, #d7dce6);
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 12px 24px rgba(11, 88, 163, 0.14);
            text-align: left;
            font-weight: normal;
            box-sizing: border-box;
            /* thead has color:white; reset so controls and labels stay readable on white panel */
            color: #1e293b;
        }

        .vendor-col-filter-option {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 4px 2px;
            font-size: 12px;
            color: var(--color-text-primary, #161c2d);
            cursor: pointer;
            border-radius: 4px;
        }

        .vendor-col-filter-option:hover {
            background: #f8fafc;
        }

        .vendor-col-filter-option input {
            margin-top: 2px;
            flex-shrink: 0;
        }

        .vendor-col-filter-actions {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px solid #e9edf4;
            display: flex;
            justify-content: flex-end;
        }

        .vendor-col-filter-clear {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
            border: 1px solid #dee3ec;
            background: #fff;
            color: #1e293b;
            cursor: pointer;
            font-weight: 500;
        }

        .vendor-col-filter-clear:hover {
            background: #f8fafc;
            color: #0f172a;
        }

        .cost-calculator-grid .row-status .row-status-top {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .cost-calculator-grid .row-status .row-status-select {
            width: 100%;
            flex: 1 1 auto;
            min-width: 0;
        }

        .cost-calculator-grid .cancel-guidance-btn {
            width: 28px;
            height: 28px;
            border: 1px solid #d7dce6;
            border-radius: 999px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
            flex: 0 0 auto;
        }

        .cost-calculator-grid .cancel-guidance-btn:hover {
            transform: translateY(-1px);
            border-color: #f59e0b;
            box-shadow: 0 6px 12px rgba(245, 158, 11, 0.22);
        }

        .cost-calculator-grid .cancel-guidance-btn[hidden] {
            display: none;
        }

        .cost-calculator-grid .cancel-guidance-icon {
            font-size: 16px;
            color: #f59e0b;
        }

        .cost-calculator-grid .row-status .cancel-deadline-input {
            display: block;
            width: 100%;
            margin-top: 4px;
            box-sizing: border-box;
        }

        .cost-calculator-grid .row-status .cancel-deadline-input[hidden] {
            display: none;
        }

        /* Filter dropdown styles */
        .report-filters {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .report-filters label {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
            margin: 0;
        }

        .report-filters select {
            padding: 10px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            color: #374151;
            cursor: pointer;
            min-width: 200px;
            transition: all 0.3s ease;
        }

        .report-filters select:focus {
            outline: none;
            border-color: #6b5b95;
            box-shadow: 0 0 0 3px rgba(107, 91, 149, 0.1);
        }

        .report-filters select:hover {
            border-color: #6b5b95;
        }

        .report-filters .column-toggle-btn {
            margin-left: auto;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: normal;
            line-height: 1.2;
            text-align: center;
        }

        .report-filters .column-toggle-btn:hover {
            border-color: #6b5b95;
            color: #4a3f6b;
        }

        .report-filters .bulk-action-btn {
            padding: 8px 12px;
            font-size: 13px;
            border-radius: 8px;
        }

        .report-filters-top {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            width: 100%;
        }

        .status-key-details {
            width: 100%;
            margin: 4px 0 0;
            padding: 0;
            border: none;
            font-size: 13px;
            color: #374151;
        }

        .status-key-summary {
            cursor: pointer;
            font-weight: 600;
            color: #4a3f6b;
            list-style: none;
            padding: 6px 0;
            user-select: none;
        }

        .status-key-summary::-webkit-details-marker {
            display: none;
        }

        .status-key-summary::before {
            content: '';
            display: inline-block;
            width: 0;
            height: 0;
            margin-right: 6px;
            border-style: solid;
            border-width: 5px 0 5px 6px;
            border-color: transparent transparent transparent #6b5b95;
            vertical-align: middle;
            transform: rotate(0deg);
            transition: transform 0.15s ease;
        }

        .status-key-details[open] .status-key-summary::before {
            transform: rotate(90deg);
        }

        .status-key-summary:hover {
            color: #6b5b95;
        }

        .status-key-summary:focus {
            outline: none;
        }

        .status-key-summary:focus-visible {
            outline: 2px solid #6b5b95;
            outline-offset: 3px;
            border-radius: 4px;
        }

        .status-key-body {
            margin-top: 10px;
            padding: 12px 14px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
        }

        .status-key-body dl {
            margin: 0;
            display: grid;
            grid-template-columns: minmax(9rem, 12rem) 1fr;
            gap: 6px 16px;
            align-items: start;
        }

        .status-key-body dt {
            margin: 0;
            font-weight: 600;
            color: #1f2937;
        }

        .status-key-body dd {
            margin: 0;
            color: #4b5563;
            line-height: 1.45;
        }

        @media (max-width: 640px) {
            .status-key-body dl {
                grid-template-columns: 1fr;
            }

            .status-key-body dt {
                margin-top: 6px;
            }

            .status-key-body dt:first-child {
                margin-top: 0;
            }
        }

        .cost-calculator-grid.notes-collapsed {
            min-width: 880px;
        }

        .cost-calculator-grid.notes-collapsed .notes {
            display: none;
        }

        .cost-calculator-grid .notes {
            min-width: 140px;
        }

        .cost-calculator-actions {
            margin: 20px 0;
            text-align: center;
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .vendor-pagination {
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        .vendor-pagination[hidden] {
            display: none;
        }

        .vendor-pagination-btn {
            width: 30px;
            height: 30px;
            border: 1px solid #d1d5db;
            background: #fff;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
            margin: 0;
        }

        .vendor-pagination-btn .material-symbols-outlined {
            font-size: 18px;
            color: var(--color-primary-hover);
            font-variation-settings: 'FILL' 0, 'wght' 500, 'GRAD' 0, 'opsz' 20;
            line-height: 1;
        }

        .vendor-pagination-btn:hover:not(:disabled) {
            border-color: var(--color-secondary);
            box-shadow: 0 6px 12px rgba(11, 88, 163, 0.16);
            transform: translateY(-1px);
        }

        .vendor-pagination-btn:hover:not(:disabled) .material-symbols-outlined {
            color: var(--color-primary);
        }

        .vendor-pagination-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .vendor-pagination-status {
            font-size: 12px;
            color: var(--color-text-secondary);
            min-width: 120px;
            text-align: center;
        }

        .add-row-btn {
            background: #6b5b95;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }

        .add-row-btn:hover {
            background: #4a3f6b;
        }

        .bulk-action-btn {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover));
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(11, 88, 163, 0.22);
        }

        .bulk-action-btn:hover {
            background: var(--color-primary-hover);
            box-shadow: 0 8px 20px rgba(10, 75, 142, 0.28);
        }

        .bulk-actions-form {
            display: grid;
            gap: 12px;
        }

        .bulk-actions-form label {
            margin: 0;
            font-size: 14px;
            color: #374151;
            font-weight: 600;
        }

        .bulk-actions-form .bulk-action-controls {
            display: grid;
            gap: 10px;
        }

        .bulk-actions-form .bulk-actions-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .bulk-actions-buttons .btn-secondary {
            font-size: 13px;
            padding: 8px 12px;
        }

        .project-wizard-cancel-btn {
            font-size: 12px;
            padding: 6px 10px;
        }

        .bulk-actions-form .bulk-confirm-summary {
            background: #f8f9fa;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            color: #374151;
            line-height: 1.45;
        }

        .savings-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 30px;
        }

        .savings-section {
            padding: 20px;
            background: #f8f9fa;
            border: 2px solid #6b5b95;
            border-radius: 8px;
            text-align: center;
        }

        .confirmed-savings-section {
            border-color: #10b981;
        }

        .savings-section h3 {
            color: #424242;
            margin-bottom: 10px;
        }

        .savings-amount {
            font-size: 32px;
            font-weight: 700;
            color: #6b5b95;
        }

        .confirmed-savings-amount {
            color: #10b981;
        }

        @media (max-width: 768px) {
            .savings-summary {
                grid-template-columns: 1fr;
            }
        }

        .logo-above-container {
            text-align: center;
            padding: 10px 0;
        }
        
        .logo-above-container .login-logo {
            max-width: 160px;
        }

        .logo-tagline {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.03em;
            color: #ffffff;
        }

        /* Responsive styles for cost savings tool table */
        @media screen and (max-width: 768px) {
            .cost-calculator-table-wrapper {
                margin: 20px -10px;
            }

            .cost-calculator-grid {
                font-size: 12px;
                min-width: 920px;
            }

            .cost-calculator-grid th,
            .cost-calculator-grid td {
                padding: 6px 4px;
                font-size: 11px;
            }

            .cost-calculator-grid th {
                font-size: 12px;
                white-space: nowrap;
            }

            .cost-calculator-grid input[type="text"],
            .cost-calculator-grid input[type="number"],
            .cost-calculator-grid select,
            .cost-calculator-grid textarea {
                padding: 4px;
                font-size: 11px;
            }

            .cost-calculator-grid .item-number {
                width: 40px;
            }

            .cost-calculator-grid .select-row,
            .cost-calculator-grid .select-row-cell {
                width: 34px;
            }

            .cost-calculator-grid .vendor-name {
                min-width: 140px;
            }

            .cost-calculator-grid .cost-per-period {
                min-width: 85px;
            }

            .cost-calculator-grid .frequency {
                min-width: 90px;
            }

            .cost-calculator-grid .annual-cost {
                min-width: 85px;
                font-size: 11px;
            }

            .cost-calculator-grid .annual-cost-display {
                font-size: 11px;
            }

            .cost-calculator-grid .manager-col,
            .cost-calculator-grid .visibility-col {
                min-width: 85px;
            }

            .cost-calculator-grid .row-status {
                min-width: 130px;
            }

            .report-filters {
                flex-direction: column;
                align-items: flex-start;
            }

            .report-filters select {
                min-width: 100%;
            }

            .report-filters .column-toggle-btn {
                margin-left: 0;
                width: 100%;
                text-align: center;
            }

            .cost-calculator-grid .notes {
                min-width: 120px;
            }

            .cost-calculator-grid .notes textarea {
                rows: 1;
                min-height: 30px;
            }

            .cost-calculator-actions {
                margin: 15px 0;
            }

            .add-row-btn {
                padding: 8px 16px;
                font-size: 14px;
            }

            .bulk-action-btn {
                padding: 8px 16px;
                font-size: 14px;
            }

            .savings-summary {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .savings-section {
                margin-top: 20px;
                padding: 15px;
            }

            .savings-section h3 {
                font-size: 18px;
            }

            .savings-amount {
                font-size: 24px;
            }
        }

        @media screen and (max-width: 480px) {
            .cost-calculator-table-wrapper {
                margin: 20px -15px;
            }

            .cost-calculator-grid {
                font-size: 11px;
            }

            .cost-calculator-grid th,
            .cost-calculator-grid td {
                padding: 5px 3px;
                font-size: 10px;
            }

            .cost-calculator-grid th {
                font-size: 11px;
            }

            .cost-calculator-grid input[type="text"],
            .cost-calculator-grid input[type="number"],
            .cost-calculator-grid select,
            .cost-calculator-grid textarea {
                padding: 3px;
                font-size: 10px;
            }

            .savings-amount {
                font-size: 20px;
            }
        }
        
        /* Enhanced Score Page Styling */
        .score-container {
            max-width: 1100px;
            margin: 0 auto;
        }
        
        .score-summary-section {
            margin-bottom: 30px;
        }
        
        .score-display-card {
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(107, 91, 149, 0.3);
            margin-bottom: 20px;
        }
        
        .score-display-card h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
            font-weight: 600;
            color: white;
        }
        
        .score-display {
            font-size: 72px;
            font-weight: bold;
            margin: 10px 0;
            color: white;
        }
        
        .score-display strong {
            color: white;
        }
        
        .score-description {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        
        .executive-summary-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
            border: 1px solid #e2e8f0;
        }
        
        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .summary-header h3 {
            margin: 0;
            color: #2d3748;
            font-size: 20px;
            font-weight: 600;
        }
        
        .refresh-btn {
            background: #e2e8f0;
            color: #4a5568;
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: #cbd5e0;
            transform: translateY(-1px);
        }
        
        .summary-content {
            min-height: 300px;
        }
        
        .summary-text {
            font-size: 15px;
            line-height: 1.8;
            color: #2d3748;
            white-space: pre-wrap;
        }
        
        .summary-placeholder {
            text-align: center;
            padding: 50px 20px;
            color: #718096;
            background: #f7fafc;
            border-radius: 10px;
            border: 2px dashed #cbd5e0;
        }
        
        .summary-placeholder p {
            margin-bottom: 15px;
            font-size: 15px;
            line-height: 1.6;
        }
        
        .generate-summary-btn {
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .generate-summary-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(107, 91, 149, 0.3);
        }
        
        .score-actions-section {
            text-align: center;
            padding: 25px;
            background: #f8fafc;
            border-radius: 15px;
            border: 1px solid #e2e8f0;
            margin-top: 20px;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .primary-btn {
            background: #6b5b95;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .primary-btn:hover {
            background: #5b4d8f;
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(91, 77, 143, 0.28);
        }
        
        .secondary-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .secondary-btn:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        #summary-loading {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            border-top-color: #6b5b95;
            animation: spin 1s ease-in-out infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Score Container Styling */
        .score-container {
            min-height: 680px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        
        /* Debug: Temporary visible background */
        .question-nav {
            /* background: rgba(255, 0, 0, 0.1); */
            /* padding: 10px; */
        }
        
        .form-group { 
            margin-bottom: 25px; 
            position: relative;
        }
        
        label { 
            display: block; 
            margin-bottom: 8px; 
            color: #374151; 
            font-weight: 600; 
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .checkbox-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 0;
            font-weight: 400;
            cursor: pointer;
            text-transform: none;
            letter-spacing: normal;
        }

        .checkbox-label input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            flex-shrink: 0;
            accent-color: var(--color-primary);
            cursor: pointer;
        }

        .checkbox-label span {
            line-height: 1.5;
            color: #374151;
            font-size: 14px;
        }

        .checkbox-label a {
            color: var(--color-primary);
            text-decoration: underline;
        }

        .checkbox-label a:hover {
            color: var(--color-primary-hover);
        }
        
        input[type="email"], input[type="text"], input[type="password"], select { 
            width: 100%; 
            padding: 16px 20px; 
            border: 2px solid var(--color-border); 
            border-radius: 12px; 
            font-size: 16px; 
            line-height: 1.4;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(10px);
            font-family: inherit;
            -webkit-appearance: none;
            appearance: none;
            box-sizing: border-box;
        }

        input[type="password"] {
            letter-spacing: 0.02em;
        }
        
        input[type="email"]:focus, input[type="text"]:focus, input[type="password"]:focus, select:focus { 
            outline: none;
            border-color: var(--color-secondary);
            box-shadow: 0 0 0 3px rgba(37, 168, 224, 0.14);
            transform: translateY(-2px);
            background: #fff;
        }
        
        button { 
            padding: 16px 32px; 
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover)); 
            color: #fff; 
            border: none; 
            border-radius: 12px; 
            font-size: 16px; 
            font-weight: 600;
            cursor: pointer; 
            margin: 8px; 
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        button:hover::before {
            left: 100%;
        }
        
        button:hover { 
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(11, 88, 163, 0.3);
        }
        
        .btn-secondary { 
            background: linear-gradient(135deg, #6b7280, #4b5563); 
        }
        
        .org-role-info-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            padding: 0;
            border: 1px solid #d1d5db;
            border-radius: 50%;
            background: #f9fafb;
            color: #4b5563;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
        }
        .org-role-info-btn:hover,
        .org-role-info-btn:focus-visible {
            background: #eef2ff;
            border-color: #a5b4fc;
            color: #3730a3;
        }
        .btn-secondary:hover { 
            box-shadow: 0 8px 25px rgba(107, 114, 128, 0.3);
        }

        .btn-secondary:disabled,
        #aiSubmitBtn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
        }
        
        .button-group { 
            display: flex; 
            gap: 15px; 
            flex-wrap: wrap; 
            justify-content: center; 
            margin-top: 25px; 
        }
        
        .form-group small { 
            display: block; 
            margin-top: 8px; 
            color: #6b7280; 
            font-size: 13px;
            font-weight: 500;
        }

        .role-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .role-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .role-option:hover {
            border-color: #6b5b95;
            box-shadow: 0 6px 18px rgba(107, 91, 149, 0.15);
        }

        .role-option input[type="radio"] {
            accent-color: #6b5b95;
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .role-option span {
            font-size: 15px;
            color: #1f2937;
            font-weight: 500;
        }
        
        button.secondary { 
            background: linear-gradient(135deg, #6b7280, #4b5563); 
        }
        
        .message { 
            padding: 16px 20px; 
            margin: 20px 0; 
            border-radius: 12px; 
            position: relative; 
            z-index: 10; 
            clear: both;
            border: none;
            backdrop-filter: blur(10px);
        }
        
        .error { 
            background: linear-gradient(135deg, #fee2e2, #fecaca); 
            color: #991b1b; 
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.18);
        }
        
        .success { 
            background: linear-gradient(135deg, #dcfce7, #bbf7d0); 
            color: #14532d; 
            box-shadow: 0 4px 15px rgba(22, 163, 74, 0.2);
        }
        
        .navigation { 
            display: flex; 
            justify-content: flex-end; 
            margin-top: 30px; 
            gap: 15px;
        }
        
        .logout-form {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
            margin: 0;
        }
        
        .logout-button {
            padding: 10px 16px;
            border-radius: 8px;
            border: 2px solid rgba(255, 255, 255, 0.85);
            background: transparent;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: none;
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .logout-button:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.12);
            border-color: #ffffff;
        }
        
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .logout-button i {
            font-size: 16px;
            color: inherit;
        }

        .logout-button span {
            color: inherit;
            font-size: inherit;
        }
        
        .question-text { 
            font-size: 20px; 
            margin-bottom: 25px; 
            color: #1f2937; 
            font-weight: 600;
            line-height: 1.5;
        }
        
        .progress { 
            background: rgba(229, 231, 235, 0.6); 
            height: 8px; 
            border-radius: 8px; 
            margin-bottom: 25px; 
            overflow: hidden;
            position: relative;
        }
        
        .progress-bar { 
            background: linear-gradient(90deg, var(--color-secondary), var(--color-primary)); 
            height: 100%; 
            border-radius: 8px; 
            transition: width 0.6s ease;
            position: relative;
        }
        
        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: progressShimmer 2s infinite;
        }
        
        @keyframes progressShimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        /* Section styling */
        .section-header { 
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-hover) 100%); 
            color: white; 
            padding: 20px 25px; 
            margin: 0; 
            border-radius: 20px 20px 0 0; 
            text-align: center; 
            font-size: 22px; 
            font-weight: 700;
            letter-spacing: 0.5px;
            position: relative;
            box-shadow: 0 4px 15px rgba(11, 88, 163, 0.3);
        }
        
        .section-header::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 15px solid transparent;
            border-right: 15px solid transparent;
            border-top: 10px solid var(--color-primary-hover);
        }

        /* Content padding for areas that need it */
        .content-padding {
            padding: 30px;
            position: relative;
            z-index: 10;
        }
        
        /* Restore padding on login page only */
        .content-padding.login-page {
            padding: 40px;
        }
        
        .content-padding.no-top {
            padding-top: 0;
        }

        /* Popup link styling */
        .popup-link {
            color: #6b5b95;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            margin: 15px 0;
            display: inline-block;
            padding: 12px 20px;
            background: rgba(107, 91, 149, 0.1);
            border-radius: 8px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .popup-link:hover {
            color: #4f46e5;
            background: rgba(107, 91, 149, 0.2);
            border-color: rgba(107, 91, 149, 0.3);
            transform: translateY(-2px);
        }

        /* Hide action steps link - targets spans with onclick containing 'actions-' */
        span.popup-link[onclick*="'actions-"] {
            display: none !important;
        }

        /* Modal overlay */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            z-index: 1000;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
        }

        /* Modal content */
        .modal-content {
            position: relative;
            background: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 12px;
            max-width: 700px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
            color: white;
            padding: 20px;
            margin: -30px -30px 20px -30px;
            border-radius: 12px 12px 0 0;
            text-align: center;
            font-size: 22px;
            font-weight: 700;
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 15px;
            right: 20px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 8px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            line-height: 1.6;
            color: #424242;
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        /* Custom scrollbar for modal body */
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a6fd8, #6a4190);
        }

        .performance-tier {
            margin-bottom: 30px;
            padding: 25px;
            border-left: 4px solid transparent;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .performance-tier::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
        }
        
        .performance-tier:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 25px rgba(107, 91, 149, 0.15);
        }

        .tier-title {
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 12px;
            font-size: 17px;
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .tier-description {
            line-height: 1.7;
            color: #4b5563;
            font-size: 15px;
        }

        .action-item {
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #f5f0ff, #ede9fe);
            border-radius: 12px;
            border-left: 4px solid #6b5b95;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .action-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(107, 91, 149, 0.2);
        }

        .action-item.pro-tip {
            background: linear-gradient(135deg, #fefce8, #fef3c7);
            border-left-color: #f59e0b;
        }
        
        .action-item.pro-tip:hover {
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.2);
        }

        .action-title {
            font-weight: bold;
            color: #424242;
            margin-bottom: 5px;
        }

        /* AI Guidance Popup Styles */
        .ai-guidance-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            overflow-y: auto;
        }

        .ai-guidance-popup {
            position: relative;
            background: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 12px;
            max-width: 700px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .ai-guidance-header {
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
            color: white;
            padding: 20px;
            margin: -30px -30px 20px -30px;
            border-radius: 12px 12px 0 0;
            text-align: center;
        }

        .ai-guidance-header h3 {
            margin: 0;
            font-size: 22px;
        }

        .ai-guidance-content {
            line-height: 1.6;
            color: #424242;
            max-height: 500px;
            overflow-y: auto;
            padding-right: 10px;
        }

        /* Chat Interface Styles */
        .chat-container {
            max-height: 350px;
            overflow-y: auto;
            margin-bottom: 20px;
            padding: 10px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .chat-message {
            margin-bottom: 15px;
            display: flex;
            align-items: flex-start;
        }

        .chat-message.ai-message {
            justify-content: flex-start;
        }

        .chat-message.user-message {
            justify-content: flex-end;
        }

        .chat-bubble {
            max-width: 80%;
            padding: 12px 16px;
            border-radius: 18px;
            word-wrap: break-word;
            white-space: pre-wrap;
        }

        .chat-bubble.ai-bubble {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            color: #1565c0;
            border-bottom-left-radius: 6px;
        }

        .chat-bubble.user-bubble {
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
            color: white;
            border-bottom-right-radius: 6px;
        }

        .chat-timestamp {
            font-size: 11px;
            opacity: 0.7;
            margin-top: 5px;
        }

        .chat-input-container {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }

        .chat-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 20px;
            outline: none;
            resize: none;
            max-height: 100px;
            font-family: inherit;
        }

        .chat-input:focus {
            border-color: #6b5b95;
            box-shadow: 0 0 0 2px rgba(91, 77, 143, 0.2);
        }

        .chat-send-button {
            background: #6b5b95;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.2s;
        }

        .chat-send-button:hover:not(:disabled) {
            background: #4a3f6b;
        }

        .chat-send-button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .vendor-chat-shell {
            display: grid;
            gap: 12px;
            min-height: 420px;
        }

        .vendor-chat-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: linear-gradient(135deg, #f5f3ff 0%, #eef2ff 100%);
            color: #4a3f6b;
            font-size: 13px;
            font-weight: 600;
        }

        .vendor-chat-meta-badge {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #6b5b95;
            position: relative;
            flex: 0 0 24px;
        }

        .vendor-chat-meta-badge::before {
            content: '';
            position: absolute;
            top: 7px;
            left: 5px;
            width: 12px;
            height: 8px;
            border: 2px solid #fff;
            border-radius: 6px;
            box-sizing: border-box;
        }

        .vendor-chat-meta-badge::after {
            content: '';
            position: absolute;
            top: 14px;
            left: 8px;
            width: 4px;
            height: 4px;
            border-left: 2px solid #fff;
            border-bottom: 2px solid #fff;
            transform: skewX(-20deg);
        }

        .vendor-chat-log {
            min-height: 250px;
            max-height: 390px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: radial-gradient(circle at top right, #f7f5ff 0%, #f8fafc 52%, #f1f5f9 100%);
            padding: 12px;
        }

        .vendor-chat-row {
            display: flex;
            margin-bottom: 12px;
        }

        .vendor-chat-row.is-self {
            justify-content: flex-end;
        }

        .vendor-chat-row.is-other {
            justify-content: flex-start;
        }

        .vendor-chat-bubble {
            max-width: min(78%, 560px);
            border-radius: 14px;
            padding: 10px 12px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            white-space: pre-wrap;
            word-break: break-word;
        }

        .vendor-chat-row.is-self .vendor-chat-bubble {
            background: linear-gradient(135deg, #6b5b95 0%, #4a3f6b 100%);
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .vendor-chat-row.is-other .vendor-chat-bubble {
            background: #fff;
            color: #1f2937;
            border: 1px solid #e5e7eb;
            border-bottom-left-radius: 4px;
        }

        .vendor-chat-author {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .vendor-chat-time {
            font-size: 11px;
            margin-top: 6px;
            opacity: 0.78;
        }

        .vendor-chat-empty {
            min-height: 226px;
            border: 1px dashed #c4b5fd;
            border-radius: 12px;
            background: #faf5ff;
            display: grid;
            place-items: center;
            text-align: center;
            color: #5b4e7d;
            font-size: 14px;
            padding: 20px;
            line-height: 1.45;
        }

        .vendor-chat-composer {
            display: grid;
            gap: 8px;
        }

        .vendor-chat-input {
            width: 100%;
            min-height: 76px;
            max-height: 160px;
            resize: vertical;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .vendor-chat-input:focus {
            outline: none;
            border-color: #6b5b95;
            box-shadow: 0 0 0 2px rgba(107, 91, 149, 0.18);
        }

        .vendor-chat-composer-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .vendor-chat-hint {
            font-size: 12px;
            color: #64748b;
        }

        .vendor-chat-send-btn {
            min-width: 120px;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, #6b5b95 0%, #4a3f6b 100%);
            color: #fff;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .vendor-chat-send-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .vendor-cancel-ai-shell {
            display: grid;
            gap: 12px;
            min-height: 260px;
        }

        .vendor-cancel-ai-context {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 10px 12px;
            background: linear-gradient(135deg, #fffbeb 0%, #f8fafc 100%);
            color: #78350f;
            font-size: 13px;
            font-weight: 600;
        }

        .vendor-cancel-ai-content {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            min-height: 180px;
            max-height: 420px;
            overflow-y: auto;
            padding: 12px;
            line-height: 1.55;
            color: #1f2937;
        }

        .vendor-cancel-ai-content p {
            margin: 0 0 10px 0;
        }

        .vendor-cancel-ai-content p:last-child {
            margin-bottom: 0;
        }

        .vendor-cancel-ai-content ul,
        .vendor-cancel-ai-content ol {
            margin: 0 0 10px 20px;
            padding: 0;
        }

        .vendor-cancel-ai-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .question-limit-notice {
            font-size: 12px;
            color: #666;
            text-align: center;
            margin-top: 10px;
            font-style: italic;
        }

        .question-limit-reached {
            color: #dc3545;
            font-weight: 500;
        }

        /* Custom scrollbar for AI guidance content */
        .ai-guidance-content::-webkit-scrollbar {
            width: 8px;
        }

        .ai-guidance-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .ai-guidance-content::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #6b5b95, #4a3f6b);
            border-radius: 4px;
        }

        .ai-guidance-content::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a6fd8, #6a4190);
        }

        .ai-guidance-content h4 {
            color: #6b5b95;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        .ai-guidance-content ul {
            padding-left: 20px;
        }

        .ai-guidance-content li {
            margin-bottom: 8px;
        }

        .ai-loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .ai-loading-spinner {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #6b5b95;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .ai-guidance-actions {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--color-border);
        }

        .ai-guidance-button {
            background: var(--color-primary);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            margin: 0 10px;
        }

        .ai-guidance-button:hover {
            background: var(--color-primary-hover);
        }

        .app-nav {
            width: 100%;
            margin: 0 0 18px 0;
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.97) 0%, rgba(247, 250, 252, 0.96) 100%);
            box-shadow: 0 6px 18px rgba(11, 88, 163, 0.08);
            display: flex;
            justify-content: center;
            padding: 4px 10px;
        }

        .app-nav-shell {
            width: 100%;
            max-width: 100%;
            margin: 0 auto 14px auto;
        }

        .app-nav-shell .app-nav {
            margin-bottom: 0;
        }

        .app-nav-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            max-width: 780px;
        }

        .app-nav-item {
            position: relative;
        }

        .app-nav-inline-form {
            margin: 0;
        }

        .app-nav-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--color-text-primary);
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background .15s ease, border-color .15s ease, color .15s ease;
        }

        .app-nav-link:hover,
        .app-nav-link:focus-visible,
        .app-nav-item.is-open > .app-nav-link {
            background: #eaf5fd;
            border-color: var(--color-secondary);
            color: var(--color-primary-hover);
            outline: none;
        }

        .app-nav-item.has-submenu .app-nav-link::after {
            content: '▾';
            margin-left: 8px;
            font-size: 11px;
            opacity: 0.8;
        }

        .app-submenu {
            position: absolute;
            top: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            min-width: 220px;
            list-style: none;
            margin: 0;
            padding: 8px;
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 12px 24px rgba(11, 88, 163, 0.14);
            z-index: 25;
            display: none;
        }

        .app-nav-item.is-open > .app-submenu {
            display: block;
        }

        .app-submenu-item {
            display: block;
            width: 100%;
            box-sizing: border-box;
            margin: 0;
            padding: 9px 10px;
            border: 0;
            border-radius: 7px;
            background: transparent;
            color: var(--color-text-primary);
            text-decoration: none;
            text-align: left;
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }

        .app-submenu-item:hover,
        .app-submenu-item:focus-visible {
            background: #edf7fd;
            color: var(--color-primary-hover);
            outline: none;
        }

        .app-submenu-label {
            display: block;
            margin: 0 0 6px 0;
            font-size: 12px;
            font-weight: 600;
            color: var(--color-text-primary);
        }

        .app-submenu-select {
            appearance: none;
            -webkit-appearance: none;
            width: 100%;
            min-height: 34px;
            padding: 6px 30px 6px 10px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            background-color: #fff;
            background-image:
                linear-gradient(45deg, transparent 50%, var(--color-primary) 50%),
                linear-gradient(135deg, var(--color-primary) 50%, transparent 50%);
            background-position:
                calc(100% - 16px) calc(50% - 2px),
                calc(100% - 11px) calc(50% - 2px);
            background-size: 5px 5px, 5px 5px;
            background-repeat: no-repeat;
            color: var(--color-text-primary);
            font-size: 13px;
            line-height: 1.2;
            cursor: pointer;
        }

        .app-submenu-select:focus {
            outline: none;
            border-color: var(--color-secondary);
            box-shadow: 0 0 0 3px rgba(37, 168, 224, 0.18);
        }

        .app-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10000;
            background: rgba(31, 41, 55, 0.45);
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .app-modal-overlay.is-open {
            display: flex;
        }

        .app-modal {
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--color-border);
            box-shadow: 0 20px 50px rgba(11, 88, 163, 0.18);
            max-width: 640px;
            width: 100%;
            max-height: min(90vh, 720px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #appModalAI .app-modal {
            width: 90vw;
            max-width: 90vw;
        }

        .app-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--color-border);
            background: linear-gradient(135deg, #ffffff, #f1f8fd);
            cursor: grab;
            user-select: none;
            -webkit-user-select: none;
            flex-shrink: 0;
        }

        .app-modal-header:active {
            cursor: grabbing;
        }

        .app-modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
            font-family: 'Cormorant Garamond', Georgia, serif;
            color: var(--color-primary);
            font-weight: 700;
        }

        .app-modal-close {
            border: none;
            background: transparent;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
            color: var(--color-text-secondary);
            padding: 4px 8px;
            border-radius: 6px;
        }

        .app-modal-close:hover {
            background: #edf7fd;
            color: var(--color-primary-hover);
        }

        .app-modal-body {
            padding: 16px 18px;
            overflow-y: auto;
            font-size: 14px;
        }

        .members-table-wrap {
            margin-top: 16px;
            overflow-x: auto;
        }

        .members-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .members-table th,
        .members-table td {
            text-align: left;
            padding: 8px 10px;
            border-bottom: 1px solid var(--color-border);
        }

        .members-table th {
            font-weight: 600;
            color: var(--color-primary-hover);
            background: #f6fbff;
        }

        .member-status-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .member-status-pill--active {
            background: #ecfdf3;
            color: #065f46;
        }

        .member-status-pill--disabled {
            background: #fef2f2;
            color: #991b1b;
        }

        .member-action-btn {
            padding: 6px 10px;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            background: #ffffff;
            color: #111827;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }

        .member-action-btn:hover {
            background: #f8fafc;
        }

        .app-modal-body .invite-block {
            margin-bottom: 12px;
            padding: 12px;
            background: linear-gradient(135deg, #f6fbff, #eff8fd);
            border-radius: 8px;
            border: 1px solid var(--color-border);
        }

        .app-modal-body .data-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
        }

        .csv-account-list {
            max-height: 360px;
            overflow-y: auto;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 8px;
            margin-bottom: 12px;
        }

        .csv-account-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 6px 4px;
            font-size: 14px;
            line-height: 1.4;
        }

        .csv-account-row label {
            cursor: pointer;
            flex: 1;
        }

        .csv-account-count {
            color: #6b7280;
            font-size: 12px;
            white-space: nowrap;
        }

        .app-modal-body .settings-block {
            margin-bottom: 16px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .app-modal-body .ai-assistant-card {
            padding: 18px;
            background: linear-gradient(145deg, #f9fcff, #eef8fc);
            border-radius: 12px;
            border: 1px solid var(--color-border);
            box-shadow: 0 4px 20px rgba(11, 88, 163, 0.06);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .app-modal-body .ai-usage-bar {
            font-size: 12px;
            color: var(--color-text-secondary);
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 10px 12px;
            line-height: 1.45;
        }

        .app-modal-body .ai-usage-bar strong {
            color: var(--color-primary-hover);
        }

        .app-modal-body .ai-presets-row {
            display: flex;
            flex-wrap: nowrap;
            gap: 8px;
            margin-bottom: 10px;
            overflow-x: auto;
            padding-bottom: 2px;
            scrollbar-width: thin;
        }

        .app-modal-body .ai-preset {
            flex: 0 0 auto;
            border: 0;
            border-radius: 10px;
            padding: 11px 16px;
            min-height: 42px;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            background: linear-gradient(145deg, #5e6a7f, #4f5b6f);
            box-shadow: 0 4px 10px rgba(65, 74, 94, 0.22);
            white-space: nowrap;
        }

        .app-modal-body .ai-preset:hover {
            background: linear-gradient(145deg, #677389, #566276);
            transform: translateY(-1px);
        }

        .app-modal-body .ai-preset:disabled {
            opacity: 0.72;
            cursor: not-allowed;
            transform: none;
        }

        .app-modal-body .ai-composer {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            margin-top: auto;
        }

        .app-modal-body .ai-question-input {
            width: 100%;
            max-width: 100%;
            min-height: 70px;
            box-sizing: border-box;
            padding: 12px 14px;
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: #fff;
            color: var(--color-text-primary);
            font-size: 14px;
            line-height: 1.4;
            resize: vertical;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .app-modal-body .ai-question-input:focus {
            outline: none;
            border-color: var(--color-secondary);
            box-shadow: 0 0 0 3px rgba(37, 168, 224, 0.2);
        }

        .app-modal-body .ai-submit-btn {
            border: 0;
            border-radius: 10px;
            padding: 11px 18px;
            min-width: 84px;
            min-height: 44px;
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(145deg, var(--color-primary), var(--color-primary-hover));
            box-shadow: 0 5px 12px rgba(10, 75, 142, 0.3);
            cursor: pointer;
            white-space: nowrap;
        }

        .app-modal-body .ai-submit-btn:hover {
            background: linear-gradient(145deg, #1366b8, var(--color-primary-hover));
            transform: translateY(-1px);
        }

        .app-modal-body .ai-submit-btn:disabled {
            opacity: 0.72;
            cursor: not-allowed;
            transform: none;
        }

        .app-modal-body .ai-chat-log {
            max-height: 220px;
            min-height: 72px;
            overflow-y: auto;
            flex: 1 1 auto;
            padding: 10px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            background: #f8fcff;
        }

        .app-modal-body .ai-chat-log .chat-message {
            margin-bottom: 12px;
        }

        .app-modal-body .ai-chat-log .chat-bubble {
            font-size: 13px;
        }

        .app-modal-body .ai-chat-log .ai-bubble-html {
            white-space: normal;
        }

        .app-modal-body .ai-chat-log .ai-bubble-html p {
            margin: 0 0 0.5em 0;
        }

        .app-modal-body .ai-chat-log .ai-bubble-html p:last-child {
            margin-bottom: 0;
        }

        .app-modal-body .ai-chat-log .ai-bubble-html ul,
        .app-modal-body .ai-chat-log .ai-bubble-html ol {
            margin: 0.35em 0 0.5em 0;
            padding-left: 1.15em;
        }

        .app-modal-body .ai-chat-log .ai-bubble-html li {
            margin-bottom: 0.25em;
        }

        .app-modal-body .ai-chat-log .ai-bubble-html h3,
        .app-modal-body .ai-chat-log .ai-bubble-html h4,
        .app-modal-body .ai-chat-log .ai-bubble-html h5 {
            margin: 0.5em 0 0.35em 0;
            font-size: 1.05em;
            font-weight: 600;
        }

        .app-modal-body .ai-chat-log .ai-bubble-html h3:first-child,
        .app-modal-body .ai-chat-log .ai-bubble-html h4:first-child {
            margin-top: 0;
        }

        .ai-guidance-button.secondary {
            background: #4b5563;
        }

        .ai-guidance-button.secondary:hover {
            background: #374151;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .container {
                padding: 25px;
                border-radius: 15px;
                margin: 10px auto;
            }
            
            .section-header {
                margin: 15px -25px 25px -25px;
                padding: 18px 20px;
                font-size: 18px;
            }
            
            h1 {
                font-size: 1.8em;
            }
            
            .question-text {
                font-size: 18px;
            }
            
            .button-group {
                flex-direction: column;
                gap: 10px;
            }
            
            button {
                width: 100%;
                margin: 5px 0;
            }
            
            .navigation {
                flex-direction: column;
                gap: 10px;
            }
            
            .modal-content {
                margin: 10px;
                max-height: 90vh;
            }
            
            .modal-header {
                padding: 20px 25px;
                font-size: 18px;
            }
            
            .modal-body {
                padding: 25px;
            }
            
            .login-logo {
                max-width: 280px;
            }
            
            .logout-form {
                top: 15px;
                right: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 20px;
            }
            
            h1 {
                font-size: 1.6em;
            }
            
            .score-display {
                font-size: 48px !important;
            }
            
            .score-display-card {
                padding: 20px !important;
            }
            
            input[type="email"], input[type="text"], input[type="password"], select {
                padding: 14px 16px;
            }
            
            button {
                padding: 14px 24px;
                font-size: 15px;
            }
            
            .logout-button {
                padding: 8px 12px;
                font-size: 13px;
            }
            
            .logout-button i {
                font-size: 14px;
            }
        }
        
        /* Snackbar Styles */
        .snackbar {
            visibility: hidden;
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-hover) 100%);
            color: white;
            text-align: center;
            border-radius: 12px;
            padding: 16px 24px;
            z-index: 9999;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 90%;
            font-weight: 500;
            transition: all 0.3s ease-in-out;
            opacity: 0;
            transform: translateX(-50%) translateY(-20px);
        }
        
        .snackbar.error {
            background: linear-gradient(135deg, #ef4444 0%, var(--color-error) 100%);
        }
        
        .snackbar.success {
            background: linear-gradient(135deg, #22c55e 0%, var(--color-success) 100%);
        }
        
        .snackbar.show {
            visibility: visible;
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
        
        .snackbar .close-btn {
            margin-left: 12px;
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }
        
        .snackbar .close-btn:hover {
            opacity: 1;
        }

        /* Savvy theme overrides for legacy sections */
        .cost-calculator-grid .vendor-raw-btn {
            color: var(--color-primary-hover);
        }

        .cost-calculator-grid .vendor-raw-btn:hover:not(:disabled) .vendor-raw-icon {
            color: var(--color-primary);
        }

        .cost-calculator-grid .vendor-chat-btn:hover:not(:disabled) {
            border-color: var(--color-secondary);
            box-shadow: 0 6px 16px rgba(11, 88, 163, 0.22);
        }

        .cost-calculator-grid .vendor-chat-btn:hover:not(:disabled) .vendor-chat-icon {
            color: var(--color-primary-hover);
        }

        .cost-calculator-grid .cancel-guidance-btn:hover .cancel-guidance-icon {
            color: #d97706;
        }

        .report-filters select:focus,
        .report-filters select:hover,
        .report-filters .column-toggle-btn:hover {
            border-color: var(--color-secondary);
        }

        .report-filters .column-toggle-btn:hover {
            color: var(--color-primary-hover);
        }

        .add-row-btn,
        .primary-btn,
        .generate-summary-btn,
        .chat-send-button,
        .vendor-chat-send-btn {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover));
        }

        .add-row-btn:hover,
        .primary-btn:hover,
        .chat-send-button:hover:not(:disabled) {
            background: var(--color-primary-hover);
        }

        .savings-section {
            border-color: var(--color-primary);
        }

        .savings-amount,
        .popup-link,
        .ai-guidance-content h4 {
            color: var(--color-primary);
        }

        .score-display-card,
        .modal-header,
        .ai-guidance-header,
        .chat-bubble.user-bubble,
        .vendor-chat-row.is-self .vendor-chat-bubble {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover));
        }

        .popup-link {
            background: rgba(37, 168, 224, 0.12);
        }

        .popup-link:hover {
            color: var(--color-primary-hover);
            background: rgba(37, 168, 224, 0.2);
            border-color: rgba(37, 168, 224, 0.3);
        }

        .modal-body::-webkit-scrollbar-thumb,
        .ai-guidance-content::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover));
        }

        .performance-tier::before {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover));
        }

        .tier-title {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .action-item {
            background: linear-gradient(135deg, #f0f8fe, #e7f3fb);
            border-left-color: var(--color-primary);
        }

        .chat-input:focus,
        .vendor-chat-input:focus,
        .role-option:hover {
            border-color: var(--color-secondary);
            box-shadow: 0 0 0 2px rgba(37, 168, 224, 0.2);
        }

        .vendor-chat-meta {
            background: linear-gradient(135deg, #f2f9fe 0%, #eef8fc 100%);
            color: var(--color-primary-hover);
        }

        .vendor-chat-meta-badge {
            background: var(--color-primary);
        }

        .vendor-chat-log {
            background: radial-gradient(circle at top right, #f0f8fe 0%, #f8fbfd 52%, #f1f5f9 100%);
        }

        .vendor-chat-empty {
            border: 1px dashed #9ed4ec;
            background: #f4fbff;
            color: var(--color-text-secondary);
        }

        .ai-loading-spinner,
        .loading-spinner {
            border-top-color: var(--color-primary);
        }

        .btn-inline-spinner {
            width: 16px;
            height: 16px;
            margin-right: 8px;
            vertical-align: -3px;
        }

        button.is-loading {
            pointer-events: none;
            opacity: 0.88;
        }

        .app-ai-populate-overlay {
            position: fixed;
            inset: 0;
            z-index: 10050;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.82);
        }

        .app-ai-populate-overlay[hidden] {
            display: none !important;
        }

        .app-ai-populate-overlay-inner {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 22px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
            font-size: 15px;
            color: #374151;
        }

        .role-option input[type="radio"] {
            accent-color: var(--color-primary);
        }
    </style>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-K84J5NBK1Y"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'G-K84J5NBK1Y');
    </script>    
</head>
<body>

    <!-- Snackbar for messages -->
    <div id="snackbar" class="snackbar">
        <span id="snackbar-message"></span>
        <button type="button" class="close-btn" onclick="hideSnackbar()">&times;</button>
    </div>

    <div id="appAiPopulateOverlay" class="app-ai-populate-overlay" hidden aria-live="polite" aria-busy="false">
        <div class="app-ai-populate-overlay-inner">
            <span class="loading-spinner btn-inline-spinner" aria-hidden="true"></span>
            <span id="appAiPopulateOverlayText">Populating purposes with AI…</span>
        </div>
    </div>

    <script>
    // Snackbar Functions
    function showSnackbar(message, type = '') {
        const snackbar = document.getElementById('snackbar');
        const messageSpan = document.getElementById('snackbar-message');
        
        messageSpan.textContent = message;
        snackbar.className = 'snackbar ' + type;
        snackbar.classList.add('show');
        
        // Auto-hide after 5 seconds
        setTimeout(hideSnackbar, 5000);
    }
    
    function hideSnackbar() {
        const snackbar = document.getElementById('snackbar');
        snackbar.classList.remove('show');
    }

    function setButtonLoading(btn, isLoading, loadingLabel) {
        if (!btn) return;
        if (isLoading) {
            if (!btn.dataset.idleHtml) {
                btn.dataset.idleHtml = btn.innerHTML;
            }
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<span class="loading-spinner btn-inline-spinner" aria-hidden="true"></span><span>'
                + (loadingLabel || 'Loading…') + '</span>';
        } else {
            btn.classList.remove('is-loading');
            btn.removeAttribute('aria-busy');
            if (btn.dataset.idleHtml) {
                btn.innerHTML = btn.dataset.idleHtml;
            }
        }
    }

    function showAiPopulateLoader(message) {
        var overlay = document.getElementById('appAiPopulateOverlay');
        var textEl = document.getElementById('appAiPopulateOverlayText');
        if (textEl) {
            if (!textEl.dataset.defaultText) {
                textEl.dataset.defaultText = textEl.textContent;
            }
            if (message) {
                textEl.textContent = message;
            }
        }
        if (overlay) {
            overlay.hidden = false;
            overlay.setAttribute('aria-busy', 'true');
            document.body.style.overflow = 'hidden';
        }
    }

    function hideAiPopulateLoader() {
        var overlay = document.getElementById('appAiPopulateOverlay');
        if (overlay) {
            overlay.hidden = true;
            overlay.setAttribute('aria-busy', 'false');
        }
        if (!document.querySelector('.app-modal-overlay.is-open')) {
            document.body.style.overflow = '';
        }
    }
    </script>

    <script>
    // Check for PHP messages and show snackbar
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_SESSION['error'])): ?>
            showSnackbar('<?php echo addslashes(htmlspecialchars($_SESSION['error'])); ?>', 'error');
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['message'])): ?>
            showSnackbar('<?php echo addslashes(htmlspecialchars($_SESSION['message'])); ?>', 'success');
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['smtp_debug_transcript'])): ?>
            try {
                console.group('Postmark HTTP debug');
                console.log(<?php echo json_encode((string) $_SESSION['smtp_debug_transcript']); ?>);
                console.groupEnd();
            } catch (e) {}
            <?php unset($_SESSION['smtp_debug_transcript']); ?>
        <?php endif; ?>
    });
    </script>

    <div class="container-wrapper <?php echo ($current_view === 'placeholder') ? 'placeholder-container-wrapper' : ''; ?>">
        <?php if ($current_view === 'placeholder' || $current_view === 'login'): ?>
            <!-- Logo above container -->
            <div class="logo-above-container">
                <img src="https://savvycfo.com/wp-content/uploads/2023/06/SavvyCFO_logo_mainfinal-bluewhite_23Jun23.png" 
                     alt="Savvy CFO Logo" 
                     class="login-logo">
                <div class="logo-tagline">Savvy Saver</div>
            </div>
        <?php endif; ?>
        <?php if ($current_view === 'placeholder'): ?>
            <div class="app-nav-shell">
                <nav class="app-nav" aria-label="App sections">
                    <ul class="app-nav-list">
                        <li class="app-nav-item has-submenu" id="appMembersNavItem">
                            <button type="button" class="app-nav-link" id="appMembersMenuBtn" aria-haspopup="true" aria-expanded="false" aria-controls="appMembersSubmenu">Members</button>
                            <ul class="app-submenu" id="appMembersSubmenu" role="menu" aria-label="Members actions">
                                <?php if ($is_admin): ?>
                                <li role="none"><button type="button" role="menuitem" class="app-submenu-item" data-open-modal="appModalMembersInvite">Invite</button></li>
                                <?php endif; ?>
                                <li role="none"><button type="button" role="menuitem" class="app-submenu-item" data-open-modal="appModalMembersManage">Manage</button></li>
                            </ul>
                        </li>
                        <li class="app-nav-item has-submenu" id="appProjectNavItem">
                            <button type="button" class="app-nav-link" id="appProjectMenuBtn" aria-haspopup="true" aria-expanded="false" aria-controls="appProjectSubmenu">Project</button>
                            <ul class="app-submenu" id="appProjectSubmenu" role="menu" aria-label="Project actions">
                                <?php if ($can_create_projects): ?>
                                <li role="none"><button type="button" role="menuitem" class="app-submenu-item" id="appCreateProjectBtn" data-open-modal="appModalProjectWizard">Create New Project</button></li>
                                <li role="none"><button type="button" role="menuitem" class="app-submenu-item" id="appDeleteProjectBtn">Delete project…</button></li>
                                <?php endif; ?>
                                <li role="none">
                                    <label class="app-submenu-item" for="projectSwitcherSelect">
                                        <span class="app-submenu-label">Switch Project</span>
                                        <select id="projectSwitcherSelect" class="app-submenu-select"></select>
                                    </label>
                                </li>
                            </ul>
                        </li>
                        <li class="app-nav-item has-submenu" id="appDataNavItem">
                            <button type="button" class="app-nav-link" id="appDataMenuBtn" aria-haspopup="true" aria-expanded="false" aria-controls="appDataSubmenu">Data</button>
                            <ul class="app-submenu" id="appDataSubmenu" role="menu" aria-label="Data actions">
                                <li role="none"><a role="menuitem" class="app-submenu-item" href="?action=export_vendors&amp;format=xlsx">Download Excel</a></li>
                                <li role="none"><a role="menuitem" class="app-submenu-item" href="?action=export_vendors&amp;format=pdf">Download PDF</a></li>
                                <li role="none"><a role="menuitem" class="app-submenu-item" href="?action=export_vendors&amp;format=summary_pdf">Executive summary PDF</a></li>
                                <li role="none">
                                    <button type="button" role="menuitem" class="app-submenu-item" id="appImportCsvBtn">Import CSV</button>
                                    <input type="file" id="csvImportInput" accept=".csv,text/csv" style="display:none;">
                                </li>
                            </ul>
                        </li>
                        <li class="app-nav-item has-submenu" id="appAiNavItem">
                            <button type="button" class="app-nav-link" id="appAiMenuBtn" aria-haspopup="true" aria-expanded="false" aria-controls="appAiSubmenu">AI</button>
                            <ul class="app-submenu" id="appAiSubmenu" role="menu" aria-label="AI actions">
                                <li role="none"><button type="button" role="menuitem" class="app-submenu-item" id="appAiAssistantBtn" data-open-modal="appModalAI">Assistant</button></li>
                                <li role="none"><button type="button" role="menuitem" class="app-submenu-item" id="appAutoPopulatePurposeBtn">Auto populate purpose</button></li>
                            </ul>
                        </li>
                        <li class="app-nav-item has-submenu" id="appAdminNavItem">
                            <button type="button" class="app-nav-link" id="appAdminMenuBtn" aria-haspopup="true" aria-expanded="false" aria-controls="appAdminSubmenu"><?php echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['user_email'] ?? 'Account'); ?></button>
                            <ul class="app-submenu" id="appAdminSubmenu" role="menu" aria-label="Account actions">
                                <li role="none">
                                    <button type="button" role="menuitem" class="app-submenu-item" data-open-modal="appModalSettings">Settings</button>
                                </li>
                                <li role="none">
                                    <form method="POST" class="app-nav-inline-form">
                                        <input type="hidden" name="action" value="logout">
                                        <button type="submit" role="menuitem" class="app-submenu-item">Logout</button>
                                    </form>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
        <div class="container<?php echo ($current_view === 'placeholder' && $can_create_projects && !empty($_SESSION['project_onboarding_required'])) ? ' project-onboarding-hidden' : ''; ?>">
            <?php if ($current_view === 'login'): ?>
            <div class="content-padding login-page">
                <h1>Savvy Saver</h1>
                <p class="subtitle">Sign in with your username and password.</p>
            
            <?php if (!empty($_SESSION['awaiting_role'])): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="save_user_role">
                    <div class="form-group">
                        <label>Select the option that best describes you:</label>
                        <p class="subtitle" style="margin-top: 4px; font-size: 15px; color: #4b5563;">
                            Signed in as <?php echo htmlspecialchars($_SESSION['user_email'] ?? $_SESSION['username'] ?? ''); ?>. Let us know who you are to tailor your experience.
                        </p>
                        <div class="role-options">
                            <?php foreach ($user_role_options as $option): ?>
                                <label class="role-option">
                                    <input type="radio" name="user_role" value="<?php echo htmlspecialchars($option); ?>" required>
                                    <span><?php echo htmlspecialchars($option); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="button-group">
                        <button type="submit">Continue</button>
                    </div>
                </form>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label for="username">Username or email</label>
                        <input type="text" id="username" name="username" required autocomplete="username">
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="agree_terms" id="agree_terms" required>
                            <span>By using this cost savings tool, I agree to the <a href="https://savvycfo.com/terms-conditions-privacy-policy/" target="_blank" rel="noopener noreferrer">terms of use</a>.</span>
                        </label>
                    </div>
                    <button type="submit">Log in</button>
                </form>
            <?php endif; ?>
            
            <!-- eBook Promotion Section -->
            </div> <!-- Close content-padding -->

        <?php elseif ($current_view === 'placeholder'): ?>
            <div class="content-padding">
                <div class="report-filters">
                    <div class="report-filters-top">
                        <label for="reportFilter">Report Filters:</label>
                        <select id="reportFilter" onchange="filterTableRows(this.value)">
                            <option value="all">All</option>
                            <option value="pending">Pending</option>
                            <option value="question">Question</option>
                            <option value="unknown">Unknown</option>
                            <option value="keep">Keep</option>
                            <option value="mark_for_cancellation">Mark for Cancellation</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <button type="button" class="bulk-action-btn" data-open-modal="appModalBulkActions">Bulk Actions</button>
                        <button type="button" id="togglePurposeColumnBtn" class="column-toggle-btn" aria-pressed="false">Show Purpose</button>
                    </div>
                    <details class="status-key-details" id="vendorStatusKey">
                        <summary class="status-key-summary">Status key</summary>
                        <div class="status-key-body">
                            <dl>
                                <dt>Pending</dt>
                                <dd>Not reviewed yet, or no decision recorded—use this until the team classifies the vendor.</dd>
                                <dt>Question</dt>
                                <dd>Needs a follow-up (stakeholder input, clarification, or discussion) before you commit to keep or cancel.</dd>
                                <dt>Unknown</dt>
                                <dd>Not enough context to decide—purpose, owner, or numbers are unclear (common right after import or with thin data).</dd>
                                <dt>Keep</dt>
                                <dd>Reviewed and you plan to continue this vendor or spend; no cancellation in progress.</dd>
                                <dt>Mark for Cancellation</dt>
                                <dd>Decision is to cancel; use the cancellation date field to track the target end until it is fully executed.</dd>
                                <dt>Cancelled</dt>
                                <dd>Cancellation is complete (or the contract ended); treat this spend as off for forward-looking savings.</dd>
                            </dl>
                        </div>
                    </details>
                </div>
                
                <div class="cost-calculator-table-wrapper">
                    <table class="cost-calculator-grid" id="costCalculatorGrid">
                    <thead>
                        <tr>
                            <th class="select-row">
                                <input type="checkbox" id="selectAllVendors" aria-label="Select all vendors matching current filters">
                            </th>
                            <th class="item-number">Item #</th>
                            <th class="vendor-name">Vendor</th>
                            <th class="cost-per-period">Cost</th>
                            <th class="frequency th-with-filter">
                                <div class="th-with-filter-inner">
                                    <span class="th-with-filter-caption">Freq</span>
                                    <button type="button" class="vendor-col-filter-btn" data-vendor-filter="frequency" title="Filter by frequency" aria-label="Filter by frequency" aria-haspopup="true" aria-expanded="false">
                                        <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
                                    </button>
                                    <div class="vendor-col-filter-dropdown" data-vendor-filter="frequency" hidden>
                                        <div class="vendor-col-filter-list"></div>
                                        <div class="vendor-col-filter-actions">
                                            <button type="button" class="vendor-col-filter-clear" data-vendor-filter="frequency">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th class="annual-cost">Annual Cost</th>
                            <th class="manager-col th-with-filter">
                                <div class="th-with-filter-inner">
                                    <span class="th-with-filter-caption">Manager</span>
                                    <button type="button" class="vendor-col-filter-btn" data-vendor-filter="manager" title="Filter by manager" aria-label="Filter by manager" aria-haspopup="true" aria-expanded="false">
                                        <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
                                    </button>
                                    <div class="vendor-col-filter-dropdown" data-vendor-filter="manager" hidden>
                                        <div class="vendor-col-filter-list"></div>
                                        <div class="vendor-col-filter-actions">
                                            <button type="button" class="vendor-col-filter-clear" data-vendor-filter="manager">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th class="visibility-col th-with-filter">
                                <div class="th-with-filter-inner">
                                    <span class="th-with-filter-caption">Visibility</span>
                                    <button type="button" class="vendor-col-filter-btn" data-vendor-filter="visibility" title="Filter by visibility" aria-label="Filter by visibility" aria-haspopup="true" aria-expanded="false">
                                        <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
                                    </button>
                                    <div class="vendor-col-filter-dropdown" data-vendor-filter="visibility" hidden>
                                        <div class="vendor-col-filter-list"></div>
                                        <div class="vendor-col-filter-actions">
                                            <button type="button" class="vendor-col-filter-clear" data-vendor-filter="visibility">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th class="row-status th-with-filter" title="Vendor status: Pending, Question, Unknown, Keep, Mark for Cancellation, or Cancelled">
                                <div class="th-with-filter-inner">
                                    <span class="th-with-filter-caption">Status</span>
                                    <button type="button" class="vendor-col-filter-btn" data-vendor-filter="status" title="Filter by status" aria-label="Filter by column status" aria-haspopup="true" aria-expanded="false">
                                        <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
                                    </button>
                                    <div class="vendor-col-filter-dropdown" data-vendor-filter="status" hidden>
                                        <div class="vendor-col-filter-list"></div>
                                        <div class="vendor-col-filter-actions">
                                            <button type="button" class="vendor-col-filter-clear" data-vendor-filter="status">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th class="notes">Purpose</th>
                            <th class="vendor-chat-col th-with-filter">
                                <div class="th-with-filter-inner">
                                    <span class="th-with-filter-caption">Chat</span>
                                    <button type="button" class="vendor-col-filter-btn" data-vendor-filter="chat_unread" title="Filter chat" aria-label="Filter by chat unread" aria-haspopup="true" aria-expanded="false">
                                        <span class="material-symbols-outlined" aria-hidden="true">filter_alt</span>
                                    </button>
                                    <div class="vendor-col-filter-dropdown" data-vendor-filter="chat_unread" hidden>
                                        <div class="vendor-col-filter-list"></div>
                                        <div class="vendor-col-filter-actions">
                                            <button type="button" class="vendor-col-filter-clear" data-vendor-filter="chat_unread">Clear</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="calculatorRows">
                        <!-- Rows will be added dynamically -->
                    </tbody>
                </table>
                <div id="vendorPagination" class="vendor-pagination" hidden>
                    <button type="button" id="vendorPaginationPrev" class="vendor-pagination-btn" aria-label="Previous page" title="Previous page">
                        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                    </button>
                    <div id="vendorPaginationStatus" class="vendor-pagination-status">Page 1 of 1</div>
                    <button type="button" id="vendorPaginationNext" class="vendor-pagination-btn" aria-label="Next page" title="Next page">
                        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                    </button>
                </div>
                
                <div class="cost-calculator-actions">
                    <button type="button" class="add-row-btn" onclick="addCalculatorRow()">+ Add Row</button>
                </div>
                
                <div class="savings-summary">
                    <div class="savings-section">
                        <h3>Potential + Confirmed Annual Savings</h3>
                        <div class="savings-amount" id="potentialSavings">$0.00</div>
                    </div>
                    <div class="savings-section confirmed-savings-section">
                        <h3>Confirmed Annual Savings</h3>
                        <div class="savings-amount confirmed-savings-amount" id="confirmedSavings">$0.00</div>
                    </div>
                </div>
            </div>
            
            <script>
            let rowCount = 0;
            let currentActiveProjectId = null;
            let deleteProjectTargetId = 0;
            let deleteProjectExpectedName = '';
            const isAdminUser = <?php echo $is_admin ? 'true' : 'false'; ?>;
            const canCreateProjects = <?php echo $can_create_projects ? 'true' : 'false'; ?>;

            function postJson(data) {
                return fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(data),
                }).then(function(r) { return r.json(); });
            }

            function loadProjectsIntoMenu() {
                return postJson({ action: 'project_list' })
                    .then(function(d) {
                        if (!d || !d.success) return;
                        const sel = document.getElementById('projectSwitcherSelect');
                        if (!sel) return;
                        sel.innerHTML = '';
                        (d.projects || []).forEach(function(p) {
                            const opt = document.createElement('option');
                            opt.value = String(p.id);
                            opt.textContent = p.name;
                            if (parseInt(p.id, 10) === parseInt(d.active_project_id || 0, 10)) {
                                opt.selected = true;
                            }
                            sel.appendChild(opt);
                        });
                        currentActiveProjectId = parseInt(d.active_project_id || 0, 10) || null;
                        updateActiveProjectHeader(sel);
                        const hasNoProjects = !Array.isArray(d.projects) || d.projects.length === 0;
                        if (canCreateProjects && (d.onboarding_required || hasNoProjects)) {
                            openAppModal('appModalProjectWizard');
                        }
                    })
                    .catch(function() {});
            }

            function syncDeleteProjectConfirmState() {
                var btn = document.getElementById('deleteProjectSubmitBtn');
                var inp = document.getElementById('deleteProjectConfirmInput');
                if (!btn || !inp) return;
                var ok = deleteProjectExpectedName !== '' && inp.value.trim() === deleteProjectExpectedName;
                btn.disabled = !ok;
            }

            function openDeleteProjectModal() {
                var sel = document.getElementById('projectSwitcherSelect');
                if (!sel || sel.options.length <= 1) {
                    showSnackbar('Cannot delete the only project in your organization.', 'error');
                    return;
                }
                var opt = sel.options[sel.selectedIndex];
                var pid = parseInt(sel.value, 10) || 0;
                if (!pid) {
                    showSnackbar('Select a project to delete.', 'error');
                    return;
                }
                deleteProjectTargetId = pid;
                deleteProjectExpectedName = opt ? String(opt.textContent || opt.text || '').trim() : '';
                var disp = document.getElementById('deleteProjectNameDisplay');
                if (disp) disp.textContent = deleteProjectExpectedName;
                var inp = document.getElementById('deleteProjectConfirmInput');
                if (inp) inp.value = '';
                syncDeleteProjectConfirmState();
                openAppModal('appModalDeleteProject');
            }

            function updateActiveProjectHeader(projectSource) {
                const baseTitle = 'Savvy Saver';

                let projectName = '';
                if (typeof projectSource === 'string') {
                    projectName = projectSource;
                } else if (projectSource && typeof projectSource === 'object' && 'selectedIndex' in projectSource) {
                    const idx = projectSource.selectedIndex;
                    const opt = idx >= 0 ? projectSource.options[idx] : null;
                    projectName = opt ? String(opt.text || '') : '';
                }

                projectName = projectName.trim();
                document.title = projectName !== '' ? (baseTitle + ' - ' + projectName) : baseTitle;
            }

            var postProjectCreateFlow = {
                active: false,
                projectId: 0,
                projectName: '',
                dataMode: 'upload_after',
                previousActiveProjectId: 0,
                step: null,
                importCompleted: false,
                copyPurposes: null,
                copyChats: null,
                overwriteBlankPurposes: false
            };

            function postCreateFlowStepLabel(stepNum) {
                var name = postProjectCreateFlow.projectName || 'your project';
                return 'Setting up ' + name + ' — step ' + stepNum + ' of 3';
            }

            function setPostCreateSubtitle(elId, stepNum) {
                var el = document.getElementById(elId);
                if (el) el.textContent = postCreateFlowStepLabel(stepNum);
            }

            function fetchActiveProjectVendorCount() {
                var fd = new FormData();
                fd.append('action', 'load_cost_calculator');
                return fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data || !data.success || !Array.isArray(data.items)) return 0;
                        return data.items.filter(function(item) {
                            return String(item.vendor_name || '').trim() !== '';
                        }).length;
                    })
                    .catch(function() { return 0; });
            }

            function resetPostCreatePurposeModal() {
                postProjectCreateFlow.copyPurposes = null;
                postProjectCreateFlow.copyChats = null;
                postProjectCreateFlow.overwriteBlankPurposes = false;
                var questionsBlock = document.getElementById('postCreateCopyQuestionsBlock');
                var sourceBlock = document.getElementById('postCreatePurposeSelectBlock');
                var continueBtn = document.getElementById('postCreateCopyContinueBtn');
                var proceedBtn = document.getElementById('postCreatePurposeProceedBtn');
                var select = document.getElementById('postCreatePurposeSource');
                var blankCb = document.getElementById('postCreateOverwriteBlankPurposes');
                if (questionsBlock) questionsBlock.style.display = '';
                if (sourceBlock) sourceBlock.style.display = 'none';
                if (continueBtn) continueBtn.style.display = '';
                if (proceedBtn) proceedBtn.style.display = 'none';
                if (select) select.innerHTML = '';
                if (blankCb) blankCb.checked = false;
                document.querySelectorAll('input[name="postCreateCopyPurposes"]').forEach(function(r) { r.checked = false; });
                document.querySelectorAll('input[name="postCreateCopyChats"]').forEach(function(r) { r.checked = false; });
            }

            function postCreateCopyAnswersChosen() {
                var purposeRadio = document.querySelector('input[name="postCreateCopyPurposes"]:checked');
                var chatRadio = document.querySelector('input[name="postCreateCopyChats"]:checked');
                return !!(purposeRadio && chatRadio);
            }

            function readPostCreateCopyAnswers() {
                var purposeRadio = document.querySelector('input[name="postCreateCopyPurposes"]:checked');
                var chatRadio = document.querySelector('input[name="postCreateCopyChats"]:checked');
                postProjectCreateFlow.copyPurposes = purposeRadio ? purposeRadio.value === 'yes' : null;
                postProjectCreateFlow.copyChats = chatRadio ? chatRadio.value === 'yes' : null;
                var blankCb = document.getElementById('postCreateOverwriteBlankPurposes');
                postProjectCreateFlow.overwriteBlankPurposes = blankCb ? !!blankCb.checked : false;
            }

            function populatePostCreatePurposeSourceSelect() {
                var select = document.getElementById('postCreatePurposeSource');
                if (!select) return Promise.resolve();
                return postJson({ action: 'project_list' }).then(function(d) {
                    select.innerHTML = '';
                    var firstOpt = null;
                    (d.projects || []).forEach(function(p) {
                        var pid = parseInt(p.id, 10) || 0;
                        if (pid <= 0 || pid === postProjectCreateFlow.projectId) return;
                        var opt = document.createElement('option');
                        opt.value = String(pid);
                        opt.textContent = p.name || ('Project ' + pid);
                        select.appendChild(opt);
                        if (!firstOpt) firstOpt = opt;
                    });
                    var prev = postProjectCreateFlow.previousActiveProjectId;
                    if (prev > 0 && prev !== postProjectCreateFlow.projectId) {
                        select.value = String(prev);
                    } else if (firstOpt) {
                        select.value = firstOpt.value;
                    }
                });
            }

            function openPostCreatePurposeModal() {
                resetPostCreatePurposeModal();
                setPostCreateSubtitle('postCreatePurposeSubtitle', 2);
                openAppModal('appModalPostCreatePurpose');
            }

            function openPostCreateInviteModal() {
                setPostCreateSubtitle('postCreateInviteSubtitle', 3);
                openAppModal('appModalPostCreateInvite');
            }

            function proceedToPurposeOrInvite() {
                var projectId = parseInt(postProjectCreateFlow.projectId, 10) || parseInt(currentActiveProjectId, 10) || 0;
                if (!projectId) {
                    postProjectCreateFlow.step = 'invite';
                    openPostCreateInviteModal();
                    return;
                }
                var syncActive = postJson({ action: 'project_set_active', project_id: projectId }).then(function(d) {
                    if (d && d.success) {
                        currentActiveProjectId = projectId;
                    }
                }).catch(function() {});
                syncActive.then(function() {
                    return fetchActiveProjectVendorCount();
                }).then(function(count) {
                    if (count === 0) {
                        showSnackbar('Import vendors first to copy from another project or use AI purpose populate.', 'info');
                        postProjectCreateFlow.step = 'invite';
                        openPostCreateInviteModal();
                        return;
                    }
                    showSnackbar('Populating purposes with AI… This may take a minute.', 'info');
                    showAiPopulateLoader('Populating purposes with AI…');
                    clearTimeout(saveTimeout);
                    var populateFn = typeof window.runProjectAutoPopulatePurpose === 'function'
                        ? window.runProjectAutoPopulatePurpose
                        : null;
                    var waitForSaves = typeof window.waitForCalculatorSaveIdle === 'function'
                        ? window.waitForCalculatorSaveIdle()
                        : Promise.resolve();
                    var populatePromise = waitForSaves.then(function() {
                        if (!populateFn) {
                            return Promise.resolve({ success: false, error: 'Auto populate is not ready yet.' });
                        }
                        // Server persists purposes; skip client save + grid reload avoids overwriting DB.
                        return populateFn(projectId, { silent: true, skipClientSave: true, hideLoader: true });
                    });
                    return populatePromise.then(function(d) {
                        if (!d || !d.success) {
                            showSnackbar((d && d.error) || 'Purpose auto-populate failed.', 'error');
                        } else {
                            var updated = typeof d.updated === 'number' ? d.updated : 0;
                            if (updated > 0) {
                                showSnackbar('Populated purposes for ' + updated + ' vendor(s).', 'success');
                            } else if (!(d.resolved && d.resolved.length)) {
                                showSnackbar('No purposes could be resolved for these vendors.', 'info');
                            }
                        }
                        return loadCalculatorData().then(function() {
                            if (d && Array.isArray(d.resolved) && d.resolved.length
                                && typeof window.applyPurposeLookupResultsToUi === 'function') {
                                window.applyPurposeLookupResultsToUi(d.resolved);
                            }
                        });
                    });
                }).then(function() {
                    postProjectCreateFlow.step = 'purpose';
                    openPostCreatePurposeModal();
                }).catch(function() {
                    showSnackbar('Could not finish purpose setup.', 'error');
                    postProjectCreateFlow.step = 'purpose';
                    openPostCreatePurposeModal();
                }).finally(function() {
                    hideAiPopulateLoader();
                });
            }

            function advancePostProjectCreateFlow() {
                if (!postProjectCreateFlow.active) return;
                var step = postProjectCreateFlow.step;
                if (step === 'upload' || step === 'upload_waiting_import') {
                    postProjectCreateFlow.step = 'purpose_check';
                    proceedToPurposeOrInvite();
                    return;
                }
                if (step === 'purpose' || step === 'purpose_done') {
                    postProjectCreateFlow.step = 'invite';
                    openPostCreateInviteModal();
                    return;
                }
            }

            function endPostProjectCreateFlow() {
                if (!postProjectCreateFlow.active) return;
                postProjectCreateFlow.active = false;
                postProjectCreateFlow.step = null;
                postProjectCreateFlow.importCompleted = false;
                closeAppModal('appModalPostCreateUpload');
                closeAppModal('appModalPostCreatePurpose');
                closeAppModal('appModalPostCreateInvite');
                resetPostCreatePurposeModal();
                var form = document.getElementById('projectWizardForm');
                if (form) form.reset();
                var uploadRadio = document.getElementById('projectWizardDataModeUpload');
                if (uploadRadio) uploadRadio.checked = true;
                var startDateInput = document.getElementById('projectWizardStartDate');
                if (startDateInput && !startDateInput.value) {
                    startDateInput.value = new Date().toISOString().slice(0, 10);
                }
                showSnackbar('Project setup complete.', 'success');
            }

            function startPostProjectCreateFlow(opts) {
                opts = opts || {};
                postProjectCreateFlow.active = true;
                postProjectCreateFlow.projectId = parseInt(opts.projectId, 10) || 0;
                postProjectCreateFlow.projectName = opts.projectName || '';
                postProjectCreateFlow.dataMode = opts.dataMode || 'upload_after';
                postProjectCreateFlow.previousActiveProjectId = parseInt(opts.previousActiveProjectId, 10) || 0;
                postProjectCreateFlow.importCompleted = false;
                if (postProjectCreateFlow.dataMode === 'upload_after') {
                    postProjectCreateFlow.step = 'upload';
                    setPostCreateSubtitle('postCreateUploadSubtitle', 1);
                    openAppModal('appModalPostCreateUpload');
                } else {
                    postProjectCreateFlow.step = 'purpose_check';
                    proceedToPurposeOrInvite();
                }
            }

            function initPostProjectCreateFlow() {
                var chooseBtn = document.getElementById('postCreateUploadChooseBtn');
                var skipBtn = document.getElementById('postCreateUploadSkipBtn');
                var csvIn = document.getElementById('csvImportInput');
                if (chooseBtn && csvIn) {
                    chooseBtn.addEventListener('click', function() {
                        closeAppModal('appModalPostCreateUpload');
                        postProjectCreateFlow.step = 'upload_waiting_import';
                        csvIn.click();
                    });
                }
                if (skipBtn) {
                    skipBtn.addEventListener('click', function() {
                        closeAppModal('appModalPostCreateUpload');
                        advancePostProjectCreateFlow();
                    });
                }
                var copyContinueBtn = document.getElementById('postCreateCopyContinueBtn');
                var purposeProceed = document.getElementById('postCreatePurposeProceedBtn');
                if (copyContinueBtn) {
                    copyContinueBtn.addEventListener('click', function() {
                        if (!postCreateCopyAnswersChosen()) {
                            showSnackbar('Answer both questions to continue.', 'error');
                            return;
                        }
                        readPostCreateCopyAnswers();
                        if (!postProjectCreateFlow.copyPurposes && !postProjectCreateFlow.copyChats) {
                            closeAppModal('appModalPostCreatePurpose');
                            postProjectCreateFlow.step = 'purpose_done';
                            advancePostProjectCreateFlow();
                            return;
                        }
                        populatePostCreatePurposeSourceSelect().then(function() {
                            var select = document.getElementById('postCreatePurposeSource');
                            if (!select || select.options.length === 0) {
                                showSnackbar('No other projects are available to copy from.', 'info');
                                return;
                            }
                            var questionsBlock = document.getElementById('postCreateCopyQuestionsBlock');
                            var sourceBlock = document.getElementById('postCreatePurposeSelectBlock');
                            var proceedBtn = document.getElementById('postCreatePurposeProceedBtn');
                            var blankRow = document.getElementById('postCreateBlankPurposeRow');
                            if (questionsBlock) questionsBlock.style.display = 'none';
                            if (sourceBlock) sourceBlock.style.display = 'grid';
                            if (copyContinueBtn) copyContinueBtn.style.display = 'none';
                            if (proceedBtn) proceedBtn.style.display = '';
                            if (blankRow) {
                                blankRow.style.display = postProjectCreateFlow.copyPurposes ? '' : 'none';
                            }
                        });
                    });
                }
                if (purposeProceed) {
                    purposeProceed.addEventListener('click', function() {
                        var select = document.getElementById('postCreatePurposeSource');
                        var fromId = select ? parseInt(select.value, 10) : 0;
                        if (!fromId) {
                            showSnackbar('Select a source project.', 'error');
                            return;
                        }
                        readPostCreateCopyAnswers();
                        var toId = postProjectCreateFlow.projectId;
                        purposeProceed.disabled = true;
                        var chain = Promise.resolve();
                        if (postProjectCreateFlow.copyPurposes) {
                            chain = chain.then(function() {
                                return postJson({
                                    action: 'copy_project_purposes',
                                    from_project_id: fromId,
                                    to_project_id: toId,
                                    include_blank_purposes: postProjectCreateFlow.overwriteBlankPurposes ? 1 : 0
                                }).then(function(d) {
                                    if (!d || !d.success) {
                                        throw new Error((d && d.error) || 'Could not copy purposes.');
                                    }
                                    var matched = parseInt(d.matched || 0, 10) || 0;
                                    showSnackbar('Copied purposes for ' + matched + ' vendor(s).', 'success');
                                });
                            });
                        }
                        if (postProjectCreateFlow.copyChats) {
                            chain = chain.then(function() {
                                return postJson({
                                    action: 'copy_project_chats',
                                    from_project_id: fromId,
                                    to_project_id: toId
                                }).then(function(d) {
                                    if (!d || !d.success) {
                                        throw new Error((d && d.error) || 'Could not copy chats.');
                                    }
                                    var msgs = parseInt(d.copied_messages || 0, 10) || 0;
                                    var vendors = parseInt(d.matched_vendors || 0, 10) || 0;
                                    showSnackbar('Copied ' + msgs + ' chat message(s) across ' + vendors + ' vendor(s).', 'success');
                                });
                            });
                        }
                        chain.then(function() {
                            return loadCalculatorData();
                        }).then(function() {
                            closeAppModal('appModalPostCreatePurpose');
                            postProjectCreateFlow.step = 'purpose_done';
                            advancePostProjectCreateFlow();
                        }).catch(function(err) {
                            showSnackbar(err && err.message ? err.message : 'Copy failed.', 'error');
                        }).finally(function() {
                            purposeProceed.disabled = false;
                        });
                    });
                }
                var inviteNo = document.getElementById('postCreateInviteNoBtn');
                var inviteYes = document.getElementById('postCreateInviteYesBtn');
                if (inviteNo) {
                    inviteNo.addEventListener('click', function() {
                        closeAppModal('appModalPostCreateInvite');
                        endPostProjectCreateFlow();
                    });
                }
                if (inviteYes) {
                    inviteYes.addEventListener('click', function() {
                        closeAppModal('appModalPostCreateInvite');
                        postProjectCreateFlow.step = 'invite_open';
                        openAppModal('appModalMembersInvite');
                    });
                }
            }

            function submitProjectWizardForm() {
                const form = document.getElementById('projectWizardForm');
                if (!form) return;
                const previousActiveProjectId = currentActiveProjectId || 0;
                const dataMode = (document.querySelector('input[name="projectWizardDataMode"]:checked') || {}).value || 'upload_after';
                const projectName = (document.getElementById('projectWizardName') || {}).value || '';
                const payload = {
                    action: 'project_create',
                    project_name: projectName,
                    start_date: (document.getElementById('projectWizardStartDate') || {}).value || '',
                    end_date: '',
                    member_ids: [],
                    copy_from_active: (dataMode === 'copy_from_active' ? 1 : 0),
                    source_project_id: (dataMode === 'copy_from_active' ? (currentActiveProjectId || 0) : 0),
                };
                postJson(payload)
                    .then(function(d) {
                        if (!d || !d.success) {
                            showSnackbar((d && d.error) || 'Could not create project.', 'error');
                            return;
                        }
                        showSnackbar('Project created.', 'success');
                        var mainAppContainer = document.querySelector('.container');
                        if (mainAppContainer) mainAppContainer.classList.remove('project-onboarding-hidden');
                        closeAppModal('appModalProjectWizard');
                        var loadPromise = loadProjectsIntoMenu().then(function() {
                            var calc = loadCalculatorData();
                            return calc || Promise.resolve();
                        });
                        loadPromise.then(function() {
                            if (canCreateProjects) {
                                startPostProjectCreateFlow({
                                    projectId: d.project_id,
                                    projectName: projectName,
                                    dataMode: dataMode,
                                    previousActiveProjectId: previousActiveProjectId
                                });
                            }
                        });
                    })
                    .catch(function() {
                        showSnackbar('Could not create project.', 'error');
                    });
            }
            function resetAppModalPosition(modal) {
                if (!modal) return;
                modal.style.position = '';
                modal.style.left = '';
                modal.style.top = '';
                modal.style.width = '';
                modal.style.margin = '';
                modal.style.transform = '';
                modal.style.maxHeight = '';
            }
            function openAppModal(overlay) {
                if (!overlay) return;
                if (typeof overlay === 'string') {
                    overlay = document.getElementById(overlay);
                }
                if (!overlay || !overlay.querySelector) return;
                var modal = overlay.querySelector('.app-modal');
                resetAppModalPosition(modal);
                overlay.classList.add('is-open');
                overlay.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                var focusable = overlay.querySelector('button, [href], input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])');
                if (focusable) focusable.focus();
            }
            function closeAppModal(overlay) {
                if (!overlay) return;
                if (typeof overlay === 'string') {
                    overlay = document.getElementById(overlay);
                }
                if (!overlay || !overlay.querySelector) return;
                var modal = overlay.querySelector('.app-modal');
                resetAppModalPosition(modal);
                overlay.classList.remove('is-open');
                overlay.setAttribute('aria-hidden', 'true');
                if (overlay.id === 'appModalVendorChat') {
                    activeVendorChatItemId = 0;
                    activeVendorChatVendorName = '';
                    vendorChatLastSignature = '';
                    if (vendorChatPollTimer) {
                        clearInterval(vendorChatPollTimer);
                        vendorChatPollTimer = null;
                    }
                }
                if (overlay.id === 'appModalCancelGuidance') {
                    activeCancelGuidanceItemId = 0;
                    activeCancelGuidanceVendorName = '';
                }
                if (overlay.id === 'appModalCsvAccounts') {
                    if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import' && !postProjectCreateFlow.importCompleted) {
                        advancePostProjectCreateFlow();
                    }
                    pendingCsvFile = null;
                }
                if (overlay.id === 'appModalMembersInvite' && postProjectCreateFlow.active && postProjectCreateFlow.step === 'invite_open') {
                    endPostProjectCreateFlow();
                }
                if (!document.querySelector('.app-modal-overlay.is-open')) {
                    document.body.style.overflow = '';
                }
            }
            var pendingCsvFile = null;
            function updateCsvAccountSelectionStatus() {
                var list = document.getElementById('csvAccountList');
                var status = document.getElementById('csvAccountSelectionStatus');
                var importBtn = document.getElementById('csvAccountImportBtn');
                if (!list) return;
                var boxes = list.querySelectorAll('input[type="checkbox"]');
                var checked = 0;
                var txnTotal = 0;
                boxes.forEach(function(cb) {
                    if (cb.checked) {
                        checked++;
                        txnTotal += parseInt(cb.getAttribute('data-txn-count') || '0', 10) || 0;
                    }
                });
                if (status) {
                    status.textContent = checked > 0
                        ? (checked + ' account(s), ' + txnTotal + ' transaction(s)')
                        : 'No accounts selected';
                }
                if (importBtn) importBtn.disabled = checked === 0;
            }
            function renderCsvAccountList(accounts) {
                var list = document.getElementById('csvAccountList');
                if (!list) return;
                list.innerHTML = '';
                (accounts || []).forEach(function(acct, idx) {
                    var name = (acct && acct.name) ? String(acct.name) : '';
                    var count = parseInt((acct && acct.transaction_count) || 0, 10) || 0;
                    var row = document.createElement('div');
                    row.className = 'csv-account-row';
                    var id = 'csvAcct_' + idx;
                    row.innerHTML =
                        '<input type="checkbox" id="' + id + '" value="" data-txn-count="0">' +
                        '<label for="' + id + '">' + aiEscapeHtml(name) +
                        ' <span class="csv-account-count">(' + count + ')</span></label>';
                    var cb = row.querySelector('input');
                    cb.value = name;
                    cb.setAttribute('data-txn-count', String(count));
                    cb.addEventListener('change', updateCsvAccountSelectionStatus);
                    list.appendChild(row);
                });
                updateCsvAccountSelectionStatus();
            }
            function runCsvImport(file, selectedAccounts) {
                if (!file) {
                    showSnackbar('No file to import', 'error');
                    if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import') {
                        advancePostProjectCreateFlow();
                    }
                    return Promise.resolve();
                }
                var fd = new FormData();
                fd.append('action', 'import_vendor_csv');
                fd.append('csv_file', file);
                if (selectedAccounts && selectedAccounts.length > 0) {
                    fd.append('selected_accounts', JSON.stringify(selectedAccounts));
                }
                return fetch(window.location.href, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success) {
                            var rawCount = parseInt(d.raw_inserted || 0, 10) || 0;
                            showSnackbar('Imported ' + (d.inserted || 0) + ' vendor(s), ' + rawCount + ' raw transactions', 'success');
                            var flowActive = postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import';
                            if (flowActive) {
                                postProjectCreateFlow.importCompleted = true;
                            }
                            var loadP = loadCalculatorData();
                            if (flowActive) {
                                return (loadP || Promise.resolve()).then(function() {
                                    advancePostProjectCreateFlow();
                                });
                            }
                        } else {
                            showSnackbar(d.error || 'Import failed', 'error');
                            if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import') {
                                advancePostProjectCreateFlow();
                            }
                        }
                    })
                    .catch(function() {
                        showSnackbar('Import failed', 'error');
                        if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import') {
                            advancePostProjectCreateFlow();
                        }
                    });
            }
            function handleCsvFileSelected(file, inputEl) {
                if (!file) {
                    if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import') {
                        advancePostProjectCreateFlow();
                    }
                    return;
                }
                if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload') {
                    postProjectCreateFlow.step = 'upload_waiting_import';
                }
                var fd = new FormData();
                fd.append('action', 'preview_csv_import');
                fd.append('csv_file', file);
                fetch(window.location.href, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d.success) {
                            showSnackbar(d.error || 'Could not read CSV', 'error');
                            if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import') {
                                advancePostProjectCreateFlow();
                            }
                            return;
                        }
                        if (d.format === 'vendor') {
                            return runCsvImport(file, null);
                        }
                        if (d.format === 'account') {
                            pendingCsvFile = file;
                            renderCsvAccountList(d.accounts || []);
                            openAppModal('appModalCsvAccounts');
                            return;
                        }
                        showSnackbar('Unrecognized CSV format', 'error');
                        if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import') {
                            advancePostProjectCreateFlow();
                        }
                    })
                    .catch(function() {
                        showSnackbar('Could not read CSV', 'error');
                        if (postProjectCreateFlow.active && postProjectCreateFlow.step === 'upload_waiting_import') {
                            advancePostProjectCreateFlow();
                        }
                    })
                    .finally(function() {
                        if (inputEl) inputEl.value = '';
                    });
            }
            function initCsvImportUi() {
                var csvIn = document.getElementById('csvImportInput');
                var csvBtn = document.getElementById('appImportCsvBtn');
                if (csvBtn && csvIn && !csvBtn.dataset.csvBound) {
                    csvBtn.dataset.csvBound = '1';
                    csvBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        csvIn.click();
                    });
                }
                if (csvIn && !csvIn.dataset.csvBound) {
                    csvIn.dataset.csvBound = '1';
                    csvIn.addEventListener('change', function() {
                        handleCsvFileSelected(this.files[0], this);
                    });
                }
                var csvModal = document.getElementById('appModalCsvAccounts');
                if (!csvModal || csvModal.dataset.csvBound) {
                    return;
                }
                csvModal.dataset.csvBound = '1';
                var selectAllBtn = document.getElementById('csvAccountSelectAllBtn');
                var clearBtn = document.getElementById('csvAccountClearBtn');
                var importBtn = document.getElementById('csvAccountImportBtn');
                var list = document.getElementById('csvAccountList');
                if (selectAllBtn && list) {
                    selectAllBtn.addEventListener('click', function() {
                        list.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = true; });
                        updateCsvAccountSelectionStatus();
                    });
                }
                if (clearBtn && list) {
                    clearBtn.addEventListener('click', function() {
                        list.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });
                        updateCsvAccountSelectionStatus();
                    });
                }
                if (importBtn) {
                    importBtn.addEventListener('click', function() {
                        if (!pendingCsvFile || !list) return;
                        var selected = [];
                        list.querySelectorAll('input[type="checkbox"]:checked').forEach(function(cb) {
                            if (cb.value) selected.push(cb.value);
                        });
                        if (selected.length === 0) {
                            showSnackbar('Select at least one account', 'error');
                            return;
                        }
                        setButtonLoading(importBtn, true, 'Importing…');
                        runCsvImport(pendingCsvFile, selected).then(function() {
                            pendingCsvFile = null;
                            closeAppModal(document.getElementById('appModalCsvAccounts'));
                        }).finally(function() {
                            setButtonLoading(importBtn, false);
                            updateCsvAccountSelectionStatus();
                        });
                    });
                }
            }
            function aiEscapeHtml(s) {
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }
            function loadVendorRawDataModal(vendorName) {
                var overlay = document.getElementById('appModalVendorRaw');
                var title = document.getElementById('appModalVendorRawTitle');
                var body = document.getElementById('vendorRawBody');
                if (!overlay || !title || !body) return;
                title.textContent = 'Raw Data - ' + vendorName;
                body.innerHTML = '<p>Loading transaction history...</p>';
                openAppModal(overlay);
                var fd = new FormData();
                fd.append('action', 'load_vendor_raw_data');
                fd.append('vendor_name', vendorName);
                fetch(window.location.href, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d.success) {
                            body.innerHTML = '<p>' + aiEscapeHtml(d.error || 'Could not load raw data.') + '</p>';
                            return;
                        }
                        var rows = Array.isArray(d.transactions) ? d.transactions : [];
                        if (!rows.length) {
                            body.innerHTML = '<p>No raw transactions found for this vendor yet.</p>';
                            return;
                        }
                        var html = '<div class="vendor-raw-results"><table><thead><tr>'
                            + '<th>Date</th><th class="amount-col">Amount</th><th>Transaction Type</th><th>Account</th><th>Memo/Description</th>'
                            + '</tr></thead><tbody>';
                        rows.forEach(function(row) {
                            var date = aiEscapeHtml(String(row.transaction_date || ''));
                            var amount = aiEscapeHtml(formatMoneyInteger(row.amount || 0));
                            var type = aiEscapeHtml(String(row.transaction_type || ''));
                            var account = aiEscapeHtml(String(row.account || ''));
                            var memo = aiEscapeHtml(String(row.memo || ''));
                            html += '<tr><td>' + date + '</td><td class="amount-col">' + amount + '</td><td>' + type + '</td><td>' + account + '</td><td>' + memo + '</td></tr>';
                        });
                        html += '</tbody></table></div>';
                        body.innerHTML = html;
                    })
                    .catch(function() {
                        body.innerHTML = '<p>Could not load raw data.</p>';
                    });
            }
            function attachModalDrag(overlay) {
                var modal = overlay.querySelector('.app-modal');
                var header = overlay.querySelector('.app-modal-header');
                if (!modal || !header) return;
                var closeBtn = modal.querySelector('.app-modal-close');
                var dragging = false;
                var startX, startY, origLeft, origTop;

                function clientXY(e) {
                    if (e.touches && e.touches.length) return { x: e.touches[0].clientX, y: e.touches[0].clientY };
                    return { x: e.clientX, y: e.clientY };
                }
                function onStart(e) {
                    if (e.target === closeBtn || (closeBtn && closeBtn.contains(e.target))) return;
                    var rect = modal.getBoundingClientRect();
                    modal.style.position = 'fixed';
                    modal.style.left = rect.left + 'px';
                    modal.style.top = rect.top + 'px';
                    modal.style.width = rect.width + 'px';
                    modal.style.margin = '0';
                    modal.style.transform = 'none';
                    modal.style.maxHeight = 'min(90vh, 720px)';
                    var xy = clientXY(e);
                    startX = xy.x;
                    startY = xy.y;
                    origLeft = rect.left;
                    origTop = rect.top;
                    dragging = true;
                    e.preventDefault();
                }
                function onMove(e) {
                    if (!dragging) return;
                    var xy = clientXY(e);
                    var nw = modal.offsetWidth;
                    var nh = modal.offsetHeight;
                    var nl = origLeft + (xy.x - startX);
                    var nt = origTop + (xy.y - startY);
                    nl = Math.max(8, Math.min(nl, window.innerWidth - nw - 8));
                    nt = Math.max(8, Math.min(nt, window.innerHeight - nh - 8));
                    modal.style.left = nl + 'px';
                    modal.style.top = nt + 'px';
                    e.preventDefault();
                }
                function onEnd() {
                    dragging = false;
                }
                header.addEventListener('mousedown', onStart);
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onEnd);
                header.addEventListener('touchstart', onStart, { passive: false });
                document.addEventListener('touchmove', onMove, { passive: false });
                document.addEventListener('touchend', onEnd);
            }
            function initAppModals() {
                document.querySelectorAll('[data-open-modal]').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var id = btn.getAttribute('data-open-modal');
                        var el = id ? document.getElementById(id) : null;
                        if (el) openAppModal(el);
                    });
                });
                document.querySelectorAll('.app-modal-overlay').forEach(function(overlay) {
                    attachModalDrag(overlay);
                    var modal = overlay.querySelector('.app-modal');
                    if (modal) {
                        modal.addEventListener('click', function(e) { e.stopPropagation(); });
                    }
                    overlay.addEventListener('click', function(e) {
                        if (e.target !== overlay) return;
                        if (overlay.classList.contains('post-create-flow-modal')) return;
                        closeAppModal(overlay);
                    });
                    overlay.querySelectorAll('.app-modal-close').forEach(function(b) {
                        b.addEventListener('click', function() { closeAppModal(overlay); });
                    });
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key !== 'Escape') return;
                    document.querySelectorAll('.app-modal-overlay.is-open').forEach(function(ov) {
                        if (postProjectCreateFlow.active && ov.classList.contains('post-create-flow-modal')) {
                            if (ov.id === 'appModalPostCreateUpload') {
                                closeAppModal(ov);
                                advancePostProjectCreateFlow();
                            } else if (ov.id === 'appModalPostCreatePurpose') {
                                closeAppModal(ov);
                                postProjectCreateFlow.step = 'purpose_done';
                                advancePostProjectCreateFlow();
                            } else if (ov.id === 'appModalPostCreateInvite') {
                                closeAppModal(ov);
                                endPostProjectCreateFlow();
                            }
                            return;
                        }
                        closeAppModal(ov);
                    });
                });
            }
            function initNavSubmenus() {
                var submenuItems = Array.from(document.querySelectorAll('.app-nav-item.has-submenu'));
                if (!submenuItems.length) return;
                function closeMenu(item) {
                    var trigger = item.querySelector('.app-nav-link[aria-controls]');
                    item.classList.remove('is-open');
                    if (trigger) trigger.setAttribute('aria-expanded', 'false');
                }
                function closeAll() {
                    submenuItems.forEach(closeMenu);
                }
                submenuItems.forEach(function(item) {
                    var trigger = item.querySelector('.app-nav-link[aria-controls]');
                    if (!trigger) return;
                    trigger.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var willOpen = !item.classList.contains('is-open');
                        closeAll();
                        if (willOpen) {
                            item.classList.add('is-open');
                            trigger.setAttribute('aria-expanded', 'true');
                        }
                    });
                    item.querySelectorAll('.app-submenu a, .app-submenu button').forEach(function(action) {
                        action.addEventListener('click', function() { closeAll(); });
                    });
                });
                document.addEventListener('click', function(e) {
                    if (!submenuItems.some(function(item) { return item.contains(e.target); })) closeAll();
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') closeAll();
                });
                initCsvImportUi();
            }
            const TEAM_MEMBERS = <?php echo $team_members_json; ?>;
            const IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
            const CURRENT_USER_ID = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;
            let pendingBulkActionData = null;
            let activeVendorChatItemId = 0;
            let activeVendorChatVendorName = '';
            let vendorChatPollTimer = null;
            let vendorChatRequestInFlight = false;
            let vendorChatLastSignature = '';
            let vendorChatUnreadPollTimer = null;
            var VENDOR_CHAT_UNREAD_POLL_MS = 35000;

            function setVendorChatUnreadBadge(chatBtn, count) {
                if (!chatBtn) return;
                var nRaw = Number(count);
                var n = (isFinite(nRaw) && nRaw > 0) ? Math.floor(nRaw) : 0;
                chatBtn.setAttribute('data-chat-unread', String(n));
                var badge = chatBtn.querySelector('.vendor-chat-unread-badge');
                var vendorHint = (chatBtn.getAttribute('data-vendor-name') || '').trim();
                var baseAria = chatBtn.disabled
                    ? 'Open vendor chat'
                    : ('Open vendor chat for ' + (vendorHint || 'this vendor'));
                if (chatBtn.disabled || n <= 0) {
                    chatBtn.title = chatBtn.disabled
                        ? 'Save this row first to enable chat'
                        : ('Open vendor chat for ' + (vendorHint || 'this vendor'));
                    chatBtn.setAttribute('aria-label', baseAria);
                    if (badge) {
                        badge.classList.remove('is-visible');
                        badge.hidden = true;
                    }
                    scheduleVendorTablePaginationIfChatUnreadFilter();
                    return;
                }
                chatBtn.title = vendorHint ? ('Unread notes for ' + vendorHint + ' (' + n + ')') : ('Unread vendor chat (' + n + ')');
                chatBtn.setAttribute('aria-label', baseAria + '; ' + n + ' unread');
                if (badge) {
                    badge.hidden = false;
                    badge.classList.add('is-visible');
                }
                scheduleVendorTablePaginationIfChatUnreadFilter();
            }

            function applySparseVendorChatUnreadCounts(counts) {
                var map = (counts && typeof counts === 'object') ? counts : {};
                document.querySelectorAll('#calculatorRows tr .vendor-chat-btn').forEach(function(btn) {
                    var vid = parseInt(btn.getAttribute('data-vendor-item-id'), 10) || 0;
                    if (vid <= 0) {
                        setVendorChatUnreadBadge(btn, 0);
                        return;
                    }
                    var c = parseInt(map[String(vid)], 10) || 0;
                    setVendorChatUnreadBadge(btn, c);
                });
            }

            function pollVendorChatUnreadCounts() {
                if (document.visibilityState === 'hidden') return;
                var fd = new FormData();
                fd.append('action', 'vendor_chat_unread_counts');
                fetch(window.location.href, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d || !d.success || !d.counts) return;
                        applySparseVendorChatUnreadCounts(d.counts);
                    })
                    .catch(function() {});
            }

            let activeCancelGuidanceItemId = 0;
            let activeCancelGuidanceVendorName = '';
            const VENDOR_PAGE_SIZE = 20;
            let vendorCurrentPage = 1;
            let vendorCurrentFilter = 'all';

            const VENDOR_FILTER_COLS = ['frequency', 'manager', 'visibility', 'status', 'chat_unread'];
            let vendorColumnFilters = {
                frequency: new Set(),
                manager: new Set(),
                visibility: new Set(),
                status: new Set(),
                chat_unread: new Set(),
            };

            let vendorChatUnreadFilterReflowScheduled = false;
            function scheduleVendorTablePaginationIfChatUnreadFilter() {
                if (!vendorColumnFilters.chat_unread || vendorColumnFilters.chat_unread.size === 0) return;
                if (vendorChatUnreadFilterReflowScheduled) return;
                vendorChatUnreadFilterReflowScheduled = true;
                window.requestAnimationFrame(function() {
                    vendorChatUnreadFilterReflowScheduled = false;
                    applyVendorTablePagination(vendorCurrentPage);
                });
            }

            /** Labels for checkbox list in frequency column header filter (values match `.frequency-select` options). */
            const FREQUENCY_FILTER_LABELS = {
                '': '(Not set)',
                weekly: 'Weekly',
                monthly: 'Monthly',
                quarterly: 'Quarterly',
                semi_annual: 'Semi-annual',
                annually: 'Annually',
                one_off: 'One-off',
            };
            const FREQUENCY_FILTER_ORDER = ['', 'weekly', 'monthly', 'quarterly', 'semi_annual', 'annually', 'one_off'];

            const STATUS_COLUMN_FILTER_LABELS = {
                pending: 'Pending',
                question: 'Question',
                unknown: 'Unknown',
                keep: 'Keep',
                mark_for_cancellation: 'Mark for Cancellation',
                cancelled: 'Cancelled',
            };

            function formatVendorsSelectedLabel(count) {
                const n = Number(count) || 0;
                return n === 1 ? '1 vendor selected' : (n + ' vendors selected');
            }

            function getSelectedVendorRows() {
                const filteredSet = new Set(getFilteredVendorRows(vendorCurrentFilter));
                return Array.from(document.querySelectorAll('#calculatorRows tr')).filter(function(row) {
                    if (!filteredSet.has(row)) return false;
                    const cb = row.querySelector('.row-select-checkbox');
                    return !!(cb && cb.checked);
                });
            }

            function updateSelectAllCheckboxState() {
                const selectAll = document.getElementById('selectAllVendors');
                if (!selectAll) return;
                const filtered = getFilteredVendorRows(vendorCurrentFilter);
                if (!filtered.length) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                    return;
                }
                let selectedCount = 0;
                filtered.forEach(function(row) {
                    const cb = row.querySelector('.row-select-checkbox');
                    if (cb && cb.checked) selectedCount++;
                });
                selectAll.checked = selectedCount === filtered.length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < filtered.length;
            }

            function setAllRowSelection(checked) {
                getFilteredVendorRows(vendorCurrentFilter).forEach(function(row) {
                    const cb = row.querySelector('.row-select-checkbox');
                    if (cb) cb.checked = !!checked;
                });
                updateSelectAllCheckboxState();
            }

            function clearRowSelection() {
                setAllRowSelection(false);
            }

            function updateBulkActionFields() {
                const actionSel = document.getElementById('bulkActionType');
                const frequencyWrap = document.getElementById('bulkFrequencyWrap');
                const statusWrap = document.getElementById('bulkStatusWrap');
                const visibilityWrap = document.getElementById('bulkVisibilityWrap');
                const managerWrap = document.getElementById('bulkManagerWrap');
                if (!actionSel || !frequencyWrap || !statusWrap || !visibilityWrap || !managerWrap) return;
                const action = actionSel.value;
                frequencyWrap.style.display = action === 'frequency' ? '' : 'none';
                statusWrap.style.display = action === 'status' ? '' : 'none';
                visibilityWrap.style.display = action === 'visibility' ? '' : 'none';
                managerWrap.style.display = action === 'manager' ? '' : 'none';
            }

            function getBulkActionPayload() {
                const actionSel = document.getElementById('bulkActionType');
                if (!actionSel) return null;
                const action = actionSel.value;
                if (!action) return null;
                if (action === 'frequency') {
                    const freq = document.getElementById('bulkFrequencyValue');
                    if (!freq || !freq.value) return null;
                    return { action: action, value: freq.value, label: 'Update frequency to ' + freq.value };
                }
                if (action === 'status') {
                    const st = document.getElementById('bulkStatusValue');
                    if (!st || !st.value) return null;
                    const opt = st.options[st.selectedIndex];
                    const statusLabel = opt ? opt.text : st.value;
                    return { action: action, value: st.value, label: 'Update status to ' + statusLabel };
                }
                if (action === 'visibility') {
                    const vis = document.getElementById('bulkVisibilityValue');
                    if (!vis || !vis.value) return null;
                    return { action: action, value: vis.value, label: 'Update visibility to ' + vis.value };
                }
                if (action === 'manager') {
                    const mgr = document.getElementById('bulkManagerValue');
                    if (!mgr) return null;
                    const val = mgr.value;
                    const label = val === ''
                        ? 'Clear manager assignment'
                        : ('Update manager to ' + (mgr.options[mgr.selectedIndex] ? mgr.options[mgr.selectedIndex].text : val));
                    return { action: action, value: val, label: label };
                }
                if (action === 'delete') {
                    return { action: action, value: null, label: 'Delete selected vendor rows' };
                }
                return null;
            }

            function closeBulkModalById(id) {
                const overlay = id ? document.getElementById(id) : null;
                if (overlay) closeAppModal(overlay);
            }

            function openBulkConfirmModal(payload, selectedCount) {
                const overlay = document.getElementById('appModalBulkConfirm');
                const body = document.getElementById('bulkConfirmDetails');
                if (!overlay || !body) return;
                body.innerHTML = ''
                    + '<div class="bulk-confirm-summary">'
                    + '<div><strong>Action:</strong> ' + payload.label + '</div>'
                    + '<div><strong>' + formatVendorsSelectedLabel(selectedCount) + '</strong></div>'
                    + '</div>';
                pendingBulkActionData = payload;
                openAppModal(overlay);
            }

            function applyBulkAction(payload) {
                if (!payload) return;
                const selectedRows = getSelectedVendorRows();
                if (!selectedRows.length) {
                    showSnackbar('Please select at least one vendor row.', 'error');
                    return;
                }
                clearTimeout(saveTimeout);
                if (payload.action === 'delete') {
                    selectedRows.forEach(function(row) { row.remove(); });
                } else if (payload.action === 'frequency') {
                    selectedRows.forEach(function(row) {
                        const frequencySelect = row.querySelector('.frequency-select');
                        if (frequencySelect) {
                            frequencySelect.value = payload.value;
                            calculateAnnualCost({ target: frequencySelect });
                        }
                    });
                } else if (payload.action === 'visibility') {
                    selectedRows.forEach(function(row) {
                        const visSel = row.querySelector('.visibility-select');
                        if (visSel && !visSel.disabled) visSel.value = payload.value;
                    });
                } else if (payload.action === 'manager') {
                    selectedRows.forEach(function(row) {
                        const mgrSel = row.querySelector('.manager-select');
                        if (mgrSel && !mgrSel.disabled) {
                            mgrSel.value = payload.value;
                            syncMemberStatusEditability(row);
                        }
                    });
                } else if (payload.action === 'status') {
                    selectedRows.forEach(function(row) {
                        const statusSelect = row.querySelector('.row-status-select');
                        if (!statusSelect || statusSelect.disabled) return;
                        statusSelect.value = payload.value;
                        syncRowDeadlineVisibility(row);
                        syncRowCancellationGuidanceVisibility(row);
                        if (payload.value === 'mark_for_cancellation') {
                            const dlIn = row.querySelector('.cancel-deadline-input');
                            if (dlIn && !dlIn.disabled && !dlIn.value) {
                                dlIn.value = getEndOfCurrentMonthIsoDate();
                            }
                        }
                    });
                    const filterSelect = document.getElementById('reportFilter');
                    if (filterSelect) {
                        filterTableRows(filterSelect.value);
                    }
                }
                applyVendorTablePagination(vendorCurrentPage);
                calculateAnnualSavings();
                calculateConfirmedSavings();
                const bulkItemsSnapshot = collectCostCalculatorItemsFromDom();
                clearRowSelection();
                saveCalculatorData({ silent: true, items: bulkItemsSnapshot }).then(function(saveResult) {
                    if (saveResult && saveResult.success) {
                        showSnackbar(formatVendorsSelectedLabel(selectedRows.length) + '. Bulk action applied.', 'success');
                    } else if (saveResult && saveResult.aborted) {
                        showSnackbar('Save was interrupted by a newer change. Check vendor data and save again if needed.', 'error');
                    } else {
                        showSnackbar((saveResult && saveResult.error) || 'Could not save changes. Wait for the table to finish loading, then try again.', 'error');
                    }
                });
            }

            function managerOptionsHtml(selectedId) {
                let o = '<option value="">—</option>';
                (TEAM_MEMBERS || []).forEach(function(m) {
                    const id = String(m.id);
                    const lab = (m.username || m.email || '').replace(/</g, '');
                    const sel = (selectedId && String(selectedId) === id) ? ' selected' : '';
                    o += '<option value="' + id + '"' + sel + '>' + lab + '</option>';
                });
                return o;
            }

            function syncBulkManagerOptions() {
                const bulkManager = document.getElementById('bulkManagerValue');
                if (!bulkManager) return;
                bulkManager.innerHTML = managerOptionsHtml('');
            }
            
            function addCalculatorRow() {
                rowCount++;
                const tbody = document.getElementById('calculatorRows');
                const row = document.createElement('tr');
                row.setAttribute('data-row-id', rowCount);
                
                row.innerHTML = `
                    <td class="select-row-cell">
                        <input type="checkbox" class="row-select-checkbox" aria-label="Select vendor row" />
                    </td>
                    <td class="item-number">${rowCount}</td>
                    <td class="vendor-name">
                        <input type="hidden" class="row-db-id" value="" />
                        <div class="vendor-cell-wrap">
                            <input type="text" name="vendor[]" placeholder="Enter vendor name" />
                            <button type="button" class="vendor-raw-btn" disabled title="View imported raw transaction history" aria-label="View imported raw transaction history">
                                <span class="material-symbols-outlined vendor-raw-icon" aria-hidden="true">format_list_bulleted</span>
                            </button>
                        </div>
                    </td>
                    <td class="cost-per-period">
                        <input type="text" name="cost[]" class="cost-input" placeholder="$0" data-row="${rowCount}" />
                    </td>
                    <td class="frequency">
                        <select name="frequency[]" class="frequency-select" data-row="${rowCount}">
                            <option value="">Select</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi_annual">Semi-annual</option>
                            <option value="annually">Annually</option>
                            <option value="one_off">One-off</option>
                        </select>
                    </td>
                    <td class="annual-cost">
                        <span class="annual-cost-display" data-row="${rowCount}">$0</span>
                    </td>
                    <td class="manager-col">
                        <select class="manager-select" data-row="${rowCount}" ${IS_ADMIN ? '' : 'disabled'}>
                            ${managerOptionsHtml(IS_ADMIN ? '' : String(CURRENT_USER_ID))}
                        </select>
                    </td>
                    <td class="visibility-col">
                        <select class="visibility-select" data-row="${rowCount}" ${IS_ADMIN ? '' : 'disabled'}>
                            <option value="public">Public</option>
                            <option value="confidential">Confidential</option>
                        </select>
                    </td>
                    <td class="row-status">
                        <div class="row-status-top">
                            <select name="status[]" class="row-status-select" data-row="${rowCount}">
                                <option value="pending">Pending</option>
                                <option value="question">Question</option>
                                <option value="unknown">Unknown</option>
                                <option value="keep">Keep</option>
                                <option value="mark_for_cancellation">Mark for Cancellation</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <button type="button" class="cancel-guidance-btn" aria-label="Show cancellation guidance" title="Show AI cancellation guidance for this vendor" hidden>
                                <span class="material-symbols-outlined cancel-guidance-icon" aria-hidden="true">rule</span>
                            </button>
                        </div>
                        <input type="date"
                               class="cancel-deadline-input row-status-deadline"
                               data-row="${rowCount}"
                               aria-label="Cancellation deadline"
                               title="Cancellation deadline"
                               hidden />
                    </td>
                    <td class="notes">
                        <textarea name="notes[]" class="purpose-textarea" rows="2" placeholder="Purpose of subscription"></textarea>
                        <input type="hidden" class="last-payment-input" data-row="${rowCount}" />
                    </td>
                    <td class="vendor-chat-col">
                        <button type="button" class="vendor-chat-btn" disabled title="Save this row first to enable chat" aria-label="Open vendor chat">
                            <span class="vendor-chat-unread-badge" hidden aria-hidden="true"></span>
                            <span class="material-symbols-outlined vendor-chat-icon" aria-hidden="true">chat</span>
                        </button>
                    </td>
                `;
                
                tbody.appendChild(row);
                
                // Attach event listeners (with auto-save)
                attachRowListenersWithSave(row);
                updateVendorDrilldownState(row);
                syncRowDeadlineVisibility(row);
                syncRowCancellationGuidanceVisibility(row);
                syncMemberStatusEditability(row);
                const rowCheckbox = row.querySelector('.row-select-checkbox');
                if (rowCheckbox) rowCheckbox.addEventListener('change', updateSelectAllCheckboxState);

                applyVendorTablePagination(vendorCurrentPage);
            }
            
            function attachRowListeners(row) {
                const costInput = row.querySelector('.cost-input');
                const frequencySelect = row.querySelector('.frequency-select');
                const statusSelect = row.querySelector('.row-status-select');

                costInput.addEventListener('input', calculateAnnualCost);
                frequencySelect.addEventListener('change', calculateAnnualCost);
                if (statusSelect) {
                    statusSelect.addEventListener('change', function() {
                        calculateAnnualSavings();
                        calculateConfirmedSavings();
                    });
                }
            }
            
            function calculateAnnualCost(event) {
                const row = event.target.closest('tr');
                const costInput = row.querySelector('.cost-input');
                const frequencySelect = row.querySelector('.frequency-select');
                const annualCostDisplay = row.querySelector('.annual-cost-display');
                
                const cost = parseMoneyInput(costInput.value);
                const frequency = frequencySelect.value;
                
                let multiplier = 0;
                switch(frequency) {
                    case 'weekly': multiplier = 52; break;
                    case 'monthly': multiplier = 12; break;
                    case 'quarterly': multiplier = 4; break;
                    case 'semi_annual': multiplier = 2; break;
                    case 'annually': multiplier = 1; break;
                    case 'one_off': multiplier = 1; break;
                }
                
                const annualCost = cost * multiplier;
                annualCostDisplay.textContent = formatMoneyInteger(annualCost);
                
                calculateAnnualSavings();
            }
            
            function calculateAnnualSavings() {
                const rows = document.querySelectorAll('#calculatorRows tr');
                let totalSavings = 0;

                rows.forEach(row => {
                    const status = getRowStatus(row);
                    const annualCostText = row.querySelector('.annual-cost-display').textContent;
                    const annualCost = parseFloat(annualCostText.replace(/[^0-9.-]/g, '')) || 0;

                    if (status === 'mark_for_cancellation' || status === 'cancelled') {
                        totalSavings += annualCost;
                    }
                });

                document.getElementById('potentialSavings').textContent = formatCurrency(totalSavings);
                calculateConfirmedSavings();
            }

            function calculateConfirmedSavings() {
                const rows = document.querySelectorAll('#calculatorRows tr');
                let totalConfirmedSavings = 0;

                rows.forEach(row => {
                    const status = getRowStatus(row);
                    const annualCostText = row.querySelector('.annual-cost-display').textContent;
                    const annualCost = parseFloat(annualCostText.replace(/[^0-9.-]/g, '')) || 0;

                    if (status === 'cancelled') {
                        totalConfirmedSavings += annualCost;
                    }
                });

                document.getElementById('confirmedSavings').textContent = formatCurrency(totalConfirmedSavings);
            }
            
            function formatMoneyInteger(amount) {
                var n = Math.round(Number(amount) || 0);
                var sign = n < 0 ? '-' : '';
                var abs = Math.abs(n);
                return sign + '$' + String(abs).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            }

            function parseMoneyInput(value) {
                return parseFloat(String(value || '').replace(/[^0-9.-]/g, '')) || 0;
            }

            function formatCostInputValue(amount) {
                return formatMoneyInteger(amount);
            }

            function formatCurrency(amount) {
                return '$' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            }
            
            function updateRowNumbers() {
                const rows = document.querySelectorAll('#calculatorRows tr');
                rows.forEach((row, index) => {
                    row.querySelector('.item-number').textContent = index + 1;
                    const rowId = index + 1;
                    row.setAttribute('data-row-id', rowId);
                    const inputs = row.querySelectorAll('[data-row]');
                    inputs.forEach(input => input.setAttribute('data-row', rowId));
                });
                rowCount = rows.length;
                updateSelectAllCheckboxState();
            }
            
            // Format cost input as currency on blur
            document.addEventListener('blur', function(e) {
                if (e.target.classList.contains('cost-input')) {
                    const numValue = parseMoneyInput(e.target.value);
                    if (numValue) {
                        e.target.value = formatCostInputValue(numValue);
                        calculateAnnualCost(e);
                    }
                }
            }, true);
            
            // Auto-save function (debounced — fast refresh could miss this; see flushSaveOnLeave + immediate save on Status change)
            let saveTimeout;
            /** True while repopulating rows from the server — avoids save races (partial DELETE/INSERT) from synthetic events. */
            let calculatorLoadInProgress = false;
            /** Serialize saves: server replaces all rows per request; overlapping saves must not complete out of order. */
            let saveQueue = Promise.resolve();
            /** Abort stale in-flight save HTTP requests so an older payload cannot commit after a newer save was sent (bulk vs autosave races). */
            let calculatorSaveFetchController = null;
            const AI_PURPOSE_PREFIX = <?php echo json_encode(\CostSavings\VendorPurposeService::AI_PURPOSE_UI_PREFIX, JSON_UNESCAPED_UNICODE); ?>;
            function syncPurposeAiBadgeDataset(textarea, val) {
                if (!textarea) return;
                if (val && typeof val === 'string' && val.startsWith(AI_PURPOSE_PREFIX)) {
                    textarea.dataset.aiPurposeBadge = '1';
                } else {
                    delete textarea.dataset.aiPurposeBadge;
                }
            }
            function autoSave() {
                console.log('autoSave called');
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(function() {
                    console.log('Auto-saving after timeout');
                    saveCalculatorData();
                }, 1000); // Save 1 second after last change
            }
            
            function saveCalculatorData(options) {
                const opts = (options && typeof options === 'object') ? options : {};
                const keepalive = !!opts.keepalive;
                const silent = !!opts.silent || keepalive;
                const itemsPayload = Array.isArray(opts.items) ? opts.items : null;
                if (calculatorLoadInProgress && !keepalive) {
                    return Promise.resolve({ success: false, error: 'Still loading vendor data; save skipped.' });
                }
                saveQueue = saveQueue.then(function () {
                    return performSaveCalculatorData(keepalive, silent, itemsPayload);
                }).catch(function (e) {
                    console.error('Calculator save queue:', e);
                    return { success: false, error: String(e && e.message ? e.message : e) };
                });
                return saveQueue;
            }
            
            const VALID_ROW_STATUSES = ['pending', 'question', 'unknown', 'keep', 'mark_for_cancellation', 'cancelled'];

            function normalizeVendorFilter(filterValue) {
                const filter = String(filterValue || 'all');
                if (filter === 'all' || VALID_ROW_STATUSES.indexOf(filter) !== -1) {
                    return filter;
                }
                return 'all';
            }

            function getFilteredVendorRows(filterValue) {
                const filter = normalizeVendorFilter(filterValue);
                return Array.from(document.querySelectorAll('#calculatorRows tr')).filter(function(row) {
                    const passesReport = filter === 'all' || getRowStatus(row) === filter;
                    return passesReport && rowMatchesColumnFilters(row);
                });
            }

            function renderVendorPagination(totalFilteredRows, totalPages) {
                const wrapper = document.getElementById('vendorPagination');
                const prevBtn = document.getElementById('vendorPaginationPrev');
                const nextBtn = document.getElementById('vendorPaginationNext');
                const status = document.getElementById('vendorPaginationStatus');
                if (!wrapper || !prevBtn || !nextBtn || !status) return;

                const shouldShow = totalFilteredRows > VENDOR_PAGE_SIZE;
                wrapper.hidden = !shouldShow;
                if (!shouldShow) {
                    prevBtn.disabled = true;
                    nextBtn.disabled = true;
                    status.textContent = 'Page 1 of 1';
                    return;
                }

                status.textContent = 'Page ' + vendorCurrentPage + ' of ' + totalPages;
                prevBtn.disabled = vendorCurrentPage <= 1;
                nextBtn.disabled = vendorCurrentPage >= totalPages;
            }

            function applyVendorTablePagination(page, filterValue) {
                if (typeof filterValue !== 'undefined') {
                    vendorCurrentFilter = normalizeVendorFilter(filterValue);
                }
                if (typeof page === 'number' && isFinite(page)) {
                    vendorCurrentPage = page;
                }

                const allRows = Array.from(document.querySelectorAll('#calculatorRows tr'));
                const filteredRows = getFilteredVendorRows(vendorCurrentFilter);
                const totalPages = Math.max(1, Math.ceil(filteredRows.length / VENDOR_PAGE_SIZE));
                vendorCurrentPage = Math.min(totalPages, Math.max(1, vendorCurrentPage));

                const startIdx = (vendorCurrentPage - 1) * VENDOR_PAGE_SIZE;
                const endIdx = startIdx + VENDOR_PAGE_SIZE;
                const pageRows = filteredRows.slice(startIdx, endIdx);
                const visibleSet = new Set(pageRows);
                const filteredSet = new Set(filteredRows);

                allRows.forEach(function(row) {
                    if (!filteredSet.has(row)) {
                        const cb = row.querySelector('.row-select-checkbox');
                        if (cb) cb.checked = false;
                    }
                });

                allRows.forEach(function(row) {
                    row.style.display = visibleSet.has(row) ? '' : 'none';
                });

                updateRowNumbers();
                renderVendorPagination(filteredRows.length, totalPages);
                updateSelectAllCheckboxState();
            }

            function goToVendorPage(page) {
                applyVendorTablePagination(page);
            }

            function normalizeStatusToken(value) {
                if (value === undefined || value === null) return 'pending';
                let s = String(value).trim().toLowerCase().replace(/[\s-]+/g, '_');
                if (VALID_ROW_STATUSES.indexOf(s) !== -1) return s;
                if (s === '1') return 'keep';
                if (s === '0' || s === 'cancel' || s === 'mark') return 'mark_for_cancellation';
                if (s === 'confirmed_cancelled' || s === 'cancelled_confirmed') return 'cancelled';
                return 'pending';
            }

            function getRowStatus(row) {
                const sel = row.querySelector('select.row-status-select');
                if (!sel) return 'pending';
                return normalizeStatusToken(sel.value);
            }

            function getRowFrequencyValue(row) {
                const sel = row.querySelector('.frequency-select');
                return sel ? sel.value : '';
            }

            function getRowManagerFilterKey(row) {
                const mgrSel = row.querySelector('.manager-select');
                const mgr = mgrSel && mgrSel.value ? String(mgrSel.value) : '';
                return mgr === '' ? '__none__' : mgr;
            }

            function rowMatchesColumnFilters(row) {
                if (vendorColumnFilters.frequency.size) {
                    if (!vendorColumnFilters.frequency.has(getRowFrequencyValue(row))) return false;
                }
                if (vendorColumnFilters.manager.size) {
                    if (!vendorColumnFilters.manager.has(getRowManagerFilterKey(row))) return false;
                }
                if (vendorColumnFilters.visibility.size) {
                    const visSel = row.querySelector('.visibility-select');
                    const vis = visSel ? visSel.value : 'public';
                    if (!vendorColumnFilters.visibility.has(vis)) return false;
                }
                if (vendorColumnFilters.status.size) {
                    if (!vendorColumnFilters.status.has(getRowStatus(row))) return false;
                }
                if (vendorColumnFilters.chat_unread.size) {
                    if (!vendorColumnFilters.chat_unread.has('unread')) return false;
                    const chatBtn = row.querySelector('.vendor-chat-btn');
                    const nRaw = chatBtn ? Number(chatBtn.getAttribute('data-chat-unread')) : 0;
                    const n = (isFinite(nRaw) && nRaw > 0) ? Math.floor(nRaw) : 0;
                    if (n <= 0) return false;
                }
                return true;
            }

            function closeAllVendorColumnFilterDropdowns() {
                document.querySelectorAll('.vendor-col-filter-dropdown').forEach(function(dd) {
                    dd.hidden = true;
                });
                document.querySelectorAll('.vendor-col-filter-btn').forEach(function(b) {
                    b.setAttribute('aria-expanded', 'false');
                });
            }

            function updateVendorFilterButtonActive(col) {
                const btn = document.querySelector('.vendor-col-filter-btn[data-vendor-filter="' + col + '"]');
                if (btn) btn.classList.toggle('is-active', vendorColumnFilters[col].size > 0);
            }

            function populateVendorColumnFilterList(col) {
                const dd = document.querySelector('.vendor-col-filter-dropdown[data-vendor-filter="' + col + '"]');
                if (!dd) return;
                const listEl = dd.querySelector('.vendor-col-filter-list');
                if (!listEl) return;
                listEl.innerHTML = '';
                const opts = [];
                if (col === 'frequency') {
                    FREQUENCY_FILTER_ORDER.forEach(function(v) {
                        opts.push({ value: v, label: FREQUENCY_FILTER_LABELS[v] || v });
                    });
                } else if (col === 'manager') {
                    opts.push({ value: '__none__', label: 'Unassigned' });
                    (TEAM_MEMBERS || []).forEach(function(m) {
                        const lab = (m.username || m.email || ('User ' + m.id)).replace(/</g, '');
                        opts.push({ value: String(m.id), label: lab });
                    });
                } else if (col === 'visibility') {
                    opts.push({ value: 'public', label: 'Public' });
                    opts.push({ value: 'confidential', label: 'Confidential' });
                } else if (col === 'status') {
                    VALID_ROW_STATUSES.forEach(function(s) {
                        opts.push({ value: s, label: STATUS_COLUMN_FILTER_LABELS[s] || s });
                    });
                } else if (col === 'chat_unread') {
                    opts.push({ value: 'unread', label: 'Unread' });
                }
                opts.forEach(function(opt) {
                    const labEl = document.createElement('label');
                    labEl.className = 'vendor-col-filter-option';
                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.value = opt.value;
                    cb.checked = vendorColumnFilters[col].has(opt.value);
                    labEl.appendChild(cb);
                    labEl.appendChild(document.createTextNode(opt.label));
                    listEl.appendChild(labEl);
                });
            }

            function initVendorColumnHeaderFilters() {
                document.addEventListener('click', function(e) {
                    if (e.target.closest('.th-with-filter')) return;
                    closeAllVendorColumnFilterDropdowns();
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') closeAllVendorColumnFilterDropdowns();
                });
                VENDOR_FILTER_COLS.forEach(function(col) {
                    const btn = document.querySelector('.vendor-col-filter-btn[data-vendor-filter="' + col + '"]');
                    const dd = document.querySelector('.vendor-col-filter-dropdown[data-vendor-filter="' + col + '"]');
                    const clearBtn = document.querySelector('.vendor-col-filter-clear[data-vendor-filter="' + col + '"]');
                    const listEl = dd ? dd.querySelector('.vendor-col-filter-list') : null;
                    if (!btn || !dd || !listEl) return;
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        const wasOpen = !dd.hidden;
                        closeAllVendorColumnFilterDropdowns();
                        if (!wasOpen) {
                            populateVendorColumnFilterList(col);
                            dd.hidden = false;
                            btn.setAttribute('aria-expanded', 'true');
                        }
                    });
                    listEl.addEventListener('change', function() {
                        vendorColumnFilters[col].clear();
                        listEl.querySelectorAll('input[type=checkbox]').forEach(function(c) {
                            if (c.checked) vendorColumnFilters[col].add(c.value);
                        });
                        applyVendorTablePagination(vendorCurrentPage);
                        updateVendorFilterButtonActive(col);
                    });
                    if (clearBtn) {
                        clearBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            vendorColumnFilters[col].clear();
                            listEl.querySelectorAll('input[type=checkbox]').forEach(function(c) {
                                c.checked = false;
                            });
                            applyVendorTablePagination(vendorCurrentPage);
                            updateVendorFilterButtonActive(col);
                        });
                    }
                    updateVendorFilterButtonActive(col);
                });
            }

            function syncRowDeadlineVisibility(row) {
                if (!row) return;
                const dl = row.querySelector('.cancel-deadline-input');
                if (!dl) return;
                dl.hidden = (getRowStatus(row) !== 'mark_for_cancellation');
            }

            function syncRowCancellationGuidanceVisibility(row) {
                if (!row) return;
                const btn = row.querySelector('.cancel-guidance-btn');
                if (!btn) return;
                btn.hidden = (getRowStatus(row) !== 'mark_for_cancellation');
            }

            /** For org members: assigned rows are editable only by that manager; unassigned rows are editable by any member who can see them (bulk triage). */
            function syncMemberStatusEditability(row) {
                if (!row) return;
                const statusSel = row.querySelector('.row-status-select');
                const dl = row.querySelector('.cancel-deadline-input');
                const guide = row.querySelector('.cancel-guidance-btn');
                const lockedTip = 'Only the assigned manager can change status';
                if (IS_ADMIN) {
                    if (statusSel) {
                        statusSel.disabled = false;
                        statusSel.removeAttribute('title');
                    }
                    if (dl) {
                        dl.disabled = false;
                        dl.removeAttribute('title');
                    }
                    if (guide) {
                        guide.disabled = false;
                        guide.removeAttribute('title');
                    }
                    return;
                }
                const rowIdEl = row.querySelector('.row-db-id');
                const dbId = rowIdEl && rowIdEl.value ? parseInt(rowIdEl.value, 10) : 0;
                const mgrSel = row.querySelector('.manager-select');
                const managerVal = mgrSel ? String(mgrSel.value || '').trim() : '';
                const managerIdParsed = managerVal !== '' ? parseInt(managerVal, 10) : NaN;
                const hasAssignedManager = managerVal !== '' && !isNaN(managerIdParsed);
                const unassignedManager = !hasAssignedManager;
                const isMine = (dbId === 0) || unassignedManager || (hasAssignedManager && managerIdParsed === CURRENT_USER_ID);
                const locked = !isMine;
                if (statusSel) {
                    statusSel.disabled = locked;
                    if (locked) {
                        statusSel.title = lockedTip;
                    } else {
                        statusSel.removeAttribute('title');
                    }
                }
                if (dl) {
                    dl.disabled = locked;
                    if (locked) {
                        dl.title = lockedTip;
                    } else {
                        dl.removeAttribute('title');
                    }
                }
                if (guide) {
                    guide.disabled = locked;
                    if (locked) {
                        guide.title = lockedTip;
                    } else {
                        guide.removeAttribute('title');
                    }
                }
            }

            function statusToLegacyCancelKeep(status) {
                return (status === 'mark_for_cancellation' || status === 'cancelled') ? 'Cancel' : 'Keep';
            }

            function statusToLegacyCancelledStatus(status) {
                return status === 'cancelled' ? 1 : 0;
            }

            function getEndOfCurrentMonthIsoDate() {
                const now = new Date();
                const monthEnd = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                const year = String(monthEnd.getFullYear());
                const month = String(monthEnd.getMonth() + 1).padStart(2, '0');
                const day = String(monthEnd.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            }
            
            function collectCostCalculatorItemsFromDom() {
                const rows = document.querySelectorAll('#calculatorRows tr');
                const items = [];
                rows.forEach(function(row) {
                    const vendorInput = row.querySelector('input[name="vendor[]"]');
                    const costInput = row.querySelector('.cost-input');
                    const frequencySelect = row.querySelector('.frequency-select');
                    const notesTextarea = row.querySelector('textarea.purpose-textarea') || row.querySelector('textarea[name="notes[]"]');
                    const annualCostDisplay = row.querySelector('.annual-cost-display');
                    const rowIdEl = row.querySelector('.row-db-id');
                    const mgrSel = row.querySelector('.manager-select');
                    const visSel = row.querySelector('.visibility-select');
                    const deadlineIn = row.querySelector('.cancel-deadline-input');
                    const lastPayIn = row.querySelector('.last-payment-input');

                    const vendorName = vendorInput ? vendorInput.value.trim() : '';
                    const costPerPeriod = costInput ? parseFloat(costInput.value.replace(/[^0-9.-]/g, '')) || 0 : 0;
                    const frequency = frequencySelect ? frequencySelect.value : '';
                    const status = getRowStatus(row);
                    const cancelKeep = statusToLegacyCancelKeep(status);
                    const cancelledStatusInt = statusToLegacyCancelledStatus(status);
                    const notes = notesTextarea ? notesTextarea.value.trim() : '';
                    const annualCost = annualCostDisplay ? parseFloat(annualCostDisplay.textContent.replace(/[^0-9.-]/g, '')) || 0 : 0;
                    const idVal = rowIdEl && rowIdEl.value ? parseInt(rowIdEl.value, 10) : null;
                    const managerRaw = mgrSel ? String(mgrSel.value || '').trim() : '';
                    const managerParsed = managerRaw !== '' ? parseInt(managerRaw, 10) : NaN;
                    const managerOk = managerRaw !== '' && !isNaN(managerParsed) && managerParsed > 0;
                    const visibility = visSel ? visSel.value : 'public';
                    const cancelDl = deadlineIn && deadlineIn.value ? deadlineIn.value : '';
                    const lastPay = lastPayIn && lastPayIn.value ? lastPayIn.value : '';

                    if (vendorName || costPerPeriod > 0 || status !== 'pending' || notes || idVal) {
                        const o = {
                            vendor_name: vendorName,
                            cost_per_period: costPerPeriod,
                            frequency: frequency,
                            annual_cost: annualCost,
                            status: status,
                            cancel_keep: cancelKeep,
                            cancelKeep: cancelKeep,
                            cancelled_status: cancelledStatusInt,
                            notes: notes,
                            purpose_of_subscription: notes,
                            visibility: visibility,
                            cancellation_deadline: cancelDl,
                            last_payment_date: lastPay,
                            manager_user_id: managerOk ? managerParsed : null
                        };
                        if (idVal) { o.id = idVal; }
                        items.push(o);
                    }
                });
                return items;
            }

            function performSaveCalculatorData(keepalive, silent, prebuiltItems) {
                const items = Array.isArray(prebuiltItems) ? prebuiltItems : collectCostCalculatorItemsFromDom();
                const payload = { action: 'save_cost_calculator', items: items };

                if (!keepalive && calculatorSaveFetchController) {
                    try {
                        calculatorSaveFetchController.abort();
                    } catch (abortErr) {}
                }
                const fetchController = (!keepalive && typeof AbortController !== 'undefined') ? new AbortController() : null;
                if (fetchController) {
                    calculatorSaveFetchController = fetchController;
                }

                const fetchOpts = {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                    body: JSON.stringify(payload),
                    keepalive: keepalive
                };
                if (fetchController) {
                    fetchOpts.signal = fetchController.signal;
                }

                return fetch(window.location.href, fetchOpts)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data && data.success) {
                        console.log('Data saved successfully');
                        return { success: true };
                    }
                    console.error('Error saving data:', data && data.error);
                    if (!silent) {
                        alert('Error saving data: ' + ((data && data.error) || 'Unknown error'));
                    }
                    return { success: false, error: (data && data.error) || 'Unknown error' };
                })
                .catch(error => {
                    if (error && error.name === 'AbortError') {
                        return { success: false, aborted: true, error: 'Save superseded by a newer request' };
                    }
                    console.error('Error saving:', error);
                    if (!silent) {
                        alert('Error saving data. Please check console for details.');
                    }
                    return { success: false, error: error.message || 'Network error' };
                });
            }
            
            function flushSaveOnLeave() {
                clearTimeout(saveTimeout);
                saveCalculatorData({ keepalive: true, silent: true });
            }
            
            function loadCalculatorData() {
                const formData = new FormData();
                formData.append('action', 'load_cost_calculator');
                
                return fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    calculatorLoadInProgress = true;
                    try {
                        if (data.success && data.items && data.items.length > 0) {
                            // Clear existing rows
                            document.getElementById('calculatorRows').innerHTML = '';
                            rowCount = 0;
                            
                            // Load saved items (do not dispatch change on the status select — that triggered immediate saves mid-load and corrupted rows)
                            data.items.forEach(item => {
                                addCalculatorRow();
                                const lastRow = document.querySelector('#calculatorRows tr:last-child');
                                if (lastRow) {
                                    const rowIdEl = lastRow.querySelector('.row-db-id');
                                    if (rowIdEl && item.id) rowIdEl.value = String(item.id);
                                    const vendorInput = lastRow.querySelector('input[name="vendor[]"]');
                                    const costInput = lastRow.querySelector('.cost-input');
                                    const frequencySelect = lastRow.querySelector('.frequency-select');
                                    const statusSelect = lastRow.querySelector('.row-status-select');
                                    const notesTextarea = lastRow.querySelector('textarea.purpose-textarea');
                                    const mgr = lastRow.querySelector('.manager-select');
                                    const vis = lastRow.querySelector('.visibility-select');
                                    const dl = lastRow.querySelector('.cancel-deadline-input');
                                    const lp = lastRow.querySelector('.last-payment-input');
                                    
                                    if (vendorInput) vendorInput.value = item.vendor_name || '';
                                    updateVendorDrilldownState(lastRow);
                                    var uch = typeof item.vendor_chat_unread !== 'undefined' ? item.vendor_chat_unread : 0;
                                    var cbtn = lastRow.querySelector('.vendor-chat-btn');
                                    if (cbtn) setVendorChatUnreadBadge(cbtn, uch);

                                    if (costInput) costInput.value = item.cost_per_period > 0 ? formatCostInputValue(item.cost_per_period) : '';
                                    if (frequencySelect) frequencySelect.value = item.frequency || '';
                                    if (mgr) {
                                        const mid = item.manager_user_id ? String(item.manager_user_id) : '';
                                        mgr.innerHTML = managerOptionsHtml(mid);
                                    }
                                    if (vis) vis.value = (item.visibility === 'confidential') ? 'confidential' : 'public';
                                    if (dl && item.cancellation_deadline) {
                                        const d = String(item.cancellation_deadline).substring(0, 10);
                                        dl.value = d;
                                    }
                                    if (lp && item.last_payment_date) {
                                        const d = String(item.last_payment_date).substring(0, 10);
                                        lp.value = d;
                                    }
                                    
                                    if (statusSelect) {
                                        let resolved = 'pending';
                                        if (item.status) {
                                            resolved = normalizeStatusToken(item.status);
                                        } else {
                                            // Backward-compat: derive from legacy fields if server didn't send `status`.
                                            const legacyCk = item.cancel_keep ? String(item.cancel_keep).trim() : 'Keep';
                                            const legacyConfirmed = (item.cancelled_status == 1 || item.cancelled_status === true);
                                            if (legacyConfirmed) {
                                                resolved = 'cancelled';
                                            } else if (legacyCk === 'Cancel' || legacyCk === '0') {
                                                resolved = 'mark_for_cancellation';
                                            } else {
                                                resolved = 'keep';
                                            }
                                        }
                                        statusSelect.value = resolved;
                                        if (statusSelect.value !== resolved) {
                                            statusSelect.value = 'pending';
                                        }
                                    }
                                    syncRowDeadlineVisibility(lastRow);
                                    syncRowCancellationGuidanceVisibility(lastRow);
                                    syncMemberStatusEditability(lastRow);

                                    if (notesTextarea) {
                                        var pVal = item.purpose_of_subscription || item.notes || '';
                                        notesTextarea.value = pVal;
                                        syncPurposeAiBadgeDataset(notesTextarea, pVal);
                                    }
                                    
                                    if (costInput && frequencySelect) {
                                        const event = new Event('input');
                                        costInput.dispatchEvent(event);
                                    }
                                }
                            });
                            
                            calculateAnnualSavings();
                            calculateConfirmedSavings();
                            clearRowSelection();
                            const filterSelect = document.getElementById('reportFilter');
                            applyVendorTablePagination(1, filterSelect ? filterSelect.value : 'all');
                        } else if (data.success) {
                            // Empty project (or zero visible rows): must clear DOM or previous project rows stay visible.
                            document.getElementById('calculatorRows').innerHTML = '';
                            rowCount = 0;
                            addCalculatorRow();
                            calculateAnnualSavings();
                            calculateConfirmedSavings();
                            clearRowSelection();
                            const filterSelectEmpty = document.getElementById('reportFilter');
                            applyVendorTablePagination(1, filterSelectEmpty ? filterSelectEmpty.value : 'all');
                        } else {
                            addCalculatorRow();
                            clearRowSelection();
                            const filterSelect = document.getElementById('reportFilter');
                            applyVendorTablePagination(1, filterSelect ? filterSelect.value : 'all');
                        }
                    } finally {
                        calculatorLoadInProgress = false;
                    }
                })
                .catch(error => {
                    console.error('Error loading data:', error);
                    calculatorLoadInProgress = true;
                    try {
                        addCalculatorRow();
                        clearRowSelection();
                        const filterSelect = document.getElementById('reportFilter');
                        applyVendorTablePagination(1, filterSelect ? filterSelect.value : 'all');
                    } finally {
                        calculatorLoadInProgress = false;
                    }
                });
            }
            
            function attachRowListenersWithSave(row) {
                const costInput = row.querySelector('.cost-input');
                const frequencySelect = row.querySelector('.frequency-select');
                const statusSelect = row.querySelector('.row-status-select');
                const vendorInput = row.querySelector('input[name="vendor[]"]');
                const rawBtn = row.querySelector('.vendor-raw-btn');
                const notesTextarea = row.querySelector('textarea.purpose-textarea');
                const mgrSel = row.querySelector('.manager-select');
                const visSel = row.querySelector('.visibility-select');
                const dlIn = row.querySelector('.cancel-deadline-input');
                const lpIn = row.querySelector('.last-payment-input');

                if (costInput) {
                    costInput.addEventListener('input', function(e) {
                        calculateAnnualCost(e);
                        autoSave();
                    });
                    costInput.addEventListener('blur', autoSave);
                }

                if (frequencySelect) {
                    frequencySelect.addEventListener('change', function(e) {
                        calculateAnnualCost(e);
                        autoSave();
                        if (!calculatorLoadInProgress) {
                            applyVendorTablePagination(vendorCurrentPage);
                        }
                    });
                }

                if (statusSelect) {
                    statusSelect.addEventListener('change', function() {
                        syncRowDeadlineVisibility(row);
                        syncRowCancellationGuidanceVisibility(row);
                        const newStatus = getRowStatus(row);
                        if (newStatus === 'mark_for_cancellation' && dlIn && !dlIn.value) {
                            dlIn.value = getEndOfCurrentMonthIsoDate();
                        }
                        calculateAnnualSavings();
                        calculateConfirmedSavings();
                        clearTimeout(saveTimeout);
                        saveCalculatorData();
                        const filterSelect = document.getElementById('reportFilter');
                        if (filterSelect) {
                            filterTableRows(filterSelect.value);
                        }
                    });
                }
                
                if (vendorInput) {
                    const syncVendor = function() { updateVendorDrilldownState(row); };
                    vendorInput.addEventListener('input', syncVendor);
                    vendorInput.addEventListener('blur', function() {
                        syncVendor();
                        autoSave();
                    });
                }
                if (rawBtn) {
                    rawBtn.addEventListener('click', function() {
                        const v = ((rawBtn.getAttribute('data-vendor-name') || '').trim() || (vendorInput ? vendorInput.value.trim() : ''));
                        if (!v) {
                            showSnackbar('Enter a vendor name first.', 'error');
                            return;
                        }
                        loadVendorRawDataModal(v);
                    });
                }
                
                if (notesTextarea) {
                    notesTextarea.addEventListener('input', function() {
                        if (this.dataset.aiPurposeBadge === '1') {
                            if (this.value.startsWith(AI_PURPOSE_PREFIX)) {
                                this.value = this.value.slice(AI_PURPOSE_PREFIX.length);
                            }
                            delete this.dataset.aiPurposeBadge;
                        }
                    });
                    notesTextarea.addEventListener('blur', autoSave);
                }
                [mgrSel, visSel].forEach(function(el) {
                    if (el) el.addEventListener('change', function() {
                        autoSave();
                        if (!calculatorLoadInProgress) {
                            applyVendorTablePagination(vendorCurrentPage);
                        }
                    });
                });
                [dlIn, lpIn].forEach(function(el) {
                    if (el) el.addEventListener('change', autoSave);
                });
            }

            function updateVendorDrilldownState(row) {
                if (!row) return;
                const idEl = row.querySelector('.row-db-id');
                const vendorInput = row.querySelector('input[name="vendor[]"]');
                const rawBtn = row.querySelector('.vendor-raw-btn');
                const chatBtn = row.querySelector('.vendor-chat-btn');
                const cancelGuideBtn = row.querySelector('.cancel-guidance-btn');
                if (!rawBtn || !vendorInput) return;
                const idVal = idEl && idEl.value ? parseInt(idEl.value, 10) : 0;
                const vendorName = vendorInput.value ? vendorInput.value.trim() : '';
                const enabled = vendorName !== '';
                // Keep button clickable so users always get immediate feedback.
                rawBtn.disabled = false;
                rawBtn.setAttribute('data-vendor-name', enabled ? vendorName : '');
                rawBtn.title = enabled ? ('View raw transactions for ' + vendorName) : 'Enter a vendor name first';
                if (chatBtn) {
                    const canChat = idVal > 0;
                    chatBtn.disabled = !canChat;
                    chatBtn.setAttribute('data-vendor-item-id', canChat ? String(idVal) : '');
                    chatBtn.setAttribute('data-vendor-name', enabled ? vendorName : '');
                    chatBtn.title = canChat
                        ? ('Open vendor chat for ' + (vendorName || 'this vendor'))
                        : 'Save this row first to enable chat';
                    if (!canChat) {
                        setVendorChatUnreadBadge(chatBtn, 0);
                    } else {
                        var prevUnread = parseInt(chatBtn.getAttribute('data-chat-unread'), 10);
                        if (!isFinite(prevUnread)) prevUnread = 0;
                        setVendorChatUnreadBadge(chatBtn, prevUnread);
                    }
                }
                if (cancelGuideBtn) {
                    cancelGuideBtn.setAttribute('data-vendor-item-id', idVal > 0 ? String(idVal) : '');
                    cancelGuideBtn.setAttribute('data-vendor-name', enabled ? vendorName : '');
                }
            }
            
            // Override attachRowListeners to use the new version with auto-save
            const originalAttachRowListeners = attachRowListeners;
            attachRowListeners = attachRowListenersWithSave;
            
            // Filter table rows based on selected filter
            function filterTableRows(filterValue) {
                applyVendorTablePagination(1, filterValue);
            }

            function setPurposeColumnState(isVisible) {
                const grid = document.getElementById('costCalculatorGrid');
                const toggleBtn = document.getElementById('togglePurposeColumnBtn');
                if (!grid) return;
                grid.classList.toggle('notes-collapsed', !isVisible);
                if (toggleBtn) {
                    toggleBtn.textContent = isVisible ? 'Hide Purpose' : 'Show Purpose';
                    toggleBtn.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
                }
            }

            function initPurposeColumnToggle() {
                const toggleBtn = document.getElementById('togglePurposeColumnBtn');
                if (!toggleBtn) return;
                const prefKey = 'costCalculatorPurposeVisible';
                const savedPref = localStorage.getItem(prefKey);
                const defaultVisible = window.innerWidth > 1100;
                const isVisible = savedPref === null ? defaultVisible : savedPref === '1';
                setPurposeColumnState(isVisible);

                toggleBtn.addEventListener('click', function() {
                    const grid = document.getElementById('costCalculatorGrid');
                    if (!grid) return;
                    const nextVisible = grid.classList.contains('notes-collapsed');
                    setPurposeColumnState(nextVisible);
                    localStorage.setItem(prefKey, nextVisible ? '1' : '0');
                });
            }
            
            // Initialize: Load data on page load; flush debounced saves before refresh/navigation
            document.addEventListener('DOMContentLoaded', function() {
                initAppModals();
                var orgRoleInfoBtn = document.getElementById('orgRoleInfoBtn');
                if (orgRoleInfoBtn) {
                    orgRoleInfoBtn.addEventListener('click', function() {
                        openAppModal('appModalOrgRolesInfo');
                    });
                }
                initPostProjectCreateFlow();
                initNavSubmenus();
                initPurposeColumnToggle();
                initVendorColumnHeaderFilters();
                loadProjectsIntoMenu();
                syncBulkManagerOptions();
                const projectSwitcher = document.getElementById('projectSwitcherSelect');
                if (projectSwitcher) {
                    const handleProjectSwitch = function() {
                        const nextProjectId = parseInt(this.value, 10) || 0;
                        if (!nextProjectId) return;
                        if (currentActiveProjectId && nextProjectId === currentActiveProjectId) return;
                        const selectedName = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : 'project';
                        postJson({ action: 'project_set_active', project_id: nextProjectId })
                            .then(function(d) {
                                if (!d || !d.success) {
                                    showSnackbar((d && d.error) || 'Could not switch project.', 'error');
                                    return;
                                }
                                currentActiveProjectId = nextProjectId;
                                updateActiveProjectHeader(selectedName);
                                loadCalculatorData();
                                showSnackbar('Switched to ' + selectedName, 'success');
                            })
                            .catch(function() {
                                showSnackbar('Could not switch project.', 'error');
                            });
                    };
                    // Some browsers commit selection on input; others on change.
                    projectSwitcher.addEventListener('input', handleProjectSwitch);
                    projectSwitcher.addEventListener('change', handleProjectSwitch);
                }
                const projectWizardForm = document.getElementById('projectWizardForm');
                if (projectWizardForm) {
                    const startDateInput = document.getElementById('projectWizardStartDate');
                    if (startDateInput && !startDateInput.value) {
                        startDateInput.value = new Date().toISOString().slice(0, 10);
                    }
                    projectWizardForm.addEventListener('submit', function(e) {
                        e.preventDefault();
                        submitProjectWizardForm();
                    });
                }
                const deleteProjectBtn = document.getElementById('appDeleteProjectBtn');
                if (deleteProjectBtn) {
                    deleteProjectBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        openDeleteProjectModal();
                    });
                }
                const deleteProjectConfirmInput = document.getElementById('deleteProjectConfirmInput');
                if (deleteProjectConfirmInput) {
                    deleteProjectConfirmInput.addEventListener('input', syncDeleteProjectConfirmState);
                }
                const deleteProjectSubmitBtn = document.getElementById('deleteProjectSubmitBtn');
                if (deleteProjectSubmitBtn) {
                    deleteProjectSubmitBtn.addEventListener('click', function() {
                        if (deleteProjectSubmitBtn.disabled || !deleteProjectTargetId) return;
                        deleteProjectSubmitBtn.disabled = true;
                        postJson({ action: 'project_delete', project_id: deleteProjectTargetId })
                            .then(function(d) {
                                if (!d || !d.success) {
                                    deleteProjectSubmitBtn.disabled = false;
                                    showSnackbar((d && d.error) || 'Could not delete project.', 'error');
                                    syncDeleteProjectConfirmState();
                                    return;
                                }
                                window.location.reload();
                            })
                            .catch(function() {
                                deleteProjectSubmitBtn.disabled = false;
                                showSnackbar('Could not delete project.', 'error');
                                syncDeleteProjectConfirmState();
                            });
                    });
                }
                const selectAll = document.getElementById('selectAllVendors');
                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        setAllRowSelection(selectAll.checked);
                        if (selectAll.checked) {
                            const n = getFilteredVendorRows(vendorCurrentFilter).length;
                            showSnackbar(formatVendorsSelectedLabel(n), 'success');
                        }
                    });
                }
                const bulkActionType = document.getElementById('bulkActionType');
                if (bulkActionType) {
                    bulkActionType.addEventListener('change', updateBulkActionFields);
                    updateBulkActionFields();
                }
                const bulkApplyBtn = document.getElementById('bulkActionsApplyBtn');
                if (bulkApplyBtn) {
                    bulkApplyBtn.addEventListener('click', function() {
                        const selectedCount = getSelectedVendorRows().length;
                        if (!selectedCount) {
                            showSnackbar('Please select at least one vendor row.', 'error');
                            return;
                        }
                        const payload = getBulkActionPayload();
                        if (!payload) {
                            showSnackbar('Please choose a bulk action and value.', 'error');
                            return;
                        }
                        closeBulkModalById('appModalBulkActions');
                        openBulkConfirmModal(payload, selectedCount);
                    });
                }
                const bulkConfirmBtn = document.getElementById('bulkConfirmProceedBtn');
                if (bulkConfirmBtn) {
                    bulkConfirmBtn.addEventListener('click', function() {
                        closeBulkModalById('appModalBulkConfirm');
                        applyBulkAction(pendingBulkActionData);
                        pendingBulkActionData = null;
                    });
                }
                const bulkConfirmCancelBtn = document.getElementById('bulkConfirmCancelBtn');
                if (bulkConfirmCancelBtn) {
                    bulkConfirmCancelBtn.addEventListener('click', function() {
                        pendingBulkActionData = null;
                        closeBulkModalById('appModalBulkConfirm');
                    });
                }
                const vendorPaginationPrev = document.getElementById('vendorPaginationPrev');
                if (vendorPaginationPrev) {
                    vendorPaginationPrev.addEventListener('click', function() {
                        goToVendorPage(vendorCurrentPage - 1);
                    });
                }
                const vendorPaginationNext = document.getElementById('vendorPaginationNext');
                if (vendorPaginationNext) {
                    vendorPaginationNext.addEventListener('click', function() {
                        goToVendorPage(vendorCurrentPage + 1);
                    });
                }
                const calculatorRowsEl = document.getElementById('calculatorRows');
                if (calculatorRowsEl) {
                    calculatorRowsEl.addEventListener('click', function(e) {
                        const rawBtn = e.target.closest('.vendor-raw-btn');
                        if (rawBtn) {
                            const row = rawBtn.closest('tr');
                            const vendorInput = row ? row.querySelector('input[name="vendor[]"]') : null;
                            const vendorName = ((rawBtn.getAttribute('data-vendor-name') || '').trim() || (vendorInput ? vendorInput.value.trim() : ''));
                            if (!vendorName) {
                                showSnackbar('Enter a vendor name first.', 'error');
                                return;
                            }
                            loadVendorRawDataModal(vendorName);
                            return;
                        }
                        const chatBtn = e.target.closest('.vendor-chat-btn');
                        if (chatBtn) {
                            const row = chatBtn.closest('tr');
                            if (!row) return;
                            openVendorChatModalForRow(row);
                            return;
                        }
                        const cancelGuideBtn = e.target.closest('.cancel-guidance-btn');
                        if (cancelGuideBtn) {
                            const row = cancelGuideBtn.closest('tr');
                            if (!row) return;
                            openCancelGuidanceModalForRow(row);
                        }
                    });
                }
                loadCalculatorData();
                vendorChatUnreadPollTimer = window.setInterval(pollVendorChatUnreadCounts, VENDOR_CHAT_UNREAD_POLL_MS);
                window.addEventListener('beforeunload', function() {
                    if (vendorChatUnreadPollTimer) {
                        clearInterval(vendorChatUnreadPollTimer);
                        vendorChatUnreadPollTimer = null;
                    }
                });
                initCsvImportUi();
                function aiEscapeHtml(s) {
                    var d = document.createElement('div');
                    d.textContent = s;
                    return d.innerHTML;
                }
                function formatVendorChatTimestamp(rawValue) {
                    var raw = String(rawValue || '').trim();
                    if (!raw) return '';
                    var normalized = raw.indexOf('T') !== -1 ? raw : raw.replace(' ', 'T');
                    var dt = new Date(normalized);
                    if (isNaN(dt.getTime())) return raw;
                    return dt.toLocaleString([], {
                        year: 'numeric',
                        month: 'short',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                    });
                }
                function appendVendorChatMessage(msg) {
                    var log = document.getElementById('vendorChatLog');
                    if (!log || !msg) return;
                    var row = document.createElement('div');
                    var mine = parseInt(msg.user_id || 0, 10) === CURRENT_USER_ID;
                    row.className = 'vendor-chat-row ' + (mine ? 'is-self' : 'is-other');
                    var bubble = document.createElement('div');
                    bubble.className = 'vendor-chat-bubble';
                    var author = document.createElement('div');
                    author.className = 'vendor-chat-author';
                    author.textContent = String(msg.username || 'User');
                    var body = document.createElement('div');
                    body.className = 'vendor-chat-text';
                    body.textContent = String(msg.message || '');
                    var stamp = document.createElement('div');
                    stamp.className = 'vendor-chat-time';
                    stamp.textContent = formatVendorChatTimestamp(msg.created_at);
                    bubble.appendChild(author);
                    bubble.appendChild(body);
                    bubble.appendChild(stamp);
                    row.appendChild(bubble);
                    log.appendChild(row);
                }
                function renderVendorChatMessages(messages) {
                    var log = document.getElementById('vendorChatLog');
                    if (!log) return;
                    log.innerHTML = '';
                    if (!Array.isArray(messages) || !messages.length) {
                        var empty = document.createElement('div');
                        empty.className = 'vendor-chat-empty';
                        empty.textContent = 'No notes yet for this vendor. Start the conversation by adding the first note.';
                        log.appendChild(empty);
                        return;
                    }
                    messages.forEach(function(msg) { appendVendorChatMessage(msg); });
                    log.scrollTop = log.scrollHeight;
                }
                function setVendorChatBusy(busy) {
                    var sendBtn = document.getElementById('vendorChatSendBtn');
                    var input = document.getElementById('vendorChatInput');
                    if (sendBtn) sendBtn.disabled = !!busy;
                    if (input) input.disabled = !!busy;
                }
                function isVendorChatModalOpen() {
                    var overlay = document.getElementById('appModalVendorChat');
                    return !!(overlay && overlay.classList.contains('is-open'));
                }
                function stopVendorChatPolling() {
                    if (vendorChatPollTimer) {
                        clearInterval(vendorChatPollTimer);
                        vendorChatPollTimer = null;
                    }
                }
                function startVendorChatPolling() {
                    stopVendorChatPolling();
                    if (!activeVendorChatItemId) return;
                    vendorChatPollTimer = window.setInterval(function() {
                        if (!activeVendorChatItemId || !isVendorChatModalOpen()) {
                            stopVendorChatPolling();
                            return;
                        }
                        if (vendorChatRequestInFlight) return;
                        loadVendorChatMessages(activeVendorChatItemId, { silent: true, preserveScroll: true });
                    }, 10000);
                }
                function loadVendorChatMessages(vendorItemId, options) {
                    options = options || {};
                    var title = document.getElementById('appModalVendorChatTitle');
                    var log = document.getElementById('vendorChatLog');
                    if (!vendorItemId || !log) return;
                    var isSilent = !!options.silent;
                    var preserveScroll = !!options.preserveScroll;
                    if (!isSilent) {
                        log.innerHTML = '<div class="vendor-chat-empty">Loading chat history...</div>';
                    }
                    var previousTop = log.scrollTop;
                    var wasNearBottom = (log.scrollHeight - log.scrollTop - log.clientHeight) < 24;
                    vendorChatRequestInFlight = true;
                    var fd = new FormData();
                    fd.append('action', 'load_vendor_chat_messages');
                    fd.append('vendor_item_id', String(vendorItemId));
                    fetch(window.location.href, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            vendorChatRequestInFlight = false;
                            if (d && d.success) {
                                var rows = document.querySelectorAll('.vendor-chat-btn[data-vendor-item-id="' + String(vendorItemId) + '"]');
                                rows.forEach(function(b) { setVendorChatUnreadBadge(b, 0); });
                            }
                            if (!d || !d.success) {
                                if (!isSilent) {
                                    renderVendorChatMessages([]);
                                    showSnackbar((d && d.error) || 'Could not load vendor chat.', 'error');
                                }
                                return;
                            }
                            if (!isVendorChatModalOpen() || activeVendorChatItemId !== vendorItemId) {
                                return;
                            }
                            if (title && d.vendor_name) {
                                title.textContent = 'Vendor Chat - ' + d.vendor_name;
                            }
                            var messages = Array.isArray(d.messages) ? d.messages : [];
                            var signature = messages.map(function(m) {
                                return String(m.id || '') + '|' + String(m.created_at || '');
                            }).join(',');
                            if (signature === vendorChatLastSignature) {
                                return;
                            }
                            vendorChatLastSignature = signature;
                            renderVendorChatMessages(messages);
                            if (preserveScroll && !wasNearBottom) {
                                log.scrollTop = previousTop;
                            }
                        })
                        .catch(function() {
                            vendorChatRequestInFlight = false;
                            if (!isSilent) {
                                renderVendorChatMessages([]);
                                showSnackbar('Could not load vendor chat.', 'error');
                            }
                        });
                }
                function openVendorChatModalForRow(row) {
                    if (!row) return;
                    var idEl = row.querySelector('.row-db-id');
                    var vendorInput = row.querySelector('input[name="vendor[]"]');
                    var vendorItemId = idEl && idEl.value ? parseInt(idEl.value, 10) : 0;
                    var vendorName = vendorInput ? vendorInput.value.trim() : '';
                    if (!vendorItemId) {
                        showSnackbar('Save this vendor row first, then open chat.', 'info');
                        return;
                    }
                    var overlay = document.getElementById('appModalVendorChat');
                    var title = document.getElementById('appModalVendorChatTitle');
                    var context = document.getElementById('vendorChatContextName');
                    var input = document.getElementById('vendorChatInput');
                    if (!overlay) return;
                    activeVendorChatItemId = vendorItemId;
                    activeVendorChatVendorName = vendorName || '';
                    if (title) title.textContent = 'Vendor Chat - ' + (vendorName || ('Row #' + vendorItemId));
                    if (context) context.textContent = vendorName || ('Vendor item #' + vendorItemId);
                    if (input) input.value = '';
                    vendorChatLastSignature = '';
                    openAppModal(overlay);
                    loadVendorChatMessages(vendorItemId);
                    startVendorChatPolling();
                    if (input) input.focus();
                }
                function sendVendorChatMessage() {
                    var input = document.getElementById('vendorChatInput');
                    if (!input) return;
                    var text = input.value.trim();
                    if (!activeVendorChatItemId) {
                        showSnackbar('No vendor row selected for chat.', 'error');
                        return;
                    }
                    if (!text) {
                        showSnackbar('Enter a message before sending.', 'error');
                        return;
                    }
                    setVendorChatBusy(true);
                    var fd = new FormData();
                    fd.append('action', 'add_vendor_chat_message');
                    fd.append('vendor_item_id', String(activeVendorChatItemId));
                    fd.append('message', text);
                    fetch(window.location.href, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            setVendorChatBusy(false);
                            if (!d || !d.success) {
                                showSnackbar((d && d.error) || 'Could not send chat message.', 'error');
                                return;
                            }
                            var sentVid = activeVendorChatItemId;
                            input.value = '';
                            if (sentVid) {
                                document.querySelectorAll('.vendor-chat-btn[data-vendor-item-id="' + String(sentVid) + '"]').forEach(function(b) {
                                    setVendorChatUnreadBadge(b, 0);
                                });
                            }
                            if (d.vendor_name) {
                                activeVendorChatVendorName = String(d.vendor_name);
                                var title = document.getElementById('appModalVendorChatTitle');
                                var context = document.getElementById('vendorChatContextName');
                                if (title) title.textContent = 'Vendor Chat - ' + activeVendorChatVendorName;
                                if (context) context.textContent = activeVendorChatVendorName;
                            }
                            if (d.message) {
                                var log = document.getElementById('vendorChatLog');
                                if (log && log.querySelector('.vendor-chat-empty')) {
                                    log.innerHTML = '';
                                }
                                appendVendorChatMessage(d.message);
                                vendorChatLastSignature = '';
                                log = document.getElementById('vendorChatLog');
                                if (log) log.scrollTop = log.scrollHeight;
                            } else {
                                loadVendorChatMessages(activeVendorChatItemId);
                            }
                        })
                        .catch(function() {
                            setVendorChatBusy(false);
                            showSnackbar('Could not send chat message.', 'error');
                        });
                }
                var vendorChatSendBtn = document.getElementById('vendorChatSendBtn');
                if (vendorChatSendBtn) {
                    vendorChatSendBtn.addEventListener('click', function() {
                        sendVendorChatMessage();
                    });
                }
                var vendorChatInput = document.getElementById('vendorChatInput');
                if (vendorChatInput) {
                    vendorChatInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            sendVendorChatMessage();
                        }
                    });
                }
                function setCancelGuidanceLoading(messageHtml) {
                    var body = document.getElementById('cancelGuidanceBody');
                    var retryBtn = document.getElementById('cancelGuidanceRetryBtn');
                    if (body) body.innerHTML = messageHtml;
                    if (retryBtn) retryBtn.disabled = true;
                }
                function setCancelGuidanceReady(html) {
                    var body = document.getElementById('cancelGuidanceBody');
                    var retryBtn = document.getElementById('cancelGuidanceRetryBtn');
                    if (body) body.innerHTML = html;
                    if (retryBtn) retryBtn.disabled = false;
                }
                function fetchCancelGuidanceForActiveRow() {
                    if (!activeCancelGuidanceItemId) {
                        setCancelGuidanceReady('<p>No vendor row selected.</p>');
                        return;
                    }
                    setCancelGuidanceLoading('<p>Generating AI cancellation guidance...</p>');
                    var fd = new FormData();
                    fd.append('action', 'ai_ask');
                    fd.append('preset', 'cancel_steps');
                    fd.append('question', '');
                    fd.append('focus_item_id', String(activeCancelGuidanceItemId));
                    fetch(window.location.href, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            updateAiUsageBar(d || {});
                            if (!d || !d.success) {
                                var msg = '<p>' + aiEscapeHtml((d && d.error) || 'Could not load cancellation guidance.') + '</p>';
                                setCancelGuidanceReady(msg);
                                showSnackbar((d && d.error) || 'Could not load cancellation guidance.', 'error');
                                return;
                            }
                            setCancelGuidanceReady(d.reply || '<p>No guidance returned.</p>');
                        })
                        .catch(function() {
                            setCancelGuidanceReady('<p>Could not load cancellation guidance.</p>');
                            showSnackbar('Could not load cancellation guidance.', 'error');
                        });
                }
                function openCancelGuidanceModalForRow(row) {
                    if (!row) return;
                    var status = getRowStatus(row);
                    if (status !== 'mark_for_cancellation') {
                        showSnackbar('Guidance is only available for Mark for Cancellation.', 'info');
                        return;
                    }
                    var idEl = row.querySelector('.row-db-id');
                    var vendorInput = row.querySelector('input[name="vendor[]"]');
                    var vendorItemId = idEl && idEl.value ? parseInt(idEl.value, 10) : 0;
                    var vendorName = vendorInput ? vendorInput.value.trim() : '';
                    if (!vendorItemId) {
                        showSnackbar('Save this vendor row first to get cancellation guidance.', 'info');
                        return;
                    }
                    var overlay = document.getElementById('appModalCancelGuidance');
                    var title = document.getElementById('appModalCancelGuidanceTitle');
                    var context = document.getElementById('cancelGuidanceContext');
                    if (!overlay) return;
                    activeCancelGuidanceItemId = vendorItemId;
                    activeCancelGuidanceVendorName = vendorName || '';
                    if (title) title.textContent = 'Cancellation Guidance - ' + (vendorName || ('Vendor #' + vendorItemId));
                    if (context) context.textContent = vendorName
                        ? ('Vendor: ' + vendorName)
                        : ('Vendor item #' + vendorItemId);
                    openAppModal(overlay);
                    fetchCancelGuidanceForActiveRow();
                }
                var cancelGuidanceRetryBtn = document.getElementById('cancelGuidanceRetryBtn');
                if (cancelGuidanceRetryBtn) {
                    cancelGuidanceRetryBtn.addEventListener('click', function() {
                        fetchCancelGuidanceForActiveRow();
                    });
                }
                function loadVendorRawDataModal(vendorName) {
                    var overlay = document.getElementById('appModalVendorRaw');
                    var title = document.getElementById('appModalVendorRawTitle');
                    var body = document.getElementById('vendorRawBody');
                    if (!overlay || !title || !body) return;
                    title.textContent = 'Raw Data - ' + vendorName;
                    body.innerHTML = '<p>Loading transaction history...</p>';
                    openAppModal(overlay);
                    var fd = new FormData();
                    fd.append('action', 'load_vendor_raw_data');
                    fd.append('vendor_name', vendorName);
                    fetch(window.location.href, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (!d.success) {
                                body.innerHTML = '<p>' + aiEscapeHtml(d.error || 'Could not load raw data.') + '</p>';
                                return;
                            }
                            var rows = Array.isArray(d.transactions) ? d.transactions : [];
                            if (!rows.length) {
                                body.innerHTML = '<p>No raw transactions found for this vendor yet.</p>';
                                return;
                            }
                            var html = '<div class="vendor-raw-results"><table><thead><tr>'
                                + '<th>Date</th><th class="amount-col">Amount</th><th>Transaction Type</th><th>Account</th><th>Memo/Description</th>'
                                + '</tr></thead><tbody>';
                            rows.forEach(function(row) {
                                var date = aiEscapeHtml(String(row.transaction_date || ''));
                                var amount = aiEscapeHtml(formatMoneyInteger(row.amount || 0));
                                var type = aiEscapeHtml(String(row.transaction_type || ''));
                                var account = aiEscapeHtml(String(row.account || ''));
                                var memo = aiEscapeHtml(String(row.memo || ''));
                                html += '<tr><td>' + date + '</td><td class="amount-col">' + amount + '</td><td>' + type + '</td><td>' + account + '</td><td>' + memo + '</td></tr>';
                            });
                            html += '</tbody></table></div>';
                            body.innerHTML = html;
                        })
                        .catch(function() {
                            body.innerHTML = '<p>Could not load raw data.</p>';
                        });
                }
                function updateAiUsageBar(d) {
                    var bar = document.getElementById('aiUsageBar');
                    if (!bar || d.limit === undefined) return;
                    var used = d.used !== undefined ? d.used : Math.max(0, d.limit - (d.remaining !== undefined ? d.remaining : 0));
                    var hint = d.reset_hint || '';
                    bar.innerHTML = '<strong>This month:</strong> ' + used + ' / ' + d.limit + ' questions used'
                        + (hint ? '<br><span style="font-size:11px;color:#4B5563;">' + aiEscapeHtml(hint) + '</span>' : '');
                }
                function appendAiChatMessage(role, text, asHtml) {
                    var log = document.getElementById('aiChatLog');
                    if (!log) return;
                    var wrap = document.createElement('div');
                    wrap.className = 'chat-message ' + (role === 'user' ? 'user-message' : 'ai-message');
                    var bubble = document.createElement('div');
                    bubble.className = 'chat-bubble ' + (role === 'user' ? 'user-bubble' : 'ai-bubble');
                    if (role === 'assistant' && asHtml) {
                        bubble.className += ' ai-bubble-html';
                        bubble.innerHTML = text;
                    } else {
                        bubble.textContent = text;
                    }
                    wrap.appendChild(bubble);
                    log.appendChild(wrap);
                    if (role === 'assistant') {
                        // Keep the newest assistant answer positioned from its start.
                        var wrapRect = wrap.getBoundingClientRect();
                        var logRect = log.getBoundingClientRect();
                        var targetTop = log.scrollTop + (wrapRect.top - logRect.top);
                        log.scrollTop = targetTop > 0 ? targetTop : 0;
                    } else {
                        log.scrollTop = log.scrollHeight;
                    }
                }
                function setAiUiBusy(busy) {
                    var aiBtn = document.getElementById('aiSubmitBtn');
                    if (aiBtn) aiBtn.disabled = !!busy;
                    document.querySelectorAll('.ai-preset').forEach(function(b) { b.disabled = !!busy; });
                }
                function collectVisibleVendorRowsForPurposeLookup() {
                    var rows = [];
                    document.querySelectorAll('#calculatorRows tr').forEach(function(row) {
                        var idEl = row.querySelector('.row-db-id');
                        var vendorInput = row.querySelector('input[name="vendor[]"]');
                        var idVal = idEl && idEl.value ? parseInt(idEl.value, 10) : 0;
                        var vendorName = vendorInput ? vendorInput.value.trim() : '';
                        if (idVal > 0 && vendorName) {
                            rows.push({ id: idVal, vendor_name: vendorName });
                        }
                    });
                    return rows;
                }
                function applyPurposeLookupResultsToUi(resultRows) {
                    if (!Array.isArray(resultRows)) return;
                    var byId = {};
                    var bySource = {};
                    resultRows.forEach(function(r) {
                        if (r && r.id) {
                            byId[String(r.id)] = String(r.purpose || '');
                            bySource[String(r.id)] = String(r.source || '');
                        }
                    });
                    document.querySelectorAll('#calculatorRows tr').forEach(function(row) {
                        var idEl = row.querySelector('.row-db-id');
                        var idVal = idEl && idEl.value ? String(parseInt(idEl.value, 10) || 0) : '';
                        if (!idVal || !byId[idVal]) return;
                        var notesTextarea = row.querySelector('textarea.purpose-textarea') || row.querySelector('textarea[name="notes[]"]');
                        if (!notesTextarea) return;
                        notesTextarea.value = byId[idVal];
                        var src = bySource[idVal] || '';
                        if (src === 'vendor_detail' || src === 'live_lookup') {
                            notesTextarea.dataset.aiPurposeBadge = '1';
                        } else {
                            delete notesTextarea.dataset.aiPurposeBadge;
                        }
                    });
                }
                function filterResolvedRowsById(resultRows, allowedIds) {
                    if (!Array.isArray(resultRows) || !Array.isArray(allowedIds)) return [];
                    var allow = {};
                    allowedIds.forEach(function(id) {
                        var normalized = parseInt(id, 10);
                        if (normalized > 0) allow[String(normalized)] = true;
                    });
                    return resultRows.filter(function(row) {
                        if (!row || !row.id) return false;
                        return !!allow[String(parseInt(row.id, 10) || 0)];
                    });
                }
                function fetchAiUsageStats() {
                    var fd = new FormData();
                    fd.append('action', 'ai_usage_stats');
                    fetch(window.location.href, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (d.success) updateAiUsageBar(d);
                            else {
                                var bar = document.getElementById('aiUsageBar');
                                if (bar) bar.textContent = 'Could not load usage.';
                            }
                        })
                        .catch(function() {
                            var bar = document.getElementById('aiUsageBar');
                            if (bar) bar.textContent = 'Could not load usage.';
                        });
                }
                fetchAiUsageStats();
                function runProjectAutoPopulatePurpose(projectId, opts) {
                    opts = opts || {};
                    var pid = parseInt(projectId, 10) || currentActiveProjectId || 0;
                    if (!pid) {
                        return Promise.resolve({ success: false, error: 'No project selected.' });
                    }
                    var fd2 = new FormData();
                    fd2.append('action', 'auto_populate_purpose');
                    fd2.append('project_id', String(pid));
                    fd2.append('rows', '[]');
                    if (!opts.silent) {
                        setAiUiBusy(true);
                    }
                    if (!opts.hideLoader) {
                        showAiPopulateLoader('Populating purposes with AI…');
                    }
                    return fetch(window.location.href, { method: 'POST', body: fd2, credentials: 'same-origin' })
                        .then(function(r) {
                            return r.text().then(function(text) {
                                var d = null;
                                try {
                                    d = text ? JSON.parse(text) : null;
                                } catch (parseErr) {
                                    d = null;
                                }
                                if (!r.ok) {
                                    var err = (d && d.error) ? d.error : ('Request failed (HTTP ' + r.status + ').');
                                    throw new Error(err);
                                }
                                return d;
                            });
                        })
                        .then(function(d) {
                            if (!opts.silent) {
                                setAiUiBusy(false);
                            }
                            if (d && d.success && !opts.skipClientSave) {
                                applyPurposeLookupResultsToUi(d.resolved || []);
                                if ((d.resolved || []).length) {
                                    clearTimeout(saveTimeout);
                                    return saveCalculatorData({ silent: true }).then(function() {
                                        return d;
                                    });
                                }
                            }
                            return d || { success: false, error: 'Invalid response.' };
                        })
                        .catch(function(err) {
                            if (!opts.silent) {
                                setAiUiBusy(false);
                            }
                            return {
                                success: false,
                                error: (err && err.message) ? err.message : 'Auto populate request failed.',
                            };
                        })
                        .finally(function() {
                            if (!opts.hideLoader) {
                                hideAiPopulateLoader();
                            }
                        });
                }
                window.runProjectAutoPopulatePurpose = runProjectAutoPopulatePurpose;
                window.applyPurposeLookupResultsToUi = applyPurposeLookupResultsToUi;
                window.waitForCalculatorSaveIdle = function() {
                    return saveQueue || Promise.resolve();
                };
                function triggerAutoPopulatePurpose() {
                    appendAiChatMessage('user', 'Auto populate purpose');
                    showSnackbar('Populating purposes with AI… This may take a minute.', 'info');
                    setAiUiBusy(true);
                    runProjectAutoPopulatePurpose(currentActiveProjectId, { silent: true })
                        .then(function(d) {
                            setAiUiBusy(false);
                            if (d.success) {
                                var unresolved = Array.isArray(d.unresolved) ? d.unresolved.length : 0;
                                var applied = typeof d.applied === 'number' ? d.applied : 0;
                                var changed = typeof d.updated === 'number' ? d.updated : 0;
                                var resolvedList = Array.isArray(d.resolved) ? d.resolved : [];
                                var unknownCount = resolvedList.filter(function(r) {
                                    return r && r.source === 'fallback_unknown';
                                }).length;
                                var statusText = 'Auto populate finished. Applied to ' + applied + ' rows.';
                                if (unknownCount) {
                                    statusText += ' ' + unknownCount + ' marked as Unknown.';
                                }
                                if (changed !== applied) {
                                    statusText += ' ' + changed + ' rows had DB value changes.';
                                }
                                appendAiChatMessage(
                                    'assistant',
                                    statusText + (unresolved ? (' ' + unresolved + ' vendors could not be resolved.') : ''),
                                    false
                                );
                                showSnackbar('Purpose auto-populate completed.', 'success');
                            } else {
                                var errMsg = d.error || 'Auto populate failed.';
                                if (errMsg.indexOf('No') === 0 || errMsg.indexOf('no ') === 0) {
                                    errMsg = 'No vendor rows found for this project.';
                                }
                                appendAiChatMessage('assistant', errMsg, false);
                                showSnackbar(errMsg, 'error');
                            }
                        });
                }

                var aiOpenBtn = document.getElementById('appAiAssistantBtn');
                if (aiOpenBtn) {
                    aiOpenBtn.addEventListener('click', function() { setTimeout(fetchAiUsageStats, 150); });
                }
                var autoPurposeBtn = document.getElementById('appAutoPopulatePurposeBtn');
                if (autoPurposeBtn) {
                    autoPurposeBtn.addEventListener('click', function() {
                        var overlay = document.getElementById('appModalAI');
                        if (overlay) openAppModal(overlay);
                        setTimeout(fetchAiUsageStats, 150);
                        triggerAutoPopulatePurpose();
                    });
                }
                var aiBtn = document.getElementById('aiSubmitBtn');
                if (aiBtn) {
                    aiBtn.addEventListener('click', function() {
                        var q = document.getElementById('aiQuestion').value.trim();
                        if (!q) {
                            showSnackbar('Enter a question or use a preset.', 'error');
                            return;
                        }
                        appendAiChatMessage('user', q);
                        var fd = new FormData();
                        fd.append('action', 'ai_ask');
                        fd.append('question', q);
                        setAiUiBusy(true);
                        fetch(window.location.href, { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                setAiUiBusy(false);
                                updateAiUsageBar(d);
                                if (d.success) {
                                    appendAiChatMessage('assistant', d.reply || '', true);
                                } else {
                                    appendAiChatMessage('assistant', d.error || 'Error', false);
                                }
                            })
                            .catch(function() {
                                setAiUiBusy(false);
                                appendAiChatMessage('assistant', 'Request failed.', false);
                            });
                    });
                }
                document.querySelectorAll('.ai-preset').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var preset = btn.getAttribute('data-preset') || '';
                        var label = (btn.textContent || '').trim();
                        if (preset === 'auto_purpose') {
                            triggerAutoPopulatePurpose();
                            return;
                        }
                        appendAiChatMessage('user', label);
                        var fd = new FormData();
                        fd.append('action', 'ai_ask');
                        fd.append('preset', preset);
                        fd.append('question', '');
                        setAiUiBusy(true);
                        fetch(window.location.href, { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                setAiUiBusy(false);
                                updateAiUsageBar(d);
                                if (d.success) {
                                    appendAiChatMessage('assistant', d.reply || '', true);
                                } else {
                                    appendAiChatMessage('assistant', d.error || 'Error', false);
                                }
                            })
                            .catch(function() {
                                setAiUiBusy(false);
                                appendAiChatMessage('assistant', 'Request failed.', false);
                            });
                    });
                });
            });
            window.addEventListener('pagehide', flushSaveOnLeave);
            </script>

        <?php endif; ?>

        </div>

    </div>

    <?php if ($current_view === 'placeholder'): ?>
    <div class="app-modal-overlay" id="appModalMembersInvite" role="dialog" aria-modal="true" aria-labelledby="appModalMembersInviteTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalMembersInviteTitle">Invite user</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <p style="margin:0 0 10px;color:#4b5563;font-size:14px;">
                    Usage: <strong><?php echo (int) $team_members_count; ?>/<?php echo (int) $team_members_max; ?></strong> members
                </p>
                <div class="invite-block">
                    <form method="POST" style="display:flex;flex-direction:column;gap:10px;max-width:420px;">
                        <input type="hidden" name="action" value="invite_member">
                        <label style="display:grid;gap:6px;font-size:14px;">
                            <span>Email</span>
                            <input type="email" name="email" required placeholder="user@company.com" style="min-width:200px;">
                        </label>
                        <?php if (!empty($invite_can_choose_org_role)): ?>
                        <label style="display:grid;gap:6px;font-size:14px;">
                            <span style="display:flex;align-items:center;gap:6px;">
                                Organization role
                                <button type="button" class="org-role-info-btn" id="orgRoleInfoBtn" aria-label="About organization roles" title="About organization roles">&#9432;</button>
                            </span>
                            <select name="invite_role" style="max-width:280px;">
                                <option value="member">Member</option>
                                <option value="admin">Administrator (cannot create projects)</option>
                            </select>
                        </label>
                        <?php endif; ?>
                        <div>
                            <button type="submit">Send invite</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($invite_can_choose_org_role)): ?>
    <div class="app-modal-overlay" id="appModalOrgRolesInfo" role="dialog" aria-modal="true" aria-labelledby="appModalOrgRolesInfoTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1" style="max-width:480px;">
            <div class="app-modal-header">
                <h2 id="appModalOrgRolesInfoTitle">Organization roles</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <?php foreach (\CostSavings\OrgRole::roleDescriptionsForInvite() as $roleDesc): ?>
                <div style="margin-bottom:14px;">
                    <strong style="display:block;font-size:14px;margin-bottom:4px;"><?php echo htmlspecialchars($roleDesc['title']); ?></strong>
                    <p style="margin:0;font-size:14px;color:#374151;line-height:1.5;"><?php echo htmlspecialchars($roleDesc['description']); ?></p>
                    <?php if (!empty($roleDesc['note'])): ?>
                    <p style="margin:6px 0 0;font-size:13px;color:#6b7280;"><?php echo htmlspecialchars($roleDesc['note']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="app-modal-overlay" id="appModalMembersManage" role="dialog" aria-modal="true" aria-labelledby="appModalMembersManageTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalMembersManageTitle">Manage Members</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <p style="margin:0 0 10px;color:#4b5563;font-size:14px;">
                    Usage: <strong><?php echo (int) $team_members_count; ?>/<?php echo (int) $team_members_max; ?></strong> members
                </p>
                <div class="members-table-wrap">
                    <table class="members-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <?php if ($is_admin): ?>
                                <th>Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $members_colspan = $is_admin ? 5 : 4; ?>
                            <?php if (empty($team_members_rows)): ?>
                            <tr><td colspan="<?php echo (int) $members_colspan; ?>">No members in this organization yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($team_members_rows as $tm): ?>
                            <?php $member_is_disabled = !empty($tm['is_disabled']); ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tm['display_name'] ?? $tm['username'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($tm['email'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars(\CostSavings\OrgRole::label((string) ($tm['role'] ?? 'member'))); ?></td>
                                <td>
                                    <?php if ($member_is_disabled): ?>
                                    <span class="member-status-pill member-status-pill--disabled">Disabled</span>
                                    <?php else: ?>
                                    <span class="member-status-pill member-status-pill--active">Active</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($is_admin): ?>
                                <td>
                                    <?php if (($tm['role'] ?? 'member') === 'member'): ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="toggle_member_disabled">
                                        <input type="hidden" name="member_id" value="<?php echo (int) ($tm['id'] ?? 0); ?>">
                                        <input type="hidden" name="disable" value="<?php echo $member_is_disabled ? '0' : '1'; ?>">
                                        <button type="submit" class="member-action-btn"><?php echo $member_is_disabled ? 'Enable' : 'Disable'; ?></button>
                                    </form>
                                    <?php else: ?>
                                    <span>—</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if ($can_create_projects): ?>
    <div class="app-modal-overlay" id="appModalProjectWizard" role="dialog" aria-modal="true" aria-labelledby="appModalProjectWizardTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalProjectWizardTitle">Create New Project</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <form id="projectWizardForm" style="display:grid;gap:10px;">
                    <label>Project name
                        <input type="text" id="projectWizardName" required maxlength="255" placeholder="Example: FY2026 Savings">
                    </label>
                    <label>Start date
                        <input type="date" id="projectWizardStartDate" required>
                    </label>
                    <label>
                        <input type="radio" name="projectWizardDataMode" id="projectWizardDataModeUpload" value="upload_after" checked>
                        I will upload data after creating this project.
                    </label>
                    <label>
                        <input type="radio" name="projectWizardDataMode" id="projectWizardDataModeCopy" value="copy_from_active">
                        Copy data from current active project.
                    </label>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" class="btn-secondary app-modal-close project-wizard-cancel-btn">Cancel</button>
                        <button type="submit">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="app-modal-overlay" id="appModalDeleteProject" role="dialog" aria-modal="true" aria-labelledby="appModalDeleteProjectTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalDeleteProjectTitle">Delete project</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <p style="margin:0 0 10px;line-height:1.5;">You are about to permanently delete <strong id="deleteProjectNameDisplay"></strong>. All vendor lines, chat history, and uploaded raw transactions tied to this project will be removed. This cannot be undone.</p>
                <p style="margin:0 0 10px;font-size:14px;color:#4b5563;">Type the project name exactly to confirm.</p>
                <label style="display:grid;gap:6px;margin-bottom:12px;">
                    <span class="visually-hidden">Confirm project name</span>
                    <input type="text" id="deleteProjectConfirmInput" autocomplete="off" placeholder="Project name">
                </label>
                <div class="bulk-actions-buttons" style="margin-top:4px;">
                    <button type="button" class="btn-secondary app-modal-close">Cancel</button>
                    <button type="button" id="deleteProjectSubmitBtn" disabled style="border-color:#b91c1c;color:#b91c1c;background:#fff;">Delete project</button>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay post-create-flow-modal" id="appModalPostCreateUpload" role="dialog" aria-modal="true" aria-labelledby="appModalPostCreateUploadTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalPostCreateUploadTitle">Import your data</h2>
            </div>
            <div class="app-modal-body">
                <p id="postCreateUploadSubtitle" style="margin:0 0 12px;font-size:14px;color:#4b5563;line-height:1.5;"></p>
                <p style="margin:0 0 14px;font-size:14px;color:#374151;line-height:1.5;">Upload a QuickBooks or vendor CSV to populate vendors for this project.</p>
                <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                    <button type="button" class="btn-secondary" id="postCreateUploadSkipBtn">Skip for now</button>
                    <button type="button" id="postCreateUploadChooseBtn">Choose CSV file</button>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay post-create-flow-modal" id="appModalPostCreatePurpose" role="dialog" aria-modal="true" aria-labelledby="appModalPostCreatePurposeTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalPostCreatePurposeTitle">Copy from previous project</h2>
            </div>
            <div class="app-modal-body">
                <p id="postCreatePurposeSubtitle" style="margin:0 0 12px;font-size:14px;color:#4b5563;line-height:1.5;"></p>
                <div id="postCreateCopyQuestionsBlock">
                    <fieldset style="border:none;margin:0 0 14px;padding:0;">
                        <legend style="font-size:14px;color:#374151;margin-bottom:8px;">Copy purposes from a previous project? Purposes are matched by vendor name.</legend>
                        <label style="margin-right:14px;font-size:14px;"><input type="radio" name="postCreateCopyPurposes" value="yes"> Yes</label>
                        <label style="font-size:14px;"><input type="radio" name="postCreateCopyPurposes" value="no"> No</label>
                    </fieldset>
                    <fieldset style="border:none;margin:0 0 14px;padding:0;">
                        <legend style="font-size:14px;color:#374151;margin-bottom:8px;">Copy vendor chats from a previous project? Chats are matched by vendor name.</legend>
                        <label style="margin-right:14px;font-size:14px;"><input type="radio" name="postCreateCopyChats" value="yes"> Yes</label>
                        <label style="font-size:14px;"><input type="radio" name="postCreateCopyChats" value="no"> No</label>
                    </fieldset>
                </div>
                <div id="postCreatePurposeSelectBlock" style="display:none;margin-bottom:14px;">
                    <label style="display:grid;gap:6px;font-size:14px;margin-bottom:10px;">
                        <span>Source project</span>
                        <select id="postCreatePurposeSource" style="max-width:100%;"></select>
                    </label>
                    <label id="postCreateBlankPurposeRow" style="display:none;align-items:flex-start;gap:8px;font-size:14px;cursor:pointer;">
                        <input type="checkbox" id="postCreateOverwriteBlankPurposes" style="margin-top:3px;">
                        <span>Overwrite blank purposes from the selected project</span>
                    </label>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                    <button type="button" id="postCreateCopyContinueBtn">Continue</button>
                    <button type="button" id="postCreatePurposeProceedBtn" style="display:none;">Proceed</button>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay post-create-flow-modal" id="appModalPostCreateInvite" role="dialog" aria-modal="true" aria-labelledby="appModalPostCreateInviteTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalPostCreateInviteTitle">Invite users</h2>
            </div>
            <div class="app-modal-body">
                <p id="postCreateInviteSubtitle" style="margin:0 0 12px;font-size:14px;color:#4b5563;line-height:1.5;"></p>
                <p style="margin:0 0 14px;font-size:14px;color:#374151;line-height:1.5;">Would you like to invite users to your organization?</p>
                <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                    <button type="button" class="btn-secondary" id="postCreateInviteNoBtn">No</button>
                    <button type="button" id="postCreateInviteYesBtn">Yes</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="app-modal-overlay" id="appModalCsvAccounts" role="dialog" aria-modal="true" aria-labelledby="appModalCsvAccountsTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalCsvAccountsTitle">Select accounts to import</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <p style="margin:0 0 10px;font-size:14px;color:#4b5563;line-height:1.5;">Choose which GL accounts to include. Vendor rows are grouped by payee (Name) from the selected accounts only.</p>
                <div class="data-actions">
                    <button type="button" class="btn-secondary" id="csvAccountSelectAllBtn">Select all</button>
                    <button type="button" class="btn-secondary" id="csvAccountClearBtn">Clear</button>
                    <span id="csvAccountSelectionStatus" style="font-size:13px;color:#4b5563;"></span>
                </div>
                <div class="csv-account-list" id="csvAccountList" role="group" aria-label="GL accounts"></div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button type="button" class="btn-secondary app-modal-close" id="csvAccountCancelBtn">Cancel</button>
                    <button type="button" id="csvAccountImportBtn" disabled>Import selected</button>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay" id="appModalAI" role="dialog" aria-modal="true" aria-labelledby="appModalAITitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalAITitle">AI Assistant</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <div id="aiAssistant" class="ai-assistant-card">
                    <div id="aiUsageBar" class="ai-usage-bar" aria-live="polite">Loading usage…</div>
                    <div class="ai-presets-row">
                        <button type="button" class="btn-secondary ai-preset" data-preset="overlap">Overlap between vendors</button>
                        <button type="button" class="btn-secondary ai-preset" data-preset="duplicates">Duplicate subscriptions</button>
                        <button type="button" class="btn-secondary ai-preset" data-preset="executive">Executive summary</button>
                    </div>
                    <div id="aiChatLog" class="chat-container ai-chat-log" aria-label="AI Assistant conversation"></div>
                    <div class="ai-composer">
                        <textarea id="aiQuestion" class="ai-question-input" rows="2" placeholder="Ask a specific question..."></textarea>
                        <button type="button" id="aiSubmitBtn" class="ai-submit-btn">Ask</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay" id="appModalSettings" role="dialog" aria-modal="true" aria-labelledby="appModalSettingsTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalSettingsTitle">Settings</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="settings-block">
                    <form method="POST" style="display:grid;gap:10px;">
                        <input type="hidden" name="action" value="save_reminder_settings">
                        <?php if ($is_admin): ?>
                        <label><input type="checkbox" name="deadline_reminders_enabled" value="1" <?php echo $deadline_reminders_org ? 'checked' : ''; ?>> Email monthly executive summary</label>
                        <label style="display:grid;gap:6px;font-size:14px;">
                            <span>Webhook for notifications</span>
                            <input
                                type="url"
                                name="notification_webhook_url"
                                value="<?php echo htmlspecialchars($notification_webhook_url); ?>"
                                placeholder="https://your-endpoint.example/webhook"
                                style="min-width:320px;"
                            >
                            <small style="color:#6b7280;line-height:1.45;">
                                Sends vendor and project details when a vendor is marked for cancellation.
                                Example endpoints: Slack Incoming Webhook URL, Asana automation webhook endpoint, Notion automation webhook URL, Evernote integration webhook URL.
                            </small>
                        </label>
                        <?php endif; ?>
                        <label style="font-size:14px;"><input type="checkbox" name="user_deadline_reminders" value="1" <?php echo $deadline_reminders_user ? 'checked' : ''; ?>> Email me cancellation date reminders</label>
                        <div><button type="submit">Save</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay" id="appModalBulkActions" role="dialog" aria-modal="true" aria-labelledby="appModalBulkActionsTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalBulkActionsTitle">Bulk Vendor Actions</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="bulk-actions-form">
                    <label for="bulkActionType">Choose action</label>
                    <select id="bulkActionType">
                        <option value="">Select action</option>
                        <option value="frequency">Update Frequency</option>
                        <option value="status">Update Status</option>
                        <?php if ($is_admin): ?>
                        <option value="visibility">Update Visibility</option>
                        <option value="manager">Update Manager</option>
                        <?php endif; ?>
                        <option value="delete">Delete Selected Rows</option>
                    </select>
                    <div class="bulk-action-controls">
                        <div id="bulkFrequencyWrap" style="display:none;">
                            <label for="bulkFrequencyValue">Frequency value</label>
                            <select id="bulkFrequencyValue">
                                <option value="">Select frequency</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="semi_annual">Semi-annual</option>
                                <option value="annually">Annually</option>
                                <option value="one_off">One-off</option>
                            </select>
                        </div>
                        <div id="bulkStatusWrap" style="display:none;">
                            <label for="bulkStatusValue">Status value</label>
                            <select id="bulkStatusValue">
                                <option value="">Select status</option>
                                <option value="pending">Pending</option>
                                <option value="question">Question</option>
                                <option value="unknown">Unknown</option>
                                <option value="keep">Keep</option>
                                <option value="mark_for_cancellation">Mark for Cancellation</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div id="bulkVisibilityWrap" style="display:none;">
                            <label for="bulkVisibilityValue">Visibility value</label>
                            <select id="bulkVisibilityValue">
                                <option value="public">Public</option>
                                <option value="confidential">Confidential</option>
                            </select>
                        </div>
                        <div id="bulkManagerWrap" style="display:none;">
                            <label for="bulkManagerValue">Manager value</label>
                            <select id="bulkManagerValue"></select>
                        </div>
                    </div>
                    <div class="bulk-actions-buttons">
                        <button type="button" class="btn-secondary app-modal-close">Cancel</button>
                        <button type="button" id="bulkActionsApplyBtn">Review Action</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay" id="appModalBulkConfirm" role="dialog" aria-modal="true" aria-labelledby="appModalBulkConfirmTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalBulkConfirmTitle">Confirm Bulk Action</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <div id="bulkConfirmDetails"></div>
                <div class="bulk-actions-buttons" style="margin-top:12px;">
                    <button type="button" class="btn-secondary" id="bulkConfirmCancelBtn">Cancel</button>
                    <button type="button" id="bulkConfirmProceedBtn">Proceed</button>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay" id="appModalVendorChat" role="dialog" aria-modal="true" aria-labelledby="appModalVendorChatTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalVendorChatTitle">Vendor Chat</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="vendor-chat-shell">
                    <div class="vendor-chat-meta">
                        <span class="vendor-chat-meta-badge" aria-hidden="true"></span>
                        <span id="vendorChatContextName">Select a vendor row to view notes.</span>
                    </div>
                    <div id="vendorChatLog" class="vendor-chat-log" aria-live="polite"></div>
                    <div class="vendor-chat-composer">
                        <textarea id="vendorChatInput" class="vendor-chat-input" maxlength="2000" placeholder="Write a note for this vendor. Press Enter to send, Shift+Enter for a new line."></textarea>
                        <div class="vendor-chat-composer-actions">
                            <span class="vendor-chat-hint">Shared notes include author and timestamp.</span>
                            <button type="button" id="vendorChatSendBtn" class="vendor-chat-send-btn">Send Note</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay" id="appModalCancelGuidance" role="dialog" aria-modal="true" aria-labelledby="appModalCancelGuidanceTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalCancelGuidanceTitle">Cancellation Guidance</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="vendor-cancel-ai-shell">
                    <div id="cancelGuidanceContext" class="vendor-cancel-ai-context">Select a vendor row marked for cancellation.</div>
                    <div id="cancelGuidanceBody" class="vendor-cancel-ai-content" aria-live="polite">
                        <p>AI cancellation guidance will appear here.</p>
                    </div>
                    <div class="vendor-cancel-ai-actions">
                        <button type="button" id="cancelGuidanceRetryBtn" class="btn-secondary">Refresh Guidance</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-modal-overlay" id="appModalVendorRaw" role="dialog" aria-modal="true" aria-labelledby="appModalVendorRawTitle" aria-hidden="true">
        <div class="app-modal" tabindex="-1">
            <div class="app-modal-header">
                <h2 id="appModalVendorRawTitle">Raw Data</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body" id="vendorRawBody">
                <p>Select a vendor row and click Raw to load transaction history.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

</body>
</html>
