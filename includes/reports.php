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
    'charges'    => ['Charges Report',                true,  'Everything billed in the period: rent, deposits, appliances and damage.'],
    'unpaid'     => ['Unpaid Balances',               false, 'Who owes money today and how much of it is overdue.'],
    'tenants'    => ['Tenant List',                   true,  'Tenants with their contact details, room and balance (period: move-in date).'],
    'occupancy'  => ['Room Occupancy',                false, 'Every room with its beds taken and free, and its rent, on a chosen date.'],
    'complaints' => ['Complaints Report',             true,  'Complaints filed in the period and where they stand.'],
];

// Periods offered by the date filter. 'custom' uses the from / to dates.
const REPORT_PERIODS = [
    'all' => 'All time', 'today' => 'Today', 'week' => 'This week', 'month' => 'This month',
    'last_month' => 'Last month', 'year' => 'This year', 'last_year' => 'Last year', 'custom' => 'Custom dates',
];

// Kinds of charge, for the Charges Report: label => SQL condition on c.kind.
const CHARGE_TYPES = [
    'rent'      => ['Rent',        "(c.kind = 'rent' OR c.kind LIKE 'room\\_%')"],
    'deposit'   => ['Deposit',     "c.kind IN ('deposit', 'deposit_applied')"],
    'appliance' => ['Appliance',   "c.kind LIKE 'appl\\_%'"],
    'damage'    => ['Damage',      "c.kind LIKE 'dmg\\_%'"],
    'other'     => ['Other',       "c.kind = 'opening'"],
];

/** "Rent", "Deposit", ... for one charges.kind. */
function chargeTypeLabel(string $kind): string {
    if ($kind === 'rent' || str_starts_with($kind, 'room_')) return 'Rent';
    if ($kind === 'deposit') return 'Deposit';
    if ($kind === 'deposit_applied') return 'Deposit used';
    if (str_starts_with($kind, 'appl_')) return 'Appliance';
    if (str_starts_with($kind, 'dmg_')) return 'Damage';
    return 'Other';
}

/** A date from the query string, or the default. */
function reportDate(?string $value, string $default): string {
    $d = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $d && $d->format('Y-m-d') === $value ? $value : $default;
}

/** A valid 'Y-m-d' date, or null. */
function reportDateOrNull(?string $value): ?string {
    $d = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $d && $d->format('Y-m-d') === $value ? $value : null;
}

/**
 * The filters a report offers:
 *   period  - label of the date the period applies to (absent: no period)
 *   as_of   - true: a single "as of" date instead of a period
 *   q       - placeholder of the search box
 *   room    - true: filter by room
 *   selects - key => [label, [value => text]] (the first option, value '', means "any")
 */
function reportFilterSpec(string $type): array {
    $method = ['Method', ['' => 'All methods', 'cash' => 'Cash', 'gcash' => 'GCash']];
    $standing = ['Standing', ['' => 'Any standing', 'paid' => 'Paid up', 'due' => 'Owes, not yet due', 'overdue' => 'Overdue']];
    $tenantStatus = ['Tenant', ['' => 'All tenants', 'active' => 'Living here', 'deactivated' => 'Moved out']];
    switch ($type) {
        case 'financial':
            return ['period' => 'Payment date', 'q' => 'Tenant or reference no.', 'room' => true, 'selects' => ['method' => $method]];
        case 'payments':
            return ['period' => 'Payment date', 'q' => 'Tenant or reference no.', 'room' => true, 'selects' => [
                'status' => ['Status', ['' => 'All statuses', 'verified' => 'Verified', 'pending' => 'Pending', 'rejected' => 'Rejected']],
                'method' => $method]];
        case 'charges':
            return ['period' => 'Due date', 'q' => 'Tenant or description', 'room' => true, 'selects' => [
                'charge' => ['Type', ['' => 'All types'] + array_map(fn($t) => $t[0], CHARGE_TYPES)]]];
        case 'unpaid':
            return ['q' => 'Tenant name or mobile', 'room' => true, 'selects' => [
                'standing' => ['Standing', ['' => 'Everyone who owes', 'overdue' => 'Overdue only', 'due' => 'Not yet due only']],
                'status' => $tenantStatus]];
        case 'tenants':
            return ['period' => 'Move-in date', 'q' => 'Name, mobile or email', 'room' => true, 'selects' => [
                'status' => $tenantStatus, 'standing' => $standing]];
        case 'occupancy':
            return ['as_of' => true, 'q' => 'Room number', 'selects' => [
                'availability' => ['Rooms', ['' => 'All rooms', 'space' => 'With free beds', 'available' => 'Empty', 'partial' => 'Partly taken',
                                             'full' => 'Full', 'maintenance' => 'Under maintenance']]]];
        case 'complaints':
            return ['period' => 'Date filed', 'q' => 'Tenant or subject', 'room' => true, 'selects' => [
                'status' => ['Status', ['' => 'All statuses', 'pending' => 'Pending', 'in_progress' => 'In Progress', 'resolved' => 'Resolved']],
                'priority' => ['Priority', ['' => 'Any priority'] + array_map(fn($p) => $p[0], COMPLAINT_PRIORITIES)],
                'category' => ['Category', ['' => 'All categories'] + array_combine(array_keys(COMPLAINT_CATEGORIES), array_keys(COMPLAINT_CATEGORIES))]]];
    }
    return [];
}

