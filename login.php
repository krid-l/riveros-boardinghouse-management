<?php
// login.php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) header("Location: admin/dashboard.php");
    else header("Location: tenant/dashboard.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $loginType = $_POST['login_type'] ?? 'tenant';
        
        if ($user['role'] !== $loginType) {
            if ($user['role'] === 'admin') {
                $error = "You are an Admin. Please click 'Login as Admin' below.";
            } else {
                $error = "You are a Tenant. Please click 'Login as Tenant' below.";
            }
        } else {
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
    
    <style>
        body, html { 
            min-height: 100vh; margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            overflow-x: hidden; overflow-y: auto; background: #f8fafc;
            display: flex; flex-direction: column;
        }
        
        /* Background Elements */
        .bg-left {
            position: fixed; left: 0; top: 0; bottom: 0; width: 40%;
            background: url('https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80') center/cover no-repeat;
            z-index: 1;
        }
        .bg-right {
            position: fixed; right: 0; top: 0; bottom: 0; width: 60%;
            background: #ffffff;
            z-index: 1;
        }
        
        .dots-pattern {
            position: fixed; top: 40px; right: 50px; opacity: 0.15;
            width: 100px; height: 100px;
            background-image: radial-gradient(#1e3a8a 2.5px, transparent 2.5px);
            background-size: 20px 20px;
            z-index: 2;
        }
        
        .wave-bottom {
            position: fixed; bottom: 0; right: 0; width: 100%; height: 50%;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1440 320" xmlns="http://www.w3.org/2000/svg"><path fill="%231e40af" fill-opacity="1" d="M0,256L48,229.3C96,203,192,149,288,144C384,139,480,181,576,197.3C672,213,768,203,864,181.3C960,160,1056,128,1152,122.7C1248,117,1344,139,1392,149.3L1440,160L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') bottom right / cover no-repeat;
            z-index: 2;
        }

        .abstract-circle-1 {
            position: fixed; top: 50%; right: -5%; transform: translateY(-50%);
            width: 600px; height: 600px; border-radius: 50%;
            background: rgba(239, 246, 255, 0.6); z-index: 1;
        }
        .abstract-circle-2 {
            position: fixed; top: 50%; right: 5%; transform: translateY(-50%);
            width: 450px; height: 450px; border-radius: 50%;
            background: rgba(219, 234, 254, 0.4); z-index: 1;
        }

        /* Container */
        .main-wrapper {
            flex: 1; position: relative; width: 100%; display: flex; justify-content: center; align-items: center; z-index: 10;
            padding: 3rem 1rem;
        }

        /* Login Card - Scaled Down */
        .login-card {
            background: #ffffff; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            width: 100%; max-width: 420px; padding: 2rem; position: relative;
        }
        
        .logo-circle {
            width: 50px; height: 50px; border-radius: 50%; background: transparent; color: #1d4ed8;
            display: flex; justify-content: center; align-items: center; margin: 0 auto 12px;
            font-size: 1.5rem; border: 3px solid #1d4ed8; box-shadow: inset 0 0 0 3px #eff6ff, 0 4px 10px rgba(29, 78, 216, 0.15);
        }
        
        .brand-name { font-weight: 800; color: #1e293b; font-size: 1.35rem; margin-bottom: 6px; text-align: center; }
        
        .divider-text {
            display: flex; align-items: center; text-align: center; color: #64748b; font-size: 0.75rem; font-weight: 600; margin-bottom: 10px;
        }
        .divider-text::before, .divider-text::after { content: ''; flex: 1; border-bottom: 1px solid #e2e8f0; }
        .divider-text:not(:empty)::before { margin-right: 1em; }
        .divider-text:not(:empty)::after { margin-left: 1em; }

        .welcome-text { text-align: center; color: #64748b; font-size: 0.8rem; margin-bottom: 20px; }

        /* Form Inputs */
        .form-label-custom { font-size: 0.75rem; font-weight: 700; color: #1e293b; margin-bottom: 4px; display: block; text-align: left; }
        
        .input-group-custom { position: relative; margin-bottom: 15px; }
        .input-group-custom i.icon-left { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .input-group-custom i.icon-right { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; transition: 0.2s; }
        .input-group-custom i.icon-right:hover { color: #1d4ed8; }
        .input-group-custom input {
            width: 100%; padding: 10px 15px 10px 42px; border: 1px solid #cbd5e1; border-radius: 8px;
            font-size: 0.85rem; color: #1e293b; transition: all 0.2s; outline: none; background: #ffffff;
        }
        .input-group-custom input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .input-group-custom input::placeholder { color: #94a3b8; font-weight: 400; }
        
        .options-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; font-size: 0.8rem; }
        .options-row .form-check-input { margin-right: 8px; cursor: pointer; border-color: #94a3b8; }
        .options-row .form-check-label { color: #475569; font-weight: 500; cursor: pointer; margin-top: 2px; }
        .options-row a { color: #3b82f6; text-decoration: none; font-weight: 600; }
        .options-row a:hover { text-decoration: underline; }
        
        /* Buttons */
        .btn-primary-custom {
            background: #1d4ed8; color: white; border: none; border-radius: 8px; width: 100%; padding: 10px;
            font-size: 0.9rem; font-weight: 600; transition: all 0.2s; margin-bottom: 15px;
        }
        .btn-primary-custom:hover { background: #1e40af; box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3); }
        
        .or-divider {
            text-align: center; color: #94a3b8; font-size: 0.75rem; margin-bottom: 15px; position: relative;
        }
        .or-divider::before, .or-divider::after {
            content: ''; position: absolute; top: 50%; width: 45%; border-bottom: 1px solid #e2e8f0;
        }
        .or-divider::before { left: 0; }
        .or-divider::after { right: 0; }
        
        .btn-outline-custom {
            background: transparent; color: #1d4ed8; border: 1px solid #1d4ed8; border-radius: 8px; width: 100%; padding: 10px;
            font-size: 0.9rem; font-weight: 600; transition: all 0.2s; margin-bottom: 20px;
        }
        .btn-outline-custom:hover { background: #eff6ff; }
        
        .copyright { text-align: center; color: #94a3b8; font-size: 0.7rem; font-weight: 500; }

        /* Bottom Feature Bar */
        .feature-bar {
            position: relative; width: 100%; background: #1e3a8a;
            display: flex; justify-content: center; gap: 60px; padding: 20px; z-index: 20; color: white; flex-wrap: wrap;
            margin-top: auto;
        }
        .feature-item { display: flex; align-items: center; gap: 12px; }
        .feature-icon { width: 38px; height: 38px; background: rgba(255,255,255,0.15); border-radius: 8px; display: flex; justify-content: center; align-items: center; font-size: 1.1rem; }
        .feature-text .title { font-size: 0.85rem; font-weight: 700; margin-bottom: 2px; }
        .feature-text .desc { font-size: 0.7rem; color: #bfdbfe; font-weight: 500; }

        @media (max-width: 992px) {
            .bg-left { display: none; }
            .bg-right { width: 100%; }
            .feature-bar { display: none !important; }
            .wave-bottom { display: none; }
            .login-card { margin: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
            body, html { background: #f8fafc; }
            .main-wrapper { padding: 2rem 1rem; }
        }
    </style>

</head>
<body>

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
            
            <div class="divider-text" id="portalType">Tenant Portal</div>
            <p class="welcome-text">Welcome back! Please login to access your account.</p>
            
            <?php if ($error): ?>
                <div class="alert alert-danger text-center" style="font-size: 0.85rem; padding: 8px; border-radius: 8px;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="login_type" id="loginType" value="tenant">
                <label class="form-label-custom">Username</label>
                <div class="input-group-custom">
                    <i class="fa-regular fa-user icon-left"></i>
                    <input type="text" name="username" placeholder="Enter your username" required autofocus>
                </div>

                <label class="form-label-custom">Password</label>
                <div class="input-group-custom">
                    <i class="fa-solid fa-lock icon-left"></i>
                    <input type="password" name="password" id="passwordField" placeholder="Enter your password" required>
                    <i class="fa-regular fa-eye icon-right" id="togglePassword" onclick="togglePass()"></i>
                </div>
                
                <div class="options-row">
                    <div class="form-check d-flex align-items-center">
                        <input class="form-check-input" type="checkbox" id="rememberMe">
                        <label class="form-check-label" for="rememberMe">Remember me</label>
                    </div>
                    <a href="#">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary-custom">
                    <i class="fa-solid fa-lock me-2"></i> Login
                </button>
            </form>
            
            <div class="or-divider">or</div>
            
            <button type="button" class="btn-outline-custom" id="toggleLoginModeBtn" onclick="toggleLoginMode()">
                <i class="fa-solid fa-shield-halved me-2" id="toggleLoginIcon"></i> <span id="toggleLoginText">Login as Admin</span>
            </button>
            
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

        function toggleLoginMode() {
            const typeInput = document.getElementById('loginType');
            const portalText = document.getElementById('portalType');
            const toggleText = document.getElementById('toggleLoginText');
            const toggleIcon = document.getElementById('toggleLoginIcon');
            const submitBtn = document.querySelector('.btn-primary-custom');
            
            if (typeInput.value === 'tenant') {
                typeInput.value = 'admin';
                portalText.textContent = 'Admin Portal';
                toggleText.textContent = 'Login as Tenant';
                toggleIcon.className = 'fa-solid fa-user me-2';
                submitBtn.style.background = '#0f172a'; // Darker theme for admin
            } else {
                typeInput.value = 'tenant';
                portalText.textContent = 'Tenant Portal';
                toggleText.textContent = 'Login as Admin';
                toggleIcon.className = 'fa-solid fa-shield-halved me-2';
                submitBtn.style.background = '#1d4ed8'; // Blue for tenant
            }
            document.querySelector('input[name=username]').focus();
        }
    </script>
</body>
</html>
