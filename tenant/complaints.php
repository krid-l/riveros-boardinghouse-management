<?php
require_once 'header.php';
require_once '../includes/notifications.php';
require_once '../includes/complaints.php';
require_once '../includes/uploads.php';

const TENANT_COMPLAINT_CATEGORIES = ['Maintenance & Repair', 'Noise Complaint', 'Cleanliness', 'Security', 'Others'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject'])) {
    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 100);
    $message = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 500);
    $category = in_array($_POST['category'] ?? '', TENANT_COMPLAINT_CATEGORIES, true) ? $_POST['category'] : 'Others';

    if ($subject === '' || $message === '') {
        $error = 'Please fill in the subject and the details.';
    } else {
        // Optional photo, checked like the payment screenshots.
        $upload = isset($_FILES['photo']) ? storeUploadedImage($_FILES['photo'], 'payments/complaints') : ['path' => null, 'error' => null];
        if ($upload['error']) {
            $error = $upload['error'];
        } else {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO complaints (tenant_id, subject, message, category, status, photo_path, created_at, updated_at) VALUES (?, ?, ?, ?, 'open', ?, ?, ?)")
                ->execute([$currentTenant['id'], $subject, $message, $category, $upload['path'], $now, $now]);
            $success = "Complaint submitted. You'll see the admin's reply here.";
            notifyAdmin($pdo, 'complaint', 'New complaint: ' . $subject,
                trim($currentTenant['first_name'] . ' ' . $currentTenant['last_name']) . " · $category", 'complaints.php?status=pending');
        }
    }
}

// Fetch complaints
$stmt = $pdo->prepare("SELECT * FROM complaints WHERE tenant_id = ? ORDER BY created_at DESC");
$stmt->execute([$currentTenant['id']]);
$complaints = $stmt->fetchAll();
?>

<style>
/* Custom Styles for Complaints Screen */
.complaints-container { max-width: 800px; margin: 0 auto; padding-bottom: 3rem; }

