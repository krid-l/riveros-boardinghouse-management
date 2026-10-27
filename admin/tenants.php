<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
require_once '../includes/tenant_actions.php';
require_once '../includes/pagination.php';
require_once '../includes/sms_reminders.php';   // REMINDER_DAYS_BEFORE, for "due soon"
require_once '../includes/ui.php';
require_once '../includes/tenant_profile.php';
requireAdmin();

$error = '';
$success = '';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ['action' => $action, 'success' => $success, 'error' => $error] = handleTenantAction($pdo);

    // "add" stays on the page so the admin can copy the generated login details.
    if (empty($error) && $action !== 'add') {
        header("Location: tenants.php?msg=" . urlencode(html_entity_decode(strip_tags($success))));
        exit;
    }
}
// After a failed add, the pop-up opens again with what was typed.
$addOld = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action ?? '') === 'add' && !empty($error)) ? $_POST : [];
// The dashboard's "Add Tenant" button links here with ?add=1 (and a room's details with &room=id).
$openAddModal = $addOld || queryParam('add') === '1';
if (empty($success) && !empty($_GET['msg'])) {
    $success = htmlspecialchars($_GET['msg'] ?? '');
}

// Fetch stats
$totalTenants = $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn();

// Active tenants by billing state, for the summary cards (each one filters the list).
$tenantCounts = ['paid' => 0, 'due' => 0, 'overdue' => 0];
$cardNotYetDue = chargesNotYetDue($pdo);
foreach ($pdo->query("SELECT id, balance, status FROM tenants WHERE status = 'active'")->fetchAll() as $t) {
    $tenantCounts[tenantBillingStatus($t, $cardNotYetDue)['key']]++;
}

// --- TENANTS LIST ---
// Searching, the status filter and paging all happen in SQL, so the page only ever builds
// the rows it shows instead of the whole tenancy.
$filterSearch = queryParam('q');
$filterStatus = queryParam('status', 'all') ?: 'all';
if (queryParam('deactivated') === '1') {
    $filterStatus = 'deactivated';   // older links to "Show deactivated"
}

// Deactivated tenants are former tenants: hidden unless asked for. "Show deactivated" (or
// Deactivated in the status dropdown) lists only them. "All unpaid" includes them because
// moved-out tenants can still owe rent.
$showDeactivated = $filterStatus === 'deactivated' || $filterStatus === 'unpaid';

// not_yet_due is the part of a tenant's balance that isn't past its due date yet. It is what
// tenantBillingStatus() uses to tell "Unpaid" from "Overdue", expressed here so the same
// split can be filtered on in SQL.
$listSql = "
    SELECT * FROM (
        SELECT t.*, u.username, u.created_at AS user_created_at, r.room_number,
               COALESCE((SELECT SUM(c.amount) FROM charges c
                         WHERE c.tenant_id = t.id AND c.due_date >= ?), 0) AS not_yet_due,
               (SELECT COUNT(*) FROM payments p
                WHERE p.tenant_id = t.id AND p.status <> 'rejected' AND p.payment_date >= t.move_in_date) AS stay_payments
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
    case 'due_soon':
        // Nothing late yet, and a bill falls due within the reminder window.
        $where[] = "x.status = 'active' AND x.balance > 0 AND (x.balance - x.not_yet_due) <= 0.005
                    AND EXISTS (SELECT 1 FROM charges c WHERE c.tenant_id = x.id AND c.amount > 0 AND c.due_date BETWEEN ? AND ?)";
        array_push($params, date('Y-m-d'), date('Y-m-d', strtotime('+' . REMINDER_DAYS_BEFORE . ' days')));
        break;
    case 'unpaid':
        // Anyone who owes money, moved-out tenants included (the dashboard's "Total Unpaid Rent").
        $where[] = "x.balance > 0"; break;
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
// Rooms under maintenance aren't offered at all.
$roomsStmt = $pdo->query("SELECT r.id, r.room_number, r.capacity, r.price_per_month, (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id AND t.status = 'active') as occupied FROM rooms r WHERE r.status <> 'maintenance'");
$allRooms = $roomsStmt->fetchAll();
usort($allRooms, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));   // 1, 2, 10 - not 1, 10, 2

require_once 'header.php';
?>

