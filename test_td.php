<?php
session_start();
$_SESSION['user_id'] = 1; // Assuming admin
$_SESSION['role'] = 'admin';
$_GET['id'] = 2; // Pass a tenant ID
require 'admin/tenant_details.php';
?>
