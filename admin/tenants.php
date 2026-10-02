<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
require_once '../includes/tenant_actions.php';
require_once '../includes/pagination.php';
requireAdmin();

$error = '';
$success = '';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ['action' => $action, 'success' => $success, 'error' => $error] = handleTenantAction($pdo);

    // "add" stays on the page so the admin can copy the generated login details.
    if (empty($error) && $action !== 'add') {
        header("Location: tenants.php?msg=" . urlencode(strip_tags($success)));
        exit;
    }
}
if (empty($success) && !empty($_GET['msg'])) {
    $success = htmlspecialchars($_GET['msg'] ?? '');
}

// Fetch stats
$totalTenants = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn();
$occupiedRooms = $pdo->query("SELECT COUNT(DISTINCT room_id) FROM tenants WHERE room_id IS NOT NULL AND status = 'active'")->fetchColumn();
// Includes deactivated tenants who still owe money: it's still money owed to the house.
$totalOutstanding = $pdo->query("SELECT SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) FROM tenants")->fetchColumn() ?: 0;

// Total paid this month
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE " . REVENUE_FILTER_SQL . " AND payment_date BETWEEN ? AND ?");
$stmt->execute([$monthStart, $monthEnd]);
$totalPaidThisMonth = $stmt->fetchColumn() ?: 0;

// --- TENANTS LIST ---
// Searching, the status filter and paging all happen in SQL, so the page only ever builds
// the rows it shows instead of the whole tenancy.
$filterSearch = queryParam('q');
$filterStatus = queryParam('status', 'all') ?: 'all';

// Deactivated tenants are hidden by default: they are former tenants and just clutter the
// list. The "Show deactivated" button brings them back with ?deactivated=1, and picking
// Deactivated from the status dropdown obviously has to show them too.
$showDeactivated = queryParam('deactivated') === '1' || $filterStatus === 'deactivated';

// not_yet_due is the part of a tenant's balance that isn't past its due date yet. It is what
// tenantBillingStatus() uses to tell "Unpaid" from "Overdue", expressed here so the same
// split can be filtered on in SQL.
$listSql = "
    SELECT * FROM (
        SELECT t.*, u.username, u.created_at AS user_created_at, r.room_number,
               COALESCE((SELECT SUM(c.amount) FROM charges c
                         WHERE c.tenant_id = t.id AND c.due_date >= ?), 0) AS not_yet_due
        FROM tenants t
        JOIN users u ON t.user_id = u.id
        LEFT JOIN rooms r ON t.room_id = r.id
    ) x";
$params = [date('Y-m-d')];
$where = [];

if ($filterSearch !== '') {
    $where[] = "(LOWER(x.first_name) LIKE ? OR LOWER(x.last_name) LIKE ?
                 OR LOWER(CONCAT(x.first_name, ' ', x.last_name)) LIKE ? OR LOWER(x.username) LIKE ?)";
    $like = '%' . strtolower($filterSearch) . '%';
    array_push($params, $like, $like, $like, $like);
}
switch ($filterStatus) {
    case 'deactivated':
        $where[] = "x.status = 'deactivated'"; break;
    case 'paid':
        $where[] = "x.status = 'active' AND x.balance <= 0"; break;
    case 'overdue':
        $where[] = "x.status = 'active' AND x.balance > 0 AND (x.balance - x.not_yet_due) > 0.005"; break;
    case 'due':
        $where[] = "x.status = 'active' AND x.balance > 0 AND (x.balance - x.not_yet_due) <= 0.005"; break;
}
if (!$showDeactivated) {
    $where[] = "x.status <> 'deactivated'";
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// How many deactivated tenants the button would reveal, honouring the current search.
$deactivatedCountSql = "SELECT COUNT(*) FROM tenants t WHERE t.status = 'deactivated'";
$deactivatedParams = [];
if ($filterSearch !== '') {
    $deactivatedCountSql .= " AND (LOWER(t.first_name) LIKE ? OR LOWER(t.last_name) LIKE ?
                                   OR LOWER(CONCAT(t.first_name, ' ', t.last_name)) LIKE ?)";
    $like = '%' . strtolower($filterSearch) . '%';
    array_push($deactivatedParams, $like, $like, $like);
}
$deactivatedStmt = $pdo->prepare($deactivatedCountSql);
$deactivatedStmt->execute($deactivatedParams);
$deactivatedCount = (int)$deactivatedStmt->fetchColumn();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($listSql$whereSql) counted");
$countStmt->execute($params);
$pager = paginate((int)$countStmt->fetchColumn(), 12);

