<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=payments_export_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['Payment ID', 'Tenant Name', 'Room', 'Amount', 'Date', 'Status', 'Reference Number']);

$stmt = $pdo->query("
    SELECT p.id, t.first_name, t.last_name, r.room_number, p.amount, p.payment_date, p.status, p.reference_number
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    ORDER BY p.payment_date DESC
");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['first_name'] . ' ' . $row['last_name'],
        $row['room_number'] ?? 'N/A',
        $row['amount'],
        $row['payment_date'],
        $row['status'],
        $row['reference_number'] ?? 'N/A'
    ]);
}
fclose($output);
?>
