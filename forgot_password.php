<?php
// forgot_password.php
// Step 1: the username; a 6-digit code is texted to the account's mobile number.
// Step 2: the code and a new password.

require_once __DIR__ . '/includes/php_version_check.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/password_reset.php';
require_once 'includes/uploads.php';

if (isLoggedIn()) {
    header('Location: ' . (isAdmin() ? 'admin/dashboard.php' : 'tenant/dashboard.php'));
    exit;
}

$step = isset($_SESSION['pw_reset_username']) ? 'code' : 'username';
$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'send') {
        $username = trim((string)($_POST['username'] ?? ''));
        if ($username === '') {
            $error = 'Enter your username.';
        } else {
            $outcome = requestPasswordReset($pdo, $username);
            if ($outcome === 'too_many') {
                $error = 'Too many codes were asked for in the last hour. Please try again later.';
            } else {
                // The same message whether or not the username exists, so the page can't be used
                // to find out which accounts there are.
                $_SESSION['pw_reset_username'] = $username;
                $step = 'code';
                $info = 'If that account has a mobile number on file, a 6-digit code has been texted to it. It expires in '
                      . PASSWORD_RESET_MINUTES . ' minutes.';
            }
        }
    } elseif ($action === 'reset' && isset($_SESSION['pw_reset_username'])) {
        $error = completePasswordReset($pdo, $_SESSION['pw_reset_username'], (string)($_POST['code'] ?? ''),
                                       (string)($_POST['password'] ?? ''), (string)($_POST['confirm'] ?? ''));
        if ($error === '') {
            unset($_SESSION['pw_reset_username']);
            header('Location: login.php?reset=1');
            exit;
        }
        $step = 'code';
    } elseif ($action === 'restart') {
        unset($_SESSION['pw_reset_username']);
        $step = 'username';
    }
}

$loginBg = uploadSrc((string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'login_bg_path'")->fetchColumn() ?: ''));
$houseName = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'boarding_house_name'")->fetchColumn() ?: 'Riveros Boarding House');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Riveros Boarding House</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body<?php if ($loginBg): ?> class="has-custom-bg" style="background-image: linear-gradient(rgba(15, 23, 42, 0.55), rgba(15, 23, 42, 0.55)), url('<?= htmlspecialchars($loginBg, ENT_QUOTES) ?>');"<?php endif; ?>>
    <div class="bg-left"></div>
    <div class="bg-right">
        <div class="dots-pattern"></div>
        <div class="abstract-circle-1"></div>
        <div class="abstract-circle-2"></div>
        <div class="wave-bottom"></div>
    </div>

    <div class="main-wrapper">
        <div class="login-card">
            <div class="logo-circle"><i class="fa-solid fa-key"></i></div>
            <h3 class="brand-name"><?= htmlspecialchars(ucwords(strtolower($houseName))) ?></h3>
            <div class="divider-text">Forgot Password</div>

            <?php if ($error): ?>
                <div class="alert alert-danger text-center" style="font-size: 0.85rem; padding: 8px; border-radius: 8px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($info): ?>
                <div class="alert alert-info text-center" style="font-size: 0.8rem; padding: 8px; border-radius: 8px;"><?= htmlspecialchars($info) ?></div>
            <?php endif; ?>

            <?php if ($step === 'username'): ?>
                <p class="welcome-text">Enter your username and we'll text a code to the mobile number on your account.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="send">
                    <label class="form-label-custom">Username</label>
                    <div class="input-group-custom">
                        <i class="fa-regular fa-user icon-left"></i>
                        <input type="text" name="username" placeholder="Enter your username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                    </div>
                    <button type="submit" class="btn-primary-custom"><i class="fa-solid fa-paper-plane me-2"></i> Send Code</button>
                </form>
            <?php else: ?>
                <p class="welcome-text">Enter the code from the text message for <strong><?= htmlspecialchars($_SESSION['pw_reset_username']) ?></strong>, then choose a new password.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="reset">
                    <label class="form-label-custom">6-digit code</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-hashtag icon-left"></i>
                        <input type="text" name="code" class="code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000" required autofocus>
                    </div>
                    <label class="form-label-custom">New password</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-lock icon-left"></i>
                        <input type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters" required autocomplete="new-password">
                    </div>
                    <label class="form-label-custom">Confirm new password</label>
                    <div class="input-group-custom">
                        <i class="fa-solid fa-lock icon-left"></i>
                        <input type="password" name="confirm" minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="Type it again" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn-primary-custom"><i class="fa-solid fa-check me-2"></i> Change Password</button>
                </form>
                <form method="POST" class="text-center">
                    <input type="hidden" name="action" value="restart">
                    <button type="submit" class="btn btn-link back-link p-0">Didn't get a code? Try again</button>
                </form>
            <?php endif; ?>

            <div class="auth-note">
                No code arriving? Tenants: ask the admin to reset your password.
                Admins: set a recovery mobile number under Settings.
            </div>
            <div class="text-center mt-2"><a class="back-link" href="login.php"><i class="fa-solid fa-arrow-left me-1"></i> Back to login</a></div>
        </div>
    </div>
</body>
</html>