<?php if (!empty($error) || !empty($success)): ?>
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
<?php endif; ?>

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
    tr.tenant-row[data-href] { cursor: pointer; }
    tr.tenant-row[data-href] .row-actions { cursor: default; }
    tr.tenant-row[data-href]:hover .tenant-link { color: #2563eb !important; text-decoration: underline !important; }
    .movein-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.6rem 0.75rem; }
    .movein-box .form-check-label { font-size: 0.75rem; font-weight: 600; color: #0f172a; margin-bottom: 0; }
</style>

<!-- Header Section -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <p class="text-muted mb-0" style="font-size: 0.85rem;">Add new tenants and manage existing tenant information.</p>
    <button type="button" class="btn btn-primary shadow-sm fw-semibold px-4" data-bs-toggle="modal" data-bs-target="#addTenantModal">
        <i class="fa-solid fa-user-plus me-2"></i> Add New Tenant
    </button>
</div>

<!-- Summary cards: each one filters the list below -->
<?= statCards([
    ['label' => 'Active Tenants', 'value' => (int)$totalTenants,
     'icon' => 'fa-solid fa-users', 'color' => 'primary', 'href' => 'tenants.php', 'active' => $filterStatus === 'all', 'aria' => 'Show all tenants'],
    ['label' => 'Paid', 'value' => $tenantCounts['paid'],
     'icon' => 'fa-solid fa-circle-check', 'color' => 'success', 'href' => 'tenants.php?status=paid', 'active' => $filterStatus === 'paid', 'aria' => 'Show tenants who are paid up'],
    ['label' => 'Unpaid', 'value' => $tenantCounts['due'],
     'icon' => 'fa-regular fa-clock', 'color' => 'warning', 'href' => 'tenants.php?status=due', 'active' => $filterStatus === 'due', 'aria' => 'Show tenants with rent not yet due'],
    ['label' => 'Overdue', 'value' => $tenantCounts['overdue'],
     'icon' => 'fa-solid fa-triangle-exclamation', 'color' => 'danger', 'href' => 'tenants.php?status=overdue', 'active' => $filterStatus === 'overdue', 'aria' => 'Show overdue tenants'],
]) ?>

<!-- Main Content Grid -->
<div class="row g-4">
    
    <!-- Tenant List (full width; the add form is in a pop-up) -->
    <div class="col-12">
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
                
                <form method="GET" id="filterForm" class="d-flex flex-wrap gap-2">
                    <div class="input-group input-group-sm rounded-2 border bg-white" style="width:200px;">
                        <span class="input-group-text bg-transparent border-0 pe-1"><i class="fa-solid fa-magnifying-glass text-muted" style="font-size:0.65rem;"></i></span>
                        <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($filterSearch) ?>" class="form-control border-0 shadow-none px-1" placeholder="Search tenants..." style="font-size:0.7rem;">
                    </div>
                    <select name="status" class="form-select form-select-sm border rounded-2 shadow-none text-muted" style="width:140px; font-size:0.7rem;" onchange="this.form.submit()">
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="paid" <?= $filterStatus === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="due" <?= $filterStatus === 'due' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="due_soon" <?= $filterStatus === 'due_soon' ? 'selected' : '' ?>>Due in <?= REMINDER_DAYS_BEFORE ?> days</option>
                        <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>All unpaid</option>
                        <option value="deactivated" <?= $filterStatus === 'deactivated' ? 'selected' : '' ?>>Deactivated</option>
                    </select>

                    <?php
                        // Keep the search when toggling, but go back to page 1: the row count
                        // changes, so the current page number may not exist.
                        $toggleUrl = pageUrl([
                            'status' => $filterStatus === 'deactivated' ? null : 'deactivated',
                            'deactivated' => null,
                            'page' => null,
                        ]);
                    ?>
                    <?php if ($filterStatus === 'deactivated'): ?>
                        <a href="<?= htmlspecialchars($toggleUrl) ?>" class="btn btn-sm btn-secondary rounded-2 px-2 text-nowrap" style="font-size:0.7rem;" title="Back to current tenants">
                            <i class="fa-solid fa-eye-slash me-1"></i> Hide deactivated
                        </a>
                    <?php elseif ($deactivatedCount > 0): ?>
                        <a href="<?= htmlspecialchars($toggleUrl) ?>" class="btn btn-sm btn-outline-secondary rounded-2 px-2 text-nowrap" style="font-size:0.7rem;" title="Show deactivated tenants">
                            <i class="fa-solid fa-eye me-1"></i> Show deactivated (<?= $deactivatedCount ?>)
                        </a>
                    <?php endif; ?>

                    <?php if ($filterSearch !== '' || $filterStatus !== 'all'): ?>
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
                            // Moved in, owes money, and hasn't paid anything since: the advance and
                            // deposit are still outstanding and can be recorded as received.
                            $moveInDue = null;
                            if (!$isDeactivated && $t['room_id'] && $t['move_in_date'] && $balance > 0 && (int)$t['stay_payments'] === 0) {
                                $moveInDue = moveInAmountDue($pdo, (int)$t['id']);
                                if ($moveInDue['amount'] <= 0) $moveInDue = null;
                            }
                            $rowData = htmlspecialchars(json_encode([
                                'id' => (int)$t['id'],
                                'name' => $t['first_name'] . ' ' . $t['last_name'],
                                'first_name' => $t['first_name'],
                                'last_name' => $t['last_name'],
                                'profile' => array_combine(TENANT_PROFILE_COLUMNS, array_map(fn($c) => $t[$c] ?? '', TENANT_PROFILE_COLUMNS)),
                                'room_id' => $t['room_id'] ? (int)$t['room_id'] : null,
                                'room_number' => $t['room_number'],
                                'has_move_in' => !empty($t['move_in_date']),
                                'balance' => $balance,
                                'movein_due' => $moveInDue,
                            ]), ENT_QUOTES);
                        ?>
                        <tr class="tenant-row <?= $isDeactivated ? 'opacity-75' : '' ?>" data-href="tenant_details.php?id=<?= (int)$t['id'] ?>" data-status="<?= $bs['key'] ?>" data-search="<?= htmlspecialchars(strtolower($t['first_name'].' '.$t['last_name'].' '.$t['username'])) ?>">
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <?= avatarHtml($t['first_name'] . ' ' . $t['last_name'], 28, 'me-2', $t['profile_picture'] ?? null, '../') ?>
                                    <a href="tenant_details.php?id=<?= (int)$t['id'] ?>" class="fw-bold text-dark text-truncate text-decoration-none tenant-link" style="max-width:130px;"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></a>
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
                            <td class="text-end pe-4 text-nowrap row-actions" data-tenant="<?= $rowData ?>">
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
                                    <?php if ($moveInDue): ?>
                                    <button type="button" onclick="openMoveInModal(this)" class="btn btn-sm btn-outline-success rounded-1 px-1 py-0 me-1" title="Record move-in payment (advance + deposit)">
                                        <i class="fa-solid fa-money-bill-wave" style="font-size: 0.65rem;"></i>
                                    </button>
                                    <?php endif; ?>
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
        // Rent per bed: the room price divided by its capacity.
        $share = rentShare((float)$r['price_per_month'], (int)$r['capacity']);
        $label = 'Room ' . htmlspecialchars($r['room_number'] ?? '')
               . ($isFull ? ' - FULL' : " (Avail: $avail | ₱" . number_format($share, 2) . " per tenant)");
        $html .= '<option value="' . (int)$r['id'] . '" data-full="' . ($isFull ? 1 : 0) . '" data-share="' . $share . '"' . ($isFull ? ' disabled' : '') . '>' . $label . '</option>';
    }
    return $html;
}

