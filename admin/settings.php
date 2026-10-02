<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/uploads.php';
require_once '../includes/sms.php';

$success = '';
$error = '';

// Write a setting, creating the row when this is the first time it is saved.
function updateSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                           " . sqlUpsert(['setting_key'], ['setting_value']));
    $stmt->execute([$key, $value]);
}

function readSetting($pdo, $key) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? '' : (string)$value;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');
        $confPass = trim($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $currentHash = $stmt->fetchColumn();

        if ($newPass === '') {
            $error = 'Enter a new password.';
        } elseif (!$currentHash || !password_verify($currentPass, $currentHash)) {
            $error = 'Your current password is incorrect.';
        } elseif (strlen($newPass) < 6) {
            $error = 'The new password must be at least 6 characters.';
        } elseif ($newPass !== $confPass) {
            $error = 'Passwords do not match.';
        } elseif ($newPass === $currentPass) {
            $error = 'The new password must be different from your current one.';
        } else {
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$hashed, $_SESSION['user_id']]);
            $success = 'Password updated successfully.';
        }
    } elseif ($action === 'update_payment') {
        updateSetting($pdo, 'gcash_name', $_POST['gcash_name'] ?? '');
        updateSetting($pdo, 'gcash_number', $_POST['gcash_number'] ?? '');
        updateSetting($pdo, 'gcash_instructions', $_POST['gcash_instructions'] ?? '');
        $success = 'Payment settings saved successfully.';

        // GCash QR code image. Tenants see it on the payment page.
        if (isset($_FILES['gcash_qr'])) {
            $upload = storeUploadedImage($_FILES['gcash_qr'], 'qr');
            if ($upload['error']) {
                $error = $upload['error'];
                $success = '';
            } elseif ($upload['path']) {
                deleteLocalUpload(readSetting($pdo, 'gcash_qr_path'));
                updateSetting($pdo, 'gcash_qr_path', $upload['path']);
                updateSetting($pdo, 'gcash_qr_uploaded_at', date('Y-m-d H:i:s'));
                $success = 'Payment settings and GCash QR code saved successfully.';
            }
        }
    } elseif ($action === 'delete_qr') {
        deleteLocalUpload(readSetting($pdo, 'gcash_qr_path'));
        updateSetting($pdo, 'gcash_qr_path', '');
        updateSetting($pdo, 'gcash_qr_uploaded_at', '');
        $success = 'GCash QR code removed.';
    } elseif ($action === 'update_sms') {
        // The saved token is never sent back to the page, so a blank box means "keep it".
        $token = cleanSmsToken((string)($_POST['sms_api_key'] ?? ''));
        $sender = trim($_POST['sms_sender_id'] ?? '') ?: PHILSMS_DEFAULT_SENDER;
        if (!preg_match('/^[A-Za-z0-9 .\-]{1,11}$/', $sender)) {
            $error = 'The sender name can be at most 11 letters or digits, and must be one PhilSMS has approved for your account.';
        } else {
            updateSetting($pdo, 'sms_provider', 'PhilSMS');
            updateSetting($pdo, 'sms_sender_id', $sender);
            if (!empty($_POST['clear_token'])) {
                updateSetting($pdo, 'sms_api_key', '');
                $success = 'SMS settings saved. The API token was removed, so texts are no longer sent.';
            } else {
                if ($token !== '') {
                    updateSetting($pdo, 'sms_api_key', $token);
                }
                $success = 'SMS settings saved. Send a test message to check that they work.';
                if ($token !== '' && !str_contains($token, '|')) {
                    $success .= ' Note: PhilSMS tokens usually start with a number and "|" (like 123|AbC...). If the test says the token was not accepted, copy the whole token again.';
                }
            }
        }
    } elseif ($action === 'sms_test') {
        $number = trim($_POST['test_number'] ?? '');
        if (!normalizePhMobile($number)) {
            $error = 'Enter a Philippine mobile number for the test, like 0917 123 4567.';
        } elseif (!smsConfigured($pdo)) {
            $error = 'Save your PhilSMS API token first.';
        } else {
            $name = smsConfig($pdo)['name'];
            $r = sendSMS($pdo, $number, "$name: This is a test message from your boarding house system. SMS is working!", 'test');
            if ($r['sent'] > 0) {
                $success = 'Test message sent to ' . formatPhMobile(normalizePhMobile($number)) . '. It should arrive within a minute.';
            } else {
                $why = smsErrorText($r);
                $error = (str_contains($why, 'did not answer') ? 'No reply from PhilSMS for the test message. ' : 'Test message not sent. ') . $why;
            }
        }
    } elseif ($action === 'sms_balance') {
        $b = philsmsBalance($pdo);
        if ($b['ok']) {
            $success = 'PhilSMS is connected. Credits left: ' . $b['balance'] . ($b['expires'] ? ' (expires ' . $b['expires'] . ')' : '') . '.';
        } else {
            $error = 'Could not check the balance. ' . $b['error'];
        }
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

// GCash QR code: a Supabase URL when deployed, an uploads/ path when run locally.
$qrSrc = uploadSrc($settingsMap['gcash_qr_path'] ?? '', '../');

// SMS: whether a token is saved (the token itself never goes back to the browser), and the
// latest texts, so the admin can see what went out and why anything didn't.
$smsTokenSaved = trim($settingsMap['sms_api_key'] ?? '') !== '';
$smsTokenHint = $smsTokenSaved ? smsTokenHint(cleanSmsToken($settingsMap['sms_api_key'])) : '';
$smsTokenFromEnv = !$smsTokenSaved && smsConfigured($pdo);
$smsLog = $pdo->query("SELECT l.*, t.first_name, t.last_name FROM sms_log l
                       LEFT JOIN tenants t ON t.id = l.tenant_id
                       ORDER BY l.created_at DESC, l.id DESC LIMIT 15")->fetchAll();
$smsPurposeLabels = [
    'payment_verified' => 'Payment verified', 'payment_covered' => 'Paid by roommate', 'move_in_payment' => 'Move-in payment',
    'payment_rejected' => 'Payment rejected', 'reminder_due' => 'Rent due soon',
    'reminder_overdue' => 'Rent overdue', 'announcement' => 'Announcement', 'test' => 'Test',
];
$qrUploadedAt = $settingsMap['gcash_qr_uploaded_at'] ?? '';

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
<?php if ($error): ?>
    <div class="alert alert-danger py-2 px-3 border-0 rounded-3 shadow-sm mb-3" style="font-size: 0.7rem;">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Main Grid -->
<div class="row g-3">
    
    <!-- LEFT COLUMN -->
    <div class="col-lg-7 col-xl-8">
        
        <!-- Administrator Profile -->
        <div class="settings-card shadow-sm">
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="settings-header">
                    <div>
                        <h6 class="card-title-lg"><i class="fa-regular fa-circle-user icon-header text-primary"></i> Administrator Profile</h6>
                        <div class="card-subtitle-sm">Update your account information and credentials.</div>
                    </div>
                </div>
                <div class="settings-body">
                    <div class="row g-4">
                        <!-- Left Sub-column -->
                        <div class="col-md-6 border-end border-light pe-md-4">
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <input type="text" class="form-control" value="Administrator" disabled>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled>
                                <div class="form-text">Username cannot be changed.</div>
                            </div>
                            <div>
                                <label class="form-label">Current Password</label>
                                <input type="password" class="form-control" name="current_password" placeholder="Enter your current password" autocomplete="current-password">
                                <div class="form-text">Required to confirm it is really you.</div>
                            </div>
                        </div>
                        <!-- Right Sub-column -->
                        <div class="col-md-6 ps-md-4 d-flex flex-column">
                            <h6 class="sub-heading">Change Password</h6>
                            <div class="mb-3 password-input-group">
                                <label class="form-label">New Password</label>
                                <input type="password" class="form-control" name="new_password" placeholder="Enter new password" minlength="6" autocomplete="new-password">
                            </div>
                            <div class="mb-3 password-input-group">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password" minlength="6" autocomplete="new-password">
                            </div>
                            <div class="mt-auto">
                                <button type="submit" class="btn btn-primary btn-save shadow-sm w-auto"><i class="fa-solid fa-lock me-2" style="font-size:0.6rem;"></i>Update Password</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- GCash Payment Information -->
        <div class="settings-card shadow-sm">
            <form method="POST" enctype="multipart/form-data">
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
                                    <?php if ($qrSrc): ?>
                                        <img src="<?= htmlspecialchars($qrSrc) ?>" alt="GCash QR code" class="rounded border me-2" style="width:38px; height:38px; object-fit:cover;">
                                    <?php else: ?>
                                        <i class="fa-solid fa-qrcode fs-3 text-dark opacity-25 me-2"></i>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-bold text-dark" style="font-size:0.65rem;"><?= $qrSrc ? 'QR code uploaded' : 'No QR code yet' ?></div>
                                        <div class="text-muted" style="font-size:0.55rem;">
                                            <?= $qrUploadedAt ? 'Uploaded ' . htmlspecialchars(date('M j, Y', strtotime($qrUploadedAt))) : 'Choose an image below' ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($qrSrc): ?>
                                    <button type="submit" form="deleteQrForm" class="btn btn-link p-0 text-muted" title="Remove QR code"
                                            onclick="return confirm('Remove the GCash QR code? Tenants will stop seeing it on the payment page.');">
                                        <i class="fa-regular fa-trash-can" style="font-size:0.7rem;"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <input type="file" class="form-control mt-2" name="gcash_qr" accept="image/png,image/jpeg,image/webp,image/gif">
                            <div class="form-text">PNG or JPG, up to 5MB. Tenants see this on the payment page.</div>
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
        
        <!-- SMS (PhilSMS) -->
        <div class="settings-card shadow-sm mb-0" id="sms">
            <form method="POST">
                <input type="hidden" name="action" value="update_sms">
                <div class="settings-header">
                    <div>
                        <h6 class="card-title-lg"><i class="fa-solid fa-comment-sms icon-header text-primary"></i> SMS Notifications (PhilSMS)</h6>
                        <div class="card-subtitle-sm">Texts tenants when payments are verified or rejected, rent reminders, and announcements.</div>
                    </div>
                    <?php if ($smsTokenSaved || $smsTokenFromEnv): ?>
                        <span class="badge badge-soft-success align-self-start"><i class="fa-solid fa-circle-check me-1"></i>Set up</span>
                    <?php else: ?>
                        <span class="badge badge-soft-warning align-self-start">Not set up</span>
                    <?php endif; ?>
                </div>
                <div class="settings-body">
                    <?php if (!$smsTokenSaved && !$smsTokenFromEnv): ?>
                    <div class="alert alert-light border small py-2 mb-3">
                        Until an API token is saved, no texts are sent; they are listed below as "skipped" instead.
                        Get the token from your PhilSMS account: <strong>dashboard.philsms.com &rarr; Developers &rarr; API Token</strong>.
                    </div>
                    <?php endif; ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-7 password-input-group">
                            <label class="form-label">PhilSMS API Token</label>
                            <input type="password" name="sms_api_key" class="form-control" autocomplete="off"
                                   placeholder="<?= $smsTokenSaved ? 'Saved. Leave blank to keep it' : ($smsTokenFromEnv ? 'Set on the server (PHILSMS_API_TOKEN)' : 'Paste your API token') ?>">
                            <i class="fa-regular fa-eye eye-icon"></i>
                            <?php if ($smsTokenSaved): ?>
                            <div class="form-text" style="font-size:0.65rem;">Saved token: <code><?= htmlspecialchars($smsTokenHint) ?></code>. Compare it with the one on the PhilSMS Developers page.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Sender Name</label>
                            <input type="text" name="sms_sender_id" class="form-control" maxlength="11" value="<?= $s('sms_sender_id') ?: PHILSMS_DEFAULT_SENDER ?>">
                            <div class="form-text" style="font-size:0.65rem;">Shown as the sender. Use "PhilSMS" unless PhilSMS approved your own.</div>
                        </div>

                    </div>
                    <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-1 gap-2 flex-wrap">
                        <?php if ($smsTokenSaved): ?>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="clear_token" value="1" id="clearToken">
                            <label class="form-check-label small text-muted" for="clearToken">Remove the saved token</label>
                        </div>
                        <?php else: ?><span></span><?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-save shadow-sm"><i class="fa-regular fa-floppy-disk me-2" style="font-size:0.65rem;"></i>Save SMS Settings</button>
                    </div>
                </div>
            </form>

            <div class="settings-body border-top">
                <div class="row g-2 align-items-end">
                    <div class="col-md-7">
                        <form method="POST" class="d-flex gap-2 align-items-end">
                            <input type="hidden" name="action" value="sms_test">
                            <div class="flex-grow-1">
                                <label class="form-label">Send a test SMS to</label>
                                <input type="text" name="test_number" class="form-control" placeholder="e.g., 0917 123 4567" required>
                            </div>
                            <button type="submit" class="btn btn-outline-primary btn-save text-nowrap"><i class="fa-solid fa-paper-plane me-1"></i>Send Test</button>
                        </form>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action" value="sms_balance">
                            <button type="submit" class="btn btn-light border btn-save text-nowrap"><i class="fa-solid fa-coins me-1 text-warning"></i>Check Credits</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="settings-body border-top">
                <div class="fw-semibold text-dark mb-2" style="font-size: 0.8rem;">Recent SMS</div>
                <?php if (empty($smsLog)): ?>
                    <div class="text-muted small">No texts yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="font-size: 0.72rem;">
                        <thead class="table-light"><tr><th>When</th><th>To</th><th>Type</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($smsLog as $l): ?>
                            <tr title="<?= htmlspecialchars($l['message']) ?>">
                                <td class="text-muted text-nowrap"><?= date('M j, g:i A', strtotime($l['created_at'])) ?></td>
                                <td>
                                    <?= htmlspecialchars(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?: '-') ?>
                                    <div class="text-muted"><?= htmlspecialchars(($n = normalizePhMobile($l['recipient'])) ? formatPhMobile($n) : ($l['recipient'] ?: 'no number')) ?></div>
                                </td>
                                <td><?= htmlspecialchars($smsPurposeLabels[$l['purpose']] ?? ucfirst(str_replace('_', ' ', $l['purpose']))) ?></td>
                                <td>
                                    <?php if ($l['status'] === 'sent'): ?>
                                        <span class="badge badge-soft-success">Sent</span>
                                    <?php elseif ($l['status'] === 'failed'): ?>
                                        <span class="badge badge-soft-danger">Failed</span>
                                    <?php elseif ($l['status'] === 'unknown'): ?>
                                        <span class="badge badge-soft-warning">No reply</span>
                                    <?php else: ?>
                                        <span class="badge badge-soft-secondary">Skipped</span>
                                    <?php endif; ?>
                                    <?php if (!empty($l['error'])): ?>
                                        <div class="text-muted" style="font-size: 0.65rem; max-width: 220px;"><?= htmlspecialchars($l['error']) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
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

<form method="POST" id="deleteQrForm" class="d-none">
    <input type="hidden" name="action" value="delete_qr">
</form>

<?php require_once 'footer.php'; ?>
