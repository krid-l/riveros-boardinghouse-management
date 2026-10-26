<?php
// login.php

// Must come first: everything below needs PHP 8, and an older PHP cannot even
// parse those files, so it would die before reaching any message of ours.
require_once __DIR__ . '/includes/php_version_check.php';

require_once 'includes/db.php';
require_once 'includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) header("Location: admin/dashboard.php");
    else header("Location: tenant/dashboard.php");
    exit;
}

$error = isset($_GET['deactivated']) ? "This tenant account has been deactivated. Please contact the admin." : '';
$notice = isset($_GET['reset']) ? "Your password was changed. Log in with your new password." : '';

// Background photo: the one uploaded under Settings -> Login Page, or the boarding house photo.
require_once 'includes/uploads.php';
$loginBg = loginBackgroundSrc($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT u.*, t.status AS tenant_status FROM users u LEFT JOIN tenants t ON t.user_id = u.id WHERE u.username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // One login for everyone: the account's role decides whether it opens the admin dashboard
    // or the tenant portal.
    if ($user && password_verify($password, $user['password_hash'])) {
        if ($user['role'] === 'tenant' && $user['tenant_status'] === 'deactivated') {
            $error = "This tenant account has been deactivated. Please contact the admin.";
        } else {
            session_regenerate_id(true);   // a fresh session id for the signed-in user
            $pdo->prepare("UPDATE users SET last_login_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $user['id']]);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            
            if ($user['role'] === 'admin') {
                header("Location: admin/dashboard.php");
            } else {
                // Ensure tenant profile exists in session logic if needed, but dashboard handles it
                header("Location: tenant/dashboard.php");
            }
            exit;
        }
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riveros Boarding House - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/auth.css') ?>">

</head>
<body class="has-custom-bg" style="background-image: linear-gradient(rgba(15, 23, 42, 0.45), rgba(15, 23, 42, 0.45)), url('<?= htmlspecialchars($loginBg, ENT_QUOTES) ?>');">

    <!-- Background Elements -->
    <div class="bg-left"></div>
    <div class="bg-right">
        <div class="dots-pattern"></div>
        <div class="abstract-circle-1"></div>
        <div class="abstract-circle-2"></div>
        <div class="wave-bottom"></div>
    </div>

    <!-- Main Flex Container -->
    <div class="main-wrapper">
        <div class="login-card">
            <div class="logo-circle">
                <i class="fa-solid fa-house"></i>
            </div>
            
            <h3 class="brand-name">Riveros Boarding House</h3>
            
            <div class="divider-text">Sign In</div>
            <p class="welcome-text">Welcome back! Please login to access your account.</p>
            
            <?php if ($notice): ?>
                <div class="alert alert-success text-center" style="font-size: 0.85rem; padding: 8px; border-radius: 8px;"><?= htmlspecialchars($notice) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger text-center" style="font-size: 0.85rem; padding: 8px; border-radius: 8px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <label class="form-label-custom">Username</label>
                <div class="input-group-custom">
                    <i class="fa-regular fa-user icon-left"></i>
                    <input type="text" name="username" placeholder="Enter your username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                </div>

                <label class="form-label-custom">Password</label>
                <div class="input-group-custom">
                    <i class="fa-solid fa-lock icon-left"></i>
                    <input type="password" name="password" id="passwordField" placeholder="Enter your password" required>
                    <i class="fa-regular fa-eye icon-right" id="togglePassword" onclick="togglePass()"></i>
                </div>
                
                <div class="options-row justify-content-end">
                    <a href="forgot_password.php">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary-custom">
                    <i class="fa-solid fa-lock me-2"></i> Login
                </button>
            </form>
            
            <div class="copyright">
                &copy; <?= date('Y') ?> Riveros Boarding House. All rights reserved.
            </div>
        </div>
    </div>

    <!-- Bottom Feature Bar -->
    <div class="feature-bar">
        <div class="feature-item">
            <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="feature-text">
                <div class="title">Secure & Reliable</div>
                <div class="desc">Your data is safe with us</div>
            </div>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><i class="fa-regular fa-clock"></i></div>
            <div class="feature-text">
                <div class="title">Easy Management</div>
                <div class="desc">Manage payments and more</div>
            </div>
        </div>
        <div class="feature-item">
            <div class="feature-icon"><i class="fa-solid fa-headset"></i></div>
            <div class="feature-text">
                <div class="title">We're Here to Help</div>
                <div class="desc">Support whenever you need</div>
            </div>
        </div>
    </div>

    <script>
        function togglePass() {
            const passField = document.getElementById('passwordField');
            const toggleIcon = document.getElementById('togglePassword');
            if (passField.type === 'password') {
                passField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