/**
 * Read and check a report's filters. $get(key) returns the raw value of one filter (or null).
 * @return array period, from, to (null: open-ended), as_of, q, room (0: any), and each select's value
 */
function normalizeReportFilters(string $type, callable $get): array {
    $spec = reportFilterSpec($type);
    $f = ['period' => 'all', 'from' => null, 'to' => null, 'as_of' => null, 'q' => '', 'room' => 0];
    if (!empty($spec['period'])) {
        $period = (string)($get('period') ?? '');
        // Older links carry only from / to: those are custom dates.
        if ($period === '' && ($get('from') || $get('to'))) $period = 'custom';
        $f['period'] = array_key_exists($period, REPORT_PERIODS) ? $period : 'all';
        [$f['from'], $f['to']] = reportPeriodRange($f['period'], $get('from'), $get('to'));
    }
    if (!empty($spec['as_of'])) {
        $asOf = reportDateOrNull($get('as_of'));
        $f['as_of'] = $asOf && $asOf <= date('Y-m-d') ? $asOf : date('Y-m-d');
    }
    $f['q'] = mb_substr(trim((string)($get('q') ?? '')), 0, 80);
    $f['room'] = !empty($spec['room']) ? max(0, (int)($get('room') ?? 0)) : 0;
    foreach ($spec['selects'] ?? [] as $key => [, $options]) {
        $value = (string)($get($key) ?? '');
        $f[$key] = array_key_exists($value, $options) ? $value : '';
    }
    return $f;
}

/** [from, to] of a period, either end null when open. */
function reportPeriodRange(string $period, $from = null, $to = null): array {
    $today = date('Y-m-d');
    switch ($period) {
        case 'today':      return [$today, $today];
        case 'week':       return [date('Y-m-d', strtotime('monday this week')), $today];
        case 'month':      return [date('Y-m-01'), $today];
        case 'last_month': return [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))];
        case 'year':       return [date('Y-01-01'), $today];
        case 'last_year':  return [date('Y-01-01', strtotime('-1 year')), date('Y-12-31', strtotime('-1 year'))];
        case 'custom':
            $from = reportDateOrNull(is_scalar($from) ? (string)$from : null);
            $to = reportDateOrNull(is_scalar($to) ? (string)$to : null);
            if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
            return [$from, $to];
    }
    return [null, null];
}

/** The filters that differ from "everything", as query parameters (period, from, to, q, ...). */
function reportFilterParams(string $type, array $f): array {
    $spec = reportFilterSpec($type);
    $out = [];
    if (!empty($spec['period']) && $f['period'] !== 'all') {
        $out['period'] = $f['period'];
        if ($f['period'] === 'custom') {
            if ($f['from']) $out['from'] = $f['from'];
            if ($f['to']) $out['to'] = $f['to'];
        }
    }
    if (!empty($spec['as_of']) && $f['as_of'] && $f['as_of'] !== date('Y-m-d')) $out['as_of'] = $f['as_of'];
    if ($f['q'] !== '') $out['q'] = $f['q'];
    if ($f['room']) $out['room'] = $f['room'];
    foreach (array_keys($spec['selects'] ?? []) as $key) {
        if (($f[$key] ?? '') !== '') $out[$key] = $f[$key];
    }
    return $out;
}

