<?php
require_once 'header.php';
require_once '../includes/uploads.php';
require_once '../includes/sms.php';
require_once '../includes/tenant_profile.php';

// Fetch the username from users table for this tenant
$stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmtUser->execute([$_SESSION['user_id']]);
$userRec = $stmtUser->fetch();
$username = $userRec ? $userRec['username'] : '';

$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        try {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($current, $user['password_hash'])) {
                throw new Exception("Current password is incorrect.");
            }
            if ($new !== $confirm) {
                throw new Exception("New passwords do not match.");
            }
            if (strlen($new) < 6) {
                throw new Exception("New password must be at least 6 characters.");
            }
            if ($new === $current) {
                throw new Exception("New password must be different from your current password.");
            }
            
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, temp_password = NULL WHERE id = ?");
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            
            $success = "Password updated successfully!";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        try {
            // The same details the admin entered when adding the tenant; only the name is the admin's.
            $profile = tenantProfileFromPost($_POST, false);

            $picUpdate = '';
            $params = array_values($profile);

            // Saved to Supabase Storage when deployed, to uploads/profiles/ when run locally.
            if (isset($_FILES['profile_picture'])) {
                $upload = storeUploadedImage($_FILES['profile_picture'], 'profiles');
                if ($upload['error']) {
                    throw new Exception($upload['error']);
                }
                if ($upload['path']) {
                    $picUpdate = ', profile_picture = ?';
                    $params[] = $upload['path'];
                }
            }
            $params[] = $_SESSION['tenant_id'];

            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($profile)));
            $stmt = $pdo->prepare("UPDATE tenants SET $set $picUpdate WHERE id = ?");
            $stmt->execute($params);
            
            $success = 'Profile updated successfully!';
            
            // Refresh data
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmt->execute([$_SESSION['tenant_id']]);
            $currentTenant = $stmt->fetch();
        } catch (Exception $e) {
            $error = "Profile not saved: " . $e->getMessage();
            $profileOld = $_POST;   // keep what was typed
        }
    }
}

// Generate Avatar URL
$fullName = htmlspecialchars($currentTenant['first_name'] . ' ' . $currentTenant['last_name']);
// The uploaded photo when there is one, otherwise initials drawn in the page.
?>

<div class="d-flex justify-content-between align-items-end mb-4">
    <div>
        <p class="text-muted mb-0">Complete your profile information and update your contact details.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm text-center pt-5 pb-4 px-4 h-100">
            <div class="mx-auto mb-3"><?= avatarHtml($currentTenant['first_name'] . ' ' . $currentTenant['last_name'], 100, 'shadow-sm', $currentTenant['profile_picture'] ?? null, '../') ?></div>
            <h5 class="fw-bold text-dark mb-1"><?= $fullName ?></h5>
            <p class="text-muted small mb-3">Tenant Account</p>
            <hr class="text-muted my-4">
            <div class="text-start">
                <div class="mb-3">
                    <span class="text-muted d-block" style="font-size:0.75rem;"><i class="fa-solid fa-user me-2"></i> Username</span>
                    <span class="fw-semibold text-dark"><?= htmlspecialchars($username) ?></span>
                </div>
                <div class="mb-3">
                    <span class="text-muted d-block" style="font-size:0.75rem;"><i class="fa-solid fa-phone me-2"></i> Mobile number</span>
                    <span class="fw-semibold text-dark"><?= !empty($currentTenant['contact_number']) ? htmlspecialchars($currentTenant['contact_number'] ?? '') : '<i class="text-black-50 small">Not set</i>' ?></span>
                </div>
                <div class="mb-3">
                    <span class="text-muted d-block" style="font-size:0.75rem;"><i class="fa-solid fa-at me-2"></i> Email</span>
                    <span class="fw-semibold text-dark text-break"><?= !empty($currentTenant['email']) ? htmlspecialchars($currentTenant['email']) : '<i class="text-black-50 small">Not set</i>' ?></span>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size:0.75rem;"><i class="fa-solid fa-truck-medical me-2"></i> Emergency contact</span>
                    <?php $emergencyText = tenantEmergencyText($currentTenant); ?>
                    <span class="fw-semibold text-dark"><?= $emergencyText !== '' ? htmlspecialchars($emergencyText) : '<i class="text-black-50 small">Not set</i>' ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-8">
        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-center border-0 rounded-3 shadow-sm" role="alert">
                <i class="fa-solid fa-circle-check fa-lg me-3"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center border-0 rounded-3 shadow-sm" role="alert">
                <i class="fa-solid fa-triangle-exclamation fa-lg me-3"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark"><i class="fa-solid fa-user-pen me-2 text-primary"></i> Personal Information</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-4 mt-2">
                        <label class="form-label text-muted fw-semibold small">Profile Picture</label>
                        <input type="file" class="form-control" name="profile_picture" accept="image/*">
                        <div class="form-text" style="font-size: 0.65rem;">Leave empty to keep current picture. Recommended size: 200x200px.</div>
                    </div>

                    <div class="form-compact mb-3">
                        <?= tenantProfileFormFields(isset($profileOld) ? array_merge($currentTenant, $profileOld) : $currentTenant, 'me', false) ?>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-4 rounded-pill shadow-sm"><i class="fa-solid fa-floppy-disk me-2"></i> Save Profile Details</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="m-0 fw-bold text-dark"><i class="fa-solid fa-lock me-2 text-danger"></i> Change Password</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold small">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold small">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted fw-semibold small">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6">
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-danger px-4 rounded-pill shadow-sm"><i class="fa-solid fa-key me-2"></i> Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
