<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
require_once '../includes/pagination.php';
require_once '../includes/ui.php';
require_once '../includes/reports.php';
requireAdmin();

// --- DATA FETCHING ---
// Totals
$totalTenants = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn() ?: 0;
$totalRooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn() ?: 0;

$roomsData = $pdo->query("
    SELECT r.id, r.room_number, r.capacity, r.status,
           (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id AND t.status = 'active') as tenant_count
    FROM rooms r
")->fetchAll();
usort($roomsData, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));   // 1, 2, 10 - not 1, 10, 2

$totalCapacity = 0;
$totalOccupied = 0;
$occupiedRooms = 0;
$availableRooms = 0;

$maintenanceBeds = 0;   // empty beds in rooms under maintenance: not available to anyone
foreach($roomsData as $r) {
    $totalCapacity += $r['capacity'];
    $totalOccupied += $r['tenant_count'];
    if ($r['status'] === 'maintenance') $maintenanceBeds += max(0, $r['capacity'] - $r['tenant_count']);
    if($r['tenant_count'] > 0) $occupiedRooms++;
    else $availableRooms++;
}

$occupancyRate = $totalRooms > 0 ? ($occupiedRooms / $totalRooms) * 100 : 0;
$bedOccupancyRate = $totalCapacity > 0 ? ($totalOccupied / $totalCapacity) * 100 : 0;
$availableBeds = max(0, $totalCapacity - $totalOccupied - $maintenanceBeds);
$pctOfBeds = fn($n) => $totalCapacity > 0 ? number_format($n / $totalCapacity * 100, 1) : '0.0';

// The Exported Reports tab: what was downloaded, newest first.
$exportPager = paginate((int)$pdo->query("SELECT COUNT(*) FROM report_exports")->fetchColumn(), 15, 'page_exports', ['tab' => 'exported']);
$exportRows = $pdo->query("SELECT * FROM report_exports ORDER BY created_at DESC, id DESC" . paginationLimitSql($exportPager))->fetchAll();

// Revenue excludes "covered by roommate" rows: that money is already in the room payment itself.
$totalRevenue = $pdo->query("SELECT SUM(amount) FROM payments WHERE " . REVENUE_FILTER_SQL)->fetchColumn() ?: 0;
$outstandingBalance = $pdo->query("SELECT SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) FROM tenants")->fetchColumn() ?: 0;
$totalTransactions = $pdo->query("SELECT COUNT(*) FROM payments WHERE " . REVENUE_FILTER_SQL)->fetchColumn() ?: 0;