/** "Nov 1, 2026 to Nov 17, 2026", "From Nov 1, 2026", "All time" */
function reportPeriodText(?string $from, ?string $to): string {
    $d = fn($x) => date('M j, Y', strtotime($x));
    if ($from && $to) return $from === $to ? $d($from) : $d($from) . ' to ' . $d($to);
    if ($from) return 'From ' . $d($from);
    if ($to) return 'Up to ' . $d($to);
    return 'All time';
}

/** The filters in words, besides the period: "Method: Cash · Room 101 · Search: juan". */
function describeReportFilters(PDO $pdo, string $type, array $f): string {
    $spec = reportFilterSpec($type);
    $parts = [];
    foreach ($spec['selects'] ?? [] as $key => [$label, $options]) {
        if (($f[$key] ?? '') !== '') $parts[] = "$label: " . $options[$f[$key]];
    }
    if (!empty($f['room'])) {
        $r = $pdo->prepare("SELECT room_number FROM rooms WHERE id = ?");
        $r->execute([$f['room']]);
        $parts[] = 'Room ' . ($r->fetchColumn() ?: '?');
    }
    if (($f['q'] ?? '') !== '') $parts[] = 'Search: "' . $f['q'] . '"';
    return implode(' · ', $parts);
}

/** SQL (and its parameters) for "the tenant aliased $t lives in room ?" - main or additional room. */
function reportRoomSql(string $t, int $roomId): array {
    return ["($t.room_id = ? OR EXISTS (SELECT 1 FROM tenant_rooms rx WHERE rx.tenant_id = $t.id AND rx.room_id = ? AND rx.ended_at IS NULL))",
            [$roomId, $roomId]];
}

/** Lower-case LIKE pattern for a search box. */
function reportLike(string $q): string {
    return '%' . mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q)) . '%';
}

/** Lower-case "first last" of a tenant alias, for searching. */
function reportNameSql(string $t): string {
    return "LOWER(CONCAT($t.first_name, ' ', $t.last_name))";
}

/**
 * Which room each tenant was in on a past date, rebuilt from the room history (moves in and out,
 * room changes, additional rooms). Tenants with no history at all count in their current room
 * from their move-in date.
 * @return array room id => list of [tenant_id, first_name, last_name, extra (bool)]
 */
