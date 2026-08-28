<?php
// includes/auth.php
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isTenant() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'tenant';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        die("Access Denied: Admin privileges required.");
    }
}

function requireTenant() {
    requireLogin();
    if (!isTenant()) {
        die("Access Denied: Tenant privileges required.");
    }
}
?>
