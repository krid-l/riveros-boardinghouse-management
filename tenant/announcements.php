<?php
require_once 'header.php';
require_once '../includes/pagination.php';

// Every announcement the admin has posted, newest first, a page at a time.
$totalAnnouncements = (int)$pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
$pager = paginate($totalAnnouncements, 10);
$announcements = $pdo->query("SELECT * FROM announcements
                              ORDER BY created_at DESC, id DESC" . paginationLimitSql($pager))->fetchAll();

// Posted after the tenant's previous visit (header.php has already marked them read).
$isNew = fn(array $a): bool => $announcementsSeenBefore === null
    || strtotime($a['created_at']) > strtotime($announcementsSeenBefore);
?>

<style>
.announcements-container { max-width: 800px; margin: 0 auto; padding-bottom: 3rem; }
.announcement-item { background: #ffffff; border-radius: 16px; border: 1px solid #f1f5f9; box-shadow: 0 4px 12px rgba(0,0,0,0.03); padding: 1.25rem 1.5rem; margin-bottom: 1rem; }
.announcement-item.is-new { border-left: 4px solid #eab308; background: #fffdf5; }
.announcement-icon { width: 42px; height: 42px; border-radius: 50%; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.announcement-message { font-size: 0.9rem; color: #334155; white-space: normal; overflow-wrap: anywhere; }
</style>

<div class="announcements-container pt-3">
    <div class="mb-4">
        <h3 class="fw-bolder mb-0 text-dark">Announcements</h3>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">News and reminders from the boarding house management.</p>
    </div>

    <?php if (empty($announcements)): ?>
        <div class="announcement-item text-center text-muted py-5">
            <i class="fa-solid fa-bullhorn fa-2x mb-3 d-block" style="color: #cbd5e1;"></i>
            No announcements yet.
        </div>
    <?php else: ?>
        <?php foreach ($announcements as $a): ?>
        <div class="announcement-item d-flex gap-3 <?= $isNew($a) ? 'is-new' : '' ?>">
            <div class="announcement-icon"><i class="fa-solid fa-bullhorn"></i></div>
            <div class="flex-grow-1" style="min-width: 0;">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <h6 class="fw-bold text-dark mb-1"><?= htmlspecialchars($a['title'] ?? '') ?></h6>
                    <?php if ($isNew($a)): ?>
                        <span class="badge badge-soft-warning">New</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted mb-2" style="font-size: 0.75rem;">
                    <i class="fa-regular fa-clock me-1"></i><?= date('M j, Y h:i A', strtotime($a['created_at'])) ?>
                </div>
                <div class="announcement-message"><?= nl2br(htmlspecialchars($a['message'] ?? '')) ?></div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
            <small class="text-muted"><?= paginationSummary($pager, 'announcements') ?></small>
            <ul class="pagination pagination-sm mb-0"><?= paginationControls($pager) ?></ul>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