$tenantsStmt = $pdo->prepare($listSql . $whereSql
    . " ORDER BY (x.status = 'active') DESC, x.user_created_at DESC, x.id DESC"
    . paginationLimitSql($pager));
$tenantsStmt->execute($params);
$tenants = $tenantsStmt->fetchAll();
$notYetDue = chargesNotYetDue($pdo);

// Fetch available rooms for the dropdown (only active tenants take up beds)
$roomsStmt = $pdo->query("SELECT r.id, r.room_number, r.capacity, r.price_per_month, (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id AND t.status = 'active') as occupied FROM rooms r ORDER BY r.room_number ASC");
$allRooms = $roomsStmt->fetchAll();

require_once 'header.php';
?>

<div class="container-fluid mb-3">
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0" role="alert" style="font-size: 0.85rem;">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" role="alert" style="font-size: 0.85rem;">
            <i class="fa-solid fa-circle-check me-2"></i> <?= $success ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
</div>

<style>
    .compact-table th {
        font-size: 0.6rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        color: #64748b;
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
        white-space: nowrap;
    }
    .compact-table td {
        font-size: 0.75rem;
        vertical-align: middle;
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
    }

    .status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 4px;
    }
    .form-compact label {
        font-size: 0.75rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.25rem;
    }
    .form-compact .form-control, .form-compact .form-select {
        font-size: 0.8rem;
        padding: 0.4rem 0.75rem;
    }
</style>

<!-- Header Section -->
<div class="row align-items-center mb-4">
    <div class="col-xl-4 col-lg-5 mb-3 mb-lg-0">
        <h3 class="fw-bold mb-1 text-dark">Manage Tenants</h3>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">Add new tenants and manage existing tenant information.</p>
    </div>
    
    <!-- Stats Cards in Header -->
    <div class="col-xl-8 col-lg-7">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-0">
                <div class="row g-0">
                    <!-- Metric 1 -->
                    <div class="col-6 col-md-3 p-2 d-flex align-items-center border-end border-light">
                        <div class="bg-primary bg-opacity-10 text-primary rounded d-flex justify-content-center align-items-center me-2 me-xl-3 flex-shrink-0" style="width: 35px; height: 35px;">
                            <i class="fa-solid fa-users"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark fs-6"><?= $totalTenants ?></h5>
                            <small class="text-muted" style="font-size:0.6rem; font-weight:600;">Total Tenants</small>
                        </div>
                    </div>
                    <!-- Metric 2 -->
                    <div class="col-6 col-md-3 p-2 d-flex align-items-center border-end border-light">
                        <div class="bg-success bg-opacity-10 text-success rounded d-flex justify-content-center align-items-center me-2 me-xl-3 flex-shrink-0" style="width: 35px; height: 35px;">
                            <i class="fa-solid fa-bed"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark fs-6"><?= $occupiedRooms ?></h5>
                            <small class="text-muted" style="font-size:0.6rem; font-weight:600;">Occupied Rooms</small>
                        </div>
                    </div>
                    <!-- Metric 3 -->
                    <div class="col-6 col-md-3 p-2 d-flex align-items-center border-end border-light">
                        <div class="bg-danger bg-opacity-10 text-danger rounded d-flex justify-content-center align-items-center me-2 me-xl-3 flex-shrink-0" style="width: 35px; height: 35px;">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark fs-6">₱<?= number_format($totalOutstanding, 2) ?></h5>
                            <small class="text-muted" style="font-size:0.6rem; font-weight:600;">Total Unpaid Rent</small>
                        </div>
                    </div>
                    <!-- Metric 4 -->
                    <div class="col-6 col-md-3 p-2 d-flex align-items-center">
                        <div class="bg-success bg-opacity-10 text-success rounded d-flex justify-content-center align-items-center me-2 me-xl-3 flex-shrink-0" style="width: 35px; height: 35px;">
                            <i class="fa-solid fa-money-bill-trend-up"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark fs-6">₱<?= number_format($totalPaidThisMonth, 2) ?></h5>
                            <small class="text-muted" style="font-size:0.6rem; font-weight:600;">Paid (This Month)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Grid -->