// Recent Transactions (Overview): money received, newest first.
$recentTransactions = $pdo->query("
    SELECT p.*, t.first_name, t.last_name, r.room_number
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    WHERE " . revenueFilterSql('p') . "
    ORDER BY p.payment_date DESC, p.id DESC LIMIT 5
")->fetchAll();

// The three long report tables are paged in SQL. Each tab keeps its own page number, and
// the active tab travels in the URL so a page link doesn't bounce you back to Overview.
$activeTab = preg_match('/^[a-z]+$/', queryParam('tab')) ? queryParam('tab') : 'overview';

// Income & Ledger: money actually received.
$ledgerPager = paginate(
    (int)$pdo->query("SELECT COUNT(*) FROM payments p WHERE " . revenueFilterSql('p'))->fetchColumn(),
    15, 'page_ledger', ['tab' => 'financial']
);
$ledgerRows = $pdo->query("
    SELECT p.*, t.first_name, t.last_name, r.room_number
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    WHERE " . revenueFilterSql('p') . "
    ORDER BY p.payment_date DESC, p.id DESC" . paginationLimitSql($ledgerPager) . "
")->fetchAll();

// Comprehensive payment log: every payment row, verified or not.
$payLogPager = paginate((int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn(), 15, 'page_paylog', ['tab' => 'payment']);
$allPayments = $pdo->query("
    SELECT p.*, t.first_name, t.last_name, r.room_number
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY p.payment_date DESC, p.id DESC" . paginationLimitSql($payLogPager) . "
")->fetchAll();

// Tenant report.
$tenantPager = paginate((int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn(), 15, 'page_tenants', ['tab' => 'tenant']);
$allTenants = $pdo->query("
    SELECT t.*, r.room_number
    FROM tenants t
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY t.first_name ASC, t.id ASC" . paginationLimitSql($tenantPager) . "
")->fetchAll();

// Revenue Overview and Top Paying Tenants: one month, this month unless another is picked.
$monthChoices = revenueMonthChoices($pdo);
$revMonth = isset($monthChoices[queryParam('month')]) ? queryParam('month') : array_key_first($monthChoices);
$revMonthLabel = $revMonth === date('Y-m') ? 'This Month' : date('M Y', strtotime($revMonth . '-01'));
$revenue = monthRevenueSummary($pdo, $revMonth);

$topStmt = $pdo->prepare("
    SELECT t.id, t.first_name, t.last_name, r.room_number, SUM(p.amount) AS total_paid
    FROM payments p
    JOIN tenants t ON t.id = p.tenant_id
    LEFT JOIN rooms r ON r.id = t.room_id
    WHERE " . revenueFilterSql('p') . " AND p.payment_date BETWEEN ? AND ?
    GROUP BY t.id, t.first_name, t.last_name, r.room_number
    ORDER BY total_paid DESC, t.first_name
    LIMIT 5
");
$topStmt->execute([$revMonth . '-01', date('Y-m-t', strtotime($revMonth . '-01'))]);
$topTenants = $topStmt->fetchAll();

// Monthly Revenue Trend: the last 6 or 12 calendar months.
$trendMonths = queryParam('trend') === '12' ? 12 : 6;
$trend = monthlyRevenueTrend($pdo, $trendMonths);
$trendHasData = array_sum($trend) > 0;

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
    .chart-container-line { position: relative; height: 220px; width: 100%; }
    
    /* Tables */
    .table-compact th { font-size: 0.55rem; text-transform: uppercase; color: #64748b; font-weight: 600; padding: 0.6rem 0.5rem; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; }
    .table-compact td { font-size: 0.65rem; vertical-align: middle; padding: 0.5rem 0.5rem; border-bottom: 1px solid #f8fafc; }
    .table-compact tbody tr:hover { background-color: #f8fafc; }
    
    /* Helpers */
    .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; margin-right: 6px; }
    
    /* Shortcuts */
    .shortcut-card { border: 1px solid #eef2f7; border-radius: 8px; padding: 0.65rem 0.75rem; height: 100%; display: flex; align-items: center; justify-content: space-between; background: #fff; cursor: pointer; transition: 0.2s; }
    .shortcut-card:hover { border-color: #cbd5e1; background: #f8fafc; }
    .shortcut-icon { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: #0d6efd; font-size: 0.8rem; margin-right: 0.75rem; flex-shrink: 0; }
    
    /* File Icon */
    .file-icon { font-size: 1.25rem; color: #ef4444; }
    .file-icon.csv { color: #22c55e; }

    /* Overview cards (laid out like the reference) */
    .rpt-card { border: 1px solid #eef2f7 !important; display: flex; flex-direction: column; }
    .rpt-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; padding: 0.9rem 1rem 0.25rem; }
    .rpt-head h6 { display: flex; align-items: center; gap: 0.6rem; margin: 0; font-size: 0.9rem; font-weight: 700; color: #1e293b; }
    .rpt-icon { width: 32px; height: 32px; border-radius: 50%; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; }
    .rpt-select { font-size: 0.75rem; width: auto; max-width: 100%; padding: 0.3rem 2rem 0.3rem 0.75rem; background-position: right 0.6rem center; }
    .rpt-link { font-size: 0.75rem; font-weight: 600; color: #2563eb; text-decoration: none; }
    .rpt-link:hover { text-decoration: underline; }
    .rpt-table>thead>tr>th { font-size: 0.7rem; text-transform: none; letter-spacing: 0; padding: 0.55rem 0.5rem; background: #f8fafc; color: #475569; }
    .rpt-table>tbody>tr>td { font-size: 0.78rem; padding: 0.6rem 0.5rem; color: #475569; }
    .rpt-empty { text-align: center; color: #64748b; font-size: 0.75rem; background: #f8fafc; border-radius: 8px; padding: 2.5rem 1rem; }
    .rpt-empty > i { font-size: 1.3rem; color: #94a3b8; margin-bottom: 0.5rem; display: block; }
    .soft-pill { display: inline-block; font-size: 0.68rem; font-weight: 600; padding: 0.2em 0.7em; border-radius: 999px; white-space: nowrap; }

    .occ-box { border: 1px solid #eef2f7; border-radius: 8px; padding: 0.7rem 0.8rem; height: 100%; }
    .occ-label { font-size: 0.75rem; font-weight: 600; color: #1e293b; margin-bottom: 0.3rem; white-space: nowrap; }
    .occ-num { font-size: 1.35rem; font-weight: 700; color: #0f172a; }
    .occ-pct { font-size: 0.7rem; color: #64748b; }
    .occ-totals { display: grid; grid-template-columns: repeat(4, 1fr); border: 1px solid #eef2f7; border-radius: 8px; text-align: center; }
    .occ-totals > div { padding: 0.65rem 0.25rem; display: flex; flex-direction: column; gap: 0.15rem; }
    .occ-totals > div + div { border-left: 1px solid #eef2f7; }
    .occ-totals span { font-size: 0.7rem; color: #64748b; }
    .occ-totals strong { font-size: 1rem; color: #0f172a; }

    .rev-box { border: 1px solid #eef2f7; border-radius: 8px; padding: 0.75rem; height: 100%; display: flex; gap: 0.6rem; align-items: flex-start; }
    .rev-icon { width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; flex-shrink: 0; }
    .rev-label { font-size: 0.72rem; font-weight: 600; color: #1e293b; line-height: 1.25; }
    .rev-value { font-size: 1.05rem; font-weight: 700; color: #0f2a5c; white-space: nowrap; margin-top: 0.15rem; }
    .rev-sub { font-size: 0.65rem; color: #64748b; line-height: 1.25; margin-top: 0.15rem; }
    @media (max-width: 575.98px) {
        .occ-box { padding: 0.55rem; }
        .occ-num { font-size: 1.1rem; }
        .occ-label { font-size: 0.64rem; }
        .occ-label .dot { margin-right: 4px; }
        .rev-box { flex-direction: column; }
        .rpt-table>thead>tr>th, .rpt-table>tbody>tr>td { padding-left: 0.4rem; padding-right: 0.4rem; }
    }
</style>

<!-- Header -->
<div class="mb-3">
    <p class="text-muted mb-0" style="font-size: 0.8rem;">Overview of your boarding house performance and insights.</p>
</div>

<!-- Tabs & Actions -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div class="nav-tabs-custom px-2 overflow-auto w-100" id="reportTabs">
        <div class="tab-item <?= $activeTab === 'overview' ? 'active' : '' ?>" data-target="tab-overview">Overview</div>
        <div class="tab-item text-nowrap <?= $activeTab === 'financial' ? 'active' : '' ?>" data-target="tab-financial">Financial Reports</div>
        <div class="tab-item text-nowrap <?= $activeTab === 'occupancy' ? 'active' : '' ?>" data-target="tab-occupancy">Occupancy Reports</div>
        <div class="tab-item text-nowrap <?= $activeTab === 'tenant' ? 'active' : '' ?>" data-target="tab-tenant">Tenant Reports</div>
        <div class="tab-item text-nowrap <?= $activeTab === 'payment' ? 'active' : '' ?>" data-target="tab-payment">Payment Reports</div>
        <div class="tab-item text-nowrap <?= $activeTab === 'exported' ? 'active' : '' ?>" data-target="tab-exported">Exported Reports</div>
    </div>
    
    <div class="d-flex gap-2 align-items-center flex-shrink-0">
        <button type="button" class="btn btn-primary fw-semibold btn-sm px-3 py-1 rounded-2 shadow-sm text-nowrap" style="font-size:0.75rem;" data-bs-toggle="modal" data-bs-target="#exportModal"><i class="fa-solid fa-download me-1"></i> Export Report</button>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: OVERVIEW (Default) -->
<!-- ============================================== -->
<div id="tab-overview" class="tab-pane <?= $activeTab === 'overview' ? '' : 'd-none' ?>">
    <!-- Top KPIs: same cards as the other pages; each opens the report or list behind it -->
    <?= statCards([
        ['label' => 'Total Tenants', 'value' => (int)$totalTenants, 'sub' => 'Currently staying',
         'icon' => 'fa-solid fa-user-group', 'color' => 'primary', 'href' => 'reports.php?tab=tenant', 'aria' => 'Open the tenant report'],
        ['label' => 'Bed Occupancy Rate', 'value' => number_format($bedOccupancyRate, 1) . '%',
         'sub' => "$totalOccupied of $totalCapacity beds · $totalRooms " . ((int)$totalRooms === 1 ? 'room' : 'rooms'),
         'icon' => 'fa-solid fa-bed', 'color' => 'success', 'href' => 'reports.php?tab=occupancy', 'aria' => 'Open the occupancy report'],
        ['label' => 'Total Revenue', 'value' => peso((float)$totalRevenue), 'sub' => 'All time, verified payments', 'subClass' => 'text-success',
         'icon' => 'fa-solid fa-wallet', 'color' => 'info', 'href' => 'reports.php?tab=financial', 'aria' => 'Open the financial report'],
        ['label' => 'Total Unpaid Rent', 'value' => peso((float)$outstandingBalance), 'sub' => 'Incl. moved-out tenants', 'subClass' => $outstandingBalance > 0 ? 'text-danger' : 'text-muted',
         'icon' => 'fa-solid fa-coins', 'color' => 'warning', 'href' => 'tenants.php?status=unpaid', 'aria' => 'View tenants with unpaid rent'],
    ]) ?>

    <!-- Row 1: beds and this month's money -->
    <div class="row g-3 mb-3">
        <div class="col-xl-5">
            <div class="card rpt-card h-100">
                <div class="rpt-head">
                    <h6><span class="rpt-icon"><i class="fa-solid fa-bed"></i></span>Bed Occupancy Overview</h6>
                </div>
                <div class="p-3">
                    <div class="row g-2 mb-2">
                        <?php foreach ([['Occupied', $totalOccupied, 'success'], ['Available', $availableBeds, 'primary'], ['Maintenance', $maintenanceBeds, 'warning']] as [$label, $n, $color]): ?>
                        <div class="col-4">
                            <div class="occ-box">
                                <div class="occ-label"><span class="dot bg-<?= $color ?>"></span><?= $label ?></div>
                                <div><span class="occ-num"><?= (int)$n ?></span> <span class="occ-pct">(<?= $pctOfBeds($n) ?>%)</span></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="occ-totals">
                        <div><span>Total Beds</span><strong><?= (int)$totalCapacity ?></strong></div>
                        <div><span>Occupied</span><strong><?= (int)$totalOccupied ?></strong></div>
                        <div><span>Available</span><strong><?= (int)$availableBeds ?></strong></div>
                        <div><span>Maintenance</span><strong><?= (int)$maintenanceBeds ?></strong></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card rpt-card h-100">
                <div class="rpt-head">
                    <h6><span class="rpt-icon"><i class="fa-solid fa-chart-column"></i></span>Revenue Overview</h6>
                    <form method="GET" class="m-0">
                        <input type="hidden" name="tab" value="overview">
                        <?php if ($trendMonths !== 6): ?><input type="hidden" name="trend" value="<?= $trendMonths ?>"><?php endif; ?>
                        <select name="month" class="form-select form-select-sm rpt-select" aria-label="Month to show" onchange="this.form.submit()">
                            <?php foreach ($monthChoices as $m => $label): ?>
                            <option value="<?= $m ?>" <?= $m === $revMonth ? 'selected' : '' ?>><?= $m === date('Y-m') ? 'This Month (' . date('M j', strtotime($m . '-01')) . ' - ' . date('M t, Y', strtotime($m . '-01')) . ')' : $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="btn btn-sm btn-light border mt-1">Show</button></noscript>
                    </form>
                </div>
                <div class="p-3 d-flex flex-column flex-grow-1">
                    <div class="row g-2 row-cols-2 row-cols-lg-4 row-cols-xl-2">
                        <?php foreach ([
                            ['Expected Revenue', peso($revenue['expected']), 'fa-peso-sign', 'success', 'Rent and deposits billed'],
                            ['Total Payments Collected', peso($revenue['collected']), 'fa-receipt', 'primary', 'Verified, received ' . ($revMonth === date('Y-m') ? 'this month' : 'that month')],
                            ['Total Unpaid Rent', peso($revenue['unpaid']), 'fa-triangle-exclamation', 'danger', 'Still owed on these bills'],
                            ['Total Transactions', (string)$revenue['transactions'], 'fa-arrow-right-arrow-left', 'info', $revenue['transactions'] === 1 ? 'Verified payment' : 'Verified payments'],
                        ] as [$label, $value, $icon, $color, $sub]): ?>
                        <div class="col">
                            <div class="rev-box">
                                <span class="rev-icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="fa-solid <?= $icon ?>"></i></span>
                                <div style="min-width:0;">
                                    <div class="rev-label"><?= $label ?></div>
                                    <div class="rev-value"><?= $value ?></div>
                                    <div class="rev-sub"><?= $sub ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-end mt-auto pt-2">
                        <a href="reports.php?tab=financial" class="rpt-link">View Financial Report <i class="fa-solid fa-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 2: rooms, recent money, shortcuts -->
    <div class="row g-3 mb-3">
        <div class="col-lg-6 col-xxl-4">
            <div class="card rpt-card h-100">
                <div class="rpt-head"><h6><span class="rpt-icon"><i class="fa-solid fa-door-open"></i></span>Occupancy by Room</h6></div>
                <div class="table-responsive px-3">
                    <table class="table rpt-table mb-0">
                        <thead><tr><th>Room</th><th>Capacity</th><th>Occupied</th><th>Rate</th></tr></thead>
                        <tbody>
                            <?php if (!$roomsData): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No rooms yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach (array_slice($roomsData, 0, 6) as $r):
                                $rate = $r['capacity'] > 0 ? round($r['tenant_count'] / $r['capacity'] * 100) : 0; ?>
                            <tr>
                                <td class="fw-semibold text-dark text-nowrap">Room <?= htmlspecialchars($r['room_number'] ?? '') ?></td>
                                <td><?= (int)$r['capacity'] ?></td>
                                <td><?= (int)$r['tenant_count'] ?></td>
                                <td><?= $r['status'] === 'maintenance' ? '<span class="soft-pill bg-warning-subtle text-warning-emphasis">Maintenance</span>' : $rate . '%' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-3 mt-auto">
                    <a href="reports.php?tab=occupancy" class="rpt-link" onclick="document.querySelector('[data-target=tab-occupancy]').click(); return false;">View Occupancy Report<?= count($roomsData) > 6 ? ' (all ' . count($roomsData) . ' rooms)' : '' ?> <i class="fa-solid fa-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>

        <div class="col-lg-6 col-xxl-4">
            <div class="card rpt-card h-100">
                <div class="rpt-head"><h6><span class="rpt-icon"><i class="fa-regular fa-file-lines"></i></span>Recent Transactions</h6></div>
                <div class="table-responsive px-3">
                    <table class="table rpt-table mb-0">
                        <thead><tr><th>Date</th><th class="d-none d-sm-table-cell">Type</th><th>Description</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <?php if (!$recentTransactions): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No payments received yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($recentTransactions as $p): ?>
                            <tr>
                                <td class="text-nowrap"><?= date('M d, Y', strtotime($p['payment_date'])) ?></td>
                                <td class="d-none d-sm-table-cell"><?= ($p['reference_number'] ?? '') === 'MOVE-IN' ? 'Move-in' : 'Payment' ?></td>
                                <td><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?><?= $p['room_number'] ? ' (Rm ' . htmlspecialchars($p['room_number']) . ')' : '' ?></td>
                                <td class="text-end text-nowrap fw-semibold text-dark"><?= peso((float)$p['amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-3 mt-auto text-end">
                    <a href="reports.php?tab=payment" class="rpt-link" onclick="document.querySelector('[data-target=tab-payment]').click(); return false;">View All Transactions <i class="fa-solid fa-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>

        <div class="col-xxl-4">
            <div class="card rpt-card h-100">
                <div class="rpt-head"><h6><span class="rpt-icon"><i class="fa-solid fa-bolt"></i></span>Reports Shortcuts</h6></div>
                <div class="p-3 row g-2 row-cols-1 row-cols-md-2 row-cols-xxl-1 mx-0">
                    <?php foreach ([
                        ['financial', 'fa-chart-line', 'Financial Reports', 'View revenue and payments received'],
                        ['occupancy', 'fa-bed', 'Occupancy Reports', 'Analyze room occupancy and status'],
                        ['tenant', 'fa-users', 'Tenant Reports', 'View tenant summary and balances'],
                        ['payment', 'fa-file-invoice', 'Payment Reports', 'Track payments and outstanding balances'],
                    ] as [$tab, $icon, $title, $desc]): ?>
                    <div class="col"><a href="reports.php?tab=<?= $tab ?>" class="shortcut-card text-decoration-none" onclick="document.querySelector('[data-target=tab-<?= $tab ?>]').click(); return false;">
                        <span class="d-flex align-items-center" style="min-width:0;">
                            <span class="shortcut-icon"><i class="fa-solid <?= $icon ?>"></i></span>
                            <span style="min-width:0;"><span class="d-block fw-semibold text-dark" style="font-size:0.8rem;"><?= $title ?></span><span class="d-block text-muted" style="font-size:0.7rem;"><?= $desc ?></span></span>
                        </span>
                        <i class="fa-solid fa-chevron-right text-muted" style="font-size:0.65rem;"></i>
                    </a></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: trend and top payers -->
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card rpt-card h-100">
                <div class="rpt-head">
                    <h6><span class="rpt-icon"><i class="fa-solid fa-chart-simple"></i></span>Monthly Revenue Trend</h6>
                    <form method="GET" class="m-0">
                        <input type="hidden" name="tab" value="overview">
                        <?php if ($revMonth !== date('Y-m')): ?><input type="hidden" name="month" value="<?= $revMonth ?>"><?php endif; ?>
                        <select name="trend" class="form-select form-select-sm rpt-select" aria-label="Months to show" onchange="this.form.submit()">
                            <option value="6" <?= $trendMonths === 6 ? 'selected' : '' ?>>Last 6 Months</option>
                            <option value="12" <?= $trendMonths === 12 ? 'selected' : '' ?>>Last 12 Months</option>
                        </select>
                        <noscript><button type="submit" class="btn btn-sm btn-light border mt-1">Show</button></noscript>
                    </form>
                </div>
                <div class="p-3">
                    <?php if ($trendHasData): ?>
                        <div class="chart-container-line"><canvas id="revenueLine" aria-label="Revenue per month" role="img"></canvas></div>
                    <?php else: ?>
                        <div class="rpt-empty">
                            <i class="fa-solid fa-chart-simple"></i>
                            <div class="fw-semibold text-dark">No data available</div>
                            <div>There is no revenue data for the selected period.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card rpt-card h-100">
                <div class="rpt-head"><h6><span class="rpt-icon"><i class="fa-solid fa-trophy"></i></span>Top Paying Tenants (<?= $revMonthLabel ?>)</h6></div>
                <div class="table-responsive px-3">
                    <table class="table rpt-table mb-0">
                        <thead><tr><th style="width:2.5rem;">#</th><th>Tenant</th><th>Room</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            <?php if (!$topTenants): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No verified payments in <?= date('F Y', strtotime($revMonth . '-01')) ?>.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($topTenants as $i => $t): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><a href="tenant_details.php?id=<?= (int)$t['id'] ?>" class="text-dark text-decoration-none fw-semibold"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></a></td>
                                <td class="text-nowrap"><?= $t['room_number'] ? 'Room ' . htmlspecialchars($t['room_number']) : '—' ?></td>
                                <td class="text-end text-nowrap fw-semibold text-dark"><?= peso((float)$t['total_paid']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-3 mt-auto text-end">
                    <a href="reports.php?tab=financial" class="rpt-link" onclick="document.querySelector('[data-target=tab-financial]').click(); return false;">View Full Report <i class="fa-solid fa-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: FINANCIAL REPORTS -->
<!-- ============================================== -->
<div id="tab-financial" class="tab-pane <?= $activeTab === 'financial' ? '' : 'd-none' ?>">
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
                    <?php if(empty($ledgerRows)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">No financial records found.</td></tr>
                    <?php else: ?>
                        <?php foreach($ledgerRows as $p): ?>
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
            <div class="card-footer bg-white border-top p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted" style="font-size:0.65rem;"><?= paginationSummary($ledgerPager, 'entries') ?></span>
                <nav><ul class="pagination pagination-sm mb-0 shadow-sm" style="font-size:0.65rem;"><?= paginationControls($ledgerPager) ?></ul></nav>
            </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: OCCUPANCY REPORTS -->
<!-- ============================================== -->
<div id="tab-occupancy" class="tab-pane <?= $activeTab === 'occupancy' ? '' : 'd-none' ?>">
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
                        <td class="ps-3 fw-bold text-dark">Room <?= htmlspecialchars($r['room_number'] ?? '') ?></td>
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
<div id="tab-tenant" class="tab-pane <?= $activeTab === 'tenant' ? '' : 'd-none' ?>">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between">
            <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;">Tenant Directory & Status Report</h6>
            <span class="badge bg-primary rounded-pill px-3"><?= $totalTenants ?> Active Tenants</span>
        </div>
        <div class="card-body p-0 mt-2">
            <table class="table table-compact mb-0">
                <thead class="bg-light">
                    <tr><th class="ps-3 border-0">Tenant Name</th><th class="border-0">Contact Number</th><th class="border-0">Assigned Room</th><th class="border-0 text-center">Standing</th><th class="border-0 text-end pe-3">Unpaid Rent</th></tr>
                </thead>
                <tbody>
                    <?php $reportNotYetDue = chargesNotYetDue($pdo);
                    foreach($allTenants as $t):
                        $bs = tenantBillingStatus($t, $reportNotYetDue);
                        $hasBalance = $bs['balance'] > 0;
                        $statBadge = '<span class="badge bg-' . $bs['color'] . '-subtle text-' . $bs['color'] . '">' . ($bs['key'] === 'paid' ? 'Good Standing' : $bs['label']) . '</span>';
                    ?>
                    <tr>
                        <td class="ps-3 fw-bold text-dark" style="font-size:0.65rem;"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></td>
                        <td class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($t['contact_number'] ?? '') ?></td>
                        <td class="text-dark fw-semibold" style="font-size:0.65rem;">Room <?= htmlspecialchars($t['room_number'] ?? 'None') ?></td>
                        <td class="text-center"><?= $statBadge ?></td>
                        <td class="text-end pe-3 fw-bold <?= $hasBalance ? 'text-danger' : 'text-success' ?>" style="font-size:0.65rem;">₱<?= number_format($t['balance'] ?? 0, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
            <div class="card-footer bg-white border-top p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted" style="font-size:0.65rem;"><?= paginationSummary($tenantPager, 'tenants') ?></span>
                <nav><ul class="pagination pagination-sm mb-0 shadow-sm" style="font-size:0.65rem;"><?= paginationControls($tenantPager) ?></ul></nav>
            </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: PAYMENT REPORTS -->
<!-- ============================================== -->
<div id="tab-payment" class="tab-pane <?= $activeTab === 'payment' ? '' : 'd-none' ?>">
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
                        <td class="ps-3 text-dark fw-semibold" style="font-size:0.65rem;"><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                        <td class="text-dark fw-semibold" style="font-size:0.65rem;"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?> (Rm <?= htmlspecialchars($p['room_number'] ?? '?') ?>)</td>
                        <td class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($p['reference_number'] ?: 'N/A') ?></td>
                        <td class="text-center"><span class="badge bg-<?= $sClass ?>-subtle text-<?= $sClass ?> rounded-pill px-2" style="font-size:0.55rem;"><?= $sText ?></span></td>
                        <td class="text-end pe-3 fw-bold text-dark" style="font-size:0.65rem;">₱<?= number_format($p['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
            <div class="card-footer bg-white border-top p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted" style="font-size:0.65rem;"><?= paginationSummary($payLogPager, 'payments') ?></span>
                <nav><ul class="pagination pagination-sm mb-0 shadow-sm" style="font-size:0.65rem;"><?= paginationControls($payLogPager) ?></ul></nav>
            </div>
    </div>
</div>

<!-- ============================================== -->
<!-- TAB: EXPORTED REPORTS -->
<!-- ============================================== -->
<div id="tab-exported" class="tab-pane <?= $activeTab === 'exported' ? '' : 'd-none' ?>">
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="fw-bold text-dark mb-0" style="font-size:0.8rem;">Exported Reports</h6>
                <small class="text-muted" style="font-size:0.68rem;">Every report downloaded from this page. Download again rebuilds it from today's data.</small>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" style="font-size:0.72rem;" data-bs-toggle="modal" data-bs-target="#exportModal"><i class="fa-solid fa-plus me-1"></i>New export</button>
        </div>
        <div class="card-body p-0 mt-2">
            <div class="table-responsive">
            <table class="table table-compact mb-0">
                <thead class="bg-light">
                    <tr><th class="ps-3 border-0">Report</th><th class="border-0">Period</th><th class="border-0">Format</th><th class="border-0 text-end">Rows</th><th class="border-0">Exported</th><th class="border-0 text-end pe-3">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (!$exportRows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4" style="font-size:0.75rem;">Nothing exported yet. Use <strong>Export Report</strong> to download one.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($exportRows as $x):
                        $xType = REPORT_TYPES[$x['report_type']] ?? null;
                        if (!$xType) continue;
                        $again = 'export_report.php?' . http_build_query(array_filter(['type' => $x['report_type'], 'format' => $x['format'],
                                 'from' => $x['date_from'], 'to' => $x['date_to'], 'again' => 1]));
                    ?>
                    <tr>
                        <td class="ps-3 fw-semibold text-dark"><i class="fa-regular <?= $x['format'] === 'pdf' ? 'fa-file-pdf text-danger' : 'fa-file-excel text-success' ?> me-2"></i><?= htmlspecialchars($xType[0]) ?></td>
                        <td class="text-muted"><?= $x['date_from'] ? date('M j, Y', strtotime($x['date_from'])) . ' – ' . date('M j, Y', strtotime($x['date_to'])) : 'As of ' . date('M j, Y', strtotime($x['created_at'])) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= strtoupper($x['format']) ?></span></td>
                        <td class="text-end"><?= (int)$x['row_count'] ?></td>
                        <td class="text-muted text-nowrap"><?= date('M j, Y g:i A', strtotime($x['created_at'])) ?></td>
                        <td class="text-end pe-3"><a href="<?= htmlspecialchars($again) ?>" class="btn btn-sm btn-light border py-0 px-2" style="font-size:0.7rem;"><i class="fa-solid fa-download me-1"></i>Download again</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php if ($exportPager['totalPages'] > 1): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <small class="text-muted"><?= paginationSummary($exportPager, 'exports') ?></small>
            <ul class="pagination pagination-sm mb-0"><?= paginationControls($exportPager) ?></ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Export a report -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-labelledby="exportModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="GET" action="export_report.php" class="modal-content border-0 shadow" id="exportForm">
            <div class="modal-header bg-light border-0">
                <h6 class="modal-title fw-bold" id="exportModalTitle"><i class="fa-solid fa-file-export text-primary me-2"></i>Export Report</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body form-compact">
                <div class="mb-3">
                    <label for="exportType">Report</label>
                    <select name="type" id="exportType" class="form-select">
                        <?php foreach (REPORT_TYPES as $key => [$label, $range, $about]): ?>
                            <option value="<?= $key ?>" data-range="<?= $range ? 1 : 0 ?>" data-about="<?= htmlspecialchars($about) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="exportAbout" style="font-size:0.68rem;"></div>
                </div>
                <div id="exportRange">
                    <label>Period</label>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <?php
                        $presets = [
                            'This month' => [date('Y-m-01'), date('Y-m-d')],
                            'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
                            'This year'  => [date('Y-01-01'), date('Y-m-d')],
                            'All time'   => [$pdo->query("SELECT MIN(payment_date) FROM payments")->fetchColumn() ?: date('Y-m-01'), date('Y-m-d')],
                        ];
                        foreach ($presets as $label => [$pf, $pt]): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 export-preset" style="font-size:0.7rem;" data-from="<?= $pf ?>" data-to="<?= $pt ?>"><?= $label ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label for="exportFrom" class="text-muted fw-normal">From</label><input type="date" name="from" id="exportFrom" class="form-control" value="<?= date('Y-m-01') ?>" max="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6"><label for="exportTo" class="text-muted fw-normal">To</label><input type="date" name="to" id="exportTo" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
                    </div>
                </div>
                <label class="d-block">Format</label>
                <div class="btn-group w-100" role="group" aria-label="Format">
                    <input type="radio" class="btn-check" name="format" id="formatCsv" value="csv" checked>
                    <label class="btn btn-outline-success btn-sm" for="formatCsv"><i class="fa-regular fa-file-excel me-1"></i>Excel (CSV)</label>
                    <input type="radio" class="btn-check" name="format" id="formatPdf" value="pdf">
                    <label class="btn btn-outline-danger btn-sm" for="formatPdf"><i class="fa-regular fa-file-pdf me-1"></i>PDF</label>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa-solid fa-download me-1"></i>Download</button>
            </div>
        </form>
    </div>
</div>

<script>
// Export pop-up: the period only matters for some reports; it opens on the report of the open tab.
document.addEventListener('DOMContentLoaded', function () {
    const type = document.getElementById('exportType');
    const range = document.getElementById('exportRange');
    const about = document.getElementById('exportAbout');
    const sync = () => {
        const opt = type.selectedOptions[0];
        range.classList.toggle('d-none', opt.dataset.range !== '1');
        range.querySelectorAll('input').forEach(i => i.disabled = opt.dataset.range !== '1');
        about.textContent = opt.dataset.about;
    };
    type.addEventListener('change', sync);
    document.querySelectorAll('.export-preset').forEach(b => b.addEventListener('click', () => {
        document.getElementById('exportFrom').value = b.dataset.from;
        document.getElementById('exportTo').value = b.dataset.to;
    }));
    const tabToReport = { 'tab-financial': 'financial', 'tab-occupancy': 'occupancy', 'tab-tenant': 'tenants', 'tab-payment': 'payments' };
    document.getElementById('exportModal').addEventListener('show.bs.modal', () => {
        const open = document.querySelector('#reportTabs .tab-item.active');
        const pick = open && tabToReport[open.dataset.target];
        if (pick) type.value = pick;
        sync();
    });
    // The download doesn't leave the page; show it in the Exported Reports list afterwards.
    document.getElementById('exportForm').addEventListener('submit', () => {
        bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
        setTimeout(() => { window.location = 'reports.php?tab=exported'; }, 1500);
    });
    sync();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<script>
// Tab switching lives in its own block, ahead of the charts: if the chart library fails to
// load, Chart.register below throws and everything after it in that block stops running.
// The tabs must keep working regardless. The open tab is already set server-side; this only
// handles clicking between them.
document.addEventListener("DOMContentLoaded", function () {
    const tabs = document.querySelectorAll('#reportTabs .tab-item');
    const panes = document.querySelectorAll('.tab-pane');

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const target = document.getElementById(targetId);
            if (!target) return;
            tabs.forEach(t => t.classList.toggle('active', t === this));
            panes.forEach(p => p.classList.add('d-none'));
            target.classList.remove('d-none');
            // Remember the tab in the address bar so the pagination links come back here.
            const url = new URL(window.location);
            url.searchParams.set('tab', targetId.replace('tab-', ''));
            history.replaceState(null, '', url);
        });
    });
});
</script>

<?php if ($trendHasData): ?>
<script>
if (window.ChartDataLabels) Chart.register(ChartDataLabels);   // the labels plugin is optional

document.addEventListener("DOMContentLoaded", function() {
    // Monthly Revenue Trend
    const peso = (v) => '₱' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 });
    new Chart(document.getElementById('revenueLine'), {
        type: 'line',
        data: {
            labels: <?= json_encode(array_keys($trend)) ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= json_encode(array_values($trend)) ?>,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.08)',
                borderWidth: 2,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#2563eb',
                pointBorderWidth: 2,
                pointRadius: 4,
                fill: true,
                tension: 0.25
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 22 } },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f1f5f9' },
                    ticks: { color: '#94a3b8', font: { size: 10 }, maxTicksLimit: 5, callback: (val) => val >= 1000 ? '₱' + (val / 1000) + 'k' : '₱' + val }
                },
                x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 10 } } }
            },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => peso(ctx.parsed.y) } },
                datalabels: {
                    display: (ctx) => ctx.dataset.data[ctx.dataIndex] > 0,
                    align: 'top', color: '#1e293b', font: { weight: 'bold', size: 9 },
                    formatter: peso
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
