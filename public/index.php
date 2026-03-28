<?php
session_start();

// Load configuration
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_config.php';

function normalizeUserEmail($email) {
    return strtolower(trim($email));
}

if (isset($_SESSION['user_email'])) {
    $_SESSION['user_email'] = normalizeUserEmail($_SESSION['user_email']);
}

// Try to load PHPMailer - handle different possible paths
$phpmailer_paths = [
    __DIR__ . '/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php'
];

$phpmailer_loaded = false;
foreach ($phpmailer_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        require_once dirname($path) . '/SMTP.php';
        require_once dirname($path) . '/Exception.php';
        $phpmailer_loaded = true;
        break;
    }
}

// Only use PHPMailer classes if they were successfully loaded
if ($phpmailer_loaded) {
    // PHPMailer classes are available
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
    error_log("POST request received");
    error_log("POST data: " . print_r($_POST, true));
    
    if (isset($_POST['action'])) {
        error_log("Action: " . $_POST['action']);
        switch ($_POST['action']) {
            case 'send_otp':
                error_log("Calling handleSendOTP()");
                handleSendOTP();
                break;
            case 'verify_otp':
                handleVerifyOTP();
                break;
            case 'save_user_role':
                handleSaveUserRole();
                break;
            case 'change_email':
                handleChangeEmail();
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
        }
    }
}

// Functions
function sendEmail($to, $subject, $body) {
    global $phpmailer_loaded;
    
    error_log("sendEmail called for: " . $to);
    error_log("PHPMailer loaded: " . ($phpmailer_loaded ? 'Yes' : 'No'));
    error_log("PHPMailer class exists: " . (class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'Yes' : 'No'));
    
    // Check if PHPMailer is available
    if (!$phpmailer_loaded || !class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer not available, using PHP mail() function");
        
        // Fallback to PHP's built-in mail function
        $headers = array(
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
            'Reply-To: ' . SMTP_FROM_EMAIL,
            'X-Mailer: PHP/' . phpversion()
        );
        
        $result = mail($to, $subject, $body, implode("\r\n", $headers));
        error_log("PHP mail() result: " . ($result ? 'Success' : 'Failed'));
        
        if ($result) {
            return true;
        } else {
            return [
                'success' => false,
                'error_message' => 'PHP mail() function failed',
                'error_info' => 'Server mail configuration may be incorrect or mail service unavailable'
            ];
        }
    }

    error_log("Using PHPMailer for email sending");
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Enable verbose debug output (0 = off, 1 = client messages, 2 = client and server messages)
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer Debug (Level $level): $str");
        };
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        
        // Additional SMTP options
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        error_log("PHPMailer: Attempting to send email to $to");
        $mail->send();
        error_log("PHPMailer: Email sent successfully to $to");
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("PHPMailer sending failed: " . $e->getMessage());
        error_log("PHPMailer ErrorInfo: " . $mail->ErrorInfo);
        // Return error details for display
        return [
            'success' => false,
            'error_message' => $e->getMessage(),
            'error_info' => $mail->ErrorInfo
        ];
    }
}

