<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
require_once '../includes/sms_reminders.php';   // REMINDER_DAYS_BEFORE: "due soon" means the same as for the SMS reminders
require_once '../includes/ui.php';
requireAdmin();

// --- DATA FETCHING ---

// Batched Tenant Aggregates (unpaid rent includes moved-out tenants who still owe)
$tenantStats = $pdo->query("SELECT
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as total_tenants,
    COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as total_outstanding
    FROM tenants")->fetch();

$totalTenants = (int)$tenantStats['total_tenants'];
$totalOutstanding = $tenantStats['total_outstanding'];

// Overdue = balance past its due date (the 30th), not just any unpaid balance.
// Due soon = nothing late yet, and the bill falls due within the reminder window.
$notYetDue = chargesNotYetDue($pdo);
$overdueTenantsCount = 0;
$overdueAmount = 0.0;
$dueSoonCount = 0;
$dueSoonAmount = 0.0;
$dueSoonLimit = date('Y-m-d', strtotime('+' . REMINDER_DAYS_BEFORE . ' days'));
foreach ($pdo->query("SELECT id, balance, status FROM tenants WHERE balance > 0")->fetchAll() as $t) {
    $billing = tenantBillingStatus($t, $notYetDue);
    if ($billing['overdue'] > 0) {
        $overdueTenantsCount++;
        $overdueAmount += $billing['overdue'];
    } elseif ($billing['key'] === 'due') {
        $due = upcomingDueDate($pdo, (int)$t['id']);
        if ($due && $due <= $dueSoonLimit) {
            $dueSoonCount++;
            $dueSoonAmount += $billing['balance'];
        }
    }
}

// Complaints not resolved yet (pending or in progress).
$unresolvedComplaints = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status <> 'resolved'")->fetchColumn();

// Batched Rooms Aggregate
$totalRooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();

// Batched Payments Aggregate (collected = this month's money received)
$payStmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN " . REVENUE_FILTER_SQL . " AND payment_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as total_collected,
    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as total_pending,
    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count
    FROM payments");
$payStmt->execute([date('Y-m-01'), date('Y-m-t')]);
$paymentStats = $payStmt->fetch();

$totalCollected = $paymentStats['total_collected'];
$totalPending = $paymentStats['total_pending'];
$pendingPaymentsCount = $paymentStats['pending_count'];

// Rooms Capacity & Occupancy Analysis
$roomsData = $pdo->query("
    SELECT r.id, r.room_number, r.capacity, r.status,
           (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id AND t.status = 'active') as tenant_count
    FROM rooms r
")->fetchAll();
usort($roomsData, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));   // 1, 2, 10 - not 1, 10, 2

$availableSpaces = 0;
$totalCapacity = 0;
$occupiedRooms = 0;
$availableRooms = 0;
$fullRooms = 0;
$partialRooms = 0;
$maintenanceRooms = 0;

foreach($roomsData as $r) {
    $totalCapacity += $r['capacity'];
    $space = $r['capacity'] - $r['tenant_count'];
    // A room under maintenance can't take anyone, so its empty beds aren't available.
    if ($r['status'] === 'maintenance') {
        $maintenanceRooms++;
        if ($r['tenant_count'] > 0) $occupiedRooms++;
        continue;
    }
    if($space > 0) $availableSpaces += $space;
    
    if($r['tenant_count'] > 0) {
        $occupiedRooms++;
        if($r['tenant_count'] >= $r['capacity']) {
            $fullRooms++;
        } else {
            $partialRooms++;
        }
    } else {
        $availableRooms++;
    }
}

$actualOccupied = array_sum(array_column($roomsData, 'tenant_count'));
$occupancyRate = $totalCapacity > 0 ? round(($actualOccupied / $totalCapacity) * 100) : 0;
$roomsWithSpaceCount = $availableRooms + $partialRooms;

// Fetch recent payments (verified or not)
$recentPayments = $pdo->query("
    SELECT p.*, t.first_name, t.last_name, t.profile_picture, r.room_number
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 8
")->fetchAll();

// Monthly Revenue Data for Charts (PostgreSQL)
// Latest 6 months of revenue, shown oldest to newest
$monthlyRevData = array_reverse($pdo->query("
    SELECT " . sqlMonthName('payment_date') . " as month_name,
           EXTRACT(MONTH FROM payment_date) as month_num,
           EXTRACT(YEAR FROM payment_date) as year_num,
           SUM(amount) as total
    FROM payments
    WHERE " . REVENUE_FILTER_SQL . "
    GROUP BY year_num, month_num, month_name
    ORDER BY year_num DESC, month_num DESC
    LIMIT 6
")->fetchAll());

$monthNames = [];
$monthTotals = [];
if (empty($monthlyRevData)) {
    // Graceful fallback if database has no verified payments yet
    $monthNames = ["No Data"];
    $monthTotals = [0];
} else {
    foreach ($monthlyRevData as $row) {
        $monthNames[] = $row['month_name'];
        $monthTotals[] = $row['total'];
    }
}
$jsMonthNames = json_encode($monthNames);
$jsMonthTotals = json_encode($monthTotals);

// Card sub-lines (real figures, not placeholders)
$moveStmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN to_room_id IS NOT NULL AND note LIKE 'Moved in%' THEN 1 ELSE 0 END), 0) AS move_ins,
    COALESCE(SUM(CASE WHEN note LIKE 'Moved out%' THEN 1 ELSE 0 END), 0) AS move_outs
    FROM room_transfers WHERE transferred_at BETWEEN ? AND ?");
$moveStmt->execute([date('Y-m-01'), date('Y-m-t')]);
$moves = $moveStmt->fetch();
$tenantTrend = $moves['move_ins'] . " moved in, " . $moves['move_outs'] . " moved out this month";
$roomTrend = "$occupiedRooms occupied" . ($maintenanceRooms ? ", $maintenanceRooms under maintenance" : '');
$spaceTrend = "in $roomsWithSpaceCount " . ($roomsWithSpaceCount === 1 ? 'room' : 'rooms');
$balanceTrend = $overdueTenantsCount . " tenant" . ($overdueTenantsCount == 1 ? '' : 's') . " overdue";

require_once 'header.php';
?>

<style>
    body { background-color: #f8fafc; }
    .metric-card { transition: transform 0.2s; border: 1px solid #f1f5f9; }
    .metric-card:hover { transform: translateY(-2px); box-shadow: 0 5px 10px -3px rgba(0,0,0,0.05); }
    
    .icon-box-lg {
        width: 38px; height: 38px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem;
    }
    
    .card-title-sm {
        font-size: 0.6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 2px;
    }

    .trend-text { font-size: 0.65rem; font-weight: 500; }
    
    .chart-container { position: relative; height: 140px; width: 100%; }
    
    .alert-box { border-radius: 8px; padding: 0.6rem 0.75rem; margin-bottom: 0.5rem; border: 1px solid transparent; }
    .alert-danger-soft { background-color: #fef2f2; border-color: #fee2e2; }
    .alert-warning-soft { background-color: #fffbeb; border-color: #fef3c7; }
    .alert-success-soft { background-color: #f0fdf4; border-color: #dcfce7; }
    
    .table-compact th { font-size: 0.6rem; text-transform: uppercase; color: #64748b; font-weight: 600; padding: 0.5rem; }
    .table-compact td { font-size: 0.7rem; vertical-align: middle; padding: 0.4rem 0.5rem; }
    
    .legend-pill {
        display: inline-flex; align-items: center;
        background-color: #f8fafc; border: 1px solid #e2e8f0;
        border-radius: 20px; padding: 2px 8px; font-size: 0.6rem; font-weight: 600; color: #475569;
        margin-right: 4px; margin-bottom: 4px;
    }
    .dot { width: 5px; height: 5px; border-radius: 50%; display: inline-block; margin-right: 5px; }
    
    .attention-row { gap: 0.6rem; padding: 0.55rem 0.6rem; border-radius: 8px; border: 1px solid #f1f5f9; margin-bottom: 0.4rem; }
    .attention-row:last-child { margin-bottom: 0; }
    .attention-row:hover { background: #f8fafc; border-color: #e2e8f0; }
    .attention-row.is-clear { opacity: 0.75; }
    .attention-icon { width: 30px; height: 30px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; flex-shrink: 0; }
    .room-status-item { padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9; }
    .room-status-item:last-child { border-bottom: none; }
    .room-status-item:hover { background: #f8fafc; }
</style>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div>
        <p class="text-muted mb-0" style="font-size: 0.8rem;">Welcome back, Administrator! Here's what's happening today.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="tenants.php?add=1" class="btn btn-outline-primary bg-white text-primary fw-semibold btn-sm px-2 py-1 rounded-2" style="font-size:0.75rem;"><i class="fa-solid fa-plus me-1"></i> Add Tenant</a>
        <a href="rooms.php" class="btn btn-outline-primary bg-white text-primary fw-semibold btn-sm px-2 py-1 rounded-2" style="font-size:0.75rem;"><i class="fa-solid fa-plus me-1"></i> Add Room</a>
    </div>
</div>

<!-- Top Stats Row: every card opens the list behind it -->
<?= statCards([
    ['label' => 'Total Tenants', 'value' => $totalTenants, 'sub' => $tenantTrend, 'icon' => 'fa-solid fa-user-group', 'color' => 'primary',
     'href' => 'tenants.php', 'aria' => 'View tenants'],
    ['label' => 'Total Rooms', 'value' => $totalRooms, 'sub' => $roomTrend, 'icon' => 'fa-solid fa-door-open', 'color' => 'info',
     'href' => 'rooms.php', 'aria' => 'View rooms'],
    ['label' => 'Available Beds', 'value' => $availableSpaces, 'sub' => $spaceTrend, 'subClass' => 'text-success', 'icon' => 'fa-solid fa-bed', 'color' => 'success',
     'href' => 'rooms.php?show=space', 'aria' => 'View rooms with free beds'],
    ['label' => 'Total Unpaid Rent', 'value' => peso((float)$totalOutstanding), 'sub' => $balanceTrend, 'subClass' => $overdueTenantsCount ? 'text-danger' : 'text-muted',
     'icon' => 'fa-solid fa-coins', 'color' => 'warning', 'href' => 'tenants.php?status=unpaid', 'aria' => 'View tenants with unpaid rent'],
]) ?>

<!-- Middle Charts Row -->
<div class="row g-2 mb-3">
    <!-- Occupancy Overview -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.8rem;"><i class="fa-solid fa-chart-simple text-primary me-2"></i> Occupancy Overview</h6>
                
                <div class="row align-items-center mb-3">
                    <div class="col-4 text-center">
                        <h2 class="fw-bolder text-primary mb-0"><?= $occupancyRate ?>%</h2>
                        <small class="text-muted fw-semibold" style="font-size: 0.65rem;">Occupancy Rate</small>
                    </div>
                    <div class="col-4">
                        <div class="chart-container" style="height: 100px;">
                            <canvas id="occupancyChart"></canvas>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="mb-2">
                            <h6 class="fw-bold text-primary mb-0"><?= $totalTenants ?></h6>
                            <small class="text-muted fw-semibold" style="font-size: 0.65rem;">Occupied Spaces</small>
                        </div>
                        <div>
                            <h6 class="fw-bold text-success mb-0"><?= $availableSpaces ?></h6>
                            <small class="text-muted fw-semibold" style="font-size: 0.65rem;">Available Spaces</small>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex flex-wrap justify-content-center mt-2 border-top pt-2">
                    <div class="legend-pill"><span class="dot bg-primary"></span> <?= $totalTenants ?> Occupied</div>
                    <div class="legend-pill"><span class="dot bg-success"></span> <?= $availableSpaces ?> Available</div>
                    <div class="legend-pill"><span class="dot bg-danger"></span> <?= $fullRooms ?> Full</div>
                    <div class="legend-pill"><span class="dot bg-warning"></span> <?= $partialRooms ?> Partially Occupied</div>
                    <?php if ($maintenanceRooms): ?><div class="legend-pill"><span class="dot bg-secondary"></span> <?= $maintenanceRooms ?> Maintenance</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Payment Overview -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-dark mb-0" style="font-size:0.8rem;"><i class="fa-solid fa-wallet text-primary me-2"></i> Payment Overview</h6>
                    <span class="text-muted fw-semibold" style="font-size: 0.7rem;"><?= date('F Y') ?></span>
                </div>
                
                <div class="row align-items-center">
                    <div class="col-md-5">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted fw-semibold" style="font-size: 0.7rem;"><span class="dot bg-success"></span> Total Collected</span>
                            <span class="fw-bold text-success" style="font-size:0.75rem;">₱<?= number_format($totalCollected, 0) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted fw-semibold" style="font-size: 0.7rem;"><span class="dot bg-warning"></span> Pending Verification</span>
                            <span class="fw-bold text-warning" style="font-size:0.75rem;">₱<?= number_format($totalPending, 0) ?></span>
                        </div>
                        
                        <hr class="text-light my-2">
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-dark fw-bold" style="font-size: 0.7rem;">Unpaid Rent</span>
                            <span class="fw-bold text-danger fs-6">₱<?= number_format($totalOutstanding, 0) ?></span>
                        </div>
                    </div>
                    
                    <div class="col-md-7">
                        <div class="chart-container" style="height: 120px;">
                            <canvas id="paymentChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Row: Attention Required and Rooms Status side by side, Recent Payments below -->
<div class="row g-2">
    <!-- Attention Required: what is waiting on the admin, each linked to where it is dealt with -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.8rem;"><i class="fa-solid fa-triangle-exclamation text-warning me-2"></i> Attention Required</h6>
                <?php
                $attention = [
                    ['Overdue Rent', $overdueTenantsCount,
                     $overdueTenantsCount ? peso($overdueAmount) . ' from ' . $overdueTenantsCount . ' ' . ($overdueTenantsCount === 1 ? 'tenant' : 'tenants') : 'Nobody is behind on rent',
                     'fa-circle-exclamation', 'danger', 'tenants.php?status=overdue'],
                    ['Pending Payment Verification', (int)$pendingPaymentsCount,
                     $pendingPaymentsCount ? peso((float)$totalPending) . ' waiting to be checked' : 'No payments to verify',
                     'fa-hourglass-half', 'warning', 'payments.php?status=pending'],
                    ['Unresolved Complaints', $unresolvedComplaints,
                     $unresolvedComplaints ? 'Pending or in progress' : 'No open complaints',
                     'fa-comment-dots', 'danger', 'complaints.php?status=unresolved'],
                    ['Upcoming Rent Due', $dueSoonCount,
                     $dueSoonCount ? peso($dueSoonAmount) . ' due in the next ' . REMINDER_DAYS_BEFORE . ' days' : 'Nothing due in the next ' . REMINDER_DAYS_BEFORE . ' days',
                     'fa-calendar-day', 'primary', 'tenants.php?status=due_soon'],
                    ['Available Rooms', $roomsWithSpaceCount,
                     $roomsWithSpaceCount ? $availableSpaces . ' free ' . ($availableSpaces === 1 ? 'bed' : 'beds') : 'Every room is full',
                     'fa-door-open', 'success', 'rooms.php?show=space'],
                ];
                foreach ($attention as [$label, $count, $detail, $icon, $color, $href]): ?>
                <a href="<?= $href ?>" class="attention-row d-flex align-items-center text-decoration-none <?= $count ? '' : 'is-clear' ?>">
                    <span class="attention-icon bg-<?= $count ? $color : 'secondary' ?> bg-opacity-10 text-<?= $count ? $color : 'secondary' ?>"><i class="fa-solid <?= $icon ?>"></i></span>
                    <span class="flex-grow-1" style="min-width:0;">
                        <span class="d-block fw-bold text-dark" style="font-size: 0.75rem;"><?= $label ?></span>
                        <span class="d-block text-muted text-truncate" style="font-size: 0.65rem;"><?= htmlspecialchars($detail) ?></span>
                    </span>
                    <span class="badge rounded-pill <?= $count ? "bg-$color" : 'bg-light text-muted border' ?> ms-2"><?= $count ?></span>
                    <i class="fa-solid fa-chevron-right text-muted ms-2" style="font-size: 0.6rem;"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Rooms Status -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0" style="font-size:0.8rem;"><i class="fa-solid fa-door-closed text-primary me-2"></i> Rooms Status</h6>
                <a href="rooms.php" class="text-primary text-decoration-none fw-semibold" style="font-size: 0.65rem;">View All</a>
            </div>
            <div class="card-body p-3 pt-2">
                <?php 
                $count = 0;
                foreach($roomsData as $r): 
                    if($count++ >= 5) break;
                    
                    if ($r['status'] === 'maintenance') {
                        $iconColor = 'secondary';
                        $statusBadge = '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-2 py-0" style="font-size:0.6rem;">Maintenance</span>';
                    } elseif($r['tenant_count'] >= $r['capacity']) {
                        $iconColor = 'danger';
                        $statusBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-0" style="font-size:0.6rem;">Full</span>';
                    } elseif ($r['tenant_count'] > 0) {
                        $iconColor = 'warning';
                        $statusBadge = '<span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-0" style="font-size:0.6rem;">Partially Occupied</span>';
                    } else {
                        $iconColor = 'success';
                        $statusBadge = '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0" style="font-size:0.6rem;">Available</span>';
                    }
                ?>
                <a href="rooms.php?room=<?= (int)$r['id'] ?>" class="room-status-item d-flex justify-content-between align-items-center text-decoration-none">
                    <div class="d-flex align-items-center">
                        <div class="bg-<?= $iconColor ?> bg-opacity-10 text-<?= $iconColor ?> rounded d-flex justify-content-center align-items-center me-2" style="width: 32px; height: 32px; font-size: 0.8rem;">
                            <i class="fa-solid fa-door-open"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.75rem;">Room <?= htmlspecialchars($r['room_number'] ?? '') ?></div>
                            <div class="text-muted" style="font-size: 0.65rem;"><?= $r['tenant_count'] ?> / <?= $r['capacity'] ?> tenants</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <?= $statusBadge ?>
                        <i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.6rem;"></i>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Recent Payments: full width under the two panels above -->
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0" style="font-size:0.8rem;"><i class="fa-solid fa-money-bill text-primary me-2"></i> Recent Payments</h6>
                <a href="payments.php" class="text-primary text-decoration-none fw-semibold" style="font-size: 0.7rem;">View All</a>
            </div>
            <div class="card-body p-0 mt-2">
                <div class="table-responsive">
                    <table class="table table-hover table-compact mb-0 border-top w-100">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th class="ps-3 border-0">Tenant</th>
                                <th class="border-0">Room</th>
                                <th class="border-0">Date</th>
                                <th class="border-0">Method</th>
                                <th class="border-0">Reference</th>
                                <th class="border-0 text-end">Amount</th>
                                <th class="pe-3 border-0 text-end">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($recentPayments) === 0): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No recent payments.</td></tr>
                            <?php endif; ?>

                            <?php foreach($recentPayments as $p):
                                if ($p['status'] === 'verified') {
                                    $statusBadge = '<span class="badge bg-success-subtle text-success border border-success-subtle px-2" style="font-size:0.65rem;">Paid</span>';
                                } elseif ($p['status'] === 'rejected') {
                                    $statusBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2" style="font-size:0.65rem;">Rejected</span>';
                                } else {
                                    $statusBadge = '<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2" style="font-size:0.65rem;">Pending</span>';
                                }
                                $method = $p['payment_method'] ?? 'gcash';
                                $methodLabel = stripos($method, 'covered by') === 0 ? $method : ($method === 'cash' ? 'Cash' : 'GCash');
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center">
                                        <?= avatarHtml($p['first_name'] . ' ' . $p['last_name'], 24, 'me-2 shadow-sm', $p['profile_picture'] ?? null, '../') ?>
                                        <span class="fw-semibold text-dark text-truncate d-inline-block" style="max-width:180px; font-size: 0.78rem;"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?></span>
                                    </div>
                                </td>
                                <td class="text-muted fw-semibold" style="font-size: 0.75rem;"><?= $p['room_number'] ? 'Room ' . htmlspecialchars($p['room_number']) : '<span class="text-black-50">No room</span>' ?></td>
                                <td class="text-muted text-nowrap" style="font-size: 0.75rem;"><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                                <td class="text-muted" style="font-size: 0.75rem;"><?= htmlspecialchars($methodLabel) ?></td>
                                <td class="text-muted text-truncate" style="font-size: 0.75rem; max-width: 160px;"><?= htmlspecialchars($p['reference_number'] ?? '') ?></td>
                                <td class="fw-bold text-dark text-end text-nowrap" style="font-size: 0.78rem;">₱<?= number_format($p['amount'], 2) ?></td>
                                <td class="pe-3 text-end"><?= $statusBadge ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white border-top p-2 text-center">
                <span class="text-muted" style="font-size: 0.7rem;">Latest <?= count($recentPayments) ?> payment<?= count($recentPayments) === 1 ? '' : 's' ?> · <a href="payments.php" class="text-decoration-none">see all on the Payments page</a></span>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Occupancy Doughnut Chart
    const occCtx = document.getElementById('occupancyChart').getContext('2d');
    new Chart(occCtx, {
        type: 'doughnut',
        data: {
            labels: ['Occupied', 'Available'],
            datasets: [{
                data: [<?= $actualOccupied ?>, <?= $availableSpaces ?>],
                backgroundColor: ['#0d6efd', '#20c997'],
                borderWidth: 0,
                cutout: '75%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true }
            }
        }
    });

    // Payments Bar Chart
    const payCtx = document.getElementById('paymentChart').getContext('2d');
    const monthNames = <?= $jsMonthNames ?>;
    const monthData = <?= $jsMonthTotals ?>;

    new Chart(payCtx, {
        type: 'bar',
        data: {
            labels: monthNames,
            datasets: [{
                label: 'Collected',
                data: monthData,
                backgroundColor: '#3b82f6',
                borderRadius: 4,
                barThickness: 16
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 9 },
                        callback: function(value) { return '₱' + (value/1000) + 'k'; }
                    }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 10 } }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) { return ' ₱' + context.raw.toLocaleString(); }
                    }
                }
            }
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
