<?php
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db_config.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../includes/mail.php';

use CostSavings\OrgRole;
use CostSavings\RoleContext;

$error = '';
$token = $_POST['token'] ?? ($_GET['token'] ?? '');
$inviteAcceptOnly = false;
$inviteEmail = '';

if ($token !== '') {
    try {
        $pdoPreview = getDBConnection();
        $hashPreview = hash('sha256', $token);
        $stPreview = $pdoPreview->prepare(
            'SELECT email FROM invitations WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stPreview->execute([$hashPreview]);
        $invPreview = $stPreview->fetch(PDO::FETCH_ASSOC);
        if ($invPreview) {
            $inviteEmail = strtolower(trim((string) ($invPreview['email'] ?? '')));
            if ($inviteEmail !== '') {
                $existsPreview = $pdoPreview->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $existsPreview->execute([$inviteEmail]);
                $inviteAcceptOnly = (bool) $existsPreview->fetch(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        error_log('[invite-register] preview: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $token === '') {
    $error = 'Open this page using the link from your invitation email.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_invite'])) {
    $token = $_POST['token'] ?? '';
    if ($token === '') {
        $error = 'Invalid invitation link.';
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
            $existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $existing->execute([$email]);
            $existingUser = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$existingUser) {
                $error = 'No existing account found for this invitation. Please complete registration.';
            } else {
                $existingUserId = (int) ($existingUser['id'] ?? 0);
                $inOrg = $pdo->prepare('SELECT 1 FROM user_organizations WHERE user_id = ? AND org_id = ? LIMIT 1');
                $inOrg->execute([$existingUserId, $orgId]);
                if ($inOrg->fetchColumn()) {
                    $error = 'You are already a member of this organization. Please log in.';
                } elseif (getOrganizationMemberCount($pdo, $orgId) >= getOrganizationMaxUsers($pdo, $orgId)) {
                    $error = 'This organization already has the maximum number of users.';
                } else {
                    $desiredRole = strtolower(trim((string) ($inv['invite_role'] ?? 'member')));
                    if ($desiredRole !== OrgRole::ROLE_ADMIN && $desiredRole !== OrgRole::ROLE_MEMBER) {
                        $desiredRole = OrgRole::ROLE_MEMBER;
                    }
                    RoleContext::upsertOrgMembership($pdo, $existingUserId, $orgId, $desiredRole);
                    $pdo->prepare('UPDATE invitations SET consumed_at = NOW() WHERE id = ?')->execute([(int) $inv['id']]);
                    $_SESSION['message'] = 'You have been added to the organization. Please log in.';
                    header('Location: index.php');
                    exit;
                }
            }
        }
    }
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
            $c = getOrganizationMemberCount($pdo, $orgId);
            if ($c >= $maxUsers) {
                error_log('[invite-register] blocked_org_limit org_id=' . $orgId . ' users=' . $c . ' max=' . $maxUsers);
                $error = 'This organization already has the maximum number of users (' . $maxUsers . ').';
            } else {
                $existing = $pdo->prepare('SELECT id, username FROM users WHERE email = ? LIMIT 1');
                $existing->execute([$email]);
                $existingUser = $existing->fetch(PDO::FETCH_ASSOC);

                if ($existingUser) {
                    $existingUserId = (int) ($existingUser['id'] ?? 0);
                    $inOrg = $pdo->prepare('SELECT 1 FROM user_organizations WHERE user_id = ? AND org_id = ? LIMIT 1');
                    $inOrg->execute([$existingUserId, $orgId]);
                    if ($inOrg->fetchColumn()) {
                        error_log('[invite-register] blocked_existing_member org_id=' . $orgId . ' email=' . $email);
                        $error = 'You are already a member of this organization. Please log in.';
                    } else {
                        $inviterId = (int) ($inv['invited_by_user_id'] ?? 0);
                        $desiredRole = strtolower(trim((string) ($inv['invite_role'] ?? 'member')));
                        if ($desiredRole !== OrgRole::ROLE_ADMIN && $desiredRole !== OrgRole::ROLE_MEMBER) {
                            $desiredRole = OrgRole::ROLE_MEMBER;
                        }
                        $inviterRole = OrgRole::ROLE_MEMBER;
                        if ($inviterId > 0) {
                            $inviterRole = RoleContext::orgRole($pdo, $inviterId, $orgId) ?? OrgRole::ROLE_MEMBER;
                        }
                        if ($desiredRole !== OrgRole::ROLE_MEMBER && !OrgRole::canElevateOrgRoles($inviterRole)) {
                            $desiredRole = OrgRole::ROLE_MEMBER;
                        }
                        RoleContext::upsertOrgMembership($pdo, $existingUserId, $orgId, $desiredRole);
                        $pdo->prepare('UPDATE invitations SET consumed_at = NOW() WHERE id = ?')->execute([(int) $inv['id']]);
                        error_log('[invite-register] existing_user_added org_id=' . $orgId . ' email=' . $email);
                        $_SESSION['message'] = 'You have been added to the organization. Please log in.';
                        header('Location: index.php');
                        exit;
                    }
                } else {
                $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                $dup->execute([$username]);
                if ($dup->fetch()) {
                    error_log('[invite-register] blocked_duplicate_username org_id=' . $orgId . ' email=' . $email . ' username=' . $username);
                    $error = 'Username is already taken.';
                } else {
                    $inviterId = (int) ($inv['invited_by_user_id'] ?? 0);
                    $desiredRole = strtolower(trim((string) ($inv['invite_role'] ?? 'member')));
                    if ($desiredRole !== OrgRole::ROLE_ADMIN && $desiredRole !== OrgRole::ROLE_MEMBER) {
                        $desiredRole = OrgRole::ROLE_MEMBER;
                    }
                    $inviterRole = OrgRole::ROLE_MEMBER;
                    if ($inviterId > 0) {
                        $inviterRole = RoleContext::orgRole($pdo, $inviterId, $orgId) ?? OrgRole::ROLE_MEMBER;
                    }
                    if ($desiredRole !== OrgRole::ROLE_MEMBER && !OrgRole::canElevateOrgRoles($inviterRole)) {
                        $desiredRole = OrgRole::ROLE_MEMBER;
                    }

                    $ph = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare(
                        'INSERT INTO users (username, email, password_hash, display_name) VALUES (?,?,?,?)'
                    );
                    $ins->execute([$username, $email, $ph, $displayName]);
                    $newUserId = (int) $pdo->lastInsertId();
                    RoleContext::upsertOrgMembership($pdo, $newUserId, $orgId, $desiredRole);
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Savvy Saver</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #0B58A3;
            --color-primary-hover: #0A4B8E;
            --color-secondary: #25A8E0;
            --color-bg: #F7FAFC;
            --color-surface: #FFFFFF;
            --color-text-primary: #1F2937;
            --color-text-secondary: #4B5563;
            --color-border: #DCE3EA;
            --color-error: #DC2626;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            max-width: 440px;
            margin: 40px auto;
            padding: 28px 20px;
            box-sizing: border-box;
            min-height: 100vh;
            background: linear-gradient(160deg, var(--color-bg) 0%, #edf5fa 40%, #e4f2f8 100%);
        }
        h1 {
            font-family: 'Cormorant Garamond', Georgia, serif;
            font-size: 1.85rem;
            font-weight: 700;
            text-align: center;
            background: linear-gradient(135deg, var(--color-primary-hover) 0%, var(--color-primary) 50%, var(--color-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        label { display: block; margin-top: 14px; font-weight: 600; color: var(--color-text-primary); font-size: 14px; }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 16px 18px;
            margin-top: 6px;
            box-sizing: border-box;
            border: 2px solid var(--color-border);
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
            border-color: var(--color-secondary);
            box-shadow: 0 0 0 3px rgba(37, 168, 224, 0.18);
            background: #fff;
        }
        button {
            margin-top: 22px;
            padding: 14px 22px;
            width: 100%;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover));
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }
        button:hover { filter: brightness(1.07); }
        .err { color: var(--color-error); margin-bottom: 12px; }
        a { color: var(--color-primary); }
        a:hover { color: var(--color-primary-hover); }
    </style>
</head>
<body>
    <h1>Savvy Saver</h1>
    <p style="text-align:center;color:#4B5563;margin:0 0 20px;font-size:15px;">Complete your registration</p>
    <?php if ($error): ?><p class="err"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    <?php if ($inviteAcceptOnly): ?>
    <p style="font-size:14px;color:#374151;line-height:1.5;">An account already exists for <strong><?php echo htmlspecialchars($inviteEmail); ?></strong>. Accept this invitation to join the organization.</p>
    <form method="post" style="margin-top:16px;">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="accept_invite" value="1">
        <button type="submit">Accept invitation</button>
    </form>
    <?php else: ?>
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
    <?php endif; ?>
    <p><a href="index.php">Back to login</a></p>
</body>
</html>
