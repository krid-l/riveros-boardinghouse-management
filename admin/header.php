<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/avatar.php';
require_once '../includes/notifications.php';
$current_page = basename($_SERVER['PHP_SELF']);
notifyUpcomingRentDue($pdo);
$bellUnread = unreadNotificationCount($pdo);
$bellItems = recentNotifications($pdo, 8);
$bellBack = $current_page . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
// Shown in the top bar (the one page title) and the browser tab; named like the sidebar links.
$pageTitles = [
    'dashboard.php'      => 'Dashboard',
    'rooms.php'          => 'Rooms',
    'tenants.php'        => 'Tenants',
    'tenant_details.php' => 'Tenant Details',
    'payments.php'       => 'Payments',
    'complaints.php'     => 'Complaints',
    'reports.php'        => 'Reports & Analytics',
    'announcements.php'  => 'Announcements',
    'settings.php'       => 'Settings',
    'notifications.php'  => 'Notifications',
];
$pageTitle = $pageTitles[$current_page] ?? ucwords(str_replace(['.php', '_'], ['', ' '], $current_page));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Admin - Riveros Boarding House</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome for Professional Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Theme CSS (?v= changes whenever the file does, so browsers never keep an old copy) -->
    <link rel="stylesheet" href="../assets/css/custom.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/custom.css') ?>">
