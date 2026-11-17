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
           " . roomBedsTakenSql('r.id') . " as tenant_count
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
$availableBeds = max(0, $totalCapacity - $totalOccupied - $maintenanceBeds);
// Occupied, available and maintenance beds as shares that add up to exactly 100%.
$bedShares = percentShares(['occupied' => $totalOccupied, 'available' => $availableBeds, 'maintenance' => $maintenanceBeds]);
$bedOccupancyRate = $bedShares['occupied'];

// The Exported Reports tab: what was downloaded, newest first, filtered by report, format and
// when it was exported.
$expType = array_key_exists(queryParam('exported_type'), REPORT_TYPES) ? queryParam('exported_type') : '';
$expFormat = in_array(queryParam('exported_format'), ['csv', 'pdf'], true) ? queryParam('exported_format') : '';
$expPeriod = array_key_exists(queryParam('exported_period'), REPORT_PERIODS) ? queryParam('exported_period') : 'all';
[$expFrom, $expTo] = reportPeriodRange($expPeriod, queryParam('exported_from'), queryParam('exported_to'));
$expWhere = [];
$expParams = [];
if ($expType !== '') { $expWhere[] = 'report_type = ?'; $expParams[] = $expType; }
if ($expFormat !== '') { $expWhere[] = 'format = ?'; $expParams[] = $expFormat; }
if ($expFrom) { $expWhere[] = 'created_at >= ?'; $expParams[] = $expFrom . ' 00:00:00'; }
if ($expTo) { $expWhere[] = 'created_at < ?'; $expParams[] = date('Y-m-d', strtotime($expTo . ' +1 day')) . ' 00:00:00'; }
$expWhereSql = $expWhere ? ' WHERE ' . implode(' AND ', $expWhere) : '';
$expCount = $pdo->prepare("SELECT COUNT(*) FROM report_exports" . $expWhereSql);
$expCount->execute($expParams);
$exportPager = paginate((int)$expCount->fetchColumn(), 15, 'page_exports', ['tab' => 'exported']);
$expStmt = $pdo->prepare("SELECT * FROM report_exports" . $expWhereSql . " ORDER BY created_at DESC, id DESC" . paginationLimitSql($exportPager));
$expStmt->execute($expParams);
$exportRows = $expStmt->fetchAll();

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

// The report tabs: each shows one report (includes/reports.php) with its own filters, read from
// the query string with the tab's name in front ("payment_status=verified"), so every tab keeps
// its filters while you move between them. What a tab shows is exactly what its Export gives.
const REPORT_TABS = [
    // tab        => [report type, tab label]
    'financial'  => ['financial',  'Financial'],
    'payment'    => ['payments',   'Payments'],
    'charges'    => ['charges',    'Charges'],
    'unpaid'     => ['unpaid',     'Unpaid'],
    'tenant'     => ['tenants',    'Tenants'],
    'occupancy'  => ['occupancy',  'Occupancy'],
    'complaints' => ['complaints', 'Complaints'],
];
$tabReports = [];
foreach (REPORT_TABS as $tab => [$type]) {
    $f = normalizeReportFilters($type, fn($key) => isset($_GET[$tab . '_' . $key]) && is_scalar($_GET[$tab . '_' . $key]) ? (string)$_GET[$tab . '_' . $key] : null);
    $report = buildReport($pdo, $type, $f);
    $pager = paginate(count($report['rows']), 15, 'page_' . $tab, ['tab' => $tab]);
    $tabReports[$tab] = ['type' => $type, 'filters' => $f, 'report' => $report, 'pager' => $pager];
}
$roomOptions = $pdo->query("SELECT id, room_number FROM rooms")->fetchAll();
usort($roomOptions, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));

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

/** A coloured pill for a status cell (Verified, Pending, Overdue, Full...). */
function reportStatusBadge(string $text): string {
    $colour = match ($text) {
        'Verified', 'Active', 'Paid', 'Resolved', 'Available' => 'success',
        'Pending', 'Unpaid', 'Partial', 'In Progress' => 'warning',
        'Rejected', 'Overdue', 'Full' => 'danger',
        'Maintenance', 'Moved out', 'Deactivated' => 'secondary',
        default => 'primary',
    };
    return '<span class="badge bg-' . $colour . '-subtle text-' . $colour . '-emphasis rounded-pill px-2" style="font-size:0.58rem;">' . htmlspecialchars($text) . '</span>';
}

