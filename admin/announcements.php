<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $title = trim($_POST['title']);
        $message = trim($_POST['message']);
        if (!empty($title) && !empty($message)) {
            $stmt = $pdo->prepare("INSERT INTO announcements (title, message) VALUES (?, ?)");
            $stmt->execute([$title, $message]);
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->execute([$id]);
    }
    header("Location: announcements.php");
    exit;
}

$announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetchAll();
require_once 'header.php';
?>

<div class="d-flex justify-content-between align-items-end mb-4">
    <div>
        <h3 class="fw-bold mb-1 text-dark">Announcements</h3>
        <p class="text-muted mb-0">Manage announcements visible to all tenants.</p>
    </div>
    <button class="btn btn-primary fw-bold px-4" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fa-solid fa-plus me-2"></i> New Announcement
    </button>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-4">
        <?php if (empty($announcements)): ?>
            <div class="text-center text-muted py-4">No announcements found.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th>
                            <th>Message</th>
                            <th>Posted On</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($announcements as $a): ?>
                        <tr>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($a['title']) ?></td>
                            <td class="text-muted" style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($a['message']) ?></td>
                            <td class="text-muted" style="font-size:0.85rem;"><?= date('M j, Y h:i A', strtotime($a['created_at'])) ?></td>
                            <td class="text-end">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this announcement?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark">Post Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-semibold">Title</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-semibold">Message</label>
                        <textarea name="message" class="form-control" rows="4" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Post to Tenants</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>