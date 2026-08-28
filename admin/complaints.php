<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_complaint') {
    $complaintId = (int)$_POST['complaint_id'];
    $status = $_POST['status']; // e.g. 'pending' or 'resolved'
    $response = trim($_POST['admin_response']);
    
    $stmt = $pdo->prepare("UPDATE complaints SET status = ?, admin_response = ? WHERE id = ?");
    $stmt->execute([$status, $response, $complaintId]);
    
    header("Location: complaints.php?success=1");
    exit;
}

$success_msg = isset($_GET['success']) ? "Complaint updated successfully." : "";

// --- DATA FETCHING ---
$stmt = $pdo->query("
    SELECT c.*, t.first_name, t.last_name, r.room_number
    FROM complaints c
    JOIN tenants t ON c.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY c.created_at DESC
");
$complaints = $stmt->fetchAll();

$totalComplaints = count($complaints);
$openCount = 0;
$inProgressCount = 0;
$resolvedCount = 0;
$closedCount = 0;

// Fetch Category Counts
$catStmt = $pdo->query("SELECT category, COUNT(*) as cat_count FROM complaints GROUP BY category ORDER BY cat_count DESC LIMIT 4");
$categoryStats = $catStmt->fetchAll();

// Enrich data for UI
$enrichedComplaints = [];
foreach ($complaints as $c) {
    // Derive UI status
    if ($c['status'] == 'resolved') {
        $uiStatus = 'Resolved';
        $resolvedCount++;
    } else {
        if (empty($c['admin_response'])) {
            $uiStatus = 'Pending';
            $pendingCount++;
        } else {
            $uiStatus = 'In Progress';
            $inProgressCount++;
        }
    }
    
    // Default priority since schema lacks priority
    $priority = 'Medium';
    
    // updated_at defaults to created_at if schema lacks updated_at tracking
    $updatedAt = $c['updated_at'] ?? $c['created_at'];

    $c['ui_status'] = $uiStatus;
    $c['priority'] = $priority;
    $c['last_update'] = $updatedAt;
    
    $enrichedComplaints[] = $c;
}

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
    
    .card-title-sm { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; margin-bottom: 2px; }
    .trend-text { font-size: 0.65rem; font-weight: 500; }
    
    .table-compact th { font-size: 0.55rem; text-transform: uppercase; color: #64748b; font-weight: 600; padding: 0.6rem 0.5rem; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; }
    .table-compact td { font-size: 0.65rem; vertical-align: middle; padding: 0.5rem 0.5rem; border-bottom: 1px solid #f8fafc; }
    .table-compact tbody tr:hover { background-color: #f8fafc; }
    
    .chart-container-donut { position: relative; height: 120px; width: 120px; }
    .donut-center-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
    
    .dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; margin-right: 6px; }
    
    .activity-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.75rem; background: white; }
    .action-btn-list a { display: block; padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.65rem; font-weight: 600; color: #0d6efd; text-decoration: none; margin-bottom: 0.5rem; }
    .action-btn-list a:hover { background: #f8fafc; }
    
    .cat-row { display: flex; justify-content: space-between; align-items: center; padding: 0.4rem 0; border-bottom: 1px dashed #f1f5f9; font-size: 0.65rem; }
    .cat-row:last-child { border-bottom: none; }
</style>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 pt-2 gap-2">
    <div>
        <h5 class="fw-bold mb-1 text-dark">Manage Complaints</h5>
        <p class="text-muted mb-0" style="font-size: 0.7rem;">View, track and resolve tenant complaints.</p>
    </div>
    <div>
        <!-- Tenants handle new complaints -->
    </div>
</div>

<?php if(!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 py-2 px-3 mb-3" role="alert" style="font-size: 0.75rem;">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close" style="font-size: 0.5rem;"></button>
    </div>
<?php endif; ?>

<!-- Top Stats Row -->
<div class="row g-2 mb-3">
    <!-- Total Complaints -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-primary bg-opacity-10 text-primary me-2 flex-shrink-0">
                    <i class="fa-solid fa-comment-dots"></i>
                </div>
                <div>
                    <div class="card-title-sm">Total Complaints</div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $totalComplaints ?></h5>
                    <div class="trend-text text-muted mt-1">All time</div>
                </div>
            </div>
        </div>
    </div>
    <!-- Pending -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-warning bg-opacity-10 text-warning me-2 flex-shrink-0">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <div class="card-title-sm">Open</div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $openCount ?></h5>
                    <div class="trend-text text-muted mt-1">Needs attention</div>
                </div>
            </div>
        </div>
    </div>
    <!-- In Progress -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-danger bg-opacity-10 text-danger me-2 flex-shrink-0">
                    <i class="fa-solid fa-circle-exclamation"></i>
                </div>
                <div>
                    <div class="card-title-sm">In Progress</div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $inProgressCount ?></h5>
                    <div class="trend-text text-muted mt-1">Being handled</div>
                </div>
            </div>
        </div>
    </div>
    <!-- Resolved -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-success bg-opacity-10 text-success me-2 flex-shrink-0">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="card-title-sm">Resolved</div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $resolvedCount ?></h5>
                    <div class="trend-text text-muted mt-1">This month</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Grid -->
<div class="row g-2">
    <!-- Left Column: Table & Activity -->
    <div class="col-lg-8 col-xl-9">
        
        <!-- Filter Bar -->
        <div class="d-flex flex-wrap gap-2 mb-2 align-items-center bg-white p-2 rounded-3 shadow-sm border-0">
            <div class="input-group input-group-sm rounded-2 border flex-grow-1 bg-white" style="max-width:250px;">
                <span class="input-group-text bg-transparent border-0 pe-1"><i class="fa-solid fa-magnifying-glass text-muted" style="font-size:0.65rem;"></i></span>
                <input type="text" id="searchInput" class="form-control border-0 shadow-none px-1" placeholder="Search by tenant, subject or message..." style="font-size:0.7rem;">
            </div>
            <select id="statusFilter" class="form-select form-select-sm border rounded-2 shadow-none text-muted" style="width:110px; font-size:0.7rem;">
                <option value="all">All Status</option>
                <option value="pending">Pending</option>
                <option value="in progress">In Progress</option>
                <option value="resolved">Resolved</option>
            </select>
            <div class="input-group input-group-sm rounded-2 border bg-white" style="width:140px;">
                <input type="text" class="form-control border-0 shadow-none px-2" placeholder="Select Date Range" style="font-size:0.7rem;">
                <span class="input-group-text bg-transparent border-0 pe-2"><i class="fa-regular fa-calendar text-muted" style="font-size:0.65rem;"></i></span>
            </div>
            <button class="btn btn-link btn-sm text-primary text-decoration-none fw-semibold ms-auto" style="font-size:0.65rem;" onclick="document.getElementById('searchInput').value=''; document.getElementById('statusFilter').value='all'; filterTable();"><i class="fa-solid fa-rotate-right me-1"></i> Clear Filters</button>
        </div>
        
        <!-- Table Card -->
        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white border-0 p-3 pb-0 d-flex align-items-center">
                <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;"><i class="fa-solid fa-file-lines text-primary me-2"></i> Complaints List</h6>
            </div>
            <div class="card-body p-0 mt-2 overflow-auto">
                <table id="complaintsTable" class="table table-compact mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 border-0">Date</th>
                            <th class="border-0">Tenant</th>
                            <th class="border-0">Subject</th>
                            <th class="border-0 text-center">Priority</th>
                            <th class="border-0 text-center">Status</th>
                            <th class="border-0">Last Update</th>
                            <th class="border-0 text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($enrichedComplaints)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">No complaints found.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($enrichedComplaints as $c): 
                            $dateStr = date('M d, Y', strtotime($c['created_at']));
                            $timeStr = date('h:i A', strtotime($c['created_at']));
                            
                            $updateDateStr = date('M d, Y', strtotime($c['last_update']));
                            $updateTimeStr = date('h:i A', strtotime($c['last_update']));
                            
                            $pBadgeClass = ['High' => 'danger', 'Medium' => 'warning', 'Low' => 'success'][$c['priority']];
                            
                            if ($c['ui_status'] == 'Resolved') $sBadgeClass = 'success';
                            elseif ($c['ui_status'] == 'Pending') $sBadgeClass = 'warning';
                            else $sBadgeClass = 'primary';
                        ?>
                        <tr>
                            <td class="ps-3">
                                <div class="text-dark fw-semibold" style="font-size:0.65rem;"><?= $dateStr ?></div>
                                <div class="text-muted" style="font-size:0.55rem;"><?= $timeStr ?></div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($c['first_name'].' '.$c['last_name']) ?>&background=random&color=fff" class="rounded-circle me-2 shadow-sm" width="22" height="22">
                                    <div>
                                        <div class="fw-bold text-dark" style="font-size:0.65rem; line-height:1.1;"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></div>
                                        <div class="text-muted" style="font-size:0.55rem;">Room <?= htmlspecialchars($c['room_number'] ?? 'N/A') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="max-width: 150px;">
                                <div class="fw-bold text-dark text-truncate" style="font-size:0.65rem; line-height:1.1;" title="<?= htmlspecialchars($c['subject']) ?>"><?= htmlspecialchars($c['subject']) ?></div>
                                <div class="text-muted text-truncate" style="font-size:0.55rem;" title="<?= htmlspecialchars($c['message']) ?>"><?= htmlspecialchars($c['message']) ?></div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $pBadgeClass ?>-subtle text-<?= $pBadgeClass ?> border border-<?= $pBadgeClass ?>-subtle px-2 py-0 rounded-pill" style="font-size:0.55rem;"><?= $c['priority'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $sBadgeClass ?>-subtle text-<?= $sBadgeClass ?> border border-<?= $sBadgeClass ?>-subtle px-2 py-0 rounded-pill" style="font-size:0.55rem;"><?= $c['ui_status'] ?></span>
                            </td>
                            <td>
                                <div class="text-dark fw-semibold" style="font-size:0.65rem;"><?= $updateDateStr ?></div>
                                <div class="text-muted" style="font-size:0.55rem;"><?= $updateTimeStr ?></div>
                            </td>
                            <td class="text-end pe-3">
                                <button class="btn btn-sm btn-outline-secondary rounded-2 px-1 py-0 me-1"
                                        data-bs-toggle="modal" data-bs-target="#viewComplaintModal"
                                        data-id="<?= $c['id'] ?>"
                                        data-tenant="<?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?>"
                                        data-room="<?= htmlspecialchars($c['room_number'] ?? 'N/A') ?>"
                                        data-subject="<?= htmlspecialchars($c['subject']) ?>"
                                        data-message="<?= htmlspecialchars($c['message']) ?>"
                                        data-admin-response="<?= htmlspecialchars($c['admin_response'] ?? '') ?>"
                                        data-status="<?= htmlspecialchars($c['status']) ?>"
                                        data-category="<?= htmlspecialchars($c['category'] ?? 'Others') ?>">
                                    <i class="fa-regular fa-eye" style="font-size:0.6rem;"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top p-2 d-flex justify-content-between align-items-center">
                <span class="text-muted" style="font-size:0.65rem;">Showing 1 to <?= min(5, count($enrichedComplaints)) ?> of <?= count($enrichedComplaints) ?> complaints</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0 shadow-sm" style="font-size:0.65rem;">
                        <li class="page-item disabled"><a class="page-link text-muted border-light px-2 py-1" href="#">&lsaquo; Previous</a></li>
                        <li class="page-item active"><a class="page-link border-primary px-2 py-1" href="#">1</a></li>
                        <li class="page-item"><a class="page-link text-muted border-light px-2 py-1" href="#">Next &rsaquo;</a></li>
                    </ul>
                </nav>
            </div>
        </div>
        
        <!-- Recent Activity Cards -->
        <?php if(!empty($enrichedComplaints)): ?>
        <h6 class="fw-bold text-dark mb-2" style="font-size:0.75rem;">Recent Activity</h6>
        <div class="row g-2 mb-3">
            <?php foreach(array_slice($enrichedComplaints, 0, 3) as $c): 
                if ($c['ui_status'] == 'Resolved') { $dot = 'primary'; $act = 'resolved'; $sClass = 'success'; }
                elseif ($c['ui_status'] == 'Pending') { $dot = 'warning'; $act = 'received'; $sClass = 'warning'; }
                else { $dot = 'primary'; $act = 'updated'; $sClass = 'primary'; }
            ?>
            <div class="col-md-4">
                <div class="activity-card h-100 d-flex justify-content-between align-items-start shadow-sm border-0">
                    <div class="d-flex align-items-start">
                        <span class="dot bg-<?= $dot ?> mt-1 flex-shrink-0"></span>
                        <div>
                            <div class="fw-bold text-dark" style="font-size:0.6rem; line-height:1.2;">Complaint #C-2025-<?= str_pad($c['id'], 3, '0', STR_PAD_LEFT) ?> <?= $act ?></div>
                            <div class="text-muted text-truncate" style="font-size:0.55rem; max-width: 140px; margin-top: 1px;">
                                <?= htmlspecialchars($c['subject']) ?> - <?= htmlspecialchars($c['first_name']) ?> (Rm <?= htmlspecialchars($c['room_number'] ?? '?') ?>)
                            </div>
                            <div class="text-muted" style="font-size:0.5rem; margin-top: 2px;"><?= date('M d, Y h:i A', strtotime($c['last_update'])) ?></div>
                        </div>
                    </div>
                    <span class="badge bg-<?= $sClass ?>-subtle text-<?= $sClass ?> rounded-pill" style="font-size:0.5rem;"><?= $c['ui_status'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
    </div>
    
    <!-- Right Column: Sidebar -->
    <div class="col-lg-4 col-xl-3">
        
        <!-- Complaint Overview -->
        <div class="card border-0 shadow-sm rounded-3 mb-2">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.75rem;"><i class="fa-solid fa-chart-pie text-primary me-2"></i> Complaint Overview</h6>
                
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="chart-container-donut ms-1 flex-shrink-0" style="height: 100px; width: 100px;">
                        <canvas id="complaintDonut"></canvas>
                        <div class="donut-center-text">
                            <div class="fw-bold text-dark" style="font-size:1.1rem; line-height:1;"><?= $totalComplaints ?></div>
                            <div class="text-muted" style="font-size:0.5rem;">Total</div>
                        </div>
                    </div>
                    
                    <div class="ps-2 flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark" style="font-size:0.6rem;"><span class="dot bg-warning"></span> Pending</span>
                            <span class="text-muted" style="font-size:0.55rem;"><?= $pendingCount ?> (<?= $totalComplaints ? round(($pendingCount/$totalComplaints)*100) : 0 ?>%)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark" style="font-size:0.6rem;"><span class="dot bg-primary"></span> In Progress</span>
                            <span class="text-muted" style="font-size:0.55rem;"><?= $inProgressCount ?> (<?= $totalComplaints ? round(($inProgressCount/$totalComplaints)*100) : 0 ?>%)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark" style="font-size:0.6rem;"><span class="dot bg-success"></span> Resolved</span>
                            <span class="text-muted" style="font-size:0.55rem;"><?= $resolvedCount ?> (<?= $totalComplaints ? round(($resolvedCount/$totalComplaints)*100) : 0 ?>%)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark" style="font-size:0.6rem;"><span class="dot bg-danger"></span> Rejected</span>
                            <span class="text-muted" style="font-size:0.55rem;">0 (0%)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Common Categories -->
        <div class="card border-0 shadow-sm rounded-3 mb-2">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-2" style="font-size:0.75rem;">Common Categories</h6>
                <?php if (empty($categoryStats)): ?>
                    <div class="text-muted text-center" style="font-size:0.65rem;">No data available.</div>
                <?php else: ?>
                    <?php foreach ($categoryStats as $stat): 
                        // Pick icon based on category name
                        $catLower = strtolower($stat['category']);
                        $icon = 'fa-ellipsis';
                        if (strpos($catLower, 'maintenance') !== false || strpos($catLower, 'repair') !== false) $icon = 'fa-wrench';
                        elseif (strpos($catLower, 'noise') !== false) $icon = 'fa-volume-high';
                        elseif (strpos($catLower, 'clean') !== false) $icon = 'fa-broom';
                        elseif (strpos($catLower, 'facilit') !== false) $icon = 'fa-building';
                        elseif (strpos($catLower, 'security') !== false) $icon = 'fa-shield-halved';
                    ?>
                    <div class="cat-row">
                        <span class="text-dark fw-semibold text-truncate pe-2"><i class="fa-solid <?= $icon ?> text-muted me-2 w-15px"></i><?= htmlspecialchars($stat['category'] ?: 'Others') ?></span>
                        <span class="fw-bold text-muted"><?= $stat['cat_count'] ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Response Time (Avg.) -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.75rem;">Response Time (Avg.)</h6>
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3 flex-shrink-0" style="width:40px; height:40px;">
                        <i class="fa-regular fa-clock fs-5"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-baseline gap-1">
                            <span class="fw-bold text-dark fs-5">1.8</span>
                            <span class="text-dark fw-semibold" style="font-size:0.7rem;">days</span>
                        </div>
                        <div class="text-muted" style="font-size:0.55rem;">Average resolution time</div>
                        <div class="text-success mt-1" style="font-size:0.55rem;"><i class="fa-solid fa-arrow-down me-1"></i>0.6 days from last month</div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<!-- View/Update Complaint Modal -->
<div class="modal fade" id="viewComplaintModal" tabindex="-1" aria-labelledby="viewComplaintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="complaints.php" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="update_complaint">
            <input type="hidden" name="complaint_id" id="modalComplaintId">
            
            <div class="modal-header bg-light border-0">
                <h6 class="modal-title fw-bold" id="viewComplaintModalLabel"><i class="fa-solid fa-file-lines text-primary me-2"></i>Complaint Details</h6>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-6">
                        <label class="text-muted" style="font-size:0.65rem; font-weight:600; text-transform:uppercase;">Tenant</label>
                        <div id="modalTenantName" class="fw-bold text-dark" style="font-size:0.85rem;"></div>
                        <div id="modalTenantRoom" class="text-muted" style="font-size:0.75rem;"></div>
                    </div>
                    <div class="col-6 text-end">
                        <label class="text-muted" style="font-size:0.65rem; font-weight:600; text-transform:uppercase;">Category</label>
                        <div id="modalCategory" class="fw-bold text-dark" style="font-size:0.85rem;"></div>
                    </div>
                </div>
                
                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="fw-bold text-dark mb-1" id="modalSubject" style="font-size:0.9rem;"></div>
                    <div class="text-muted" id="modalMessage" style="font-size:0.8rem; white-space: pre-wrap;"></div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label" style="font-size:0.75rem; font-weight:600;">Admin Response</label>
                    <textarea name="admin_response" id="modalAdminResponse" class="form-control shadow-none" rows="3" placeholder="Write a response or note..." style="font-size:0.8rem;"></textarea>
                </div>
                
                <div class="mb-2">
                    <label class="form-label" style="font-size:0.75rem; font-weight:600;">Status</label>
                    <select name="status" id="modalStatus" class="form-select shadow-none" style="font-size:0.8rem;">
                        <option value="open">Open</option>
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="closed">Closed</option>
                    </select>
                </div>
            </div>
            
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-light btn-sm fw-semibold" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary btn-sm fw-semibold">Save Updates</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Populate Modal
    const viewComplaintModal = document.getElementById('viewComplaintModal');
    if(viewComplaintModal) {
        viewComplaintModal.addEventListener('show.bs.modal', function (event) {
            const btn = event.relatedTarget;
            document.getElementById('modalComplaintId').value = btn.getAttribute('data-id');
            document.getElementById('modalTenantName').textContent = btn.getAttribute('data-tenant');
            document.getElementById('modalTenantRoom').textContent = 'Room ' + btn.getAttribute('data-room');
            document.getElementById('modalCategory').textContent = btn.getAttribute('data-category');
            document.getElementById('modalSubject').textContent = btn.getAttribute('data-subject');
            document.getElementById('modalMessage').textContent = btn.getAttribute('data-message');
            document.getElementById('modalAdminResponse').value = btn.getAttribute('data-admin-response');
            
            // Set status select
            let stat = btn.getAttribute('data-status');
            document.getElementById('modalStatus').value = stat;
        });
    }
    const compCtx = document.getElementById('complaintDonut').getContext('2d');
    new Chart(compCtx, {
        type: 'doughnut',
        data: {
            labels: ['Open', 'In Progress', 'Resolved', 'Closed'],
            datasets: [{
                data: [<?= $openCount ?: 0 ?>, <?= $inProgressCount ?: 0 ?>, <?= $resolvedCount ?: 0 ?>, <?= $closedCount ?: 0 ?>],
                backgroundColor: ['#f59e0b', '#0d6efd', '#22c55e', '#6c757d'],
                borderWidth: 0,
                cutout: '80%'
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
});
</script>

<script>
let searchInput, statusFilter, tableRows;
document.addEventListener('DOMContentLoaded', function() {
    searchInput = document.getElementById('searchInput');
    statusFilter = document.getElementById('statusFilter');
    tableRows = document.querySelectorAll('#complaintsTable tbody tr');

    if (searchInput) searchInput.addEventListener('input', filterTable);
    if (statusFilter) statusFilter.addEventListener('change', filterTable);
});

function filterTable() {
    if(!searchInput || !tableRows) return;
    const query = searchInput.value.toLowerCase();
    const status = statusFilter.value.toLowerCase();

    tableRows.forEach(row => {
        const textContent = row.textContent.toLowerCase();
        let show = textContent.includes(query);
        
        if (status !== 'all') {
            if (!textContent.includes(status)) show = false;
        }
        
        row.style.display = show ? '' : 'none';
    });
}
</script>
<?php require_once 'footer.php'; ?>