/* Form Card */
.complaint-form-card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 1.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
.cf-header { background: #64748b; color: #ffffff; padding: 1rem 1.25rem; font-weight: 600; font-size: 1rem; display: flex; align-items: center; gap: 10px; }
.cf-body { padding: 1.5rem; }

.form-label-custom { font-size: 0.8rem; font-weight: 700; color: #1e293b; margin-bottom: 0.4rem; display: flex; justify-content: space-between; }
.required-ast { color: #ef4444; }
.form-control-custom { border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.85rem; padding: 0.75rem 1rem; box-shadow: none; color: #334155; }
.form-control-custom:focus { border-color: #3b82f6; box-shadow: 0 0 0 0.2rem rgba(59,130,246,0.1); }
.char-count { font-weight: 500; color: #94a3b8; font-size: 0.7rem; margin-top: 4px; text-align: right; }

.file-drop-area { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 2rem; text-align: center; background: #f8fafc; cursor: pointer; transition: all 0.2s; position: relative; }
.file-drop-area:hover { border-color: #94a3b8; background: #f1f5f9; }
.file-drop-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }

.btn-submit-complaint { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; font-weight: 700; font-size: 0.95rem; border-radius: 8px; padding: 0.85rem; width: 100%; border: none; margin-top: 1rem; transition: transform 0.2s; box-shadow: 0 4px 6px rgba(245, 158, 11, 0.2); }
.btn-submit-complaint:hover { transform: translateY(-1px); box-shadow: 0 6px 10px rgba(245, 158, 11, 0.3); }

.info-box-blue { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 1.25rem; display: flex; gap: 1rem; align-items: flex-start; margin-bottom: 1.5rem; }
.ib-title { font-size: 0.85rem; font-weight: 700; color: #1d4ed8; margin-bottom: 0.25rem; }
.ib-text { font-size: 0.8rem; color: #334155; line-height: 1.5; }

/* History Card */
.history-card { background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
.hc-header { background: #334155; color: #ffffff; padding: 1rem 1.25rem; display: flex; justify-content: space-between; align-items: center; }
.hc-title { font-weight: 600; font-size: 1rem; display: flex; align-items: center; gap: 10px; }

/* Tabs */
.complaint-tabs { display: flex; border-bottom: 1px solid #e2e8f0; overflow-x: auto; scrollbar-width: none; }
.complaint-tabs::-webkit-scrollbar { display: none; }
.ctab { padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 700; cursor: pointer; border-bottom: 2px solid transparent; white-space: nowrap; color: #64748b; }
.ctab[data-filter="all"].active { color: #eab308; border-bottom-color: #eab308; }
.ctab[data-filter="pending"].active { color: #3b82f6; border-bottom-color: #3b82f6; }
.ctab[data-filter="in_progress"].active { color: #f97316; border-bottom-color: #f97316; }
.ctab[data-filter="resolved"].active { color: #22c55e; border-bottom-color: #22c55e; }

/* History Items */
.complaint-item { border-bottom: 1px solid #f1f5f9; padding: 1.25rem; display: flex; align-items: flex-start; gap: 1.25rem; transition: background 0.2s; }
.complaint-item:last-child { border-bottom: none; }
.complaint-item:hover { background: #f8fafc; }

.ci-icon-box { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
.icon-open { background: #eff6ff; color: #3b82f6; }
.icon-progress { background: #fff7ed; color: #f97316; }
.icon-resolved { background: #f0fdf4; color: #22c55e; }

.ci-content { flex: 1; }
.ci-meta { font-size: 0.7rem; color: #64748b; margin-bottom: 0.25rem; font-weight: 500; }
.ci-subject { font-size: 0.95rem; font-weight: 700; color: #1e293b; margin-bottom: 0.25rem; line-height: 1.3; }
.ci-message { font-size: 0.8rem; color: #475569; line-height: 1.5; }
.ci-photo-link { display: inline-block; font-size: 0.75rem; font-weight: 600; margin-top: 0.4rem; text-decoration: none; }
.ci-reply { margin-top: 0.75rem; background: #eff6ff; border-left: 3px solid #3b82f6; border-radius: 6px; padding: 0.6rem 0.75rem; font-size: 0.8rem; color: #1e293b; }
.ci-reply-title { font-size: 0.7rem; font-weight: 700; color: #1d4ed8; margin-bottom: 0.2rem; }
.ci-waiting { margin-top: 0.6rem; font-size: 0.72rem; color: #94a3b8; }

.ci-status-badge { font-size: 0.7rem; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 6px; white-space: nowrap; }
.badge-open { background: #eff6ff; color: #2563eb; }
.badge-progress { background: #fff7ed; color: #ea580c; }
.badge-resolved { background: #f0fdf4; color: #16a34a; }

@media (max-width: 767.98px) {
    .complaint-item { gap: 0.75rem; padding: 1rem; }
    .ci-meta .ci-status-badge { font-size: 0.6rem; padding: 0.1rem 0.4rem; }
}
</style>

<div class="complaints-container">
    
    <div class="mb-3">
        <p class="text-muted mb-0" style="font-size: 0.85rem;">We're here to help. Submit your concern and we'll take care of it.</p>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success d-flex align-items-center border-0 shadow-sm rounded-3 py-2 mb-4" style="font-size: 0.85rem;"><i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger d-flex align-items-center border-0 shadow-sm rounded-3 py-2 mb-4" style="font-size: 0.85rem;"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Form Card -->
    <div class="complaint-form-card">
        <div class="cf-header">
            <i class="fa-regular fa-pen-to-square"></i> Submit New Complaint
        </div>
        <div class="cf-body">
            <form method="POST" enctype="multipart/form-data">
                <!-- Subject -->
                <div class="mb-3">
                    <label class="form-label-custom">
                        <span>Subject <span class="required-ast">*</span></span>
                    </label>
                    <input type="text" name="subject" id="inpSubject" class="form-control-custom w-100" placeholder="e.g. Water not running, Noise complaint, etc." maxlength="100" value="<?= htmlspecialchars(!empty($error) ? ($_POST['subject'] ?? '') : '') ?>" required>
                    <div class="char-count" id="countSubject">0/100</div>
                </div>

                <!-- Category -->
                <div class="mb-3">
                    <label class="form-label-custom">Category</label>
                    <select name="category" class="form-control-custom w-100">
                        <?php foreach (TENANT_COMPLAINT_CATEGORIES as $cat): ?>
                            <option <?= ($error ?? '') && ($_POST['category'] ?? '') === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Message -->
                <div class="mb-3">
                    <label class="form-label-custom">
                        <span>Message / Details <span class="required-ast">*</span></span>
                    </label>
                    <textarea name="message" id="inpMessage" class="form-control-custom w-100" rows="4" placeholder="Please provide more details about your concern..." maxlength="500" required><?= htmlspecialchars(!empty($error) ? ($_POST['message'] ?? '') : '') ?></textarea>
                    <div class="char-count" id="countMessage">0/500</div>
                </div>

                <!-- Attach a photo -->
                <div class="mb-3">
                    <label class="form-label-custom" style="margin-bottom: 0.5rem;" for="complaintPhoto">Attach a photo (optional)</label>
                    <div class="file-drop-area">
                        <input type="file" name="photo" id="complaintPhoto" class="file-drop-input" accept="image/png, image/jpeg, image/webp">
                        <div class="fw-bold text-dark" style="font-size: 0.85rem;" id="photoLabel">
                            <i class="fa-solid fa-cloud-arrow-up text-primary me-1"></i> <span class="text-primary">Choose a photo</span> or drag it here
                        </div>
                        <div class="text-muted mt-1" style="font-size: 0.75rem;">JPG, PNG or WEBP, up to 5MB</div>
                    </div>
                </div>

                <button type="submit" class="btn-submit-complaint">
                    <i class="fa-regular fa-paper-plane me-2"></i> Submit Complaint
                </button>
                <div class="text-center mt-3 text-muted" style="font-size: 0.7rem;">
                    <i class="fa-solid fa-lock"></i> Your complaint will be reviewed by the administrator.
                </div>
            </form>
        </div>
    </div>

    <!-- Info Box -->
    <div class="info-box-blue">
        <i class="fa-solid fa-circle-info text-primary fa-2x mt-1"></i>
        <div>
            <div class="ib-title">What happens next?</div>
            <div class="ib-text">We will review your complaint and respond as soon as possible.<br>You can check the status in your complaint history below.</div>
        </div>
    </div>

    <!-- Complaint History Card -->
    <div class="history-card">
        <div class="hc-header">
            <div class="hc-title"><i class="fa-solid fa-clock-rotate-left"></i> Complaint History</div>
            <span class="badge bg-light text-dark"><?= count($complaints) ?></span>
        </div>
        
        <div class="complaint-tabs">
            <div class="ctab active" data-filter="all" onclick="filterComplaints('all', this)">All</div>
            <div class="ctab" data-filter="pending" onclick="filterComplaints('pending', this)">Pending</div>
            <div class="ctab" data-filter="in_progress" onclick="filterComplaints('in_progress', this)">In Progress</div>
            <div class="ctab" data-filter="resolved" onclick="filterComplaints('resolved', this)">Resolved</div>
        </div>

        <div id="complaintsList">
            <?php if (empty($complaints)): ?>
                <div class="text-center py-5 text-muted">No complaints found.</div>
            <?php endif; ?>

            <?php foreach ($complaints as $c):
                $st = complaintStatus($c);
                [$iconClass, $badgeClass, $icon] = match ($st['key']) {
                    'resolved'    => ['icon-resolved', 'badge-resolved', 'fa-circle-check'],
                    'in_progress' => ['icon-progress', 'badge-progress', 'fa-screwdriver-wrench'],
                    default       => ['icon-open', 'badge-open', 'fa-clock'],
                };
                $photo = uploadSrc($c['photo_path'] ?? '', '../');
            ?>
            <div class="complaint-item cmp-row" data-status="<?= $st['key'] ?>">
                <div class="ci-icon-box <?= $iconClass ?>"><i class="fa-solid <?= $icon ?>"></i></div>

                <div class="ci-content">
                    <div class="ci-meta">
                        <?= complaintCode($c) ?> &bull; <?= date('M j, Y g:i A', strtotime($c['created_at'])) ?> &bull; <?= htmlspecialchars($c['category'] ?: 'Others') ?>
                        <span class="ci-status-badge <?= $badgeClass ?> d-md-none ms-1"><?= $st['label'] ?></span>
                    </div>
                    <div class="ci-subject"><?= htmlspecialchars($c['subject'] ?? '') ?></div>
                    <div class="ci-message"><?= nl2br(htmlspecialchars($c['message'] ?? '')) ?></div>
                    <?php if ($photo): ?>
                        <a href="<?= htmlspecialchars($photo) ?>" target="_blank" rel="noopener" class="ci-photo-link"><i class="fa-regular fa-image me-1"></i>View photo</a>
                    <?php endif; ?>
                    <?php if (trim((string)($c['admin_response'] ?? '')) !== ''): ?>
                        <div class="ci-reply">
                            <div class="ci-reply-title"><i class="fa-solid fa-reply me-1"></i>Reply from the admin<?= !empty($c['updated_at']) ? ' · ' . date('M j, g:i A', strtotime($c['updated_at'])) : '' ?></div>
                            <div><?= nl2br(htmlspecialchars($c['admin_response'])) ?></div>
                        </div>
                    <?php else: ?>
                        <div class="ci-waiting"><i class="fa-regular fa-hourglass-half me-1"></i>Waiting for the admin's reply</div>
                    <?php endif; ?>
                </div>

                <div class="ci-status-badge <?= $badgeClass ?> d-none d-md-block"><?= $st['label'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
// Character Counters
const inpSubject = document.getElementById('inpSubject');
const countSubject = document.getElementById('countSubject');
inpSubject.addEventListener('input', () => {
    countSubject.textContent = `${inpSubject.value.length}/100`;
});

const inpMessage = document.getElementById('inpMessage');
const countMessage = document.getElementById('countMessage');
inpMessage.addEventListener('input', () => {
    countMessage.textContent = `${inpMessage.value.length}/500`;
});

// Show the chosen photo's name.
document.getElementById('complaintPhoto').addEventListener('change', function () {
    if (this.files.length) document.getElementById('photoLabel').innerHTML = '<i class="fa-regular fa-image text-primary me-1"></i>' + this.files[0].name.replace(/[<>&"]/g, '');
});

// Tab Filtering
function filterComplaints(status, el) {
    document.querySelectorAll('.ctab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');

    const rows = document.querySelectorAll('.cmp-row');
    rows.forEach(r => {
        if (status === 'all' || r.getAttribute('data-status') === status) {
            r.style.display = 'flex';
        } else {
            r.style.display = 'none';
        }
    });
}
</script>

<?php require_once 'footer.php'; ?>
