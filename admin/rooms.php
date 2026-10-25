<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
require_once '../includes/tenant_actions.php';
require_once '../includes/ui.php';
require_once '../includes/pagination.php';
requireAdmin();

$error = '';
$success = '';
$selectedRoom = isset($_GET['room']) ? (int)$_GET['room'] : 0;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $roomActions = ['add', 'edit', 'delete'];
    $isRoomAction = in_array($action, $roomActions, true) && !isset($_POST['tenant_id']) && !isset($_POST['first_name']);

    if ($isRoomAction) {
        // price_per_month is the price of the whole room when full; each tenant pays price / capacity.
        $roomNumber = trim($_POST['room_number'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 0);
        $roomPrice = round((float)($_POST['price_per_month'] ?? 0), 2);
        $roomError = '';

        if ($action !== 'delete') {
            if ($roomNumber === '') {
                $roomError = 'Room number is required.';
            } elseif ($capacity < 1) {
                $roomError = 'Capacity must be at least 1 tenant.';
            } elseif ($roomPrice < 0) {
                $roomError = 'Room price cannot be negative.';
            }
        }

        if ($roomError) {
            header("Location: rooms.php?err=" . urlencode($roomError));
            exit;
        }

        try {
            if ($action === 'add') {
                $newRoomId = insertReturningId($pdo, "INSERT INTO rooms (room_number, capacity, price_per_month, status) VALUES (?, ?, ?, ?)",
                                               [$roomNumber, $capacity, $roomPrice, 'vacant']);
                notifyAdmin($pdo, 'room_available', "New room $roomNumber is available",
                    $capacity . ($capacity === 1 ? ' bed' : ' beds') . ', PHP ' . number_format(rentShare($roomPrice, $capacity), 2) . ' per bed a month.',
                    "rooms.php?room=$newRoomId");
            } elseif ($action === 'edit') {
                $roomId = (int)($_POST['room_id'] ?? 0);
                $status = in_array($_POST['status'] ?? '', ['vacant', 'occupied', 'maintenance'], true) ? $_POST['status'] : 'vacant';
                $oldStatus = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
                $oldStatus->execute([$roomId]);
                $oldStatus = $oldStatus->fetchColumn();
                $stmt = $pdo->prepare("UPDATE rooms SET room_number = ?, capacity = ?, price_per_month = ?, status = ? WHERE id = ?");
                $stmt->execute([$roomNumber, $capacity, $roomPrice, $status, $roomId]);
                if ($status === 'maintenance' && $oldStatus !== 'maintenance') {
                    notifyAdmin($pdo, 'room_maintenance', "Room $roomNumber is under maintenance",
                        'It is no longer offered when assigning tenants.', "rooms.php?room=$roomId");
                } elseif ($status !== 'maintenance' && $oldStatus === 'maintenance') {
                    $free = max(0, $capacity - roomOccupantCount($pdo, $roomId));
                    notifyAdmin($pdo, 'room_available', "Room $roomNumber is back from maintenance",
                        $free ? ($free === 1 ? '1 free bed.' : "$free free beds.") : 'All beds are taken.', "rooms.php?room=$roomId");
                }
            } elseif ($action === 'delete') {
                $pdo->prepare("UPDATE tenants SET room_id = NULL WHERE room_id = ?")->execute([$_POST['room_id']]);
                $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$_POST['room_id']]);
            }
        } catch (PDOException $e) {
            header("Location: rooms.php?err=" . urlencode("Could not save the room. Room number '$roomNumber' may already be taken."));
            exit;
        }
        header("Location: rooms.php");
        exit;
    }

    // Tenant actions (change room / remove), shared with the Tenants tab
    ['success' => $success, 'error' => $error] = handleTenantAction($pdo);
    $back = 'rooms.php?room=' . (int)($_POST['return_room'] ?? 0);
    header("Location: $back&" . ($error ? 'err=' . urlencode($error) : 'msg=' . urlencode(html_entity_decode(strip_tags($success)))));
    exit;
}
if (!empty($_GET['msg'])) $success = htmlspecialchars($_GET['msg'] ?? '');
if (!empty($_GET['err'])) $error = htmlspecialchars($_GET['err'] ?? '');

// Fetch all rooms, in natural room-number order: 1, 2, 10, 101 (the column is text, so SQL
// alone would put "10" before "2").
$roomsStmt = $pdo->query("SELECT * FROM rooms");
$rooms = $roomsStmt->fetchAll();
usort($rooms, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));

