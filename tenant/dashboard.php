<?php
require_once 'header.php';

// Fetch specific room info for the tenant
$room = null;
$roomOccupants = 0;
if (!empty($currentTenant['room_id'])) {
    $stmt = $pdo->prepare("
        SELECT r.*, (SELECT COUNT(*) FROM tenants WHERE room_id = r.id) as occupant_count 
        FROM rooms r WHERE r.id = ?
    ");
    $stmt->execute([$currentTenant['room_id']]);
    $room = $stmt->fetch();
    $roomOccupants = $room ? $room['occupant_count'] : 0;
}

// Fetch recent payment history
$payStmt = $pdo->prepare("SELECT * FROM payments WHERE tenant_id = ? ORDER BY payment_date DESC LIMIT 4");
$payStmt->execute([$currentTenant['id']]);
$recentPayments = $payStmt->fetchAll();

// Fetch settings (for GCash)
$settings = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$gcashNumber = $settings['gcash_number'] ?? '0917 123 4567';
$gcashName = $settings['gcash_name'] ?? 'Boarding House';

// Get user's move-in date
$uStmt = $pdo->prepare("SELECT created_at FROM users WHERE id = ?");
$uStmt->execute([$_SESSION['user_id']]);
$userCreatedAt = $uStmt->fetchColumn();
$moveInDay = (int)date('d', strtotime($userCreatedAt));

// Calculate Next Due Date based on move-in day
$currentDay = (int)date('d');
$currentMonth = (int)date('m');
$currentYear = (int)date('Y');

if ($currentDay >= $moveInDay) {
    // Due date has passed this month, so next due date is next month
    $nextMonth = $currentMonth + 1;
    $nextYear = $currentYear;
    if ($nextMonth > 12) {
        $nextMonth = 1;
        $nextYear++;
    }
    $nextDueDate = date('M d, Y', strtotime("$nextYear-$nextMonth-$moveInDay"));
} else {
    // Due date is coming up this month
    $nextDueDate = date('M d, Y', strtotime("$currentYear-$currentMonth-$moveInDay"));
}

$balance = $currentTenant['balance'] ?? 0;
?>

<style>
.dashboard-header { margin-bottom: 1.5rem; }
.room-card {
    background: #0d6efd; /* Fallback */
    background: linear-gradient(135deg, #1e71ff 0%, #0055ff 100%);
    border-radius: 20px;
    padding: 1.5rem 1.75rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 20px rgba(13, 110, 253, 0.2);
}
.room-card-circle {
    position: absolute;
    top: -50px;
    right: -30px;
    width: 160px;
    height: 160px;
    background: #ffffff;
    border-radius: 50%;
    z-index: 1;
}
.room-badge {
    background: #ffffff;
    color: #198754;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 0.4rem 1rem;
    border-radius: 50rem;
    position: relative;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
}
.room-badge .dot { width: 6px; height: 6px; background: #198754; border-radius: 50%; }
.room-inner-card {
    background: rgba(0, 0, 0, 0.15);
    border-radius: 14px;
    padding: 1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 1.25rem;
}
.quick-action-btn {
    text-align: center;
    text-decoration: none;
    color: #334155;
    font-size: 0.75rem;
    font-weight: 600;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    transition: transform 0.2s ease;
}
.quick-action-btn:hover { transform: translateY(-3px); }
.qa-icon {
    width: 56px;
    height: 56px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
}
.qa-submit { background: #eff6ff; color: #3b82f6; }
.qa-receipts { background: #f0fdf4; color: #22c55e; }
.qa-history { background: #fefce8; color: #eab308; }
.qa-report { background: #fef2f2; color: #ef4444; }

.section-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: 0 2px 12px rgba(0,0,0,0.04);
    margin-bottom: 1.25rem;
}
.transaction-table { width: 100%; min-width: 600px; }
.transaction-table th { font-size: 0.65rem; color: #64748b; text-transform: uppercase; padding-bottom: 1rem; border-bottom: 1px solid #f1f5f9; font-weight: 700; letter-spacing: 0.5px;}
.transaction-table td { font-size: 0.8rem; vertical-align: middle; padding: 1rem 0; border-bottom: 1px solid #f1f5f9; }
.transaction-table tr:last-child td { border-bottom: none; padding-bottom: 0; }

.gcash-box {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.5rem;
    background: #fafafa;
}
.announcement-card {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    border-radius: 16px;
    padding: 1.5rem;
}
</style>

<div class="container-fluid pt-3 pb-5" style="max-width: 800px; margin: 0 auto;">
<div class="dashboard-header d-flex justify-content-between align-items-center">
    <div>
        <h3 class="fw-bolder mb-0 text-dark">My Space</h3>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">Welcome back, <?= htmlspecialchars($currentTenant['first_name']) ?>! 👋</p>
    </div>
    <a href="payments.php" class="btn btn-primary fw-bold rounded-3 shadow-sm px-3 py-2" style="font-size: 0.85rem;">
        <i class="fa-solid fa-upload me-2"></i> Submit Payment
    </a>
</div>

<?php if ($room): ?>
<div class="room-card mb-4">
    <div class="room-card-circle"></div>
    <div class="d-flex justify-content-between align-items-start mb-3 position-relative z-2">
        <div class="text-white d-flex align-items-center gap-2" style="font-size: 0.85rem; font-weight: 500;">
            <i class="fa-solid fa-door-closed"></i> Current Room
        </div>
        <div class="room-badge">
            <div class="dot"></div> Active
        </div>
    </div>
    
    <div class="position-relative z-2 mb-3">
        <h1 class="fw-bolder text-white mb-1" style="font-size: 2rem;">Room <?= htmlspecialchars($room['room_number']) ?></h1>
        <div class="text-white-50 fw-semibold" style="font-size: 0.85rem;">
            <i class="fa-solid fa-money-bill-wave me-1"></i> PHP <?= number_format($room['price_per_month'], 2) ?> / month
        </div>
    </div>

    <div class="row text-white position-relative z-2 mb-2 g-3">
        <div class="col-4">
            <div class="text-white-50" style="font-size: 0.65rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Capacity</div>
            <div class="fw-semibold" style="font-size: 0.8rem;"><i class="fa-solid fa-user-group me-1 opacity-75"></i> <?= $room['capacity'] ?> persons</div>
        </div>
        <div class="col-4 border-start border-light border-opacity-25 ps-3">
            <div class="text-white-50" style="font-size: 0.65rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Occupied</div>
            <div class="fw-semibold" style="font-size: 0.8rem;"><i class="fa-solid fa-users me-1 opacity-75"></i> <?= $roomOccupants ?> / <?= $room['capacity'] ?> persons</div>
        </div>
        <div class="col-4 border-start border-light border-opacity-25 ps-3">
            <div class="text-white-50" style="font-size: 0.65rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Next Due Date</div>
            <div class="fw-semibold" style="font-size: 0.8rem;"><i class="fa-regular fa-calendar-days me-1 opacity-75"></i> <?= $nextDueDate ?></div>
        </div>
    </div>

    <div class="room-inner-card position-relative z-2">
        <div>
            <?php if ($balance <= 0): ?>
                <div class="text-white-50 fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Balance Status</div>
                <div class="fw-bolder" style="font-size: 1.25rem; color: #4ade80;">Fully Paid <i class="fa-solid fa-check-circle ms-1" style="font-size: 1rem;"></i></div>
            <?php else: ?>
                <div class="text-white-50 fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Outstanding Balance</div>
                <div class="fw-bolder" style="font-size: 1.25rem; color: #ff6b6b;">PHP <?= number_format($balance, 2) ?></div>
            <?php endif; ?>
        </div>
        <a href="payments.php" class="btn bg-white text-primary fw-bold rounded-pill shadow-sm px-3 py-2" style="font-size: 0.75rem;">
            View Details <i class="fa-solid fa-chevron-right ms-1"></i>
        </a>
    </div>
</div>
<?php else: ?>
<div class="section-card mb-4 text-center py-5">
    <div class="bg-light rounded-circle d-inline-flex justify-content-center align-items-center mb-3" style="width: 70px; height: 70px;">
        <i class="fa-solid fa-door-open fa-2x text-muted"></i>
    </div>
    <h5 class="fw-bold text-dark">No Room Assigned</h5>
    <p class="text-muted mb-0">Please wait for the administrator to assign you to a room.</p>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-4 mt-2 px-2">
    <a href="payments.php" class="quick-action-btn">
        <div class="qa-icon qa-submit"><i class="fa-solid fa-upload"></i></div>
        Submit<br>Payment
    </a>
    <a href="receipts.php" class="quick-action-btn">
        <div class="qa-icon qa-receipts"><i class="fa-solid fa-file-invoice"></i></div>
        View<br>Receipts
    </a>
    <a href="payments.php" class="quick-action-btn">
        <div class="qa-icon qa-history"><i class="fa-solid fa-clock-rotate-left"></i></div>
        Payment<br>History
    </a>
    <a href="complaints.php" class="quick-action-btn">
        <div class="qa-icon qa-report"><i class="fa-solid fa-bullhorn"></i></div>
        Report<br>Issue
    </a>
</div>

<div class="section-card">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i> Recent Transactions</h6>
        <a href="payments.php" class="text-primary fw-bold text-decoration-none" style="font-size: 0.75rem;">View All</a>
    </div>
    
    <div class="table-responsive">
        <table class="transaction-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference No.</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentPayments)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">No transactions found.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentPayments as $p): ?>
                <tr>
                    <td>
                        <div class="fw-bold text-dark"><?= date('M d, Y', strtotime($p['payment_date'])) ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;"><?= date('h:i A', strtotime($p['created_at'] ?? $p['payment_date'])) ?></div>
                    </td>
                    <td>
                        <div class="fw-bold text-dark font-monospace"><?= htmlspecialchars($p['reference_number']) ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;"><?= ucfirst($p['payment_method'] ?? 'gcash') ?></div>
                    </td>
                    <td class="fw-bold text-dark">PHP <?= number_format($p['amount'], 2) ?></td>
                    <td>
                        <?php if($p['status'] === 'verified'): ?>
                            <span class="badge bg-success-subtle text-success px-2 py-1 rounded-1 fw-bold border border-success-subtle">Paid</span>
                        <?php elseif($p['status'] === 'pending'): ?>
                            <span class="badge bg-warning-subtle text-warning px-2 py-1 rounded-1 fw-bold border border-warning-subtle">Pending</span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-1 fw-bold border border-danger-subtle">Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.7rem;"></i>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="gcash-box mb-4">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                <i class="fa-solid fa-wallet text-primary fa-lg"></i>
            </div>
            <div>
                <h6 class="fw-bold text-dark mb-1">GCash Payment</h6>
                <div class="text-muted" style="font-size: 0.75rem;">Send your payment to the number below and upload your proof of payment.</div>
            </div>
        </div>
        <button class="btn btn-sm btn-white border shadow-sm fw-bold text-primary d-flex align-items-center gap-2" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($gcashNumber) ?>'); alert('Copied!');">
            <i class="fa-regular fa-copy"></i> Copy
        </button>
    </div>
    
    <div class="d-flex justify-content-between align-items-center border-top pt-3">
        <div>
            <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px;">GCash Number</div>
            <h4 class="fw-bolder text-primary mb-0"><?= htmlspecialchars($gcashNumber) ?></h4>
        </div>
        <div class="border-start ps-4">
            <div class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px;">Account Name</div>
            <h6 class="fw-bolder text-dark mb-0"><?= htmlspecialchars($gcashName) ?></h6>
        </div>
        <div class="border-start ps-4 d-none d-md-block text-center">
            <div class="text-dark fw-bold mb-1" style="font-size: 0.65rem;">Scan to Pay</div>
            <div class="bg-dark rounded" style="width: 40px; height: 40px; opacity: 0.8; display: flex; align-items:center; justify-content:center;">
                <i class="fa-solid fa-qrcode text-white"></i>
            </div>
        </div>
    </div>
</div>

<div class="announcement-card mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-bullhorn text-warning me-2"></i> Announcements</h6>
    </div>
    
    <?php if (empty($announcements)): ?>
        <div class="text-muted small">No recent announcements.</div>
    <?php else: ?>
        <?php foreach ($announcements as $a): ?>
        <div class="d-flex gap-2 align-items-start mb-3">
            <div class="mt-1"><span class="dot" style="width: 6px; height: 6px; background: #eab308; border-radius: 50%; display: block;"></span></div>
            <div>
                <div class="text-dark fw-bold" style="font-size: 0.85rem;"><?= htmlspecialchars($a['title']) ?></div>
                <div class="text-dark" style="font-size: 0.8rem;"><?= nl2br(htmlspecialchars($a['message'])) ?></div>
                <div class="text-muted mt-1" style="font-size: 0.7rem;"><?= date('M j, Y h:i A', strtotime($a['created_at'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div> <!-- Close container-fluid -->

<?php require_once 'footer.php'; ?>