<div class="row g-4">
    
    <!-- Left Column: Add Tenant Form -->
    <div class="col-lg-4 col-xl-3">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3 form-compact">
                <div class="d-flex align-items-center mb-4">
                    <div class="bg-primary bg-opacity-10 text-primary rounded d-flex justify-content-center align-items-center me-3" style="width: 35px; height: 35px;">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark" style="font-size:0.85rem;">Add New Tenant</h6>
                        <small class="text-muted" style="font-size:0.65rem;">Create a new tenant account.</small>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-2">
                        <label>First Name</label>
                        <input type="text" class="form-control" name="first_name" placeholder="e.g., Juan" required>
                    </div>
                    <div class="mb-2">
                        <label>Last Name</label>
                        <input type="text" class="form-control" name="last_name" placeholder="e.g., Dela Cruz" required>
                    </div>
                    <div class="mb-2">
                        <label>Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" placeholder="e.g., 0912 345 6789">
                    </div>
                    <div class="mb-2">
                        <label>Occupation</label>
                        <input type="text" class="form-control" name="occupation" placeholder="e.g., Student">
                    </div>
                    <div class="mb-2">
                        <label>Emergency Contact</label>
                        <input type="text" class="form-control" name="emergency_contact" placeholder="Name & Number">
                    </div>

                    <div class="mb-3">
                        <label>Assign Room <span class="text-muted fw-normal">(Optional)</span></label>
                        <select class="form-select" name="room_id">
                            <option value="">Unassigned</option>
                            <?php foreach($allRooms as $r): ?>
                                <?php 
                                    $isFull = $r['occupied'] >= $r['capacity'];
                                    $label = 'Room ' . htmlspecialchars($r['room_number'] ?? '');
                                    if ($isFull) {
                                        $label .= ' - FULL';
                                    } else {
                                        $avail = $r['capacity'] - $r['occupied'];
                                        // Adding this tenant makes one more person to split the room price between.
                                        $share = $r['price_per_month'] / ($r['occupied'] + 1);
                                        $label .= " (Avail: $avail | ₱" . number_format($r['price_per_month']) . "/room, ₱" . number_format($share, 2) . " each)";
                                    }
                                ?>
                                <option value="<?= $r['id'] ?>" <?= $isFull ? 'disabled' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Move-in Date</label>
                        <input type="date" class="form-control" name="move_in_date" value="<?= date('Y-m-d') ?>">
                        <small class="text-muted d-block mt-1" style="font-size:0.62rem;">Used when a room is assigned. Day 1–15: full first month. Day 16+: half. Due every 30th.</small>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" style="font-size:0.8rem; font-weight:600;"><i class="fa-solid fa-plus me-2"></i>Create Tenant</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Tenant List -->
    <div class="col-lg-8 col-xl-9">
        <div class="card border-0 shadow-sm rounded-3 h-100 d-flex flex-column">
            
            <div class="card-header bg-white border-0 p-3 pb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 text-primary rounded d-flex justify-content-center align-items-center me-2" style="width: 32px; height: 32px;">
                        <i class="fa-solid fa-address-book" style="font-size:0.85rem;"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0 text-dark" style="font-size:0.85rem;">Tenant List</h6>
                        <small class="text-muted" style="font-size:0.65rem;">View and manage all tenants.</small>
                    </div>
                </div>
                
                <form method="GET" id="filterForm" class="d-flex gap-2">
                    <?php if ($showDeactivated && $filterStatus !== 'deactivated'): ?>
                        <input type="hidden" name="deactivated" value="1">
                    <?php endif; ?>
                    <div class="input-group input-group-sm rounded-2 border bg-white" style="width:200px;">
                        <span class="input-group-text bg-transparent border-0 pe-1"><i class="fa-solid fa-magnifying-glass text-muted" style="font-size:0.65rem;"></i></span>
                        <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($filterSearch) ?>" class="form-control border-0 shadow-none px-1" placeholder="Search tenants..." style="font-size:0.7rem;">
                    </div>
                    <select name="status" class="form-select form-select-sm border rounded-2 shadow-none text-muted" style="width:120px; font-size:0.7rem;" onchange="this.form.submit()">
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="due" <?= $filterStatus === 'due' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="deactivated" <?= $filterStatus === 'deactivated' ? 'selected' : '' ?>>Deactivated</option>
                    </select>

                    <?php
                        // Keep the search and status when toggling, but go back to page 1:
                        // the row count changes, so the current page number may not exist.
                        $toggleUrl = pageUrl([
                            'deactivated' => $showDeactivated ? null : '1',
                            'page' => null,
                        ]);
                    ?>
                    <?php if ($filterStatus === 'deactivated'): ?>
                        <?php /* the dropdown is already showing only deactivated tenants */ ?>
                    <?php elseif ($showDeactivated): ?>
                        <a href="<?= htmlspecialchars($toggleUrl) ?>" class="btn btn-sm btn-secondary rounded-2 px-2 text-nowrap" style="font-size:0.7rem;" title="Hide deactivated tenants">
                            <i class="fa-solid fa-eye-slash me-1"></i> Hide deactivated
                        </a>
                    <?php elseif ($deactivatedCount > 0): ?>
                        <a href="<?= htmlspecialchars($toggleUrl) ?>" class="btn btn-sm btn-outline-secondary rounded-2 px-2 text-nowrap" style="font-size:0.7rem;" title="Show deactivated tenants">
                            <i class="fa-solid fa-eye me-1"></i> Show deactivated (<?= $deactivatedCount ?>)
                        </a>
                    <?php endif; ?>

                    <?php if ($filterSearch !== '' || $filterStatus !== 'all' || $showDeactivated): ?>
                        <a href="tenants.php" class="btn btn-sm btn-light border rounded-2 text-muted px-2" style="font-size:0.7rem;" title="Clear filters"><i class="fa-solid fa-rotate-right"></i></a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card-body p-0 mt-2 overflow-auto" style="flex:1;">
                <table class="table table-hover compact-table mb-0 border-top text-nowrap" id="tenantsTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 border-0">Tenant</th>
                            <th class="border-0">Contact</th>
                            <th class="border-0">Username</th>
                            <th class="border-0">Room</th>
                            <th class="border-0">Balance</th>
                            <th class="border-0">Status</th>
                            <th class="border-0 text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($tenants) === 0): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">No tenants found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach($tenants as $t):
                            $bs = tenantBillingStatus($t, $notYetDue);
                            $balance = $bs['balance'];
                            $isDeactivated = $bs['key'] === 'deactivated';
                            $rowData = htmlspecialchars(json_encode([
                                'id' => (int)$t['id'],
                                'name' => $t['first_name'] . ' ' . $t['last_name'],
                                'first_name' => $t['first_name'],
                                'last_name' => $t['last_name'],
                                'contact' => $t['contact_number'] ?? '',
                                'occupation' => $t['occupation'] ?? '',
                                'emergency_contact' => $t['emergency_contact'] ?? '',
                                'room_id' => $t['room_id'] ? (int)$t['room_id'] : null,
                                'room_number' => $t['room_number'],
                                'has_move_in' => !empty($t['move_in_date']),
                                'balance' => $balance,
                            ]), ENT_QUOTES);
                        ?>
                        <tr class="tenant-row <?= $isDeactivated ? 'opacity-75' : '' ?>" data-status="<?= $bs['key'] ?>" data-search="<?= htmlspecialchars(strtolower($t['first_name'].' '.$t['last_name'].' '.$t['username'])) ?>">
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <?= avatarHtml($t['first_name'] . ' ' . $t['last_name'], 28, 'me-2', $t['profile_picture'] ?? null, '../') ?>
                                    <span class="fw-bold text-dark text-truncate" style="max-width:130px;"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></span>
                                </div>
                            </td>
                            <td class="text-muted"><?= htmlspecialchars($t['contact_number'] ?? '') ?: '<span class="text-black-50 fst-italic">None</span>' ?></td>
                            <td class="text-primary" style="font-size: 0.7rem;"><?= htmlspecialchars($t['username'] ?? '') ?></td>
                            <td class="text-dark fw-semibold">
                                <?php if ($isDeactivated): ?>
                                    <span class="text-muted fw-normal">Moved out<?= $t['deactivated_at'] ? ' ' . date('M j, Y', strtotime($t['deactivated_at'])) : '' ?></span>
                                <?php else: ?>
                                    <?= $t['room_number'] ? 'Room ' . htmlspecialchars($t['room_number'] ?? '') : '<span class="text-muted fw-normal">Unassigned</span>' ?>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
                                ₱<?= number_format($balance, 2) ?>
                                <?php if ($bs['overdue'] > 0 && $bs['overdue'] < $balance): ?>
                                    <div class="text-danger fw-normal" style="font-size:0.6rem;">₱<?= number_format($bs['overdue'], 2) ?> overdue</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $bs['color'] ?>-subtle text-<?= $bs['color'] ?> rounded-pill px-2 py-1 fw-semibold border border-<?= $bs['color'] ?>-subtle" style="font-size: 0.6rem;">
                                    <span class="status-dot bg-<?= $bs['color'] ?>"></span> <?= $bs['label'] ?>
                                </span>
                            </td>
                            <td class="text-end pe-4 text-nowrap" data-tenant="<?= $rowData ?>">
                                <a href="tenant_details.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-1 px-1 py-0 me-1" title="View details">
                                    <i class="fa-regular fa-eye" style="font-size: 0.65rem;"></i>
                                </a>
                                <button type="button" onclick="openEditModal(this)" class="btn btn-sm btn-outline-primary rounded-1 px-1 py-0 me-1" title="Edit details">
                                    <i class="fa-regular fa-pen-to-square" style="font-size: 0.65rem;"></i>
                                </button>
                                <?php if ($isDeactivated): ?>
                                    <button type="button" onclick="openReactivateModal(this)" class="btn btn-sm btn-outline-success rounded-1 px-1 py-0" title="Re-activate">
                                        <i class="fa-solid fa-rotate-left" style="font-size: 0.65rem;"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" onclick="openRoomModal(this)" class="btn btn-sm btn-outline-info rounded-1 px-1 py-0 me-1" title="<?= $t['room_id'] ? 'Change room' : 'Assign room' ?>">
                                        <i class="fa-solid fa-right-left" style="font-size: 0.65rem;"></i>
                                    </button>
                                    <button type="button" onclick="openDeactivateModal(this)" class="btn btn-sm btn-outline-danger rounded-1 px-1 py-0" title="Remove tenant">
                                        <i class="fa-solid fa-user-xmark" style="font-size: 0.65rem;"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white border-top p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted" style="font-size:0.75rem;"><?= paginationSummary($pager, 'tenants') ?></span>
                <nav>
                    <ul class="pagination pagination-sm mb-0 shadow-sm" style="font-size:0.7rem;"><?= paginationControls($pager) ?></ul>
                </nav>
            </div>

        </div>
    </div>