function occupantsOnDate(PDO $pdo, string $date): array {
    $stmt = $pdo->prepare("SELECT rt.tenant_id, rt.from_room_id, rt.to_room_id, rt.note, t.first_name, t.last_name
                           FROM room_transfers rt JOIN tenants t ON t.id = rt.tenant_id
                           WHERE rt.transferred_at <= ? ORDER BY rt.transferred_at, rt.id");
    $stmt->execute([$date]);
    $state = [];   // tenant id => [room id => extra]
    $names = [];
    foreach ($stmt->fetchAll() as $e) {
        $tid = (int)$e['tenant_id'];
        $names[$tid] = [$e['first_name'], $e['last_name']];
        $state[$tid] = $state[$tid] ?? [];
        if ($e['from_room_id']) unset($state[$tid][(int)$e['from_room_id']]);
        if ($e['to_room_id']) $state[$tid][(int)$e['to_room_id']] = str_starts_with((string)$e['note'], 'Rented an additional room');
    }
    $legacy = $pdo->prepare("SELECT t.id, t.room_id, t.first_name, t.last_name FROM tenants t
                             WHERE t.status = 'active' AND t.room_id IS NOT NULL AND t.move_in_date <= ?
                               AND NOT EXISTS (SELECT 1 FROM room_transfers rt WHERE rt.tenant_id = t.id)");
    $legacy->execute([$date]);
    foreach ($legacy->fetchAll() as $t) {
        $state[(int)$t['id']] = [(int)$t['room_id'] => false];
        $names[(int)$t['id']] = [$t['first_name'], $t['last_name']];
    }
    $out = [];
    foreach ($state as $tid => $rooms) {
        foreach ($rooms as $roomId => $extra) {
            $out[$roomId][] = ['tenant_id' => $tid, 'first_name' => $names[$tid][0], 'last_name' => $names[$tid][1], 'extra' => $extra];
        }
    }
    return $out;
}

/**
 * One report, ready to print.
 * @param array $f filters from normalizeReportFilters()
 * @return array type, title, period, filters (in words), columns [[label, width, align]], rows (list of lists),
 *               totals [label => text], links (row index => tenant id, for the page)
 */
function buildReport(PDO $pdo, string $type, array $f): array {
    $money = fn($v) => number_format((float)$v, 2);
    $name = fn($r) => trim($r['first_name'] . ' ' . $r['last_name']);
    [$title, $usesRange] = REPORT_TYPES[$type];
    $spec = reportFilterSpec($type);
    $period = !empty($spec['period']) ? reportPeriodText($f['from'], $f['to'])
            : 'As of ' . date('M j, Y', strtotime($f['as_of'] ?? date('Y-m-d')));
    if (!empty($spec['period']) && $spec['period'] !== 'Payment date' && $period !== 'All time') {
        $period = $spec['period'] . ': ' . $period;   // "Move-in date: Nov 1, 2026 to ..."
    }
    $report = ['type' => $type, 'title' => $title, 'period' => $period, 'filters' => describeReportFilters($pdo, $type, $f),
               'columns' => [], 'rows' => [], 'totals' => [], 'links' => []];

    // WHERE pieces shared by the reports: the period on $dateExpr, the room, the search.
    $where = [];
    $params = [];
    $addPeriod = function (string $dateExpr, bool $isTimestamp = false) use (&$where, &$params, $f) {
        if ($f['from']) { $where[] = "$dateExpr >= ?"; $params[] = $f['from'] . ($isTimestamp ? ' 00:00:00' : ''); }
        if ($f['to'])   { $where[] = $isTimestamp ? "$dateExpr < ?" : "$dateExpr <= ?";
                          $params[] = $isTimestamp ? date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00' : $f['to']; }
    };
    $addRoom = function (string $t) use (&$where, &$params, $f) {
        if ($f['room']) { [$sql, $p] = reportRoomSql($t, $f['room']); $where[] = $sql; array_push($params, ...$p); }
    };
    $addSearch = function (array $exprs) use (&$where, &$params, $f) {
        if ($f['q'] === '') return;
        $where[] = '(' . implode(' OR ', array_map(fn($e) => "$e LIKE ?", $exprs)) . ')';
        foreach ($exprs as $e) $params[] = reportLike($f['q']);
    };
    $addMethod = function () use (&$where, $f) {
        if (($f['method'] ?? '') === 'cash') $where[] = "p.payment_method = 'cash'";
        if (($f['method'] ?? '') === 'gcash') $where[] = "(p.payment_method = 'gcash' OR p.payment_method IS NULL OR p.payment_method = '')";
    };
    // A closure, not an arrow function: it must see $where as filled in later, not as it is now.
    $whereSql = function () use (&$where): string {
        return $where ? ' AND ' . implode(' AND ', $where) : '';
    };

    if ($type === 'financial') {
        $report['columns'] = [['Date', 24, 'L'], ['Receipt No.', 26, 'L'], ['Tenant', 52, 'L'], ['Room', 20, 'L'],
                              ['Method', 34, 'L'], ['Reference No.', 44, 'L'], ['Amount (PHP)', 30, 'R']];
        $addPeriod('p.payment_date');
        $addRoom('t');
        $addSearch([reportNameSql('t'), 'LOWER(p.reference_number)']);
        $addMethod();
        $stmt = $pdo->prepare("SELECT p.*, t.first_name, t.last_name, r.room_number FROM payments p
                               JOIN tenants t ON t.id = p.tenant_id LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE " . revenueFilterSql('p') . $whereSql() . "
                               ORDER BY p.payment_date, p.id");
        $stmt->execute($params);
        $total = 0.0;
        $byMethod = [];
        foreach ($stmt->fetchAll() as $p) {
            $method = paymentMethodLabel($p['payment_method'] ?? 'gcash');
            $report['links'][count($report['rows'])] = (int)$p['tenant_id'];
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
        $addPeriod('p.payment_date');
        $addRoom('t');
        $addSearch([reportNameSql('t'), 'LOWER(p.reference_number)']);
        $addMethod();
        if ($f['status'] !== '') { $where[] = 'p.status = ?'; $params[] = $f['status']; }
        $stmt = $pdo->prepare("SELECT p.*, t.first_name, t.last_name, r.room_number FROM payments p
                               JOIN tenants t ON t.id = p.tenant_id LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE p.covered_by_payment_id IS NULL" . $whereSql() . "
                               ORDER BY p.payment_date, p.id");
        $stmt->execute($params);
        $sum = ['verified' => [0, 0.0], 'pending' => [0, 0.0], 'rejected' => [0, 0.0]];
        foreach ($stmt->fetchAll() as $p) {
            $report['links'][count($report['rows'])] = (int)$p['tenant_id'];
            $report['rows'][] = [$p['payment_date'], $name($p), $p['room_number'] ?? '-', paymentMethodLabel($p['payment_method'] ?? 'gcash'),
                                 reportReference($p['reference_number'] ?? ''), ucfirst($p['status']), $money($p['amount'])];
            if (isset($sum[$p['status']])) {
                $sum[$p['status']][0]++;
                $sum[$p['status']][1] += (float)$p['amount'];
            }
        }
        foreach ($sum as $status => [$n, $amount]) {
            if ($f['status'] !== '' && $f['status'] !== $status) continue;
            $report['totals'][ucfirst($status)] = "$n · PHP " . $money($amount);
        }

    } elseif ($type === 'charges') {
        $report['columns'] = [['Due', 24, 'L'], ['Tenant', 46, 'L'], ['Room', 18, 'L'], ['Type', 26, 'L'],
                              ['Description', 120, 'L'], ['Amount (PHP)', 30, 'R']];
        $addPeriod('c.due_date');
        $addRoom('t');
        $addSearch([reportNameSql('t'), 'LOWER(c.description)']);
        if ($f['charge'] !== '') $where[] = CHARGE_TYPES[$f['charge']][1];
        $stmt = $pdo->prepare("SELECT c.*, t.first_name, t.last_name, r.room_number FROM charges c
                               JOIN tenants t ON t.id = c.tenant_id LEFT JOIN rooms r ON r.id = c.room_id
                               WHERE 1 = 1" . $whereSql() . "
                               ORDER BY c.due_date, c.id");
        $stmt->execute($params);
        $byType = [];
        $total = 0.0;
        foreach ($stmt->fetchAll() as $c) {
            $label = chargeTypeLabel($c['kind']);
            $report['links'][count($report['rows'])] = (int)$c['tenant_id'];
            $report['rows'][] = [$c['due_date'], $name($c), $c['room_number'] ?? '-', $label, $c['description'] ?? '', $money($c['amount'])];
            $byType[$label] = ($byType[$label] ?? 0) + (float)$c['amount'];
            $total += (float)$c['amount'];
        }
        $report['totals']['Charges'] = (string)count($report['rows']);
        foreach ($byType as $label => $amount) {
            $report['totals'][$label] = 'PHP ' . $money($amount);
        }
        $report['totals']['Total billed'] = 'PHP ' . $money($total);

    } elseif ($type === 'unpaid') {
        $report['columns'] = [['Tenant', 50, 'L'], ['Room', 20, 'L'], ['Mobile', 32, 'L'], ['Status', 26, 'L'],
                              ['Balance (PHP)', 30, 'R'], ['Overdue (PHP)', 30, 'R'], ['Overdue Since', 28, 'L']];
        $notYetDue = chargesNotYetDue($pdo);
        $addRoom('t');
        $addSearch([reportNameSql('t'), "LOWER(COALESCE(t.contact_number, ''))"]);
        if ($f['status'] !== '') { $where[] = 't.status = ?'; $params[] = $f['status']; }
        $stmt = $pdo->prepare("SELECT t.*, r.room_number FROM tenants t LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE t.balance > 0" . $whereSql() . " ORDER BY t.balance DESC");
        $stmt->execute($params);
        $balance = 0.0;
        $overdue = 0.0;
        foreach ($stmt->fetchAll() as $t) {
            $billing = tenantBillingStatus($t, $notYetDue);
            if ($f['standing'] === 'overdue' && $billing['overdue'] <= 0) continue;
            if ($f['standing'] === 'due' && $billing['overdue'] > 0) continue;
            $since = $billing['overdue'] > 0 ? oldestUnpaidDueDate($pdo, (int)$t['id'], (float)$t['balance']) : null;
            $report['links'][count($report['rows'])] = (int)$t['id'];
            $report['rows'][] = [$name($t), $t['room_number'] ?? '-', $t['contact_number'] ?? '', $t['status'] === 'active' ? $billing['label'] : 'Moved out',
                                 $money($billing['balance']), $money($billing['overdue']), $since ?? '-'];
            $balance += $billing['balance'];
            $overdue += $billing['overdue'];
        }
        $report['totals']['Tenants who owe'] = (string)count($report['rows']);
        $report['totals']['Total unpaid'] = 'PHP ' . $money($balance);
        $report['totals']['Of which overdue'] = 'PHP ' . $money($overdue);

    } elseif ($type === 'tenants') {
        $report['columns'] = [['Tenant', 46, 'L'], ['Mobile', 30, 'L'], ['Email', 50, 'L'], ['Room', 18, 'L'],
                              ['Move-in', 24, 'L'], ['Status', 22, 'L'], ['Rent (PHP)', 24, 'R'], ['Balance (PHP)', 26, 'R']];
        $addPeriod('t.move_in_date');
        $addRoom('t');
        $addSearch([reportNameSql('t'), "LOWER(COALESCE(t.contact_number, ''))", "LOWER(COALESCE(t.email, ''))"]);
        if ($f['status'] !== '') { $where[] = 't.status = ?'; $params[] = $f['status']; }
        $stmt = $pdo->prepare("SELECT t.*, r.room_number, r.price_per_month, r.capacity, r.solo_pays_full,
                                      " . roomBedsTakenSql('r.id') . " AS beds_taken FROM tenants t
                               LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE 1 = 1" . $whereSql() . "
                               ORDER BY (t.status = 'active') DESC, t.first_name, t.last_name");
        $stmt->execute($params);
        $tenants = $stmt->fetchAll();
        // Rooms rented on top of the main room add their rent, and are listed after it.
        $extra = [];
        foreach ($pdo->query("SELECT x.tenant_id, r.room_number, r.price_per_month, r.capacity, r.solo_pays_full,
                                     " . roomBedsTakenSql('r.id') . " AS beds_taken FROM tenant_rooms x
                              JOIN rooms r ON r.id = x.room_id WHERE x.ended_at IS NULL ORDER BY x.start_date, x.id")->fetchAll() as $x) {
            $extra[(int)$x['tenant_id']][] = $x;
        }
        $notYetDue = chargesNotYetDue($pdo);
        $active = 0;
        foreach ($tenants as $t) {
            if ($f['standing'] !== '') {
                $bal = round((float)$t['balance'], 2);
                $od = max(0, round($bal - ($notYetDue[$t['id']] ?? 0), 2));
                $standing = $bal <= 0 ? 'paid' : ($od > 0 ? 'overdue' : 'due');
                if ($standing !== $f['standing']) continue;
            }
            $isActive = $t['status'] === 'active';
            $active += $isActive ? 1 : 0;
            $rent = $t['room_id'] ? bedRent($t, (int)$t['beds_taken']) : 0;
            $roomText = $t['room_number'] ?? '-';
            foreach ($extra[(int)$t['id']] ?? [] as $x) {
                $rent += bedRent($x, (int)$x['beds_taken']);
                $roomText .= ', ' . $x['room_number'];
            }
            $report['links'][count($report['rows'])] = (int)$t['id'];
            $report['rows'][] = [$name($t), $t['contact_number'] ?? '', $t['email'] ?? '', $roomText,
                                 $t['move_in_date'] ?? '-', $isActive ? 'Active' : 'Moved out', $rent ? $money($rent) : '-', $money($t['balance'])];
        }
        $report['totals']['Tenants'] = (string)count($report['rows']);
        $report['totals']['Active tenants'] = (string)$active;
        $report['totals']['Moved out'] = (string)(count($report['rows']) - $active);

    } elseif ($type === 'occupancy') {
        $report['columns'] = [['Room', 20, 'L'], ['Status', 26, 'L'], ['Beds', 14, 'R'], ['Taken', 14, 'R'], ['Free', 14, 'R'],
                              ['Room Price', 28, 'R'], ['Per Tenant', 26, 'R'], ['Tenants', 100, 'L']];
        $rooms = $pdo->query("SELECT r.* FROM rooms r")->fetchAll();
        usort($rooms, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));
        $asOf = $f['as_of'] ?? date('Y-m-d');
        $byRoom = [];
        if ($asOf === date('Y-m-d')) {
            $who = $pdo->query("SELECT room_id, first_name, last_name FROM tenants WHERE status = 'active' AND room_id IS NOT NULL ORDER BY first_name")->fetchAll();
            foreach ($who as $w) {
                $byRoom[$w['room_id']][] = $name($w);
            }
            // Tenants renting a room on top of their main one take a bed in it too.
            $extraWho = $pdo->query("SELECT x.room_id, t.first_name, t.last_name FROM tenant_rooms x JOIN tenants t ON t.id = x.tenant_id
                                     WHERE x.ended_at IS NULL AND t.status = 'active' ORDER BY t.first_name")->fetchAll();
            foreach ($extraWho as $w) {
                $byRoom[$w['room_id']][] = $name($w) . ' (additional)';
            }
        } else {
            foreach (occupantsOnDate($pdo, $asOf) as $roomId => $list) {
                usort($list, fn($a, $b) => strcasecmp($a['first_name'], $b['first_name']));
                $byRoom[$roomId] = array_map(fn($o) => $name($o) . ($o['extra'] ? ' (additional)' : ''), $list);
            }
        }
        $beds = $taken = 0;
        $q = mb_strtolower($f['q']);
        foreach ($rooms as $r) {
            if ($q !== '' && !str_contains(mb_strtolower((string)$r['room_number']), $q)) continue;
            $names = $byRoom[$r['id']] ?? [];
            $n = count($names);
            $cap = (int)$r['capacity'];
            $key = $r['status'] === 'maintenance' ? 'maintenance' : ($n >= $cap ? 'full' : ($n > 0 ? 'partial' : 'available'));
            $av = $f['availability'];
            if ($av === 'space' ? !in_array($key, ['available', 'partial'], true) : ($av !== '' && $av !== $key)) continue;
            $status = ['maintenance' => 'Maintenance', 'full' => 'Full', 'partial' => 'Partial', 'available' => 'Available'][$key];
            $report['rows'][] = ['Room ' . $r['room_number'], $status, (string)$cap, (string)$n, (string)max(0, $cap - $n),
                                 $money($r['price_per_month']), $money(rentShare((float)$r['price_per_month'], $cap)), implode(', ', $names) ?: '-'];
            $beds += $cap;
            $taken += $n;
        }
        $report['totals']['Rooms'] = (string)count($report['rows']);
        $report['totals']['Beds taken'] = "$taken of $beds";
        $report['totals']['Bed occupancy'] = ($beds ? round($taken / $beds * 100, 1) : 0) . '%';

    } elseif ($type === 'complaints') {
        $report['columns'] = [['No.', 26, 'L'], ['Filed', 24, 'L'], ['Tenant', 44, 'L'], ['Room', 18, 'L'], ['Category', 40, 'L'],
                              ['Priority', 20, 'L'], ['Status', 24, 'L'], ['Subject', 80, 'L']];
        $addPeriod('c.created_at', true);
        $addRoom('t');
        $addSearch([reportNameSql('t'), 'LOWER(c.subject)']);
        if ($f['priority'] !== '') { $where[] = "COALESCE(c.priority, 'normal') = ?"; $params[] = $f['priority']; }
        if ($f['category'] !== '') { $where[] = "COALESCE(NULLIF(c.category, ''), 'Others') = ?"; $params[] = $f['category']; }
        if ($f['status'] === 'resolved') $where[] = "c.status = 'resolved'";
        if ($f['status'] === 'pending') $where[] = COMPLAINT_PENDING_SQL;
        if ($f['status'] === 'in_progress') $where[] = COMPLAINT_PROGRESS_SQL;
        $stmt = $pdo->prepare("SELECT c.*, t.first_name, t.last_name, r.room_number FROM complaints c
                               JOIN tenants t ON t.id = c.tenant_id LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE 1 = 1" . $whereSql() . " ORDER BY c.created_at, c.id");
        $stmt->execute($params);
        $counts = [];
        foreach ($stmt->fetchAll() as $c) {
            $st = complaintStatus($c);
            $report['links'][count($report['rows'])] = (int)$c['tenant_id'];
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

function reportFileName(array $report, array $f, string $ext): string {
    $when = ($f['from'] || $f['to']) ? ($f['from'] ?? 'start') . '_to_' . ($f['to'] ?? date('Y-m-d')) : ($f['as_of'] ?? date('Y-m-d'));
    return strtolower(str_replace(' ', '_', $report['title'])) . '_' . $when . ($report['filters'] !== '' ? '_filtered' : '') . ".$ext";
}

/** CSV with a byte-order mark, so Excel shows names like "Peña" correctly. */
function outputReportCsv(array $report, string $filename, string $houseName): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$houseName . ' - ' . $report['title']]);
    fputcsv($out, [$report['period']]);
    if (($report['filters'] ?? '') !== '') {
        fputcsv($out, ['Filters: ' . str_replace('·', '-', $report['filters'])]);
    }
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
    if (($report['filters'] ?? '') !== '') {
        $pdf->Cell(0, 5, $text('Filters: ' . $report['filters']), 0, 1);
    }
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
        $pdf->Cell($usable, 10, 'Nothing matches these filters.', 0, 1, 'C');
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

/**
 * The months the Revenue Overview can show: from the first bill or payment to this month,
 * newest first (at most two years back). 'YYYY-MM' => 'October 2026'.
 */
function revenueMonthChoices(PDO $pdo): array {
    $first = $pdo->query("SELECT MIN(m) FROM (SELECT MIN(billing_month) AS m FROM charges
                          UNION ALL SELECT " . sqlYearMonth('MIN(payment_date)') . " FROM payments) x")->fetchColumn();
    $current = date('Y-m');
    $oldest = max($first && $first <= $current ? $first : $current, date('Y-m', strtotime('-23 months', strtotime($current . '-01'))));
    $choices = [];
    for ($m = $current; $m >= $oldest; $m = date('Y-m', strtotime('-1 month', strtotime($m . '-01')))) {
        $choices[$m] = date('F Y', strtotime($m . '-01'));
    }
    return $choices;
}

/**
 * One month's money, for the Revenue Overview.
 *   expected     - bills for the month (rent and deposits)
 *   collected    - verified payments received in the month
 *   transactions - how many verified payments that was
 *   unpaid       - what is still owed on the month's bills. Payments settle the oldest bills
 *                  first, so a tenant's balance sits on their newest bills.
 */
function monthRevenueSummary(PDO $pdo, string $month): array {
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM charges WHERE billing_month = ? AND amount > 0");
    $stmt->execute([$month]);
    $expected = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payments WHERE " . REVENUE_FILTER_SQL . " AND payment_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    [$count, $collected] = $stmt->fetch(PDO::FETCH_NUM);

    $unpaid = 0.0;
    $owing = $pdo->query("SELECT id, balance FROM tenants WHERE balance > 0")->fetchAll();
    $bills = $pdo->prepare("SELECT amount, billing_month FROM charges WHERE tenant_id = ? AND amount > 0 ORDER BY due_date DESC, id DESC");
    foreach ($owing as $t) {
        $left = (float)$t['balance'];
        $bills->execute([$t['id']]);
        foreach ($bills->fetchAll() as $b) {
            if ($left <= 0) break;
            $part = min($left, (float)$b['amount']);
            if ($b['billing_month'] === $month) $unpaid += $part;
            $left -= $part;
        }
    }

    return ['expected' => $expected, 'collected' => (float)$collected, 'transactions' => (int)$count, 'unpaid' => round($unpaid, 2)];
}

/**
 * Verified revenue per calendar month for the last $months months, oldest first, with zeros
 * for months without payments. ['Oct 2026' => 4000.0, ...]
 */
function monthlyRevenueTrend(PDO $pdo, int $months): array {
    $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months', strtotime(date('Y-m-01'))));
    $stmt = $pdo->prepare("SELECT " . sqlYearMonth('payment_date') . " AS ym, SUM(amount) AS total FROM payments
                           WHERE " . REVENUE_FILTER_SQL . " AND payment_date >= ? GROUP BY ym");
    $stmt->execute([$start]);
    $byMonth = array_column($stmt->fetchAll(), 'total', 'ym');
    $trend = [];
    for ($i = 0; $i < $months; $i++) {
        $m = date('Y-m', strtotime("+$i months", strtotime($start)));
        $trend[date('M Y', strtotime($m . '-01'))] = (float)($byMonth[$m] ?? 0);
    }
    return $trend;
}