// Fetch all assigned tenants
$tenantsByRoom = [];
$tenantsStmt = $pdo->query("SELECT t.id, t.first_name, t.last_name, t.room_id, t.balance, t.move_in_date, t.profile_picture,
    COALESCE(t.move_in_date, " . sqlToDate('u.created_at') . ") AS created_at
    FROM tenants t JOIN users u ON t.user_id = u.id
    WHERE t.room_id IS NOT NULL AND t.status = 'active'
    ORDER BY t.first_name");
foreach ($tenantsStmt->fetchAll() as $t) {
    $tenantsByRoom[$t['room_id']][] = $t;
}

// Calculate Stats
// rooms.price_per_month is the price of the whole room when every bed is taken. Each tenant
// pays one bed's part, price_per_month / capacity, so a room earns that per occupied bed and
// its full price only when full.
$totalRooms = count($rooms);
$totalCapacity = 0;
$potentialValue = 0;   // if every bed were taken
$currentValue = 0;     // from the beds taken now
$occupiedRooms = 0;      // full rooms
$roomsWithSpace = 0;     // can take someone now (not under maintenance)
$freeBeds = 0;
$maintenanceRooms = 0;

foreach ($rooms as &$r) {
    $totalCapacity += $r['capacity'];
    $potentialValue += $r['price_per_month'];
    $occCount = isset($tenantsByRoom[$r['id']]) ? count($tenantsByRoom[$r['id']]) : 0;
    $currentValue += rentShare((float)$r['price_per_month'], (int)$r['capacity']) * min($occCount, max(1, (int)$r['capacity']));
    if ($r['status'] === 'maintenance') {
        $maintenanceRooms++;   // set by hand; never changed here
        continue;
    }
    // Occupied / vacant follows who lives there.
    $isFull = $occCount >= $r['capacity'] && $r['capacity'] > 0;
    $derived = $isFull ? 'occupied' : 'vacant';
    if ($r['status'] !== $derived) {
        $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?")->execute([$derived, $r['id']]);
        $r['status'] = $derived;
    }
    if ($isFull) {
        $occupiedRooms++;
    } else {
        $roomsWithSpace++;
        $freeBeds += (int)$r['capacity'] - $occCount;
    }
}
unset($r);
$showFilter = in_array(queryParam('show'), ['available', 'space', 'partial', 'occupied', 'maintenance'], true) ? queryParam('show') : 'all';

require_once 'header.php';
?>

<style>
    .rooms-table { font-size: 0.8rem; }
    .rooms-table thead th { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; font-weight: 700; white-space: nowrap; }
    .rooms-table thead th.sortable { cursor: pointer; user-select: none; }
    .rooms-table thead th.sortable:hover { color: #1e293b; }
    .rooms-table thead th .sort-icon { font-size: 0.6rem; margin-left: 4px; opacity: 0.35; }
    .rooms-table thead th.sorted .sort-icon { opacity: 1; color: #2563eb; }
    .room-row { cursor: pointer; }
    .room-row td { vertical-align: middle; }
    .room-row.active td { background-color: #eff6ff !important; }
    .room-row.active td:first-child { box-shadow: inset 3px 0 0 #2563eb; }
    .tenant-item {
        transition: background-color 0.2s;
    }
    .tenant-item:hover {
        background-color: #f1f5f9;
    }
    .right-panel-scroll::-webkit-scrollbar {
        width: 6px;
    }
    .right-panel-scroll::-webkit-scrollbar-thumb {
        background-color: #cbd5e1;
        border-radius: 10px;
    }
</style>

<div class="d-flex w-100 h-100 bg-transparent">
    
    <!-- ==================== LEFT / MIDDLE CONTENT (Grid) ==================== -->
    <div class="flex-grow-1 p-4 overflow-auto h-100">
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert" style="font-size: 0.85rem;">
                <i class="fa-solid fa-circle-check me-2"></i><?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert" style="font-size: 0.85rem;">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 gap-2 flex-wrap">
            <div>
                <p class="text-muted mb-0">Organize and monitor all rooms in your boarding house.</p>
            </div>
            <div>
                <button class="btn btn-primary shadow-sm fw-semibold px-4" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                    <i class="fa-solid fa-plus me-2"></i> Add New Room
                </button>
            </div>
        </div>

        <!-- Stats Row: each card filters the list below -->
        <?= statCards([
            ['label' => 'Total Rooms', 'value' => $totalRooms, 'sub' => "$totalCapacity beds" . ($maintenanceRooms ? " · $maintenanceRooms under maintenance" : ''),
             'icon' => 'fa-solid fa-door-open', 'color' => 'primary', 'href' => 'rooms.php', 'active' => $showFilter === 'all', 'aria' => 'Show all rooms'],
            ['label' => 'With Free Beds', 'value' => $roomsWithSpace, 'sub' => $freeBeds . ' free ' . ($freeBeds === 1 ? 'bed' : 'beds'), 'subClass' => 'text-success',
             'icon' => 'fa-solid fa-bed', 'color' => 'success', 'href' => 'rooms.php?show=space', 'active' => $showFilter === 'space', 'aria' => 'Show rooms with free beds'],
            ['label' => 'Full Rooms', 'value' => $occupiedRooms, 'sub' => 'Every bed taken',
             'icon' => 'fa-solid fa-door-closed', 'color' => 'secondary', 'href' => 'rooms.php?show=occupied', 'active' => $showFilter === 'occupied', 'aria' => 'Show full rooms'],
            ['label' => 'Monthly Income Now', 'value' => peso((float)$currentValue), 'sub' => peso((float)$potentialValue) . ' if every bed is taken',
             'icon' => 'fa-solid fa-peso-sign', 'color' => 'warning', 'href' => 'reports.php?tab=financial', 'aria' => 'Open the financial report'],
        ]) ?>

        <!-- Filters -->
        <div class="d-flex flex-column flex-md-row gap-3 mb-4">
            <div class="input-group bg-white rounded-3 shadow-sm" style="max-width: 350px; border: none;">
                <span class="input-group-text bg-transparent border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" class="form-control border-0 bg-transparent ps-0" placeholder="Search rooms..." id="roomSearch" oninput="applyRoomFilters()">
            </div>
            <select class="form-select bg-white border-0 shadow-sm w-auto fw-semibold text-dark" id="statusFilter" onchange="applyRoomFilters()">
                <option value="all">All Status</option>
                <option value="space">With free beds</option>
                <option value="available">Available (empty)</option>
                <option value="partial">Partial</option>
                <option value="occupied">Occupied (full)</option>
                <option value="maintenance">Maintenance</option>
            </select>
            <select class="form-select bg-white border-0 shadow-sm w-auto fw-semibold text-dark" id="roomSort" onchange="sortRooms(this.value)" aria-label="Sort rooms">
                <option value="number">Sort: Room number</option>
                <option value="space">Sort: Most free beds</option>
                <option value="price-asc">Sort: Price, low to high</option>
                <option value="price-desc">Sort: Price, high to low</option>
                <option value="occupancy">Sort: Fullest first</option>
            </select>
        </div>

        <!-- Rooms List -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 rooms-table">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3 sortable sorted" data-sort="number" onclick="sortRooms('number')">Room <i class="fa-solid fa-sort sort-icon"></i></th>
                            <th>Status</th>
                            <th class="sortable" data-sort="occupancy" onclick="sortRooms('occupancy')">Occupancy <i class="fa-solid fa-sort sort-icon"></i></th>
                            <th class="sortable text-end" data-sort="price-desc" onclick="sortRooms(currentSort === 'price-desc' ? 'price-asc' : 'price-desc')">Price / Room <i class="fa-solid fa-sort sort-icon"></i></th>
                            <th>Tenants</th>
                            <th class="pe-3"></th>
                        </tr>
                    </thead>
                    <tbody id="roomList">
                        <?php if (count($rooms) === 0): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No rooms found. Add a new room to get started.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($rooms as $index => $r): ?>
                            <?php
                                $roomTenants = $tenantsByRoom[$r['id']] ?? [];
                                $occCount = count($roomTenants);
                                $occPct = $r['capacity'] > 0 ? min(100, round(($occCount / $r['capacity']) * 100)) : 0;

                                // The status shown (and filtered on) comes from who actually lives there.
                                if ($r['status'] == 'maintenance') {
                                    $badgeClass = 'badge-soft-danger'; $badgeText = 'Maintenance'; $statusKey = 'maintenance';
                                } elseif ($occCount >= $r['capacity'] && $r['capacity'] > 0) {
                                    $badgeClass = 'badge-soft-success'; $badgeText = 'Occupied'; $statusKey = 'occupied';
                                } elseif ($occCount > 0) {
                                    $badgeClass = 'badge-soft-info'; $badgeText = 'Partial'; $statusKey = 'partial';
                                } else {
                                    $badgeClass = 'badge-soft-warning'; $badgeText = 'Available'; $statusKey = 'available';
                                }
                                $isActive = $selectedRoom ? $selectedRoom === (int)$r['id'] : $index === 0;
                                $names = array_map(fn($t) => $t['first_name'], $roomTenants);
                            ?>
                            <tr class="room-row <?= $isActive ? 'active' : '' ?>" id="row-<?= $r['id'] ?>" onclick="selectRoom(<?= $r['id'] ?>)"
                                data-status="<?= $statusKey ?>" data-search="<?= htmlspecialchars(strtolower($r['room_number'] . ' ' . implode(' ', $names))) ?>"
                                data-order="<?= $index ?>" data-price="<?= (float)$r['price_per_month'] ?>"
                                data-free="<?= max(0, $r['capacity'] - $occCount) ?>" data-occ="<?= $occPct ?>">
                                <td class="ps-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary bg-opacity-10 text-primary rounded d-flex justify-content-center align-items-center me-2 flex-shrink-0" style="width: 30px; height: 30px;">
                                            <i class="fa-solid fa-door-open" style="font-size: 0.8rem;"></i>
                                        </div>
                                        <span class="fw-bold text-dark text-nowrap">Room <?= htmlspecialchars($r['room_number'] ?? '') ?></span>
                                    </div>
                                </td>
                                <td><span class="badge <?= $badgeClass ?>" style="font-size: 0.65rem;"><?= $badgeText ?></span></td>
                                <td style="min-width: 120px;">
                                    <div class="d-flex justify-content-between mb-1" style="font-size: 0.72rem;">
                                        <span class="text-muted"><i class="fa-solid fa-user me-1"></i><?= $occCount ?>/<?= $r['capacity'] ?></span>
                                        <span class="fw-semibold text-primary"><?= $occPct ?>%</span>
                                    </div>
                                    <div class="progress rounded-pill bg-light" style="height: 4px;">
                                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $occPct ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <div class="fw-bold text-dark">₱<?= number_format($r['price_per_month'], 2) ?></div>
                                    <div class="text-muted" style="font-size: 0.68rem;"><?= $r['capacity'] > 1
                                        ? '₱' . number_format(rentShare((float)$r['price_per_month'], (int)$r['capacity']), 2) . ' per tenant (÷ ' . (int)$r['capacity'] . ' beds)'
                                        : 'one tenant' ?></div>
                                </td>
                                <td>
                                    <?php if ($occCount === 0): ?>
                                        <span class="text-muted fst-italic" style="font-size: 0.75rem;">No tenants yet</span>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <?php foreach (array_slice($roomTenants, 0, 3) as $t): ?>
                                                <span title="<?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?>"><?= avatarHtml($t['first_name'] . ' ' . $t['last_name'], 24, 'shadow-sm', $t['profile_picture'] ?? null, '../') ?></span>
                                            <?php endforeach; ?>
                                            <span class="text-muted text-truncate ms-1 d-inline-block" style="font-size: 0.72rem; max-width: 140px;"><?= htmlspecialchars(implode(', ', $names)) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-3 text-end"><i class="fa-solid fa-chevron-right text-muted" style="font-size: 0.7rem;"></i></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="noRoomMatch" class="d-none"><td colspan="6" class="text-center py-4 text-muted">No rooms match your search.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ==================== RIGHT PANEL (Details) ==================== -->
    <div class="bg-white border-start h-100 right-panel-scroll shadow-sm d-none d-lg-block" id="rightDetailsContainer" style="width: 360px; min-width: 360px; overflow-y: auto;">
        
        <?php if (count($rooms) === 0): ?>
            <div class="d-flex h-100 justify-content-center align-items-center p-4 text-muted">
                Select a room to view details.
            </div>
        <?php endif; ?>

        <?php foreach ($rooms as $index => $r): ?>
            <?php
                // Only the open room's panel is built; the others are a link away (?room=id).
                $isOpenPanel = $selectedRoom ? $selectedRoom === (int)$r['id'] : $index === 0;
                if (!$isOpenPanel) continue;

                $roomTenants = $tenantsByRoom[$r['id']] ?? []; 
                $occCount = count($roomTenants);
                $occPct = $r['capacity'] > 0 ? min(100, round(($occCount / $r['capacity']) * 100)) : 0;
                
                if ($r['status'] == 'maintenance') {
                    $badgeClass = 'badge-soft-danger';
                    $badgeText = 'Maintenance';
                } elseif ($occCount >= $r['capacity'] && $r['capacity'] > 0) {
                    $badgeClass = 'badge-soft-success';
                    $badgeText = 'Occupied';
                } elseif ($occCount > 0) {
                    $badgeClass = 'badge-soft-info';
                    $badgeText = 'Partial';
                } else {
                    $badgeClass = 'badge-soft-warning';
                    $badgeText = 'Available';
                }
            ?>
            <div class="room-details-panel d-flex flex-column h-100 p-3" id="panel-<?= $r['id'] ?>">
                
                <!-- Panel Header -->
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex justify-content-center align-items-center me-2" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-building"></i>
                        </div>
                        <div class="d-flex align-items-center">
                            <h5 class="fw-bold mb-0 text-dark me-2">Room <?= htmlspecialchars($r['room_number'] ?? '') ?></h5>
                            <span class="badge <?= $badgeClass ?> ms-1" style="font-size: 0.65rem;"><?= $badgeText ?></span>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-light border-0 rounded-circle text-muted" onclick="closeDetailsPanel()"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <!-- Stats List -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                        <span class="text-muted fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-users me-2"></i> Capacity</span>
                        <span class="fw-bold text-dark" style="font-size: 0.8rem;"> <?= $r['capacity'] ?> Persons</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                        <span class="text-muted fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-peso-sign me-2"></i> Price / Room</span>
                        <span class="fw-bold text-dark" style="font-size: 0.8rem;">₱<?= number_format($r['price_per_month'], 2) ?> <span class="text-muted fw-normal">/ month</span></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                        <span class="text-muted fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-user me-2"></i> Rent / Tenant</span>
                        <span class="fw-bold text-dark" style="font-size: 0.8rem;">₱<?= number_format(rentShare((float)$r['price_per_month'], (int)$r['capacity']), 2) ?> <span class="text-muted fw-normal">/ month<?= $r['capacity'] > 1 ? ' (÷ ' . (int)$r['capacity'] . ' beds)' : '' ?></span></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                        <span class="text-muted fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-sack-dollar me-2"></i> Earning Now</span>
                        <span class="fw-bold <?= $occCount ? 'text-success' : 'text-muted' ?>" style="font-size: 0.8rem;">₱<?= number_format(rentShare((float)$r['price_per_month'], (int)$r['capacity']) * $occCount, 2) ?> <span class="text-muted fw-normal">/ month<?= $occCount < $r['capacity'] ? ' (₱' . number_format($r['price_per_month'], 0) . ' when full)' : '' ?></span></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                        <span class="text-muted fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-lock me-2"></i> Status</span>
                        <span class="fw-bold text-dark" style="font-size: 0.8rem;"><?= ucfirst($r['status']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                        <span class="text-muted fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-chart-pie me-2"></i> Occupancy</span>
                        <div class="text-end" style="width: 120px;">
                            <div class="d-flex justify-content-between mb-1" style="font-size: 0.7rem;">
                                <span class="fw-semibold text-muted"><i class="fa-solid fa-user-group me-1"></i> <?= $occCount ?>/<?= $r['capacity'] ?></span>
                                <span class="fw-bold text-primary">(<?= $occPct ?>%)</span>
                            </div>
                            <div class="progress rounded-pill bg-light" style="height: 4px;">
                                <div class="progress-bar bg-primary" style="width: <?= $occPct ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tenants Header -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0 text-dark" style="font-size: 0.8rem;">Tenants (<?= $occCount ?>)</h6>
                    <a href="tenants.php" class="btn btn-sm btn-outline-primary fw-semibold rounded-pill px-2 py-0" style="font-size: 0.7rem; line-height: 1.5;">
                        <i class="fa-solid fa-plus me-1"></i> Add Tenant
                    </a>
                </div>
                
                <!-- Tenants List -->
                <div class="tenant-list flex-grow-1">
                    <?php if ($occCount === 0): ?>
                        <div class="text-center py-3 text-muted bg-light rounded-3" style="font-size: 0.75rem;">No tenants assigned yet.</div>
                    <?php endif; ?>
                    
                    <?php foreach ($roomTenants as $t):
                        $tData = htmlspecialchars(json_encode([
                            'id' => (int)$t['id'],
                            'name' => $t['first_name'] . ' ' . $t['last_name'],
                            'room_id' => (int)$t['room_id'],
                            'room_number' => $r['room_number'],
                            'balance' => round((float)$t['balance'], 2),
                            'return_room' => (int)$r['id'],
                        ]), ENT_QUOTES);
                    ?>
                        <div class="d-flex align-items-center justify-content-between p-1 mb-1 tenant-item rounded" data-tenant="<?= $tData ?>">
                            <div class="d-flex align-items-center">
                                <?= avatarHtml($t['first_name'] . ' ' . $t['last_name'], 30, 'me-2 shadow-sm', $t['profile_picture'] ?? null, '../') ?>
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark" style="font-size: 0.75rem; line-height:1.2;"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></h6>
                                    <small class="text-muted" style="font-size: 0.65rem;">Since <?= date('M j, Y', strtotime($t['created_at'])) ?></small>
                                </div>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-link text-muted p-0 px-2" type="button" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false" title="Tenant actions">
                                    <i class="fa-solid fa-ellipsis-vertical" style="font-size:0.8rem;"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="font-size: 0.75rem;">
                                    <li><a class="dropdown-item" href="tenant_details.php?id=<?= $t['id'] ?>"><i class="fa-regular fa-eye me-2 text-muted"></i>View details</a></li>
                                    <li><button type="button" class="dropdown-item" onclick="openRoomModal(this)"><i class="fa-solid fa-right-left me-2 text-info"></i>Change room</button></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button type="button" class="dropdown-item text-danger" onclick="openDeactivateModal(this)"><i class="fa-solid fa-user-xmark me-2"></i>Remove tenant</button></li>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Action Buttons -->
                <div class="mt-auto pt-2">
                    <button class="btn btn-light border w-100 fw-bold text-dark mb-1 py-1" style="font-size: 0.8rem;" onclick="openEditModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['room_number'])) ?>', <?= $r['capacity'] ?>, <?= $r['price_per_month'] ?>, '<?= htmlspecialchars($r['status'] ?? '') ?>')">
                        <i class="fa-solid fa-pen me-1 text-muted"></i> Edit Room
                    </button>
                    
                    <?php if ($occCount === 0): ?>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this room?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="room_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn-link text-danger w-100 text-decoration-none py-1" style="font-size: 0.75rem;">
                            Delete Room
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                
            </div>
        <?php endforeach; ?>
    </div>
</div>



<!-- Change Room Modal (tenant) -->
<div class="modal fade" id="roomChangeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="change_room">
                <input type="hidden" name="tenant_id" id="rc_tenant_id">
                <input type="hidden" name="return_room" id="rc_return_room">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold">Change Room</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3" style="font-size:0.8rem;">
                        <span class="text-muted">Tenant:</span> <strong id="rc_name"></strong><br>
                        <span class="text-muted">Current room:</span> <strong id="rc_current"></strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold" style="font-size:0.8rem;">New Room</label>
                        <select class="form-select" name="room_id" id="rc_room" required>
                            <option value="">Select a room</option>
                            <?php foreach ($rooms as $opt):
                                $optOcc = isset($tenantsByRoom[$opt['id']]) ? count($tenantsByRoom[$opt['id']]) : 0;
                                $optFull = $optOcc >= $opt['capacity'];
                            ?>
                                <option value="<?= $opt['id'] ?>" data-full="<?= $optFull ? 1 : 0 ?>" <?= $optFull ? 'disabled' : '' ?>>
                                    Room <?= htmlspecialchars($opt['room_number'] ?? '') ?><?= $optFull ? ' - FULL' : ' (Avail: ' . ($opt['capacity'] - $optOcc) . ' | ₱' . number_format(rentShare((float)$opt['price_per_month'], (int)$opt['capacity']), 2) . ' per tenant)' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold" style="font-size:0.8rem;">Date of Transfer</label>
                        <input type="date" class="form-control" name="effective_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="alert alert-info border-0 py-2 mb-0" style="font-size:0.72rem;">
                        This month's rent stays as billed. The new room's rate applies from next month's bill.
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove Tenant Modal -->
<div class="modal fade" id="tenantRemoveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="tenant_id" id="tr_tenant_id">
                <input type="hidden" name="return_room" id="tr_return_room">
                <div class="modal-header bg-light border-0">
                    <h6 class="modal-title fw-bold text-danger"><i class="fa-solid fa-user-xmark me-2"></i>Remove Tenant</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p style="font-size:0.85rem;" class="mb-2">Remove <strong id="tr_name"></strong> from <strong id="tr_room"></strong>?</p>
                    <ul class="text-muted ps-3 mb-3" style="font-size:0.75rem;">
                        <li>Their bed becomes available.</li>
                        <li>Their account is deactivated and they can no longer log in.</li>
                        <li>No more monthly rent will be charged.</li>
                        <li>Payments, receipts and complaints are kept on record.</li>
                    </ul>
                    <div class="alert alert-warning border-0 py-2 mb-3 d-none" style="font-size:0.75rem;" id="tr_balance"></div>
                    <div>
                        <label class="form-label text-muted fw-semibold" style="font-size:0.8rem;">Move-out Date</label>
                        <input type="date" class="form-control" name="deactivated_at" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger px-4">Remove &amp; Deactivate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modals -->
<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header bg-light border-0">
                    <h5 class="modal-title fw-bold">Add New Room</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Room Name / Number</label>
                        <input type="text" class="form-control" name="room_number" required placeholder="e.g. 101 or Room A">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-semibold">Capacity (Persons)</label>
                            <input type="number" class="form-control" name="capacity" min="1" required placeholder="e.g. 4">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-semibold">Price per Room / Month (PHP)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="price_per_month" required placeholder="8000.00">
                            <div class="form-text" style="font-size: 0.7rem;">The rent for the whole room when every bed is taken. Each tenant pays this ÷ capacity, even while beds are empty.</div>
                            <div class="fw-semibold text-primary mt-1 rent-preview" style="font-size:0.75rem;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Room</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editRoomModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="room_id" id="edit_room_id">
                <div class="modal-header bg-light border-0">
                    <h5 class="modal-title fw-bold">Edit Room Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Room Name / Number</label>
                        <input type="text" class="form-control" name="room_number" id="edit_room_number" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-semibold">Capacity</label>
                            <input type="number" class="form-control" name="capacity" id="edit_capacity" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-semibold">Price per Room / Month (PHP)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="price_per_month" id="edit_price" required>
                            <div class="form-text" style="font-size: 0.7rem;">The rent for the whole room when every bed is taken. Each tenant pays this ÷ capacity. A change applies from next month's rent.</div>
                            <div class="fw-semibold text-primary mt-1 rent-preview" style="font-size:0.75rem;"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-semibold">Status</label>
                        <select class="form-select" name="status" id="edit_status" required>
                            <option value="vacant">Available (Vacant)</option>
                            <option value="occupied">Occupied</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Update Room</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function closeDetailsPanel() {
    const container = document.getElementById('rightDetailsContainer');
    if (container) {
        container.classList.remove('d-block', 'd-lg-block');
        container.classList.add('d-none');
    }
    // Deselect all rows
    document.querySelectorAll('.room-row').forEach(row => row.classList.remove('active'));
}

function selectRoom(id) {
    // Already showing this room? Nothing to fetch.
    if (document.getElementById('panel-' + id)) {
        const container = document.getElementById('rightDetailsContainer');
        if (container) { container.classList.remove('d-none'); container.classList.add('d-block'); }
        return;
    }
    // Otherwise ask the server for that room's panel. Only one panel is ever built, which
    // keeps this page small no matter how many rooms and tenants there are.
    window.location = 'rooms.php?room=' + encodeURIComponent(id);
}

// "Each tenant pays ₱2,000.00 (₱4,000.00 ÷ 2 beds)" under the price, as it is typed.
function updateRentPreview(form) {
    const price = parseFloat(form.querySelector('[name="price_per_month"]').value);
    const capacity = parseInt(form.querySelector('[name="capacity"]').value, 10);
    const out = form.querySelector('.rent-preview');
    if (!out) return;
    if (!(price >= 0) || !(capacity >= 1)) { out.textContent = ''; return; }
    const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    out.textContent = 'Each tenant pays ' + peso(Math.round(price / capacity * 100) / 100)
        + (capacity > 1 ? ' (' + peso(price) + ' ÷ ' + capacity + ' beds)' : '');
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('#addRoomModal form, #editRoomModal form').forEach(form => {
        form.addEventListener('input', () => updateRentPreview(form));
    });
});

function openEditModal(id, number, capacity, price, status) {
    document.getElementById('edit_room_id').value = id;
    document.getElementById('edit_room_number').value = number;
    document.getElementById('edit_capacity').value = capacity;
    document.getElementById('edit_price').value = price;
    document.getElementById('edit_status').value = status;
    updateRentPreview(document.getElementById('edit_price').form);
    
    var editModal = new bootstrap.Modal(document.getElementById('editRoomModal'));
    editModal.show();
}

// Search and the status filter together: a row shows only if it passes both.
function applyRoomFilters() {
    const query = document.getElementById('roomSearch').value.trim().toLowerCase();
    const status = document.getElementById('statusFilter').value;
    let shown = 0;
    document.querySelectorAll('.room-row').forEach(row => {
        const statusOk = status === 'all' || row.dataset.status === status
            || (status === 'space' && (row.dataset.status === 'available' || row.dataset.status === 'partial'));
        const ok = row.dataset.search.includes(query) && statusOk;
        row.style.display = ok ? '' : 'none';
        if (ok) shown++;
    });
    document.getElementById('noRoomMatch').classList.toggle('d-none', shown > 0 || !document.querySelector('.room-row'));
}

// Re-order the rows in the page. "number" is the order the server sent (natural room order).
let currentSort = 'number';
function sortRooms(how) {
    currentSort = how;
    const body = document.getElementById('roomList');
    const rows = Array.from(body.querySelectorAll('.room-row'));
    const num = (row, key) => parseFloat(row.dataset[key]) || 0;
    const byOrder = (a, b) => num(a, 'order') - num(b, 'order');
    const compare = {
        'number':     byOrder,
        'space':      (a, b) => num(b, 'free') - num(a, 'free') || byOrder(a, b),
        'price-asc':  (a, b) => num(a, 'price') - num(b, 'price') || byOrder(a, b),
        'price-desc': (a, b) => num(b, 'price') - num(a, 'price') || byOrder(a, b),
        'occupancy':  (a, b) => num(b, 'occ') - num(a, 'occ') || byOrder(a, b),
    }[how] || byOrder;
    rows.sort(compare).forEach(row => body.insertBefore(row, document.getElementById('noRoomMatch')));
    document.getElementById('roomSort').value = how;
    document.querySelectorAll('.rooms-table th.sortable').forEach(th => {
        th.classList.toggle('sorted', th.dataset.sort === how || (how === 'price-asc' && th.dataset.sort === 'price-desc'));
    });
    try { localStorage.setItem('roomSort', how); } catch (e) {}
}
document.addEventListener('DOMContentLoaded', () => {
    // The summary cards and the dashboard link here with ?show=space etc.
    const show = <?= json_encode($showFilter) ?>;
    if (show !== 'all') {
        document.getElementById('statusFilter').value = show;
        applyRoomFilters();
    }
    let saved = 'number';
    try { saved = localStorage.getItem('roomSort') || 'number'; } catch (e) {}
    if (saved !== 'number') sortRooms(saved);
});

function tenantData(el) {
    return JSON.parse(el.closest('[data-tenant]').dataset.tenant);
}

function openRoomModal(el) {
    const t = tenantData(el);
    document.getElementById('rc_tenant_id').value = t.id;
    document.getElementById('rc_return_room').value = t.return_room;
    document.getElementById('rc_name').textContent = t.name;
    document.getElementById('rc_current').textContent = 'Room ' + t.room_number;
    const select = document.getElementById('rc_room');
    select.value = '';
    for (const opt of select.options) {
        if (!opt.value) continue;
        opt.disabled = opt.dataset.full === '1' || Number(opt.value) === t.room_id;
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('roomChangeModal')).show();
}

function openDeactivateModal(el) {
    const t = tenantData(el);
    document.getElementById('tr_tenant_id').value = t.id;
    document.getElementById('tr_return_room').value = t.return_room;
    document.getElementById('tr_name').textContent = t.name;
    document.getElementById('tr_room').textContent = 'Room ' + t.room_number;
    const bal = document.getElementById('tr_balance');
    if (t.balance > 0) {
        bal.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>This tenant still owes <strong>₱'
            + Number(t.balance).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
            + '</strong>. The balance stays on their record after removal.';
        bal.classList.remove('d-none');
    } else {
        bal.classList.add('d-none');
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('tenantRemoveModal')).show();
}

</script>

<?php require_once 'footer.php'; ?>