// "Advance & deposit received" for a form that moves a tenant in. Ticked by default: tenants
// pay the first month and the deposit when they move in.
function moveInPaymentFields(string $id, bool $startHidden = false): string {
    $id = htmlspecialchars($id);
    return '
                    <div class="movein-box mb-3' . ($startHidden ? ' d-none' : '') . '" id="' . $id . '" data-movein>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="movein_paid" value="1" id="' . $id . '_paid" checked>
                            <label class="form-check-label" for="' . $id . '_paid">Advance &amp; deposit received</label>
                        </div>
                        <div class="movein-estimate text-muted mb-2" style="font-size:0.68rem;"></div>
                        <div class="movein-details row g-2">
                            <div class="col-5">
                                <label class="form-label mb-1" for="' . $id . '_method" style="font-size:0.68rem;">Paid by</label>
                                <select class="form-select" name="movein_method" id="' . $id . '_method">
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </div>
                            <div class="col-7">
                                <label class="form-label mb-1" for="' . $id . '_ref" style="font-size:0.68rem;">Reference no. (optional)</label>
                                <input type="text" class="form-control" name="movein_reference" id="' . $id . '_ref" maxlength="100">
                            </div>
                        </div>
                        <small class="text-muted d-block mt-2" style="font-size:0.62rem;">Recorded as a verified payment with a receipt, so the tenant starts fully paid. Untick if they will pay later.</small>
                    </div>';
}
?>

