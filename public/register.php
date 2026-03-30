<?php
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/mail.php';

$error = '';
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

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
            $error = 'Invalid or expired invitation link.';
        } else {
            $orgId = (int) $inv['org_id'];
            $email = strtolower(trim($inv['email']));
            $c = (int) $pdo->query('SELECT COUNT(*) AS c FROM users WHERE org_id = ' . $orgId)->fetch()['c'];
            if ($c >= 10) {
                $error = 'This organization already has the maximum number of users.';
            } else {
                $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
                $dup->execute([$username, $email]);
                if ($dup->fetch()) {
                    $error = 'Username or email is already taken.';
                } else {
                    $ph = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare(
                        'INSERT INTO users (org_id, username, email, password_hash, role, display_name) VALUES (?,?,?,?,?,?)'
                    );
                    $ins->execute([$orgId, $username, $email, $ph, 'member', $displayName]);
                    $pdo->prepare('UPDATE invitations SET consumed_at = NOW() WHERE id = ?')->execute([(int) $inv['id']]);
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
    <title>Register — Cost Savings Tool</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 420px; margin: 40px auto; padding: 20px; }
        label { display: block; margin-top: 12px; font-weight: 600; }
        input { width: 100%; padding: 8px; margin-top: 4px; box-sizing: border-box; }
        button { margin-top: 20px; padding: 10px 20px; background: #238FBE; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .err { color: #b91c1c; margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>Complete registration</h1>
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
