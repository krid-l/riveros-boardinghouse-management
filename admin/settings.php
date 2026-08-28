<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$success = '';
$error = '';

// Helper function to update setting
function updateSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
    $stmt->execute([$value, $key]);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $newPass = trim($_POST['new_password'] ?? '');
        $confPass = trim($_POST['confirm_password'] ?? '');
        
        if (!empty($newPass)) {
            if ($newPass !== $confPass) {
                $error = 'Passwords do not match.';
            } else {
                $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                if ($stmt->execute([$hashed, $_SESSION['user_id']])) {
                    $success = 'Profile and password updated successfully.';
                } else {
                    $error = 'Failed to update profile.';
                }
            }
        } else {
            $success = 'Profile updated successfully.';
        }
    } elseif ($action === 'update_payment') {
        updateSetting($pdo, 'gcash_name', $_POST['gcash_name'] ?? '');
        updateSetting($pdo, 'gcash_number', $_POST['gcash_number'] ?? '');
        updateSetting($pdo, 'gcash_instructions', $_POST['gcash_instructions'] ?? '');
        $success = 'Payment settings saved successfully.';
    } elseif ($action === 'update_sms') {
        updateSetting($pdo, 'sms_provider', $_POST['sms_provider'] ?? '');
        updateSetting($pdo, 'sms_api_key', $_POST['sms_api_key'] ?? '');
        updateSetting($pdo, 'sms_sender_id', $_POST['sms_sender_id'] ?? '');
        $success = 'SMS settings saved successfully.';
    } elseif ($action === 'update_business') {
        updateSetting($pdo, 'boarding_house_name', $_POST['boarding_house_name'] ?? '');
        updateSetting($pdo, 'address', $_POST['address'] ?? '');
        updateSetting($pdo, 'contact_number', $_POST['contact_number'] ?? '');
        $success = 'Business information updated successfully.';
    } elseif ($action === 'update_prefs') {
        updateSetting($pdo, 'rent_due_date', $_POST['rent_due_date'] ?? '');
        updateSetting($pdo, 'currency', $_POST['currency'] ?? '');
        updateSetting($pdo, 'date_format', $_POST['date_format'] ?? '');
        updateSetting($pdo, 'time_zone', $_POST['time_zone'] ?? '');
        $success = 'System preferences updated successfully.';
    }
}

// Fetch current user details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Fetch settings
$settingsMap = [];
$settingsRows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
foreach ($settingsRows as $row) {
    $settingsMap[$row['setting_key']] = $row['setting_value'];
}
$s = function($key) use ($settingsMap) {
    return htmlspecialchars($settingsMap[$key] ?? '');
};

require_once 'header.php';
?>

