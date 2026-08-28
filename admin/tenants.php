<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$error = '';
$success = '';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        try {
            $pdo->beginTransaction();
            
            // Create user account
            $firstName = trim($_POST['first_name']);
            $lastName = trim($_POST['last_name']);
            
            $roomId = !empty($_POST['room_id']) ? $_POST['room_id'] : null;
            if ($roomId) {
                $stmtCap = $pdo->prepare("SELECT capacity, (SELECT COUNT(*) FROM tenants WHERE room_id = ?) as occupied FROM rooms WHERE id = ?");
                $stmtCap->execute([$roomId, $roomId]);
                $capInfo = $stmtCap->fetch();
                if ($capInfo && $capInfo['occupied'] >= $capInfo['capacity']) {
                    throw new Exception("Cannot assign tenant: Room is already full.");
                }
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
            
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, temp_password, role) VALUES (?, ?, ?, 'tenant')");
            $stmt->execute([$username, $password, $rawPassword]);
            $userId = $pdo->lastInsertId();
            
            // Create tenant profile
            $stmt = $pdo->prepare("INSERT INTO tenants (user_id, first_name, last_name, contact_number, room_id, occupation, emergency_contact) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId, 
                $_POST['first_name'], 
                $_POST['last_name'], 
                $_POST['contact_number'], 
                $roomId, 
                !empty($_POST['occupation']) ? $_POST['occupation'] : null, 
                !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null
            ]);
            
            $pdo->commit();
            $success = "Tenant added successfully! <br><strong>Username:</strong> " . htmlspecialchars($username) . " <br><strong>Password:</strong> " . htmlspecialchars($rawPassword) . " <br><small>Please save these credentials!</small>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error adding tenant: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'edit') {
        try {
            $roomId = !empty($_POST['room_id']) ? $_POST['room_id'] : null;
            if ($roomId) {
                // Get current room
                $currStmt = $pdo->prepare("SELECT room_id FROM tenants WHERE id = ?");
                $currStmt->execute([$_POST['tenant_id']]);
                $currRoom = $currStmt->fetchColumn();
                
                if ($currRoom != $roomId) {
                    $stmtCap = $pdo->prepare("SELECT capacity, (SELECT COUNT(*) FROM tenants WHERE room_id = ?) as occupied FROM rooms WHERE id = ?");
                    $stmtCap->execute([$roomId, $roomId]);
                    $capInfo = $stmtCap->fetch();
                    if ($capInfo && $capInfo['occupied'] >= $capInfo['capacity']) {
                        throw new Exception("Cannot assign tenant: Selected room is already full.");
                    }
                }
            }
            $stmt = $pdo->prepare("UPDATE tenants SET first_name = ?, last_name = ?, contact_number = ?, room_id = ?, occupation = ?, emergency_contact = ? WHERE id = ?");
            $stmt->execute([
                $_POST['first_name'], 
                $_POST['last_name'], 
                $_POST['contact_number'], 
                $roomId, 
                !empty($_POST['occupation']) ? $_POST['occupation'] : null, 
                !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null, 
                $_POST['tenant_id']
            ]);
            $success = "Tenant updated successfully.";
        } catch (Exception $e) {
            $error = "Error updating tenant: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'delete') {
        try {
            $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $stmt->execute([$_POST['tenant_id']]);
            $uid = $stmt->fetchColumn();
            if ($uid) {
                $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
            }
            $success = "Tenant deleted successfully.";
        } catch (Exception $e) {
            $error = "Error deleting tenant: " . $e->getMessage();
        }
    }
    
    if (empty($error)) {
        header("Location: tenants.php");
        exit;
    }
}

// Fetch stats
$totalTenants = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
$occupiedRooms = $pdo->query("SELECT COUNT(DISTINCT room_id) FROM tenants WHERE room_id IS NOT NULL")->fetchColumn();
$totalOutstanding = $pdo->query("SELECT SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) FROM tenants")->fetchColumn() ?: 0;

// Total paid this month
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$stmt = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE status = 'verified' AND payment_date BETWEEN ? AND ?");
$stmt->execute([$monthStart, $monthEnd]);
$totalPaidThisMonth = $stmt->fetchColumn() ?: 0;