function handleSendOTP() {
    error_log("handleSendOTP() called");
    $email = normalizeUserEmail($_POST['email'] ?? '');
    error_log("Email from POST: " . $email);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email address.';
        error_log("Invalid email address: " . $email);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $agreeTerms = isset($_POST['agree_terms']) && $_POST['agree_terms'] === 'on';
    if (!$agreeTerms) {
        $_SESSION['error'] = 'You must agree to the terms of use to continue.';
        error_log('Terms of use not agreed for email: ' . $email);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $otp = rand(100000, 999999);
    
    // Ensure cache directory exists
    if (!is_dir(CACHE_DIR)) {
        if (!mkdir(CACHE_DIR, 0755, true)) {
            $_SESSION['error'] = 'Unable to create cache directory. Please check file permissions.';
            error_log("Failed to create cache directory: " . CACHE_DIR);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        error_log("Created cache directory: " . CACHE_DIR);
    }
    
    $otp_file = CACHE_DIR . md5($email) . '_otp.txt';
    $file_write_result = file_put_contents($otp_file, $otp);
    error_log("OTP generated: " . $otp . " for email: " . $email);
    error_log("OTP file path: " . $otp_file);
    error_log("File write result: " . ($file_write_result !== false ? 'Success' : 'Failed'));
    
    if ($file_write_result === false) {
        $_SESSION['error'] = 'Failed to save OTP. Please check file permissions.';
        error_log("Failed to write OTP file: " . $otp_file);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    $_SESSION['otp_email'] = $email;
    
    // Send OTP via email
    $subject = 'Your Savvy CFO Cost Savings Tool OTP Code';
    $body = '
    <html>
    <head>
        <title>Your OTP Code</title>
    </head>
    <body>
        <h2>Savvy CFO Cost Savings Tool Access</h2>
        <p>Your One-Time Password (OTP) code is: <strong>' . $otp . '</strong></p>
        <p>This code will expire in 10 minutes.</p>
        <p>If you did not request this code, please ignore this email.</p>
        <br>
        <p>Best regards,<br>Savvy CFO Portal Team</p>
    </body>
    </html>';
    
    $email_result = sendEmail($email, $subject, $body);
    error_log("Email result: " . print_r($email_result, true));
    
    if ($email_result === true) {
        $_SESSION['message'] = 'OTP sent to your email address successfully! Please check your inbox.';
        error_log("Setting success message");
    } elseif (is_array($email_result) && isset($email_result['error_message'])) {
        // Email failed but OTP was saved, so allow user to proceed with manual entry
        // Log OTP to error log for debugging
        error_log("OTP Email sending failed for: " . $email . ", Error: " . $email_result['error_message'] . ", Info: " . $email_result['error_info']);
        error_log("OTP CODE FOR MANUAL ENTRY: " . $otp);
        
        // For development/testing: show OTP in error message if email fails
        // Remove this in production and just show generic error
        $_SESSION['error'] = 'Email sending failed, but OTP has been generated. Please contact support or check server logs. (Debug: OTP logged to server)';
        // In production, use this instead:
        // $_SESSION['error'] = 'Failed to send email. Please try again or contact support.';
    } else {
        error_log("OTP Email sending failed for: " . $email . " - Unknown error");
        error_log("OTP CODE FOR MANUAL ENTRY: " . $otp);
        $_SESSION['error'] = 'Failed to send OTP email. Please try again.';
    }
    
    $_SESSION['show_otp'] = true;
    error_log("Set show_otp to true, redirecting...");
    
    // Redirect to prevent form resubmission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function handleVerifyOTP() {
    $email = normalizeUserEmail($_POST['email'] ?? '');
    $otp = $_POST['otp'] ?? '';
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Invalid email address.';
        return;
    }
    
    $stored_otp_file = CACHE_DIR . md5($email) . '_otp.txt';
    if (!file_exists($stored_otp_file)) {
        $_SESSION['error'] = 'OTP not found.';
        return;
    }
    
    $stored_otp = trim(file_get_contents($stored_otp_file));
    if ($stored_otp === $otp) {
        unlink($stored_otp_file);
        $_SESSION['user_email'] = $email;

        loadUserResponses($email);

        try {
            $existing_role = getUserRoleFromDB($email);
        } catch (Exception $e) {
            error_log('handleVerifyOTP getUserRoleFromDB: ' . $e->getMessage());
            $existing_role = null;
        }
        if ($existing_role !== null) {
            $_SESSION['user_role'] = $existing_role;
            unset($_SESSION['awaiting_role'], $_SESSION['pending_next_chapter']);
        } else {
            $_SESSION['awaiting_role'] = true;
        }

        unset($_SESSION['error'], $_SESSION['message'], $_SESSION['show_otp'], $_SESSION['otp_email']);
    } else {
        $_SESSION['error'] = 'Incorrect OTP.';
    }
}

function handleSaveUserRole() {
    if (!isset($_SESSION['user_email'])) {
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

    // Sync contact to GoHighLevel
    syncContactToGHL($email, $role);
}

function handleChangeEmail() {
    // Clear OTP related session variables to go back to email entry
    unset(
        $_SESSION['show_otp'],
        $_SESSION['otp_email'],
        $_SESSION['error'],
        $_SESSION['message'],
        $_SESSION['awaiting_role'],
        $_SESSION['pending_next_chapter'],
        $_SESSION['user_email'],
        $_SESSION['user_role']
    );
}





function handleLogout() {
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



// Database Functions (mysqli — cost savings tool data; user roles use PDO in db_config.php)
function getMysqliConnection() {
    static $conn = null;
    
    if ($conn === null) {
        try {
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($conn->connect_error) {
                error_log("Database connection failed: " . $conn->connect_error);
                return null;
            }
            
            $conn->set_charset(DB_CHARSET);
        } catch (Exception $e) {
            error_log("Database connection exception: " . $e->getMessage());
            return null;
        }
    }
    
    return $conn;
}

function createCostCalculatorTable() {
    $conn = getMysqliConnection();
    if (!$conn) {
        error_log("Cannot create table: Database connection failed");
        return false;
    }
    
    $sql = "CREATE TABLE IF NOT EXISTS cost_calculator_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(255) NOT NULL,
        vendor_name VARCHAR(255) DEFAULT NULL,
        cost_per_period DECIMAL(10, 2) DEFAULT 0.00,
        frequency VARCHAR(20) DEFAULT NULL,
        annual_cost DECIMAL(10, 2) DEFAULT 0.00,
        cancel_keep VARCHAR(10) DEFAULT 'Keep',
        cancelled_status TINYINT(1) DEFAULT 0,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_email (user_email),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($sql) === TRUE) {
        error_log("Cost savings tool table created or already exists");
        
        // Check and fix cancel_keep column type if needed (for existing tables)
        $checkCancelKeep = $conn->query("SHOW COLUMNS FROM cost_calculator_items WHERE Field = 'cancel_keep'");
        if (!$checkCancelKeep) {
            error_log("ERROR: Failed to check cancel_keep column: " . $conn->error);
        } elseif ($checkCancelKeep->num_rows > 0) {
            $columnInfo = $checkCancelKeep->fetch_assoc();
            $columnType = $columnInfo['Type'];
            error_log("Migration check: cancel_keep column type is: " . $columnType);
            
            // Check if column is not VARCHAR or CHAR (string type)
            if (stripos($columnType, 'varchar') === false && stripos($columnType, 'char') === false) {
                error_log("Migration needed: cancel_keep column is not VARCHAR/CHAR! Current type: " . $columnType);
                error_log("Executing ALTER TABLE to fix column type...");
                
                // Attempt to fix the column type
                $alterResult = $conn->query("ALTER TABLE cost_calculator_items MODIFY COLUMN cancel_keep VARCHAR(10) DEFAULT 'Keep'");
                if ($alterResult) {
                    error_log("SUCCESS: Migrated cancel_keep column type from " . $columnType . " to VARCHAR(10)");
                    
                    // Verify the change
                    $verifyCheck = $conn->query("SHOW COLUMNS FROM cost_calculator_items WHERE Field = 'cancel_keep'");
                    if ($verifyCheck && $verifyCheck->num_rows > 0) {
                        $verifyInfo = $verifyCheck->fetch_assoc();
                        error_log("Verification: cancel_keep column type is now: " . $verifyInfo['Type']);
                    }
                } else {
                    error_log("ERROR: Failed to migrate cancel_keep column: " . $conn->error);
                    error_log("SQL Error Code: " . $conn->errno);
                    // This is critical - we should not continue if the column is wrong type
                    return false;
                }
            } else {
                error_log("Column type is correct (VARCHAR/CHAR), no migration needed");
            }
        } else {
            error_log("WARNING: cancel_keep column not found in table - table may need to be recreated");
        }
        
        // Add cancelled_status column if it doesn't exist (for existing tables)
        $checkColumn = $conn->query("SHOW COLUMNS FROM cost_calculator_items LIKE 'cancelled_status'");
        if ($checkColumn && $checkColumn->num_rows == 0) {
            $conn->query("ALTER TABLE cost_calculator_items ADD COLUMN cancelled_status TINYINT(1) DEFAULT 0");
            error_log("Added cancelled_status column to cost_calculator_items table");
        }
        
        return true;
    } else {
        error_log("Error creating table: " . $conn->error);
        return false;
    }
}

/**
 * Normalize cancel_keep from DB or JSON to exactly "Keep" or "Cancel" for UI and storage.
 */
function normalizeCancelKeepValue($value) {
    if ($value === null || $value === '') {
        return 'Keep';
    }
    $s = trim((string) $value);
    if (strcasecmp($s, 'Cancel') === 0 || $s === '0') {
        return 'Cancel';
    }
    if (strcasecmp($s, 'Keep') === 0 || $s === '1') {
        return 'Keep';
    }
    return 'Keep';
}

/** Resolve cancel_keep from client payload (underscore, camelCase, or rare key mangling). */
function extractCancelKeepFromItem(array $item) {
    if (array_key_exists('cancel_keep', $item)) {
        return normalizeCancelKeepValue($item['cancel_keep']);
    }
    if (array_key_exists('cancelKeep', $item)) {
        return normalizeCancelKeepValue($item['cancelKeep']);
    }
    if (array_key_exists('cancel-keep', $item)) {
        return normalizeCancelKeepValue($item['cancel-keep']);
    }
    return 'Keep';
}

function saveCostCalculatorData($email, $items) {
    $conn = getMysqliConnection();
    if (!$conn) {
        return ['success' => false, 'error' => 'Database connection failed'];
    }
    
    // Ensure table exists
    createCostCalculatorTable();
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete existing items for this user
        $deleteStmt = $conn->prepare("DELETE FROM cost_calculator_items WHERE user_email = ?");
        $deleteStmt->bind_param("s", $email);
        $deleteStmt->execute();
        $deleteStmt->close();
        
        // Insert new items
        // One prepared statement per row avoids mysqli bind_param reference quirks with reused statements in a loop.
        $insertSql = "INSERT INTO cost_calculator_items 
            (user_email, vendor_name, cost_per_period, frequency, annual_cost, cancel_keep, cancelled_status, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        

        $cc = "";
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $vendorName = $item['vendor_name'] ?? '';
            $costPerPeriod = floatval($item['cost_per_period'] ?? 0);
            $frequency = $item['frequency'] ?? '';
            $annualCost = floatval($item['annual_cost'] ?? 0);
            $cancelKeep = extractCancelKeepFromItem($item);

            $cc = $cc . "-" . $cancelKeep;

            $cancelledStatus = isset($item['cancelled_status']) ? (int)$item['cancelled_status'] : 0;
            $notes = $item['notes'] ?? '';
            
            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) 
            {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $ck = 0;
            if ($cancelKeep == "Keep")
            {
                $ck = 1;
            }


            $result = $insertStmt->bind_param("ssdsdiss", 
                $email,
                $vendorName,
                $costPerPeriod,
                $frequency,
                $annualCost,
                $ck,
                $cancelledStatus,
                $notes
            );
            
            if (!$result) {
                $insertStmt->close();
                throw new Exception("Failed to bind parameters: " . $insertStmt->error);
            }
            
            $executeResult = $insertStmt->execute();
            if (!$executeResult) {
                $err = $insertStmt->error;
                $insertStmt->close();
                throw new Exception("Failed to insert item: " . $err);
            }
            $insertStmt->close();
        }
        
        $conn->commit();
        
        return ['success' => true, 'message' => 'Data saved successfully', 'cancelKeep' => $cc];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error saving cost savings tool data: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function loadCostCalculatorData($email) {
    $conn = getMysqliConnection();
    if (!$conn) {
        return [];
    }
    
    // Ensure table exists
    createCostCalculatorTable();
    
    $stmt = $conn->prepare("SELECT vendor_name, cost_per_period, frequency, annual_cost, cancel_keep, cancelled_status, notes 
                           FROM cost_calculator_items 
                           WHERE user_email = ? 
                           ORDER BY id ASC");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'vendor_name' => $row['vendor_name'],
            'cost_per_period' => $row['cost_per_period'],
            'frequency' => $row['frequency'],
            'annual_cost' => $row['annual_cost'],
            'cancel_keep' => normalizeCancelKeepValue($row['cancel_keep']),
            'cancelled_status' => isset($row['cancelled_status']) ? (int)$row['cancelled_status'] : 0,
            'notes' => $row['notes']
        ];
    }
    
    $stmt->close();
    return $items;
}

function handleSaveCostCalculator() {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_email'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in']);
        exit;
    }
    
    $email = $_SESSION['user_email'];
    $itemsRaw = $_POST['items'] ?? '[]';
    error_log("=== SAVE COST SAVINGS TOOL START ===");
    if (is_array($itemsRaw)) {
        $items = $itemsRaw;
        error_log("Received items as PHP array, count: " . count($items));
    } else {
        $itemsJson = (string)$itemsRaw;
        error_log("Received items JSON: " . substr($itemsJson, 0, 1000));
        $items = json_decode($itemsJson, true);
    }
    
    if (!is_array($items)) {
        error_log("Failed to decode items JSON. Error: " . json_last_error_msg());
        echo json_encode(['success' => false, 'error' => 'Invalid items data']);
        exit;
    }
    
    error_log("Decoded items count: " . count($items));
    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $ck = extractCancelKeepFromItem($item);
        error_log("Item $idx cancel_keep (resolved): " . var_export($ck, true));
    }
    
    // Verify and fix column type before save
    $conn = getMysqliConnection();
    if ($conn) {
        // Ensure table exists first
        createCostCalculatorTable();
        
        // Explicitly check and fix cancel_keep column type
        $checkCancelKeep = $conn->query("SHOW COLUMNS FROM cost_calculator_items WHERE Field = 'cancel_keep'");
        if ($checkCancelKeep && $checkCancelKeep->num_rows > 0) {
            $columnInfo = $checkCancelKeep->fetch_assoc();
            $columnType = $columnInfo['Type'];
            error_log("Pre-save check: cancel_keep column type is: " . $columnType);
            
            // Check if column is not VARCHAR
            if (stripos($columnType, 'varchar') === false && stripos($columnType, 'char') === false) {
                error_log("WARNING: cancel_keep column is not VARCHAR! Current type: " . $columnType);
                error_log("Attempting to fix column type to VARCHAR(10)...");
                
                $alterResult = $conn->query("ALTER TABLE cost_calculator_items MODIFY COLUMN cancel_keep VARCHAR(10) DEFAULT 'Keep'");
                if ($alterResult) {
                    error_log("SUCCESS: Fixed cancel_keep column type from " . $columnType . " to VARCHAR(10)");
                } else {
                    error_log("ERROR: Failed to alter cancel_keep column: " . $conn->error);
                    // Still continue with save, but log the error
                }
            } else {
                error_log("Column type is correct (VARCHAR/CHAR)");
            }
        }
    }
    
    $result = saveCostCalculatorData($email, $items);
    error_log("=== SAVE COST SAVINGS TOOL END ===");
    echo json_encode($result);
    exit;
}

