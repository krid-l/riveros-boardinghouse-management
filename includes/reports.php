<?php
// includes/reports.php
//
// The reports the admin can export from Reports & Analytics, as CSV (opens in Excel) or PDF.
// buildReport() gathers one report's rows; outputReportCsv() / outputReportPdf() send it.

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/complaints.php';

const REPORT_TYPES = [
    // type        => [title,                          uses a date range, what it is]
    'financial'  => ['Financial Report',              true,  'Money received (verified payments) in the period.'],
    'payments'   => ['Payment Log',                   true,  'Every payment submitted in the period: verified, pending and rejected.'],
    'unpaid'     => ['Unpaid Balances',               false, 'Who owes rent today and how much of it is overdue.'],
    'tenants'    => ['Tenant List',                   false, 'All tenants with their contact details, room and balance.'],
    'occupancy'  => ['Room Occupancy',                false, 'Every room with its beds taken and free, and its rent.'],
    'complaints' => ['Complaints Report',             true,  'Complaints filed in the period and where they stand.'],
];

/** A date from the query string, or the default. */
function reportDate(?string $value, string $default): string {
    $d = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $d && $d->format('Y-m-d') === $value ? $value : $default;
}

/**
 * One report, ready to print.
 * @return array title, period, columns [[label, width, align]], rows (list of lists), totals [label => text]
 */
