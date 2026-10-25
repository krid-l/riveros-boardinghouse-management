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

// No dates given: from the first payment ever (all time) to today.
$firstDay = $pdo->query("SELECT MIN(payment_date) FROM payments")->fetchColumn() ?: date('Y-m-01');
$from = reportDate($_GET['from'] ?? null, $firstDay);
$to = reportDate($_GET['to'] ?? null, date('Y-m-d'));
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$report = buildReport($pdo, $type, $from, $to);
$houseName = (string)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'boarding_house_name'")->fetchColumn() ?: 'Riveros Boarding House');

if (empty($_GET['again'])) {
    $pdo->prepare("INSERT INTO report_exports (report_type, format, date_from, date_to, row_count, created_at) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$type, $format, $usesRange ? $from : null, $usesRange ? $to : null, count($report['rows']), date('Y-m-d H:i:s')]);
}

$filename = reportFileName($report, $from, $to, $format);
if ($format === 'pdf') {
    outputReportPdf($report, $filename, $houseName);
} else {
    outputReportCsv($report, $filename, $houseName);
}