</div>

<?php
// Room <option>s. Full rooms (and the tenant's current room) are disabled.
function roomOptions(array $rooms): string {
    $html = '';
    foreach ($rooms as $r) {
        $isFull = $r['occupied'] >= $r['capacity'];
        $avail = max(0, $r['capacity'] - $r['occupied']);
        // Adding this tenant makes one more person to split the room price between.
        $share = $r['price_per_month'] / ($r['occupied'] + 1);
        $label = 'Room ' . htmlspecialchars($r['room_number'] ?? '')
               . ($isFull ? ' - FULL' : " (Avail: $avail | ₱" . number_format($r['price_per_month']) . "/room, ₱" . number_format($share, 2) . " each)");
        $html .= '<option value="' . (int)$r['id'] . '" data-full="' . ($isFull ? 1 : 0) . '"' . ($isFull ? ' disabled' : '') . '>' . $label . '</option>';
    }
    return $html;
}
?>

<!-- Edit Tenant Modal -->
<div class="modal fade" id="editTenantModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="tenant_id" id="edit_tenant_id">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold">Edit Tenant Details</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 form-compact">
                    <div class="mb-3">
                        <label>First Name</label>
                        <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                    </div>
                    <div class="mb-3">
                        <label>Last Name</label>
                        <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
                    </div>
                    <div class="mb-3">
                        <label>Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" id="edit_contact">
                    </div>
                    <div class="mb-3">
                        <label>Occupation</label>
                        <input type="text" class="form-control" name="occupation" id="edit_occupation">
                    </div>
                    <div class="mb-3">
                        <label>Emergency Contact</label>
                        <input type="text" class="form-control" name="emergency_contact" id="edit_emergency_contact">
                    </div>
                    <small class="text-muted" style="font-size:0.65rem;"><i class="fa-solid fa-circle-info me-1"></i>To move the tenant, use the <i class="fa-solid fa-right-left"></i> Change Room button.</small>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" style="font-size:0.8rem;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Room Modal -->