function handleLoadCostCalculator() {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_email'])) {
        echo json_encode(['success' => false, 'error' => 'User not logged in', 'items' => []]);
        exit;
    }
    
    $email = $_SESSION['user_email'];
    $items = loadCostCalculatorData($email);
    
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
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
        'Business owner' => 'cost savings tool business owner',
        'Financial professional (book keeper, CPA, fractional CFO, accountant, etc)' => 'cost savings tool financial professional',
        'Aspiring business owner.' => 'cost savings tool aspiring business owner',
        'Employee of a small/medium-size business.' => 'cost savings tool employee of smb',
        'Other' => 'cost savings tool other'
    ];
    
    // Get the tag name for this role, default to 'cost savings tool other' if not found
    $roleTagName = $roleTagMap[$role] ?? 'cost savings tool other';
    
    // Tags to apply: role-specific tag + general registration tag
    $tags = [$roleTagName, 'cost savings tool registered'];
    
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

















// Determine current view
$current_view = 'login';
if (isset($_SESSION['user_email'])) {
    if (empty($_SESSION['user_role'])) {
        $email = $_SESSION['user_email'];
        loadUserResponses($email);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savvy CFO Cost Savings Tool</title>
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
            background: linear-gradient(135deg, #238FBE 0%, #1a6d91 100%);
            margin: 0;
            padding: 20px 20px;
            min-height: 100vh;
            line-height: 1.6;
            position: relative;
        }
        
        .container-wrapper {
            position: relative;
            max-width: 700px;
            margin: 0 auto;
        }
        
        /* Container for placeholder/cost savings tool */
        .placeholder-container-wrapper {
            max-width: 90%;
            margin: 0 auto;
        }
        
        .container {
            max-width: 700px; 
            margin: 0 auto; 
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(255, 255, 255, 0.95) 50%, rgba(248, 250, 252, 0.95) 100%);
            backdrop-filter: blur(10px);
            border-radius: 20px; 
            box-shadow: 0 20px 40px rgba(0,0,0,0.1), 0 0 0 1px rgba(255,255,255,0.2);
            padding: 0; 
            position: relative;
            overflow: visible;
            z-index: 8;
        }
        
        /* Wider container for placeholder/cost savings tool */
        .placeholder-container-wrapper .container {
            max-width: 90%;
        }
        
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: #238FBE;
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
            color: #424242;
            font-size: 16px;
            margin: -15px 0 30px 0;
            line-height: 1.5;
            font-weight: 400;
        }
        
        h1 { 
            font-size: 2.2em; 
            color: #238FBE;
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
            background: linear-gradient(135deg, rgba(35, 143, 190, 0.05), rgba(26, 109, 145, 0.05));
            border: 1px solid rgba(35, 143, 190, 0.15);
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
            color: #238FBE;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .ebook-promotion .ebook-link:hover {
            color: #1a6d91;
            text-decoration: underline;
        }

        /* Placeholder/Cost Savings Tool Link Styles */
        .cost-calculator-link {
            display: inline-block;
            padding: 15px 40px;
            background: #238FBE;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            transition: background 0.3s, transform 0.2s;
            box-shadow: 0 4px 6px rgba(0, 120, 212, 0.3);
        }

        .cost-calculator-link:hover {
            background: #1a6d91;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 120, 212, 0.4);
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
            color: #238FBE;
            text-decoration: underline;
        }

        .placeholder-content p a:hover {
            color: #1a6d91;
        }

        /* Cost Savings Tool Grid Styles */
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
            background: #238FBE;
            border-radius: 4px;
        }
        
        .cost-calculator-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #1a6d91;
        }

        .cost-calculator-grid {
            width: 100%;
            min-width: 900px;
            border-collapse: collapse;
            margin: 0;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .cost-calculator-grid thead {
            background: #238FBE;
            color: white;
        }

        .cost-calculator-grid th {
            padding: 12px 8px;
            text-align: left;
            font-weight: 600;
            border: 1px solid #1a6d91;
            font-size: 14px;
        }

        .cost-calculator-grid td {
            padding: 8px;
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
            padding: 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .cost-calculator-grid input[type="text"]:focus,
        .cost-calculator-grid input[type="number"]:focus,
        .cost-calculator-grid select:focus,
        .cost-calculator-grid textarea:focus {
            outline: none;
            border-color: #238FBE;
            box-shadow: 0 0 0 2px rgba(35, 143, 190, 0.2);
        }

        .cost-calculator-grid .item-number {
            text-align: center;
            font-weight: 600;
            width: 60px;
        }

        .cost-calculator-grid .vendor-name {
            min-width: 200px;
        }

        .cost-calculator-grid .cost-per-period {
            min-width: 120px;
        }

        .cost-calculator-grid .frequency {
            min-width: 140px;
        }

        .cost-calculator-grid .annual-cost {
            min-width: 120px;
            text-align: right;
            font-weight: 600;
        }

        .cost-calculator-grid .cancel-keep {
            min-width: 100px;
        }

        .cost-calculator-grid .cancelled-status {
            min-width: 120px;
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
            border-color: #238FBE;
            box-shadow: 0 0 0 3px rgba(35, 143, 190, 0.1);
        }

        .report-filters select:hover {
            border-color: #238FBE;
        }

        .cost-calculator-grid .notes {
            min-width: 200px;
        }

        .cost-calculator-grid .delete-row {
            width: 50px;
            text-align: center;
        }

        .cost-calculator-grid .delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .cost-calculator-grid .delete-btn:hover {
            background: #c82333;
        }

        .cost-calculator-actions {
            margin: 20px 0;
            text-align: center;
        }

        .add-row-btn {
            background: #238FBE;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }

        .add-row-btn:hover {
            background: #1a6d91;
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
            border: 2px solid #238FBE;
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
            color: #238FBE;
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
                min-width: 800px;
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

            .cost-calculator-grid .vendor-name {
                min-width: 150px;
            }

            .cost-calculator-grid .cost-per-period {
                min-width: 90px;
            }

            .cost-calculator-grid .frequency {
                min-width: 100px;
            }

            .cost-calculator-grid .annual-cost {
                min-width: 90px;
                font-size: 11px;
            }

            .cost-calculator-grid .cancel-keep {
                min-width: 80px;
            }

            .cost-calculator-grid .cancelled-status {
                min-width: 100px;
            }

            .report-filters {
                flex-direction: column;
                align-items: flex-start;
            }

            .report-filters select {
                min-width: 100%;
            }

            .cost-calculator-grid .notes {
                min-width: 150px;
            }

            .cost-calculator-grid .notes textarea {
                rows: 1;
                min-height: 30px;
            }

            .cost-calculator-grid .delete-row {
                width: 40px;
            }

            .cost-calculator-grid .delete-btn {
                padding: 4px 8px;
                font-size: 10px;
            }

            .cost-calculator-actions {
                margin: 15px 0;
            }

            .add-row-btn {
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
            background: linear-gradient(135deg, #238FBE, #1a6d91);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(35, 143, 190, 0.3);
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
            background: linear-gradient(135deg, #238FBE, #1a6d91);
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
            box-shadow: 0 6px 20px rgba(35, 143, 190, 0.3);
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
            background: #238FBE;
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
            background: #106ebe;
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(0, 120, 212, 0.3);
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
            border-top-color: #238FBE;
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
            accent-color: #238FBE;
            cursor: pointer;
        }

        .checkbox-label span {
            line-height: 1.5;
            color: #374151;
            font-size: 14px;
        }

        .checkbox-label a {
            color: #238FBE;
            text-decoration: underline;
        }

        .checkbox-label a:hover {
            color: #1a6d91;
        }
        
        input[type="email"], input[type="text"], select { 
            width: 100%; 
            padding: 16px 20px; 
            border: 2px solid #e5e7eb; 
            border-radius: 12px; 
            font-size: 16px; 
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(10px);
        }
        
        input[type="email"]:focus, input[type="text"]:focus, select:focus { 
            outline: none;
            border-color: #238FBE;
            box-shadow: 0 0 0 3px rgba(35, 143, 190, 0.1);
            transform: translateY(-2px);
        }
        
        button { 
            padding: 16px 32px; 
            background: linear-gradient(135deg, #238FBE, #1a6d91); 
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
            box-shadow: 0 8px 25px rgba(35, 143, 190, 0.3);
        }
        
        .btn-secondary { 
            background: linear-gradient(135deg, #6b7280, #4b5563); 
        }
        
        .btn-secondary:hover { 
            box-shadow: 0 8px 25px rgba(107, 114, 128, 0.3);
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
            border-color: #238FBE;
            box-shadow: 0 6px 18px rgba(35, 143, 190, 0.15);
        }

        .role-option input[type="radio"] {
            accent-color: #238FBE;
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
            background: linear-gradient(90deg, #238FBE, #1a6d91); 
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
            background: linear-gradient(135deg, #238FBE 0%, #1a6d91 100%); 
            color: white; 
            padding: 20px 25px; 
            margin: 0; 
            border-radius: 20px 20px 0 0; 
            text-align: center; 
            font-size: 22px; 
            font-weight: 700;
            letter-spacing: 0.5px;
            position: relative;
            box-shadow: 0 4px 15px rgba(35, 143, 190, 0.3);
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
            border-top: 10px solid #1a6d91;
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
            color: #238FBE;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            margin: 15px 0;
            display: inline-block;
            padding: 12px 20px;
            background: rgba(35, 143, 190, 0.1);
            border-radius: 8px;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .popup-link:hover {
            color: #4f46e5;
            background: rgba(35, 143, 190, 0.2);
            border-color: rgba(35, 143, 190, 0.3);
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
            background: linear-gradient(135deg, #238FBE, #1a6d91);
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
            background: linear-gradient(135deg, #238FBE, #1a6d91);
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
            background: linear-gradient(135deg, #238FBE, #1a6d91);
        }
        
        .performance-tier:hover {
            transform: translateX(5px);
            box-shadow: 0 8px 25px rgba(35, 143, 190, 0.15);
        }

        .tier-title {
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 12px;
            font-size: 17px;
            background: linear-gradient(135deg, #238FBE, #1a6d91);
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
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-radius: 12px;
            border-left: 4px solid #238FBE;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .action-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(35, 143, 190, 0.2);
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
            background: linear-gradient(135deg, #238FBE, #1a6d91);
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
            background: linear-gradient(135deg, #238FBE, #1a6d91);
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
            border-color: #238FBE;
            box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.2);
        }

        .chat-send-button {
            background: #238FBE;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.2s;
        }

        .chat-send-button:hover:not(:disabled) {
            background: #1a6d91;
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
            background: linear-gradient(135deg, #238FBE, #1a6d91);
            border-radius: 4px;
        }

        .ai-guidance-content::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a6fd8, #6a4190);
        }

        .ai-guidance-content h4 {
            color: #238FBE;
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
            border-top: 3px solid #238FBE;
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
            background: #238FBE;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            margin: 0 10px;
        }

        .ai-guidance-        button:hover { 
            background: #1a6d91;
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
            
            input[type="email"], input[type="text"], select {
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
            background: linear-gradient(135deg, #238FBE 0%, #1a6d91 100%);
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
    <?php if (isset($_SESSION['user_email']) && empty($_SESSION['awaiting_role'])): ?>
        <form method="POST" class="logout-form">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="logout-button" title="Logout">
                <i class="fas fa-right-from-bracket" aria-hidden="true"></i>
                <span>Log out</span>
            </button>
        </form>
    <?php endif; ?>

    <!-- Snackbar for messages -->
    <div id="snackbar" class="snackbar">
        <span id="snackbar-message"></span>
        <button type="button" class="close-btn" onclick="hideSnackbar()">&times;</button>
    </div>

    <script>
    // Global OTP Functions - Available on all pages
    function changeEmail() {
        // Create a form to clear the OTP session and go back to email entry
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="change_email">';
        document.body.appendChild(form);
        form.submit();
    }

    function resendOTP() {
        // Get the email from the hidden input in the form
        const emailInput = document.querySelector('input[name="email"]');
        const email = emailInput ? emailInput.value : '';
        
        if (!email) {
            alert('Email not found. Please refresh the page and try again.');
            return;
        }
        
        // Create a form to resend the OTP to the same email
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="send_otp">
            <input type="hidden" name="email" value="${email}">
            <input type="hidden" name="agree_terms" value="on">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    
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
                <h1>Cost Savings Tool</h1>
                <p class="subtitle">Provide your email below to securely access your cost savings tool.</p>
            
            <?php if (!empty($_SESSION['awaiting_role'])): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="save_user_role">
                    <div class="form-group">
                        <label>Select the option that best describes you:</label>
                        <p class="subtitle" style="margin-top: 4px; font-size: 15px; color: #4b5563;">
                            OTP verified for <?php echo htmlspecialchars($_SESSION['user_email']); ?>. Let us know who you are to tailor your experience.
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
                        <button type="button" onclick="changeEmail()" class="btn-secondary">Use a Different Email</button>
                    </div>
                </form>
            <?php elseif (!isset($_SESSION['show_otp'])): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="send_otp">
                    <div class="form-group">
                        <label for="email">Email Address:</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="agree_terms" id="agree_terms" required>
                            <span>By using this cost savings tool, I agree to the <a href="https://savvycfo.com/terms-conditions-privacy-policy/" target="_blank" rel="noopener noreferrer">terms of use</a>.</span>
                        </label>
                    </div>
                    <button type="submit">Send OTP</button>
                </form>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="verify_otp">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($_SESSION['otp_email']); ?>">
                    <div class="form-group">
                        <label for="otp">Enter OTP:</label>
                        <input type="text" id="otp" name="otp" required>
                        <small>OTP sent to: <?php echo htmlspecialchars($_SESSION['otp_email']); ?></small>
                    </div>
                    <div class="button-group">
                        <button type="submit">Login</button>
                        <button type="button" onclick="changeEmail()" class="btn-secondary">Change Email</button>
                        <button type="button" onclick="resendOTP()" class="btn-secondary">Resend OTP</button>
                    </div>
                </form>
            <?php endif; ?>
            
            <!-- eBook Promotion Section -->
            </div> <!-- Close content-padding -->

        <?php elseif ($current_view === 'placeholder'): ?>
            <div class="content-padding">
                <h1>Cost Savings Tool</h1>
                
                <div class="report-filters">
                    <label for="reportFilter">Report Filters:</label>
                    <select id="reportFilter" onchange="filterTableRows(this.value)">
                        <option value="all">All</option>
                        <option value="keep">Keep</option>
                        <option value="pending_cancelled">Pending Cancelled</option>
                        <option value="confirmed_cancelled">Confirmed Cancelled</option>
                    </select>
                </div>
                
                <div class="cost-calculator-table-wrapper">
                    <table class="cost-calculator-grid" id="costCalculatorGrid">
                    <thead>
                        <tr>
                            <th class="item-number">Item #</th>
                            <th class="vendor-name">Vendor/Contractor Name</th>
                            <th class="cost-per-period">Cost per Period</th>
                            <th class="frequency">Frequency</th>
                            <th class="annual-cost">Annual Cost</th>
                            <th class="cancel-keep" title="Stored in DB column cancel_keep (Cancel = cancel this cost)">Cancel/Keep</th>
                            <th class="cancelled-status" title="Stored in DB column cancelled_status. Check when the vendor has confirmed cancellation (separate from Cancel/Keep intent).">Confirmed</th>
                            <th class="notes">Notes/Comments</th>
                            <th class="delete-row"></th>
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
            
            function addCalculatorRow() {
                rowCount++;
                const tbody = document.getElementById('calculatorRows');
                const row = document.createElement('tr');
                row.setAttribute('data-row-id', rowCount);
                
                row.innerHTML = `
                    <td class="item-number">${rowCount}</td>
                    <td class="vendor-name">
                        <input type="text" name="vendor[]" placeholder="Enter vendor name" />
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
                            <option value="annually">Annually</option>
                        </select>
                    </td>
                    <td class="annual-cost">
                        <span class="annual-cost-display" data-row="${rowCount}">$0.00</span>
                    </td>
                    <td class="cancel-keep">
                        <select name="cancel_keep[]" class="cancel-keep-select" data-row="${rowCount}">
                            <option value="Keep">Keep</option>
                            <option value="Cancel">Cancel</option>
                        </select>
                    </td>
                    <td class="cancelled-status">
                        <input type="checkbox" name="cancelled_status[]" class="cancelled-status-checkbox" data-row="${rowCount}" />
                    </td>
                    <td class="notes">
                        <textarea name="notes[]" rows="2" placeholder="Add notes..."></textarea>
                    </td>
                    <td class="delete-row">
                        <button type="button" class="delete-btn" onclick="deleteRow(this)">Delete</button>
                    </td>
                `;
                
                tbody.appendChild(row);
                
                // Attach event listeners (with auto-save)
                attachRowListenersWithSave(row);

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
            
            function deleteRow(button) {
                const row = button.closest('tr');
                row.remove();
                updateRowNumbers();
                calculateAnnualSavings();
                calculateConfirmedSavings();
                autoSave(); // Auto-save after deletion
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
                    const notesTextarea = row.querySelector('textarea[name="notes[]"]');
                    const annualCostDisplay = row.querySelector('.annual-cost-display');
                    
                    const vendorName = vendorInput ? vendorInput.value.trim() : '';
                    const costPerPeriod = costInput ? parseFloat(costInput.value.replace(/[^0-9.-]/g, '')) || 0 : 0;
                    const frequency = frequencySelect ? frequencySelect.value : '';
                    const cancelKeep = getCancelKeepFromRow(row);
                    const cancelledStatus = cancelledCheckbox ? cancelledCheckbox.checked : false;
                    const notes = notesTextarea ? notesTextarea.value.trim() : '';
                    const annualCost = annualCostDisplay ? parseFloat(annualCostDisplay.textContent.replace(/[^0-9.-]/g, '')) || 0 : 0;
                    
                    // Save rows with any meaningful data (vendor/cost, or cancel/keep, notes, or cancelled status)
                    if (vendorName || costPerPeriod > 0 || cancelKeep !== 'Keep' || cancelledStatus || notes) {
                        items.push({
                            vendor_name: vendorName,
                            cost_per_period: costPerPeriod,
                            frequency: frequency,
                            annual_cost: annualCost,
                            cancel_keep: cancelKeep,
                            cancelKeep: cancelKeep,
                            cancelled_status: cancelledStatus ? 1 : 0,
                            notes: notes
                        });
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
                                    const vendorInput = lastRow.querySelector('input[name="vendor[]"]');
                                    const costInput = lastRow.querySelector('.cost-input');
                                    const frequencySelect = lastRow.querySelector('.frequency-select');
                                    const cancelKeepSelect = lastRow.querySelector('.cancel-keep-select');
                                    const cancelledCheckbox = lastRow.querySelector('.cancelled-status-checkbox');
                                    const notesTextarea = lastRow.querySelector('textarea[name="notes[]"]');
                                    
                                    if (vendorInput) vendorInput.value = item.vendor_name || '';
                                    if (costInput) costInput.value = item.cost_per_period > 0 ? '$' + parseFloat(item.cost_per_period).toFixed(2) : '';
                                    if (frequencySelect) frequencySelect.value = item.frequency || '';
                                    
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
                                    if (notesTextarea) notesTextarea.value = item.notes || '';
                                    
                                    if (costInput && frequencySelect) {
                                        const event = new Event('input');
                                        costInput.dispatchEvent(event);
                                    }
                                }
                            });
                            
                            calculateAnnualSavings();
                            calculateConfirmedSavings();
                        } else {
                            addCalculatorRow();
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
                const notesTextarea = row.querySelector('textarea[name="notes[]"]');
                
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
                    vendorInput.addEventListener('blur', autoSave);
                }
                
                if (notesTextarea) {
                    notesTextarea.addEventListener('blur', autoSave);
                }
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
            
            // Initialize: Load data on page load; flush debounced saves before refresh/navigation
            document.addEventListener('DOMContentLoaded', function() {
                loadCalculatorData();
            });
            window.addEventListener('pagehide', flushSaveOnLeave);
            </script>

        <?php endif; ?>

        </div>

    </div>

</body>
</html>
