<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

// --- DATA FETCHING ---

// Batched Tenant Aggregates
$tenantStats = $pdo->query("SELECT 
    COUNT(*) as total_tenants, 
    COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) as total_outstanding, 
    SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) as overdue_count
    FROM tenants")->fetch();

$totalTenants = $tenantStats['total_tenants'];
$totalOutstanding = $tenantStats['total_outstanding'];
$overdueTenantsCount = $tenantStats['overdue_count'];

// Batched Rooms Aggregate
$totalRooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();

// Batched Payments Aggregate
$paymentStats = $pdo->query("SELECT 
    COALESCE(SUM(CASE WHEN status = 'verified' THEN amount ELSE 0 END), 0) as total_collected,
    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as total_pending,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
    FROM payments")->fetch();

$totalCollected = $paymentStats['total_collected'];
$totalPending = $paymentStats['total_pending'];
$pendingPaymentsCount = $paymentStats['pending_count'];

// Rooms Capacity & Occupancy Analysis
$roomsData = $pdo->query("
    SELECT r.id, r.room_number, r.capacity, 
           (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id) as tenant_count
    FROM rooms r
    ORDER BY r.room_number ASC
")->fetchAll();

$availableSpaces = 0;
$totalCapacity = 0;
$occupiedRooms = 0;
$availableRooms = 0;
$fullRooms = 0;
$partialRooms = 0;

foreach($roomsData as $r) {
    $totalCapacity += $r['capacity'];
    $space = $r['capacity'] - $r['tenant_count'];
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

$actualOccupied = $totalCapacity - $availableSpaces;
$occupancyRate = $totalCapacity > 0 ? round(($actualOccupied / $totalCapacity) * 100) : 0;
$roomsWithSpaceCount = $availableRooms + $partialRooms;

// Fetch recent payments (verified or not)
$recentPayments = $pdo->query("
    SELECT p.*, t.first_name, t.last_name, r.room_number
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY p.payment_date DESC
    LIMIT 6
")->fetchAll();

// Monthly Revenue Data for Charts (PostgreSQL)
$monthlyRevData = $pdo->query("
    SELECT TO_CHAR(payment_date, 'Mon') as month_name,
           EXTRACT(MONTH FROM payment_date) as month_num,
           EXTRACT(YEAR FROM payment_date) as year_num,
           SUM(amount) as total
    FROM payments
    WHERE status = 'verified'
    GROUP BY year_num, month_num, month_name
    ORDER BY year_num ASC, month_num ASC
    LIMIT 6
")->fetchAll();

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

// Trend placeholders
$tenantTrend = "↑ 2 this month";
$roomTrend = "No change";
$spaceTrend = "↑ 1 this month";
$balanceTrend = "↓ ₱2,150 from last month";

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
    
    .room-status-item { padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9; }
    .room-status-item:last-child { border-bottom: none; }
</style>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div>
        <h4 class="fw-bold mb-1 text-dark">Dashboard</h4>
        <p class="text-muted mb-0" style="font-size: 0.75rem;">Welcome back, Administrator! Here's what's happening today.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="tenants.php" class="btn btn-outline-primary bg-white text-primary fw-semibold btn-sm px-2 py-1 rounded-2" style="font-size:0.75rem;"><i class="fa-solid fa-plus me-1"></i> Add Tenant</a>
        <a href="rooms.php" class="btn btn-outline-primary bg-white text-primary fw-semibold btn-sm px-2 py-1 rounded-2" style="font-size:0.75rem;"><i class="fa-solid fa-plus me-1"></i> Add Room</a>
    </div>
</div>

<!-- Top Stats Row -->
<div class="row g-2 mb-3">
    <!-- Total Tenants -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-primary bg-opacity-10 text-primary me-2 flex-shrink-0">
                    <i class="fa-solid fa-user-group"></i>
                </div>
                <div>
                    <div class="card-title-sm">Total Tenants</div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $totalTenants ?></h5>
                    <div class="trend-text text-success mt-1"><?= $tenantTrend ?></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Total Rooms -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-info bg-opacity-10 text-info me-2 flex-shrink-0">
                    <i class="fa-solid fa-door-open"></i>
                </div>
                <div>
                    <div class="card-title-sm">Total Rooms</div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $totalRooms ?></h5>
                    <div class="trend-text text-muted mt-1"><?= $roomTrend ?></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Available Spaces -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-success bg-opacity-10 text-success me-2 flex-shrink-0">
                    <i class="fa-solid fa-bed"></i>
                </div>
                <div>
                    <div class="card-title-sm">Available Spaces</div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $availableSpaces ?></h5>
                    <div class="trend-text text-success mt-1"><?= $spaceTrend ?></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Outstanding Balance -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-warning bg-opacity-10 text-warning me-2 flex-shrink-0">
                    <i class="fa-solid fa-coins"></i>
                </div>
                <div>
                    <div class="card-title-sm">Total Unpaid Rent</div>
                    <h5 class="fw-bold mb-0 text-dark">₱<?= number_format($totalOutstanding, 0) ?></h5>
                    <div class="trend-text text-danger mt-1"><?= $balanceTrend ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

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
                    <select class="form-select form-select-sm w-auto shadow-none text-muted py-0 pe-4" style="font-size: 0.7rem;">
                        <option>This Month</option>
                        <option>Last Month</option>
                    </select>
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

<!-- Bottom Row -->
<div class="row g-2">
    <!-- Attention Required -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.8rem;"><i class="fa-solid fa-triangle-exclamation text-warning me-2"></i> Attention Required</h6>
                
                <?php if ($overdueTenantsCount > 0): ?>
                <div class="alert-box alert-danger-soft">
                    <div class="d-flex align-items-start">
                        <i class="fa-solid fa-circle-exclamation text-danger mt-1 me-2" style="font-size:0.7rem;"></i>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.7rem; line-height: 1.2;"><?= $overdueTenantsCount ?> tenants have overdue payments</div>
                            <a href="tenants.php" class="text-primary text-decoration-none fw-semibold" style="font-size: 0.65rem;">View Tenants &rarr;</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($pendingPaymentsCount > 0): ?>
                <div class="alert-box alert-warning-soft">
                    <div class="d-flex align-items-start">
                        <i class="fa-solid fa-clock text-warning mt-1 me-2" style="font-size:0.7rem;"></i>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.7rem; line-height: 1.2;"><?= $pendingPaymentsCount ?> payments require verification</div>
                            <a href="payments.php" class="text-primary text-decoration-none fw-semibold" style="font-size: 0.65rem;">View Payments &rarr;</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($roomsWithSpaceCount > 0): ?>
                <div class="alert-box alert-success-soft">
                    <div class="d-flex align-items-start">
                        <i class="fa-solid fa-door-open text-success mt-1 me-2" style="font-size:0.7rem;"></i>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.7rem; line-height: 1.2;"><?= $roomsWithSpaceCount ?> rooms have available spaces</div>
                            <a href="rooms.php" class="text-primary text-decoration-none fw-semibold" style="font-size: 0.65rem;">View Rooms &rarr;</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($overdueTenantsCount == 0 && $roomsWithSpaceCount == 0 && $pendingPaymentsCount == 0): ?>
                    <div class="text-center text-muted py-4" style="font-size: 0.75rem;">All caught up!</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Payments -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0" style="font-size:0.8rem;"><i class="fa-solid fa-money-bill text-primary me-2"></i> Recent Payments</h6>
                <a href="payments.php" class="text-primary text-decoration-none fw-semibold" style="font-size: 0.65rem;">View All</a>
            </div>
            <div class="card-body p-0 mt-2">
                <div class="table-responsive">
                    <table class="table table-hover table-compact mb-0 border-top w-100">
                        <thead class="bg-light text-muted">
                            <tr>
                                <th class="ps-3 border-0">Tenant</th>
                                <th class="border-0">Room</th>
                                <th class="border-0">Amount</th>
                                <th class="pe-3 border-0">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($recentPayments) === 0): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No recent payments.</td></tr>
                            <?php endif; ?>
                            
                            <?php foreach($recentPayments as $p): 
                                $statusBadge = $p['status'] == 'verified' ? '<span class="badge bg-success-subtle text-success border border-success-subtle px-1" style="font-size:0.6rem;">Paid</span>' : '<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-1" style="font-size:0.6rem;">Pending</span>';
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="d-flex align-items-center">
                                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($p['first_name'].' '.$p['last_name']) ?>&background=random&color=fff" class="rounded-circle me-2 shadow-sm" width="20" height="20">
                                        <span class="fw-semibold text-dark text-truncate d-inline-block" style="max-width:70px; font-size: 0.75rem;"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?></span>
                                    </div>
                                </td>
                                <td class="text-muted fw-semibold" style="font-size: 0.75rem;">Rm <?= htmlspecialchars($p['room_number']) ?></td>
                                <td class="fw-bold text-dark" style="font-size: 0.75rem;">₱<?= number_format($p['amount'], 0) ?></td>
                                <td class="pe-3"><?= $statusBadge ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white border-top p-2 text-center">
                <span class="text-muted" style="font-size: 0.65rem;">Showing 1 to <?= min(5, count($recentPayments)) ?> of <?= count($recentPayments) ?></span>
            </div>
        </div>
    </div>
    
    <!-- Rooms Status -->
    <div class="col-lg-4">
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
                    
                    if($r['tenant_count'] >= $r['capacity']) {
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
                <div class="room-status-item d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <div class="bg-<?= $iconColor ?> bg-opacity-10 text-<?= $iconColor ?> rounded d-flex justify-content-center align-items-center me-2" style="width: 32px; height: 32px; font-size: 0.8rem;">
                            <i class="fa-solid fa-door-open"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.75rem;">Room <?= htmlspecialchars($r['room_number']) ?></div>
                            <div class="text-muted" style="font-size: 0.65rem;"><?= $r['tenant_count'] ?> / <?= $r['capacity'] ?> tenants</div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <?= $statusBadge ?>
                        <i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.6rem;"></i>
                    </div>
                </div>
                <?php endforeach; ?>
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
