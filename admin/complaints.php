<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/pagination.php';
require_once '../includes/ui.php';
require_once '../includes/complaints.php';
require_once '../includes/uploads.php';

/** Back to the list as it was (search, status filter, page) after saving. */
function complaintsBackUrl(string $flag): string {
    $back = "complaints.php?$flag=1";
    foreach (['q', 'status', 'page'] as $keep) {
        if (isset($_POST["keep_$keep"]) && $_POST["keep_$keep"] !== '') $back .= "&$keep=" . urlencode((string)$_POST["keep_$keep"]);
    }
    return $back;
}

// Reply to a complaint, set its priority, keep it open or mark it resolved.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_complaint') {
    $complaintId = (int)($_POST['complaint_id'] ?? 0);
    $status = ($_POST['status'] ?? '') === 'resolved' ? 'resolved' : 'open';
    $priority = array_key_exists($_POST['priority'] ?? '', COMPLAINT_PRIORITIES) ? $_POST['priority'] : 'normal';
    $response = trim((string)($_POST['admin_response'] ?? ''));

    $stmt = $pdo->prepare("SELECT status, responded_at, resolved_at FROM complaints WHERE id = ?");
    $stmt->execute([$complaintId]);
    $old = $stmt->fetch();
    if ($old) {
        $now = date('Y-m-d H:i:s');
        $respondedAt = $old['responded_at'] ?: ($response !== '' ? $now : null);   // the first reply
        $resolvedAt = $status === 'resolved' ? ($old['status'] === 'resolved' && $old['resolved_at'] ? $old['resolved_at'] : $now) : null;
        $pdo->prepare("UPDATE complaints SET status = ?, priority = ?, admin_response = ?, responded_at = ?, resolved_at = ?, updated_at = ? WHERE id = ?")
            ->execute([$status, $priority, $response === '' ? null : $response, $respondedAt, $resolvedAt, $now, $complaintId]);
    }
    header('Location: ' . complaintsBackUrl('success'));
    exit;
}

// A complaint the admin writes down for a tenant, e.g. one made in person or by phone.
$addError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_complaint') {
    $tenantId = (int)($_POST['tenant_id'] ?? 0);
    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 100);
    $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 500);
    $category = array_key_exists($_POST['category'] ?? '', COMPLAINT_CATEGORIES) ? $_POST['category'] : 'Others';
    $priority = array_key_exists($_POST['priority'] ?? '', COMPLAINT_PRIORITIES) ? $_POST['priority'] : 'normal';
    $tenantOk = $pdo->prepare("SELECT 1 FROM tenants WHERE id = ? AND status = 'active'");
    $tenantOk->execute([$tenantId]);
    if (!$tenantOk->fetchColumn()) {
        $addError = 'Choose the tenant the complaint is from.';
    } elseif ($subject === '' || $message === '') {
        $addError = 'Fill in the subject and the details.';
    } else {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO complaints (tenant_id, subject, message, category, status, priority, created_at, updated_at) VALUES (?, ?, ?, ?, 'open', ?, ?, ?)")
            ->execute([$tenantId, $subject, $message, $category, $priority, $now, $now]);
        header('Location: complaints.php?added=1');
        exit;
    }
}

$success_msg = isset($_GET['success']) ? 'Complaint updated.' : (isset($_GET['added']) ? 'Complaint added. The tenant sees it, and your reply, in their portal.' : '');