<div class="modal fade" id="roomModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="change_room">
                <input type="hidden" name="tenant_id" id="room_tenant_id">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold" id="room_title">Change Room</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 form-compact">
                    <div class="mb-3" style="font-size:0.75rem;">
                        <span class="text-muted">Tenant:</span> <strong id="room_tenant_name"></strong><br>
                        <span class="text-muted">Current room:</span> <strong id="room_current"></strong>
                    </div>
                    <div class="mb-3">
                        <label>New Room</label>
                        <select class="form-select" name="room_id" id="room_select" required>
                            <option value="">Select a room</option>
                            <?= roomOptions($allRooms) ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label id="room_date_label">Date of Transfer</label>
                        <input type="date" class="form-control" name="effective_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="alert alert-info border-0 py-2 mb-0" style="font-size:0.7rem;" id="room_note"></div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" style="font-size:0.8rem;">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove / Deactivate Modal -->
<div class="modal fade" id="deactivateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="tenant_id" id="deact_tenant_id">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold text-danger"><i class="fa-solid fa-user-xmark me-2"></i>Remove Tenant</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 form-compact">
                    <p style="font-size:0.8rem;" class="mb-2">Remove <strong id="deact_name"></strong> from the boarding house?</p>
                    <ul class="text-muted ps-3 mb-3" style="font-size:0.72rem;">
                        <li>Their bed in <strong id="deact_room"></strong> becomes available.</li>
                        <li>Their account is deactivated and they can no longer log in.</li>
                        <li>No more monthly rent will be charged.</li>
                        <li>Payments, receipts and complaints are kept on record.</li>
                    </ul>
                    <div class="alert alert-warning border-0 py-2 mb-3 d-none" style="font-size:0.72rem;" id="deact_balance"></div>
                    <div class="mb-1">
                        <label>Move-out Date</label>
                        <input type="date" class="form-control" name="deactivated_at" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4" style="font-size:0.8rem;">Remove &amp; Deactivate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Re-activate Modal -->
