<?php
// index.php

// Must come first: everything below needs PHP 8, and an older PHP cannot even
// parse those files, so it would die before reaching any message of ours.
require_once __DIR__ . '/includes/php_version_check.php';

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
