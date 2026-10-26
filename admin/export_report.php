<?php
// admin/export_report.php
//
// Downloads one report (includes/reports.php) as CSV or PDF:
//   export_report.php?type=financial&from=2026-10-01&to=2026-10-31&format=pdf
// Every export is recorded for the Exported Reports tab; downloading one again from there
// (&again=1) doesn't add another entry.

require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/reports.php';

$type = array_key_exists($_GET['type'] ?? '', REPORT_TYPES) ? $_GET['type'] : 'financial';
$format = ($_GET['format'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';
$usesRange = REPORT_TYPES[$type][1];

// No dates given: all time, from the first payment (or complaint) ever to today.
$firstDay = $type === 'complaints'
    ? $pdo->query("SELECT MIN(created_at) FROM complaints")->fetchColumn()
    : $pdo->query("SELECT MIN(payment_date) FROM payments")->fetchColumn();
$firstDay = $firstDay ? date('Y-m-d', strtotime($firstDay)) : date('Y-m-01');
$from = reportDate($_GET['from'] ?? null, $firstDay);
$to = reportDate($_GET['to'] ?? null, date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$report = buildReport($pdo, $type, $from, $to);
$business = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('boarding_house_name', 'address', 'contact_number')")->fetchAll(PDO::FETCH_KEY_PAIR);
$houseName = trim((string)($business['boarding_house_name'] ?? '')) ?: 'Riveros Boarding House';
// "Address · Contact number", whichever of the two are filled in under Settings.
$houseDetails = implode(' · ', array_filter([trim(preg_replace('/\s+/', ' ', (string)($business['address'] ?? ''))), trim((string)($business['contact_number'] ?? ''))]));

if (empty($_GET['again'])) {
    $pdo->prepare("INSERT INTO report_exports (report_type, format, date_from, date_to, row_count, created_at) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$type, $format, $usesRange ? $from : null, $usesRange ? $to : null, count($report['rows']), date('Y-m-d H:i:s')]);
}

$filename = reportFileName($report, $from, $to, $format);
if ($format === 'pdf') {
    outputReportPdf($report, $filename, $houseName, $houseDetails);
} else {
    outputReportCsv($report, $filename, $houseName);
}
