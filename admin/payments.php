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
require_once '../includes/sms_templates.php';

// --- ACTION HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminders') {
    // Rent reminders by SMS: due within a few days, or overdue. Never repeats one already sent.
    require_once '../includes/sms_reminders.php';
    $r = sendRentReminders($pdo);
    if ($r['sent'] + $r['failed'] + $r['skipped'] === 0) {
        $success = "Nobody needs a reminder right now. Everyone with rent due in the next " . REMINDER_DAYS_BEFORE . " days or overdue has already been reminded.";
    } elseif ($r['sent'] > 0 && $r['failed'] + $r['skipped'] === 0) {
        $success = "Rent reminders sent: {$r['due']} due soon or today, {$r['overdue']} overdue.";
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
            $insCoveredSql = "INSERT INTO payments (tenant_id, amount, payment_date, reference_number, screenshot_path, payment_method, status, pay_for_room, covered_by_payment_id, created_at)
                              VALUES (?, ?, ?, ?, ?, ?, 'verified', ?, ?, ?)";
            foreach ($boardmateShares as $s) {
                $bm = $s['bm'];
                $subtract->execute([$s['share'], $bm['id']]);

                // Record of the covered share for the boardmate's portal. Linked to the real payment,
                // so it isn't counted as extra revenue.
                $newPaymentId = insertReturningId($pdo, $insCoveredSql, [
                    $bm['id'], $s['share'], $payment['payment_date'], $payment['reference_number'],
                    $payment['screenshot_path'], $payMethodStr, dbBool(false), $paymentId, $payment['created_at'] ?? date('Y-m-d H:i:s')
                ]);

                $bmFullName = $bm['first_name'] . ' ' . $bm['last_name'];
                $proxyReceiptPath = generateReceipt($newPaymentId, $bmFullName, $s['share'], $payment['payment_date'], $payment['reference_number'], $payMethodStr);
                $pdo->prepare("UPDATE payments SET receipt_path = ? WHERE id = ?")->execute([$proxyReceiptPath, $newPaymentId]);

                $smsQueue[] = [$bm['contact_number'], (int)$bm['id'], 'payment_covered',
                    smsHeader($pdo) . "\n\nHello, $bmFullName! Your rent share of " . smsPeso($s['share']) . ' was paid by ' . $payment['first_name']
                    . " as part of a payment for your room, and it has been verified.\n\nRemaining Balance: " . smsPeso(max(0, (float)$bm['balance'] - $s['share']))
                    . "\n\nThank you!"];
            }

            // Generate Receipt PDF
            $fullName = $payment['first_name'] . ' ' . $payment['last_name'];
            $receiptPath = generateReceipt($paymentId, $fullName, $amount, $payment['payment_date'], $payment['reference_number'], $payment['payment_method'] ?? 'gcash');
            $pdo->prepare("UPDATE payments SET receipt_path = ? WHERE id = ?")->execute([$receiptPath, $paymentId]);

            $pdo->commit();

            // SMS only after the money is committed.
            $remaining = $pdo->prepare("SELECT balance FROM tenants WHERE id = ?");
            $remaining->execute([$payment['tenant_id']]);
            $smsQueue[] = [$payment['contact_number'], (int)$payment['tenant_id'], 'payment_verified',
                smsPaymentVerifiedMessage($pdo, $fullName, $amount, paymentMethodLabel($payment['payment_method'] ?? 'gcash'),
                                          $payment['payment_date'], (float)$remaining->fetchColumn())];
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
            $msg = smsPaymentRejectedMessage($pdo, $payment['first_name'] . ' ' . $payment['last_name'], (float)$payment['amount'],
                                             str_starts_with((string)$payment['reference_number'], 'CASH-') ? '' : (string)$payment['reference_number']);
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
$filterStatus = in_array(queryParam('status'), ['pending', 'verified', 'rejected'], true) ? queryParam('status') : 'all';
$filterMethod = in_array(queryParam('method'), ['gcash', 'cash'], true) ? queryParam('method') : 'all';
$validDate = function (string $d): string {
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d ? $d : '';
};
$filterFrom = $validDate(queryParam('from'));
$filterTo = $validDate(queryParam('to'));
if ($filterFrom === '' && $filterTo === '' && $validDate(queryParam('date')) !== '') {
    $filterFrom = $filterTo = queryParam('date');   // older links filtered on one day
}

$where = [];
$params = [];

if ($filterSearch !== '') {
    $where[] = "(LOWER(t.first_name) LIKE ? OR LOWER(t.last_name) LIKE ?
                 OR LOWER(CONCAT(t.first_name, ' ', t.last_name)) LIKE ? OR LOWER(p.reference_number) LIKE ?
                 OR LOWER(r.room_number) LIKE ?)";
    $like = '%' . strtolower($filterSearch) . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($filterStatus !== 'all') {
    $where[] = "p.status = ?";
    $params[] = $filterStatus;
}
if ($filterMethod !== 'all') {
    $where[] = "p.payment_method = ?";
    $params[] = $filterMethod;
}
if ($filterFrom !== '') {
    $where[] = "p.payment_date >= ?";
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $where[] = "p.payment_date <= ?";
    $params[] = $filterTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$filtered = $filterSearch !== '' || $filterStatus !== 'all' || $filterMethod !== 'all' || $filterFrom !== '' || $filterTo !== '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM payments p JOIN tenants t ON p.tenant_id = t.id LEFT JOIN rooms r ON t.room_id = r.id $whereSql");
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

// Right-hand column: this month's money by method, and the latest payments entered.
$countByMethod = [];
foreach ($monthPayments as $mp) {
    $label = paymentMethodLabel($mp['payment_method'] ?? 'gcash');
    $countByMethod[$label] = ($countByMethod[$label] ?? 0) + 1;
}
$recentActivity = $pdo->query("SELECT p.*, t.first_name, t.last_name FROM payments p JOIN tenants t ON t.id = p.tenant_id
                               WHERE p.covered_by_payment_id IS NULL ORDER BY p.id DESC LIMIT 4")->fetchAll();

/** "Paid" / "Pending Verification" / "Rejected", with its colour. */
function paymentStatusLabel(string $status): array {
    return match ($status) {
        'verified' => ['Paid', 'success'],
        'rejected' => ['Rejected', 'danger'],
        default    => ['Pending Verification', 'warning'],
    };
}

/** The date as "Oct 03, 2026" and, under it, the time it was entered (older payments: the weekday). */
function paymentWhen(array $p): array {
    return [date('M d, Y', strtotime($p['payment_date'])),
            !empty($p['created_at']) ? date('h:i A', strtotime($p['created_at'])) : date('l', strtotime($p['payment_date']))];
}

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
    .payments-table { font-size: 0.8rem; }
    .payments-table>thead>tr>th { font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 600; background: #f8fafc; border-bottom: 0; padding: 0.75rem 0.6rem; white-space: nowrap; }
    .payments-table>tbody>tr>td { padding: 0.7rem 0.5rem; border-bottom: 1px solid #f1f5f9; }
    .payments-table>thead>tr>th { padding: 0.75rem 0.5rem; }
    .payments-table tbody tr:hover { background-color: #f8fafc; }
    .payments-table .cell-sub { font-size: 0.7rem; color: #94a3b8; margin-top: 2px; }
    .method-pill { display: inline-flex; align-items: center; border-radius: 8px; padding: 0.25rem 0.55rem; font-size: 0.7rem; font-weight: 600; line-height: 1.2; text-align: left; }
    .status-pill { display: inline-block; border-radius: 8px; padding: 0.25rem 0.55rem; font-size: 0.7rem; font-weight: 600; line-height: 1.25; text-align: center; }
    .payments-table .status-pill { max-width: 92px; }
    .ref-no { font-size: 0.76rem; color: #334155; }
    .btn-view { background: #eff6ff; color: #2563eb; border: 0; font-weight: 600; }
    .btn-view:hover { background: #dbeafe; color: #1d4ed8; }
    .chart-container-donut { position: relative; height: 120px; width: 120px; }
    .donut-center-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
    .side-row { display: flex; justify-content: space-between; align-items: center; padding: 0.35rem 0; font-size: 0.8rem; }
    .method-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
    .activity-row { display: flex; gap: 0.6rem; padding: 0.55rem 0; border-bottom: 1px solid #f1f5f9; }
    .activity-row:last-child { border-bottom: 0; }
    .proof-box { height: 280px; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; overflow: hidden; }
    .pm-list { display: grid; grid-template-columns: 110px 1fr; row-gap: 0.45rem; font-size: 0.82rem; }
    .pm-list dt { color: #64748b; font-weight: 500; }
    .pm-list dd { margin: 0; color: #0f172a; font-weight: 600; }

    .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 8px; }

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
    <p class="text-muted mb-0" style="font-size: 0.85rem;">Track and manage tenant payments and verify GCash transactions.</p>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary fw-semibold px-3 shadow-sm" style="font-size:0.8rem;" data-bs-toggle="modal" data-bs-target="#remindersModal">
            <i class="fa-solid fa-paper-plane me-1"></i> Send Rent Reminders<?php if ($pendingReminders): ?> <span class="badge bg-white text-primary rounded-pill ms-1"><?= count($pendingReminders) ?></span><?php endif; ?>
        </button>
        <a href="export_payments.php" class="btn btn-light border bg-white fw-semibold px-3 shadow-sm" style="font-size:0.8rem;"><i class="fa-solid fa-download me-1"></i> Export Data</a>
    </div>
</div>

<!-- Top Stats Row: each card opens the list behind its figure -->
<?= statCards([
    ['label' => 'Total Collected (This Month)', 'value' => peso((float)$totalCollected), 'sub' => $collectedTrend,
     'subClass' => $totalCollected >= $lastMonthCollected ? 'text-success' : 'text-danger',
     'icon' => 'fa-solid fa-wallet', 'color' => 'success', 'modal' => 'statCollectedModal', 'aria' => "Show how this month's collections break down"],
    ['label' => 'Due Soon', 'value' => peso((float)$dueSoonAmount),
     'sub' => $dueSoonCount . ' ' . ($dueSoonCount == 1 ? 'tenant' : 'tenants') . ' · due ' . date('M j', strtotime(billingDueDate(date('Y-m')))), 'subClass' => 'text-muted',
     'icon' => 'fa-regular fa-clock', 'color' => 'primary', 'modal' => 'statDueModal', 'aria' => 'Show who has rent coming due'],
    ['label' => 'Overdue', 'value' => peso((float)$actualOverdueAmount),
     'sub' => $actualOverdueCount . ' ' . ($actualOverdueCount == 1 ? 'tenant' : 'tenants'), 'subClass' => $actualOverdueCount ? 'text-danger' : 'text-muted',
     'icon' => 'fa-solid fa-triangle-exclamation', 'color' => 'danger', 'modal' => 'statOverdueModal', 'aria' => 'Show who is overdue'],
    ['label' => 'Payments Received', 'value' => $paidCount, 'sub' => 'This month',
     'icon' => 'fa-regular fa-circle-check', 'color' => 'info', 'modal' => 'statPaidModal', 'aria' => "Show this month's payments"],
]) ?>

<div class="row g-3">
    <!-- Left: filters, the list, and the verification note -->
    <div class="col-xxl-8">
        <form method="GET" id="filterForm" class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-body p-3">
                <div class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <div class="input-group input-group-sm border rounded-2 bg-white">
                            <span class="input-group-text bg-transparent border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                            <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($filterSearch) ?>" class="form-control border-0 shadow-none ps-0" placeholder="Search tenant name, room, or reference..." style="font-size:0.8rem;">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="status" class="form-select form-select-sm" style="font-size:0.8rem;" onchange="this.form.submit()" aria-label="Status">
                            <option value="all">All Status</option>
                            <option value="verified" <?= $filterStatus === 'verified' ? 'selected' : '' ?>>Paid</option>
                            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending Verification</option>
                            <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <select name="method" class="form-select form-select-sm" style="font-size:0.8rem;" onchange="this.form.submit()" aria-label="Method">
                            <option value="all">All Methods</option>
                            <option value="cash" <?= $filterMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="gcash" <?= $filterMethod === 'gcash' ? 'selected' : '' ?>>GCash (Manual)</option>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white"><i class="fa-regular fa-calendar text-muted"></i></span>
                            <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="form-control" aria-label="Start date" title="Start date" style="font-size:0.8rem;">
                            <span class="input-group-text bg-white text-muted" style="font-size:0.75rem;">to</span>
                            <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="form-control" aria-label="End date" title="End date" style="font-size:0.8rem;">
                        </div>
                    </div>
                    <div class="col-md-5 d-flex justify-content-end align-items-center gap-3">
                        <?php if ($filtered): ?>
                            <a href="payments.php" class="text-decoration-none fw-semibold" style="font-size:0.78rem;"><i class="fa-solid fa-rotate-right me-1"></i>Clear Filters</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm btn-primary px-3" style="font-size:0.8rem;"><i class="fa-solid fa-magnifying-glass me-1"></i> Search</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card border-0 shadow-sm rounded-3 mb-3">
            <div class="card-header bg-white border-0 p-3 pb-2 d-flex align-items-center gap-2">
                <i class="fa-regular fa-credit-card text-primary fs-5"></i>
                <h6 class="fw-bold text-dark mb-0" style="font-size:0.95rem;">Payments List</h6>
            </div>
            <div class="table-responsive px-2">
                <table id="paymentsTable" class="table payments-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Date</th>
                            <th>Tenant</th>
                            <th>Room</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th class="pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($payments) === 0): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No payments found.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($payments as $p):
                            $method = $p['payment_method'] ?? 'gcash';
                            $isCovered = stripos($method, 'covered by') === 0;
                            $ref = (string)($p['reference_number'] ?? '');
                            $refText = ($ref === '' || str_starts_with($ref, 'CASH-')) ? '-' : ($ref === 'MOVE-IN' ? 'Move-in' : $ref);
                            [$statusText, $statusColor] = paymentStatusLabel($p['status']);
                            [$dateText, $timeText] = paymentWhen($p);
                            $name = $p['first_name'] . ' ' . $p['last_name'];
                            $details = htmlspecialchars(json_encode([
                                'id' => (int)$p['id'], 'tenant' => $name, 'tenant_id' => (int)$p['tenant_id'],
                                'phone' => $p['contact_number'] ?? '', 'room' => $p['room_number'] ? 'Room ' . $p['room_number'] : 'Moved out',
                                'amount' => '₱' . number_format((float)$p['amount'], 2), 'date' => $dateText, 'time' => $timeText,
                                'method' => $isCovered ? $method : ($method === 'cash' ? 'Cash' : 'GCash (Manual)'),
                                'reference' => $refText, 'status' => $p['status'], 'status_text' => $statusText, 'status_color' => $statusColor,
                                'room_payment' => !empty($p['pay_for_room']),
                                'proof' => uploadSrc($p['screenshot_path'] ?? '', '../'), 'receipt' => uploadSrc($p['receipt_path'] ?? '', '../'),
                            ]), ENT_QUOTES);
                        ?>
                        <tr class="payment-row" data-payment="<?= $details ?>">
                            <td class="ps-3 text-nowrap">
                                <div class="text-dark"><?= $dateText ?></div>
                                <div class="cell-sub"><?= $timeText ?></div>
                            </td>
                            <td>
                                <a href="tenant_details.php?id=<?= (int)$p['tenant_id'] ?>" class="text-decoration-none">
                                    <div class="fw-semibold text-dark text-nowrap"><?= htmlspecialchars($name) ?></div>
                                    <?php if (!empty($p['contact_number'])): ?><div class="cell-sub text-nowrap"><i class="fa-solid fa-phone me-1" style="font-size:0.6rem;"></i><?= htmlspecialchars($p['contact_number']) ?></div><?php endif; ?>
                                </a>
                            </td>
                            <td class="text-nowrap"><?= $p['room_number'] ? 'Room ' . htmlspecialchars($p['room_number']) : '<span class="text-muted fst-italic">Moved out</span>' ?></td>
                            <td class="text-nowrap fw-semibold text-dark">₱<?= number_format((float)$p['amount'], 2) ?></td>
                            <td class="text-nowrap">
                                <?php if ($isCovered): ?>
                                    <span class="method-pill bg-light text-muted" title="Part of a roommate's payment"><i class="fa-solid fa-people-arrows me-1"></i>Roommate</span>
                                <?php elseif ($method === 'cash'): ?>
                                    <span class="method-pill bg-success-subtle text-success"><i class="fa-solid fa-money-bill-wave me-1"></i>Cash</span>
                                <?php else: ?>
                                    <span class="method-pill bg-primary-subtle text-primary" title="GCash (Manual)"><i class="fa-solid fa-g me-1"></i>GCash</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap ref-no"><?= htmlspecialchars($refText) ?></td>
                            <td><span class="status-pill bg-<?= $statusColor ?>-subtle text-<?= $statusColor ?>-emphasis"><?= $statusText ?></span></td>
                            <td class="pe-3 text-nowrap">
                                <?php if ($p['status'] === 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-primary px-3" onclick="openPayment(this)"><i class="fa-solid fa-check me-1"></i>Verify</button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-view px-3" onclick="openPayment(this)"><i class="fa-regular fa-eye me-1"></i>View</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-0 p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted" style="font-size:0.78rem;"><?= $pager['total'] ? str_replace(' to ', ' - ', paginationSummary($pager, 'payments')) : '' ?></span>
                <nav><ul class="pagination pagination-sm mb-0"><?= paginationControls($pager) ?></ul></nav>
            </div>
        </div>

        <!-- How verification works -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-3 d-flex align-items-center gap-3 flex-wrap flex-md-nowrap">
                <i class="fa-solid fa-shield-halved text-primary fs-3"></i>
                <div class="flex-grow-1" style="min-width: 220px;">
                    <div class="fw-bold text-dark" style="font-size:0.85rem;">Manual GCash Verification</div>
                    <div class="text-muted" style="font-size:0.75rem;">Review the transaction reference and proof of payment before marking a payment as verified.</div>
                </div>
                <a href="payments.php?status=pending" class="btn btn-primary btn-sm px-3 text-nowrap">View Pending Payments<?= $pendingCount ? " ($pendingCount)" : '' ?> <i class="fa-solid fa-chevron-right ms-1" style="font-size:0.65rem;"></i></a>
            </div>
        </div>
    </div>

    <!-- Right: this month at a glance (under the list on narrower screens) -->
    <div class="col-xxl-4">
        <div class="row g-3 row-cols-1 row-cols-md-3 row-cols-xxl-1">
        <div class="col">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.9rem;"><i class="fa-solid fa-chart-pie text-primary me-2"></i>Payment Overview</h6>
                <div class="d-flex align-items-center gap-3">
                    <div class="chart-container-donut flex-shrink-0">
                        <canvas id="paymentDonut" role="img" aria-label="Payments by status"></canvas>
                        <div class="donut-center-text">
                            <div class="fw-bold text-dark" style="font-size:0.9rem; line-height:1.1;"><?= peso((float)$totalCollected) ?></div>
                            <div class="text-muted" style="font-size:0.62rem;">Collected</div>
                        </div>
                    </div>
                    <div class="flex-grow-1">
                        <?php foreach ([['Paid', $paidCount, '#22c55e'], ['Due Soon', $dueSoonCount, '#3b82f6'], ['Overdue', $actualOverdueCount, '#ef4444'], ['Pending Verification', $pendingCount, '#f59e0b']] as [$label, $n, $color]): ?>
                        <div class="side-row">
                            <span class="text-dark"><span class="dot" style="background: <?= $color ?>;"></span><?= $label ?></span>
                            <span class="fw-bold text-dark"><?= $n ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        </div>
        <div class="col">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.9rem;"><i class="fa-regular fa-credit-card text-primary me-2"></i>Payment Method <span class="text-muted fw-normal" style="font-size:0.72rem;">· this month</span></h6>
                <?php if (!$collectedByMethod): ?>
                    <div class="text-muted" style="font-size:0.78rem;">No payments received yet this month.</div>
                <?php endif; ?>
                <?php foreach ($collectedByMethod as $label => $sum): $isCash = $label === 'Cash'; ?>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div class="method-icon <?= $isCash ? 'bg-success-subtle text-success' : 'bg-primary text-white' ?>"><i class="fa-solid <?= $isCash ? 'fa-money-bill-wave' : 'fa-g' ?>"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold text-dark" style="font-size:0.82rem;"><?= $isCash ? 'Cash' : 'GCash (Manual)' ?></div>
                        <div class="text-muted" style="font-size:0.7rem;"><?= $countByMethod[$label] ?? 0 ?> <?= ($countByMethod[$label] ?? 0) === 1 ? 'payment' : 'payments' ?></div>
                    </div>
                    <div class="fw-semibold text-dark" style="font-size:0.82rem;"><?= peso((float)$sum) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        </div>
        <div class="col">
        <div class="card border-0 shadow-sm rounded-3 h-100">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold text-dark mb-0" style="font-size:0.9rem;"><i class="fa-regular fa-bell text-primary me-2"></i>Recent Activity</h6>
                    <a href="payments.php" class="text-decoration-none" style="font-size:0.75rem;">View All</a>
                </div>
                <?php if (!$recentActivity): ?>
                    <div class="text-muted" style="font-size:0.78rem;">No payments yet.</div>
                <?php endif; ?>
                <?php foreach ($recentActivity as $a):
                    $who = $a['first_name'] . ' ' . $a['last_name'];
                    $how = paymentMethodLabel($a['payment_method'] ?? 'gcash');
                    [$what, $dot] = match ($a['status']) {
                        'verified' => ["Payment received from $who ($how)", 'success'],
                        'rejected' => ["Payment rejected ($who)", 'danger'],
                        default    => ["$how payment pending verification ($who)", 'warning'],
                    };
                    [$aDate, $aTime] = paymentWhen($a);
                ?>
                <div class="activity-row">
                    <span class="dot bg-<?= $dot ?> mt-1 flex-shrink-0"></span>
                    <div>
                        <div class="text-dark" style="font-size:0.78rem;"><?= htmlspecialchars($what) ?></div>
                        <div class="text-muted" style="font-size:0.68rem;"><?= $aDate ?> · <?= $aTime ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div>
        </div>
    </div>
</div>

<!-- One payment: proof, details, and Verify / Reject for pending ones -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold" id="paymentModalTitle"><i class="fa-solid fa-receipt text-primary me-2"></i>Payment Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <a id="pmProofLink" href="#" target="_blank" rel="noopener" class="d-block proof-box">
                            <img id="pmProof" alt="Proof of payment" class="w-100 h-100" style="object-fit: contain;">
                        </a>
                        <div id="pmNoProof" class="proof-box d-flex flex-column align-items-center justify-content-center text-muted">
                            <i class="fa-regular fa-image fs-2 mb-2 opacity-50"></i><span style="font-size:0.8rem;">No screenshot (cash payment)</span>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="fw-bold text-dark fs-5" id="pmAmount"></div>
                                <span class="status-pill" id="pmStatus"></span>
                            </div>
                        </div>
                        <dl class="pm-list mb-0">
                            <dt>Tenant</dt><dd><a href="#" id="pmTenant" class="text-decoration-none"></a></dd>
                            <dt>Contact</dt><dd id="pmPhone"></dd>
                            <dt>Room</dt><dd id="pmRoom"></dd>
                            <dt>Payment date</dt><dd id="pmDate"></dd>
                            <dt>Method</dt><dd id="pmMethod"></dd>
                            <dt>Reference no.</dt><dd id="pmRef" class="ref-no"></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light d-flex justify-content-between">
                <a href="#" id="pmReceipt" target="_blank" rel="noopener" class="btn btn-light border btn-sm"><i class="fa-solid fa-receipt me-1 text-primary"></i>Open Receipt</a>
                <div class="d-flex gap-2 ms-auto" id="pmActions">
                    <form method="POST" class="m-0" onsubmit="return confirm('Reject this payment? The tenant is told by SMS to check it and submit again.');">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="payment_id" class="pm-id">
                        <button type="submit" class="btn btn-outline-danger btn-sm px-3"><i class="fa-solid fa-xmark me-1"></i>Reject</button>
                    </form>
                    <form method="POST" class="m-0" onsubmit="return confirm('Mark this payment as verified? A receipt is made and the tenant gets an SMS.');">
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="payment_id" class="pm-id">
                        <button type="submit" class="btn btn-success btn-sm px-3"><i class="fa-solid fa-check me-1"></i>Verify Payment</button>
                    </form>
                </div>
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal" id="pmClose">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function openPayment(el) {
    const p = JSON.parse(el.closest('[data-payment]').dataset.payment);
    document.getElementById('pmAmount').textContent = p.amount + (p.room_payment ? ' (whole room)' : '');
    const status = document.getElementById('pmStatus');
    status.textContent = p.status_text;
    status.className = 'status-pill bg-' + p.status_color + '-subtle text-' + p.status_color + '-emphasis';
    const tenant = document.getElementById('pmTenant');
    tenant.textContent = p.tenant;
    tenant.href = 'tenant_details.php?id=' + p.tenant_id;
    document.getElementById('pmPhone').textContent = p.phone || '-';
    document.getElementById('pmRoom').textContent = p.room;
    document.getElementById('pmDate').textContent = p.date + ' · ' + p.time;
    document.getElementById('pmMethod').textContent = p.method;
    document.getElementById('pmRef').textContent = p.reference;
    document.getElementById('pmProofLink').classList.toggle('d-none', !p.proof);
    document.getElementById('pmNoProof').classList.toggle('d-none', !!p.proof);
    if (p.proof) {
        document.getElementById('pmProofLink').href = p.proof;
        document.getElementById('pmProof').src = p.proof;
    }
    const receipt = document.getElementById('pmReceipt');
    receipt.classList.toggle('d-none', !p.receipt);
    if (p.receipt) receipt.href = p.receipt;
    const pending = p.status === 'pending';
    document.getElementById('pmActions').classList.toggle('d-none', !pending);
    document.getElementById('pmClose').classList.toggle('d-none', pending);
    document.querySelectorAll('#paymentModal .pm-id').forEach(i => i.value = p.id);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.Chart) {
        new Chart(document.getElementById('paymentDonut'), {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Due Soon', 'Overdue', 'Pending Verification'],
                datasets: [{
                    data: <?= json_encode([$paidCount, $dueSoonCount, $actualOverdueCount, $pendingCount]) ?>,
                    backgroundColor: ['#22c55e', '#3b82f6', '#ef4444', '#f59e0b'],
                    borderWidth: 0,
                    cutout: '74%'
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }
});
</script>

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
                    <?php $roomShares = percentShares($collectedByRoom, 0);   // add up to exactly 100% ?>
                    <?php foreach ($collectedByRoom as $room => $amt): $pctOf = (int)$roomShares[$room]; ?>
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
                            <?php elseif ($r['kind'] === 'due_today'): ?>
                                <span class="badge badge-soft-warning">Due today · PHP <?= number_format($r['amount'], 2) ?></span>
                            <?php else: ?>
                                <span class="badge badge-soft-warning">Due soon · PHP <?= number_format($r['amount'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small" style="white-space: pre-line;"><?= htmlspecialchars($r['message']) ?></div>
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
