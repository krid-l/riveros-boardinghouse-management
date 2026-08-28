<?php
require_once 'header.php';

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
            
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, temp_password = NULL WHERE id = ?");
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
            
            $success = "Password updated successfully!";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        try {
            $contact = $_POST['contact_number'] ?? '';
            $occupation = $_POST['occupation'] ?? '';
            $emergency = $_POST['emergency_contact'] ?? '';

            $picUpdate = '';
            $params = [$contact, $occupation, $emergency];

            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadDir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                
                $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
                $cleanFileName = preg_replace('/[^A-Za-z0-9.\-_]/', '_', basename($_FILES['profile_picture']['name']));
                $fileName = time() . '_' . $cleanFileName;
                
                if (move_uploaded_file($fileTmpPath, $uploadDir . $fileName)) {
                    $picUpdate = ', profile_picture = ?';
                    $params[] = 'uploads/profiles/' . $fileName;
                }
            }
            $params[] = $_SESSION['tenant_id'];

            $stmt = $pdo->prepare("UPDATE tenants SET contact_number = ?, occupation = ?, emergency_contact = ? $picUpdate WHERE id = ?");
            $stmt->execute($params);
            
            $success = 'Profile updated successfully!';
            
            // Refresh data
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
            $stmt->execute([$_SESSION['tenant_id']]);
            $currentTenant = $stmt->fetch();
        } catch (Exception $e) {
            $error = "Error updating profile: " . $e->getMessage();
        }
    }
}

// Generate Avatar URL
$fullName = htmlspecialchars($currentTenant['first_name'] . ' ' . $currentTenant['last_name']);
if (!empty($currentTenant['profile_picture'])) {
    $avatarUrl = '../' . htmlspecialchars($currentTenant['profile_picture']);
} else {
    $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($fullName) . "&background=10b981&color=fff&size=128";
}
?>

<div class="d-flex justify-content-between align-items-end mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Profile Settings</h3>
        <p class="text-muted mb-0">Complete your profile information and update your contact details.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm text-center pt-5 pb-4 px-4 h-100">
            <img src="<?= $avatarUrl ?>" class="rounded-circle mx-auto mb-3 shadow-sm" width="100" height="100" alt="Avatar">
            <h5 class="fw-bold text-dark mb-1"><?= $fullName ?></h5>
            <p class="text-muted small mb-3">Tenant Account</p>
            <hr class="text-muted my-4">
            <div class="text-start">
                <div class="mb-3">
                    <span class="text-muted d-block" style="font-size:0.75rem;"><i class="fa-solid fa-envelope me-2"></i> Username</span>
                    <span class="fw-semibold text-dark"><?= htmlspecialchars($username) ?></span>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size:0.75rem;"><i class="fa-solid fa-phone me-2"></i> Contact</span>
                    <span class="fw-semibold text-dark"><?= !empty($currentTenant['contact_number']) ? htmlspecialchars($currentTenant['contact_number']) : '<i class="text-black-50 small">Not set</i>' ?></span>
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

                    
                    
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-semibold small">First Name</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($currentTenant['first_name']) ?>" readonly>
                            <div class="form-text" style="font-size: 0.65rem;">Contact admin to change name.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-semibold small">Last Name</label>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($currentTenant['last_name']) ?>" readonly>
                        </div>
                    </div>
                    
                    
                    
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-12">
                            <label class="form-label text-muted fw-semibold small">Contact Number</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white"><i class="fa-solid fa-phone text-muted" style="font-size: 0.8rem;"></i></span>
                                <input type="text" class="form-control" name="contact_number" value="<?= htmlspecialchars($currentTenant['contact_number'] ?? '') ?>" placeholder="e.g., 0912 345 6789">
                            </div>
                        </div>

                    </div>

                    
                    
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-semibold small">Occupation</label>
                            <input type="text" class="form-control" name="occupation" value="<?= htmlspecialchars($currentTenant['occupation'] ?? '') ?>" placeholder="e.g., Student, Software Engineer">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-semibold small">Emergency Contact</label>
                            <input type="text" class="form-control" name="emergency_contact" value="<?= htmlspecialchars($currentTenant['emergency_contact'] ?? '') ?>" placeholder="e.g., Jane Doe - 0998 765 4321">
                        </div>
                    </div>



                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-4 rounded-pill shadow-sm"><i class="fa-solid fa-floppy-disk me-2"></i> Save Profile Details</button>
                    </div>
                </form>
            </div>
        </div>
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

<?php require_once 'footer.php'; ?>
