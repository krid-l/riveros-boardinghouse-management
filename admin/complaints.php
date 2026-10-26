<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/pagination.php';
require_once '../includes/ui.php';
require_once '../includes/complaints.php';
require_once '../includes/uploads.php';

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
    $back = 'complaints.php?success=1';
    foreach (['q', 'status', 'page'] as $keep) {
        if (isset($_POST["keep_$keep"]) && $_POST["keep_$keep"] !== '') $back .= "&$keep=" . urlencode((string)$_POST["keep_$keep"]);
    }
    header("Location: $back");
    exit;
}

$success_msg = isset($_GET['success']) ? "Complaint updated." : "";

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

// Most common categories.
$categoryStats = $pdo->query("SELECT category, COUNT(*) AS cat_count FROM complaints GROUP BY category ORDER BY cat_count DESC LIMIT 5")->fetchAll();

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

$where = [];
$params = [];
if ($filterSearch !== '') {
    $where[] = "(LOWER(t.first_name) LIKE ? OR LOWER(t.last_name) LIKE ?
                 OR LOWER(CONCAT(t.first_name, ' ', t.last_name)) LIKE ?
                 OR LOWER(c.subject) LIKE ? OR LOWER(c.message) LIKE ?)";
    $like = '%' . strtolower($filterSearch) . '%';
    array_push($params, $like, $like, $like, $like, $like);
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

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM complaints c JOIN tenants t ON c.tenant_id = t.id $whereSql");
$countStmt->execute($params);
$pager = paginate((int)$countStmt->fetchColumn(), 10);

