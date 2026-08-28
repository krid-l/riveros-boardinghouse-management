<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

// --- DATA FETCHING ---
// Totals
$totalTenants = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn() ?: 0;
$totalRooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn() ?: 0;

$roomsData = $pdo->query("
    SELECT r.id, r.room_number, r.capacity, 
           (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id) as tenant_count
    FROM rooms r
    ORDER BY r.room_number ASC
")->fetchAll();

$totalCapacity = 0;
$totalOccupied = 0;
$occupiedRooms = 0;
$availableRooms = 0;

foreach($roomsData as $r) {
    $totalCapacity += $r['capacity'];
    $totalOccupied += $r['tenant_count'];
    if($r['tenant_count'] > 0) $occupiedRooms++;
    else $availableRooms++;
}

$occupancyRate = $totalRooms > 0 ? ($occupiedRooms / $totalRooms) * 100 : 0;
$bedOccupancyRate = $totalCapacity > 0 ? ($totalOccupied / $totalCapacity) * 100 : 0;

$totalRevenue = $pdo->query("SELECT SUM(amount) FROM payments WHERE status = 'verified'")->fetchColumn() ?: 0;
$outstandingBalance = $pdo->query("SELECT SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) FROM tenants")->fetchColumn() ?: 0;
$totalTransactions = $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'verified'")->fetchColumn() ?: 0;

// Transactions (Overview)
$recentTransactions = $pdo->query("
    SELECT p.*, t.first_name, t.last_name, r.room_number 
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY p.payment_date DESC LIMIT 5
")->fetchAll();

// All Payments (For Payment Reports)
$allPayments = $pdo->query("
    SELECT p.*, t.first_name, t.last_name, r.room_number 
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY p.payment_date DESC
")->fetchAll();

// All Tenants (For Tenant Reports)
$allTenants = $pdo->query("
    SELECT t.*, r.room_number 
    FROM tenants t
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY t.first_name ASC
")->fetchAll();

// Top Paying Tenants
$topTenants = $pdo->query("
    SELECT t.first_name, t.last_name, r.room_number, SUM(p.amount) as total_paid
    FROM tenants t
    JOIN payments p ON t.id = p.tenant_id
    LEFT JOIN rooms r ON t.room_id = r.id
    WHERE p.status = 'verified'
    GROUP BY t.id, r.room_number
    ORDER BY total_paid DESC
    LIMIT 3
")->fetchAll();

// Monthly Revenue Data for Charts
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
$monthLineNames = [];
if (empty($monthlyRevData)) {
    $monthNames = ["No Data"];
    $monthLineNames = ["No Data"];
    $monthTotals = [0];
} else {
    foreach ($monthlyRevData as $row) {
        $monthNames[] = $row['month_name'];
        $monthLineNames[] = $row['month_name'] . ' ' . $row['year_num'];
        $monthTotals[] = $row['total'];
    }
}
$jsMonthNames = json_encode($monthNames);
$jsMonthLineNames = json_encode($monthLineNames);
$jsMonthTotals = json_encode($monthTotals);

require_once 'header.php';
?>

<style>
    body { background-color: #f8fafc; }
    
    /* Metrics & Cards */
    .metric-card { border: 1px solid #f1f5f9; background: #fff; border-radius: 8px; }
    .icon-box-lg { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
    .card-title-sm { font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 2px; }
    .trend-text { font-size: 0.6rem; font-weight: 600; }
    
    /* Tabs */
    .nav-tabs-custom { border-bottom: 1px solid #e2e8f0; display: flex; gap: 1.5rem; }
    .nav-tabs-custom .tab-item { 
        padding: 0.5rem 0; font-size: 0.7rem; font-weight: 600; color: #64748b; 
        cursor: pointer; border-bottom: 2px solid transparent; 
    }
    .nav-tabs-custom .tab-item.active { color: #0d6efd; border-bottom: 2px solid #0d6efd; }
    
    /* Charts */
    .chart-container-donut { position: relative; height: 140px; width: 140px; margin: 0 auto; }
    .donut-center-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
    .chart-container-bar { position: relative; height: 180px; width: 100%; }
    .chart-container-line { position: relative; height: 160px; width: 100%; }
    
    /* Tables */
    .table-compact th { font-size: 0.55rem; text-transform: uppercase; color: #64748b; font-weight: 600; padding: 0.6rem 0.5rem; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; }
    .table-compact td { font-size: 0.65rem; vertical-align: middle; padding: 0.5rem 0.5rem; border-bottom: 1px solid #f8fafc; }
    .table-compact tbody tr:hover { background-color: #f8fafc; }
    
    /* Helpers */
    .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; margin-right: 6px; }
    
    /* Shortcuts */
    .shortcut-card { border: 1px solid #f1f5f9; border-radius: 6px; padding: 0.6rem; margin-bottom: 0.4rem; display: flex; align-items: center; justify-content: space-between; background: #fff; cursor: pointer; transition: 0.2s; }
    .shortcut-card:hover { border-color: #cbd5e1; background: #f8fafc; }
    .shortcut-icon { width: 30px; height: 30px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: #0d6efd; font-size: 0.8rem; margin-right: 0.75rem; flex-shrink: 0; }
    
    /* Top Paying List */
    .top-tenant-row { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px dashed #f1f5f9; font-size: 0.65rem; }
    .top-tenant-row:last-child { border-bottom: none; }
    .number-badge { width: 20px; height: 20px; border-radius: 50%; background: #eff6ff; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 0.6rem; margin-right: 0.5rem; }
    
    /* File Icon */
    .file-icon { font-size: 1.25rem; color: #ef4444; }
    .file-icon.csv { color: #22c55e; }
</style>

<!-- Header -->
<div class="mb-3">
    <h4 class="fw-bold mb-1 text-dark">Reports & Analytics</h4>
    <p class="text-muted mb-0" style="font-size: 0.75rem;">Overview of your boarding house performance and insights.</p>
</div>

<!-- Tabs & Actions -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div class="nav-tabs-custom px-2 overflow-auto w-100" id="reportTabs">
        <div class="tab-item active" data-target="tab-overview">Overview</div>
        <div class="tab-item text-nowrap" data-target="tab-financial">Financial Reports</div>
        <div class="tab-item text-nowrap" data-target="tab-occupancy">Occupancy Reports</div>
        <div class="tab-item text-nowrap" data-target="tab-tenant">Tenant Reports</div>
        <div class="tab-item text-nowrap" data-target="tab-payment">Payment Reports</div>
        <div class="tab-item text-nowrap" data-target="tab-exported">Exported Reports</div>
    </div>
    
    <div class="d-flex gap-2 align-items-center flex-shrink-0">
        <div class="input-group input-group-sm rounded-2 border bg-white" style="width:180px;">
            <input type="text" class="form-control border-0 shadow-none px-2 fw-semibold text-dark" value="Aug 1 - Aug 31, 2025" style="font-size:0.65rem;" readonly>
            <span class="input-group-text bg-transparent border-0 pe-2"><i class="fa-regular fa-calendar text-muted" style="font-size:0.65rem;"></i></span>
        </div>
        <button class="btn btn-primary fw-semibold btn-sm px-3 py-1 rounded-2 shadow-sm text-nowrap" style="font-size:0.7rem;"><i class="fa-solid fa-download me-1"></i> Export Report</button>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: OVERVIEW (Default) -->
<!-- ============================================== -->
<div id="tab-overview" class="tab-pane">
    <!-- Top KPIs -->
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-2 mb-3">
        <div class="col"><div class="metric-card h-100 p-2 d-flex align-items-center shadow-sm"><div class="icon-box-lg bg-primary bg-opacity-10 text-primary me-2 flex-shrink-0"><i class="fa-solid fa-user-group"></i></div><div><div class="card-title-sm">Total Tenants</div><h5 class="fw-bold mb-0 text-dark"><?= $totalTenants ?></h5><div class="trend-text text-success mt-1">↑ 2 from last month</div></div></div></div>
        <div class="col"><div class="metric-card h-100 p-2 d-flex align-items-center shadow-sm"><div class="icon-box-lg bg-info bg-opacity-10 text-info me-2 flex-shrink-0"><i class="fa-solid fa-door-open"></i></div><div><div class="card-title-sm">Total Rooms</div><h5 class="fw-bold mb-0 text-dark"><?= $totalRooms ?></h5><div class="trend-text text-muted mt-1">No change</div></div></div></div>
        <div class="col"><div class="metric-card h-100 p-2 d-flex align-items-center shadow-sm"><div class="icon-box-lg bg-success bg-opacity-10 text-success me-2 flex-shrink-0"><i class="fa-solid fa-bed"></i></div><div><div class="card-title-sm">Bed Occupancy Rate</div><h5 class="fw-bold mb-0 text-dark"><?= number_format($bedOccupancyRate, 1) ?>%</h5><div class="trend-text text-success mt-1">↑ 3.4% from last month</div></div></div></div>
        <div class="col"><div class="metric-card h-100 p-2 d-flex align-items-center shadow-sm"><div class="icon-box-lg bg-primary bg-opacity-10 text-primary me-2 flex-shrink-0"><i class="fa-solid fa-wallet"></i></div><div><div class="card-title-sm">Total Revenue</div><h5 class="fw-bold mb-0 text-dark">₱<?= number_format($totalRevenue, 2) ?></h5><div class="trend-text text-success mt-1">↑ 12.5% from last month</div></div></div></div>
        <div class="col"><div class="metric-card h-100 p-2 d-flex align-items-center shadow-sm"><div class="icon-box-lg bg-warning bg-opacity-10 text-warning me-2 flex-shrink-0"><i class="fa-solid fa-chart-pie"></i></div><div><div class="card-title-sm">Total Unpaid Rent</div><h5 class="fw-bold mb-0 text-danger">₱<?= number_format($outstandingBalance, 2) ?></h5><div class="trend-text text-danger mt-1">↑ 5.2% from last month</div></div></div></div>
    </div>

    <!-- Middle Row 1: Charts Overview -->
    <div class="row g-2 mb-3">
        <!-- Room Occupancy Donut -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body p-3">
                    <h6 class="fw-bold text-dark mb-3" style="font-size:0.75rem;">Bed Occupancy Overview</h6>
                    
                    <div class="row align-items-center mb-3">
                        <div class="col-6">
                            <div class="chart-container-donut">
                                <canvas id="occupancyDonut"></canvas>
                                <div class="donut-center-text">
                                    <div class="fw-bold text-dark" style="font-size:1.1rem; line-height:1;"><?= $totalOccupied ?></div>
                                    <div class="text-muted" style="font-size:0.5rem;">Occupied</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold text-dark" style="font-size:0.6rem;"><span class="dot bg-success"></span> Occupied</span>
                                <span class="text-muted" style="font-size:0.55rem;"><?= $totalOccupied ?> (<?= number_format($bedOccupancyRate,1) ?>%)</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold text-dark" style="font-size:0.6rem;"><span class="dot bg-primary"></span> Available</span>
                                <span class="text-muted" style="font-size:0.55rem;"><?= $totalCapacity - $totalOccupied ?> (<?= number_format(100 - $bedOccupancyRate,1) ?>%)</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-dark" style="font-size:0.6rem;"><span class="dot bg-warning"></span> Maintenance</span>
                                <span class="text-muted" style="font-size:0.55rem;">0 (0%)</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between text-center mt-3 pt-3 border-top">
                        <div><div class="fw-bold text-dark" style="font-size:0.75rem;"><?= $totalCapacity ?></div><div class="text-muted" style="font-size:0.5rem; text-transform:uppercase; font-weight:600;">Total Beds</div></div>
                        <div><div class="fw-bold text-dark" style="font-size:0.75rem;"><?= $totalOccupied ?></div><div class="text-muted" style="font-size:0.5rem; text-transform:uppercase; font-weight:600;">Occupied</div></div>
                        <div><div class="fw-bold text-dark" style="font-size:0.75rem;"><?= $totalCapacity - $totalOccupied ?></div><div class="text-muted" style="font-size:0.5rem; text-transform:uppercase; font-weight:600;">Available</div></div>
                        <div><div class="fw-bold text-dark" style="font-size:0.75rem;">0</div><div class="text-muted" style="font-size:0.5rem; text-transform:uppercase; font-weight:600;">Maintenance</div></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Revenue Bar & Stats -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body p-3">
                    <div class="row h-100">
                        <div class="col-md-7 border-end border-light">
                            <h6 class="fw-bold text-dark mb-3" style="font-size:0.75rem;">Revenue Overview</h6>
                            <div class="chart-container-bar"><canvas id="revenueBar"></canvas></div>
                        </div>
                        <div class="col-md-5 d-flex flex-column justify-content-center px-3">
                            <div class="text-dark fw-bold mb-3" style="font-size:0.65rem;">This Month (<?= date('M 1 - M t, Y') ?>)</div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-dark fw-semibold" style="font-size:0.65rem;">Expected Revenue</span>
                                <span class="fw-bold text-primary" style="font-size:0.65rem;">₱<?= number_format($totalRevenue + $outstandingBalance, 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-dark fw-semibold" style="font-size:0.65rem;">Total Payments Collected</span>
                                <span class="fw-bold text-success" style="font-size:0.65rem;">₱<?= number_format($totalRevenue, 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-dark fw-semibold" style="font-size:0.65rem;">Total Unpaid Rent</span>
                                <span class="fw-bold text-danger" style="font-size:0.65rem;">₱<?= number_format($outstandingBalance, 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-dark fw-semibold" style="font-size:0.65rem;">Total Transactions</span>
                                <span class="fw-bold text-dark" style="font-size:0.7rem;"><?= $totalTransactions ?></span>
                            </div>
                            <div class="text-center mt-2">
                                <a href="#" class="text-primary text-decoration-none fw-semibold" style="font-size:0.65rem;" onclick="document.querySelector('[data-target=tab-financial]').click();">View Financial Report &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Middle Row 2: Occupancy, Transactions, Shortcuts -->
    <div class="row g-2 mb-3">
        <!-- Occupancy by Room -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white border-0 p-3 pb-0"><h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Occupancy by Room</h6></div>
                <div class="card-body p-0 mt-2 overflow-auto">
                    <table class="table table-compact mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 border-0">Room</th>
                                <th class="border-0 text-center">Capacity</th>
                                <th class="border-0 text-center">Occupied</th>
                                <th class="border-0 text-end pe-3">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach(array_slice($roomsData, 0, 5) as $r): 
                                $rate = $r['capacity'] > 0 ? round(($r['tenant_count'] / $r['capacity']) * 100) : 0;
                                $rateColor = $rate == 100 ? 'success' : ($rate >= 50 ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td class="ps-3 fw-bold text-dark">Room <?= htmlspecialchars($r['room_number']) ?></td>
                                <td class="text-center text-muted"><?= $r['capacity'] ?></td>
                                <td class="text-center text-muted"><?= $r['tenant_count'] ?></td>
                                <td class="text-end pe-3"><span class="badge bg-<?= $rateColor ?>-subtle text-<?= $rateColor ?> rounded-pill px-2" style="font-size:0.55rem;"><?= $rate ?>%</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white border-top p-2 text-center">
                    <a href="#" class="text-primary text-decoration-none fw-semibold" style="font-size:0.6rem;" onclick="document.querySelector('[data-target=tab-occupancy]').click();">View Occupancy Report &rarr;</a>
                </div>
            </div>
        </div>
        
        <!-- Recent Transactions -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Recent Transactions</h6>
                    <a href="payments.php" class="text-primary text-decoration-none fw-semibold" style="font-size: 0.6rem;">View All</a>
                </div>
                <div class="card-body p-0 mt-2 overflow-auto">
                    <table class="table table-compact mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3 border-0">Date</th><th class="border-0">Type</th><th class="border-0">Description</th><th class="border-0">Amount</th><th class="border-0 text-center pe-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (empty($recentTransactions)) {
                                echo '<tr><td colspan="5" class="text-center py-4 text-muted">No transactions recorded.</td></tr>';
                            } else {
                                foreach($recentTransactions as $p): 
                                    $isVerified = $p['status'] == 'verified';
                                    $sClass = $isVerified ? 'success' : 'warning';
                                    $sText = $isVerified ? 'Paid' : 'Pending';
                                ?>
                                <tr>
                                    <td class="ps-3 text-dark fw-semibold" style="font-size:0.6rem;"><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                                    <td class="text-muted" style="font-size:0.6rem;">Payment</td>
                                    <td class="text-dark fw-semibold" style="font-size:0.6rem;"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?> (Rm <?= htmlspecialchars($p['room_number'] ?? '?') ?>)</td>
                                    <td class="text-dark fw-bold" style="font-size:0.6rem;">₱<?= number_format($p['amount'], 2) ?></td>
                                    <td class="text-center pe-3"><span class="badge bg-<?= $sClass ?>-subtle text-<?= $sClass ?> rounded-pill px-2" style="font-size:0.55rem;"><?= $sText ?></span></td>
                                </tr>
                                <?php endforeach; 
                            } ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white border-top p-2 text-center">
                    <a href="#" class="text-primary text-decoration-none fw-semibold" style="font-size:0.6rem;" onclick="document.querySelector('[data-target=tab-payment]').click();">View All Transactions &rarr;</a>
                </div>
            </div>
        </div>
        
        <!-- Reports Shortcuts -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body p-3">
                    <h6 class="fw-bold text-dark mb-3" style="font-size:0.75rem;">Reports Shortcuts</h6>
                    
                    <div class="shortcut-card" onclick="document.querySelector('[data-target=tab-financial]').click();">
                        <div class="d-flex align-items-center"><div class="shortcut-icon"><i class="fa-solid fa-chart-line"></i></div><div><div class="fw-bold text-primary" style="font-size:0.65rem;">Financial Reports</div><div class="text-muted" style="font-size:0.55rem;">View revenue, payments and expenses</div></div></div><i class="fa-solid fa-chevron-right text-muted" style="font-size:0.6rem;"></i>
                    </div>
                    
                    <div class="shortcut-card" onclick="document.querySelector('[data-target=tab-occupancy]').click();">
                        <div class="d-flex align-items-center"><div class="shortcut-icon"><i class="fa-solid fa-bed"></i></div><div><div class="fw-bold text-primary" style="font-size:0.65rem;">Occupancy Reports</div><div class="text-muted" style="font-size:0.55rem;">Analyze room occupancy and status</div></div></div><i class="fa-solid fa-chevron-right text-muted" style="font-size:0.6rem;"></i>
                    </div>
                    
                    <div class="shortcut-card" onclick="document.querySelector('[data-target=tab-tenant]').click();">
                        <div class="d-flex align-items-center"><div class="shortcut-icon"><i class="fa-solid fa-users"></i></div><div><div class="fw-bold text-primary" style="font-size:0.65rem;">Tenant Reports</div><div class="text-muted" style="font-size:0.55rem;">View tenant summary and analytics</div></div></div><i class="fa-solid fa-chevron-right text-muted" style="font-size:0.6rem;"></i>
                    </div>
                    
                    <div class="shortcut-card mb-0" onclick="document.querySelector('[data-target=tab-payment]').click();">
                        <div class="d-flex align-items-center"><div class="shortcut-icon"><i class="fa-regular fa-file-lines"></i></div><div><div class="fw-bold text-primary" style="font-size:0.65rem;">Payment Reports</div><div class="text-muted" style="font-size:0.55rem;">Track payments and outstanding balances</div></div></div><i class="fa-solid fa-chevron-right text-muted" style="font-size:0.6rem;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Row: Line Chart & Top Tenants -->
    <div class="row g-2">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Monthly Revenue Trend</h6>
                        <select class="form-select form-select-sm w-auto shadow-none text-muted py-0 pe-4" style="font-size: 0.65rem;"><option>Last 6 Months</option><option>This Year</option></select>
                    </div>
                    <div class="chart-container-line"><canvas id="revenueLine"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body p-3">
                    <h6 class="fw-bold text-dark mb-3" style="font-size:0.75rem;">Top Paying Tenants (This Month)</h6>
                    <div>
                        <?php 
                        if (empty($topTenants)) {
                            echo '<div class="text-muted text-center py-4" style="font-size:0.65rem;">No verified payments yet.</div>';
                        } else {
                            foreach ($topTenants as $idx => $t) {
                                echo '<div class="top-tenant-row"><div class="d-flex align-items-center"><div class="number-badge">'.($idx+1).'</div><span class="text-dark fw-semibold">'.htmlspecialchars($t['first_name'].' '.$t['last_name']).' (Room '.$t['room_number'].')</span></div><span class="text-dark fw-bold">₱'.number_format($t['total_paid'], 2).'</span></div>';
                            }
                        }
                        ?>
                    </div>
                    <div class="text-center mt-3 pt-2">
                        <a href="#" class="text-primary text-decoration-none fw-semibold" style="font-size:0.6rem;" onclick="document.querySelector('[data-target=tab-tenant]').click();">View Full Report &rarr;</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: FINANCIAL REPORTS -->
<!-- ============================================== -->
<div id="tab-financial" class="tab-pane d-none">
    <div class="row g-2 mb-3">
        <div class="col-md-4"><div class="metric-card p-3 shadow-sm text-center"><div class="card-title-sm">Total Collected</div><h4 class="fw-bold text-success mb-0">₱<?= number_format($totalRevenue, 2) ?></h4></div></div>
        <div class="col-md-4"><div class="metric-card p-3 shadow-sm text-center"><div class="card-title-sm">Total Deficit (Unpaid)</div><h4 class="fw-bold text-danger mb-0">₱<?= number_format($outstandingBalance, 2) ?></h4></div></div>
        <div class="col-md-4"><div class="metric-card p-3 shadow-sm text-center"><div class="card-title-sm">Projected Revenue</div><h4 class="fw-bold text-primary mb-0">₱<?= number_format($totalRevenue + $outstandingBalance, 2) ?></h4></div></div>
    </div>
    
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 p-3 pb-0"><h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Income & Ledger Report</h6></div>
        <div class="card-body p-0 mt-2">
            <table class="table table-compact mb-0">
                <thead class="bg-light">
                    <tr><th class="ps-3 border-0">Date</th><th class="border-0">Reference</th><th class="border-0">Description</th><th class="border-0 text-end pe-3">Credit (Amount)</th></tr>
                </thead>
                <tbody>
                    <?php if(empty($allPayments)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">No financial records found.</td></tr>
                    <?php else: ?>
                        <?php foreach($allPayments as $p): if($p['status']!='verified') continue; ?>
                        <tr>
                            <td class="ps-3 text-dark fw-semibold" style="font-size:0.65rem;"><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                            <td class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($p['reference_number'] ?: 'MANUAL-'.str_pad($p['id'], 5, '0', STR_PAD_LEFT)) ?></td>
                            <td class="text-dark fw-semibold" style="font-size:0.65rem;">Rent Payment - <?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?></td>
                            <td class="text-success fw-bold text-end pe-3" style="font-size:0.65rem;">+ ₱<?= number_format($p['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: OCCUPANCY REPORTS -->
<!-- ============================================== -->
<div id="tab-occupancy" class="tab-pane d-none">
    <div class="row g-2 mb-3">
        <div class="col-md-4"><div class="metric-card p-3 shadow-sm text-center"><div class="card-title-sm">Total Bed Capacity</div><h4 class="fw-bold text-dark mb-0"><?= $totalCapacity ?></h4></div></div>
        <div class="col-md-4"><div class="metric-card p-3 shadow-sm text-center"><div class="card-title-sm">Occupied Beds</div><h4 class="fw-bold text-primary mb-0"><?= $totalOccupied ?></h4></div></div>
        <div class="col-md-4"><div class="metric-card p-3 shadow-sm text-center"><div class="card-title-sm">Overall Bed Occupancy</div><h4 class="fw-bold text-success mb-0"><?= number_format($bedOccupancyRate, 1) ?>%</h4></div></div>
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 p-3 pb-0"><h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Detailed Room Utilization</h6></div>
        <div class="card-body p-0 mt-2">
            <table class="table table-compact mb-0">
                <thead class="bg-light">
                    <tr><th class="ps-3 border-0">Room Number</th><th class="border-0 text-center">Max Capacity</th><th class="border-0 text-center">Current Occupants</th><th class="border-0 text-center">Vacant Spaces</th><th class="border-0 text-end pe-3">Utilization</th></tr>
                </thead>
                <tbody>
                    <?php foreach($roomsData as $r): 
                        $rate = $r['capacity'] > 0 ? round(($r['tenant_count'] / $r['capacity']) * 100) : 0;
                        $rateColor = $rate == 100 ? 'success' : ($rate >= 50 ? 'warning' : 'danger');
                        $vacant = $r['capacity'] - $r['tenant_count'];
                    ?>
                    <tr>
                        <td class="ps-3 fw-bold text-dark">Room <?= htmlspecialchars($r['room_number']) ?></td>
                        <td class="text-center text-muted"><?= $r['capacity'] ?> Beds</td>
                        <td class="text-center text-dark fw-semibold"><?= $r['tenant_count'] ?> Tenants</td>
                        <td class="text-center <?= $vacant > 0 ? 'text-success fw-bold' : 'text-muted' ?>"><?= $vacant ?> Available</td>
                        <td class="text-end pe-3"><span class="badge bg-<?= $rateColor ?>-subtle text-<?= $rateColor ?> rounded-pill px-2" style="font-size:0.55rem;"><?= $rate ?>% Full</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: TENANT REPORTS -->
<!-- ============================================== -->
<div id="tab-tenant" class="tab-pane d-none">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between">
            <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Tenant Directory & Status Report</h6>
            <span class="badge bg-primary rounded-pill px-3"><?= count($allTenants) ?> Active Tenants</span>
        </div>
        <div class="card-body p-0 mt-2">
            <table class="table table-compact mb-0">
                <thead class="bg-light">
                    <tr><th class="ps-3 border-0">Tenant Name</th><th class="border-0">Contact Number</th><th class="border-0">Assigned Room</th><th class="border-0 text-center">Standing</th><th class="border-0 text-end pe-3">Unpaid Rent</th></tr>
                </thead>
                <tbody>
                    <?php foreach($allTenants as $t): 
                        $hasBalance = ($t['balance'] ?? 0) > 0;
                        $statBadge = $hasBalance ? '<span class="badge bg-warning-subtle text-warning">Delinquent</span>' : '<span class="badge bg-success-subtle text-success">Good Standing</span>';
                    ?>
                    <tr>
                        <td class="ps-3 fw-bold text-dark" style="font-size:0.65rem;"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></td>
                        <td class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($t['contact_number']) ?></td>
                        <td class="text-dark fw-semibold" style="font-size:0.65rem;">Room <?= htmlspecialchars($t['room_number'] ?? 'None') ?></td>
                        <td class="text-center"><?= $statBadge ?></td>
                        <td class="text-end pe-3 fw-bold <?= $hasBalance ? 'text-danger' : 'text-success' ?>" style="font-size:0.65rem;">₱<?= number_format($t['balance'] ?? 0, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: PAYMENT REPORTS -->
<!-- ============================================== -->
<div id="tab-payment" class="tab-pane d-none">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 p-3 pb-0">
            <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Comprehensive Payment Log</h6>
        </div>
        <div class="card-body p-0 mt-2">
            <table class="table table-compact mb-0">
                <thead class="bg-light">
                    <tr><th class="ps-3 border-0">Date Logged</th><th class="border-0">Tenant</th><th class="border-0">Reference No</th><th class="border-0 text-center">Status</th><th class="border-0 text-end pe-3">Amount Processed</th></tr>
                </thead>
                <tbody>
                    <?php foreach($allPayments as $p): 
                        $isVerified = $p['status'] == 'verified';
                        $sClass = $isVerified ? 'success' : 'warning';
                        $sText = $isVerified ? 'Verified' : 'Pending Verification';
                    ?>
                    <tr>
                        <td class="ps-3 text-dark fw-semibold" style="font-size:0.65rem;"><?= date('M d, Y h:i A', strtotime($p['created_at'] ?? $p['payment_date'])) ?></td>
                        <td class="text-dark fw-semibold" style="font-size:0.65rem;"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?> (Rm <?= htmlspecialchars($p['room_number'] ?? '?') ?>)</td>
                        <td class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($p['reference_number'] ?: 'N/A') ?></td>
                        <td class="text-center"><span class="badge bg-<?= $sClass ?>-subtle text-<?= $sClass ?> rounded-pill px-2" style="font-size:0.55rem;"><?= $sText ?></span></td>
                        <td class="text-end pe-3 fw-bold text-dark" style="font-size:0.65rem;">₱<?= number_format($p['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: EXPORTED REPORTS -->
<!-- ============================================== -->
<div id="tab-exported" class="tab-pane d-none">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 p-3 pb-0">
            <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Generated File Archives</h6>
        </div>
        <div class="card-body p-0 mt-2">
            <table class="table table-compact mb-0">
                <thead class="bg-light">
                    <tr><th class="ps-3 border-0">File Name</th><th class="border-0">Type</th><th class="border-0">Date Generated</th><th class="border-0 text-end pe-3">Action</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4" style="font-size:0.75rem;">No historical exports available yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<script>
Chart.register(ChartDataLabels);

document.addEventListener("DOMContentLoaded", function() {
    
    // TAB SWITCHING LOGIC
    const tabs = document.querySelectorAll('#reportTabs .tab-item');
    const panes = document.querySelectorAll('.tab-pane');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove active class from all tabs
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Hide all panes
            panes.forEach(p => p.classList.add('d-none'));
            
            // Show target pane
            const targetId = this.getAttribute('data-target');
            document.getElementById(targetId).classList.remove('d-none');
        });
    });

    // 1. Occupancy Donut Chart
    const occCtx = document.getElementById('occupancyDonut').getContext('2d');
    new Chart(occCtx, {
        type: 'doughnut',
        data: {
            labels: ['Occupied', 'Available', 'Maintenance'],
            datasets: [{
                data: [<?= $totalOccupied ?: 0 ?>, <?= ($totalCapacity - $totalOccupied) ?: 0 ?>, 0],
                backgroundColor: ['#22c55e', '#0d6efd', '#f59e0b'],
                borderWidth: 0,
                cutout: '75%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true },
                datalabels: { display: false }
            }
        }
    });

    // 2. Revenue Bar Chart
    const barCtx = document.getElementById('revenueBar').getContext('2d');
    new Chart(barCtx, {
        type: 'bar',
        data: {
            labels: <?= $jsMonthNames ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= $jsMonthTotals ?>,
                backgroundColor: '#3b82f6',
                borderRadius: 2,
                barThickness: 12
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100000,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 9 }, stepSize: 20000, callback: function(val) { return '₱' + (val/1000) + 'k'; } }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 10 } }
                }
            },
            plugins: { 
                legend: { display: false },
                datalabels: { display: false }
            }
        }
    });
    
    // 3. Monthly Revenue Trend Line Chart
    const lineCtx = document.getElementById('revenueLine').getContext('2d');
    new Chart(lineCtx, {
        type: 'line',
        data: {
            labels: <?= $jsMonthLineNames ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= $jsMonthTotals ?>,
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                borderWidth: 2,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#22c55e',
                pointBorderWidth: 2,
                pointRadius: 4,
                fill: true,
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: { top: 20 }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100000,
                    grid: { color: '#f1f5f9', drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 9 }, stepSize: 25000, callback: function(val) { return '₱' + (val/1000) + 'k'; } }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 10 } }
                }
            },
            plugins: { 
                legend: { display: false },
                datalabels: {
                    align: 'top',
                    color: '#1e293b',
                    font: { weight: 'bold', size: 9 },
                    formatter: function(value) { return '₱' + value.toLocaleString(); }
                }
            }
        }
    });

});
</script>

<?php require_once 'footer.php'; ?>