<style>
    body { background-color: #f8fafc; }
    
    /* Layout & Cards */
    .settings-card { border: 1px solid #f1f5f9; background: #fff; border-radius: 12px; margin-bottom: 1.25rem; }
    .settings-header { padding: 1.25rem 1.5rem 0.5rem 1.5rem; display: flex; justify-content: space-between; align-items: flex-start; }
    .settings-body { padding: 1rem 1.5rem 1.5rem 1.5rem; }
    
    /* Typography */
    .card-title-lg { font-size: 0.85rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; margin-bottom: 2px; }
    .card-subtitle-sm { font-size: 0.65rem; color: #64748b; margin-left: 28px; }
    .icon-header { width: 20px; text-align: center; margin-right: 8px; font-size: 0.9rem; }
    
    /* Form Elements */
    .form-label { font-size: 0.65rem; font-weight: 700; color: #1e293b; margin-bottom: 0.3rem; }
    .form-control, .form-select { font-size: 0.7rem; padding: 0.5rem 0.75rem; border-color: #e2e8f0; border-radius: 6px; box-shadow: none; color: #475569; }
    .form-control:focus, .form-select:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.1rem rgba(13, 110, 253, 0.1); }
    .form-control:disabled, .form-control[readonly] { background-color: #f8fafc; color: #94a3b8; }
    .form-text { font-size: 0.6rem; color: #94a3b8; margin-top: 0.3rem; }
    
    /* Password Eye Icon */
    .password-input-group { position: relative; }
    .password-input-group .eye-icon { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; font-size: 0.7rem; }
    
    /* Buttons */
    .btn-save { font-size: 0.7rem; font-weight: 600; padding: 0.5rem 1.25rem; border-radius: 6px; }
    .btn-outline-custom { font-size: 0.65rem; font-weight: 600; padding: 0.3rem 0.75rem; border-radius: 6px; border: 1px solid #e2e8f0; color: #0d6efd; background: transparent; transition: 0.2s; }
    .btn-outline-custom:hover { background: #f8fafc; border-color: #cbd5e1; }
    
    /* Tabs */
    .nav-tabs-custom { border-bottom: 1px solid #e2e8f0; display: flex; gap: 1.5rem; overflow-x: auto; white-space: nowrap; margin-bottom: 1.5rem; padding-bottom: 0px; }
    .nav-tabs-custom .tab-item { 
        padding: 0.75rem 0; font-size: 0.7rem; font-weight: 600; color: #64748b; 
        cursor: pointer; border-bottom: 2px solid transparent; display: flex; align-items: center;
    }
    .nav-tabs-custom .tab-item i { margin-right: 8px; font-size: 0.8rem; }
    .nav-tabs-custom .tab-item.active { color: #0d6efd; border-bottom: 2px solid #0d6efd; }
    
    /* Sub-section headings */
    .sub-heading { font-size: 0.75rem; font-weight: 700; color: #1e293b; margin-bottom: 1rem; }
    
    /* QR Upload Box */
    .qr-upload-box { border: 1px dashed #cbd5e1; border-radius: 6px; padding: 0.5rem; display: flex; align-items: center; justify-content: space-between; }
    
    /* Info Row */
    .info-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; font-size: 0.65rem; }
    
    /* Active Badge */
    .badge-active { background-color: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; font-size: 0.6rem; font-weight: 600; padding: 0.2rem 0.6rem; border-radius: 20px; display: inline-flex; align-items: center; }
</style>

<!-- Header -->
<div class="mb-4 pt-2">
    <h5 class="fw-bold mb-1 text-dark">System Settings</h5>
    <p class="text-muted mb-0" style="font-size: 0.7rem;">Manage system preferences, administrator account, and application settings.</p>
</div>


    
<?php if ($success): ?>
    <div class="alert alert-success py-2 px-3 border-0 rounded-3 shadow-sm mb-3" style="font-size: 0.7rem;">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<!-- Main Grid -->
<div class="row g-3">
    
    <!-- LEFT COLUMN -->
    <div class="col-lg-7 col-xl-8">
        
        <!-- Administrator Profile -->
        <div class="settings-card shadow-sm">
            <div class="settings-header">
                <div>
                    <h6 class="card-title-lg"><i class="fa-regular fa-circle-user icon-header text-primary"></i> Administrator Profile</h6>
                    <div class="card-subtitle-sm">Update your account information and credentials.</div>
                </div>
                <button class="btn btn-outline-custom"><i class="fa-solid fa-pen me-1" style="font-size:0.55rem;"></i> Edit Profile</button>
            </div>
            <div class="settings-body">
                <div class="row g-4">
                    <!-- Left Sub-column -->
                    <div class="col-md-6 border-end border-light pe-md-4">
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" value="Administrator">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" value="admin@boardinghouse.com">
                        </div>
                        <div>
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
                            <div class="form-text">Username cannot be changed.</div>
                        </div>
                    </div>
                    <!-- Right Sub-column -->
                    <div class="col-md-6 ps-md-4 d-flex flex-column">
                        <h6 class="sub-heading">Change Password</h6>
                        <div class="mb-3 password-input-group">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" placeholder="Enter new password">
                            <i class="fa-regular fa-eye eye-icon"></i>
                        </div>
                        <div class="mb-3 password-input-group">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" placeholder="Confirm new password">
                            <i class="fa-regular fa-eye eye-icon"></i>
                        </div>
                        <div class="mt-auto">
                            <button class="btn btn-primary btn-save shadow-sm w-auto"><i class="fa-solid fa-lock me-2" style="font-size:0.6rem;"></i>Update Password</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- GCash Payment Information -->
        <div class="settings-card shadow-sm">
            <form method="POST">
                <input type="hidden" name="action" value="update_payment">
                <div class="settings-header">
                    <div>
                        <h6 class="card-title-lg"><i class="fa-solid fa-g icon-header text-primary" style="font-style:italic; font-weight:900;"></i> GCash Payment Information</h6>
                        <div class="card-subtitle-sm">Provide your GCash details where tenants can send their payments.</div>
                    </div>
                </div>
                <div class="settings-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">GCash Registered Name</label>
                            <input type="text" class="form-control" name="gcash_name" value="<?= $s('gcash_name') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">GCash Mobile Number</label>
                            <input type="text" class="form-control" name="gcash_number" value="<?= $s('gcash_number') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">GCash QR Code (Optional)</label>
                            <div class="qr-upload-box">
                                <div class="d-flex align-items-center">
                                    <i class="fa-solid fa-qrcode fs-3 text-dark opacity-75 me-2"></i>
                                    <div>
                                        <div class="fw-bold text-dark" style="font-size:0.65rem;">GCash QR.png</div>
                                        <div class="text-muted" style="font-size:0.55rem;">Uploaded on Aug 1, 2025</div>
                                    </div>
                                </div>
                                <i class="fa-regular fa-trash-can text-muted" style="cursor:pointer; font-size:0.7rem;"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Payment Instructions (Shown to Tenants)</label>
                        <textarea class="form-control" name="gcash_instructions" rows="2"><?= $s('gcash_instructions') ?></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-1">
                        <div class="d-flex align-items-center">
                            <span class="badge-active me-2"><i class="fa-solid fa-circle-check me-1"></i> Active</span>
                            <span class="text-muted" style="font-size:0.65rem;">Tenants can view this payment information.</span>
                        </div>
                        <button type="submit" class="btn btn-primary btn-save shadow-sm"><i class="fa-regular fa-floppy-disk me-2" style="font-size:0.65rem;"></i>Save Payment Settings</button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- SMS API Integration -->
        <div class="settings-card shadow-sm mb-0">
            <form method="POST">
                <input type="hidden" name="action" value="update_sms">
                <div class="settings-header">
                    <div>
                        <h6 class="card-title-lg"><i class="fa-solid fa-comment-sms icon-header text-primary"></i> SMS API Integration</h6>
                        <div class="card-subtitle-sm">Configure your SMS gateway for sending notifications to tenants.</div>
                    </div>
                </div>
                <div class="settings-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">SMS Provider</label>
                            <select class="form-select" name="sms_provider">
                                <option value="Local SMS Gateway" <?= $s('sms_provider') == 'Local SMS Gateway' ? 'selected' : '' ?>>Local SMS Gateway</option>
                                <option value="Semaphore API" <?= $s('sms_provider') == 'Semaphore API' ? 'selected' : '' ?>>Semaphore API</option>
                            </select>
                        </div>
                        <div class="col-md-4 password-input-group">
                            <label class="form-label">API Key</label>
                            <input type="password" name="sms_api_key" class="form-control" value="<?= $s('sms_api_key') ?>">
                            <i class="fa-regular fa-eye eye-icon"></i>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sender ID</label>
                            <input type="text" name="sms_sender_id" class="form-control" value="<?= $s('sms_sender_id') ?>">
                        </div>
                    </div>
                    <div class="text-end border-top pt-3 mt-1">
                        <button type="submit" class="btn btn-primary btn-save shadow-sm"><i class="fa-regular fa-floppy-disk me-2" style="font-size:0.65rem;"></i>Save SMS Settings</button>
                    </div>
                </div>
            </form>
        </div>
        
    </div>
    
    <!-- RIGHT COLUMN -->
    <div class="col-lg-5 col-xl-4">
        
        <!-- Business Information -->
        <div class="settings-card shadow-sm">
            <form method="POST">
                <input type="hidden" name="action" value="update_business">
                <div class="settings-header">
                    <div>
                        <h6 class="card-title-lg"><i class="fa-regular fa-building icon-header text-primary"></i> Business Information</h6>
                        <div class="card-subtitle-sm">Update your boarding house details.</div>
                    </div>
                </div>
                <div class="settings-body pt-2">
                    <div class="mb-2">
                        <label class="form-label">Boarding House Name</label>
                        <input type="text" name="boarding_house_name" class="form-control" value="<?= $s('boarding_house_name') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"><?= $s('address') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" value="<?= $s('contact_number') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-save shadow-sm w-100"><i class="fa-regular fa-floppy-disk me-2" style="font-size:0.65rem;"></i>Update Information</button>
                </div>
            </form>
        </div>
        
        <!-- Account Information -->
        <div class="settings-card shadow-sm">
            <div class="settings-header">
                <div>
                    <h6 class="card-title-lg"><i class="fa-regular fa-user icon-header text-primary"></i> Account Information</h6>
                </div>
            </div>
            <div class="settings-body pt-3">
                <div class="info-row">
                    <span class="text-muted fw-semibold">Role</span>
                    <span class="text-dark">Administrator</span>
                </div>
                <div class="info-row">
                    <span class="text-muted fw-semibold">Last Login</span>
                    <span class="text-dark">Aug 26, 2025 10:30 AM</span>
                </div>
                <div class="info-row mb-4">
                    <span class="text-muted fw-semibold">Account Status</span>
                    <span class="badge-active px-2 py-1">Active</span>
                </div>
                
                <button class="btn btn-outline-danger shadow-none w-100 py-2 rounded-2 fw-semibold" style="font-size:0.7rem; border-color: #fca5a5; color: #ef4444;"><i class="fa-regular fa-trash-can me-2"></i>Deactivate Account</button>
            </div>
        </div>
        
    </div>
</div>

<?php require_once 'footer.php'; ?>