function buildReport(PDO $pdo, string $type, string $from, string $to): array {
    $money = fn($v) => number_format((float)$v, 2);
    $name = fn($r) => trim($r['first_name'] . ' ' . $r['last_name']);
    [$title, $usesRange] = REPORT_TYPES[$type];
    $period = $usesRange
        ? date('M j, Y', strtotime($from)) . ' to ' . date('M j, Y', strtotime($to))
        : 'As of ' . date('M j, Y');
    $report = ['type' => $type, 'title' => $title, 'period' => $period, 'columns' => [], 'rows' => [], 'totals' => []];

    if ($type === 'financial') {
        $report['columns'] = [['Date', 24, 'L'], ['Receipt No.', 26, 'L'], ['Tenant', 52, 'L'], ['Room', 20, 'L'],
                              ['Method', 34, 'L'], ['Reference No.', 44, 'L'], ['Amount (PHP)', 30, 'R']];
        $stmt = $pdo->prepare("SELECT p.*, t.first_name, t.last_name, r.room_number FROM payments p
                               JOIN tenants t ON t.id = p.tenant_id LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE " . revenueFilterSql('p') . " AND p.payment_date BETWEEN ? AND ?
                               ORDER BY p.payment_date, p.id");
        $stmt->execute([$from, $to]);
        $total = 0.0;
        $byMethod = [];
        foreach ($stmt->fetchAll() as $p) {
            $method = paymentMethodLabel($p['payment_method'] ?? 'gcash');
            $report['rows'][] = [$p['payment_date'], 'RCP-' . str_pad((string)$p['id'], 6, '0', STR_PAD_LEFT), $name($p),
                                 $p['room_number'] ?? '-', $method, reportReference($p['reference_number'] ?? ''), $money($p['amount'])];
            $total += (float)$p['amount'];
            $byMethod[$method] = ($byMethod[$method] ?? 0) + (float)$p['amount'];
        }
        $report['totals']['Payments'] = (string)count($report['rows']);
        foreach ($byMethod as $method => $sum) {
            $report['totals']["Received by $method"] = 'PHP ' . $money($sum);
        }
        $report['totals']['Total received'] = 'PHP ' . $money($total);

    } elseif ($type === 'payments') {
        $report['columns'] = [['Date', 24, 'L'], ['Tenant', 50, 'L'], ['Room', 20, 'L'], ['Method', 34, 'L'],
                              ['Reference No.', 44, 'L'], ['Status', 24, 'L'], ['Amount (PHP)', 30, 'R']];
        $stmt = $pdo->prepare("SELECT p.*, t.first_name, t.last_name, r.room_number FROM payments p
                               JOIN tenants t ON t.id = p.tenant_id LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE p.covered_by_payment_id IS NULL AND p.payment_date BETWEEN ? AND ?
                               ORDER BY p.payment_date, p.id");
        $stmt->execute([$from, $to]);
        $sum = ['verified' => [0, 0.0], 'pending' => [0, 0.0], 'rejected' => [0, 0.0]];
        foreach ($stmt->fetchAll() as $p) {
            $report['rows'][] = [$p['payment_date'], $name($p), $p['room_number'] ?? '-', paymentMethodLabel($p['payment_method'] ?? 'gcash'),
                                 reportReference($p['reference_number'] ?? ''), ucfirst($p['status']), $money($p['amount'])];
            if (isset($sum[$p['status']])) {
                $sum[$p['status']][0]++;
                $sum[$p['status']][1] += (float)$p['amount'];
            }
        }
        foreach ($sum as $status => [$n, $amount]) {
            $report['totals'][ucfirst($status)] = "$n · PHP " . $money($amount);
        }

    } elseif ($type === 'unpaid') {
        $report['columns'] = [['Tenant', 50, 'L'], ['Room', 20, 'L'], ['Mobile', 32, 'L'], ['Status', 26, 'L'],
                              ['Balance (PHP)', 30, 'R'], ['Overdue (PHP)', 30, 'R'], ['Overdue Since', 28, 'L']];
        $notYetDue = chargesNotYetDue($pdo);
        $tenants = $pdo->query("SELECT t.*, r.room_number FROM tenants t LEFT JOIN rooms r ON r.id = t.room_id
                                WHERE t.balance > 0 ORDER BY t.balance DESC")->fetchAll();
        $balance = 0.0;
        $overdue = 0.0;
        foreach ($tenants as $t) {
            $billing = tenantBillingStatus($t, $notYetDue);
            $since = $billing['overdue'] > 0 ? oldestUnpaidDueDate($pdo, (int)$t['id'], (float)$t['balance']) : null;
            $report['rows'][] = [$name($t), $t['room_number'] ?? '-', $t['contact_number'] ?? '', $t['status'] === 'active' ? $billing['label'] : 'Moved out',
                                 $money($billing['balance']), $money($billing['overdue']), $since ?? '-'];
            $balance += $billing['balance'];
            $overdue += $billing['overdue'];
        }
        $report['totals']['Tenants who owe'] = (string)count($tenants);
        $report['totals']['Total unpaid'] = 'PHP ' . $money($balance);
        $report['totals']['Of which overdue'] = 'PHP ' . $money($overdue);

    } elseif ($type === 'tenants') {
        $report['columns'] = [['Tenant', 46, 'L'], ['Mobile', 30, 'L'], ['Email', 50, 'L'], ['Room', 18, 'L'],
                              ['Move-in', 24, 'L'], ['Status', 22, 'L'], ['Rent (PHP)', 24, 'R'], ['Balance (PHP)', 26, 'R']];
        $tenants = $pdo->query("SELECT t.*, r.room_number, r.price_per_month, r.capacity FROM tenants t
                                LEFT JOIN rooms r ON r.id = t.room_id
                                ORDER BY (t.status = 'active') DESC, t.first_name, t.last_name")->fetchAll();
        $active = 0;
        foreach ($tenants as $t) {
            $isActive = $t['status'] === 'active';
            $active += $isActive ? 1 : 0;
            $rent = $t['room_id'] ? rentShare((float)$t['price_per_month'], (int)$t['capacity']) : 0;
            $report['rows'][] = [$name($t), $t['contact_number'] ?? '', $t['email'] ?? '', $t['room_number'] ?? '-',
                                 $t['move_in_date'] ?? '-', $isActive ? 'Active' : 'Moved out', $rent ? $money($rent) : '-', $money($t['balance'])];
        }
        $report['totals']['Active tenants'] = (string)$active;
        $report['totals']['Moved out'] = (string)(count($tenants) - $active);

    } elseif ($type === 'occupancy') {
        $report['columns'] = [['Room', 20, 'L'], ['Status', 26, 'L'], ['Beds', 14, 'R'], ['Taken', 14, 'R'], ['Free', 14, 'R'],
                              ['Room Price', 28, 'R'], ['Per Tenant', 26, 'R'], ['Tenants', 100, 'L']];
        $rooms = $pdo->query("SELECT r.* FROM rooms r")->fetchAll();
        usort($rooms, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));
        $who = $pdo->query("SELECT room_id, first_name, last_name FROM tenants WHERE status = 'active' AND room_id IS NOT NULL ORDER BY first_name")->fetchAll();
        $byRoom = [];
        foreach ($who as $w) {
            $byRoom[$w['room_id']][] = $name($w);
        }
        $beds = $taken = 0;
        foreach ($rooms as $r) {
            $names = $byRoom[$r['id']] ?? [];
            $n = count($names);
            $cap = (int)$r['capacity'];
            $status = $r['status'] === 'maintenance' ? 'Maintenance' : ($n >= $cap ? 'Full' : ($n > 0 ? 'Partial' : 'Available'));
            $report['rows'][] = ['Room ' . $r['room_number'], $status, (string)$cap, (string)$n, (string)max(0, $cap - $n),
                                 $money($r['price_per_month']), $money(rentShare((float)$r['price_per_month'], $cap)), implode(', ', $names) ?: '-'];
            $beds += $cap;
            $taken += $n;
        }
        $report['totals']['Rooms'] = (string)count($rooms);
        $report['totals']['Beds taken'] = "$taken of $beds";
        $report['totals']['Bed occupancy'] = ($beds ? round($taken / $beds * 100, 1) : 0) . '%';

    } elseif ($type === 'complaints') {
        $report['columns'] = [['No.', 26, 'L'], ['Filed', 24, 'L'], ['Tenant', 44, 'L'], ['Room', 18, 'L'], ['Category', 40, 'L'],
                              ['Priority', 20, 'L'], ['Status', 24, 'L'], ['Subject', 80, 'L']];
        $stmt = $pdo->prepare("SELECT c.*, t.first_name, t.last_name, r.room_number FROM complaints c
                               JOIN tenants t ON t.id = c.tenant_id LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE c.created_at >= ? AND c.created_at < ? ORDER BY c.created_at, c.id");
        $stmt->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
        $counts = [];
        foreach ($stmt->fetchAll() as $c) {
            $st = complaintStatus($c);
            $report['rows'][] = [complaintCode($c), date('Y-m-d', strtotime($c['created_at'])), $name($c), $c['room_number'] ?? '-',
                                 $c['category'] ?: 'Others', complaintPriority($c['priority'] ?? 'normal')[0], $st['label'], $c['subject']];
            $counts[$st['label']] = ($counts[$st['label']] ?? 0) + 1;
        }
        $report['totals']['Complaints'] = (string)count($report['rows']);
        foreach (['Pending', 'In Progress', 'Resolved'] as $label) {
            $report['totals'][$label] = (string)($counts[$label] ?? 0);
        }
    }
    return $report;
}

/** System references (cash and move-in codes) aren't meaningful outside the app. */
function reportReference(string $ref): string {
    if ($ref === '' || str_starts_with($ref, 'CASH-')) return '-';
    return $ref === 'MOVE-IN' ? 'Move-in' : $ref;
}

function reportFileName(array $report, string $from, string $to, string $ext): string {
    $usesRange = REPORT_TYPES[$report['type']][1];
    return strtolower(str_replace(' ', '_', $report['title'])) . '_' . ($usesRange ? "{$from}_to_{$to}" : date('Y-m-d')) . ".$ext";
}

/** CSV with a byte-order mark, so Excel shows names like "Peña" correctly. */
function outputReportCsv(array $report, string $filename, string $houseName): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$houseName . ' - ' . $report['title']]);
    fputcsv($out, [$report['period']]);
    fputcsv($out, ['Generated ' . date('M j, Y g:i A')]);
    fputcsv($out, []);
    fputcsv($out, array_column($report['columns'], 0));
    foreach ($report['rows'] as $row) {
        // Amount columns as plain numbers, so Excel can add them up.
        fputcsv($out, array_map(fn($v) => preg_match('/^-?[\d,]+\.\d{2}$/', (string)$v) ? str_replace(',', '', $v) : $v, $row));
    }
    fputcsv($out, []);
    foreach ($report['totals'] as $label => $value) {
        fputcsv($out, [$label, str_replace('·', '-', $value)]);
    }
    fclose($out);
}

/** A landscape A4 PDF: title, period, the table (header repeated on each page) and the totals. */
function outputReportPdf(array $report, string $filename, string $houseName, string $houseDetails = ''): void {
    if (!class_exists('FPDF')) {
        require_once __DIR__ . '/fpdf/fpdf.php';
    }
    $text = function ($s): string {
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT', str_replace('·', '-', (string)$s));
        return $converted === false ? '' : $converted;   // not "?: ''", which would blank out "0"
    };

    $pdf = new class('L', 'mm', 'A4') extends FPDF {
        public array $head = [];
        public string $footerText = '';
        function Footer() {
            $this->SetY(-12);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(120, 120, 120);
            $this->Cell(0, 6, $this->footerText . '   Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
        }
    };
    $pdf->AliasNbPages();
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->footerText = $text($houseName . ' - ' . $report['title'] . ' - generated ' . date('M j, Y g:i A'));
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 8, $text(strtoupper($houseName)), 0, 1);
    if ($houseDetails !== '') {
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 4, $text($houseDetails), 0, 1);
        $pdf->SetTextColor(15, 23, 42);
    }
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 7, $text($report['title']), 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 5, $text($report['period']), 0, 1);
    $pdf->Ln(3);

    // Stretch the column widths to the page width.
    $usable = $pdf->GetPageWidth() - 20;
    $sum = array_sum(array_column($report['columns'], 1));
    $widths = array_map(fn($c) => $c[1] * $usable / $sum, $report['columns']);

    $drawHead = function () use ($pdf, $report, $widths, $text) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(30, 41, 59);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($report['columns'] as $i => [$label, , $align]) {
            $pdf->Cell($widths[$i], 7, $text($label), 0, 0, $align, true);
        }
        $pdf->Ln();
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(30, 41, 59);
    };
    $drawHead();

    if (!$report['rows']) {
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($usable, 10, 'Nothing to show for this period.', 0, 1, 'C');
    }
    foreach ($report['rows'] as $n => $row) {
        if ($pdf->GetY() + 6 > $pdf->GetPageHeight() - 16) {
            $pdf->AddPage();
            $drawHead();
        }
        $pdf->SetFillColor(248, 250, 252);
        foreach ($row as $i => $value) {
            // Long text is cut to fit the column rather than spilling into the next one.
            $value = $text($value);
            while ($value !== '' && $pdf->GetStringWidth($value) > $widths[$i] - 2) {
                $value = substr($value, 0, -2) . '.';
            }
            $pdf->Cell($widths[$i], 6, $value, 'B', 0, $report['columns'][$i][2], $n % 2 === 1);
        }
        $pdf->Ln();
    }

    // Totals
    $pdf->Ln(4);
    if ($pdf->GetY() + 8 + 6 * count($report['totals']) > $pdf->GetPageHeight() - 16) {
        $pdf->AddPage();
    }
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 6, 'Summary', 0, 1);
    foreach ($report['totals'] as $label => $value) {
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(60, 6, $text($label), 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(60, 6, $text($value), 0, 1);
    }

    $pdf->Output('D', $filename);   // sends it as a download
}
