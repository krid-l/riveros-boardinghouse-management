<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
require_once '../includes/sms.php';
require_once '../includes/sms_reminders.php';
require_once '../includes/password_reset.php';
require_once '../includes/tenant_profile.php';
require_once '../includes/uploads.php';
require_once '../includes/sms_templates.php';
require_once '../includes/deposit_requests.php';
requireAdmin();

$reminderSuccess = '';
$reminderError   = '';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Tenant ID.");
}
$tenantId = (int)$_GET['id'];

// Reset Password: a new temporary password, shown here and texted to the tenant when SMS is set
// up. For a tenant who forgot theirs and can't get a code by SMS.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $tStmt = $pdo->prepare("SELECT t.user_id, t.first_name, t.contact_number, t.status, u.username FROM tenants t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $tStmt->execute([$tenantId]);
    $tRow = $tStmt->fetch();
    if (!$tRow) {
        $reminderError = 'Tenant not found.';
    } else {
        $newPassword = generateTempPassword();
        $pdo->prepare("UPDATE users SET password_hash = ?, temp_password = ? WHERE id = ?")
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $newPassword, $tRow['user_id']]);
        $reminderSuccess = "Password reset. New temporary password: $newPassword";
        if ($tRow['status'] === 'active' && smsConfigured($pdo) && normalizePhMobile($tRow['contact_number'])) {
            $r = sendSMS($pdo, $tRow['contact_number'],
                smsConfig($pdo)['name'] . ": Hi {$tRow['first_name']}, your password was reset by the admin. Username: {$tRow['username']} Temporary password: $newPassword Please change it after you log in.",
                'password_reset', $tenantId);
            $reminderSuccess .= $r['sent'] > 0 ? ' (texted to the tenant).' : '. ' . smsOutcomeText($r);
        }
    }
}

// Edit the tenant's details: the same fields as Add Tenant and the tenant's Profile Settings.
$profileError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_profile' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    try {
        $profile = tenantProfileFromPost($_POST);
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($profile)));
        $pdo->prepare("UPDATE tenants SET $set WHERE id = ?")->execute(array_merge(array_values($profile), [$tenantId]));
        $reminderSuccess = 'Tenant details saved.';
    } catch (Exception $e) {
        $profileError = $e->getMessage();
        $reminderError = $profileError;
    }
}

// Documents: files the admin keeps for the tenant (a photo of an ID, a signed contract).
$docMessage = '';
$docError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120);
    $upload = isset($_FILES['document']) ? storeUploadedDocument($_FILES['document']) : ['path' => null, 'error' => 'Choose a file.'];
    if ($title === '') {
        $docError = 'Give the document a name, e.g. "Valid ID" or "Lease contract".';
        if ($upload['path']) deleteLocalUpload($upload['path']);
    } elseif ($upload['error'] || !$upload['path']) {
        $docError = $upload['error'] ?: 'Choose a file.';
    } else {
        $ext = strtolower(pathinfo(parse_url($upload['path'], PHP_URL_PATH) ?: $upload['path'], PATHINFO_EXTENSION));
        $pdo->prepare("INSERT INTO tenant_documents (tenant_id, title, file_path, file_type, uploaded_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$tenantId, $title, $upload['path'], $ext, date('Y-m-d H:i:s')]);
        $docMessage = 'Document saved.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_document' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $doc = $pdo->prepare("SELECT file_path FROM tenant_documents WHERE id = ? AND tenant_id = ?");
    $doc->execute([(int)($_POST['document_id'] ?? 0), $tenantId]);
    $path = $doc->fetchColumn();
    if ($path !== false) {
        $pdo->prepare("DELETE FROM tenant_documents WHERE id = ?")->execute([(int)$_POST['document_id']]);
        deleteLocalUpload($path);
        $docMessage = 'Document deleted.';
    }
}

// The admin's own notes on the tenant (only the admin sees them).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $notes = trim((string)($_POST['admin_notes'] ?? ''));
    $pdo->prepare("UPDATE tenants SET admin_notes = ? WHERE id = ?")->execute([$notes === '' ? null : mb_substr($notes, 0, 5000), $tenantId]);
    $reminderSuccess = 'Notes saved.';
}

// The tenant asked to use their deposit for their last month: approve or decline it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposit_decide' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $approve = ($_POST['decision'] ?? '') === 'approve';
    $r = decideDepositRequest($pdo, (int)($_POST['request_id'] ?? 0), $approve, (string)($_POST['note'] ?? ''));
    if ($r['error'] !== '') {
        $reminderError = $r['error'];
    } else {
        $reminderSuccess = ($approve
            ? 'Deposit of ₱' . number_format($r['applied'], 2) . ' applied to the requested month.'
            : 'Request declined.') . ' ' . smsOutcomeText($r['sms']);
    }
}

// Handle Send Reminder action before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminder' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    // Re-fetch minimal tenant info needed for the reminder message
    $tStmt = $pdo->prepare("SELECT id, first_name, last_name, contact_number, balance, status, room_id, move_in_date FROM tenants WHERE id = ?");
    $tStmt->execute([$tenantId]);
    $tRow = $tStmt->fetch();

    if (!$tRow || $tRow['status'] !== 'active') {
        $reminderError = 'Reminder can only be sent to active tenants.';
    } elseif ((float)$tRow['balance'] <= 0) {
        $reminderError = 'This tenant has no outstanding balance — no reminder needed.';
    } elseif (!smsConfigured($pdo)) {
        $reminderError = 'SMS is not set up yet. Add your PhilSMS API token in Settings.';
    } else {
        $billing = tenantBillingStatus($tRow, chargesNotYetDue($pdo, $tenantId));
        $name    = trim($tRow['first_name'] . ' ' . $tRow['last_name']);

        if ($billing['key'] === 'overdue') {
            $since   = oldestUnpaidDueDate($pdo, $tenantId, (float)$tRow['balance']);
            $ref     = 'overdue:' . $tenantId . ':' . date('o-W');
            $message = smsOverdueMessage($pdo, $name, $billing['overdue'], $since);
        } else {
            // Not late yet: the bill coming up, or whatever is owed if nothing is scheduled.
            $due  = upcomingDueDate($pdo, $tenantId) ?? date('Y-m-d');
            $rent = $pdo->prepare("SELECT SUM(amount) FROM charges WHERE tenant_id = ? AND due_date = ? AND amount > 0");
            $rent->execute([$tenantId, $due]);
            $ref     = 'due:manual:' . $tenantId . ':' . date('Y-m-d');
            $message = smsDueReminderMessage($pdo, $name, (float)$rent->fetchColumn() ?: (float)$tRow['balance'], $due, (float)$tRow['balance']);
        }

        $r = sendSMS($pdo, $tRow['contact_number'], $message, 'reminder_' . $billing['key'], $tenantId, $ref);
        if ($r['sent'] > 0) {
            $reminderSuccess = 'Reminder sent to ' . formatPhMobile(normalizePhMobile($tRow['contact_number'])) . '.';
        } else {
            $reminderError = 'Reminder not sent. ' . smsErrorText($r);
        }
    }
}

