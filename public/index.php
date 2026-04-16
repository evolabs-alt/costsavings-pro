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
            case 'invite_member':
                handleInviteMember();
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

$is_admin = ($is_logged_in && ($_SESSION['role'] ?? '') === 'admin');

$deadline_reminders_org = true;
$deadline_reminders_user = true;
if ($is_logged_in && !empty($_SESSION['org_id'])) {
    try {
        $pdoView = getDBConnection();
        $st = $pdoView->prepare('SELECT deadline_reminders_enabled FROM organizations WHERE id = ?');
        $st->execute([(int) $_SESSION['org_id']]);
        $or = $st->fetch(PDO::FETCH_ASSOC);
        if ($or && isset($or['deadline_reminders_enabled'])) {
            $deadline_reminders_org = (bool) $or['deadline_reminders_enabled'];
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
$team_members_max = 10;
if ($is_logged_in && $current_view === 'placeholder' && !empty($_SESSION['org_id'])) {
    try {
        $pdoTeam = getDBConnection();
        $stTeam = $pdoTeam->prepare('SELECT id, username, display_name, email, role FROM users WHERE org_id = ? ORDER BY username, email');
        $stTeam->execute([(int) $_SESSION['org_id']]);
        $team_members_rows = $stTeam->fetchAll(PDO::FETCH_ASSOC);
        $team_members_json = json_encode($team_members_rows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $team_members_count = count($team_members_rows);
        $team_members_max = getOrganizationMaxUsers($pdoTeam, (int) $_SESSION['org_id']);
    } catch (Exception $e) {
        $team_members_rows = [];
        $team_members_json = '[]';
        $team_members_count = 0;
        $team_members_max = 10;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savvy CFO Cost Savings Pro Tool</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        
        body { 
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif; 
            background: linear-gradient(160deg, #f3effb 0%, #e8e2f4 28%, #ddd2ec 55%, #d0c4e4 100%);
            margin: 0;
            padding: 20px 20px;
            min-height: 100vh;
            line-height: 1.6;
            position: relative;
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
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.97) 0%, rgba(252, 250, 255, 0.96) 45%, rgba(248, 244, 255, 0.94) 100%);
            backdrop-filter: blur(12px);
            border-radius: 20px; 
            box-shadow: 0 24px 56px rgba(74, 63, 107, 0.12), 0 0 0 1px rgba(107, 91, 149, 0.12);
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
        
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #7c6ba8, #5b4d8f, #6b5b95);
        }
        
        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        h1, h2 { 
            text-align: center; 
            color: #424242; 
            font-weight: 700;
            margin-bottom: 30px;
        }
        
        .subtitle {
            text-align: center;
            color: #5c4d7a;
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
            background: linear-gradient(135deg, #4a3f6b 0%, #6b5b95 45%, #8b7cb8 100%);
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
            background: linear-gradient(135deg, rgba(107, 91, 149, 0.05), rgba(26, 109, 145, 0.05));
            border: 1px solid rgba(107, 91, 149, 0.15);
            border-radius: 12px;
            text-align: center;
            font-size: 14px;
            color: #4a5568;
            line-height: 1.6;
        }
        
        .ebook-promotion .ebook-title {
            font-weight: 600;
            color: #424242;
            font-style: italic;
        }
        
        .ebook-promotion .ebook-link {
            color: #6b5b95;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .ebook-promotion .ebook-link:hover {
            color: #4a3f6b;
            text-decoration: underline;
        }

        /* Placeholder / Cost Savings Pro Tool link styles */
        .cost-calculator-link {
            display: inline-block;
            padding: 15px 40px;
            background: #6b5b95;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            transition: background 0.3s, transform 0.2s;
            box-shadow: 0 4px 6px rgba(91, 77, 143, 0.28);
        }

        .cost-calculator-link:hover {
            background: #4a3f6b;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(91, 77, 143, 0.35);
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
            color: #6b5b95;
            text-decoration: underline;
        }

        .placeholder-content p a:hover {
            color: #4a3f6b;
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
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .cost-calculator-table-wrapper::-webkit-scrollbar-thumb {
            background: #6b5b95;
            border-radius: 4px;
        }
        
        .cost-calculator-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #4a3f6b;
        }

        .cost-calculator-grid {
            width: 100%;
            min-width: 1020px;
            border-collapse: collapse;
            margin: 0;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .cost-calculator-grid thead {
            background: #6b5b95;
            color: white;
        }

        .cost-calculator-grid th {
            padding: 8px 6px;
            text-align: left;
            font-weight: 600;
            border: 1px solid #4a3f6b;
            font-size: 13px;
            white-space: nowrap;
        }

        .cost-calculator-grid td {
            padding: 6px;
            border: 1px solid #e0e0e0;
        }

        .cost-calculator-grid tbody tr:hover {
            background: #f5f5f5;
        }

        .cost-calculator-grid input[type="text"],
        .cost-calculator-grid input[type="number"],
        .cost-calculator-grid select,
        .cost-calculator-grid textarea {
            width: 100%;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 13px;
            box-sizing: border-box;
        }

        .cost-calculator-grid input[type="text"]:focus,
        .cost-calculator-grid input[type="number"]:focus,
        .cost-calculator-grid select:focus,
        .cost-calculator-grid textarea:focus {
            outline: none;
            border-color: #6b5b95;
            box-shadow: 0 0 0 2px rgba(107, 91, 149, 0.2);
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
            padding: 5px 8px;
            font-size: 12px;
            line-height: 1;
            cursor: pointer;
            white-space: nowrap;
        }

        .cost-calculator-grid .vendor-raw-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
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

        .cost-calculator-grid .cost-per-period {
            min-width: 100px;
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

        .cost-calculator-grid .cancel-keep {
            min-width: 90px;
        }

        .cost-calculator-grid .cancel-keep .cancelled-status-inline {
            margin-top: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            font-size: 11px;
            color: #6b7280;
            font-weight: 500;
        }

        .cost-calculator-grid .cancel-keep .cancelled-status-inline input[type="checkbox"] {
            width: 14px;
            height: 14px;
            cursor: pointer;
        }

        .cost-calculator-grid .cancel-keep .cancelled-status-inline input[type="checkbox"]:disabled {
            cursor: not-allowed;
            opacity: 0.35;
        }

        .cost-calculator-grid .cancel-deadline {
            min-width: 110px;
        }

        .cost-calculator-grid .cancelled-status {
            min-width: 90px;
            text-align: center;
        }

        .cost-calculator-grid .cancelled-status input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .cost-calculator-grid .cancelled-status input[type="checkbox"]:disabled {
            cursor: not-allowed;
            opacity: 0.35;
        }

        /* Filter dropdown styles */
        .report-filters {
            display: flex;
            align-items: center;
            gap: 15px;
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
            white-space: nowrap;
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
            background: #4f46e5;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }

        .bulk-action-btn:hover {
            background: #4338ca;
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
            margin-bottom: 30px;
            padding: 20px 0;
        }
        
        .logo-above-container .login-logo {
            max-width: 160px;
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

            .cost-calculator-grid .cancel-keep {
                min-width: 80px;
            }

            .cost-calculator-grid .cancel-deadline {
                min-width: 100px;
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
            accent-color: #6b5b95;
            cursor: pointer;
        }

        .checkbox-label span {
            line-height: 1.5;
            color: #374151;
            font-size: 14px;
        }

        .checkbox-label a {
            color: #6b5b95;
            text-decoration: underline;
        }

        .checkbox-label a:hover {
            color: #4a3f6b;
        }
        
        input[type="email"], input[type="text"], input[type="password"], select { 
            width: 100%; 
            padding: 16px 20px; 
            border: 2px solid #e5e7eb; 
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
            border-color: #6b5b95;
            box-shadow: 0 0 0 3px rgba(107, 91, 149, 0.14);
            transform: translateY(-2px);
            background: #fff;
        }
        
        button { 
            padding: 16px 32px; 
            background: linear-gradient(135deg, #6b5b95, #4a3f6b); 
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
            box-shadow: 0 8px 25px rgba(107, 91, 149, 0.3);
        }
        
        .btn-secondary { 
            background: linear-gradient(135deg, #6b7280, #4b5563); 
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
            background: linear-gradient(135deg, #fecaca, #f87171); 
            color: #7f1d1d; 
            box-shadow: 0 4px 15px rgba(248, 113, 113, 0.2);
        }
        
        .success { 
            background: linear-gradient(135deg, #bbf7d0, #34d399); 
            color: #064e3b; 
            box-shadow: 0 4px 15px rgba(52, 211, 153, 0.2);
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
            background: linear-gradient(90deg, #6b5b95, #4a3f6b); 
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
            background: linear-gradient(135deg, #6b5b95 0%, #4a3f6b 100%); 
            color: white; 
            padding: 20px 25px; 
            margin: 0; 
            border-radius: 20px 20px 0 0; 
            text-align: center; 
            font-size: 22px; 
            font-weight: 700;
            letter-spacing: 0.5px;
            position: relative;
            box-shadow: 0 4px 15px rgba(107, 91, 149, 0.3);
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
            border-top: 10px solid #4a3f6b;
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
            border-top: 1px solid #e9ecef;
        }

        .ai-guidance-button {
            background: #6b5b95;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            margin: 0 10px;
        }

        .ai-guidance-button:hover {
            background: #4a3f6b;
        }

        .app-nav {
            width: 100%;
            margin: 0 0 18px 0;
            border: 1px solid #d8caec;
            border-radius: 10px;
            background: linear-gradient(135deg, #faf8ff, #f3effb);
            box-shadow: 0 6px 18px rgba(74, 63, 107, 0.08);
            display: flex;
            justify-content: center;
            padding: 4px 10px;
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
            color: #4a3f6b;
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
            background: #ede9fe;
            border-color: #c7b5e4;
            color: #352a55;
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
            border: 1px solid #d9ccec;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 12px 24px rgba(51, 40, 78, 0.14);
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
            color: #4a3f6b;
            text-decoration: none;
            text-align: left;
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
        }

        .app-submenu-item:hover,
        .app-submenu-item:focus-visible {
            background: #f1ebfd;
            color: #352a55;
            outline: none;
        }

        .app-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10000;
            background: rgba(45, 35, 70, 0.45);
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
            border: 1px solid #e4daf5;
            box-shadow: 0 20px 50px rgba(74, 63, 107, 0.18);
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
            border-bottom: 1px solid #ede9fe;
            background: linear-gradient(135deg, #faf8ff, #f8f5fc);
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
            color: #4a3f6b;
            font-weight: 700;
        }

        .app-modal-close {
            border: none;
            background: transparent;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
            color: #6b5b80;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .app-modal-close:hover {
            background: #ede9fe;
            color: #4a3f6b;
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
            border-bottom: 1px solid #ede9fe;
        }

        .members-table th {
            font-weight: 600;
            color: #4a3f6b;
            background: #faf8ff;
        }

        .app-modal-body .invite-block {
            margin-bottom: 12px;
            padding: 12px;
            background: linear-gradient(135deg, #faf8ff, #f3effb);
            border-radius: 8px;
            border: 1px solid #d4c4e8;
        }

        .app-modal-body .data-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
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
            background: linear-gradient(145deg, #faf8ff, #f5f0ff);
            border-radius: 12px;
            border: 1px solid #e4daf5;
            box-shadow: 0 4px 20px rgba(74,63,107,0.06);
        }

        .app-modal-body .ai-usage-bar {
            font-size: 12px;
            color: #4a3f6b;
            background: #fff;
            border: 1px solid #e4daf5;
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 10px;
            line-height: 1.45;
        }

        .app-modal-body .ai-usage-bar strong {
            color: #352a55;
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
            margin-bottom: 10px;
        }

        .app-modal-body .ai-question-input {
            width: 100%;
            max-width: 100%;
            min-height: 70px;
            box-sizing: border-box;
            padding: 12px 14px;
            border: 1px solid #d6caeb;
            border-radius: 10px;
            background: #fff;
            color: #352a55;
            font-size: 14px;
            line-height: 1.4;
            resize: vertical;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .app-modal-body .ai-question-input:focus {
            outline: none;
            border-color: #a78bda;
            box-shadow: 0 0 0 3px rgba(167, 139, 218, 0.2);
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
            background: linear-gradient(145deg, #6f5a99, #57427f);
            box-shadow: 0 5px 12px rgba(87, 66, 127, 0.3);
            cursor: pointer;
            white-space: nowrap;
        }

        .app-modal-body .ai-submit-btn:hover {
            background: linear-gradient(145deg, #7a65a5, #614b8c);
            transform: translateY(-1px);
        }

        .app-modal-body .ai-submit-btn:disabled {
            opacity: 0.72;
            cursor: not-allowed;
            transform: none;
        }

        .app-modal-body .ai-chat-log {
            max-height: 280px;
            min-height: 72px;
            overflow-y: auto;
            margin-top: 6px;
            padding: 10px;
            border: 1px solid #ddd0f0;
            border-radius: 8px;
            background: #fcfbff;
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
            background: #6c757d;
        }

        .ai-guidance-button.secondary:hover {
            background: #545b62;
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
            background: linear-gradient(135deg, #6b5b95 0%, #4a3f6b 100%);
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
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        }
        
        .snackbar.success {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
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
                console.group('SMTP Debug Transcript');
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
            </div>
        <?php endif; ?>
        <div class="container">
            <?php if ($current_view === 'login'): ?>
            <div class="content-padding login-page">
                <h1>Cost Savings Pro Tool</h1>
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
                <h1>Cost Savings Pro Tool</h1>
                <p class="subtitle" style="margin-bottom:16px;">Signed in as <?php echo htmlspecialchars($_SESSION['username'] ?? $_SESSION['user_email'] ?? ''); ?>
                    <?php if ($is_admin): ?> (Admin)<?php endif; ?></p>

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
                                <?php if ($is_admin): ?>
                                <li role="none"><button type="button" role="menuitem" class="app-submenu-item" id="appCreateProjectBtn" data-open-modal="appModalProjectWizard">Create New Project</button></li>
                                <?php endif; ?>
                                <li role="none">
                                    <label class="app-submenu-item" style="display:block;">
                                        <span style="display:block;margin-bottom:6px;">Switch Project</span>
                                        <select id="projectSwitcherSelect" style="width:100%;"></select>
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
                        <li class="app-nav-item">
                            <button type="button" class="app-nav-link" data-open-modal="appModalSettings">Settings</button>
                        </li>
                        <li class="app-nav-item">
                            <form method="POST" class="app-nav-inline-form">
                                <input type="hidden" name="action" value="logout">
                                <button type="submit" class="app-nav-link">Logout</button>
                            </form>
                        </li>
                    </ul>
                </nav>

                <div class="report-filters">
                    <label for="reportFilter">Report Filters:</label>
                    <select id="reportFilter" onchange="filterTableRows(this.value)">
                        <option value="all">All</option>
                        <option value="keep">Keep</option>
                        <option value="pending_cancelled">Pending Cancelled</option>
                        <option value="confirmed_cancelled">Confirmed Cancelled</option>
                    </select>
                    <button type="button" class="bulk-action-btn" data-open-modal="appModalBulkActions">Bulk Actions</button>
                    <button type="button" id="togglePurposeColumnBtn" class="column-toggle-btn" aria-pressed="false">Show Purpose</button>
                </div>
                
                <div class="cost-calculator-table-wrapper">
                    <table class="cost-calculator-grid" id="costCalculatorGrid">
                    <thead>
                        <tr>
                            <th class="select-row">
                                <input type="checkbox" id="selectAllVendors" aria-label="Select all vendors">
                            </th>
                            <th class="item-number">Item #</th>
                            <th class="vendor-name">Vendor</th>
                            <th class="cost-per-period">Cost</th>
                            <th class="frequency">Freq</th>
                            <th class="annual-cost">Annual Cost</th>
                            <th class="manager-col">Manager</th>
                            <th class="visibility-col">Visibility</th>
                            <th class="cancel-keep" title="Cancel = cancel this cost">Cancel/Keep</th>
                            <th class="cancel-deadline">Deadline</th>
                            <th class="notes">Purpose</th>
                        </tr>
                    </thead>
                    <tbody id="calculatorRows">
                        <!-- Rows will be added dynamically -->
                    </tbody>
                </table>
                
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
            const isAdminUser = <?php echo $is_admin ? 'true' : 'false'; ?>;
            const autoStartProjectWizard = <?php echo !empty($_SESSION['project_onboarding_required']) ? 'true' : 'false'; ?>;

            function postJson(data) {
                return fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
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
                    })
                    .catch(function() {});
            }

            function submitProjectWizardForm() {
                const form = document.getElementById('projectWizardForm');
                if (!form) return;
                const memberSel = document.getElementById('projectWizardMembers');
                const memberIds = [];
                if (memberSel) {
                    Array.from(memberSel.selectedOptions || []).forEach(function(opt) {
                        memberIds.push(parseInt(opt.value, 10));
                    });
                }
                const payload = {
                    action: 'project_create',
                    project_name: (document.getElementById('projectWizardName') || {}).value || '',
                    start_date: (document.getElementById('projectWizardStartDate') || {}).value || '',
                    end_date: (document.getElementById('projectWizardEndDate') || {}).value || '',
                    member_ids: memberIds,
                    copy_from_active: ((document.getElementById('projectWizardCopyFromActive') || {}).checked ? 1 : 0),
                    source_project_id: currentActiveProjectId || 0,
                };
                postJson(payload)
                    .then(function(d) {
                        if (!d || !d.success) {
                            showSnackbar((d && d.error) || 'Could not create project.', 'error');
                            return;
                        }
                        showSnackbar("You're done! Project created.", 'success');
                        closeAppModal('appModalProjectWizard');
                        loadProjectsIntoMenu().then(function() { loadCalculatorData(); });
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
                var modal = overlay.querySelector('.app-modal');
                resetAppModalPosition(modal);
                overlay.classList.remove('is-open');
                overlay.setAttribute('aria-hidden', 'true');
                if (!document.querySelector('.app-modal-overlay.is-open')) {
                    document.body.style.overflow = '';
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
                            + '<th>Date</th><th>Amount</th><th>Transaction Type</th><th>Account</th><th>Memo/Description</th>'
                            + '</tr></thead><tbody>';
                        rows.forEach(function(row) {
                            var date = aiEscapeHtml(String(row.transaction_date || ''));
                            var amountNum = parseFloat(row.amount || 0);
                            var amount = '$' + (isNaN(amountNum) ? '0.00' : amountNum.toFixed(2));
                            var type = aiEscapeHtml(String(row.transaction_type || ''));
                            var account = aiEscapeHtml(String(row.account || ''));
                            var memo = aiEscapeHtml(String(row.memo || ''));
                            html += '<tr><td>' + date + '</td><td>' + amount + '</td><td>' + type + '</td><td>' + account + '</td><td>' + memo + '</td></tr>';
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
                        if (e.target === overlay) closeAppModal(overlay);
                    });
                    overlay.querySelectorAll('.app-modal-close').forEach(function(b) {
                        b.addEventListener('click', function() { closeAppModal(overlay); });
                    });
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key !== 'Escape') return;
                    document.querySelectorAll('.app-modal-overlay.is-open').forEach(function(ov) {
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
                var csvIn = document.getElementById('csvImportInput');
                var csvBtn = document.getElementById('appImportCsvBtn');
                if (csvBtn && csvIn) {
                    csvBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        csvIn.click();
                    });
                }
            }
            const TEAM_MEMBERS = <?php echo $team_members_json; ?>;
            const IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
            const CURRENT_USER_ID = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;
            let pendingBulkActionData = null;

            function getVendorRowCheckboxes() {
                return Array.from(document.querySelectorAll('#calculatorRows .row-select-checkbox'));
            }

            function getSelectedVendorRows() {
                return getVendorRowCheckboxes()
                    .filter(function(cb) { return cb.checked; })
                    .map(function(cb) { return cb.closest('tr'); })
                    .filter(Boolean);
            }

            function updateSelectAllCheckboxState() {
                const selectAll = document.getElementById('selectAllVendors');
                if (!selectAll) return;
                const checkboxes = getVendorRowCheckboxes();
                const selectedCount = checkboxes.filter(function(cb) { return cb.checked; }).length;
                if (!checkboxes.length) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                    return;
                }
                selectAll.checked = selectedCount === checkboxes.length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
            }

            function setAllRowSelection(checked) {
                getVendorRowCheckboxes().forEach(function(cb) {
                    cb.checked = !!checked;
                });
                updateSelectAllCheckboxState();
            }

            function clearRowSelection() {
                setAllRowSelection(false);
            }

            function updateBulkActionFields() {
                const actionSel = document.getElementById('bulkActionType');
                const frequencyWrap = document.getElementById('bulkFrequencyWrap');
                const visibilityWrap = document.getElementById('bulkVisibilityWrap');
                const managerWrap = document.getElementById('bulkManagerWrap');
                if (!actionSel || !frequencyWrap || !visibilityWrap || !managerWrap) return;
                const action = actionSel.value;
                frequencyWrap.style.display = action === 'frequency' ? '' : 'none';
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
                if (action === 'visibility') {
                    const vis = document.getElementById('bulkVisibilityValue');
                    if (!vis || !vis.value) return null;
                    return { action: action, value: vis.value, label: 'Update visibility to ' + vis.value };
                }
                if (action === 'manager') {
                    const mgr = document.getElementById('bulkManagerValue');
                    if (!mgr || !mgr.value) return null;
                    const managerName = mgr.options[mgr.selectedIndex] ? mgr.options[mgr.selectedIndex].text : mgr.value;
                    return { action: action, value: mgr.value, label: 'Update manager to ' + managerName };
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
                    + '<div><strong>Selected records:</strong> ' + selectedCount + '</div>'
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
                        if (mgrSel && !mgrSel.disabled) mgrSel.value = payload.value;
                    });
                }
                updateRowNumbers();
                calculateAnnualSavings();
                calculateConfirmedSavings();
                clearRowSelection();
                saveCalculatorData();
                showSnackbar('Applied bulk action to ' + selectedRows.length + ' vendor record(s).', 'success');
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
                            <button type="button" class="vendor-raw-btn" disabled title="View imported raw transaction history">Raw</button>
                        </div>
                    </td>
                    <td class="cost-per-period">
                        <input type="text" name="cost[]" class="cost-input" placeholder="$0.00" data-row="${rowCount}" />
                    </td>
                    <td class="frequency">
                        <select name="frequency[]" class="frequency-select" data-row="${rowCount}">
                            <option value="">Select</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi_annual">Semi-annual</option>
                            <option value="annually">Annually</option>
                        </select>
                    </td>
                    <td class="annual-cost">
                        <span class="annual-cost-display" data-row="${rowCount}">$0.00</span>
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
                    <td class="cancel-keep">
                        <select name="cancel_keep[]" class="cancel-keep-select" data-row="${rowCount}">
                            <option value="Keep">Keep</option>
                            <option value="Cancel">Cancel</option>
                        </select>
                        <label class="cancelled-status-inline" title="Confirmed cancellation">
                            <input type="checkbox" name="cancelled_status[]" class="cancelled-status-checkbox" data-row="${rowCount}" />
                            <span>Confirmed</span>
                        </label>
                    </td>
                    <td class="cancel-deadline">
                        <input type="date" class="cancel-deadline-input" data-row="${rowCount}" />
                    </td>
                    <td class="notes">
                        <textarea name="notes[]" class="purpose-textarea" rows="2" placeholder="Purpose of subscription"></textarea>
                        <input type="hidden" class="last-payment-input" data-row="${rowCount}" />
                    </td>
                `;
                
                tbody.appendChild(row);
                
                // Attach event listeners (with auto-save)
                attachRowListenersWithSave(row);
                updateVendorDrilldownState(row);
                const rowCheckbox = row.querySelector('.row-select-checkbox');
                if (rowCheckbox) rowCheckbox.addEventListener('change', updateSelectAllCheckboxState);

                // New rows default to "Keep" — disable Confirmed checkbox immediately
                syncConfirmedCheckbox(row);
                
                // Update row numbers
                updateRowNumbers();
            }
            
            function attachRowListeners(row) {
                const costInput = row.querySelector('.cost-input');
                const frequencySelect = row.querySelector('.frequency-select');
                const cancelKeepSelect = row.querySelector('.cancel-keep-select');
                const cancelledStatusCheckbox = row.querySelector('.cancelled-status-checkbox');
                
                costInput.addEventListener('input', calculateAnnualCost);
                frequencySelect.addEventListener('change', calculateAnnualCost);
                cancelKeepSelect.addEventListener('change', calculateAnnualSavings);
                if (cancelledStatusCheckbox) {
                    cancelledStatusCheckbox.addEventListener('change', calculateConfirmedSavings);
                }
            }
            
            function calculateAnnualCost(event) {
                const row = event.target.closest('tr');
                const costInput = row.querySelector('.cost-input');
                const frequencySelect = row.querySelector('.frequency-select');
                const annualCostDisplay = row.querySelector('.annual-cost-display');
                
                const cost = parseFloat(costInput.value.replace(/[^0-9.-]/g, '')) || 0;
                const frequency = frequencySelect.value;
                
                let multiplier = 0;
                switch(frequency) {
                    case 'weekly': multiplier = 52; break;
                    case 'monthly': multiplier = 12; break;
                    case 'quarterly': multiplier = 4; break;
                    case 'semi_annual': multiplier = 2; break;
                    case 'annually': multiplier = 1; break;
                }
                
                const annualCost = cost * multiplier;
                annualCostDisplay.textContent = formatCurrency(annualCost);
                
                calculateAnnualSavings();
            }
            
            function calculateAnnualSavings() {
                const rows = document.querySelectorAll('#calculatorRows tr');
                let totalSavings = 0;
                
                rows.forEach(row => {
                    const cancelKeep = row.querySelector('.cancel-keep-select').value;
                    const annualCostText = row.querySelector('.annual-cost-display').textContent;
                    const annualCost = parseFloat(annualCostText.replace(/[^0-9.-]/g, '')) || 0;
                    
                    if (cancelKeep === 'Cancel') {
                        totalSavings += annualCost;
                    }
                });
                
                document.getElementById('potentialSavings').textContent = formatCurrency(totalSavings);
                calculateConfirmedSavings(); // Recalculate confirmed savings when potential changes
            }

            function calculateConfirmedSavings() {
                const rows = document.querySelectorAll('#calculatorRows tr');
                let totalConfirmedSavings = 0;
                
                rows.forEach(row => {
                    const cancelledCheckbox = row.querySelector('.cancelled-status-checkbox');
                    const annualCostText = row.querySelector('.annual-cost-display').textContent;
                    const annualCost = parseFloat(annualCostText.replace(/[^0-9.-]/g, '')) || 0;
                    
                    if (cancelledCheckbox && cancelledCheckbox.checked) {
                        totalConfirmedSavings += annualCost;
                    }
                });
                
                document.getElementById('confirmedSavings').textContent = formatCurrency(totalConfirmedSavings);
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
                    let value = e.target.value.replace(/[^0-9.-]/g, '');
                    if (value) {
                        const numValue = parseFloat(value);
                        if (!isNaN(numValue)) {
                            e.target.value = '$' + numValue.toFixed(2);
                            calculateAnnualCost(e);
                        }
                    }
                }
            }, true);
            
            // Auto-save function (debounced — fast refresh could miss this; see flushSaveOnLeave + immediate save on Cancel/Keep)
            let saveTimeout;
            /** True while repopulating rows from the server — avoids save races (partial DELETE/INSERT) from synthetic events. */
            let calculatorLoadInProgress = false;
            /** Serialize saves: server replaces all rows per request; overlapping saves must not complete out of order. */
            let saveQueue = Promise.resolve();
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
                if (calculatorLoadInProgress && !keepalive) {
                    return;
                }
                saveQueue = saveQueue.then(function () {
                    return performSaveCalculatorData(keepalive, silent);
                }).catch(function (e) {
                    console.error('Calculator save queue:', e);
                });
                return saveQueue;
            }
            
            function getCancelKeepFromRow(row) {
                const sel = row.querySelector('select.cancel-keep-select');
                if (!sel) {
                    return 'Keep';
                }
                let v = (sel.value !== undefined && sel.value !== null && sel.value !== '') ? String(sel.value).trim() : '';
                if (v !== 'Keep' && v !== 'Cancel') {
                    const idx = sel.selectedIndex;
                    if (idx >= 0 && sel.options[idx]) {
                        const opt = sel.options[idx];
                        const ov = String(opt.value || '').trim();
                        if (ov === 'Cancel' || ov === 'Keep') {
                            v = ov;
                        } else {
                            const t = String(opt.text || opt.textContent || '').trim().toLowerCase();
                            if (t === 'cancel') v = 'Cancel';
                            else if (t === 'keep') v = 'Keep';
                        }
                    }
                }
                return (v === 'Cancel') ? 'Cancel' : 'Keep';
            }
            
            function performSaveCalculatorData(keepalive, silent) {
                const rows = document.querySelectorAll('#calculatorRows tr');
                const items = [];
                
                rows.forEach(row => {
                    const vendorInput = row.querySelector('input[name="vendor[]"]');
                    const costInput = row.querySelector('.cost-input');
                    const frequencySelect = row.querySelector('.frequency-select');
                    const cancelledCheckbox = row.querySelector('.cancelled-status-checkbox');
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
                    const cancelKeep = getCancelKeepFromRow(row);
                    const cancelledStatus = cancelledCheckbox ? cancelledCheckbox.checked : false;
                    const notes = notesTextarea ? notesTextarea.value.trim() : '';
                    const annualCost = annualCostDisplay ? parseFloat(annualCostDisplay.textContent.replace(/[^0-9.-]/g, '')) || 0 : 0;
                    const idVal = rowIdEl && rowIdEl.value ? parseInt(rowIdEl.value, 10) : null;
                    const managerId = mgrSel && mgrSel.value ? parseInt(mgrSel.value, 10) : null;
                    const visibility = visSel ? visSel.value : 'public';
                    const cancelDl = deadlineIn && deadlineIn.value ? deadlineIn.value : '';
                    const lastPay = lastPayIn && lastPayIn.value ? lastPayIn.value : '';
                    
                    if (vendorName || costPerPeriod > 0 || cancelKeep !== 'Keep' || cancelledStatus || notes || idVal) {
                        const o = {
                            vendor_name: vendorName,
                            cost_per_period: costPerPeriod,
                            frequency: frequency,
                            annual_cost: annualCost,
                            cancel_keep: cancelKeep,
                            cancelKeep: cancelKeep,
                            cancelled_status: cancelledStatus ? 1 : 0,
                            notes: notes,
                            purpose_of_subscription: notes,
                            visibility: visibility,
                            cancellation_deadline: cancelDl,
                            last_payment_date: lastPay
                        };
                        if (idVal) { o.id = idVal; }
                        if (managerId) { o.manager_user_id = managerId; }
                        items.push(o);
                    }
                });
                
                const payload = { action: 'save_cost_calculator', items: items };
                
                return fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                    body: JSON.stringify(payload),
                    keepalive: keepalive
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        console.log('Data saved successfully');
                    } else {
                        console.error('Error saving data:', data.error);
                        if (!silent) {
                            alert('Error saving data: ' + (data.error || 'Unknown error'));
                        }
                    }
                })
                .catch(error => {
                    console.error('Error saving:', error);
                    if (!silent) {
                        alert('Error saving data. Please check console for details.');
                    }
                });
            }
            
            function flushSaveOnLeave() {
                clearTimeout(saveTimeout);
                saveCalculatorData({ keepalive: true, silent: true });
            }
            
            function loadCalculatorData() {
                const formData = new FormData();
                formData.append('action', 'load_cost_calculator');
                
                fetch(window.location.href, {
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
                            
                            // Load saved items (do not dispatch change on cancel/keep — that triggered immediate saves mid-load and corrupted rows)
                            data.items.forEach(item => {
                                addCalculatorRow();
                                const lastRow = document.querySelector('#calculatorRows tr:last-child');
                                if (lastRow) {
                                    const rowIdEl = lastRow.querySelector('.row-db-id');
                                    if (rowIdEl && item.id) rowIdEl.value = String(item.id);
                                    const vendorInput = lastRow.querySelector('input[name="vendor[]"]');
                                    const costInput = lastRow.querySelector('.cost-input');
                                    const frequencySelect = lastRow.querySelector('.frequency-select');
                                    const cancelKeepSelect = lastRow.querySelector('.cancel-keep-select');
                                    const cancelledCheckbox = lastRow.querySelector('.cancelled-status-checkbox');
                                    const notesTextarea = lastRow.querySelector('textarea.purpose-textarea');
                                    const mgr = lastRow.querySelector('.manager-select');
                                    const vis = lastRow.querySelector('.visibility-select');
                                    const dl = lastRow.querySelector('.cancel-deadline-input');
                                    const lp = lastRow.querySelector('.last-payment-input');
                                    
                                    if (vendorInput) vendorInput.value = item.vendor_name || '';
                                    updateVendorDrilldownState(lastRow);
                                    if (costInput) costInput.value = item.cost_per_period > 0 ? '$' + parseFloat(item.cost_per_period).toFixed(2) : '';
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
                                    
                                    if (cancelKeepSelect) {
                                        const savedValue = item.cancel_keep ? item.cancel_keep.toString().trim() : 'Keep';
                                        cancelKeepSelect.value = savedValue;
                                        if (cancelKeepSelect.value !== savedValue) {
                                            const options = Array.from(cancelKeepSelect.options);
                                            const matchingOption = options.find(opt => opt.value === savedValue || opt.value.toLowerCase() === savedValue.toLowerCase());
                                            if (matchingOption) {
                                                cancelKeepSelect.value = matchingOption.value;
                                            } else {
                                                cancelKeepSelect.value = 'Keep';
                                            }
                                        }
                                    }
                                    
                                    syncConfirmedCheckbox(lastRow);
                                    if (cancelledCheckbox) cancelledCheckbox.checked = (item.cancelled_status == 1 || item.cancelled_status === true);
                                    if (notesTextarea) notesTextarea.value = item.purpose_of_subscription || item.notes || '';
                                    
                                    if (costInput && frequencySelect) {
                                        const event = new Event('input');
                                        costInput.dispatchEvent(event);
                                    }
                                }
                            });
                            
                            calculateAnnualSavings();
                            calculateConfirmedSavings();
                            clearRowSelection();
                        } else {
                            addCalculatorRow();
                            clearRowSelection();
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
                    } finally {
                        calculatorLoadInProgress = false;
                    }
                });
            }
            
            // Update event listeners to trigger auto-save
            function syncConfirmedCheckbox(row) {
                const cancelKeepSelect = row.querySelector('.cancel-keep-select');
                const cancelledCheckbox = row.querySelector('.cancelled-status-checkbox');
                if (!cancelKeepSelect || !cancelledCheckbox) return;
                const isKeep = cancelKeepSelect.value === 'Keep';
                cancelledCheckbox.disabled = isKeep;
                if (isKeep) {
                    cancelledCheckbox.checked = false;
                }
            }

            function attachRowListenersWithSave(row) {
                const costInput = row.querySelector('.cost-input');
                const frequencySelect = row.querySelector('.frequency-select');
                const cancelKeepSelect = row.querySelector('.cancel-keep-select');
                const cancelledCheckbox = row.querySelector('.cancelled-status-checkbox');
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
                    });
                }
                
                if (cancelKeepSelect) {
                    cancelKeepSelect.addEventListener('change', function(e) {
                        syncConfirmedCheckbox(row);
                        calculateAnnualSavings();
                        clearTimeout(saveTimeout);
                        saveCalculatorData();
                        // Update filter if active
                        const filterSelect = document.getElementById('reportFilter');
                        if (filterSelect) {
                            filterTableRows(filterSelect.value);
                        }
                    });
                }
                
                if (cancelledCheckbox) {
                    cancelledCheckbox.addEventListener('change', function(e) {
                        calculateConfirmedSavings();
                        clearTimeout(saveTimeout);
                        saveCalculatorData();
                        // Update filter if active
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
                    notesTextarea.addEventListener('blur', autoSave);
                }
                [mgrSel, visSel].forEach(function(el) {
                    if (el) el.addEventListener('change', autoSave);
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
                if (!rawBtn || !vendorInput) return;
                const idVal = idEl && idEl.value ? parseInt(idEl.value, 10) : 0;
                const vendorName = vendorInput.value ? vendorInput.value.trim() : '';
                const enabled = vendorName !== '';
                // Keep button clickable so users always get immediate feedback.
                rawBtn.disabled = false;
                rawBtn.setAttribute('data-vendor-name', enabled ? vendorName : '');
                rawBtn.title = enabled ? ('View raw transactions for ' + vendorName) : 'Enter a vendor name first';
            }
            
            // Override attachRowListeners to use the new version with auto-save
            const originalAttachRowListeners = attachRowListeners;
            attachRowListeners = attachRowListenersWithSave;
            
            // Filter table rows based on selected filter
            function filterTableRows(filterValue) {
                const rows = document.querySelectorAll('#calculatorRows tr');
                rows.forEach(row => {
                    const cancelKeepSelect = row.querySelector('.cancel-keep-select');
                    const cancelledCheckbox = row.querySelector('.cancelled-status-checkbox');
                    
                    let showRow = false;
                    
                    switch(filterValue) {
                        case 'all':
                            showRow = true;
                            break;
                        case 'keep':
                            if (cancelKeepSelect && cancelKeepSelect.value === 'Keep') {
                                showRow = true;
                            }
                            break;
                        case 'pending_cancelled':
                            if (cancelKeepSelect && cancelKeepSelect.value === 'Cancel' && 
                                cancelledCheckbox && !cancelledCheckbox.checked) {
                                showRow = true;
                            }
                            break;
                        case 'confirmed_cancelled':
                            if (cancelledCheckbox && cancelledCheckbox.checked) {
                                showRow = true;
                            }
                            break;
                    }
                    
                    row.style.display = showRow ? '' : 'none';
                });
                
                // Update row numbers after filtering
                updateRowNumbers();
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
                initNavSubmenus();
                initPurposeColumnToggle();
                loadProjectsIntoMenu().then(function() {
                    if (autoStartProjectWizard && isAdminUser) {
                        openAppModal('appModalProjectWizard');
                    }
                });
                syncBulkManagerOptions();
                const projectSwitcher = document.getElementById('projectSwitcherSelect');
                if (projectSwitcher) {
                    projectSwitcher.addEventListener('change', function() {
                        const nextProjectId = parseInt(this.value, 10) || 0;
                        if (!nextProjectId) return;
                        postJson({ action: 'project_set_active', project_id: nextProjectId })
                            .then(function(d) {
                                if (!d || !d.success) {
                                    showSnackbar((d && d.error) || 'Could not switch project.', 'error');
                                    return;
                                }
                                currentActiveProjectId = nextProjectId;
                                loadCalculatorData();
                            })
                            .catch(function() {
                                showSnackbar('Could not switch project.', 'error');
                            });
                    });
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
                const selectAll = document.getElementById('selectAllVendors');
                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        setAllRowSelection(selectAll.checked);
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
                const calculatorRowsEl = document.getElementById('calculatorRows');
                if (calculatorRowsEl) {
                    calculatorRowsEl.addEventListener('click', function(e) {
                        const rawBtn = e.target.closest('.vendor-raw-btn');
                        if (!rawBtn) return;
                        const row = rawBtn.closest('tr');
                        const vendorInput = row ? row.querySelector('input[name="vendor[]"]') : null;
                        const vendorName = ((rawBtn.getAttribute('data-vendor-name') || '').trim() || (vendorInput ? vendorInput.value.trim() : ''));
                        if (!vendorName) {
                            showSnackbar('Enter a vendor name first.', 'error');
                            return;
                        }
                        loadVendorRawDataModal(vendorName);
                    });
                }
                loadCalculatorData();
                var csvIn = document.getElementById('csvImportInput');
                if (csvIn) {
                    csvIn.addEventListener('change', function() {
                        var f = this.files[0];
                        if (!f) return;
                        var fd = new FormData();
                        fd.append('action', 'import_vendor_csv');
                        fd.append('csv_file', f);
                        fetch(window.location.href, { method: 'POST', body: fd })
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                if (d.success) {
                                    const rawCount = parseInt(d.raw_inserted || 0, 10) || 0;
                                    showSnackbar('Imported ' + (d.inserted || 0) + ' vendor(s), ' + rawCount + ' raw transactions', 'success');
                                    loadCalculatorData();
                                } else {
                                    showSnackbar(d.error || 'Import failed', 'error');
                                }
                            })
                            .catch(function() { showSnackbar('Import failed', 'error'); });
                        this.value = '';
                    });
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
                                + '<th>Date</th><th>Amount</th><th>Transaction Type</th><th>Account</th><th>Memo/Description</th>'
                                + '</tr></thead><tbody>';
                            rows.forEach(function(row) {
                                var date = aiEscapeHtml(String(row.transaction_date || ''));
                                var amountNum = parseFloat(row.amount || 0);
                                var amount = '$' + (isNaN(amountNum) ? '0.00' : amountNum.toFixed(2));
                                var type = aiEscapeHtml(String(row.transaction_type || ''));
                                var account = aiEscapeHtml(String(row.account || ''));
                                var memo = aiEscapeHtml(String(row.memo || ''));
                                html += '<tr><td>' + date + '</td><td>' + amount + '</td><td>' + type + '</td><td>' + account + '</td><td>' + memo + '</td></tr>';
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
                        + (hint ? '<br><span style="font-size:11px;color:#6b5b80;">' + aiEscapeHtml(hint) + '</span>' : '');
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
                    log.scrollTop = log.scrollHeight;
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
                    resultRows.forEach(function(r) {
                        if (r && r.id) byId[String(r.id)] = String(r.purpose || '');
                    });
                    document.querySelectorAll('#calculatorRows tr').forEach(function(row) {
                        var idEl = row.querySelector('.row-db-id');
                        var idVal = idEl && idEl.value ? String(parseInt(idEl.value, 10) || 0) : '';
                        if (!idVal || !byId[idVal]) return;
                        var notesTextarea = row.querySelector('textarea.purpose-textarea') || row.querySelector('textarea[name="notes[]"]');
                        if (notesTextarea) notesTextarea.value = byId[idVal];
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
                function triggerAutoPopulatePurpose() {
                    appendAiChatMessage('user', 'Auto populate purpose');
                    var rows = collectVisibleVendorRowsForPurposeLookup();
                    if (!rows.length) {
                        appendAiChatMessage('assistant', 'No saved vendor rows found on screen.', false);
                        showSnackbar('No saved vendor rows found on screen.', 'error');
                        return;
                    }
                    var fd2 = new FormData();
                    fd2.append('action', 'auto_populate_purpose');
                    fd2.append('rows', JSON.stringify(rows));
                    setAiUiBusy(true);
                    fetch(window.location.href, { method: 'POST', body: fd2 })
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            setAiUiBusy(false);
                            if (d.success) {
                                var appliedRows = filterResolvedRowsById(d.resolved || [], d.applied_ids || []);
                                applyPurposeLookupResultsToUi(appliedRows);
                                if (appliedRows.length) {
                                    // Programmatic textarea updates do not fire blur/input listeners; persist immediately.
                                    clearTimeout(saveTimeout);
                                    saveCalculatorData({ silent: true });
                                }
                                var unresolved = Array.isArray(d.unresolved) ? d.unresolved.length : 0;
                                var applied = typeof d.applied === 'number' ? d.applied : 0;
                                var changed = typeof d.updated === 'number' ? d.updated : 0;
                                var statusText = 'Auto populate finished. Applied to ' + applied + ' rows.';
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
                                appendAiChatMessage('assistant', d.error || 'Auto populate failed.', false);
                                showSnackbar(d.error || 'Auto populate failed.', 'error');
                            }
                        })
                        .catch(function() {
                            setAiUiBusy(false);
                            appendAiChatMessage('assistant', 'Request failed.', false);
                            showSnackbar('Auto populate request failed.', 'error');
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
                <h2 id="appModalMembersInviteTitle">Invite Member</h2>
                <button type="button" class="app-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="app-modal-body">
                <p style="margin:0 0 10px;color:#4b5563;font-size:14px;">
                    Usage: <strong><?php echo (int) $team_members_count; ?>/<?php echo (int) $team_members_max; ?></strong> members
                </p>
                <div class="invite-block">
                    <form method="POST" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                        <input type="hidden" name="action" value="invite_member">
                        <label>Invite member email</label>
                        <input type="email" name="email" required placeholder="member@company.com" style="min-width:200px;">
                        <button type="submit">Send invite</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

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
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($team_members_rows)): ?>
                            <tr><td colspan="3">No members in this organization yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($team_members_rows as $tm): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tm['display_name'] ?? $tm['username'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($tm['email'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($tm['role'] ?? 'member'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

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
                    <label>End date
                        <input type="date" id="projectWizardEndDate">
                    </label>
                    <label>
                        <input type="checkbox" id="projectWizardUploadDataHint">
                        I will upload data after creating this project.
                    </label>
                    <?php if ($is_admin): ?>
                    <label>
                        <input type="checkbox" id="projectWizardCopyFromActive">
                        Copy data from current active project.
                    </label>
                    <?php endif; ?>
                    <label>Assign members
                        <select id="projectWizardMembers" multiple size="6">
                            <?php foreach ($team_members_rows as $tm): ?>
                            <option value="<?php echo (int) ($tm['id'] ?? 0); ?>"><?php echo htmlspecialchars(($tm['display_name'] ?? $tm['username'] ?? 'Member') . ' (' . ($tm['email'] ?? '') . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p style="margin:0;color:#4b5563;font-size:13px;">Select one or more members. Users not assigned will not be able to access this project.</p>
                    <div style="display:flex;gap:8px;justify-content:flex-end;">
                        <button type="button" class="btn-secondary app-modal-close">Cancel</button>
                        <button type="submit">You're done!</button>
                    </div>
                </form>
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
                        <button type="button" class="btn-secondary ai-preset" data-preset="alternatives">Cheaper alternatives</button>
                        <button type="button" class="btn-secondary ai-preset" data-preset="lower_tiers">Lower service tiers</button>
                        <button type="button" class="btn-secondary ai-preset" data-preset="duplicates">Duplicate subscriptions</button>
                        <button type="button" class="btn-secondary ai-preset" data-preset="executive">Executive summary suggestions</button>
                    </div>
                    <div class="ai-composer">
                        <textarea id="aiQuestion" class="ai-question-input" rows="2" placeholder="Ask a specific question..."></textarea>
                        <button type="button" id="aiSubmitBtn" class="ai-submit-btn">Ask</button>
                    </div>
                    <div id="aiChatLog" class="chat-container ai-chat-log" aria-label="AI Assistant conversation"></div>
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
                    <form method="POST" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
                        <input type="hidden" name="action" value="save_reminder_settings">
                        <?php if ($is_admin): ?>
                        <label><input type="checkbox" name="deadline_reminders_enabled" value="1" <?php echo $deadline_reminders_org ? 'checked' : ''; ?>> Org: deadline email assistant</label>
                        <?php endif; ?>
                        <label style="font-size:14px;"><input type="checkbox" name="user_deadline_reminders" value="1" <?php echo $deadline_reminders_user ? 'checked' : ''; ?>> My deadline reminders</label>
                        <button type="submit">Save</button>
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
