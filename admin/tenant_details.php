<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Tenant ID.");
}

$tenantId = (int)$_GET['id'];

// Fetch Tenant & Room details
$stmt = $pdo->prepare("
    SELECT t.*, u.username, u.temp_password, r.room_number, r.price_per_month, u.created_at as move_in_date
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

$totalPayments = 0;
foreach ($payments as $p) {
    if ($p['status'] == 'verified') {
        $totalPayments += $p['amount'];
    }
}

// Calculated fields for UI
$monthlyRent = $tenant['price_per_month'] ?? 0;
$currentBalance = $tenant['balance'] ?? 0;
$previousBalance = $currentBalance + $totalPayments; // Simplified calculation for UI

require_once 'header.php';
?>

<style>
    body { background-color: #f8fafc; }
    
    /* Top Profile Card */
    .profile-card { border-radius: 12px; border: 1px solid #f1f5f9; }
    .avatar-wrapper { position: relative; width: 80px; height: 80px; }
    .avatar-wrapper img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
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
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($tenant['first_name'].' '.$tenant['last_name']) ?>&background=random&color=fff&size=128" alt="Avatar">
                    <span class="badge bg-success-subtle text-success rounded-pill status-badge-overlap">Active</span>
                </div>
                <div>
                    <h5 class="fw-bold mb-2 text-dark"><?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?></h5>
                    <div class="d-flex flex-column gap-1 text-muted" style="font-size: 0.7rem;">
                        <span><i class="fa-solid fa-phone me-2"></i><?= htmlspecialchars($tenant['contact_number'] ?: 'N/A') ?></span>
                        <span><i class="fa-solid fa-envelope me-2"></i><?= htmlspecialchars($tenant['username']) ?>@system.local</span>
                        <span><i class="fa-solid fa-user me-2"></i><?= htmlspecialchars($tenant['username']) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Middle: Room Info -->
            <div class="col-md-5 border-end border-light px-4">
                <div class="d-flex flex-column gap-2" style="font-size: 0.75rem;">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Room</span>
                        <span class="fw-bold text-dark">Room <?= htmlspecialchars($tenant['room_number'] ?? 'Unassigned') ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Move-in Date</span>
                        <span class="fw-bold text-dark"><i class="fa-regular fa-calendar me-1"></i><?= date('M j, Y', strtotime($tenant['move_in_date'])) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Monthly Rent</span>
                        <span class="fw-bold text-dark">₱<?= number_format($monthlyRent, 2) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Right: Balance -->
            <div class="col-md-3 px-4 text-center text-md-end">
                <div class="text-muted mb-1" style="font-size: 0.75rem;">Current Balance</div>
                <?php if ($currentBalance <= 0): ?>
                    <h3 class="fw-bold text-success mb-1">₱0.00</h3>
                    <div class="text-success fw-bold" style="font-size: 0.7rem;"><i class="fa-solid fa-check me-1"></i>Fully Paid</div>
                <?php else: ?>
                    <h3 class="fw-bold text-danger mb-1">₱<?= number_format($currentBalance, 2) ?></h3>
                    <div class="text-danger" style="font-size: 0.7rem;"><i class="fa-regular fa-clock me-1"></i>Due Date: <?= date('jS', strtotime($tenant['move_in_date'])) ?> of the month</div>
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
                ' ?></span>
                <span class="info-label">Contact Number</span><span class="info-value"><?= htmlspecialchars($tenant['contact_number']) ?></span>
                <span class="info-label">Email</span><span class="info-value text-primary"><?= htmlspecialchars($tenant['username']) ?>@system.local</span>
                  <span class="info-label">Temporary Password</span><span class="info-value"><?= !empty($tenant['temp_password']) ? '<span class="badge bg-warning text-dark fw-bold" style="font-family: monospace; font-size:0.7rem;">' . htmlspecialchars($tenant['temp_password']) . '</span>' : '<span class="text-success fst-italic fw-semibold" style="font-size:0.7rem;"><i class="fa-solid fa-check"></i> Changed by tenant</span>' ?></span>
                <span class="info-label">Occupation</span><span class="info-value"><?= !empty($tenant['occupation']) ? htmlspecialchars($tenant['occupation']) : '<span class="text-black-50 fst-italic">Not provided</span>' ?></span>
                <span class="info-label">Emergency Contact</span><span class="info-value"><?= !empty($tenant['emergency_contact']) ? htmlspecialchars($tenant['emergency_contact']) : '<span class="text-black-50 fst-italic">Not provided</span>' ?></span>
                ' ?></span>
            </div>
        </div>
        
        <!-- Room Information -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-building-user text-muted me-2"></i>Room Information</h6>
            </div>
            <div class="info-grid">
                <span class="info-label">Room Number</span><span class="info-value">Room <?= htmlspecialchars($tenant['room_number'] ?? 'N/A') ?></span>
                <span class="info-label">Room Type</span><span class="info-value">Standard</span>
                <span class="info-label">Monthly Rent</span><span class="info-value">₱<?= number_format($monthlyRent, 2) ?></span>
                <span class="info-label">Status</span>
                <span class="info-value"><span class="badge bg-success-subtle text-success border border-success-subtle">Occupied</span></span>
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
                <div class="balance-row"><span class="text-muted">Previous Balance</span><span class="fw-semibold">₱<?= number_format($previousBalance, 2) ?></span></div>
                <div class="balance-row"><span class="text-muted">Total Payments</span><span class="fw-semibold text-success">₱<?= number_format($totalPayments, 2) ?></span></div>
                <div class="balance-row"><span class="text-muted">Adjustments</span><span class="fw-semibold">₱0.00</span></div>
                <hr class="my-2 border-light">
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="fw-bold text-dark" style="font-size:0.75rem;">Current Balance</span>
                    <span class="fw-bold text-danger fs-6">₱<?= number_format($currentBalance, 2) ?></span>
                </div>
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
                <i class="fa-solid fa-circle-info me-1"></i> This ledger displays an estimated breakdown of charges and payments based on the move-in date.
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-0">Date</th>
                            <th class="border-0">Description</th>
                            <th class="border-0 text-end">Charge</th>
                            <th class="border-0 text-end">Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M d, Y', strtotime($tenant['move_in_date'])) ?></td>
                            <td class="text-muted" style="font-size:0.7rem;">Initial Room Charge</td>
                            <td class="text-end fw-bold text-danger" style="font-size:0.7rem;">PHP <?= number_format($monthlyRent, 2) ?></td>
                            <td class="text-end text-muted" style="font-size:0.7rem;">-</td>
                        </tr>
                        <?php foreach($verifiedPayments as $vp): ?>
                        <tr>
                            <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M d, Y', strtotime($vp['payment_date'])) ?></td>
                            <td class="text-muted" style="font-size:0.7rem;">Payment Received (RCP-<?= str_pad($vp['id'], 6, '0', STR_PAD_LEFT) ?>)</td>
                            <td class="text-end text-muted" style="font-size:0.7rem;">-</td>
                            <td class="text-end fw-bold text-success" style="font-size:0.7rem;">PHP <?= number_format($vp['amount'], 2) ?></td>
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
