<?php
// The Payments page's old "Export Data" link: now the Payment Log report, all time, as CSV.
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
header('Location: export_report.php?type=payments&format=csv');
exit;
