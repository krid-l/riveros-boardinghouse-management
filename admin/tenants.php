<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
requireAdmin();

$error = '';
$success = '';

function postedDate(string $field): string {
    $value = trim($_POST[$field] ?? '');
    if ($value === '') return date('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        throw new Exception("Invalid date.");
    }
    return $value;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $billTenantId = null;
    try {
        $pdo->beginTransaction();

        if ($action === 'add') {
            $firstName = trim($_POST['first_name']);
            $lastName = trim($_POST['last_name']);
            $roomId = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
            $moveInDate = $roomId ? postedDate('move_in_date') : null;

            if ($roomId && !roomHasSpace($pdo, $roomId)) {
                throw new Exception("Cannot assign tenant: Room is already full.");
            }

            // Auto-generate username (e.g., juan.delacruz)
            $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
            $username = $baseUsername;

            // Ensure username uniqueness
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $checkStmt->execute([$username]);
            $counter = 1;
            while ($checkStmt->fetchColumn() > 0) {
                $username = $baseUsername . $counter;
                $checkStmt->execute([$username]);
                $counter++;
            }

            // Auto-generate a random 8-character password
            $rawPassword = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%'), 0, 8);
            $password = password_hash($rawPassword, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, temp_password, role) VALUES (?, ?, ?, 'tenant') RETURNING id");
            $stmt->execute([$username, $password, $rawPassword]);
            $userId = $stmt->fetchColumn();

            // Create tenant profile
            $stmt = $pdo->prepare("INSERT INTO tenants (user_id, first_name, last_name, contact_number, room_id, occupation, emergency_contact, status, move_in_date, balance)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, 0) RETURNING id");
            $stmt->execute([
                $userId,
                $firstName,
                $lastName,
                $_POST['contact_number'],
                $roomId,
                !empty($_POST['occupation']) ? $_POST['occupation'] : null,
                !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null,
                $moveInDate
            ]);
            $tenantId = (int)$stmt->fetchColumn();
            if ($roomId) {
                logRoomTransfer($pdo, $tenantId, null, $roomId, $moveInDate, 'Moved in');
                $billTenantId = $tenantId;
            }

            $success = "Tenant added successfully! <br><strong>Username:</strong> " . htmlspecialchars($username) . " <br><strong>Password:</strong> " . htmlspecialchars($rawPassword) . " <br><small>Please save these credentials!</small>";
            if ($moveInDate) {
                $firstRent = isHalfMonthMoveIn($moveInDate) ? 'half' : 'full';
                $success .= "<br><small>First bill: $firstRent month's rent, due " . date('M j, Y', strtotime(firstMonthDueDate($moveInDate))) . ".</small>";
            }

        } elseif ($action === 'edit') {
            $stmt = $pdo->prepare("UPDATE tenants SET first_name = ?, last_name = ?, contact_number = ?, occupation = ?, emergency_contact = ? WHERE id = ?");
            $stmt->execute([
                trim($_POST['first_name']),
                trim($_POST['last_name']),
                $_POST['contact_number'],
                !empty($_POST['occupation']) ? $_POST['occupation'] : null,
                !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null,
                (int)$_POST['tenant_id']
            ]);
            $success = "Tenant updated successfully.";

        } elseif ($action === 'change_room') {
            $tenantId = (int)$_POST['tenant_id'];
            $newRoomId = (int)($_POST['room_id'] ?? 0);
            $date = postedDate('effective_date');

            $stmt = $pdo->prepare("SELECT room_id, status, move_in_date FROM tenants WHERE id = ? FOR UPDATE");
            $stmt->execute([$tenantId]);
            $t = $stmt->fetch();
            if (!$t) throw new Exception("Tenant not found.");
            if ($t['status'] !== 'active') throw new Exception("Re-activate this tenant before assigning a room.");
            if (!$newRoomId) throw new Exception("Please choose a room.");
            if ((int)$t['room_id'] === $newRoomId) throw new Exception("The tenant is already in that room.");
            if (!roomHasSpace($pdo, $newRoomId, $tenantId)) throw new Exception("Selected room is already full.");

            if (empty($t['move_in_date'])) {
                // First room assignment: this is their move-in, so first-month billing starts here.
                $pdo->prepare("UPDATE tenants SET room_id = ?, move_in_date = ? WHERE id = ?")->execute([$newRoomId, $date, $tenantId]);
                logRoomTransfer($pdo, $tenantId, null, $newRoomId, $date, 'Moved in');
                $billTenantId = $tenantId;
                $success = "Room assigned. First bill is due " . date('M j, Y', strtotime(firstMonthDueDate($date))) . ".";
            } else {
                $pdo->prepare("UPDATE tenants SET room_id = ? WHERE id = ?")->execute([$newRoomId, $tenantId]);
                logRoomTransfer($pdo, $tenantId, $t['room_id'] ? (int)$t['room_id'] : null, $newRoomId, $date, 'Room change');
                $success = "Room changed. The new room's rent applies from next month's bill.";
            }

        } elseif ($action === 'deactivate') {
            $tenantId = (int)$_POST['tenant_id'];
            $date = postedDate('deactivated_at');

            $stmt = $pdo->prepare("SELECT room_id, status, balance FROM tenants WHERE id = ? FOR UPDATE");
            $stmt->execute([$tenantId]);
            $t = $stmt->fetch();
            if (!$t) throw new Exception("Tenant not found.");
            if ($t['status'] === 'deactivated') throw new Exception("Tenant is already deactivated.");

            // Keep the tenant's payments, receipts and any unpaid balance on record; just free the bed and block login.
            $pdo->prepare("UPDATE tenants SET status = 'deactivated', deactivated_at = ?, room_id = NULL WHERE id = ?")->execute([$date, $tenantId]);
            logRoomTransfer($pdo, $tenantId, $t['room_id'] ? (int)$t['room_id'] : null, null, $date, 'Moved out (account deactivated)');

            $success = "Tenant removed and account deactivated.";
            if ((float)$t['balance'] > 0) {
                $success .= " They still have an unpaid balance of ₱" . number_format((float)$t['balance'], 2) . ".";
            }

        } elseif ($action === 'reactivate') {
            $tenantId = (int)$_POST['tenant_id'];
            $roomId = (int)($_POST['room_id'] ?? 0);
            $date = postedDate('move_in_date');

            $stmt = $pdo->prepare("SELECT status FROM tenants WHERE id = ? FOR UPDATE");
            $stmt->execute([$tenantId]);
            $status = $stmt->fetchColumn();
            if ($status === false) throw new Exception("Tenant not found.");
            if ($status !== 'deactivated') throw new Exception("Tenant is already active.");
            if (!$roomId) throw new Exception("Please choose a room.");
            if (!roomHasSpace($pdo, $roomId, $tenantId)) throw new Exception("Selected room is already full.");

            // A return is a new move-in: the first-month (full/half) rule applies again.
            $pdo->prepare("UPDATE tenants SET status = 'active', deactivated_at = NULL, room_id = ?, move_in_date = ? WHERE id = ?")
                ->execute([$roomId, $date, $tenantId]);
            logRoomTransfer($pdo, $tenantId, null, $roomId, $date, 'Moved in again (account re-activated)');
            $billTenantId = $tenantId;
            $success = "Tenant re-activated.";

        } elseif ($action === 'delete') {
            // Permanent delete is only for tenants added by mistake: anyone with payment history must be deactivated instead.
            $tenantId = (int)$_POST['tenant_id'];
            $stmt = $pdo->prepare("SELECT user_id, (SELECT COUNT(*) FROM payments WHERE tenant_id = tenants.id) AS pay_count FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $t = $stmt->fetch();
            if (!$t) throw new Exception("Tenant not found.");
            if ($t['pay_count'] > 0) throw new Exception("This tenant has payment records. Deactivate the account instead of deleting it.");
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$t['user_id']]);
            $success = "Tenant deleted.";

        } else {
            throw new Exception("Unknown action.");
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
        $success = '';
    }

    // Post the first-month charge right away instead of waiting for the next page load.
    if (!$error && $billTenantId) {
        runBilling($pdo, $billTenantId);
    }

    if (empty($error) && $action !== 'add') {
        header("Location: tenants.php?msg=" . urlencode(strip_tags($success)));
        exit;
    }
}
if (empty($success) && !empty($_GET['msg'])) {
    $success = htmlspecialchars($_GET['msg']);
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

// Fetch tenants
$tenantsStmt = $pdo->query("
    SELECT t.*, u.username, r.room_number
    FROM tenants t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY (t.status = 'active') DESC, u.created_at DESC
");
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
                                    $label = 'Room ' . htmlspecialchars($r['room_number']);
                                    if ($isFull) {
                                        $label .= ' - FULL';
                                    } else {
                                        $avail = $r['capacity'] - $r['occupied'];
                                        $label .= " (Avail: $avail | ₱" . number_format($r['price_per_month']) . ")";
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
                
                <div class="d-flex gap-2">
                    <div class="input-group input-group-sm rounded-2 border bg-white" style="width:200px;">
                        <span class="input-group-text bg-transparent border-0 pe-1"><i class="fa-solid fa-magnifying-glass text-muted" style="font-size:0.65rem;"></i></span>
                        <input type="text" id="searchInput" class="form-control border-0 shadow-none px-1" placeholder="Search tenants..." style="font-size:0.7rem;">
                    </div>
                    <select id="statusFilter" class="form-select form-select-sm border rounded-2 shadow-none text-muted" style="width:120px; font-size:0.7rem;">
                        <option value="all">All Status</option>
                        <option value="paid">Paid</option>
                        <option value="due">Unpaid</option>
                        <option value="overdue">Overdue</option>
                        <option value="deactivated">Deactivated</option>
                    </select>
                </div>
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
                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($t['first_name'].' '.$t['last_name']) ?>&background=random&color=fff" class="rounded-circle me-2" width="28" height="28" alt="Avatar">
                                    <span class="fw-bold text-dark text-truncate" style="max-width:130px;"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></span>
                                </div>
                            </td>
                            <td class="text-muted"><?= htmlspecialchars($t['contact_number'] ?? '') ?: '<span class="text-black-50 fst-italic">None</span>' ?></td>
                            <td class="text-primary" style="font-size: 0.7rem;"><?= htmlspecialchars($t['username']) ?></td>
                            <td class="text-dark fw-semibold">
                                <?php if ($isDeactivated): ?>
                                    <span class="text-muted fw-normal">Moved out<?= $t['deactivated_at'] ? ' ' . date('M j, Y', strtotime($t['deactivated_at'])) : '' ?></span>
                                <?php else: ?>
                                    <?= $t['room_number'] ? 'Room ' . htmlspecialchars($t['room_number']) : '<span class="text-muted fw-normal">Unassigned</span>' ?>
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

            <div class="card-footer bg-white border-top p-3 d-flex justify-content-between align-items-center">
                <span class="text-muted" style="font-size:0.75rem;" id="tenantCount"><?= count($tenants) ?> tenants</span>
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
        $label = 'Room ' . htmlspecialchars($r['room_number']) . ($isFull ? ' - FULL' : " (Avail: $avail | ₱" . number_format($r['price_per_month']) . ")");
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

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const tableRows = document.querySelectorAll('#tenantsTable tbody tr.tenant-row');
    const countLabel = document.getElementById('tenantCount');

    function filterTable() {
        const query = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        let shown = 0;

        tableRows.forEach(row => {
            let show = row.textContent.toLowerCase().includes(query);
            if (status !== 'all' && row.dataset.status !== status) show = false;
            row.style.display = show ? '' : 'none';
            if (show) shown++;
        });
        countLabel.textContent = shown + ' of ' + tableRows.length + ' tenants';
    }

    if (searchInput) searchInput.addEventListener('input', filterTable);
    if (statusFilter) statusFilter.addEventListener('change', filterTable);
});
</script>
<?php require_once 'footer.php'; ?>