<!-- Add Tenant Modal -->
<div class="modal fade" id="addTenantModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
                <form method="POST" data-movein-form>
                    <input type="hidden" name="action" value="add">
                    <div class="modal-header bg-light border-0">
                        <h6 class="modal-title fw-bold"><i class="fa-solid fa-user-plus text-primary me-2"></i>Add New Tenant</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 form-compact">
                    <!-- Desktop: profile on the left, room and payment on the right, so it all fits without scrolling -->
                    <div class="row gx-4">
                    <div class="col-lg-7">
                    <?= tenantProfileFormFields($addOld, 'add', true, false) ?>
                    </div>

                    <div class="col-lg-5">
                    <div class="profile-section-title">Room &amp; move-in</div>
                    <div class="row g-2">
                    <div class="col-sm-7 col-lg-12">
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
                                        // Rent per bed: the room price divided by its capacity.
                                        $share = rentShare((float)$r['price_per_month'], (int)$r['capacity']);
                                        $label .= " (Avail: $avail | ₱" . number_format($share, 2) . " per tenant)";
                                    }
                                ?>
                                <option value="<?= $r['id'] ?>" <?= (int)($addOld['room_id'] ?? queryParam('room', '0')) === (int)$r['id'] && !$isFull ? 'selected' : '' ?> data-share="<?= $isFull ? '' : rentShare((float)$r['price_per_month'], (int)$r['capacity']) ?>" <?= $isFull ? 'disabled' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    </div>
                    <div class="col-sm-5 col-lg-12">
                    <div class="mb-3">
                        <label>Move-in Date</label>
                        <input type="date" class="form-control" name="move_in_date" value="<?= htmlspecialchars($addOld['move_in_date'] ?? date('Y-m-d')) ?>">
                        <small class="text-muted d-block mt-1" style="font-size:0.62rem;">Used when a room is assigned. Day 1–15: full first month. Day 16+: half. Due every 30th.</small>
                    </div>
                    </div>
                    </div>
                    <?= moveInPaymentFields('add_movein', true) ?>
                    <small class="text-muted d-block" style="font-size:0.65rem;"><i class="fa-solid fa-key me-1"></i>A username and temporary password are created automatically, shown after saving and texted to the tenant.</small>
                    </div>
                    </div>
                    </div>
                    <div class="modal-footer border-0 bg-light">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4" style="font-size:0.8rem; font-weight:600;"><i class="fa-solid fa-plus me-2"></i>Create Tenant</button>
                    </div>
                </form>
        </div>
    </div>
</div>

<!-- Edit Tenant Modal: the same fields as Add Tenant (and the tenant's own Profile Settings) -->
<div class="modal fade" id="editTenantModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="POST" id="editTenantForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="tenant_id" id="edit_tenant_id">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold"><i class="fa-solid fa-user-pen text-primary me-2"></i>Edit Tenant Details</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 form-compact">
                    <?= tenantProfileFormFields([], 'edit') ?>
                    <small class="text-muted d-block mt-2" style="font-size:0.65rem;"><i class="fa-solid fa-circle-info me-1"></i>To move the tenant, use the <i class="fa-solid fa-right-left"></i> Change Room button.</small>
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
            <form method="POST" data-movein-form>
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
                    <?= moveInPaymentFields('room_movein', true) ?>
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
            <form method="POST" data-movein-form>
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
                    <div class="mb-3">
                        <label>Move-in Date</label>
                        <input type="date" class="form-control" name="move_in_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <?= moveInPaymentFields('react_movein') ?>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                    <button type="submit" class="btn btn-success px-4" style="font-size:0.8rem;">Re-activate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Move-in Payment Modal -->
<div class="modal fade" id="moveInModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="movein_payment">
                <input type="hidden" name="tenant_id" id="mi_tenant_id">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold text-success"><i class="fa-solid fa-money-bill-wave me-2"></i>Record Move-in Payment</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 form-compact">
                    <p style="font-size:0.8rem;" class="mb-2"><strong id="mi_name"></strong> paid their advance and deposit:</p>
                    <table class="table table-sm mb-3" style="font-size:0.78rem;">
                        <tr><td class="text-muted">First month (advance)</td><td class="text-end" id="mi_advance"></td></tr>
                        <tr><td class="text-muted">Deposit (for the last month)</td><td class="text-end" id="mi_deposit"></td></tr>
                        <tr class="fw-bold"><td>To record as paid</td><td class="text-end text-success" id="mi_amount"></td></tr>
                    </table>
                    <div class="row g-2">
                        <div class="col-5">
                            <label>Method</label>
                            <select class="form-select" name="movein_method">
                                <option value="cash">Cash</option>
                                <option value="gcash">GCash</option>
                            </select>
                        </div>
                        <div class="col-7">
                            <label>Reference No. <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" class="form-control" name="movein_reference" maxlength="100">
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2" style="font-size:0.65rem;">A verified payment and receipt are created, and the tenant's balance drops by this amount.</small>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                    <button type="submit" class="btn btn-success px-4" style="font-size:0.8rem;">Record Payment</button>
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
    const form = document.getElementById('editTenantForm');
    for (const [key, value] of Object.entries(t.profile)) {
        if (form.elements[key]) form.elements[key].value = value ?? '';
    }
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
    // Only a first assignment is a move-in with an advance and deposit to collect.
    document.getElementById('room_movein').classList.toggle('d-none', !firstAssignment);
    document.getElementById('room_movein_paid').disabled = !firstAssignment;
    updateMoveIn(select.form);
    showModal('roomModal');
}