<div class="modal fade" id="reactivateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="reactivate">
                <input type="hidden" name="tenant_id" id="react_tenant_id">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold text-success"><i class="fa-solid fa-rotate-left me-2"></i>Re-activate Tenant</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 form-compact">
                    <p style="font-size:0.8rem;" class="mb-3"><strong id="react_name"></strong> is moving back in. Their login is restored and billing starts again from the move-in date.</p>
                    <div class="mb-3">
                        <label>Room</label>
                        <select class="form-select" name="room_id" id="react_room" required>
                            <option value="">Select a room</option>
                            <?= roomOptions($allRooms) ?>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label>Move-in Date</label>
                        <input type="date" class="form-control" name="move_in_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                    <button type="submit" class="btn btn-success px-4" style="font-size:0.8rem;">Re-activate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function tenantData(btn) {
    return JSON.parse(btn.closest('[data-tenant]').dataset.tenant);
}
function showModal(id) {
    bootstrap.Modal.getOrCreateInstance(document.getElementById(id)).show();
}
function peso(n) {
    return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function openEditModal(btn) {
    const t = tenantData(btn);
    document.getElementById('edit_tenant_id').value = t.id;
    document.getElementById('edit_first_name').value = t.first_name;
    document.getElementById('edit_last_name').value = t.last_name;
    document.getElementById('edit_contact').value = t.contact || '';
    document.getElementById('edit_occupation').value = t.occupation || '';
    document.getElementById('edit_emergency_contact').value = t.emergency_contact || '';
    showModal('editTenantModal');
}

function openRoomModal(btn) {
    const t = tenantData(btn);
    const firstAssignment = !t.has_move_in;
    document.getElementById('room_tenant_id').value = t.id;
    document.getElementById('room_tenant_name').textContent = t.name;
    document.getElementById('room_current').textContent = t.room_number ? 'Room ' + t.room_number : 'Unassigned';
    document.getElementById('room_title').textContent = firstAssignment ? 'Assign Room' : 'Change Room';
    document.getElementById('room_date_label').textContent = firstAssignment ? 'Move-in Date' : 'Date of Transfer';
    document.getElementById('room_note').innerHTML = firstAssignment
        ? 'This is the tenant\'s move-in. Moving in on day 1–15 bills the full first month; day 16 onward bills half. Rent is due every 30th.'
        : 'This month\'s rent stays as billed. The new room\'s rate applies from next month\'s bill.';
    const select = document.getElementById('room_select');
    select.value = '';
    for (const opt of select.options) {
        if (!opt.value) continue;
        opt.disabled = opt.dataset.full === '1' || Number(opt.value) === t.room_id;
    }
    showModal('roomModal');
}

function openDeactivateModal(btn) {
    const t = tenantData(btn);
    document.getElementById('deact_tenant_id').value = t.id;
    document.getElementById('deact_name').textContent = t.name;
    document.getElementById('deact_room').textContent = t.room_number ? 'Room ' + t.room_number : 'their room';
    const bal = document.getElementById('deact_balance');
    if (t.balance > 0) {
        bal.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>This tenant still owes <strong>' + peso(t.balance) + '</strong>. The balance stays on their record after removal.';
        bal.classList.remove('d-none');
    } else {
        bal.classList.add('d-none');
    }
    showModal('deactivateModal');
}

function openReactivateModal(btn) {
    const t = tenantData(btn);
    document.getElementById('react_tenant_id').value = t.id;
    document.getElementById('react_name').textContent = t.name;
    document.getElementById('react_room').value = '';
    showModal('reactivateModal');
}

// Typing in the search box submits the form after a short pause, so the server can filter.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('filterForm');
    const search = document.getElementById('searchInput');
    if (!form || !search) return;
    let timer;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(() => form.submit(), 400);
    });
});
</script>
<?php require_once 'footer.php'; ?>
