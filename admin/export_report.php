<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();

$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=financial_report_' . $startDate . '_to_' . $endDate . '.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['Financial Report from ' . $startDate . ' to ' . $endDate]);
fputcsv($output, []); // blank line
fputcsv($output, ['Payment ID', 'Date', 'Tenant Name', 'Room', 'Status', 'Reference', 'Amount']);

$stmt = $pdo->prepare("
    SELECT p.id, p.payment_date, t.first_name, t.last_name, r.room_number, p.status, p.reference_number, p.amount
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    WHERE p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date DESC
");
$stmt->execute([$startDate, $endDate]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        $row['id'],
        $row['payment_date'],
        $row['first_name'] . ' ' . $row['last_name'],
        $row['room_number'] ?? 'N/A',
        $row['status'],
        $row['reference_number'] ?? 'N/A',
        $row['amount']
    ]);
}
fclose($output);
?>
