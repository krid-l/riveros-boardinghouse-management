<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
requireAdmin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Tenant ID.");
}

$tenantId = (int)$_GET['id'];

// Fetch Tenant & Room details
$stmt = $pdo->prepare("
    SELECT t.*, u.username, u.temp_password, r.room_number, r.price_per_month, u.created_at as account_created
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
// rooms.price_per_month is the price of the whole room; this tenant pays an equal share of it.
$roomPrice = (float)($tenant['price_per_month'] ?? 0);
$roomOccupants = $tenant['room_id'] ? roomOccupantCount($pdo, (int)$tenant['room_id']) : 0;
$monthlyRent = $tenant['room_id'] ? rentShare($roomPrice, $roomOccupants) : 0.0;
$rentSplitNote = $roomOccupants > 1
    ? 'Share of the ₱' . number_format($roomPrice, 2) . ' room, split ' . $roomOccupants . ' ways'
    : '';
$currentBalance = round((float)($tenant['balance'] ?? 0), 2);
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
    $ledger[] = ['date' => $chargeDate,
                 'order' => 0, 'desc' => $c['description'] . ' (due ' . date('M j, Y', strtotime($c['due_date'])) . ')',
                 'charge' => (float)$c['amount'], 'payment' => 0];
}
foreach ($credits as $c) {
    $label = $c['covered_by_payment_id'] ? htmlspecialchars($c['payment_method']) : 'Payment received';
    $ledger[] = ['date' => $c['payment_date'], 'order' => 1,
                 'desc' => $label . ' (RCP-' . str_pad($c['id'], 6, '0', STR_PAD_LEFT) . ')',
                 'charge' => 0, 'payment' => (float)$c['credited']];
}
usort($ledger, fn($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

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
    body { background-color: #f8fafc; }
    
    /* Top Profile Card */
    .profile-card { border-radius: 12px; border: 1px solid #f1f5f9; }
    .avatar-wrapper { position: relative; width: 80px; height: 80px; flex: 0 0 80px; }
    /* Fits either an uploaded photo or the initials placeholder to the wrapper. */
    .avatar-wrapper img, .avatar-wrapper .avatar-initials {
        width: 100% !important; height: 100% !important; object-fit: cover; border-radius: 50%;
    }
    .status-badge-overlap {
        position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%);
        font-size: 0.6rem; padding: 2px 10px; border: 2px solid white;
    }
    
    .breadcrumb-sm { font-size: 0.75rem; }
    .breadcrumb-sm a { color: #0d6efd; text-decoration: none; }
    .breadcrumb-sm a:hover { text-decoration: underline; }
    
    .tab-nav { border-bottom: 1px solid #e2e8f0; display: flex; gap: 2rem; margin-bottom: 1.5rem; }
    .tab-item { 
        padding: 0.75rem 0; font-size: 0.75rem; font-weight: 600; color: #64748b; 
        cursor: pointer; border-bottom: 2px solid transparent; 
    }
    .tab-item.active { color: #0d6efd; border-bottom: 2px solid #0d6efd; }
    
    /* Content Blocks */
    .section-card { border-radius: 10px; border: 1px solid #f1f5f9; background: white; margin-bottom: 1rem; }
    .section-header { padding: 1rem; border-bottom: 1px solid #f8fafc; display: flex; justify-content: space-between; align-items: center; }
    .section-title { font-size: 0.8rem; font-weight: 700; color: #1e293b; margin: 0; }
    
    .info-grid { padding: 1rem; display: grid; grid-template-columns: 120px 1fr; row-gap: 0.75rem; font-size: 0.7rem; }
    .info-label { color: #64748b; }
    .info-value { color: #1e293b; font-weight: 500; }
    
    /* Transactions */
    .tx-item { padding: 0.75rem 1rem; border-bottom: 1px solid #f8fafc; display: flex; justify-content: space-between; align-items: center; }
    .tx-item:last-child { border-bottom: none; }
    .tx-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; }
    
    /* Receipt Cards */
    .receipt-scroll { display: flex; gap: 1rem; padding: 1rem; overflow-x: auto; }
    .receipt-card { 
        min-width: 150px; border: 1px solid #f1f5f9; border-radius: 8px; padding: 0.75rem;
        background: white; display: flex; flex-direction: column;
    }
    .receipt-thumb { 
        height: 60px; background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 4px;
        margin: 0.5rem 0; display: flex; align-items: center; justify-content: center; color: #94a3b8;
    }
    
    /* Balance Summary */
    .balance-row { display: flex; justify-content: space-between; font-size: 0.7rem; margin-bottom: 0.5rem; }
    
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-1 text-dark">Tenant Details</h4>
        <div class="breadcrumb-sm text-muted">
            <a href="dashboard.php">Dashboard</a> &rsaquo; <a href="tenants.php">Tenants</a> &rsaquo; <?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?>
        </div>
    </div>
</div>

<!-- Top Profile Card -->
<div class="card profile-card shadow-sm mb-4 bg-white">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <!-- Left: Avatar & Contact -->
            <div class="col-md-4 d-flex align-items-center border-end border-light">
                <div class="avatar-wrapper me-3">
                    <?= avatarHtml($tenant['first_name'] . ' ' . $tenant['last_name'], 80, '', $tenant['profile_picture'] ?? null, '../') ?>
                    <span class="badge bg-<?= $billing['color'] ?>-subtle text-<?= $billing['color'] ?> rounded-pill status-badge-overlap"><?= $billing['label'] ?></span>
                </div>
                <div>
                    <h5 class="fw-bold mb-2 text-dark"><?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?></h5>
                    <div class="d-flex flex-column gap-1 text-muted" style="font-size: 0.7rem;">
                        <span><i class="fa-solid fa-phone me-2"></i><?= htmlspecialchars($tenant['contact_number'] ?: 'N/A') ?></span>
                        <span><i class="fa-solid fa-user me-2"></i><?= htmlspecialchars($tenant['username']) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Middle: Room Info -->
            <div class="col-md-5 border-end border-light px-4">
                <div class="d-flex flex-column gap-2" style="font-size: 0.75rem;">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Room</span>
                        <span class="fw-bold text-dark"><?= $isDeactivated ? 'Moved out' : ($tenant['room_number'] ? 'Room ' . htmlspecialchars($tenant['room_number']) : 'Unassigned') ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Move-in Date</span>
                        <span class="fw-bold text-dark"><i class="fa-regular fa-calendar me-1"></i><?= $moveInDisplay ?></span>
                    </div>
                    <?php if ($isDeactivated && $tenant['deactivated_at']): ?>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Move-out Date</span>
                        <span class="fw-bold text-dark"><i class="fa-regular fa-calendar-xmark me-1"></i><?= date('M j, Y', strtotime($tenant['deactivated_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Monthly Rent</span>
                        <span class="fw-bold text-dark text-end">₱<?= number_format($monthlyRent, 2) ?>
                            <?php if ($rentSplitNote): ?><br><span class="text-muted fw-normal" style="font-size:0.7rem;"><?= $rentSplitNote ?></span><?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Right: Balance -->
            <div class="col-md-3 px-4 text-center text-md-end">
                <div class="text-muted mb-1" style="font-size: 0.75rem;">Current Balance</div>
                <?php if ($currentBalance <= 0): ?>
                    <h3 class="fw-bold text-success mb-1">₱<?= number_format(0, 2) ?></h3>
                    <div class="text-success fw-bold" style="font-size: 0.7rem;"><i class="fa-solid fa-check me-1"></i>Fully Paid<?= $currentBalance < 0 ? ' · ₱' . number_format(-$currentBalance, 2) . ' credit' : '' ?></div>
                <?php else: ?>
                    <h3 class="fw-bold text-danger mb-1">₱<?= number_format($currentBalance, 2) ?></h3>
                    <?php if ($billing['overdue'] > 0): ?>
                        <div class="text-danger fw-bold" style="font-size: 0.7rem;"><i class="fa-solid fa-triangle-exclamation me-1"></i>₱<?= number_format($billing['overdue'], 2) ?> overdue<?= $overdueSince ? ' since ' . date('M j, Y', strtotime($overdueSince)) : '' ?></div>
                    <?php elseif ($nextDue): ?>
                        <div class="text-warning fw-semibold" style="font-size: 0.7rem;"><i class="fa-regular fa-clock me-1"></i>Due <?= date('M j, Y', strtotime($nextDue)) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs px-2 mb-3 border-0" id="tenantTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active custom-tab me-2" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab"><i class="fa-solid fa-users text-primary me-2"></i>Overview</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link custom-tab me-2" id="transactions-tab" data-bs-toggle="tab" data-bs-target="#transactions" type="button" role="tab"><i class="fa-solid fa-arrow-right-arrow-left me-2"></i>Transactions</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link custom-tab me-2" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button" role="tab"><i class="fa-solid fa-box-archive me-2"></i>Payments & Receipts</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link custom-tab me-2" id="ledger-tab" data-bs-toggle="tab" data-bs-target="#ledger" type="button" role="tab"><i class="fa-solid fa-book me-2"></i>Ledger</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link custom-tab" id="documents-tab" data-bs-toggle="tab" data-bs-target="#documents" type="button" role="tab"><i class="fa-regular fa-file-lines me-2"></i>Documents</button>
    </li>
</ul>

<style>
/* Style for custom tabs */
.custom-tab {
    background-color: transparent !important;
    border: 0 !important;
    color: #64748b !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    padding: 0.5rem 1rem !important;
    border-radius: 0.5rem !important;
    transition: all 0.2s ease;
}
.custom-tab.active {
    background-color: #ffffff !important;
    color: #1e293b !important;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
}
.custom-tab:hover:not(.active) {
    color: #0d6efd !important;
}
</style>

<!-- Main Content Grid -->
<div class="tab-content" id="tenantTabsContent">
    
    <!-- OVERVIEW TAB -->
    <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
        <div class="row g-3">
    
    <!-- Left Column (30%) -->
    <div class="col-lg-3">
        
        <!-- Tenant Information -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-house-user text-muted me-2"></i>Tenant Information</h6>
                <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.65rem;">Edit</button>
            </div>
            <div class="info-grid">
                <span class="info-label">Full Name</span><span class="info-value"><?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?></span>
                <span class="info-label">Contact Number</span><span class="info-value"><?= htmlspecialchars($tenant['contact_number']) ?></span>
                <span class="info-label">Username</span><span class="info-value"><?= htmlspecialchars($tenant['username']) ?></span>
                  <span class="info-label">Temporary Password</span><span class="info-value"><?= !empty($tenant['temp_password']) ? '<span class="badge bg-warning text-dark fw-bold" style="font-family: monospace; font-size:0.7rem;">' . htmlspecialchars($tenant['temp_password']) . '</span>' : '<span class="text-success fst-italic fw-semibold" style="font-size:0.7rem;"><i class="fa-solid fa-check"></i> Changed by tenant</span>' ?></span>
                <span class="info-label">Occupation</span><span class="info-value"><?= !empty($tenant['occupation']) ? htmlspecialchars($tenant['occupation']) : '<span class="text-black-50 fst-italic">Not provided</span>' ?></span>
                <span class="info-label">Emergency Contact</span><span class="info-value"><?= !empty($tenant['emergency_contact']) ? htmlspecialchars($tenant['emergency_contact']) : '<span class="text-black-50 fst-italic">Not provided</span>' ?></span>
            </div>
        </div>
        
        <!-- Room Information -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-building-user text-muted me-2"></i>Room Information</h6>
            </div>
            <div class="info-grid">
                <span class="info-label">Room Number</span><span class="info-value"><?= $tenant['room_number'] ? 'Room ' . htmlspecialchars($tenant['room_number']) : 'N/A' ?></span>
                <span class="info-label">Room Type</span><span class="info-value">Standard</span>
                <span class="info-label">Monthly Rent</span><span class="info-value">₱<?= number_format($monthlyRent, 2) ?><?= $rentSplitNote ? ' <span class="text-muted fw-normal" style="font-size:0.7rem;">(' . $rentSplitNote . ')</span>' : '' ?></span>
                <span class="info-label">Rent Due</span><span class="info-value">Every 30th of the month</span>
                <span class="info-label">Status</span>
                <span class="info-value">
                    <?php if ($isDeactivated): ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Deactivated</span>
                    <?php elseif ($tenant['room_id']): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Occupied</span>
                    <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">No room</span>
                    <?php endif; ?>
                </span>
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
                            <span class="fw-semibold text-dark"><?= htmlspecialchars($h['note']) ?></span><br>
                            <span class="text-muted"><?= $h['from_room'] ? 'Room ' . htmlspecialchars($h['from_room']) : '—' ?> → <?= $h['to_room'] ? 'Room ' . htmlspecialchars($h['to_room']) : '—' ?></span>
                        </span>
                        <span class="text-muted text-nowrap ms-2"><?= date('M j, Y', strtotime($h['transferred_at'])) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        
    </div>
    
    <!-- Middle Column (45%) -->
    <div class="col-lg-6">
        
        <!-- Recent Transactions -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title">Recent Transactions</h6>
                <a href="#transactions" onclick="document.getElementById('transactions-tab').click(); return false;" class="text-primary text-decoration-none" style="font-size:0.65rem; font-weight:600;">View All</a>
            </div>
            <div>
                <?php if(empty($payments)): ?>
                    <div class="p-4 text-center text-muted" style="font-size:0.75rem;">No transactions recorded.</div>
                <?php else: ?>
                    <?php foreach(array_slice($payments, 0, 4) as $p): 
                        $isVerified = $p['status'] == 'verified';
                        $iconClass = $isVerified ? 'fa-arrow-down text-success' : 'fa-clock text-warning';
                        $bgClass = $isVerified ? 'bg-success bg-opacity-10' : 'bg-warning bg-opacity-10';
                        $title = $isVerified ? 'Payment Received' : 'Payment Pending';
                        $subtitle = date('F Y', strtotime($p['payment_date'])) . ' Rent';
                        $badge = $isVerified ? '<span class="badge bg-success-subtle text-success border border-success-subtle">Paid</span>' : '<span class="badge bg-warning-subtle text-warning border border-warning-subtle">Pending</span>';
                    ?>
                    <div class="tx-item">
                        <div class="d-flex align-items-center">
                            <div class="tx-icon <?= $bgClass ?> me-3"><i class="fa-solid <?= $iconClass ?>"></i></div>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:0.75rem;"><?= $title ?></div>
                                <div class="text-muted" style="font-size:0.65rem;"><?= $subtitle ?></div>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="text-muted" style="font-size:0.6rem; margin-bottom:2px;"><?= date('M j, Y h:i A', strtotime($p['created_at'] ?? $p['payment_date'])) ?></div>
                            <div class="fw-bold <?= $isVerified ? 'text-success' : 'text-warning' ?>" style="font-size:0.75rem;">₱<?= number_format($p['amount'], 2) ?></div>
                        </div>
                        <div><?= $badge ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Payment & Receipts -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title">Payment & Receipts</h6>
                <a href="#payments" onclick="document.getElementById('payments-tab').click(); return false;" class="text-primary text-decoration-none" style="font-size:0.65rem; font-weight:600;">View All Receipts</a>
            </div>
            <div class="receipt-scroll">
                <?php if(empty($payments)): ?>
                    <div class="text-muted w-100 text-center py-3" style="font-size:0.75rem;">No receipts available.</div>
                <?php else: ?>
                    <?php foreach($payments as $p): 
                        if($p['status'] !== 'verified') continue;
                    ?>
                    <div class="receipt-card">
                        <span class="badge bg-success-subtle text-success border border-success-subtle align-self-start mb-1" style="font-size:0.55rem;">Paid</span>
                        <div class="receipt-thumb"><i class="fa-solid fa-file-invoice text-primary opacity-50 fs-3"></i></div>
                        <div class="fw-bold text-dark mb-1" style="font-size:0.6rem;">Receipt No.<br>R-<?= str_pad($p['id'], 6, '0', STR_PAD_LEFT) ?></div>
                        <div class="text-muted" style="font-size:0.55rem;">Date<br><span class="text-dark fw-semibold"><?= date('M j, Y', strtotime($p['payment_date'])) ?></span></div>
                        <div class="text-muted mt-1" style="font-size:0.55rem;">Amount<br><span class="text-dark fw-bold">₱<?= number_format($p['amount'], 2) ?></span></div>
                        <a href="../<?= htmlspecialchars($p['receipt_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm w-100 mt-2 py-0" style="font-size:0.6rem;">View Receipt</a>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
    
    <!-- Right Column (25%) -->
    <div class="col-lg-3">
        
        <!-- Balance Summary -->
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-user text-muted me-2"></i>Balance Summary</h6></div>
            <div class="p-3">
                <div class="balance-row"><span class="text-muted">Total Charges</span><span class="fw-semibold">₱<?= number_format($totalCharges, 2) ?></span></div>
                <div class="balance-row"><span class="text-muted">Total Payments</span><span class="fw-semibold text-success">₱<?= number_format($totalPayments, 2) ?></span></div>
                <div class="balance-row"><span class="text-muted">Overdue</span><span class="fw-semibold <?= $billing['overdue'] > 0 ? 'text-danger' : '' ?>">₱<?= number_format($billing['overdue'], 2) ?></span></div>
                <hr class="my-2 border-light">
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="fw-bold text-dark" style="font-size:0.75rem;">Current Balance</span>
                    <span class="fw-bold <?= $currentBalance > 0 ? 'text-danger' : 'text-success' ?> fs-6">₱<?= number_format($currentBalance, 2) ?></span>
                </div>
                <?php if ($ledgerMismatch): ?>
                    <div class="alert alert-warning border-0 py-1 px-2 mt-2 mb-0" style="font-size:0.62rem;">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Ledger total (₱<?= number_format($ledgerBalance, 2) ?>) doesn't match the stored balance. Please review this account.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Payment Method -->
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-wallet text-muted me-2"></i>Payment Method</h6></div>
            <div class="p-3">
                <div class="d-flex justify-content-between align-items-center mb-2" style="font-size:0.7rem;">
                    <span class="text-muted">Preferred Method</span>
                    <span class="fw-bold text-primary"><i class="fa-solid fa-g me-1"></i>GCash (Manual)</span>
                </div>
                <div class="mt-3">
                    <div class="fw-bold text-dark mb-1" style="font-size:0.7rem;">Instructions</div>
                    <p class="text-muted mb-0" style="font-size:0.65rem;">Pay via GCash to 09XXXXXXXXX.<br>Enter the reference number and upload a screenshot as proof of payment.</p>
                </div>
            </div>
        </div>
        
        <!-- Notes -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-regular fa-note-sticky text-muted me-2"></i>Notes</h6>
                <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.6rem;">Add Note</button>
            </div>
            <div class="p-3 text-muted" style="font-size:0.65rem;">
                No notes available.
            </div>
        </div>
        
        <!-- Actions -->
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-user-gear text-muted me-2"></i>Actions</h6></div>
            <div class="p-3 d-flex flex-column gap-2">
                <a href="#" class="btn btn-outline-primary btn-sm text-start" style="font-size:0.7rem;"><i class="fa-regular fa-envelope me-2"></i>Send Reminder</a>
                <a href="#" class="btn btn-outline-primary btn-sm text-start" style="font-size:0.7rem;"><i class="fa-regular fa-user me-2"></i>View Tenant Profile</a>
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
                                <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M d, Y h:i A', strtotime($p['payment_date'])) ?></td>
                                <td class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($p['reference_number']) ?></td>
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
                                    <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.65rem;"><i class="fa-solid fa-download me-1"></i> Download</button>
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
                            <td class="text-end fw-semibold text-dark" style="font-size:0.7rem;">PHP <?= number_format($running, 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DOCUMENTS TAB -->
    <div class="tab-pane fade" id="documents" role="tabpanel" aria-labelledby="documents-tab">
        <div class="section-card shadow-sm p-4 text-center">
            <div class="bg-light rounded-circle d-inline-flex justify-content-center align-items-center mb-3" style="width: 60px; height: 60px;">
                <i class="fa-solid fa-folder-open fa-2x text-muted"></i>
            </div>
            <h6 class="fw-bold text-dark">No Documents Uploaded</h6>
            <p class="text-muted mb-3" style="font-size:0.75rem;">Upload lease agreements, IDs, or other tenant-related documents here.</p>
            <button class="btn btn-primary btn-sm"><i class="fa-solid fa-upload me-1"></i> Upload Document</button>
        </div>
    </div>

</div> <!-- Close Tab Content -->

<?php require_once 'footer.php'; ?>