// Fetch Tenant & Room details
$stmt = $pdo->prepare("
    SELECT t.*, u.username, u.temp_password, r.room_number, r.price_per_month, r.capacity AS room_capacity, u.created_at as account_created
    FROM tenants t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN rooms r ON t.room_id = r.id
    WHERE t.id = ?
");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die("Tenant not found.");
}

// Fetch Payments
$payStmt = $pdo->prepare("
    SELECT * FROM payments 
    WHERE tenant_id = ? 
    ORDER BY payment_date DESC, id DESC
");
$payStmt->execute([$tenantId]);
$payments = $payStmt->fetchAll();

// Amount each verified payment credited to THIS tenant (a room payment's roommate shares are credited to the roommates).
$creditStmt = $pdo->prepare("
    SELECT p.id, p.payment_date, p.amount, p.payment_method, p.covered_by_payment_id,
           p.amount - COALESCE((SELECT SUM(k.amount) FROM payments k WHERE k.covered_by_payment_id = p.id), 0) AS credited
    FROM payments p
    WHERE p.tenant_id = ? AND p.status = 'verified'
");
$creditStmt->execute([$tenantId]);
$credits = $creditStmt->fetchAll();

$chargeStmt = $pdo->prepare("SELECT * FROM charges WHERE tenant_id = ? ORDER BY due_date, id");
$chargeStmt->execute([$tenantId]);
$charges = $chargeStmt->fetchAll();

$totalCharges = array_sum(array_map(fn($c) => (float)$c['amount'], $charges));
$totalPayments = array_sum(array_map(fn($c) => (float)$c['credited'], $credits));

// Calculated fields for UI
// Rent is per bed: the room price divided by its capacity, however many beds are taken.
$roomPrice = (float)($tenant['price_per_month'] ?? 0);
$roomCapacity = max(1, (int)($tenant['room_capacity'] ?? 1));
$monthlyRent = $tenant['room_id'] ? rentShare($roomPrice, $roomCapacity) : 0.0;
$rentSplitNote = $roomCapacity > 1
    ? '1 of ' . $roomCapacity . ' beds in the ₱' . number_format($roomPrice, 2) . ' room'
    : '';
$currentBalance = round((float)($tenant['balance'] ?? 0), 2);
$depositHeld = tenantDeposit($pdo, $tenant);
$ledgerBalance = round($totalCharges - $totalPayments, 2);
$ledgerMismatch = abs($ledgerBalance - $currentBalance) >= 0.01;

$billing = tenantBillingStatus($tenant, chargesNotYetDue($pdo, $tenantId));
$isDeactivated = $billing['key'] === 'deactivated';
$nextDue = nextDueDate($tenant);
$overdueSince = $billing['overdue'] > 0 ? oldestUnpaidDueDate($pdo, $tenantId, $currentBalance) : null;
$moveInDisplay = $tenant['move_in_date'] ? date('M j, Y', strtotime($tenant['move_in_date'])) : 'Not moved in';

// Ledger rows: charges and payments by date, with a running balance
$ledger = [];
foreach ($charges as $c) {
    $chargeDate = $c['billing_month'] . '-01';
    if ($c['kind'] === 'opening') {
        $chargeDate = $tenant['move_in_date'] ?? $c['due_date'];
    } elseif ($tenant['move_in_date'] && date('Y-m', strtotime($tenant['move_in_date'])) === $c['billing_month']) {
        $chargeDate = $tenant['move_in_date'];
    }
    // A deposit spent on the last month is a credit, so it belongs in the credit column
    // rather than showing as a negative charge.
    $amount = (float)$c['amount'];
    if ($amount < 0) {
        $ledger[] = ['date' => $c['due_date'], 'order' => 1, 'desc' => $c['description'],
                     'charge' => 0, 'payment' => -$amount];
        continue;
    }
    $ledger[] = ['date' => $chargeDate,
                 'order' => 0, 'desc' => $c['description'] . ' (due ' . date('M j, Y', strtotime($c['due_date'])) . ')',
                 'charge' => $amount, 'payment' => 0];
}
foreach ($credits as $c) {
    $label = $c['covered_by_payment_id'] ? htmlspecialchars($c['payment_method'] ?? '') : 'Payment received';
    $ledger[] = ['date' => $c['payment_date'], 'order' => 1,
                 'desc' => $label . ' (RCP-' . str_pad($c['id'], 6, '0', STR_PAD_LEFT) . ')',
                 'charge' => 0, 'payment' => (float)$c['credited']];
}
usort($ledger, fn($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

$docStmt = $pdo->prepare("SELECT * FROM tenant_documents WHERE tenant_id = ? ORDER BY uploaded_at DESC, id DESC");
$docStmt->execute([$tenantId]);
$documents = $docStmt->fetchAll();
$openTab = ($docMessage || $docError) ? 'documents' : 'overview';

// Room history
$histStmt = $pdo->prepare("
    SELECT rt.*, rf.room_number AS from_room, rto.room_number AS to_room
    FROM room_transfers rt
    LEFT JOIN rooms rf ON rf.id = rt.from_room_id
    LEFT JOIN rooms rto ON rto.id = rt.to_room_id
    WHERE rt.tenant_id = ? ORDER BY rt.transferred_at DESC, rt.id DESC
");
$histStmt->execute([$tenantId]);
$roomHistory = $histStmt->fetchAll();

require_once 'header.php';
?>

<style>
    /* Header: who, standing, the four key facts, then the balance strip */
    .profile-card { border-radius: 12px; border: 1px solid #eef2f7; }
    .avatar-wrapper { position: relative; width: 84px; height: 84px; flex: 0 0 84px; }
    /* Fits either an uploaded photo or the initials placeholder to the wrapper. */
    .avatar-wrapper img, .avatar-wrapper .avatar-initials {
        width: 100% !important; height: 100% !important; object-fit: cover; border-radius: 50%;
    }
    .standing-badge { display: inline-block; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; padding: 0.3em 0.9em; border-radius: 999px; }
    .profile-id { min-width: 0; }
    .fact-box { border: 1px solid #eef2f7; border-radius: 10px; padding: 0.75rem 0.85rem; height: 100%; background: #fff; }
    .fact-icon { width: 32px; height: 32px; border-radius: 8px; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-bottom: 0.4rem; }
    .fact-label { font-size: 0.72rem; color: #64748b; }
    .fact-value { font-size: 0.95rem; font-weight: 700; color: #0f172a; line-height: 1.3; }
    .fact-sub { font-size: 0.66rem; color: #64748b; line-height: 1.3; margin-top: 2px; }

    .balance-strip { border-radius: 10px; padding: 0.85rem 1.1rem; display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; border: 1px solid transparent; }
    .strip-icon { width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.95rem; flex-shrink: 0; }
    .strip-label { font-size: 0.75rem; font-weight: 600; }
    .strip-amount { font-size: 1.35rem; font-weight: 800; line-height: 1.2; }
    .strip-divider { width: 1px; align-self: stretch; background: currentColor; opacity: 0.2; }
    .strip-detail { font-size: 0.82rem; }
    .strip-danger  { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .strip-danger .strip-icon  { background: #dc2626; color: #fff; }
    .strip-info    { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
    .strip-info .strip-icon    { background: #2563eb; color: #fff; }
    .strip-success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .strip-success .strip-icon { background: #16a34a; color: #fff; }
    .strip-muted   { background: #f8fafc; border-color: #e2e8f0; color: #475569; }
    .strip-muted .strip-icon   { background: #64748b; color: #fff; }
    @media (max-width: 575.98px) {
        .balance-strip { gap: 0.75rem; }
        .strip-divider { display: none; }
        .strip-detail { flex-basis: 100%; }
    }

    .breadcrumb-sm { font-size: 0.75rem; }
    .breadcrumb-sm a { color: #0d6efd; text-decoration: none; }
    .breadcrumb-sm a:hover { text-decoration: underline; }

    /* Tabs: underlined, scroll sideways on phones */
    .tenant-tabs { border-bottom: 1px solid #e2e8f0; flex-wrap: nowrap; overflow-x: auto; gap: 0.25rem; scrollbar-width: none; }
    .tenant-tabs .nav-link { border: 0; border-bottom: 2px solid transparent; border-radius: 0; color: #64748b; font-size: 0.8rem; font-weight: 600; padding: 0.7rem 0.9rem; white-space: nowrap; background: none; }
    .tenant-tabs .nav-link:hover { color: #1e293b; }
    .tenant-tabs .nav-link.active { color: #2563eb; border-bottom-color: #2563eb; }

    /* Content blocks */
    .section-card { border-radius: 10px; border: 1px solid #eef2f7; background: white; margin-bottom: 1rem; }
    .section-header { padding: 0.85rem 1rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
    .section-title { font-size: 0.85rem; font-weight: 700; color: #1e293b; margin: 0; }

    .info-grid { padding: 1rem; display: grid; grid-template-columns: 150px 1fr; row-gap: 0.7rem; column-gap: 1rem; font-size: 0.8rem; }
    .info-label { color: #64748b; }
    .info-value { color: #1e293b; font-weight: 500; min-width: 0; }
    @media (max-width: 575.98px) { .info-grid { grid-template-columns: 1fr; row-gap: 0.15rem; } .info-value { margin-bottom: 0.5rem; } }

    .mini-table>thead>tr>th { font-size: 0.68rem; padding: 0.6rem 0.75rem; text-transform: none; letter-spacing: 0; background: #f8fafc; }
    .mini-table>tbody>tr>td { font-size: 0.78rem; padding: 0.6rem 0.75rem; }
    .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 0.5rem; }
    .soft-pill { display: inline-block; font-size: 0.68rem; font-weight: 600; padding: 0.2em 0.7em; border-radius: 999px; white-space: nowrap; }

    .empty-state { text-align: center; padding: 1.75rem 1rem; font-size: 0.78rem; color: #64748b; }
    .empty-state > span { width: 52px; height: 52px; border-radius: 50%; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 0.6rem; }

    /* Receipt cards */
    .receipt-scroll { display: flex; gap: 0.75rem; padding: 1rem; overflow-x: auto; }
    .receipt-card { min-width: 150px; border: 1px solid #eef2f7; border-radius: 8px; padding: 0.75rem; background: white; display: flex; flex-direction: column; }
    .receipt-thumb { height: 56px; background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 4px; margin: 0.4rem 0; display: flex; align-items: center; justify-content: center; }

    /* Balance summary */
    .balance-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; padding: 0.6rem 0; border-bottom: 1px solid #f1f5f9; }
    .balance-row > span:last-child { font-size: 0.95rem; color: #0f172a; }
    .balance-row > span.text-danger:last-child { color: #dc2626 !important; }
    .balance-total { display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; font-weight: 700; border-radius: 8px; padding: 0.75rem 0.85rem; margin-top: 0.75rem; }
    .method-box { display: flex; align-items: center; gap: 0.75rem; border: 1px solid #eef2f7; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.82rem; }
    .method-icon { width: 34px; height: 34px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; }

    /* Documents */
    .doc-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.8rem; }
    .doc-row:last-child { border-bottom: 0; }
    .doc-icon { width: 38px; height: 38px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
</style>

<div class="breadcrumb-sm text-muted mb-3">
    <a href="dashboard.php">Dashboard</a> &rsaquo; <a href="tenants.php">Tenants</a> &rsaquo; <?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?>
</div>

<?php
// The tenant's standing, as the header badge and the balance strip show it.
$upcomingDue = upcomingDueDate($pdo, $tenantId);
[$standingLabel, $standingColor] = match ($billing['key']) {
    'overdue'     => ['OVERDUE', 'danger'],
    'due'         => ['NOT YET DUE', 'primary'],
    'deactivated' => ['MOVED OUT', 'secondary'],
    default       => ['PAID', 'success'],
};
?>

<!-- Top Profile Card -->
<div class="card profile-card shadow-sm mb-3 bg-white">
    <div class="card-body p-3 p-md-4">
        <div class="row g-3 align-items-center">
            <div class="col-xl-4 d-flex align-items-center gap-3 profile-id">
                <div class="avatar-wrapper"><?= avatarHtml($tenant['first_name'] . ' ' . $tenant['last_name'], 84, '', $tenant['profile_picture'] ?? null, '../') ?></div>
                <div style="min-width: 0;">
                    <h4 class="fw-bold mb-1 text-dark text-truncate"><?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?></h4>
                    <div class="text-muted mb-2" style="font-size: 0.85rem;"><i class="fa-solid fa-phone me-2"></i><?= htmlspecialchars($tenant['contact_number'] ?: 'No number') ?></div>
                    <span class="standing-badge bg-<?= $standingColor ?>-subtle text-<?= $standingColor ?>-emphasis"><?= $standingLabel ?></span>
                </div>
            </div>
            <div class="col-xl-8">
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <div class="fact-box">
                            <span class="fact-icon"><i class="fa-solid fa-bed"></i></span>
                            <div class="fact-label"><?= $isDeactivated ? 'Room' : 'Assigned Room' ?></div>
                            <div class="fact-value"><?= $isDeactivated ? 'Moved out' : ($tenant['room_number'] ? 'Room ' . htmlspecialchars($tenant['room_number']) : 'Unassigned') ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="fact-box">
                            <span class="fact-icon"><i class="fa-regular fa-calendar"></i></span>
                            <div class="fact-label"><?= $isDeactivated && $tenant['deactivated_at'] ? 'Moved Out' : 'Move-in Date' ?></div>
                            <div class="fact-value"><?= $isDeactivated && $tenant['deactivated_at'] ? date('M j, Y', strtotime($tenant['deactivated_at'])) : $moveInDisplay ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="fact-box">
                            <span class="fact-icon"><i class="fa-solid fa-circle-dollar-to-slot"></i></span>
                            <div class="fact-label">Monthly Rent</div>
                            <div class="fact-value">₱<?= number_format($monthlyRent, 2) ?></div>
                            <?php if ($rentSplitNote): ?><div class="fact-sub"><?= $rentSplitNote ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="fact-box">
                            <span class="fact-icon"><i class="fa-solid fa-shield-halved"></i></span>
                            <div class="fact-label">Deposit</div>
                            <div class="fact-value">₱<?= number_format($depositHeld, 2) ?></div>
                            <div class="fact-sub">For the last month</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance strip: red when something is overdue, blue while the next bill isn't due yet -->
        <?php
        $stripClass = match ($billing['key']) {
            'overdue' => 'strip-danger', 'due' => 'strip-info', 'deactivated' => 'strip-muted', default => 'strip-success',
        };
        ?>
        <div class="balance-strip <?= $stripClass ?> mt-3">
            <div class="d-flex align-items-center gap-3">
                <span class="strip-icon"><i class="fa-solid <?= $billing['key'] === 'overdue' ? 'fa-exclamation' : 'fa-wallet' ?>"></i></span>
                <div>
                    <div class="strip-label">Current Balance</div>
                    <div class="strip-amount">₱<?= number_format(max(0, $currentBalance), 2) ?></div>
                </div>
            </div>
            <div class="strip-divider"></div>
            <div class="d-flex align-items-center gap-2 strip-detail">
                <?php if ($billing['key'] === 'overdue'): ?>
                    <span><strong>Overdue: ₱<?= number_format($billing['overdue'], 2) ?></strong><?= $overdueSince ? ' since ' . date('M j, Y', strtotime($overdueSince)) : '' ?></span>
                <?php elseif ($billing['key'] === 'due'): ?>
                    <i class="fa-regular fa-calendar"></i>
                    <span>Next payment due:<br><strong><?= $upcomingDue ? date('M j, Y', strtotime($upcomingDue)) : '—' ?></strong></span>
                <?php elseif ($billing['key'] === 'deactivated'): ?>
                    <span><?= $currentBalance > 0 ? 'Still owes <strong>₱' . number_format($currentBalance, 2) . '</strong>' : ($currentBalance < 0 ? '<strong>₱' . number_format(-$currentBalance, 2) . '</strong> to refund' : 'Settled') ?></span>
                <?php else: ?>
                    <i class="fa-solid fa-circle-check"></i>
                    <span><strong>Fully paid</strong><?= $currentBalance < 0 ? ' · ₱' . number_format(-$currentBalance, 2) . ' credit for the next bill' : '' ?><?= $nextDue ? '<br>Next bill due ' . date('M j, Y', strtotime($nextDue)) : '' ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav tenant-tabs mb-3" id="tenantTabs" role="tablist">
    <?php foreach ([['overview', 'fa-regular fa-user', 'Overview'], ['transactions', 'fa-regular fa-file-lines', 'Transactions'],
                    ['payments', 'fa-solid fa-wallet', 'Payments & Receipts'], ['ledger', 'fa-solid fa-book-open', 'Ledger'],
                    ['documents', 'fa-regular fa-file', 'Documents' . ($documents ? ' (' . count($documents) . ')' : '')]] as [$id, $icon, $label]): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $openTab === $id ? 'active' : '' ?>" id="<?= $id ?>-tab" data-bs-toggle="tab" data-bs-target="#<?= $id ?>" type="button" role="tab"><i class="<?= $icon ?> me-2"></i><?= $label ?></button>
    </li>
    <?php endforeach; ?>
</ul>

<!-- Main Content Grid -->
<div class="tab-content" id="tenantTabsContent">

    <!-- OVERVIEW TAB -->
    <div class="tab-pane fade <?= $openTab === 'overview' ? 'show active' : '' ?>" id="overview" role="tabpanel" aria-labelledby="overview-tab">
        <div class="row g-3">

    <!-- Left: who they are, what they paid -->
    <div class="col-lg-7">

        <!-- Tenant Information -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-regular fa-user text-primary me-2"></i>Tenant Information</h6>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.7rem;" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-pen me-1"></i>Edit</button>
            </div>
            <?php $na = '<span class="text-black-50 fst-italic fw-normal">Not provided</span>'; $age = tenantAge($tenant['date_of_birth'] ?? null); $emergency = tenantEmergencyText($tenant); ?>
            <div class="info-grid">
                <span class="info-label">Full Name</span><span class="info-value"><?= htmlspecialchars(trim($tenant['first_name'] . ' ' . ($tenant['middle_name'] ?? '') . ' ' . $tenant['last_name'])) ?></span>
                <span class="info-label">Contact Number</span><span class="info-value"><?= !empty($tenant['contact_number']) ? htmlspecialchars($tenant['contact_number']) : $na ?></span>
                <span class="info-label">Username</span>
                <span class="info-value d-flex align-items-center gap-2"><span id="usernameText"><?= htmlspecialchars($tenant['username'] ?? '') ?></span>
                    <button type="button" class="btn btn-link p-0 text-muted" title="Copy username" aria-label="Copy username" onclick="navigator.clipboard.writeText(document.getElementById('usernameText').textContent); this.innerHTML='<i class=&quot;fa-solid fa-check text-success&quot;></i>';"><i class="fa-regular fa-copy"></i></button></span>
                <span class="info-label">Occupation</span><span class="info-value"><?= !empty($tenant['occupation']) ? htmlspecialchars($tenant['occupation']) : $na ?></span>
                <span class="info-label">Emergency Contact</span><span class="info-value"><?= $emergency !== '' ? htmlspecialchars($emergency) : $na ?></span>
                <span class="info-label">Email</span><span class="info-value text-break"><?= !empty($tenant['email']) ? htmlspecialchars($tenant['email']) : $na ?></span>
                <span class="info-label">Gender</span><span class="info-value"><?= !empty($tenant['gender']) ? htmlspecialchars($tenant['gender']) : $na ?></span>
                <span class="info-label">Date of Birth</span><span class="info-value"><?= !empty($tenant['date_of_birth']) ? date('M j, Y', strtotime($tenant['date_of_birth'])) . " <span class=\"text-muted fw-normal\">($age years old)</span>" : $na ?></span>
                <span class="info-label">Home Address</span><span class="info-value"><?= !empty($tenant['address']) ? nl2br(htmlspecialchars($tenant['address'])) : $na ?></span>
                <span class="info-label">Temporary Password</span><span class="info-value"><?= !empty($tenant['temp_password']) ? '<span class="badge bg-warning text-dark fw-bold" style="font-family: monospace; font-size:0.75rem;">' . htmlspecialchars($tenant['temp_password'] ?? '') . '</span>' : '<span class="text-success fst-italic fw-semibold" style="font-size:0.75rem;"><i class="fa-solid fa-check"></i> Changed by tenant</span>' ?></span>
            </div>
        </div>

        <?php if ($profileError): ?>
        <script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('editProfileModal')).show());</script>
        <?php endif; ?>
        <!-- Edit tenant details -->
        <div class="modal fade" id="editProfileModal" tabindex="-1" aria-label="Edit tenant details">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit_profile">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <div class="modal-header bg-light border-0">
                            <h6 class="modal-title fw-bold"><i class="fa-solid fa-user-pen text-primary me-2"></i>Edit Tenant Details</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4 form-compact">
                            <?= tenantProfileFormFields($profileError ? $_POST : $tenant, 'td') ?>
                        </div>
                        <div class="modal-footer border-0 bg-light">
                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                            <button type="submit" class="btn btn-primary px-4" style="font-size:0.8rem;">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-arrows-rotate text-primary me-2"></i>Recent Transactions</h6>
                <a href="#transactions" onclick="document.getElementById('transactions-tab').click(); return false;" class="text-primary text-decoration-none" style="font-size:0.75rem; font-weight:600;">View All</a>
            </div>
            <?php if (empty($payments)): ?>
                <div class="empty-state"><span><i class="fa-regular fa-file-lines"></i></span><div class="fw-semibold text-dark">No transactions yet.</div><div>Payments will appear here once the tenant pays.</div></div>
            <?php else: ?>
            <div class="table-responsive px-2 pb-2">
                <table class="table mini-table mb-0">
                    <thead><tr><th>Type</th><th>Date</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($payments, 0, 5) as $p):
                        [$typeText, $typeDot] = match ($p['status']) {
                            'verified' => ['Payment Received', 'success'],
                            'rejected' => ['Payment Rejected', 'danger'],
                            default    => ['Payment Pending', 'warning'],
                        };
                        $desc = ($p['reference_number'] ?? '') === 'MOVE-IN' ? 'Advance + Deposit'
                              : (stripos($p['payment_method'] ?? '', 'covered by') === 0 ? $p['payment_method']
                              : (!empty($p['pay_for_room']) ? 'Room Rent (whole room)' : 'Room Rent'));
                        [$stText, $stColor] = match ($p['status']) {
                            'verified' => ['Paid', 'success'], 'rejected' => ['Rejected', 'danger'], default => ['Pending', 'warning'],
                        };
                    ?>
                        <tr>
                            <td class="text-nowrap"><span class="dot bg-<?= $typeDot ?>"></span><?= $typeText ?></td>
                            <td class="text-nowrap"><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($desc) ?></td>
                            <td class="text-nowrap fw-semibold">₱<?= number_format((float)$p['amount'], 2) ?></td>
                            <td><span class="soft-pill bg-<?= $stColor ?>-subtle text-<?= $stColor ?>-emphasis"><?= $stText ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment & Receipts -->
        <?php $verifiedPayments = array_values(array_filter($payments, fn($p) => $p['status'] === 'verified')); ?>
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-receipt text-primary me-2"></i>Payment & Receipts</h6>
                <a href="#payments" onclick="document.getElementById('payments-tab').click(); return false;" class="text-primary text-decoration-none" style="font-size:0.75rem; font-weight:600;">View All Receipts</a>
            </div>
            <?php if (!$verifiedPayments): ?>
                <div class="empty-state"><span><i class="fa-regular fa-credit-card"></i></span><div class="fw-semibold text-dark">No receipts available yet.</div><div>Receipts will appear here once a payment is made.</div></div>
            <?php else: ?>
            <div class="receipt-scroll">
                <?php foreach (array_slice($verifiedPayments, 0, 6) as $p): ?>
                <div class="receipt-card">
                    <span class="soft-pill bg-success-subtle text-success-emphasis align-self-start mb-1">Paid</span>
                    <div class="receipt-thumb"><i class="fa-solid fa-file-invoice text-primary opacity-50 fs-3"></i></div>
                    <div class="fw-bold text-dark mb-1" style="font-size:0.7rem;">RCP-<?= str_pad($p['id'], 6, '0', STR_PAD_LEFT) ?></div>
                    <div class="text-muted" style="font-size:0.68rem;"><?= date('M j, Y', strtotime($p['payment_date'])) ?></div>
                    <div class="fw-bold text-dark" style="font-size:0.8rem;">₱<?= number_format((float)$p['amount'], 2) ?></div>
                    <?php if (!empty($p['receipt_path'])): ?>
                    <a href="<?= htmlspecialchars(uploadSrc($p['receipt_path'], '../')) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm w-100 mt-2 py-0" style="font-size:0.68rem;">View Receipt</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: money, deposit, notes, actions -->
    <div class="col-lg-5">

        <!-- Balance Summary -->
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-wallet text-primary me-2"></i>Balance Summary</h6></div>
            <div class="p-3">
                <div class="balance-row"><span class="text-muted">Total Charges</span><span class="fw-semibold">₱<?= number_format($totalCharges, 2) ?></span></div>
                <div class="balance-row"><span class="text-muted">Total Payments</span><span class="fw-semibold">₱<?= number_format($totalPayments, 2) ?></span></div>
                <?php if ($billing['key'] === 'overdue'): ?>
                    <div class="balance-row"><span class="text-muted">Overdue</span><span class="fw-bold text-danger">₱<?= number_format($billing['overdue'], 2) ?></span></div>
                    <div class="balance-total bg-danger-subtle text-danger-emphasis"><span>Current Balance</span><span>₱<?= number_format($currentBalance, 2) ?></span></div>
                <?php else: ?>
                    <div class="balance-row"><span class="text-muted">Outstanding Balance</span><span class="fw-semibold">₱<?= number_format(max(0, $currentBalance), 2) ?></span></div>
                    <div class="balance-total bg-<?= $standingColor ?>-subtle text-<?= $standingColor ?>-emphasis">
                        <span><i class="fa-regular fa-calendar me-2"></i>Payment Status</span>
                        <span class="soft-pill bg-white text-<?= $standingColor ?>-emphasis"><?= ucwords(strtolower($standingLabel)) ?></span>
                    </div>
                    <?php if ($currentBalance < 0): ?>
                        <div class="text-success mt-2" style="font-size:0.75rem;">₱<?= number_format(-$currentBalance, 2) ?> <?= $isDeactivated ? 'to refund' : 'credit toward the next bill' ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($ledgerMismatch): ?>
                    <div class="alert alert-warning border-0 py-1 px-2 mt-2 mb-0" style="font-size:0.68rem;">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Ledger total (₱<?= number_format($ledgerBalance, 2) ?>) doesn't match the stored balance. Please review this account.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- How this tenant pays -->
        <?php
        $methodCounts = [];
        foreach ($payments as $p) {
            if ($p['status'] !== 'verified' || !empty($p['covered_by_payment_id'])) continue;
            $label = paymentMethodLabel($p['payment_method'] ?? 'gcash');
            $methodCounts[$label] = ($methodCounts[$label] ?? 0) + 1;
        }
        arsort($methodCounts);
        $usual = $methodCounts ? array_key_first($methodCounts) : null;
        ?>
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-regular fa-credit-card text-primary me-2"></i>Payment Method</h6></div>
            <div class="p-3">
                <?php if ($usual === null): ?>
                    <div class="text-muted" style="font-size:0.8rem;">No verified payments yet.</div>
                <?php else: $isCash = $usual === 'Cash'; ?>
                    <div class="method-box">
                        <span class="method-icon <?= $isCash ? 'bg-success-subtle text-success' : 'bg-primary text-white' ?>"><i class="fa-solid <?= $isCash ? 'fa-money-bill-wave' : 'fa-g' ?>"></i></span>
                        <div class="flex-grow-1 fw-semibold text-dark"><?= $isCash ? 'Cash' : 'GCash (Manual)' ?></div>
                        <span class="soft-pill <?= $isCash ? 'bg-success-subtle text-success-emphasis' : 'bg-primary-subtle text-primary-emphasis' ?>"><?= $methodCounts[$usual] ?> <?= $methodCounts[$usual] === 1 ? 'payment' : 'payments' ?></span>
                    </div>
                    <?php foreach (array_slice($methodCounts, 1, null, true) as $label => $n): ?>
                        <div class="d-flex justify-content-between mt-2" style="font-size:0.78rem;"><span class="text-muted">Also by <?= htmlspecialchars($label === 'GCash' ? 'GCash (Manual)' : $label) ?></span><span><?= $n ?> <?= $n === 1 ? 'payment' : 'payments' ?></span></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <!-- Deposit: held for the last month; the tenant can ask to use it -->
        <?php
        $depositUsed = depositAppliedThisStay($pdo, $tenant);
        $depositUnpaid = $depositUsed ? 0.0 : depositUnpaid($pdo, $tenant);
        $depositPending = pendingDepositRequest($pdo, $tenantId);
        ?>
        <div class="section-card shadow-sm" id="deposit">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-vault text-muted me-2"></i>Deposit</h6>
                <?php if ($depositPending): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Request waiting</span><?php endif; ?>
            </div>
            <div class="p-3" style="font-size:0.75rem;">
                <?php if ($depositHeld <= 0 && !$depositUsed): ?>
                    <span class="text-muted">No deposit on record for this stay.</span>
                <?php elseif ($depositUsed): ?>
                    <div><strong>₱<?= number_format((float)$depositUsed['amount'], 2) ?></strong> used for the rent of <strong><?= date('F Y', strtotime($depositUsed['billing_month'] . '-01')) ?></strong>.</div>
                <?php else: ?>
                    <?php if ($depositUnpaid > 0): ?>
                        <div class="mb-2"><strong>₱<?= number_format($depositHeld, 2) ?></strong> deposit, <span class="text-danger fw-semibold">not paid yet</span> (₱<?= number_format($depositUnpaid, 2) ?> still due). It can't be used for the last month until it is paid.</div>
                    <?php else: ?>
                        <div class="mb-2"><strong>₱<?= number_format($depositHeld, 2) ?></strong> held for the last month of stay.</div>
                    <?php endif; ?>
                    <?php if ($depositPending): ?>
                        <div class="alert alert-warning py-2 px-2 mb-2" style="font-size:0.72rem;">
                            The tenant asked to use it for <strong><?= date('F Y', strtotime($depositPending['billing_month'] . '-01')) ?></strong>
                            (<?= date('M j, g:i A', strtotime($depositPending['created_at'])) ?>).
                        </div>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action" value="deposit_decide">
                            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                            <input type="hidden" name="request_id" value="<?= (int)$depositPending['id'] ?>">
                            <input type="text" name="note" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Note to the tenant if declining (optional)">
                            <div class="d-flex gap-2">
                                <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success flex-grow-1"<?= $depositUnpaid > 0 ? ' disabled title="The deposit has not been paid yet"' : '' ?> onclick="return confirm('Apply the ₱<?= number_format($depositHeld, 2) ?> deposit to <?= date('F Y', strtotime($depositPending['billing_month'] . '-01')) ?>?');"><i class="fa-solid fa-check me-1"></i>Approve</button>
                                <button type="submit" name="decision" value="decline" class="btn btn-sm btn-outline-danger flex-grow-1"><i class="fa-solid fa-xmark me-1"></i>Decline</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <span class="text-muted">The tenant can ask to use it for their last month from their portal. If they move out without asking, it is applied automatically.</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Room History -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-clock-rotate-left text-muted me-2"></i>Room History</h6>
            </div>
            <div class="p-3" style="font-size:0.68rem;">
                <?php if (empty($roomHistory)): ?>
                    <span class="text-muted">No room changes recorded.</span>
                <?php else: foreach ($roomHistory as $h): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>
                            <span class="fw-semibold text-dark"><?= htmlspecialchars($h['note'] ?? '') ?></span><br>
                            <span class="text-muted"><?= $h['from_room'] ? 'Room ' . htmlspecialchars($h['from_room'] ?? '') : '—' ?> → <?= $h['to_room'] ? 'Room ' . htmlspecialchars($h['to_room'] ?? '') : '—' ?></span>
                        </span>
                        <span class="text-muted text-nowrap ms-2"><?= date('M j, Y', strtotime($h['transferred_at'])) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        
        <!-- Notes: the admin's own, not shown to the tenant -->
        <div class="section-card shadow-sm" id="notes">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-regular fa-note-sticky text-muted me-2"></i>Notes</h6>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.6rem;" onclick="document.getElementById('notesView').classList.add('d-none'); document.getElementById('notesForm').classList.remove('d-none'); document.getElementById('notesText').focus();"><?= trim((string)($tenant['admin_notes'] ?? '')) !== '' ? 'Edit' : 'Add Note' ?></button>
            </div>
            <div class="p-3" style="font-size:0.7rem;">
                <div id="notesView" class="<?= trim((string)($tenant['admin_notes'] ?? '')) !== '' ? 'text-dark' : 'text-muted' ?>">
                    <?= trim((string)($tenant['admin_notes'] ?? '')) !== '' ? nl2br(htmlspecialchars($tenant['admin_notes'])) : 'No notes yet. Only you can see notes.' ?>
                </div>
                <form method="POST" id="notesForm" class="d-none m-0">
                    <input type="hidden" name="action" value="save_notes">
                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                    <textarea name="admin_notes" id="notesText" class="form-control form-control-sm mb-2" rows="4" maxlength="5000" placeholder="e.g., Pays on the 5th every month; parents in Davao."><?= htmlspecialchars($tenant['admin_notes'] ?? '') ?></textarea>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-sm btn-light border" onclick="document.getElementById('notesForm').classList.add('d-none'); document.getElementById('notesView').classList.remove('d-none');">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">Save notes</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-user-gear text-muted me-2"></i>Actions</h6></div>
            <div class="p-3 d-flex flex-column gap-2">
                <?php if ($reminderSuccess): ?>
                <div class="alert alert-success py-1 px-2 mb-1" style="font-size:0.7rem;"><?= htmlspecialchars($reminderSuccess) ?></div>
                <?php elseif ($reminderError): ?>
                <div class="alert alert-danger py-1 px-2 mb-1" style="font-size:0.7rem;"><?= htmlspecialchars($reminderError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="send_reminder">
                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                    <button type="submit" class="btn btn-outline-primary btn-sm text-start w-100" style="font-size:0.7rem;"
                        <?= (!smsConfigured($pdo) || (float)($tenant['balance'] ?? 0) <= 0 || $tenant['status'] !== 'active') ? 'disabled title="' . (!smsConfigured($pdo) ? 'SMS not set up' : ((float)($tenant['balance'] ?? 0) <= 0 ? 'No outstanding balance' : 'Tenant is not active')) . '"' : '' ?>>
                        <i class="fa-regular fa-envelope me-2"></i>Send Reminder
                    </button>
                </form>
                <form method="POST" onsubmit="return confirm('Give this tenant a new temporary password? Their current password stops working.');">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm text-start w-100" style="font-size:0.7rem;">
                        <i class="fa-solid fa-key me-2"></i>Reset Password
                    </button>
                </form>
            </div>
        </div>
    </div> <!-- Close Right Column -->
    </div> <!-- Close Overview Tab Row -->
    </div> <!-- Close Overview Tab Pane -->

    <!-- TRANSACTIONS TAB -->
    <div class="tab-pane fade" id="transactions" role="tabpanel" aria-labelledby="transactions-tab">
        <div class="section-card shadow-sm p-4">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-arrow-right-arrow-left text-muted me-2"></i>All Transactions</h6>
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-0">Date</th>
                            <th class="border-0">Reference No.</th>
                            <th class="border-0">Method</th>
                            <th class="border-0">Amount</th>
                            <th class="border-0">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($payments)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted" style="font-size:0.75rem;">No transactions recorded.</td></tr>
                        <?php else: foreach($payments as $p): ?>
                            <tr>
                                <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                                <td class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($p['reference_number'] ?? '') ?></td>
                                <td class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars(ucfirst($p['payment_method'])) ?></td>
                                <td class="fw-bold text-dark" style="font-size:0.7rem;">PHP <?= number_format($p['amount'], 2) ?></td>
                                <td>
                                    <?php if($p['status'] == 'verified'): ?>
                                        <span class="badge bg-success-subtle text-success">Verified</span>
                                    <?php elseif($p['status'] == 'rejected'): ?>
                                        <span class="badge bg-danger-subtle text-danger">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PAYMENTS & RECEIPTS TAB -->
    <div class="tab-pane fade" id="payments" role="tabpanel" aria-labelledby="payments-tab">
        <div class="section-card shadow-sm p-4">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-box-archive text-muted me-2"></i>Official Receipts</h6>
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-0">Date</th>
                            <th class="border-0">Receipt No.</th>
                            <th class="border-0">Amount</th>
                            <th class="border-0 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $verifiedPayments = array_filter($payments, fn($p) => $p['status'] === 'verified');
                        if(empty($verifiedPayments)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted" style="font-size:0.75rem;">No official receipts available yet.</td></tr>
                        <?php else: foreach($verifiedPayments as $vp): ?>
                            <tr>
                                <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M d, Y', strtotime($vp['payment_date'])) ?></td>
                                <td class="text-muted" style="font-size:0.7rem;">RCP-<?= str_pad($vp['id'], 6, '0', STR_PAD_LEFT) ?></td>
                                <td class="fw-bold text-dark" style="font-size:0.7rem;">PHP <?= number_format($vp['amount'], 2) ?></td>
                                <td class="text-end">
                                    <?php if (!empty($vp['receipt_path'])): ?>
                                    <a href="<?= htmlspecialchars(uploadSrc($vp['receipt_path'], '../')) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.65rem;"><i class="fa-solid fa-download me-1"></i> Download</a>
                                    <?php else: ?>
                                    <span class="text-muted" style="font-size:0.65rem;">No file</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- LEDGER TAB -->
    <div class="tab-pane fade" id="ledger" role="tabpanel" aria-labelledby="ledger-tab">
        <div class="section-card shadow-sm p-4">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-book text-muted me-2"></i>Account Ledger</h6>
            <div class="alert alert-info border-0 py-2 mb-3" style="font-size:0.75rem;">
                <i class="fa-solid fa-circle-info me-1"></i> Rent is charged on the 1st and due on the 30th. First month: full rent if moved in on day 1–15, half if day 16 or later.
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-0">Date</th>
                            <th class="border-0">Description</th>
                            <th class="border-0 text-end">Charge</th>
                            <th class="border-0 text-end">Payment</th>
                            <th class="border-0 text-end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledger)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted" style="font-size:0.75rem;">No charges or payments yet.</td></tr>
                        <?php endif; ?>
                        <?php $running = 0; foreach ($ledger as $row): $running += $row['charge'] - $row['payment']; ?>
                        <tr>
                            <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M d, Y', strtotime($row['date'])) ?></td>
                            <td class="text-muted" style="font-size:0.7rem;"><?= $row['desc'] ?></td>
                            <td class="text-end <?= $row['charge'] ? 'fw-bold text-danger' : 'text-muted' ?>" style="font-size:0.7rem;"><?= $row['charge'] ? 'PHP ' . number_format($row['charge'], 2) : '-' ?></td>
                            <td class="text-end <?= $row['payment'] ? 'fw-bold text-success' : 'text-muted' ?>" style="font-size:0.7rem;"><?= $row['payment'] ? 'PHP ' . number_format($row['payment'], 2) : '-' ?></td>
                            <td class="text-end fw-semibold <?= $running < 0 ? 'text-success' : 'text-dark' ?>" style="font-size:0.7rem;">PHP <?= number_format(abs($running), 2) ?><?= $running < 0 ? ' CR' : '' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DOCUMENTS TAB -->
    <div class="tab-pane fade <?= $openTab === 'documents' ? 'show active' : '' ?>" id="documents" role="tabpanel" aria-labelledby="documents-tab">
        <div class="row g-3">
            <div class="col-lg-5 order-lg-2">
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-upload text-primary me-2"></i>Add a Document</h6></div>
                    <form method="POST" enctype="multipart/form-data" class="p-3 form-compact">
                        <input type="hidden" name="action" value="upload_document">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <?php if ($docError): ?><div class="alert alert-danger py-2 px-3" style="font-size:0.78rem;"><?= htmlspecialchars($docError) ?></div><?php endif; ?>
                        <?php if ($docMessage): ?><div class="alert alert-success py-2 px-3" style="font-size:0.78rem;"><?= htmlspecialchars($docMessage) ?></div><?php endif; ?>
                        <div class="mb-2">
                            <label for="docTitle">Name</label>
                            <input type="text" id="docTitle" name="title" class="form-control" maxlength="120" required placeholder="e.g. Valid ID, Lease contract" value="<?= $docError ? htmlspecialchars($_POST['title'] ?? '') : '' ?>">
                        </div>
                        <div class="mb-3">
                            <label for="docFile">File</label>
                            <input type="file" id="docFile" name="document" class="form-control" required accept="image/jpeg,image/png,image/webp,application/pdf">
                            <div class="form-text" style="font-size:0.7rem;">Photo (JPG, PNG, WEBP) or PDF, up to 5MB. The tenant doesn't see these here.</div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-upload me-1"></i>Upload</button>
                    </form>
                </div>
            </div>
            <div class="col-lg-7 order-lg-1">
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-regular fa-folder-open text-primary me-2"></i>Documents</h6></div>
                    <?php if (!$documents): ?>
                        <div class="empty-state"><span><i class="fa-regular fa-file"></i></span><div class="fw-semibold text-dark">No documents yet.</div><div>Keep a copy of the tenant's ID or signed contract here.</div></div>
                    <?php else: foreach ($documents as $d): $isPdf = $d['file_type'] === 'pdf'; ?>
                        <div class="doc-row">
                            <span class="doc-icon <?= $isPdf ? 'bg-danger-subtle text-danger' : 'bg-primary-subtle text-primary' ?>"><i class="fa-regular <?= $isPdf ? 'fa-file-pdf' : 'fa-file-image' ?>"></i></span>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="fw-semibold text-dark text-truncate"><?= htmlspecialchars($d['title']) ?></div>
                                <div class="text-muted" style="font-size:0.7rem;"><?= strtoupper(htmlspecialchars($d['file_type'])) ?><?= $d['uploaded_at'] ? ' · Added ' . date('M j, Y', strtotime($d['uploaded_at'])) : '' ?></div>
                            </div>
                            <a href="<?= htmlspecialchars(uploadSrc($d['file_path'], '../')) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.7rem;">View</a>
                            <form method="POST" class="m-0" onsubmit="return confirm('Delete this document?');">
                                <input type="hidden" name="action" value="delete_document">
                                <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.7rem;" aria-label="Delete <?= htmlspecialchars($d['title']) ?>"><i class="fa-regular fa-trash-can"></i></button>
                            </form>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

</div> <!-- Close Tab Content -->

<?php require_once 'footer.php'; ?>
