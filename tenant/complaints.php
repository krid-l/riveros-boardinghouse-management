<?php
require_once 'header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject'])) {
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    $category = $_POST['category'] ?? 'Others';
    if ($category == 'Select a category') $category = 'Others';
    
    $stmt = $pdo->prepare("INSERT INTO complaints (tenant_id, subject, message, category) VALUES (?, ?, ?, ?)");
    $stmt->execute([$currentTenant['id'], $subject, $message, $category]);
    $success = "Complaint submitted successfully.";
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
.btn-view-all { background: #475569; color: #ffffff; border: none; border-radius: 6px; padding: 0.4rem 0.8rem; font-size: 0.75rem; font-weight: 600; }
.btn-view-all:hover { background: #64748b; color: white; }

/* Tabs */
.complaint-tabs { display: flex; border-bottom: 1px solid #e2e8f0; overflow-x: auto; scrollbar-width: none; }
.complaint-tabs::-webkit-scrollbar { display: none; }
.ctab { padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 700; cursor: pointer; border-bottom: 2px solid transparent; white-space: nowrap; color: #64748b; }
.ctab[data-filter="all"].active { color: #eab308; border-bottom-color: #eab308; }
.ctab[data-filter="open"].active { color: #3b82f6; border-bottom-color: #3b82f6; }
.ctab[data-filter="in_progress"].active { color: #f97316; border-bottom-color: #f97316; }
.ctab[data-filter="resolved"].active { color: #22c55e; border-bottom-color: #22c55e; }
.ctab[data-filter="closed"].active { color: #64748b; border-bottom-color: #64748b; }

/* History Items */
.complaint-item { border-bottom: 1px solid #f1f5f9; padding: 1.25rem; display: flex; align-items: center; gap: 1.25rem; transition: background 0.2s; }
.complaint-item:last-child { border-bottom: none; }
.complaint-item:hover { background: #f8fafc; }

.ci-icon-box { width: 56px; height: 56px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0; }
.icon-open { background: #eff6ff; color: #3b82f6; }
.icon-progress { background: #fff7ed; color: #f97316; }
.icon-resolved { background: #f0fdf4; color: #22c55e; }
.icon-closed { background: #f1f5f9; color: #64748b; }

.ci-content { flex: 1; }
.ci-meta { font-size: 0.7rem; color: #64748b; margin-bottom: 0.25rem; font-weight: 500; }
.ci-subject { font-size: 0.95rem; font-weight: 700; color: #1e293b; margin-bottom: 0.25rem; line-height: 1.3; }
.ci-preview { font-size: 0.8rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 400px; }

.ci-status-badge { font-size: 0.7rem; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 6px; white-space: nowrap; }
.badge-open { background: #eff6ff; color: #2563eb; }
.badge-progress { background: #fff7ed; color: #ea580c; }
.badge-resolved { background: #f0fdf4; color: #16a34a; }
.badge-closed { background: #f1f5f9; color: #475569; }

@media (max-width: 768px) {
    .complaint-item { flex-wrap: wrap; position: relative; padding-right: 2.5rem; }
    .ci-status-badge { position: absolute; top: 1.25rem; right: 1.25rem; font-size: 0.6rem; padding: 0.2rem 0.5rem; }
    .ci-chevron { position: absolute; right: 1.25rem; top: 50%; transform: translateY(-50%); }
}
</style>

<div class="complaints-container">
    
    <div class="mb-4">
        <h3 class="fw-bolder mb-1 text-dark">My Complaints & Concerns</h3>
        <p class="text-muted" style="font-size: 0.85rem;">We're here to help. Submit your concern and we'll take care of it.</p>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success d-flex align-items-center border-0 shadow-sm rounded-3 py-2 mb-4" style="font-size: 0.85rem;"><i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Form Card -->
    <div class="complaint-form-card">
        <div class="cf-header">
            <i class="fa-regular fa-pen-to-square"></i> Submit New Complaint
        </div>
        <div class="cf-body">
            <form method="POST">
                <!-- Subject -->
                <div class="mb-3">
                    <label class="form-label-custom">
                        <span>Subject <span class="required-ast">*</span></span>
                    </label>
                    <input type="text" name="subject" id="inpSubject" class="form-control-custom w-100" placeholder="e.g. Water not running, Noise complaint, etc." maxlength="100" required>
                    <div class="char-count" id="countSubject">0/100</div>
                </div>

                <!-- Category -->
                <div class="mb-3">
                    <label class="form-label-custom">Category</label>
                    <select name="category" class="form-control-custom w-100">
                        <option>Select a category</option>
                        <option>Maintenance & Repair</option>
                        <option>Noise Complaint</option>
                        <option>Cleanliness</option>
                        <option>Security</option>
                        <option>Other</option>
                    </select>
                </div>

                <!-- Message -->
                <div class="mb-3">
                    <label class="form-label-custom">
                        <span>Message / Details <span class="required-ast">*</span></span>
                    </label>
                    <textarea name="message" id="inpMessage" class="form-control-custom w-100" rows="4" placeholder="Please provide more details about your concern..." maxlength="500" required></textarea>
                    <div class="char-count" id="countMessage">0/500</div>
                </div>

                <!-- Attach Photos -->
                <div class="mb-3">
                    <label class="form-label-custom" style="margin-bottom: 0.5rem;">Attach Photos (Optional)</label>
                    <div class="file-drop-area">
                        <input type="file" name="photo" class="file-drop-input" accept="image/png, image/jpeg" multiple>
                        <div class="fw-bold text-dark" style="font-size: 0.85rem;">
                            <i class="fa-solid fa-cloud-arrow-up text-primary me-1"></i> <span class="text-primary">Choose files</span> or drag & drop
                        </div>
                        <div class="text-muted mt-1" style="font-size: 0.75rem;">JPG, PNG up to 5MB each</div>
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
            <button class="btn-view-all">View All</button>
        </div>
        
        <div class="complaint-tabs">
            <div class="ctab active" data-filter="all" onclick="filterComplaints('all', this)">All</div>
            <div class="ctab" data-filter="pending" onclick="filterComplaints('pending', this)">Pending</div>
            <div class="ctab" data-filter="in_progress" onclick="filterComplaints('in_progress', this)">In Progress</div>
            <div class="ctab" data-filter="resolved" onclick="filterComplaints('resolved', this)">Resolved</div>
            <div class="ctab" data-filter="closed" onclick="filterComplaints('closed', this)">Closed</div>
        </div>

        <div id="complaintsList">
            <?php if (empty($complaints)): ?>
                <div class="text-center py-5 text-muted">No complaints found.</div>
            <?php endif; ?>

            <?php foreach ($complaints as $c): 
                $statusRaw = strtolower($c['status'] ?? 'open');
                if ($statusRaw === 'pending') $statusRaw = 'open';

                $iconClass = '';
                $iconTag = '';
                $badgeClass = '';
                $badgeLabel = '';

                switch ($statusRaw) {
                    case 'in_progress':
                        $iconClass = 'icon-progress'; $iconTag = '<i class="fa-solid fa-volume-high"></i>';
                        $badgeClass = 'badge-progress'; $badgeLabel = 'In Progress';
                        break;
                    case 'resolved':
                        $iconClass = 'icon-resolved'; $iconTag = '<i class="fa-regular fa-lightbulb"></i>';
                        $badgeClass = 'badge-resolved'; $badgeLabel = 'Resolved';
                        break;
                    case 'closed':
                        $iconClass = 'icon-closed'; $iconTag = '<i class="fa-regular fa-trash-can"></i>';
                        $badgeClass = 'badge-closed'; $badgeLabel = 'Closed';
                        break;
                    default: // pending
                        $iconClass = 'icon-open'; $iconTag = '<i class="fa-solid fa-faucet-drip"></i>';
                        $badgeClass = 'badge-open'; $badgeLabel = 'Pending';
                        break;
                }
            ?>
            <div class="complaint-item cmp-row" data-status="<?= $statusRaw ?>">
                <div class="ci-icon-box <?= $iconClass ?>">
                    <?= $iconTag ?>
                </div>
                
                <div class="ci-content">
                    <div class="ci-meta">
                        <?= date('M d, Y', strtotime($c['created_at'])) ?> &bull; <?= date('h:i A', strtotime($c['created_at'])) ?>
                    </div>
                    <div class="ci-subject"><?= htmlspecialchars($c['subject']) ?></div>
                    <div class="ci-preview"><?= htmlspecialchars($c['message']) ?></div>
                </div>

                <div class="ci-status-badge <?= $badgeClass ?> d-none d-md-block">
                    <?= $badgeLabel ?>
                </div>
                
                <i class="fa-solid fa-chevron-right text-muted ci-chevron ms-3 d-none d-md-block"></i>
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
