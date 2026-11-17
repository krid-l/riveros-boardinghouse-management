<?php
// admin/export_report.php
//
// Downloads one report (includes/reports.php) as CSV or PDF, with the same filters as the
// report's tab on Reports & Analytics:
//   export_report.php?type=payments&format=pdf&period=custom&from=2026-10-01&to=2026-10-31&status=verified
// Every export is recorded (with its filters) for the Exported Reports tab; downloading one again
// from there (&again=1) doesn't add another entry.

require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/reports.php';

$type = array_key_exists($_GET['type'] ?? '', REPORT_TYPES) ? $_GET['type'] : 'financial';
$format = ($_GET['format'] ?? 'csv') === 'pdf' ? 'pdf' : 'csv';

$filters = normalizeReportFilters($type, fn($key) => isset($_GET[$key]) && is_scalar($_GET[$key]) ? (string)$_GET[$key] : null);
$report = buildReport($pdo, $type, $filters);

$business = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('boarding_house_name', 'address', 'contact_number')")->fetchAll(PDO::FETCH_KEY_PAIR);
$houseName = trim((string)($business['boarding_house_name'] ?? '')) ?: 'Riveros Boarding House';
// "Address · Contact number", whichever of the two are filled in under Settings.
$houseDetails = implode(' · ', array_filter([trim(preg_replace('/\s+/', ' ', (string)($business['address'] ?? ''))), trim((string)($business['contact_number'] ?? ''))]));

if (empty($_GET['again'])) {
    // Kept with fixed dates, so "Download again" gives the same period later: "This month"
    // becomes the dates it covered, and an "as of" date is always kept.
    $params = reportFilterParams($type, $filters);
    if (isset($params['period']) && $params['period'] !== 'custom') {
        $params = array_merge($params, array_filter(['period' => 'custom', 'from' => $filters['from'], 'to' => $filters['to']]));
    }
    if (!empty($filters['as_of'])) {
        $params['as_of'] = $filters['as_of'];
    }
    $pdo->prepare("INSERT INTO report_exports (report_type, format, date_from, date_to, row_count, filters, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$type, $format, $filters['from'], $filters['to'], count($report['rows']),
                   $params ? json_encode($params) : null, date('Y-m-d H:i:s')]);
}

$filename = reportFileName($report, $filters, $format);
if ($format === 'pdf') {
    outputReportPdf($report, $filename, $houseName, $houseDetails);
} else {
    outputReportCsv($report, $filename, $houseName);
}