// --- DATA FETCHING ---
// Totals for the summary cards: every complaint, not just the page shown.
$counts = $pdo->query("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN c.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
        SUM(CASE WHEN " . COMPLAINT_PENDING_SQL . " THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN " . COMPLAINT_PROGRESS_SQL . " THEN 1 ELSE 0 END) AS in_progress
    FROM complaints c")->fetch();
$totalComplaints = (int)$counts['total'];
$resolvedCount = (int)$counts['resolved'];
$pendingCount = (int)$counts['pending'];
$inProgressCount = (int)$counts['in_progress'];

// Every category, with how many complaints each has had (zero included).
$categoryCounts = array_fill_keys(array_keys(COMPLAINT_CATEGORIES), 0);
foreach ($pdo->query("SELECT category, COUNT(*) AS n FROM complaints GROUP BY category")->fetchAll() as $row) {
    $cat = $row['category'] ?: 'Others';
    $cat = array_key_exists($cat, $categoryCounts) ? $cat : 'Others';   // older names count as Others
    $categoryCounts[$cat] += (int)$row['n'];
}

// How quickly complaints are answered and resolved (only complaints with those times recorded).
$avg = function (string $endColumn) use ($pdo): ?float {
    $rows = $pdo->query("SELECT created_at, $endColumn AS ended FROM complaints WHERE $endColumn IS NOT NULL")->fetchAll();
    if (!$rows) return null;
    $total = 0;
    foreach ($rows as $r) {
        $total += max(0, strtotime($r['ended']) - strtotime($r['created_at']));
    }
    return $total / count($rows);
};
$avgReply = $avg('responded_at');
$avgResolve = $avg('resolved_at');

// --- COMPLAINTS LIST (filtered and paged in SQL) ---
$filterSearch = queryParam('q');
$filterStatus = queryParam('status', 'all') ?: 'all';
$statusLabels = ['pending' => 'Pending', 'in progress' => 'In Progress', 'resolved' => 'Resolved', 'unresolved' => 'Unresolved'];
if (!isset($statusLabels[$filterStatus])) $filterStatus = 'all';

$where = [];
$params = [];
if ($filterSearch !== '') {
    $where[] = "(LOWER(t.first_name) LIKE ? OR LOWER(t.last_name) LIKE ?
                 OR LOWER(CONCAT(t.first_name, ' ', t.last_name)) LIKE ?
                 OR LOWER(c.subject) LIKE ? OR LOWER(c.message) LIKE ? OR LOWER(r.room_number) LIKE ?)";
    $like = '%' . strtolower($filterSearch) . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filterStatus === 'resolved') {
    $where[] = "c.status = 'resolved'";
} elseif ($filterStatus === 'pending') {
    $where[] = COMPLAINT_PENDING_SQL;
} elseif ($filterStatus === 'in progress') {
    $where[] = COMPLAINT_PROGRESS_SQL;
} elseif ($filterStatus === 'unresolved') {
    $where[] = "c.status <> 'resolved'";   // pending or in progress (the dashboard's "Unresolved Complaints")
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$fromSql = "FROM complaints c JOIN tenants t ON c.tenant_id = t.id LEFT JOIN rooms r ON t.room_id = r.id";

$countStmt = $pdo->prepare("SELECT COUNT(*) $fromSql $whereSql");
$countStmt->execute($params);
$pager = paginate((int)$countStmt->fetchColumn(), 10);

// Open ones first (high priority on top), then the newest.
$stmt = $pdo->prepare("
    SELECT c.*, t.first_name, t.last_name, t.profile_picture, r.room_number
    $fromSql
    $whereSql
    ORDER BY CASE WHEN c.status = 'resolved' THEN 1 ELSE 0 END,
             CASE c.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
             c.created_at DESC, c.id DESC" . paginationLimitSql($pager));
$stmt->execute($params);
$complaints = $stmt->fetchAll();

// For Add Complaint: the tenants living here now.
$activeTenants = $pdo->query("SELECT t.id, t.first_name, t.last_name, r.room_number FROM tenants t LEFT JOIN rooms r ON r.id = t.room_id
                              WHERE t.status = 'active' ORDER BY t.first_name, t.last_name")->fetchAll();

require_once 'header.php';
?>

<style>
    .complaints-table { font-size: 0.8rem; }
    .complaints-table>thead>tr>th { font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 600; background: #f8fafc; border-bottom: 0; padding: 0.75rem 0.5rem; white-space: nowrap; }
    .complaints-table>tbody>tr>td { padding: 0.75rem 0.5rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .complaints-table tbody tr[data-complaint] { cursor: pointer; }
    .complaints-table tbody tr:hover > td { background-color: #f8fafc; }
    .complaints-table .cell-sub { font-size: 0.7rem; color: #94a3b8; margin-top: 2px; }
    .complaint-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.72rem; color: #475569; }
    .soft-pill { display: inline-block; border-radius: 999px; padding: 0.25rem 0.7rem; font-size: 0.7rem; font-weight: 600; line-height: 1.2; white-space: nowrap; }
    .btn-view { background: #eff6ff; color: #2563eb; border: 0; font-weight: 600; }
    .btn-view:hover { background: #dbeafe; color: #1d4ed8; }
    .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 10px; }
    .side-row { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; font-size: 0.82rem; }
    .cat-icon { width: 32px; height: 32px; border-radius: 50%; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-right: 10px; }
</style>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <p class="text-muted mb-0" style="font-size: 0.85rem;">Track, manage, and respond to tenant complaints.</p>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary fw-semibold px-3 shadow-sm" style="font-size:0.8rem;" data-bs-toggle="modal" data-bs-target="#addComplaintModal"><i class="fa-solid fa-plus me-1"></i> Add Complaint</button>
        <a href="export_report.php?type=complaints&amp;format=csv" class="btn btn-light border bg-white fw-semibold px-3 shadow-sm" style="font-size:0.8rem;"><i class="fa-solid fa-download me-1"></i> Export Data</a>
    </div>
</div>

<?php if ($success_msg !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 py-2 px-3 mb-3" role="alert" style="font-size: 0.8rem;">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success_msg) ?>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close" style="font-size: 0.6rem;"></button>
    </div>
<?php endif; ?>
<?php if ($addError !== ''): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3 py-2 px-3 mb-3" role="alert" style="font-size: 0.8rem;"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($addError) ?></div>
<?php endif; ?>

<!-- Summary cards: each one filters the list below -->
<?= statCards([
    ['label' => 'Total Complaints', 'value' => $totalComplaints, 'sub' => 'All time',
     'icon' => 'fa-regular fa-comment-dots', 'color' => 'primary', 'href' => 'complaints.php', 'active' => $filterStatus === 'all', 'aria' => 'Show all complaints'],
    ['label' => 'Pending', 'value' => $pendingCount, 'sub' => 'Needs attention', 'subClass' => $pendingCount ? 'text-warning' : 'text-muted',
     'icon' => 'fa-regular fa-clock', 'color' => 'warning', 'href' => 'complaints.php?status=pending', 'active' => $filterStatus === 'pending', 'aria' => 'Show pending complaints'],
    ['label' => 'In Progress', 'value' => $inProgressCount, 'sub' => 'Being addressed',
     'icon' => 'fa-solid fa-rotate', 'color' => 'primary', 'href' => 'complaints.php?status=' . urlencode('in progress'), 'active' => $filterStatus === 'in progress', 'aria' => 'Show complaints in progress'],
    ['label' => 'Resolved', 'value' => $resolvedCount, 'sub' => 'Completed', 'subClass' => 'text-muted',
     'icon' => 'fa-regular fa-circle-check', 'color' => 'success', 'href' => 'complaints.php?status=resolved', 'active' => $filterStatus === 'resolved', 'aria' => 'Show resolved complaints'],
]) ?>

<div class="row g-3">
    <!-- Complaints list -->
    <div class="col-xxl-8">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-header bg-white border-0 p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-list-ul text-primary fs-5"></i>
                    <h6 class="fw-bold text-dark mb-0" style="font-size:0.95rem;">Complaints List</h6>
                    <?php if ($filterStatus !== 'all'): ?>
                        <a href="complaints.php<?= $filterSearch !== '' ? '?q=' . urlencode($filterSearch) : '' ?>" class="badge rounded-pill bg-primary-subtle text-primary text-decoration-none ms-1" title="Show all">
                            <?= $statusLabels[$filterStatus] ?> <i class="fa-solid fa-xmark ms-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <form method="GET" id="filterForm" class="m-0" style="min-width: 260px;">
                    <?php if ($filterStatus !== 'all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
                    <div class="input-group input-group-sm border rounded-2 bg-white">
                        <span class="input-group-text bg-transparent border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                        <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($filterSearch) ?>" class="form-control border-0 shadow-none ps-0" placeholder="Search by tenant, subject, or room..." style="font-size:0.8rem;">
                    </div>
                </form>
            </div>

            <div class="table-responsive px-2">
                <table class="table complaints-table mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Date</th>
                            <th>Tenant</th>
                            <th>Room</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th class="pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$complaints): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No complaints found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($complaints as $c):
                            $st = complaintStatus($c);
                            [$prLabel, $prColor] = complaintPriority($c['priority'] ?? 'normal');
                            $lastUpdate = !empty($c['updated_at']) ? $c['updated_at'] : $c['created_at'];
                            $data = htmlspecialchars(json_encode([
                                'id' => (int)$c['id'],
                                'code' => complaintCode($c),
                                'filed' => date('M d, Y h:i A', strtotime($c['created_at'])),
                                'tenant' => $c['first_name'] . ' ' . $c['last_name'],
                                'room' => $c['room_number'] ? 'Room ' . $c['room_number'] : 'No room',
                                'category' => $c['category'] ?: 'Others',
                                'subject' => $c['subject'],
                                'message' => $c['message'],
                                'response' => $c['admin_response'] ?? '',
                                'status' => $c['status'] === 'resolved' ? 'resolved' : 'open',
                                'priority' => $c['priority'] ?? 'normal',
                                'photo' => uploadSrc($c['photo_path'] ?? '', '../'),
                            ]), ENT_QUOTES);
                        ?>
                        <tr data-complaint="<?= $data ?>" onclick="openComplaint(this)">
                            <td class="ps-3 text-nowrap">
                                <div class="text-dark"><?= date('M d, Y', strtotime($c['created_at'])) ?></div>
                                <div class="cell-sub"><?= date('h:i A', strtotime($c['created_at'])) ?></div>
                            </td>
                            <td class="text-nowrap fw-semibold text-dark"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></td>
                            <td class="text-nowrap"><?= $c['room_number'] ? 'Room ' . htmlspecialchars($c['room_number']) : '<span class="text-muted fst-italic">No room</span>' ?></td>
                            <td style="min-width: 140px; max-width: 220px;">
                                <div class="text-dark"><?= !empty($c['photo_path']) ? '<i class="fa-regular fa-image text-muted me-1" title="Has a photo"></i>' : '' ?><?= htmlspecialchars($c['subject'] ?? '') ?></div>
                            </td>
                            <td><span class="soft-pill bg-<?= $prColor ?>-subtle text-<?= $prColor ?>-emphasis"><?= $prLabel ?></span></td>
                            <td><span class="soft-pill bg-<?= $st['color'] ?>-subtle text-<?= $st['color'] ?>-emphasis"><?= $st['label'] ?></span></td>
                            <td class="text-nowrap">
                                <div class="text-dark"><?= date('M d, Y', strtotime($lastUpdate)) ?></div>
                                <div class="cell-sub"><?= date('h:i A', strtotime($lastUpdate)) ?></div>
                            </td>
                            <td class="pe-3 text-nowrap">
                                <button type="button" class="btn btn-sm btn-view px-3" onclick="event.stopPropagation(); openComplaint(this)"><i class="fa-regular fa-eye me-1"></i>View</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-0 p-3 d-flex justify-content-between align-items-center flex-wrap gap-2 mt-auto">
                <span class="text-muted" style="font-size:0.78rem;"><?= str_replace(' to ', ' - ', paginationSummary($pager, $pager['total'] === 1 ? 'complaint' : 'complaints')) ?></span>
                <nav><ul class="pagination pagination-sm mb-0"><?= paginationControls($pager) ?></ul></nav>
            </div>
        </div>
    </div>

    <!-- Right: status, categories, response time (under the list on narrower screens) -->
    <div class="col-xxl-4">
        <div class="row g-3 row-cols-1 row-cols-md-3 row-cols-xxl-1">
            <div class="col">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body p-3">
                        <h6 class="fw-bold text-dark mb-2" style="font-size:0.9rem;"><i class="fa-solid fa-chart-pie text-primary me-2"></i>Status Overview</h6>
                        <?php foreach ([['Pending', $pendingCount, '#f59e0b', 'pending'], ['In Progress', $inProgressCount, '#3b82f6', 'in progress'], ['Resolved', $resolvedCount, '#22c55e', 'resolved']] as [$label, $n, $color, $key]): ?>
                        <a href="complaints.php?status=<?= urlencode($key) ?>" class="side-row text-decoration-none">
                            <span class="text-dark"><span class="dot" style="background: <?= $color ?>;"></span><?= $label ?></span>
                            <span class="fw-bold text-dark"><?= $n ?></span>
                        </a>
                        <?php endforeach; ?>
                        <div class="side-row border-top mt-1 pt-2">
                            <span class="text-dark">Total</span>
                            <span class="fw-bold text-dark"><?= $totalComplaints ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body p-3">
                        <h6 class="fw-bold text-dark mb-2" style="font-size:0.9rem;"><i class="fa-regular fa-folder text-primary me-2"></i>Common Categories</h6>
                        <?php foreach ($categoryCounts as $cat => $n): ?>
                        <div class="side-row py-1">
                            <span class="text-dark d-flex align-items-center"><span class="cat-icon"><i class="fa-solid <?= COMPLAINT_CATEGORIES[$cat] ?>"></i></span><?= htmlspecialchars($cat) ?></span>
                            <span class="fw-bold text-dark"><?= $n ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body p-3">
                        <h6 class="fw-bold text-dark mb-2" style="font-size:0.9rem;"><i class="fa-regular fa-clock text-primary me-2"></i>Average Response Time</h6>
                        <?php if ($avgReply !== null): ?>
                            <div class="fw-bold" style="font-size:1.6rem; color:#0f2a5c;"><?= durationText($avgReply) ?></div>
                        <?php else: ?>
                            <div class="fw-semibold text-muted" style="font-size:1rem;">No replies yet</div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:0.75rem;">From complaint submission to first response</div>
                        <div class="text-muted mt-2" style="font-size:0.75rem;">Average time to resolve: <strong class="text-dark"><?= $avgResolve !== null ? durationText($avgResolve) : '—' ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View / reply to a complaint -->
<div class="modal fade" id="viewComplaintModal" tabindex="-1" aria-labelledby="viewComplaintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="complaints.php" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="update_complaint">
            <input type="hidden" name="complaint_id" id="modalComplaintId">
            <input type="hidden" name="keep_q" value="<?= htmlspecialchars($filterSearch) ?>">
            <input type="hidden" name="keep_status" value="<?= htmlspecialchars($filterStatus === 'all' ? '' : $filterStatus) ?>">
            <input type="hidden" name="keep_page" value="<?= $pager['page'] > 1 ? $pager['page'] : '' ?>">

            <div class="modal-header bg-light border-0">
                <div>
                    <h6 class="modal-title fw-bold mb-0" id="viewComplaintModalLabel"><i class="fa-solid fa-file-lines text-primary me-2"></i>Complaint <span id="modalCode" class="complaint-code"></span></h6>
                    <small class="text-muted" id="modalFiled"></small>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <div class="text-muted text-uppercase fw-semibold" style="font-size:0.62rem;">Tenant</div>
                        <div id="modalTenantName" class="fw-bold text-dark" style="font-size:0.88rem;"></div>
                        <div id="modalTenantRoom" class="text-muted" style="font-size:0.75rem;"></div>
                    </div>
                    <div class="col-sm-6 text-sm-end">
                        <div class="text-muted text-uppercase fw-semibold" style="font-size:0.62rem;">Category</div>
                        <div id="modalCategory" class="fw-bold text-dark" style="font-size:0.88rem;"></div>
                    </div>
                </div>

                <div class="mb-3 p-3 bg-light rounded-3">
                    <div class="fw-bold text-dark mb-1" id="modalSubject" style="font-size:0.9rem;"></div>
                    <div class="text-muted" id="modalMessage" style="font-size:0.82rem; white-space: pre-wrap;"></div>
                    <a href="#" id="modalPhoto" target="_blank" rel="noopener" class="d-none mt-2"><img alt="Photo from the tenant" class="rounded-2 border" style="max-height: 160px; max-width: 100%;"></a>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="modalAdminResponse" style="font-size:0.78rem;">Your reply <span class="text-muted fw-normal">(the tenant sees it in their portal)</span></label>
                    <textarea name="admin_response" id="modalAdminResponse" class="form-control shadow-none" rows="3" placeholder="e.g., A plumber will come on Saturday morning." style="font-size:0.82rem;"></textarea>
                </div>

                <div class="row g-3">
                    <div class="col-sm-5">
                        <label class="form-label fw-semibold" for="modalPriority" style="font-size:0.78rem;">Priority</label>
                        <select name="priority" id="modalPriority" class="form-select shadow-none" style="font-size:0.82rem;">
                            <?php foreach (COMPLAINT_PRIORITIES as $value => [$label]): ?>
                                <option value="<?= $value ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-7">
                        <label class="form-label fw-semibold d-block" style="font-size:0.78rem;">Status</label>
                        <div class="btn-group w-100" role="group" aria-label="Status">
                            <input type="radio" class="btn-check" name="status" id="statusOpen" value="open">
                            <label class="btn btn-outline-primary btn-sm" for="statusOpen"><i class="fa-solid fa-folder-open me-1"></i>Keep open</label>
                            <input type="radio" class="btn-check" name="status" id="statusResolved" value="resolved">
                            <label class="btn btn-outline-success btn-sm" for="statusResolved"><i class="fa-solid fa-circle-check me-1"></i>Resolved</label>
                        </div>
                        <div class="form-text" style="font-size:0.65rem;">An open complaint shows as Pending until you reply, then In Progress.</div>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-light border btn-sm fw-semibold" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary btn-sm fw-semibold px-3">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Add a complaint for a tenant (made in person or by phone) -->
<div class="modal fade" id="addComplaintModal" tabindex="-1" aria-labelledby="addComplaintTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="complaints.php" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="add_complaint">
            <div class="modal-header bg-light border-0">
                <h6 class="modal-title fw-bold" id="addComplaintTitle"><i class="fa-solid fa-plus text-primary me-2"></i>Add Complaint</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body form-compact">
                <p class="text-muted" style="font-size:0.75rem;">For a complaint a tenant made in person or by phone. It shows in their portal like one they filed themselves.</p>
                <div class="mb-2">
                    <label for="acTenant">Tenant</label>
                    <select name="tenant_id" id="acTenant" class="form-select" required>
                        <option value="">Choose a tenant</option>
                        <?php foreach ($activeTenants as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= (int)($_POST['tenant_id'] ?? 0) === (int)$t['id'] && $addError ? 'selected' : '' ?>><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?><?= $t['room_number'] ? ' · Room ' . htmlspecialchars($t['room_number']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-7">
                        <label for="acCategory">Category</label>
                        <select name="category" id="acCategory" class="form-select">
                            <?php foreach (array_keys(COMPLAINT_CATEGORIES) as $cat): ?><option><?= htmlspecialchars($cat) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-5">
                        <label for="acPriority">Priority</label>
                        <select name="priority" id="acPriority" class="form-select">
                            <?php foreach (COMPLAINT_PRIORITIES as $value => [$label]): ?><option value="<?= $value ?>" <?= $value === 'normal' ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label for="acSubject">Subject</label>
                    <input type="text" name="subject" id="acSubject" class="form-control" maxlength="100" required placeholder="e.g., Leaking faucet in Room 2" value="<?= htmlspecialchars($addError ? (string)($_POST['subject'] ?? '') : '') ?>">
                </div>
                <div class="mb-1">
                    <label for="acMessage">Details</label>
                    <textarea name="message" id="acMessage" class="form-control" rows="3" maxlength="500" required><?= htmlspecialchars($addError ? (string)($_POST['message'] ?? '') : '') ?></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm px-3">Add Complaint</button>
            </div>
        </form>
    </div>
</div>

<script>
function openComplaint(el) {
    const c = JSON.parse(el.closest('[data-complaint]').dataset.complaint);
    document.getElementById('modalComplaintId').value = c.id;
    document.getElementById('modalCode').textContent = c.code;
    document.getElementById('modalFiled').textContent = 'Filed ' + c.filed;
    document.getElementById('modalTenantName').textContent = c.tenant;
    document.getElementById('modalTenantRoom').textContent = c.room;
    document.getElementById('modalCategory').textContent = c.category;
    document.getElementById('modalSubject').textContent = c.subject;
    document.getElementById('modalMessage').textContent = c.message;
    const photo = document.getElementById('modalPhoto');
    photo.classList.toggle('d-none', !c.photo);
    if (c.photo) { photo.href = c.photo; photo.querySelector('img').src = c.photo; }
    document.getElementById('modalAdminResponse').value = c.response;
    document.getElementById('modalPriority').value = c.priority;
    document.getElementById(c.status === 'resolved' ? 'statusResolved' : 'statusOpen').checked = true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('viewComplaintModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    // Typing in the search box submits the form after a short pause, so the server can filter.
    const form = document.getElementById('filterForm');
    const search = document.getElementById('searchInput');
    let timer;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(() => form.submit(), 400);
    });
    <?php if ($addError !== ''): ?>
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addComplaintModal')).show();
    <?php endif; ?>
});
</script>

<?php require_once 'footer.php'; ?>