/**
 * The filter bar of one report tab: period (or "as of" date), search, room and the report's own
 * choices, plus Reset and the Excel / PDF exports of exactly what is shown.
 */
function reportFilterBar(string $tab, string $type, array $f, array $rooms, callable $exportQuery): string {
    $spec = reportFilterSpec($type);
    $id = fn($k) => "f_{$tab}_{$k}";
    $name = fn($k) => "{$tab}_{$k}";
    $h = 'htmlspecialchars';
    $html = '<form method="GET" class="filter-bar card border-0 shadow-sm rounded-3 p-3 mb-3" data-filter-bar>';
    $html .= '<input type="hidden" name="tab" value="' . $h($tab) . '">';
    // Keep the other tabs' filters (and their page numbers) as they are.
    foreach ($_GET as $k => $v) {
        if (!is_scalar($v) || $k === 'tab' || str_starts_with($k, $tab . '_') || $k === 'page_' . $tab) continue;
        $html .= '<input type="hidden" name="' . $h($k) . '" value="' . $h((string)$v) . '">';
    }
    $html .= '<div class="row g-2 align-items-end">';
    if (!empty($spec['period'])) {
        $html .= '<div class="col-6 col-md-3 col-xl-2"><label class="filter-label" for="' . $id('period') . '">' . $h($spec['period']) . '</label>'
               . '<select class="form-select form-select-sm" name="' . $name('period') . '" id="' . $id('period') . '" data-period>';
        foreach (REPORT_PERIODS as $value => $label) {
            $html .= '<option value="' . $value . '"' . ($f['period'] === $value ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select></div>';
        $custom = $f['period'] === 'custom';
        $html .= '<div class="col-6 col-md-3 col-xl-2 custom-dates' . ($custom ? '' : ' d-none') . '"><label class="filter-label" for="' . $id('from') . '">From</label>'
               . '<input type="date" class="form-control form-control-sm" name="' . $name('from') . '" id="' . $id('from') . '" value="' . $h((string)$f['from']) . '" max="' . date('Y-m-d') . '"' . ($custom ? '' : ' disabled') . '></div>'
               . '<div class="col-6 col-md-3 col-xl-2 custom-dates' . ($custom ? '' : ' d-none') . '"><label class="filter-label" for="' . $id('to') . '">To</label>'
               . '<input type="date" class="form-control form-control-sm" name="' . $name('to') . '" id="' . $id('to') . '" value="' . $h((string)$f['to']) . '"' . ($custom ? '' : ' disabled') . '></div>';
    }
    if (!empty($spec['as_of'])) {
        $html .= '<div class="col-6 col-md-3 col-xl-2"><label class="filter-label" for="' . $id('as_of') . '">As of</label>'
               . '<input type="date" class="form-control form-control-sm" name="' . $name('as_of') . '" id="' . $id('as_of') . '" value="' . $h((string)$f['as_of']) . '" max="' . date('Y-m-d') . '"></div>';
    }
    foreach ($spec['selects'] ?? [] as $key => [$label, $options]) {
        $html .= '<div class="col-6 col-md-3 col-xl-2"><label class="filter-label" for="' . $id($key) . '">' . $h($label) . '</label>'
               . '<select class="form-select form-select-sm" name="' . $name($key) . '" id="' . $id($key) . '" data-autosubmit>';
        foreach ($options as $value => $text) {
            $html .= '<option value="' . $h((string)$value) . '"' . ((string)$f[$key] === (string)$value ? ' selected' : '') . '>' . $h($text) . '</option>';
        }
        $html .= '</select></div>';
    }
    if (!empty($spec['room'])) {
        $html .= '<div class="col-6 col-md-3 col-xl-2"><label class="filter-label" for="' . $id('room') . '">Room</label>'
               . '<select class="form-select form-select-sm" name="' . $name('room') . '" id="' . $id('room') . '" data-autosubmit><option value="">All rooms</option>';
        foreach ($rooms as $r) {
            $html .= '<option value="' . (int)$r['id'] . '"' . ((int)$f['room'] === (int)$r['id'] ? ' selected' : '') . '>Room ' . $h($r['room_number']) . '</option>';
        }
        $html .= '</select></div>';
    }
    if (!empty($spec['q'])) {
        $html .= '<div class="col-12 col-md-4 col-xl-2"><label class="filter-label" for="' . $id('q') . '">Search</label>'
               . '<input type="search" class="form-control form-control-sm" name="' . $name('q') . '" id="' . $id('q') . '" value="' . $h($f['q']) . '" placeholder="' . $h($spec['q']) . '"></div>';
    }
    $hasFilters = (bool)reportFilterParams($type, $f);
    $reset = pageUrl(array_fill_keys(array_merge(
        array_map(fn($k) => $tab . '_' . $k, ['period', 'from', 'to', 'as_of', 'q', 'room']),
        array_map(fn($k) => $tab . '_' . $k, array_keys($spec['selects'] ?? [])), ['page_' . $tab]), null) + ['tab' => $tab]);
    $html .= '<div class="col-12 col-xl d-flex flex-wrap gap-2 justify-content-xl-end">'
           . '<button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Apply</button>'
           . ($hasFilters ? '<a href="' . $h($reset) . '" class="btn btn-light border btn-sm">Reset</a>' : '')
           . '<a href="' . $h($exportQuery('csv')) . '" class="btn btn-outline-success btn-sm" data-export><i class="fa-regular fa-file-excel me-1"></i>Excel</a>'
           . '<a href="' . $h($exportQuery('pdf')) . '" class="btn btn-outline-danger btn-sm" data-export><i class="fa-regular fa-file-pdf me-1"></i>PDF</a>'
           . '</div>';
    $html .= '</div></form>';
    return $html;
}

require_once 'header.php';
?>

<style>
    body { background-color: #f8fafc; }
    /* Report filters */
    .filter-bar .filter-label { font-size: 0.62rem; font-weight: 700; color: #64748b; margin-bottom: 0.2rem; display: block; }
    .filter-bar .form-select-sm, .filter-bar .form-control-sm { font-size: 0.72rem; }
    .filter-bar .btn-sm { font-size: 0.72rem; }
    .filter-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #2563eb; vertical-align: middle; margin-left: 2px; }
    
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
        <?php foreach (REPORT_TABS as $tab => [, $tabLabel]): ?>
        <div class="tab-item text-nowrap <?= $activeTab === $tab ? 'active' : '' ?>" data-target="tab-<?= $tab ?>"><?= $tabLabel ?><?php if (reportFilterParams($tabReports[$tab]['type'], $tabReports[$tab]['filters'])): ?> <span class="filter-dot" title="Filtered"></span><?php endif; ?></div>
        <?php endforeach; ?>
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
                        <?php foreach ([['Occupied', $totalOccupied, 'success', 'occupied'], ['Available', $availableBeds, 'primary', 'available'], ['Maintenance', $maintenanceBeds, 'warning', 'maintenance']] as [$label, $n, $color, $key]): ?>
                        <div class="col-4">
                            <div class="occ-box">
                                <div class="occ-label"><span class="dot bg-<?= $color ?>"></span><?= $label ?></div>
                                <div><span class="occ-num"><?= (int)$n ?></span> <span class="occ-pct">(<?= number_format($bedShares[$key], 1) ?>%)</span></div>
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
<!-- REPORT TABS: one report each, with its filters -->
<!-- ============================================== -->
<?php foreach ($tabReports as $tab => $tr):
    $report = $tr['report'];
    $f = $tr['filters'];
    $pager = $tr['pager'];
    $active = reportFilterParams($tr['type'], $f);
    $exportQuery = fn($format) => 'export_report.php?' . http_build_query(['type' => $tr['type'], 'format' => $format] + $active);
    $tenantCol = array_search('Tenant', array_column($report['columns'], 0), true);
    $statusCol = array_search('Status', array_column($report['columns'], 0), true);
    $rows = array_slice($report['rows'], $pager['offset'], $pager['perPage'], true);
?>
<div id="tab-<?= $tab ?>" class="tab-pane <?= $activeTab === $tab ? '' : 'd-none' ?>">
    <?= reportFilterBar($tab, $tr['type'], $f, $roomOptions, $exportQuery) ?>

    <!-- Summary of what the filters show -->
    <div class="row g-2 mb-3">
        <?php foreach ($report['totals'] as $label => $value): ?>
        <div class="col-6 col-md-4 col-xl"><div class="metric-card p-3 shadow-sm h-100"><div class="card-title-sm"><?= htmlspecialchars($label) ?></div><div class="fw-bold text-dark" style="font-size:1rem;"><?= htmlspecialchars(str_replace('PHP ', '₱', $value)) ?></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white border-0 p-3 pb-0">
            <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;"><?= htmlspecialchars($report['title']) ?></h6>
            <small class="text-muted" style="font-size:0.68rem;"><?= htmlspecialchars(REPORT_TYPES[$tr['type']][2]) ?> · <?= htmlspecialchars($report['period']) ?><?= $report['filters'] !== '' ? ' · ' . htmlspecialchars($report['filters']) : '' ?></small>
        </div>
        <div class="card-body p-0 mt-2">
            <div class="table-responsive">
            <table class="table table-compact mb-0">
                <thead class="bg-light">
                    <tr><?php foreach ($report['columns'] as $i => [$label, , $align]): ?><th class="border-0 text-nowrap <?= $i === 0 ? 'ps-3' : '' ?> <?= $align === 'R' ? 'text-end' : '' ?> <?= $i === count($report['columns']) - 1 ? 'pe-3' : '' ?>"><?= htmlspecialchars($label) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="<?= count($report['columns']) ?>" class="text-center py-4 text-muted"><?= $active ? 'Nothing matches these filters.' : 'Nothing to show yet.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $n => $row): ?>
                    <tr>
                        <?php foreach ($row as $i => $value):
                            $align = $report['columns'][$i][2];
                            $cls = ($i === 0 ? 'ps-3 ' : '') . ($align === 'R' ? 'text-end text-nowrap ' : '') . ($i === count($row) - 1 ? 'pe-3' : '');
                            if ($i === $tenantCol && isset($report['links'][$n])) {
                                $cell = '<a href="tenant_details.php?id=' . (int)$report['links'][$n] . '" class="fw-semibold text-dark">' . htmlspecialchars((string)$value) . '</a>';
                            } elseif ($i === $statusCol) {
                                $cell = reportStatusBadge((string)$value);
                            } else {
                                $cell = htmlspecialchars((string)$value);
                            }
                        ?>
                            <td class="<?= $cls ?>" style="font-size:0.65rem;"><?= $cell ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <div class="card-footer bg-white border-top p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="text-muted" style="font-size:0.65rem;"><?= paginationSummary($pager, 'rows') ?></span>
            <?php if ($pager['totalPages'] > 1): ?><nav><ul class="pagination pagination-sm mb-0 shadow-sm" style="font-size:0.65rem;"><?= paginationControls($pager) ?></ul></nav><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ============================================== -->
<!-- TAB: EXPORTED REPORTS -->
<!-- ============================================== -->
<div id="tab-exported" class="tab-pane <?= $activeTab === 'exported' ? '' : 'd-none' ?>">
    <form method="GET" class="filter-bar card border-0 shadow-sm rounded-3 p-3 mb-3" data-filter-bar>
        <input type="hidden" name="tab" value="exported">
        <?php foreach ($_GET as $k => $v): if (!is_scalar($v) || $k === 'tab' || str_starts_with($k, 'exported_') || $k === 'page_exports') continue; ?>
            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
        <?php endforeach; ?>
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3 col-xl-2"><label class="filter-label" for="f_exp_type">Report</label>
                <select class="form-select form-select-sm" name="exported_type" id="f_exp_type" data-autosubmit>
                    <option value="">All reports</option>
                    <?php foreach (REPORT_TYPES as $key => [$label]): ?><option value="<?= $key ?>" <?= $expType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="filter-label" for="f_exp_format">Format</label>
                <select class="form-select form-select-sm" name="exported_format" id="f_exp_format" data-autosubmit>
                    <option value="">Excel and PDF</option>
                    <option value="csv" <?= $expFormat === 'csv' ? 'selected' : '' ?>>Excel (CSV)</option>
                    <option value="pdf" <?= $expFormat === 'pdf' ? 'selected' : '' ?>>PDF</option>
                </select></div>
            <div class="col-6 col-md-3 col-xl-2"><label class="filter-label" for="f_exp_period">Exported</label>
                <select class="form-select form-select-sm" name="exported_period" id="f_exp_period" data-period>
                    <?php foreach (REPORT_PERIODS as $value => $label): ?><option value="<?= $value ?>" <?= $expPeriod === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-6 col-md-3 col-xl-2 custom-dates <?= $expPeriod === 'custom' ? '' : 'd-none' ?>"><label class="filter-label" for="f_exp_from">From</label>
                <input type="date" class="form-control form-control-sm" name="exported_from" id="f_exp_from" value="<?= htmlspecialchars((string)$expFrom) ?>" <?= $expPeriod === 'custom' ? '' : 'disabled' ?>></div>
            <div class="col-6 col-md-3 col-xl-2 custom-dates <?= $expPeriod === 'custom' ? '' : 'd-none' ?>"><label class="filter-label" for="f_exp_to">To</label>
                <input type="date" class="form-control form-control-sm" name="exported_to" id="f_exp_to" value="<?= htmlspecialchars((string)$expTo) ?>" <?= $expPeriod === 'custom' ? '' : 'disabled' ?>></div>
            <div class="col-12 col-xl d-flex gap-2 justify-content-xl-end">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Apply</button>
                <?php if ($expType !== '' || $expFormat !== '' || $expPeriod !== 'all'): ?>
                    <a href="<?= htmlspecialchars(pageUrl(['exported_type' => null, 'exported_format' => null, 'exported_period' => null, 'exported_from' => null, 'exported_to' => null, 'page_exports' => null, 'tab' => 'exported'])) ?>" class="btn btn-light border btn-sm">Reset</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
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
                    <tr><th class="ps-3 border-0">Report</th><th class="border-0">Period</th><th class="border-0">Filters</th><th class="border-0">Format</th><th class="border-0 text-end">Rows</th><th class="border-0">Exported</th><th class="border-0 text-end pe-3">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (!$exportRows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4" style="font-size:0.75rem;"><?= $expWhere ? 'No exports match these filters.' : 'Nothing exported yet. Use <strong>Export Report</strong> to download one.' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($exportRows as $x):
                        $xType = REPORT_TYPES[$x['report_type']] ?? null;
                        if (!$xType) continue;
                        // Its filters as saved; exports from before filters existed kept only their dates.
                        $saved = json_decode((string)($x['filters'] ?? ''), true);
                        if (!is_array($saved)) {
                            $saved = array_filter(['period' => $x['date_from'] ? 'custom' : null, 'from' => $x['date_from'], 'to' => $x['date_to']]);
                        }
                        $saved = array_filter($saved, 'is_scalar');
                        $xf = normalizeReportFilters($x['report_type'], fn($k) => $saved[$k] ?? null);
                        $again = 'export_report.php?' . http_build_query(['type' => $x['report_type'], 'format' => $x['format']] + $saved + ['again' => 1]);
                        $xPeriod = !empty(reportFilterSpec($x['report_type'])['period']) ? reportPeriodText($xf['from'], $xf['to'])
                                 : 'As of ' . date('M j, Y', strtotime($xf['as_of'] && isset($saved['as_of']) ? $xf['as_of'] : $x['created_at']));
                        $xFilters = describeReportFilters($pdo, $x['report_type'], $xf);
                    ?>
                    <tr>
                        <td class="ps-3 fw-semibold text-dark"><i class="fa-regular <?= $x['format'] === 'pdf' ? 'fa-file-pdf text-danger' : 'fa-file-excel text-success' ?> me-2"></i><?= htmlspecialchars($xType[0]) ?></td>
                        <td class="text-muted text-nowrap"><?= htmlspecialchars($xPeriod) ?></td>
                        <td class="text-muted" style="font-size:0.65rem;"><?= $xFilters !== '' ? htmlspecialchars($xFilters) : '—' ?></td>
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
                            <option value="<?= $key ?>" data-range="<?= !empty(reportFilterSpec($key)['period']) ? 1 : 0 ?>" data-asof="<?= !empty(reportFilterSpec($key)['as_of']) ? 1 : 0 ?>" data-about="<?= htmlspecialchars($about) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" id="exportAbout" style="font-size:0.68rem;"></div>
                </div>
                <div id="exportRange">
                    <div class="row g-2 mb-3">
                        <div class="col-12"><label for="exportPeriod">Period</label>
                            <select name="period" id="exportPeriod" class="form-select">
                                <?php foreach (REPORT_PERIODS as $value => $label): ?><option value="<?= $value ?>" <?= $value === 'month' ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-6 export-custom d-none"><label for="exportFrom" class="text-muted fw-normal">From</label><input type="date" name="from" id="exportFrom" class="form-control" value="<?= date('Y-m-01') ?>" max="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6 export-custom d-none"><label for="exportTo" class="text-muted fw-normal">To</label><input type="date" name="to" id="exportTo" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    </div>
                </div>
                <div id="exportAsOf" class="mb-3 d-none">
                    <label for="exportAsOfDate">As of</label>
                    <input type="date" name="as_of" id="exportAsOfDate" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-text mb-3" style="font-size:0.68rem;"><i class="fa-solid fa-filter me-1"></i>For more filters (status, room, search...), filter the report on its tab and use its Excel / PDF buttons.</div>
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
    const period = document.getElementById('exportPeriod');
    const asOf = document.getElementById('exportAsOf');
    const sync = () => {
        const opt = type.selectedOptions[0];
        const usesRange = opt.dataset.range === '1', custom = usesRange && period.value === 'custom';
        range.classList.toggle('d-none', !usesRange);
        period.disabled = !usesRange;
        document.querySelectorAll('.export-custom').forEach(el => {
            el.classList.toggle('d-none', !custom);
            el.querySelector('input').disabled = !custom;
        });
        asOf.classList.toggle('d-none', opt.dataset.asof !== '1');
        asOf.querySelector('input').disabled = opt.dataset.asof !== '1';
        about.textContent = opt.dataset.about;
    };
    type.addEventListener('change', sync);
    period.addEventListener('change', sync);
    const tabToReport = <?= json_encode(array_combine(array_map(fn($t) => 'tab-' . $t, array_keys(REPORT_TABS)), array_column(REPORT_TABS, 0))) ?>;
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
// Filter bars: "Custom dates" shows the from / to boxes; any other period, and the drop-downs,
// apply straight away.
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll('[data-filter-bar]').forEach(form => {
        // Leave out empty boxes and "All time", so the address stays short.
        const go = () => {
            form.querySelectorAll('input[name], select[name]').forEach(el => {
                if (el.name !== 'tab' && (el.value === '' || (el.name.endsWith('_period') && el.value === 'all'))) el.disabled = true;
            });
            form.submit();
        };
        form.addEventListener('submit', e => { e.preventDefault(); go(); });
        const period = form.querySelector('[data-period]');
        if (period) period.addEventListener('change', () => {
            const custom = period.value === 'custom';
            form.querySelectorAll('.custom-dates').forEach(el => {
                el.classList.toggle('d-none', !custom);
                el.querySelector('input').disabled = !custom;
            });
            if (!custom) go();
        });
        form.querySelectorAll('[data-autosubmit]').forEach(sel => sel.addEventListener('change', go));
        // A single "as of" date applies when picked.
        form.querySelectorAll('input[type="date"][name$="_as_of"]').forEach(i => i.addEventListener('change', go));
    });
});

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
