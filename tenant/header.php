<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireTenant();

// Fetch current tenant info
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentTenant = $stmt->fetch();
if (!$currentTenant) {
    die("Tenant profile not found!");
}
$_SESSION['tenant_id'] = $currentTenant['id'];
require_once '../includes/auto_biller.php';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Portal - Riveros Boarding House</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome for Professional Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Theme CSS -->
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body>
    <div class="d-flex h-100">
        <!-- Sidebar -->
        <div class="offcanvas-md offcanvas-start sidebar-bg text-white d-flex flex-column" tabindex="-1" id="sidebarMenu" style="width: 260px;">
            <div class="offcanvas-header border-bottom border-secondary pt-4 pb-4">
                <h5 class="offcanvas-title fw-bold text-uppercase tracking-wide d-flex align-items-center">
                    <div class="bg-success rounded p-2 me-3 d-inline-flex justify-content-center align-items-center" style="width: 35px; height: 35px;">
                        <i class="fa-solid fa-house-user text-white"></i>
                    </div>
                    Tenant Portal
                </h5>
                <button type="button" class="btn-close btn-close-white d-md-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column flex-grow-1 p-3">
                <p class="text-uppercase text-muted" style="font-size: 0.7rem; font-weight: 700; letter-spacing: 1px; margin-bottom: 0.5rem; padding-left: 1rem;">Main Menu</p>
                <ul class="nav nav-pills flex-column mb-auto w-100">
                    <li><a href="dashboard.php" class="sidebar-link text-decoration-none <?= $current_page == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i> My Space</a></li>
                    <li><a href="payments.php" class="sidebar-link text-decoration-none <?= $current_page == 'payments.php' ? 'active' : '' ?>"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Payment</a></li>
                    <li><a href="receipts.php" class="sidebar-link text-decoration-none <?= $current_page == 'receipts.php' ? 'active' : '' ?>"><i class="fa-solid fa-file-invoice"></i> Digital Receipts</a></li>
                    <li><a href="complaints.php" class="sidebar-link text-decoration-none <?= $current_page == 'complaints.php' ? 'active' : '' ?>"><i class="fa-solid fa-headset"></i> Support / Complaints</a></li>
                </ul>
                
                <p class="text-uppercase text-muted mt-4" style="font-size: 0.7rem; font-weight: 700; letter-spacing: 1px; margin-bottom: 0.5rem; padding-left: 1rem;">Preferences</p>
                <ul class="nav nav-pills flex-column w-100">
                    <li><a href="settings.php" class="sidebar-link text-decoration-none <?= $current_page == 'settings.php' ? 'active' : '' ?>"><i class="fa-solid fa-user-pen"></i> Profile Settings</a></li>
                </ul>

                <div class="mt-auto bg-dark p-3 rounded-3 mb-2 d-flex align-items-center">
                    <?php
                    $hFullName = htmlspecialchars($currentTenant['first_name'] . ' ' . $currentTenant['last_name']);
                    $hAvatar = !empty($currentTenant['profile_picture']) ? '../' . htmlspecialchars($currentTenant['profile_picture']) : 'https://ui-avatars.com/api/?name=' . urlencode($hFullName) . '&background=10b981&color=fff';
                    ?>
                    <img src="<?= $hAvatar ?>" class="rounded-circle me-3" width="40" height="40" style="object-fit: cover;" alt="Tenant">
                    <div>
                        <h6 class="mb-0 fw-bold fs-6 text-truncate" style="max-width: 130px;"><?= htmlspecialchars($currentTenant['first_name']) ?></h6>
                        <small class="text-muted">Tenant</small>
                    </div>
                </div>
                <a href="../logout.php" class="sidebar-link text-decoration-none text-danger fw-bold"><i class="fa-solid fa-right-from-bracket"></i> Sign Out</a>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="flex-grow-1 d-flex flex-column h-100 overflow-hidden bg-light">
            <!-- Topbar -->
            <div class="topbar p-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="btn btn-light d-md-none me-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <h5 class="m-0 fw-bold text-dark d-none d-md-block"><?= ucfirst(str_replace('.php', '', $current_page)) ?></h5>
                </div>
                <div class="d-flex align-items-center">
                    <button class="btn btn-light rounded-circle position-relative me-3" style="width:40px; height:40px;">
                        <i class="fa-solid fa-bell text-muted"></i>
                    </button>
                    <div class="dropdown">
                        <button class="btn btn-light dropdown-toggle border-0 fw-semibold text-dark" type="button" data-bs-toggle="dropdown">
                            <?= htmlspecialchars($currentTenant['first_name']) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                            <li><a class="dropdown-item py-2" href="settings.php"><i class="fa-solid fa-user me-2 text-muted"></i> My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Scrollable Content -->
            <div class="p-4 flex-grow-1 overflow-auto container-fluid pb-5">