function openMoveInModal(btn) {
    const t = tenantData(btn);
    document.getElementById('mi_tenant_id').value = t.id;
    document.getElementById('mi_name').textContent = t.name;
    document.getElementById('mi_advance').textContent = peso(t.movein_due.advance);
    document.getElementById('mi_deposit').textContent = peso(t.movein_due.deposit);
    document.getElementById('mi_amount').textContent = peso(t.movein_due.amount);
    showModal('moveInModal');
}

// The advance and deposit for the room and date picked: a full share each, or half a share
// for the first month when moving in after the <?= HALF_MONTH_CUTOFF_DAY ?>th. The server works
// out the exact figures when the form is saved.
function updateMoveIn(form) {
    const box = form.querySelector('[data-movein]');
    if (!box) return;
    const room = form.querySelector('select[name="room_id"]');
    const dateInput = form.querySelector('input[name="move_in_date"], input[name="effective_date"]');
    const opt = room && room.selectedOptions[0];
    const share = opt && opt.value ? parseFloat(opt.dataset.share) : NaN;

    // On the add form the block only matters once a room is picked.
    if (form.querySelector('input[name="action"]').value === 'add') {
        box.classList.toggle('d-none', !(opt && opt.value));
    }
    const estimate = box.querySelector('.movein-estimate');
    if (isNaN(share)) {
        estimate.textContent = 'Pick a room to see the amount.';
    } else {
        const day = dateInput && dateInput.value ? Number(dateInput.value.slice(8, 10)) : new Date().getDate();
        const advance = day > <?= HALF_MONTH_CUTOFF_DAY ?> ? share / 2 : share;
        estimate.textContent = 'Advance ' + peso(advance) + ' + deposit ' + peso(share) + ' = ' + peso(advance + share);
    }
    const paid = box.querySelector('input[name="movein_paid"]');
    box.querySelector('.movein-details').classList.toggle('d-none', !paid.checked);
}
<?php if ($openAddModal): ?>
document.addEventListener('DOMContentLoaded', () => showModal('addTenantModal'));
<?php endif; ?>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-movein-form]').forEach(function (form) {
        form.addEventListener('change', () => updateMoveIn(form));
        updateMoveIn(form);
    });
});

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
    updateMoveIn(document.getElementById('react_room').form);
    showModal('reactivateModal');
}

// Clicking anywhere on a tenant's row opens their details, except on the action buttons.
// Ctrl/Cmd-click or a middle click opens it in a new tab, like a link would.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('tr.tenant-row[data-href]').forEach(function (row) {
        const open = function (e) {
            if (e.target.closest('a, button, input, select, label, .row-actions')) return;   // their own job
            if (window.getSelection && String(window.getSelection()).length > 0) return;     // selecting text
            if (e.ctrlKey || e.metaKey || e.button === 1) {
                window.open(row.dataset.href, '_blank');
            } else if (e.button === 0) {
                window.location = row.dataset.href;
            }
        };
        row.addEventListener('click', open);
        row.addEventListener('auxclick', open);
    });
});

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
