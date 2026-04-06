<?php
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/mail.php';

$error = '';
$token = $_POST['token'] ?? ($_GET['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $token === '') {
    $error = 'Open this page using the link from your invitation email.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $token = $_POST['token'] ?? '';
    $displayName = trim($_POST['display_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');

    if ($displayName === '' || $username === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $hash = hash('sha256', $token);
        $pdo = getDBConnection();
        $st = $pdo->prepare(
            'SELECT * FROM invitations WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > NOW()'
        );
        $st->execute([$hash]);
        $inv = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            error_log('[invite-register] token_invalid_or_expired');
            $error = 'Invalid or expired invitation link.';
        } else {
            $orgId = (int) $inv['org_id'];
            $email = strtolower(trim($inv['email']));
            error_log('[invite-register] token_valid org_id=' . $orgId . ' email=' . $email);
            $maxUsers = getOrganizationMaxUsers($pdo, $orgId);
            $c = (int) $pdo->query('SELECT COUNT(*) AS c FROM users WHERE org_id = ' . $orgId)->fetch()['c'];
            if ($c >= $maxUsers) {
                error_log('[invite-register] blocked_org_limit org_id=' . $orgId . ' users=' . $c . ' max=' . $maxUsers);
                $error = 'This organization already has the maximum number of users (' . $maxUsers . ').';
            } else {
                $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
                $dup->execute([$username, $email]);
                if ($dup->fetch()) {
                    error_log('[invite-register] blocked_duplicate_user org_id=' . $orgId . ' email=' . $email . ' username=' . $username);
                    $error = 'Username or email is already taken.';
                } else {
                    $ph = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare(
                        'INSERT INTO users (org_id, username, email, password_hash, role, display_name) VALUES (?,?,?,?,?,?)'
                    );
                    $ins->execute([$orgId, $username, $email, $ph, 'member', $displayName]);
                    error_log('[invite-register] user_created org_id=' . $orgId . ' email=' . $email . ' username=' . $username);
                    $pdo->prepare('UPDATE invitations SET consumed_at = NOW() WHERE id = ?')->execute([(int) $inv['id']]);
                    error_log('[invite-register] invite_consumed invitation_id=' . (int) $inv['id']);
                    $_SESSION['message'] = 'Registration complete. You can log in.';
                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Cost Savings Pro Tool</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            max-width: 440px;
            margin: 40px auto;
            padding: 28px 20px;
            box-sizing: border-box;
            min-height: 100vh;
            background: linear-gradient(160deg, #f3effb 0%, #e8e2f4 40%, #d4c8e8 100%);
        }
        h1 {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-size: 1.85rem;
            font-weight: 700;
            text-align: center;
            background: linear-gradient(135deg, #4a3f6b 0%, #6b5b95 50%, #8b7cb8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        label { display: block; margin-top: 14px; font-weight: 600; color: #4a3f6b; font-size: 14px; }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 16px 18px;
            margin-top: 6px;
            box-sizing: border-box;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px;
            font-family: inherit;
            line-height: 1.4;
            background: rgba(255,255,255,0.95);
            -webkit-appearance: none;
            appearance: none;
        }
        input[type="text"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #6b5b95;
            box-shadow: 0 0 0 3px rgba(107, 91, 149, 0.14);
            background: #fff;
        }
        button {
            margin-top: 22px;
            padding: 14px 22px;
            width: 100%;
            background: linear-gradient(135deg, #7c6ba8, #5b4d8f);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }
        button:hover { filter: brightness(1.05); }
        .err { color: #b91c1c; margin-bottom: 12px; }
        a { color: #5b4d8f; }
    </style>
</head>
<body>
    <h1>Cost Savings Pro Tool</h1>
    <p style="text-align:center;color:#5c4d7a;margin:0 0 20px;font-size:15px;">Complete your registration</p>
    <?php if ($error): ?><p class="err"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="register" value="1">
        <label>Your name</label>
        <input type="text" name="display_name" required value="<?php echo htmlspecialchars($_POST['display_name'] ?? ''); ?>">
        <label>Username</label>
        <input type="text" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
        <label>Password</label>
        <input type="password" name="password" required minlength="8">
        <label>Confirm password</label>
        <input type="password" name="password_confirm" required minlength="8">
        <button type="submit">Register</button>
    </form>
    <p><a href="index.php">Back to login</a></p>
</body>
</html>