// Fetch tenants
$tenantsStmt = $pdo->query("
    SELECT t.*, u.username, r.room_number 
    FROM tenants t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY u.created_at DESC
");
$tenants = $tenantsStmt->fetchAll();

// Fetch available rooms for the dropdown
$roomsStmt = $pdo->query("SELECT r.id, r.room_number, r.capacity, r.price_per_month, (SELECT COUNT(*) FROM tenants t WHERE t.room_id = r.id) as occupied FROM rooms r ORDER BY r.room_number ASC");
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
                    <select id="statusFilter" class="form-select form-select-sm border rounded-2 shadow-none text-muted" style="width:100px; font-size:0.7rem;">
                        <option value="all">All Status</option>
                        <option value="active">Active</option>
                        <option value="overdue">Overdue</option>
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
                            $balance = (float)$t['balance'];
                            if($balance > 0) {
                                $statusColor = 'danger';
                                $statusText = 'Overdue';
                            } else {
                                $statusColor = 'success';
                                $statusText = 'Active';
                            }
                        ?>
                        <tr class="tenant-row" data-search="<?= strtolower($t['first_name'].' '.$t['last_name'].' '.$t['username']) ?>">
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($t['first_name'].' '.$t['last_name']) ?>&background=random&color=fff" class="rounded-circle me-2" width="28" height="28" alt="Avatar">
                                    <span class="fw-bold text-dark text-truncate" style="max-width:130px;"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></span>
                                </div>
                            </td>
                            <td class="text-muted"><?= htmlspecialchars($t['contact_number']) ?: '<span class="text-black-50 fst-italic">None</span>' ?></td>
                            <td class="text-primary" style="font-size: 0.7rem;"><?= htmlspecialchars($t['username']) ?></td>
                            <td class="text-dark fw-semibold"><?= $t['room_number'] ? 'Room ' . htmlspecialchars($t['room_number']) : '<span class="text-muted fw-normal">Unassigned</span>' ?></td>
                            <td class="fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">₱<?= number_format($balance, 2) ?></td>
                            <td>
                                <span class="badge bg-<?= $statusColor ?>-subtle text-<?= $statusColor ?> rounded-pill px-2 py-1 fw-semibold border border-<?= $statusColor ?>-subtle" style="font-size: 0.6rem;">
                                    <span class="status-dot bg-<?= $statusColor ?>"></span> <?= $statusText ?>
                                </span>
                            </td>
                            <td class="text-end pe-4 text-nowrap">
                                <a href="tenant_details.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary rounded-1 px-1 py-0 me-1">
                                    <i class="fa-regular fa-eye" style="font-size: 0.65rem;"></i>
                                </a>
                                <button onclick="openEditModal(<?= $t['id'] ?>, '<?= addslashes($t['first_name']) ?>', '<?= addslashes($t['last_name']) ?>', '<?= addslashes($t['contact_number'] ?? '') ?>', '<?= $t['room_id'] ?>', '<?= addslashes($t['occupation'] ?? '') ?>', '<?= addslashes($t['emergency_contact'] ?? '') ?>')" class="btn btn-sm btn-outline-primary rounded-1 px-1 py-0">
                                    <i class="fa-regular fa-pen-to-square" style="font-size: 0.65rem;"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="card-footer bg-white border-top p-3 d-flex justify-content-between align-items-center">
                <span class="text-muted" style="font-size:0.75rem;">Showing 1 to <?= count($tenants) ?> of <?= count($tenants) ?> tenants</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0 shadow-sm">
                        <li class="page-item disabled"><a class="page-link text-muted border-light" href="#">Previous</a></li>
                        <li class="page-item active"><a class="page-link border-primary" href="#">1</a></li>
                        <li class="page-item"><a class="page-link text-muted border-light" href="#">2</a></li>
                        <li class="page-item"><a class="page-link text-muted border-light" href="#">3</a></li>
                        <li class="page-item"><a class="page-link text-muted border-light" href="#">Next</a></li>
                    </ul>
                </nav>
            </div>
            
        </div>
    </div>
</div>

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
                    
                    
                    <div class="mb-3">
                        <label>Assign Room</label>
                        <select class="form-select" name="room_id" id="edit_room_id">
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
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" style="font-size:0.8rem;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(id, fname, lname, contact, room, occupation, emergency_contact) {
    document.getElementById('edit_tenant_id').value = id;
    document.getElementById('edit_first_name').value = fname;
    document.getElementById('edit_last_name').value = lname;
    document.getElementById('edit_contact').value = contact;
    document.getElementById('edit_room_id').value = room || '';
    document.getElementById('edit_occupation').value = occupation || '';
    document.getElementById('edit_emergency_contact').value = emergency_contact || '';
    
    
    
    var editModal = new bootstrap.Modal(document.getElementById('editTenantModal'));
    editModal.show();
}

function filterTenants(query) {
    query = query.toLowerCase();
    document.querySelectorAll('.tenant-row').forEach(row => {
        const text = row.getAttribute('data-search');
        row.style.display = text.includes(query) ? '' : 'none';
    });
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const tableRows = document.querySelectorAll('#tenantsTable tbody tr');

    function filterTable() {
        const query = searchInput.value.toLowerCase();
        const status = statusFilter.value.toLowerCase();

        tableRows.forEach(row => {
            const textContent = row.textContent.toLowerCase();
            let show = textContent.includes(query);
            
            if (status !== 'all') {
                if (status === 'active' && !textContent.includes('active')) show = false;
                if (status === 'overdue' && !textContent.includes('overdue')) show = false;
            }
            
            row.style.display = show ? '' : 'none';
        });
    }

    if (searchInput) searchInput.addEventListener('input', filterTable);
    if (statusFilter) statusFilter.addEventListener('change', filterTable);
});
</script>
<?php require_once 'footer.php'; ?>
