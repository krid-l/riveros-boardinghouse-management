<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/notifications.php';
require_once '../includes/pagination.php';

// Opening a notice marks it read and goes to the page it is about.
if (isset($_GET['open'])) {
    $id = (int)$_GET['open'];
    $stmt = $pdo->prepare("SELECT link FROM notifications WHERE id = ?");
    $stmt->execute([$id]);
    $link = $stmt->fetchColumn();
    $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ?")->execute([$id]);
    header('Location: ' . safeNotificationLink($link === false ? '' : $link));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        $pdo->exec("UPDATE notifications SET is_read = TRUE WHERE is_read = FALSE");
    } elseif ($action === 'mark_read') {
        $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($action === 'mark_unread') {
        $pdo->prepare("UPDATE notifications SET is_read = FALSE WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    }
    // Back to where the admin was (the bell works from every page).
    $back = $_POST['back'] ?? 'notifications.php';
    header('Location: ' . safeNotificationLink($back));
    exit;
}

$filter = queryParam('show', 'all') === 'unread' ? 'unread' : 'all';
$where = $filter === 'unread' ? 'WHERE is_read = FALSE' : '';
$total = (int)$pdo->query("SELECT COUNT(*) FROM notifications $where")->fetchColumn();
$pager = paginate($total, 20, 'page', ['show' => $filter]);
$rows = $pdo->query("SELECT * FROM notifications $where ORDER BY created_at DESC, id DESC" . paginationLimitSql($pager))->fetchAll();
$unread = unreadNotificationCount($pdo);

require_once 'header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <p class="text-muted mb-0" style="font-size: 0.8rem;">New tenants, payments, complaints, room changes and rent coming due.</p>
    <div class="d-flex gap-2 align-items-center">
        <div class="btn-group btn-group-sm" role="group" aria-label="Show">
            <a href="?show=all" class="btn <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">All</a>
            <a href="?show=unread" class="btn <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline-primary' ?>">Unread<?= $unread ? " ($unread)" : '' ?></a>
        </div>
        <?php if ($unread): ?>
        <form method="POST" class="m-0">
            <input type="hidden" name="action" value="mark_all_read">
            <input type="hidden" name="back" value="notifications.php?show=<?= $filter ?>">
            <button type="submit" class="btn btn-sm btn-light border"><i class="fa-solid fa-check-double me-1"></i>Mark all as read</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <?php if (!$rows): ?>
        <div class="text-center text-muted py-5"><i class="fa-regular fa-bell-slash fa-2x d-block mb-2 opacity-50"></i><?= $filter === 'unread' ? 'No unread notifications.' : 'No notifications yet.' ?></div>
    <?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($rows as $n): [$icon, $color] = notificationIcon($n['type']); $isUnread = !$n['is_read']; ?>
        <li class="list-group-item d-flex align-items-start gap-3 py-3 <?= $isUnread ? 'bg-primary bg-opacity-10' : '' ?>">
            <div class="rounded-circle bg-<?= $color ?> bg-opacity-10 text-<?= $color ?> d-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px;">
                <i class="fa-solid <?= $icon ?>"></i>
            </div>
            <a href="?open=<?= (int)$n['id'] ?>" class="flex-grow-1 text-decoration-none text-dark" style="min-width: 0;">
                <div class="<?= $isUnread ? 'fw-bold' : 'fw-semibold' ?>" style="font-size: 0.85rem;"><?= htmlspecialchars($n['title']) ?></div>
                <?php if ($n['message']): ?><div class="text-muted" style="font-size: 0.78rem;"><?= htmlspecialchars($n['message']) ?></div><?php endif; ?>
                <div class="text-muted mt-1" style="font-size: 0.7rem;"><i class="fa-regular fa-clock me-1"></i><?= date('M j, Y g:i A', strtotime($n['created_at'])) ?> · <?= timeAgo($n['created_at']) ?></div>
            </a>
            <form method="POST" class="m-0 flex-shrink-0">
                <input type="hidden" name="action" value="<?= $isUnread ? 'mark_read' : 'mark_unread' ?>">
                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                <input type="hidden" name="back" value="notifications.php?show=<?= $filter ?>&page=<?= $pager['page'] ?>">
                <button type="submit" class="btn btn-sm btn-link text-decoration-none p-0" style="font-size: 0.72rem;"><?= $isUnread ? 'Mark as read' : 'Mark as unread' ?></button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-muted"><?= paginationSummary($pager, 'notifications') ?></small>
        <ul class="pagination pagination-sm mb-0"><?= paginationControls($pager) ?></ul>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
