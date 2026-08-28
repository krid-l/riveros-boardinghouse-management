<?php
// index.php
require_once 'includes/auth.php';

if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin/dashboard.php');
    } else {
        header('Location: tenant/dashboard.php');
    }
} else {
    header('Location: login.php');
}
exit;
?>