// Open ones first (high priority on top), then the newest.
$stmt = $pdo->prepare("
    SELECT c.*, t.first_name, t.last_name, t.profile_picture, r.room_number
    FROM complaints c
    JOIN tenants t ON c.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    $whereSql
    ORDER BY CASE WHEN c.status = 'resolved' THEN 1 ELSE 0 END,
             CASE c.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,
             c.created_at DESC, c.id DESC" . paginationLimitSql($pager));
$stmt->execute($params);
$complaints = $stmt->fetchAll();

require_once 'header.php';
?>

<style>
    .complaints-table { font-size: 0.78rem; }
    .complaints-table>thead>tr>th { font-size: 0.64rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700; background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 0.65rem 0.6rem; white-space: nowrap; }
    .complaints-table>tbody>tr>td { padding: 0.6rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .complaints-table tbody tr[data-complaint] { cursor: pointer; }
    .complaints-table tbody tr:hover { background-color: #f8fafc; }
    .complaints-table .cell-sub { font-size: 0.68rem; color: #94a3b8; }
    .complaint-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.72rem; color: #475569; }
    .chart-container-donut { position: relative; height: 110px; width: 110px; }
    .donut-center-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
    .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; margin-right: 6px; }
    .side-row { display: flex; justify-content: space-between; align-items: center; padding: 0.45rem 0; border-bottom: 1px dashed #f1f5f9; font-size: 0.75rem; }
    .side-row:last-child { border-bottom: none; }
</style>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <p class="text-muted mb-0" style="font-size: 0.8rem;">View, reply to and resolve tenant complaints.</p>
</div>

<?php if (!empty($success_msg)): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 py-2 px-3 mb-3" role="alert" style="font-size: 0.8rem;">
        <i class="fa-solid fa-circle-check me-2"></i><?= $success_msg ?>
        <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close" style="font-size: 0.6rem;"></button>
    </div>
<?php endif; ?>

<!-- Summary cards: each one filters the list below -->
<?= statCards([
    ['label' => 'Total Complaints', 'value' => $totalComplaints, 'sub' => ($pendingCount + $inProgressCount) . ' unresolved',
     'icon' => 'fa-solid fa-comment-dots', 'color' => 'primary', 'href' => 'complaints.php', 'active' => $filterStatus === 'all', 'aria' => 'Show all complaints'],
    ['label' => 'Pending', 'value' => $pendingCount, 'sub' => 'Waiting for a reply', 'subClass' => $pendingCount ? 'text-warning' : 'text-muted',
     'icon' => 'fa-solid fa-clock', 'color' => 'warning', 'href' => 'complaints.php?status=pending', 'active' => $filterStatus === 'pending', 'aria' => 'Show pending complaints'],
    ['label' => 'In Progress', 'value' => $inProgressCount, 'sub' => 'Replied, still open',
     'icon' => 'fa-solid fa-spinner', 'color' => 'info', 'href' => 'complaints.php?status=' . urlencode('in progress'), 'active' => $filterStatus === 'in progress', 'aria' => 'Show complaints in progress'],
    ['label' => 'Resolved', 'value' => $resolvedCount, 'sub' => $totalComplaints ? round($resolvedCount / $totalComplaints * 100) . '% of all complaints' : 'None yet', 'subClass' => 'text-success',
     'icon' => 'fa-solid fa-circle-check', 'color' => 'success', 'href' => 'complaints.php?status=resolved', 'active' => $filterStatus === 'resolved', 'aria' => 'Show resolved complaints'],
]) ?>

<div class="row g-3">
    <!-- Complaints list: the full width of the page -->
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white border-0 p-3 pb-2">
                <h6 class="fw-bold text-dark mb-0" style="font-size:0.85rem;"><i class="fa-solid fa-list text-primary me-2"></i>Complaints</h6>
                <small class="text-muted" style="font-size:0.7rem;">Open complaints come first, high priority on top. Click one to reply or resolve it.</small>
            </div>
            <form method="GET" id="filterForm" class="d-flex flex-wrap gap-2 px-3 pb-3 align-items-center border-bottom">
                <div class="input-group input-group-sm rounded-2 border bg-white flex-grow-1" style="max-width:320px;">
                    <span class="input-group-text bg-transparent border-0 pe-1"><i class="fa-solid fa-magnifying-glass text-muted" style="font-size:0.7rem;"></i></span>
                    <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($filterSearch) ?>" class="form-control border-0 shadow-none px-1" placeholder="Search tenant, subject or message..." style="font-size:0.75rem;">
                </div>
                <select name="status" class="form-select form-select-sm border rounded-2 shadow-none" style="width:150px; font-size:0.75rem;" onchange="this.form.submit()" aria-label="Status">
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All statuses</option>
                    <option value="unresolved" <?= $filterStatus === 'unresolved' ? 'selected' : '' ?>>Unresolved</option>
                    <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="in progress" <?= $filterStatus === 'in progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="resolved" <?= $filterStatus === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                </select>
                <button type="submit" class="btn btn-sm btn-primary rounded-2 px-3" style="font-size:0.72rem;"><i class="fa-solid fa-magnifying-glass me-1"></i> Search</button>
                <?php if ($filterSearch !== '' || $filterStatus !== 'all'): ?>
                    <a href="complaints.php" class="btn btn-link btn-sm text-decoration-none fw-semibold ms-auto" style="font-size:0.72rem;"><i class="fa-solid fa-rotate-right me-1"></i> Clear filters</a>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="table complaints-table mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Complaint</th>
                            <th>Tenant</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Last Update</th>
                            <th class="pe-3"><span class="visually-hidden">Action</span></th>
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
                                'filed' => date('M j, Y g:i A', strtotime($c['created_at'])),
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
                                <div class="complaint-code"><?= complaintCode($c) ?></div>
                                <div class="cell-sub"><?= date('M j, Y', strtotime($c['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?= avatarHtml($c['first_name'] . ' ' . $c['last_name'], 28, 'me-2 shadow-sm', $c['profile_picture'] ?? null, '../') ?>
                                    <div>
                                        <div class="fw-bold text-dark text-nowrap"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></div>
                                        <div class="cell-sub text-nowrap"><?= $c['room_number'] ? 'Room ' . htmlspecialchars($c['room_number']) : 'No room' ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="max-width: 220px;">
                                <div class="fw-semibold text-dark text-truncate" title="<?= htmlspecialchars($c['subject'] ?? '') ?>"><?= !empty($c['photo_path']) ? '<i class="fa-regular fa-image text-muted me-1" title="Has a photo"></i>' : '' ?><?= htmlspecialchars($c['subject'] ?? '') ?></div>
                                <div class="cell-sub text-truncate"><?= htmlspecialchars($c['message'] ?? '') ?></div>
                            </td>
                            <td class="text-nowrap"><?= htmlspecialchars($c['category'] ?: 'Others') ?></td>
                            <td><span class="badge rounded-pill bg-<?= $prColor ?>-subtle text-<?= $prColor ?> border border-<?= $prColor ?>-subtle"><?= $prLabel ?></span></td>
                            <td class="text-nowrap"><span class="badge rounded-pill bg-<?= $st['color'] ?>-subtle text-<?= $st['color'] ?> border border-<?= $st['color'] ?>-subtle px-2 py-1"><i class="fa-solid <?= $st['icon'] ?> me-1"></i><?= $st['label'] ?></span></td>
                            <td class="text-nowrap">
                                <div class="text-dark"><?= date('M j, Y', strtotime($lastUpdate)) ?></div>
                                <div class="cell-sub"><?= date('g:i A', strtotime($lastUpdate)) ?></div>
                            </td>
                            <td class="pe-3 text-end text-nowrap">
                                <button type="button" class="btn btn-sm <?= $st['key'] === 'pending' ? 'btn-primary' : 'btn-light border' ?> px-2 py-1" style="font-size:0.72rem;" onclick="event.stopPropagation(); openComplaint(this)">
                                    <?= $st['key'] === 'pending' ? '<i class="fa-solid fa-reply me-1"></i>Reply' : '<i class="fa-regular fa-eye me-1"></i>View' ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top p-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted" style="font-size:0.72rem;"><?= paginationSummary($pager, 'complaints') ?></span>
                <nav><ul class="pagination pagination-sm mb-0"><?= paginationControls($pager) ?></ul></nav>
            </div>
        </div>
    </div>

    <!-- Overview, response time and categories, side by side under the list -->
    <div class="col-12">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body p-3">
                        <h6 class="fw-bold text-dark mb-3" style="font-size:0.8rem;"><i class="fa-solid fa-chart-pie text-primary me-2"></i>Overview</h6>
                        <div class="d-flex align-items-center gap-3">
                            <div class="chart-container-donut flex-shrink-0">
                                <canvas id="complaintDonut" aria-label="Complaints by status" role="img"></canvas>
                                <div class="donut-center-text">
                                    <div class="fw-bold text-dark" style="font-size:1.1rem; line-height:1;"><?= $totalComplaints ?></div>
                                    <div class="text-muted" style="font-size:0.6rem;">Total</div>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <?php foreach ([['Pending', $pendingCount, '#f59e0b'], ['In Progress', $inProgressCount, '#0d6efd'], ['Resolved', $resolvedCount, '#22c55e']] as [$label, $n, $color]): ?>
                                <div class="side-row">
                                    <span class="fw-semibold text-dark"><span class="dot" style="background: <?= $color ?>;"></span><?= $label ?></span>
                                    <span class="text-muted"><?= $n ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body p-3">
                        <h6 class="fw-bold text-dark mb-2" style="font-size:0.8rem;"><i class="fa-regular fa-clock text-primary me-2"></i>Response Time</h6>
                        <div class="side-row">
                            <span class="text-muted">Average time to reply</span>
                            <span class="fw-bold text-dark"><?= $avgReply !== null ? durationText($avgReply) : '—' ?></span>
                        </div>
                        <div class="side-row">
                            <span class="text-muted">Average time to resolve</span>
                            <span class="fw-bold text-dark"><?= $avgResolve !== null ? durationText($avgResolve) : '—' ?></span>
                        </div>
                        <?php if ($avgReply === null && $avgResolve === null): ?>
                            <div class="text-muted mt-1" style="font-size:0.65rem;">Shows once complaints have been replied to or resolved.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-3 h-100">
                    <div class="card-body p-3">
                        <h6 class="fw-bold text-dark mb-2" style="font-size:0.8rem;"><i class="fa-solid fa-tags text-primary me-2"></i>Common Categories</h6>
                        <?php if (!$categoryStats): ?>
                            <div class="text-muted" style="font-size:0.72rem;">No complaints yet.</div>
                        <?php endif; ?>
                        <?php foreach ($categoryStats as $stat): ?>
                        <div class="side-row">
                            <span class="text-dark fw-semibold text-truncate pe-2"><?= htmlspecialchars($stat['category'] ?: 'Others') ?></span>
                            <span class="fw-bold text-muted"><?= (int)$stat['cat_count'] ?></span>
                        </div>
                        <?php endforeach; ?>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    if (window.Chart) {
        new Chart(document.getElementById('complaintDonut'), {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'In Progress', 'Resolved'],
                datasets: [{
                    data: <?= json_encode([$pendingCount, $inProgressCount, $resolvedCount]) ?>,
                    backgroundColor: ['#f59e0b', '#0d6efd', '#22c55e'],
                    borderWidth: 0,
                    cutout: '78%'
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }

    // Typing in the search box submits the form after a short pause, so the server can filter.
    const form = document.getElementById('filterForm');
    const search = document.getElementById('searchInput');
    let timer;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(() => form.submit(), 400);
    });
});
</script>

<?php require_once 'footer.php'; ?>
