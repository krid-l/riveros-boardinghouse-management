<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/pdf_generator.php';
require_once '../includes/sms.php';
require_once '../includes/billing.php';
require_once '../includes/pagination.php';
require_once '../includes/uploads.php';
require_once '../includes/notifications.php';
require_once '../includes/ui.php';

// --- ACTION HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminders') {
    // Rent reminders by SMS: due within a few days, or overdue. Never repeats one already sent.
    require_once '../includes/sms_reminders.php';
    $r = sendRentReminders($pdo);
    if ($r['sent'] + $r['failed'] + $r['skipped'] === 0) {
        $success = "Nobody needs a reminder right now. Everyone with rent due in the next " . REMINDER_DAYS_BEFORE . " days or overdue has already been reminded.";
    } elseif ($r['sent'] > 0 && $r['failed'] + $r['skipped'] === 0) {
        $success = "Rent reminders sent: {$r['due']} due soon, {$r['overdue']} overdue.";
    } else {
        $error = "Rent reminders: {$r['sent']} sent, " . ($r['failed'] + $r['skipped']) . " not sent. " . smsErrorText($r);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $action = $_POST['action']; // 'verify' or 'reject'
    
    // Fetch payment details
    $stmt = $pdo->prepare("SELECT p.*, t.first_name, t.last_name, t.contact_number, t.balance, t.room_id FROM payments p JOIN tenants t ON p.tenant_id = t.id WHERE p.id = ?");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    
    $smsName = smsConfig($pdo)['name'];
    if (!$payment || $payment['status'] !== 'pending') {
        $error = "This payment was already processed.";
    } elseif ($action === 'verify') {
        $smsQueue = [];
        $pdo->beginTransaction();
        try {
            // Claim the payment atomically so a double click can't apply it twice.
            $upd = $pdo->prepare("UPDATE payments SET status = 'verified' WHERE id = ? AND status = 'pending'");
            $upd->execute([$paymentId]);
            if ($upd->rowCount() !== 1) {
                throw new Exception("This payment was already processed.");
            }

            $amount = round((float)$payment['amount'], 2);
            $lockBal = $pdo->prepare("SELECT balance FROM tenants WHERE id = ? FOR UPDATE");
            $lockBal->execute([$payment['tenant_id']]);
            $payerBalance = (float)$lockBal->fetchColumn();
            $subtract = $pdo->prepare("UPDATE tenants SET balance = balance - ? WHERE id = ?");

            // Split the money: every peso of the payment is applied to exactly one tenant's balance.
            $remaining = $amount;
            $boardmateShares = [];
            if (!empty($payment['pay_for_room']) && !empty($payment['room_id'])) {
                $payerShare = min(max($payerBalance, 0), $remaining);
                $remaining = round($remaining - $payerShare, 2);

                $stmtRoom = $pdo->prepare("SELECT id, first_name, last_name, contact_number, balance FROM tenants
                                           WHERE room_id = ? AND id <> ? AND status = 'active' AND balance > 0
                                           ORDER BY id FOR UPDATE");
                $stmtRoom->execute([$payment['room_id'], $payment['tenant_id']]);
                foreach ($stmtRoom->fetchAll() as $bm) {
                    if ($remaining <= 0) break;
                    $share = min((float)$bm['balance'], $remaining);
                    $remaining = round($remaining - $share, 2);
                    $boardmateShares[] = ['bm' => $bm, 'share' => round($share, 2)];
                }
                // If balances dropped since the tenant submitted, the extra stays with the payer as credit.
                $payerShare = round($payerShare + $remaining, 2);
            } else {
                $payerShare = $amount; // anything above the balance becomes credit toward the next bill
            }

            $subtract->execute([$payerShare, $payment['tenant_id']]);

            $payMethodStr = 'Covered by ' . $payment['first_name'];
            $insCoveredSql = "INSERT INTO payments (tenant_id, amount, payment_date, reference_number, screenshot_path, payment_method, status, pay_for_room, covered_by_payment_id)
                              VALUES (?, ?, ?, ?, ?, ?, 'verified', ?, ?)";
            foreach ($boardmateShares as $s) {
                $bm = $s['bm'];
                $subtract->execute([$s['share'], $bm['id']]);

                // Record of the covered share for the boardmate's portal. Linked to the real payment,
                // so it isn't counted as extra revenue.
                $newPaymentId = insertReturningId($pdo, $insCoveredSql, [
                    $bm['id'], $s['share'], $payment['payment_date'], $payment['reference_number'],
                    $payment['screenshot_path'], $payMethodStr, dbBool(false), $paymentId
                ]);

                $bmFullName = $bm['first_name'] . ' ' . $bm['last_name'];
                $proxyReceiptPath = generateReceipt($newPaymentId, $bmFullName, $s['share'], $payment['payment_date'], $payment['reference_number'], $payMethodStr);
                $pdo->prepare("UPDATE payments SET receipt_path = ? WHERE id = ?")->execute([$proxyReceiptPath, $newPaymentId]);

                $smsQueue[] = [$bm['contact_number'], (int)$bm['id'], 'payment_covered',
                    "$smsName: Your rent of PHP " . number_format($s['share'], 2) . " was paid by " . $payment['first_name'] . ". Receipt: RCP-" . str_pad($newPaymentId, 6, '0', STR_PAD_LEFT)];
            }

            // Generate Receipt PDF
            $fullName = $payment['first_name'] . ' ' . $payment['last_name'];
            $receiptPath = generateReceipt($paymentId, $fullName, $amount, $payment['payment_date'], $payment['reference_number'], $payment['payment_method'] ?? 'gcash');
            $pdo->prepare("UPDATE payments SET receipt_path = ? WHERE id = ?")->execute([$receiptPath, $paymentId]);

            $pdo->commit();

            // SMS only after the money is committed.
            $smsQueue[] = [$payment['contact_number'], (int)$payment['tenant_id'], 'payment_verified',
                "$smsName: Your payment of PHP " . number_format($amount, 2) . " has been VERIFIED. Receipt: RCP-" . str_pad($paymentId, 6, '0', STR_PAD_LEFT) . ". Thank you!"];
            $smsResults = [];
            foreach ($smsQueue as [$to, $tenantId, $purpose, $msg]) {
                $smsResults[] = sendSMS($pdo, $to, $msg, $purpose, $tenantId);
            }

            $smsSummary = mergeSmsResults(...$smsResults);
            $success = "Payment verified successfully. Receipt generated. " . smsOutcomeText($smsSummary);

            markNotificationsRead($pdo, "payment_submitted:$paymentId");
            notifyAdmin($pdo, 'payment_verified', "Payment verified: $fullName",
                'PHP ' . number_format($amount, 2) . ' via ' . paymentMethodLabel($payment['payment_method'] ?? 'gcash')
                . ' · Receipt RCP-' . str_pad($paymentId, 6, '0', STR_PAD_LEFT)
                . ($smsSummary['sent'] > 0 ? ' · SMS sent' : ''),
                'payments.php?status=verified');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error verifying payment: " . $e->getMessage();
        }
    } elseif ($action === 'reject') {
        $upd = $pdo->prepare("UPDATE payments SET status = 'rejected' WHERE id = ? AND status = 'pending'");
        $upd->execute([$paymentId]);
        if ($upd->rowCount() === 1) {
            $msg = "$smsName: Your payment submission of PHP " . number_format($payment['amount'], 2) . " (ref " . $payment['reference_number'] . ") was REJECTED. Please check it and submit again.";
            $success = "Payment rejected. " . smsOutcomeText(sendSMS($pdo, $payment['contact_number'], $msg, 'payment_rejected', (int)$payment['tenant_id']));
            markNotificationsRead($pdo, "payment_submitted:$paymentId");
            notifyAdmin($pdo, 'payment_rejected', 'Payment rejected: ' . $payment['first_name'] . ' ' . $payment['last_name'],
                'PHP ' . number_format((float)$payment['amount'], 2) . ($payment['reference_number'] ? ', ref ' . $payment['reference_number'] : ''),
                'payments.php?status=rejected');
        } else {
            $error = "This payment was already processed.";
        }
    }
}

// --- METRICS ---
// Collected = money actually received (roommate "covered" rows are part of another payment).
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
$lastMonthEnd = date('Y-m-t', strtotime('first day of last month'));
$stmtStats = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN " . REVENUE_FILTER_SQL . " AND payment_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as total_collected,
    COALESCE(SUM(CASE WHEN " . REVENUE_FILTER_SQL . " AND payment_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as last_month_collected,
    COALESCE(SUM(CASE WHEN " . REVENUE_FILTER_SQL . " AND payment_date BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) as paid_count,
    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count
    FROM payments");
$stmtStats->execute([$monthStart, $monthEnd, $lastMonthStart, $lastMonthEnd, $monthStart, $monthEnd]);
$paymentStats = $stmtStats->fetch();

$totalCollected = (float)$paymentStats['total_collected'];
$lastMonthCollected = (float)$paymentStats['last_month_collected'];
$paidCount = (int)$paymentStats['paid_count'];
$pendingCount = (int)$paymentStats['pending_count'];
if ($lastMonthCollected > 0) {
    $pct = ($totalCollected - $lastMonthCollected) / $lastMonthCollected * 100;
    $collectedTrend = ($pct >= 0 ? '↑ ' : '↓ ') . number_format(abs($pct), 1) . '% from last month';
} else {
    $collectedTrend = 'No payments last month';
}

// Overdue = unpaid amounts past their due date (the 30th). Due soon = unpaid but not yet past due.
$actualOverdueAmount = 0;
$actualOverdueCount = 0;
$dueSoonAmount = 0;
$dueSoonCount = 0;

$notYetDue = chargesNotYetDue($pdo);
$tenantsWithBal = $pdo->query("SELECT t.id, t.first_name, t.last_name, t.balance, t.status, t.contact_number,
                                      t.profile_picture, r.room_number
                               FROM tenants t LEFT JOIN rooms r ON r.id = t.room_id
                               WHERE t.balance > 0")->fetchAll();
// Who makes up the Due Soon and Overdue totals, shown when those cards are clicked.
$dueSoonList = [];
$overdueList = [];
$today = date('Y-m-d');
foreach ($tenantsWithBal as $t) {
    $bs = tenantBillingStatus($t, $notYetDue);
    if ($bs['overdue'] > 0) {
        $actualOverdueAmount += $bs['overdue'];
        $actualOverdueCount++;
        $since = oldestUnpaidDueDate($pdo, (int)$t['id'], $bs['balance']);
        $overdueList[] = $t + [
            'overdue' => $bs['overdue'],
            'total'   => $bs['balance'],
            'since'   => $since,
            'days'    => $since ? (int)floor((strtotime($today) - strtotime($since)) / 86400) : null,
        ];
    }
    $notDuePart = round($bs['balance'] - $bs['overdue'], 2);
    if ($notDuePart > 0) {
        $dueSoonAmount += $notDuePart;
        $dueSoonCount++;
        $dueSoonList[] = $t + ['amount' => $notDuePart, 'due' => upcomingDueDate($pdo, (int)$t['id'])];
    }
}
usort($dueSoonList, fn($a, $b) => strcmp($a['due'] ?? '9999', $b['due'] ?? '9999') ?: $b['amount'] <=> $a['amount']);
usort($overdueList, fn($a, $b) => $b['overdue'] <=> $a['overdue']);

// The payments behind Total Collected and Paid Payments: money received this month.
$monthPaymentsStmt = $pdo->prepare("SELECT p.*, t.first_name, t.last_name, t.profile_picture, r.room_number
    FROM payments p
    JOIN tenants t ON t.id = p.tenant_id
    LEFT JOIN rooms r ON r.id = t.room_id
    WHERE " . revenueFilterSql('p') . " AND p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date DESC, p.id DESC");
$monthPaymentsStmt->execute([$monthStart, $monthEnd]);
$monthPayments = $monthPaymentsStmt->fetchAll();
$collectedByMethod = [];
$collectedByRoom = [];
foreach ($monthPayments as $mp) {
    $method = ($mp['payment_method'] ?? 'gcash') === 'cash' ? 'Cash' : 'GCash';
    $collectedByMethod[$method] = ($collectedByMethod[$method] ?? 0) + (float)$mp['amount'];
    $roomKey = $mp['room_number'] !== null ? 'Room ' . $mp['room_number'] : 'No room (moved out)';
    $collectedByRoom[$roomKey] = ($collectedByRoom[$roomKey] ?? 0) + (float)$mp['amount'];
}
arsort($collectedByMethod);
uksort($collectedByRoom, 'strnatcasecmp');

// --- PAYMENTS LIST ---
// Filtering and paging happen in SQL. The page used to send every payment row and let
// JavaScript hide all but five, which meant megabytes of HTML for a five-row table.
$filterSearch = queryParam('q');
$filterStatus = queryParam('status', 'all') ?: 'all';
$filterDate = queryParam('date');

$where = [];
$params = [];

if ($filterSearch !== '') {
    $where[] = "(LOWER(t.first_name) LIKE ? OR LOWER(t.last_name) LIKE ?
                 OR LOWER(CONCAT(t.first_name, ' ', t.last_name)) LIKE ? OR LOWER(p.reference_number) LIKE ?)";
    $like = '%' . strtolower($filterSearch) . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($filterStatus === 'verified' || $filterStatus === 'pending' || $filterStatus === 'rejected') {
    $where[] = "p.status = ?";
    $params[] = $filterStatus;
} elseif ($filterStatus === 'unpaid') {
    // The table shows anything not yet verified as "Unpaid".
    $where[] = "p.status <> 'verified'";
}
if ($filterDate !== '' && DateTime::createFromFormat('Y-m-d', $filterDate)) {
    $where[] = "p.payment_date = ?";
    $params[] = $filterDate;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p JOIN tenants t ON p.tenant_id = t.id $whereSql");
$countStmt->execute($params);
$pager = paginate((int)$countStmt->fetchColumn(), 10);

$paymentsStmt = $pdo->prepare("
    SELECT p.*, t.first_name, t.last_name, t.contact_number, t.profile_picture, r.room_number
    FROM payments p
    JOIN tenants t ON p.tenant_id = t.id
    LEFT JOIN rooms r ON t.room_id = r.id
    $whereSql
    ORDER BY p.payment_date DESC, p.id DESC" . paginationLimitSql($pager) . "
");
$paymentsStmt->execute($params);
$payments = $paymentsStmt->fetchAll();

// Who the "Send rent reminders" button would text right now.
require_once '../includes/sms_reminders.php';
$pendingReminders = pendingRentReminders($pdo);
$smsReady = smsConfigured($pdo);

require_once 'header.php';
?>

<style>
    body { background-color: #f8fafc; }
    .stat-table { font-size: 0.78rem; }
    .stat-table th { font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; white-space: nowrap; }
    
    .icon-box-lg {
        width: 38px; height: 38px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem;
    }
    
    .card-title-sm {
        font-size: 0.6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 2px;
    }
    
    .trend-text { font-size: 0.65rem; font-weight: 500; }
    
    .table-compact th { font-size: 0.6rem; text-transform: uppercase; color: #64748b; font-weight: 600; padding: 0.6rem 0.5rem; letter-spacing: 0.5px; }
    .table-compact td { font-size: 0.72rem; vertical-align: middle; padding: 0.5rem 0.5rem; border-bottom: 1px solid #f1f5f9; }

    /* Payments list */
    .payments-table { font-size: 0.78rem; }
    .payments-table thead th { font-size: 0.64rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 700; background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 0.65rem 0.6rem; white-space: nowrap; }
    .payments-table td { padding: 0.6rem; border-bottom: 1px solid #f1f5f9; }
    .payments-table tbody tr:hover { background-color: #f8fafc; }
    .payments-table .cell-sub { font-size: 0.68rem; color: #94a3b8; }
    .method-pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 0.2rem 0.6rem; font-size: 0.7rem; font-weight: 600; }
    .ref-no { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.74rem; color: #334155; }
    .screenshot-thumb { width: 32px; height: 40px; display: inline-block; background-color: #f1f5f9; background-size: cover; background-position: center; border: 1px solid #e2e8f0; border-radius: 6px; vertical-align: middle; }
    .screenshot-thumb:hover { outline: 2px solid #93c5fd; }

    .dot { width: 5px; height: 5px; border-radius: 50%; display: inline-block; margin-right: 5px; }

</style>

<!-- Alerts -->
<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert" style="font-size: 0.85rem;">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="font-size: 0.7rem;"></button>
    </div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert" style="font-size: 0.85rem;">
        <i class="fa-solid fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="font-size: 0.7rem;"></button>
    </div>
<?php endif; ?>

<!-- Header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
    <div>
        <p class="text-muted mb-0" style="font-size: 0.8rem;">Track and manage all tenant payments and verify manual GCash payments.</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary fw-semibold btn-sm px-2 py-1 rounded-2" style="font-size:0.75rem;" data-bs-toggle="modal" data-bs-target="#remindersModal">
            <i class="fa-solid fa-comment-sms me-1"></i> Send Rent Reminders<?php if ($pendingReminders): ?> <span class="badge bg-primary rounded-pill ms-1"><?= count($pendingReminders) ?></span><?php endif; ?>
        </button>
        <a href="export_payments.php" class="btn btn-primary fw-semibold btn-sm px-2 py-1 rounded-2" style="font-size:0.75rem;"><i class="fa-solid fa-download me-1"></i> Export Data</a>
    </div>
</div>

<!-- Top Stats Row: each card opens the list behind its figure -->
<?= statCards([
    ['label' => 'Collected (This Month)', 'value' => peso((float)$totalCollected), 'sub' => $collectedTrend,
     'subClass' => $totalCollected >= $lastMonthCollected ? 'text-success' : 'text-danger',
     'icon' => 'fa-solid fa-wallet', 'color' => 'primary', 'modal' => 'statCollectedModal', 'aria' => "Show how this month's collections break down"],
    ['label' => 'Due Soon', 'value' => peso((float)$dueSoonAmount),
     'sub' => $dueSoonCount . ' ' . ($dueSoonCount == 1 ? 'tenant' : 'tenants') . ' · due ' . date('M j', strtotime(billingDueDate(date('Y-m')))), 'subClass' => 'text-warning',
     'icon' => 'fa-regular fa-clock', 'color' => 'warning', 'modal' => 'statDueModal', 'aria' => 'Show who has rent coming due'],
    ['label' => 'Overdue', 'value' => peso((float)$actualOverdueAmount),
     'sub' => $actualOverdueCount . ' ' . ($actualOverdueCount == 1 ? 'tenant' : 'tenants'), 'subClass' => $actualOverdueCount ? 'text-danger' : 'text-muted',
     'icon' => 'fa-solid fa-triangle-exclamation', 'color' => 'danger', 'modal' => 'statOverdueModal', 'aria' => 'Show who is overdue'],
    ['label' => 'Paid Payments', 'value' => $paidCount, 'sub' => 'This month', 'subClass' => 'text-success',
     'icon' => 'fa-regular fa-circle-check', 'color' => 'success', 'modal' => 'statPaidModal', 'aria' => "Show this month's paid payments"],
]) ?>

<!-- Payments list: the full width of the page -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white border-0 p-3 pb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="fw-bold text-dark mb-0" style="font-size:0.85rem;"><i class="fa-solid fa-list text-primary me-2"></i>Payments List</h6>
            <small class="text-muted" style="font-size:0.7rem;">GCash payments are made outside the system: check the reference number and screenshot, then verify or reject.</small>
        </div>
        <?php if ($pendingCount > 0 && $filterStatus !== 'pending'): ?>
            <a href="payments.php?status=pending" class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle text-decoration-none px-3 py-2" style="font-size:0.72rem;">
                <i class="fa-solid fa-hourglass-half me-1"></i><?= $pendingCount ?> waiting for verification
            </a>
        <?php endif; ?>
    </div>

    <!-- Filter Bar -->
    <form method="GET" id="filterForm" class="d-flex flex-wrap gap-2 px-3 pb-3 align-items-center border-bottom">
        <div class="input-group input-group-sm rounded-2 border bg-white flex-grow-1" style="max-width:320px;">
            <span class="input-group-text bg-transparent border-0 pe-1"><i class="fa-solid fa-magnifying-glass text-muted" style="font-size:0.7rem;"></i></span>
            <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($filterSearch) ?>" class="form-control border-0 shadow-none px-1" placeholder="Search tenant name or reference..." style="font-size:0.75rem;">
        </div>
        <select name="status" class="form-select form-select-sm border rounded-2 shadow-none" style="width:150px; font-size:0.75rem;" onchange="this.form.submit()" aria-label="Status">
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All statuses</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="verified" <?= $filterStatus === 'verified' ? 'selected' : '' ?>>Verified</option>
            <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
        <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" class="form-control form-control-sm border rounded-2 shadow-none" style="width:150px; font-size:0.75rem;" onchange="this.form.submit()" aria-label="Payment date">
        <button type="submit" class="btn btn-sm btn-primary rounded-2 px-3" style="font-size:0.72rem;"><i class="fa-solid fa-magnifying-glass me-1"></i> Search</button>
        <?php if ($filterSearch !== '' || $filterStatus !== 'all' || $filterDate !== ''): ?>
            <a href="payments.php" class="btn btn-link btn-sm text-decoration-none fw-semibold ms-auto" style="font-size:0.72rem;"><i class="fa-solid fa-rotate-right me-1"></i> Clear filters</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
        <table id="paymentsTable" class="table payments-table align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Date</th>
                    <th>Tenant</th>
                    <th>Room</th>
                    <th class="text-end">Amount</th>
                    <th>Method</th>
                    <th>Reference No.</th>
                    <th class="text-center">Proof</th>
                    <th>Status</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($payments) === 0): ?>
                    <tr><td colspan="9" class="text-center py-5 text-muted">No payments found.</td></tr>
                <?php endif; ?>

                <?php foreach ($payments as $p):
                    $method = $p['payment_method'] ?? 'gcash';
                    $isCovered = stripos($method, 'covered by') === 0;
                    $ref = (string)($p['reference_number'] ?? '');
                    [$statusText, $statusColor, $statusIcon] = match ($p['status']) {
                        'verified' => ['Verified', 'success', 'fa-circle-check'],
                        'rejected' => ['Rejected', 'danger', 'fa-circle-xmark'],
                        default    => ['Pending', 'warning', 'fa-hourglass-half'],
                    };
                    $proof = uploadSrc($p['screenshot_path'] ?? '', '../');
                    $receipt = uploadSrc($p['receipt_path'] ?? '', '../');
                ?>
                <tr class="payment-row">
                    <td class="ps-3 text-nowrap">
                        <div class="fw-semibold text-dark"><?= date('M j, Y', strtotime($p['payment_date'])) ?></div>
                        <div class="cell-sub"><?= date('l', strtotime($p['payment_date'])) ?></div>
                    </td>
                    <td>
                        <a href="tenant_details.php?id=<?= (int)$p['tenant_id'] ?>" class="d-flex align-items-center text-decoration-none">
                            <?= avatarHtml($p['first_name'] . ' ' . $p['last_name'], 28, 'me-2 shadow-sm', $p['profile_picture'] ?? null, '../') ?>
                            <span>
                                <span class="d-block fw-bold text-dark text-nowrap"><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></span>
                                <span class="d-block cell-sub text-nowrap"><?= htmlspecialchars($p['contact_number'] ?? '') ?></span>
                            </span>
                        </a>
                    </td>
                    <td class="text-nowrap"><?= $p['room_number'] ? 'Room ' . htmlspecialchars($p['room_number']) : '<span class="text-muted fst-italic">Moved out</span>' ?></td>
                    <td class="text-end text-nowrap">
                        <div class="fw-bold text-dark">₱<?= number_format((float)$p['amount'], 2) ?></div>
                        <?php if (!empty($p['pay_for_room'])): ?><div class="cell-sub">Whole room</div><?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($isCovered): ?>
                            <span class="method-pill bg-light text-muted" title="Part of a roommate's payment"><i class="fa-solid fa-people-arrows me-1"></i><?= htmlspecialchars($method) ?></span>
                        <?php elseif ($method === 'cash'): ?>
                            <span class="method-pill bg-success-subtle text-success"><i class="fa-solid fa-money-bill-wave me-1"></i>Cash</span>
                        <?php else: ?>
                            <span class="method-pill bg-primary-subtle text-primary"><i class="fa-solid fa-mobile-screen me-1"></i>GCash</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($ref === '' || str_starts_with($ref, 'CASH-')): ?>
                            <span class="text-muted">—</span>
                        <?php elseif ($ref === 'MOVE-IN'): ?>
                            <span class="text-muted">Move-in</span>
                        <?php else: ?>
                            <span class="ref-no"><?= htmlspecialchars($ref) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($proof): ?>
                            <a href="<?= htmlspecialchars($proof) ?>" target="_blank" rel="noopener" title="Open the screenshot" class="d-inline-block">
                                <span class="screenshot-thumb" style="background-image: url('<?= htmlspecialchars($proof, ENT_QUOTES) ?>');"></span>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge rounded-pill bg-<?= $statusColor ?>-subtle text-<?= $statusColor ?> border border-<?= $statusColor ?>-subtle px-2 py-1"><i class="fa-solid <?= $statusIcon ?> me-1"></i><?= $statusText ?></span></td>
                    <td class="text-end pe-3 text-nowrap">
                        <?php if ($p['status'] === 'pending'): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="action" value="verify">
                                <button type="submit" class="btn btn-sm btn-success px-2 py-1" onclick="return confirm('Verify this payment of ₱<?= number_format((float)$p['amount'], 2) ?>?');"><i class="fa-solid fa-check me-1"></i>Verify</button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-1" title="Reject" aria-label="Reject" onclick="return confirm('Reject this payment?');"><i class="fa-solid fa-xmark"></i></button>
                            </form>
                        <?php elseif ($receipt): ?>
                            <a href="<?= htmlspecialchars($receipt) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-light border px-2 py-1"><i class="fa-solid fa-receipt me-1 text-primary"></i>Receipt</a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white border-top p-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted" style="font-size:0.72rem;"><?= paginationSummary($pager, 'payments') ?></span>
        <nav>
            <ul class="pagination pagination-sm mb-0"><?= paginationControls($pager) ?></ul>
        </nav>
    </div>
</div>

<script>
// Typing in the search box submits the form after a short pause, so the server can filter.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('filterForm');
    const search = document.getElementById('searchInput');
    if (!form || !search) return;
    let timer;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(() => form.submit(), 400);
    });
});
</script>

<?php
// A tenant's name linking to their details page, with their photo.
$tenantCell = function (array $t): string {
    $name = $t['first_name'] . ' ' . $t['last_name'];
    return '<div class="d-flex align-items-center">' . avatarHtml($name, 26, 'me-2 shadow-sm', $t['profile_picture'] ?? null, '../')
         . '<a href="tenant_details.php?id=' . (int)($t['tenant_id'] ?? $t['id']) . '" class="fw-semibold text-dark text-decoration-none text-nowrap">' . htmlspecialchars($name) . '</a></div>';
};
$roomLabel = fn($room) => $room !== null ? 'Room ' . htmlspecialchars($room) : '<span class="text-muted fst-italic">Moved out</span>';
$monthLabel = date('F Y');
?>

<!-- Total Collected: where this month's money came from -->
<div class="modal fade" id="statCollectedModal" tabindex="-1" aria-labelledby="statCollectedTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold" id="statCollectedTitle"><i class="fa-solid fa-wallet text-primary me-2"></i>Total Collected · <?= $monthLabel ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-sm-4"><div class="border rounded-3 p-2 h-100">
                        <div class="text-muted" style="font-size:0.68rem;">This month</div>
                        <div class="fw-bold fs-5 text-dark">₱<?= number_format($totalCollected, 2) ?></div>
                        <div class="text-muted" style="font-size:0.68rem;"><?= count($monthPayments) ?> payment<?= count($monthPayments) === 1 ? '' : 's' ?></div>
                    </div></div>
                    <div class="col-sm-4"><div class="border rounded-3 p-2 h-100">
                        <div class="text-muted" style="font-size:0.68rem;">Last month</div>
                        <div class="fw-bold fs-5 text-dark">₱<?= number_format($lastMonthCollected, 2) ?></div>
                        <div class="<?= $totalCollected >= $lastMonthCollected ? 'text-success' : 'text-danger' ?>" style="font-size:0.68rem;"><?= htmlspecialchars($collectedTrend) ?></div>
                    </div></div>
                    <div class="col-sm-4"><div class="border rounded-3 p-2 h-100">
                        <div class="text-muted mb-1" style="font-size:0.68rem;">By method</div>
                        <?php if (!$collectedByMethod): ?><div class="text-muted small">Nothing yet</div><?php endif; ?>
                        <?php foreach ($collectedByMethod as $method => $amt): ?>
                            <div class="d-flex justify-content-between" style="font-size:0.78rem;"><span><?= $method ?></span><span class="fw-bold">₱<?= number_format($amt, 2) ?></span></div>
                        <?php endforeach; ?>
                    </div></div>
                </div>
                <div class="fw-semibold text-dark mb-2" style="font-size:0.8rem;">By room</div>
                <?php if (!$collectedByRoom): ?>
                    <div class="text-center text-muted py-4 small">No payments verified yet this month.</div>
                <?php else: ?>
                    <?php foreach ($collectedByRoom as $room => $amt): $pctOf = $totalCollected > 0 ? round($amt / $totalCollected * 100) : 0; ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between" style="font-size:0.78rem;">
                                <span class="fw-semibold text-dark"><?= htmlspecialchars($room) ?></span>
                                <span><span class="fw-bold">₱<?= number_format($amt, 2) ?></span> <span class="text-muted">(<?= $pctOf ?>%)</span></span>
                            </div>
                            <div class="progress rounded-pill bg-light" style="height: 5px;"><div class="progress-bar bg-primary" style="width: <?= $pctOf ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="text-muted mt-3" style="font-size:0.68rem;">Counts verified payments dated this month. A payment that also covered a roommate counts once, under the tenant who paid. Click <strong>Paid Payments</strong> for the individual payments.</div>
            </div>
        </div>
    </div>
</div>

<!-- Due Soon: rent posted but not yet due -->
<div class="modal fade" id="statDueModal" tabindex="-1" aria-labelledby="statDueTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold" id="statDueTitle"><i class="fa-regular fa-clock text-warning me-2"></i>Due Soon · ₱<?= number_format($dueSoonAmount, 2) ?> from <?= $dueSoonCount ?> tenant<?= $dueSoonCount === 1 ? '' : 's' ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">Bills that are posted but not late yet, soonest first.</p>
                <?php if (!$dueSoonList): ?>
                    <div class="text-center text-muted py-4 small"><i class="fa-regular fa-circle-check text-success fa-2x d-block mb-2"></i>Nothing coming due.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 stat-table">
                        <thead><tr><th>Tenant</th><th>Room</th><th>Due</th><th class="text-end">Amount</th><th>Contact</th></tr></thead>
                        <tbody>
                        <?php foreach ($dueSoonList as $d): ?>
                            <tr>
                                <td><?= $tenantCell($d) ?></td>
                                <td class="text-muted text-nowrap"><?= $roomLabel($d['room_number']) ?></td>
                                <td class="text-nowrap"><?= $d['due'] ? '<span class="fw-semibold">' . htmlspecialchars(dueDateLabel($d['due'])) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end fw-bold text-nowrap">₱<?= number_format($d['amount'], 2) ?></td>
                                <td class="text-muted text-nowrap"><?= htmlspecialchars($d['contact_number'] ?? '') ?: '<span class="fst-italic">None</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($dueSoonList): ?>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#remindersModal"><i class="fa-solid fa-comment-sms me-1"></i>Send Rent Reminders</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Overdue: past their due date -->
<div class="modal fade" id="statOverdueModal" tabindex="-1" aria-labelledby="statOverdueTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold" id="statOverdueTitle"><i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Overdue · ₱<?= number_format($actualOverdueAmount, 2) ?> from <?= $actualOverdueCount ?> tenant<?= $actualOverdueCount === 1 ? '' : 's' ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">Unpaid amounts past their due date, largest first.</p>
                <?php if (!$overdueList): ?>
                    <div class="text-center text-muted py-4 small"><i class="fa-regular fa-circle-check text-success fa-2x d-block mb-2"></i>Nobody is overdue.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 stat-table">
                        <thead><tr><th>Tenant</th><th>Room</th><th>Overdue since</th><th class="text-end">Overdue</th><th class="text-end">Total owed</th><th>Contact</th></tr></thead>
                        <tbody>
                        <?php foreach ($overdueList as $o): ?>
                            <tr>
                                <td><?= $tenantCell($o) ?>
                                    <?php if (($o['status'] ?? 'active') === 'deactivated'): ?><span class="badge badge-soft-secondary ms-1">Moved out</span><?php endif; ?>
                                </td>
                                <td class="text-muted text-nowrap"><?= $roomLabel($o['room_number']) ?></td>
                                <td class="text-nowrap"><?= $o['since'] ? date('M j, Y', strtotime($o['since'])) . ' <span class="text-danger small">(' . $o['days'] . ' day' . ($o['days'] === 1 ? '' : 's') . ')</span>' : '—' ?></td>
                                <td class="text-end fw-bold text-danger text-nowrap">₱<?= number_format($o['overdue'], 2) ?></td>
                                <td class="text-end text-nowrap">₱<?= number_format($o['total'], 2) ?></td>
                                <td class="text-muted text-nowrap"><?= htmlspecialchars($o['contact_number'] ?? '') ?: '<span class="fst-italic">None</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($overdueList): ?>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" data-bs-toggle="modal" data-bs-target="#remindersModal"><i class="fa-solid fa-comment-sms me-1"></i>Send Rent Reminders</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Paid Payments: each payment verified this month -->
<div class="modal fade" id="statPaidModal" tabindex="-1" aria-labelledby="statPaidTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold" id="statPaidTitle"><i class="fa-regular fa-circle-check text-success me-2"></i>Paid Payments · <?= $monthLabel ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (!$monthPayments): ?>
                    <div class="text-center text-muted py-4 small">No payments verified yet this month.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 stat-table">
                        <thead><tr><th>Date</th><th>Tenant</th><th>Room</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($monthPayments as $mp): $receipt = uploadSrc($mp['receipt_path'] ?? '', '../'); ?>
                            <tr>
                                <td class="text-nowrap text-muted"><?= date('M j', strtotime($mp['payment_date'])) ?></td>
                                <td><?= $tenantCell($mp) ?></td>
                                <td class="text-muted text-nowrap"><?= $roomLabel($mp['room_number']) ?></td>
                                <td class="text-nowrap"><?= ($mp['payment_method'] ?? 'gcash') === 'cash' ? 'Cash' : 'GCash' ?><?= !empty($mp['pay_for_room']) ? ' <span class="badge badge-soft-primary">Whole room</span>' : '' ?></td>
                                <td class="text-muted text-truncate" style="max-width: 130px;"><?= htmlspecialchars($mp['reference_number'] ?? '') ?></td>
                                <td class="text-end fw-bold text-nowrap">₱<?= number_format($mp['amount'], 2) ?></td>
                                <td class="text-end"><?php if ($receipt): ?><a href="<?= htmlspecialchars($receipt) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-light border py-0 px-2" title="Open receipt"><i class="fa-solid fa-file-invoice"></i></a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr class="fw-bold"><td colspan="5" class="text-end">Total</td><td class="text-end text-nowrap">₱<?= number_format($totalCollected, 2) ?></td><td></td></tr></tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Rent reminders by SMS -->
<div class="modal fade" id="remindersModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-comment-sms text-primary me-2"></i>Rent Reminders</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size: 0.85rem;">
                <p class="text-muted small mb-3">
                    Texts tenants whose rent is due within <?= REMINDER_DAYS_BEFORE ?> days, and tenants with an overdue balance
                    (at most once a week). Nobody gets the same reminder twice, so this is safe to press again.
                </p>
                <?php if (!$smsReady): ?>
                    <div class="alert alert-warning py-2 small"><i class="fa-solid fa-triangle-exclamation me-1"></i>
                        SMS isn't set up yet, so nothing will actually be sent. Add your PhilSMS API token in <a href="settings.php#sms">Settings</a>.</div>
                <?php endif; ?>
                <?php if (empty($pendingReminders)): ?>
                    <div class="text-center text-muted py-4"><i class="fa-regular fa-circle-check fa-2x d-block mb-2 text-success"></i>Nobody needs a reminder right now.</div>
                <?php else: ?>
                    <?php foreach ($pendingReminders as $r): ?>
                    <div class="border rounded-3 p-2 mb-2">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-1">
                            <span class="fw-bold text-dark"><?= htmlspecialchars($r['name']) ?>
                                <span class="text-muted fw-normal small ms-1"><?= htmlspecialchars(($n = normalizePhMobile($r['phone'])) ? formatPhMobile($n) : ($r['phone'] ?: 'no number')) ?></span></span>
                            <?php if ($r['kind'] === 'overdue'): ?>
                                <span class="badge badge-soft-danger">Overdue · PHP <?= number_format($r['amount'], 2) ?></span>
                            <?php else: ?>
                                <span class="badge badge-soft-warning">Due soon · PHP <?= number_format($r['amount'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small"><?= htmlspecialchars($r['message']) ?></div>
                        <?php if (!normalizePhMobile($r['phone'])): ?>
                            <div class="text-danger small mt-1"><i class="fa-solid fa-circle-exclamation me-1"></i>Can't be texted: add a valid mobile number to this tenant.</div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light fw-semibold" data-bs-dismiss="modal">Close</button>
                <?php if ($pendingReminders): ?>
                <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="send_reminders">
                    <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-paper-plane me-1"></i>Send <?= count($pendingReminders) ?> Reminder<?= count($pendingReminders) === 1 ? '' : 's' ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
