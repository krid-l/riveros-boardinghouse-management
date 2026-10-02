<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * The URL path the app is installed under.
 * "" when it is served from a domain root (the deployed site) and "/riveros-boardinghouse-management"
 * when it sits in a sub-folder of WAMP's www directory. Redirects are built on top of this so
 * they work in both places.
 */
function appBase(): string {
    static $base = null;
    if ($base === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        // admin/ and tenant/ pages live one folder below the app root.
        if (preg_match('#/(admin|tenant)$#', $dir)) {
            $dir = rtrim(str_replace('\\', '/', dirname($dir)), '/');
        }
        $base = ($dir === '' || $dir === '.' || $dir === '/') ? '' : $dir;
    }
    return $base;
}

/** Absolute URL path to a file in the app, e.g. appUrl('login.php'). */
function appUrl(string $relative): string {
    return appBase() . '/' . ltrim($relative, '/');
}

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
        header('Location: ' . appUrl('login.php'));
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