</head>
<body>
    <div class="d-flex h-100">
        <!-- Sidebar -->
        <div class="offcanvas-md offcanvas-start sidebar-bg text-white d-flex flex-column" tabindex="-1" id="sidebarMenu" style="width: 260px;">
            <div class="offcanvas-header border-bottom border-secondary pt-4 pb-4">
                <h5 class="offcanvas-title fw-bold text-uppercase tracking-wide d-flex align-items-center">
                    <div class="bg-primary rounded p-2 me-3 d-inline-flex justify-content-center align-items-center" style="width: 35px; height: 35px;">
                        <i class="fa-solid fa-building text-white"></i>
                    </div>
                    Riveros BH
                </h5>
                <button type="button" class="btn-close btn-close-white d-md-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column flex-grow-1 p-3">
                <p class="text-uppercase text-muted" style="font-size: 0.7rem; font-weight: 700; letter-spacing: 1px; margin-bottom: 0.5rem; padding-left: 1rem;">Management</p>
                <ul class="nav nav-pills flex-column mb-auto w-100">
                    <li><a href="dashboard.php" class="sidebar-link text-decoration-none <?= $current_page == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
                    <li><a href="rooms.php" class="sidebar-link text-decoration-none <?= $current_page == 'rooms.php' ? 'active' : '' ?>"><i class="fa-solid fa-door-open"></i> Rooms</a></li>
                    <li><a href="tenants.php" class="sidebar-link text-decoration-none <?= $current_page == 'tenants.php' ? 'active' : '' ?>"><i class="fa-solid fa-users"></i> Tenants</a></li>
                    <li><a href="payments.php" class="sidebar-link text-decoration-none <?= $current_page == 'payments.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice-dollar"></i> Payments</a></li>
                    <li><a href="complaints.php" class="sidebar-link text-decoration-none <?= $current_page == 'complaints.php' ? 'active' : '' ?>"><i class="fa-solid fa-clipboard-list"></i> Complaints</a></li>
                    <li><a href="reports.php" class="sidebar-link text-decoration-none <?= $current_page == 'reports.php' ? 'active' : '' ?>"><i class="fa-solid fa-chart-line"></i> Reports & Analytics</a></li>
                </ul>
                
                <p class="text-uppercase text-muted mt-4" style="font-size: 0.7rem; font-weight: 700; letter-spacing: 1px; margin-bottom: 0.5rem; padding-left: 1rem;">System</p>
                <ul class="nav nav-pills flex-column w-100">
                    <li><a href="announcements.php" class="sidebar-link text-decoration-none <?= $current_page == 'announcements.php' ? 'active' : '' ?>"><i class="fa-solid fa-bullhorn"></i> Announcements</a></li>
                    <li><a href="settings.php" class="sidebar-link text-decoration-none <?= $current_page == 'settings.php' ? 'active' : '' ?>"><i class="fa-solid fa-gear"></i> Settings</a></li>
                </ul>

                <div class="mt-auto bg-dark p-3 rounded-3 mb-2 d-flex align-items-center">
                    <?= avatarHtml('Admin', 40, 'me-3') ?>
                    <div>
                        <h6 class="mb-0 fw-bold fs-6">Administrator</h6>
                        <small class="text-muted">Master Access</small>
                    </div>
                </div>
                <a href="../logout.php" class="sidebar-link text-decoration-none text-danger fw-bold"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="flex-grow-1 d-flex flex-column h-100 overflow-hidden">
            <!-- Topbar -->
            <div class="topbar p-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center" style="min-width: 0;">
                    <button class="btn btn-light d-md-none me-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <!-- The page's only title: the pages themselves don't repeat it. -->
                    <h5 class="m-0 fw-bold text-dark text-truncate page-title"><?= htmlspecialchars($pageTitle) ?></h5>
                </div>
                <div class="d-flex align-items-center">
                    <div class="dropdown me-2 me-md-3">
                        <button class="btn btn-light rounded-circle position-relative flex-shrink-0" style="width:40px; height:40px;" type="button"
                                data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications" id="notifBell">
                            <i class="fa-solid fa-bell <?= $bellUnread ? 'text-primary' : 'text-muted' ?>"></i>
                            <?php if ($bellUnread): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-badge">
                                <?= $bellUnread > 9 ? '9+' : $bellUnread ?><span class="visually-hidden"> unread notifications</span>
                            </span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 p-0 notif-panel" aria-labelledby="notifBell">
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                                <span class="fw-bold" style="font-size: 0.85rem;">Notifications<?= $bellUnread ? " <span class=\"badge bg-danger-subtle text-danger ms-1\">$bellUnread new</span>" : '' ?></span>
                                <?php if ($bellUnread): ?>
                                <form method="POST" action="notifications.php" class="m-0">
                                    <input type="hidden" name="action" value="mark_all_read">
                                    <input type="hidden" name="back" value="<?= htmlspecialchars($bellBack) ?>">
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none" style="font-size: 0.75rem;">Mark all as read</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <div class="notif-list">
                                <?php if (!$bellItems): ?>
                                    <div class="text-center text-muted py-4" style="font-size: 0.8rem;"><i class="fa-regular fa-bell-slash d-block mb-2 fs-5 opacity-50"></i>No notifications yet.</div>
                                <?php endif; ?>
                                <?php foreach ($bellItems as $n): [$nIcon, $nColor] = notificationIcon($n['type']); ?>
                                <a href="notifications.php?open=<?= (int)$n['id'] ?>" class="notif-item d-flex gap-2 px-3 py-2 text-decoration-none <?= $n['is_read'] ? '' : 'unread' ?>">
                                    <span class="notif-icon bg-<?= $nColor ?> bg-opacity-10 text-<?= $nColor ?>"><i class="fa-solid <?= $nIcon ?>"></i></span>
                                    <span class="flex-grow-1" style="min-width: 0;">
                                        <span class="d-block notif-title"><?= htmlspecialchars($n['title']) ?></span>
                                        <?php if ($n['message']): ?><span class="d-block notif-msg text-truncate"><?= htmlspecialchars($n['message']) ?></span><?php endif; ?>
                                        <span class="d-block notif-time"><?= timeAgo($n['created_at']) ?></span>
                                    </span>
                                    <?php if (!$n['is_read']): ?><span class="notif-dot flex-shrink-0" title="Unread"></span><?php endif; ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <a href="notifications.php" class="d-block text-center py-2 border-top text-decoration-none fw-semibold" style="font-size: 0.8rem;">View all notifications</a>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle border-0 fw-semibold text-dark" type="button" data-bs-toggle="dropdown" aria-label="Manage account">
                            <i class="fa-solid fa-user-gear d-md-none"></i><span class="d-none d-md-inline">Manage Account</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                            <li><a class="dropdown-item py-2" href="settings.php"><i class="fa-solid fa-user me-2 text-muted"></i> Profile Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Scrollable Content -->
            <?php if ($current_page === 'rooms.php'): ?>
            <div class="flex-grow-1 d-flex overflow-hidden">
            <?php else: ?>
            <div class="p-4 flex-grow-1 overflow-auto container-fluid pb-5">
            <?php endif; ?>
