<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
require_once '../includes/tenant_actions.php';
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
        // price_per_month is the price of the WHOLE room; the tenants living in it split it.
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
                $stmt = $pdo->prepare("INSERT INTO rooms (room_number, capacity, price_per_month, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$roomNumber, $capacity, $roomPrice, 'vacant']);
            } elseif ($action === 'edit') {
                $stmt = $pdo->prepare("UPDATE rooms SET room_number = ?, capacity = ?, price_per_month = ?, status = ? WHERE id = ?");
                $stmt->execute([$roomNumber, $capacity, $roomPrice, $_POST['status'], $_POST['room_id']]);
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
    header("Location: $back&" . ($error ? 'err=' . urlencode($error) : 'msg=' . urlencode(strip_tags($success))));
    exit;
}
if (!empty($_GET['msg'])) $success = htmlspecialchars($_GET['msg']);
if (!empty($_GET['err'])) $error = htmlspecialchars($_GET['err']);

// Fetch all rooms
$roomsStmt = $pdo->query("SELECT * FROM rooms ORDER BY room_number ASC");
$rooms = $roomsStmt->fetchAll();

// Fetch all assigned tenants
$tenantsByRoom = [];
$tenantsStmt = $pdo->query("SELECT t.id, t.first_name, t.last_name, t.room_id, t.balance, t.move_in_date,
    COALESCE(t.move_in_date, " . sqlToDate('u.created_at') . ") AS created_at
    FROM tenants t JOIN users u ON t.user_id = u.id
    WHERE t.room_id IS NOT NULL AND t.status = 'active'
    ORDER BY t.first_name");
foreach ($tenantsStmt->fetchAll() as $t) {
    $tenantsByRoom[$t['room_id']][] = $t;
}

// Calculate Stats
// rooms.price_per_month is the price of the whole room. The tenants in it split that amount
// equally, so a room brings in its full price as soon as anyone lives there, and each tenant
// is billed price_per_month / (number of tenants in the room).
$totalRooms = count($rooms);
$totalCapacity = 0;
$potentialValue = 0;   // if every room had at least one tenant
$currentValue = 0;     // from the rooms that are lived in now
$occupiedRooms = 0;

foreach ($rooms as $r) {
    $totalCapacity += $r['capacity'];
    $potentialValue += $r['price_per_month'];
    $occCount = isset($tenantsByRoom[$r['id']]) ? count($tenantsByRoom[$r['id']]) : 0;
    $currentValue += $occCount > 0 ? $r['price_per_month'] : 0;
    if ($occCount >= $r['capacity'] && $r['capacity'] > 0) {
        $occupiedRooms++;
        if ($r['status'] !== 'occupied') {
            $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?")->execute([$r['id']]);
            $r['status'] = 'occupied';
        }
    }
}

require_once 'header.php';
?>

<style>
    .room-card {
        border: 2px solid transparent !important;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    .room-card:hover {
        border-color: #cbd5e1 !important;
        box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
    }
    .room-card.active {
        border-color: #2563eb !important;
        background-color: #f8fafc;
    }
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
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <h3 class="fw-bold mb-1 text-dark">Manage Rooms</h3>
                <p class="text-muted mb-0">Organize and monitor all rooms in your boarding house.</p>
            </div>
            <div>
                <button class="btn btn-primary shadow-sm fw-semibold px-4" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                    <i class="fa-solid fa-plus me-2"></i> Add New Room
                </button>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center p-3">
                        <div class="bg-primary bg-opacity-10 text-primary rounded p-3 me-3 d-flex justify-content-center align-items-center" style="width:45px; height:45px;">
                            <i class="fa-solid fa-bed fa-lg"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0 text-dark fs-5"><?= $totalRooms ?></h4>
                            <small class="text-muted fw-semibold" style="font-size:0.75rem;">Total Rooms</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center p-3">
                        <div class="bg-success bg-opacity-10 text-success rounded p-3 me-3 d-flex justify-content-center align-items-center" style="width:45px; height:45px;">
                            <i class="fa-solid fa-users fa-lg"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0 text-dark fs-5"><?= $totalCapacity ?></h4>
                            <small class="text-muted fw-semibold" style="font-size:0.75rem;">Total Capacity</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center p-3">
                        <div class="bg-warning bg-opacity-10 text-warning rounded p-3 me-3 d-flex justify-content-center align-items-center" style="width:45px; height:45px;">
                            <i class="fa-solid fa-peso-sign fa-lg"></i>
                        </div>
                        <div class="flex-grow-1" style="min-width:0;">
                            <h4 class="fw-bold mb-0 text-dark fs-6 text-nowrap">₱<?= number_format($currentValue, 2) ?></h4>
                            <small class="text-muted fw-semibold d-block" style="font-size:0.72rem;">Monthly Income Now</small>
                            <div class="text-muted text-nowrap" style="font-size:0.62rem;">₱<?= number_format($potentialValue) ?> if full</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center p-3">
                        <div class="bg-secondary bg-opacity-10 text-secondary rounded p-3 me-3 d-flex justify-content-center align-items-center" style="width:45px; height:45px;">
                            <i class="fa-solid fa-door-closed fa-lg"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0 text-dark fs-5"><?= $occupiedRooms ?></h4>
                            <small class="text-muted fw-semibold" style="font-size:0.75rem;">Occupied Rooms</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="d-flex flex-column flex-md-row gap-3 mb-4">
            <div class="input-group bg-white rounded-3 shadow-sm" style="max-width: 350px; border: none;">
                <span class="input-group-text bg-transparent border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" class="form-control border-0 bg-transparent ps-0" placeholder="Search rooms..." onkeyup="filterRooms(this.value)">
            </div>
            <select class="form-select bg-white border-0 shadow-sm w-auto fw-semibold text-dark" onchange="filterStatus(this.value)">
                <option value="all">All Status</option>
                <option value="vacant">Available</option>
                <option value="occupied">Occupied</option>
                <option value="maintenance">Maintenance</option>
            </select>
        </div>

        <!-- Rooms Grid -->
        <div class="row g-3" id="roomGrid">
            <?php if (count($rooms) === 0): ?>
                <div class="col-12 text-center py-5">
                    <p class="text-muted">No rooms found. Add a new room to get started.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($rooms as $index => $r): ?>
                <?php 
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
                <div class="col-md-6 col-xxl-4 room-grid-item" data-status="<?= htmlspecialchars($r['status']) ?>" data-search="<?= strtolower($r['room_number']) ?>">
                    <div class="card h-100 shadow-sm room-card <?= ($selectedRoom ? $selectedRoom === (int)$r['id'] : $index === 0) ? 'active' : '' ?>" id="card-<?= $r['id'] ?>" onclick="selectRoom(<?= $r['id'] ?>)">
                        <div class="card-body p-3 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary bg-opacity-10 text-primary rounded d-flex justify-content-center align-items-center me-2" style="width: 32px; height: 32px;">
                                        <i class="fa-solid fa-door-open"></i>
                                    </div>
                                    <h6 class="fw-bold mb-0 text-dark">Room <?= htmlspecialchars($r['room_number']) ?></h6>
                                </div>
                                <span class="badge <?= $badgeClass ?>" style="font-size: 0.65rem;"><?= $badgeText ?></span>
                            </div>
                            
                            <p class="text-muted mb-1" style="font-size: 0.8rem;">Capacity: <?= htmlspecialchars($r['capacity']) ?></p>
                            <p class="fw-bold text-dark mb-1" style="font-size: 0.85rem;">₱<?= number_format($r['price_per_month'], 2) ?> / month <span class="text-muted fw-normal">per room</span></p>
                            <p class="text-muted mb-4" style="font-size: 0.75rem;"><?= $occCount > 0
                                ? '₱' . number_format($r['price_per_month'] / $occCount, 2) . ' each, split between ' . $occCount . ' tenant' . ($occCount === 1 ? '' : 's')
                                : 'No tenants yet' ?></p>
                            
                            <div class="mt-auto">
                                <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 0.75rem;">
                                    <span class="text-muted"><i class="fa-solid fa-user me-1"></i> <?= $occCount ?>/<?= $r['capacity'] ?></span>
                                    <span class="fw-semibold text-primary"><?= $occPct ?>%</span>
                                </div>
                                <div class="progress rounded-pill bg-light" style="height: 4px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $occPct ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
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
                            <h5 class="fw-bold mb-0 text-dark me-2">Room <?= htmlspecialchars($r['room_number']) ?></h5>
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
                        <span class="text-muted fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-user me-2"></i> Share / Tenant</span>
                        <span class="fw-bold text-dark" style="font-size: 0.8rem;"><?= $occCount > 0
                            ? '₱' . number_format($r['price_per_month'] / $occCount, 2) . ' <span class="text-muted fw-normal">/ month (÷ ' . $occCount . ')</span>'
                            : '<span class="text-muted fw-normal">No tenants yet</span>' ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-light">
                        <span class="text-muted fw-semibold" style="font-size: 0.75rem;"><i class="fa-solid fa-sack-dollar me-2"></i> Earning Now</span>
                        <span class="fw-bold <?= $occCount ? 'text-success' : 'text-muted' ?>" style="font-size: 0.8rem;">₱<?= number_format($occCount > 0 ? $r['price_per_month'] : 0, 2) ?> <span class="text-muted fw-normal">/ month</span></span>
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
                                <?= avatarHtml($t['first_name'] . ' ' . $t['last_name'], 30, 'me-2 shadow-sm') ?>
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
                    <button class="btn btn-light border w-100 fw-bold text-dark mb-1 py-1" style="font-size: 0.8rem;" onclick="openEditModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['room_number'])) ?>', <?= $r['capacity'] ?>, <?= $r['price_per_month'] ?>, '<?= htmlspecialchars($r['status']) ?>')">
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
                                    Room <?= htmlspecialchars($opt['room_number']) ?><?= $optFull ? ' - FULL' : ' (Avail: ' . ($opt['capacity'] - $optOcc) . ' | ₱' . number_format($opt['price_per_month']) . '/room, ₱' . number_format($opt['price_per_month'] / ($optOcc + 1), 2) . ' each)' ?>
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
                            <div class="form-text" style="font-size: 0.7rem;">The rent for the whole room. It is split equally between the tenants living in it.</div>
                            <small class="text-muted" style="font-size:0.7rem;">Each tenant in this room is billed this amount.</small>
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
                            <div class="form-text" style="font-size: 0.7rem;">The rent for the whole room. It is split equally between the tenants living in it. A change applies from next month's rent.</div>
                            <small class="text-muted" style="font-size:0.7rem;">Each tenant in this room is billed this amount.</small>
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
    // Deselect all cards
    document.querySelectorAll('.room-card').forEach(card => card.classList.remove('active'));
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

function openEditModal(id, number, capacity, price, status) {
    document.getElementById('edit_room_id').value = id;
    document.getElementById('edit_room_number').value = number;
    document.getElementById('edit_capacity').value = capacity;
    document.getElementById('edit_price').value = price;
    document.getElementById('edit_status').value = status;
    
    var editModal = new bootstrap.Modal(document.getElementById('editRoomModal'));
    editModal.show();
}

function filterRooms(query) {
    query = query.toLowerCase();
    document.querySelectorAll('.room-grid-item').forEach(item => {
        const text = item.getAttribute('data-search');
        item.style.display = text.includes(query) ? '' : 'none';
    });
}

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

function filterStatus(status) {
    document.querySelectorAll('.room-grid-item').forEach(item => {
        if (status === 'all' || item.getAttribute('data-status') === status) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}
</script>

<?php require_once 'footer.php'; ?>
