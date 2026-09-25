<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/pdf_generator.php';
require_once '../includes/sms.php';
require_once '../includes/billing.php';
require_once '../includes/pagination.php';

// --- ACTION HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $paymentId = (int)$_POST['payment_id'];
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

                $smsQueue[] = [$bm['contact_number'], "Your rent of PHP " . number_format($s['share'], 2) . " was paid by " . $payment['first_name'] . ". Receipt: RCP-" . str_pad($newPaymentId, 6, '0', STR_PAD_LEFT)];
            }

            // Generate Receipt PDF
            $fullName = $payment['first_name'] . ' ' . $payment['last_name'];
            $receiptPath = generateReceipt($paymentId, $fullName, $amount, $payment['payment_date'], $payment['reference_number'], $payment['payment_method'] ?? 'gcash');
            $pdo->prepare("UPDATE payments SET receipt_path = ? WHERE id = ?")->execute([$receiptPath, $paymentId]);

            $pdo->commit();

            // SMS only after the money is committed.
            $smsQueue[] = [$payment['contact_number'], "Your payment of PHP " . number_format($amount, 2) . " has been VERIFIED. Receipt: RCP-" . str_pad($paymentId, 6, '0', STR_PAD_LEFT)];
            foreach ($smsQueue as [$to, $msg]) {
                sendSMS($to, $msg);
            }

            $success = "Payment verified successfully. Receipt generated and SMS sent.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error verifying payment: " . $e->getMessage();
        }
    } elseif ($action === 'reject') {
        $upd = $pdo->prepare("UPDATE payments SET status = 'rejected' WHERE id = ? AND status = 'pending'");
        $upd->execute([$paymentId]);
        if ($upd->rowCount() === 1) {
            $msg = "Your recent payment submission of PHP " . number_format($payment['amount'], 2) . " was REJECTED. Please contact admin.";
            sendSMS($payment['contact_number'], $msg);
            $success = "Payment rejected and SMS sent.";
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
$tenantsWithBal = $pdo->query("SELECT id, balance, status FROM tenants WHERE balance > 0")->fetchAll();
foreach ($tenantsWithBal as $t) {
    $bs = tenantBillingStatus($t, $notYetDue);
    if ($bs['overdue'] > 0) {
        $actualOverdueAmount += $bs['overdue'];
        $actualOverdueCount++;
    }
    $notDuePart = round($bs['balance'] - $bs['overdue'], 2);
    if ($notDuePart > 0) {
        $dueSoonAmount += $notDuePart;
        $dueSoonCount++;
    }
}
$chartTotal = max(1, $paidCount + $dueSoonCount + $actualOverdueCount + $pendingCount);

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

require_once 'header.php';
?>

<style>
    body { background-color: #f8fafc; }
    .metric-card { transition: transform 0.2s; border: 1px solid #f1f5f9; }
    .metric-card:hover { transform: translateY(-2px); box-shadow: 0 5px 10px -3px rgba(0,0,0,0.05); }
    
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
    
    .table-compact th { font-size: 0.55rem; text-transform: uppercase; color: #64748b; font-weight: 600; padding: 0.6rem 0.5rem; letter-spacing: 0.5px; }
    .table-compact td { font-size: 0.65rem; vertical-align: middle; padding: 0.5rem 0.5rem; border-bottom: 1px solid #f1f5f9; }
    .table-compact tbody tr:hover { background-color: #f8fafc; }
    
    .chart-container-donut { position: relative; height: 120px; width: 120px; }
    .donut-center-text {
        position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
        text-align: center;
    }
    
    .screenshot-thumb {
        width: 28px; height: 32px;
        background-color: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px;
        position: relative; display: inline-block;
    }
    .screenshot-verified {
        position: absolute; bottom: -4px; right: -4px;
        width: 12px; height: 12px; background-color: #22c55e; color: white;
        border-radius: 50%; font-size: 0.4rem; display: flex; align-items: center; justify-content: center;
        border: 1px solid white;
    }
    
    .status-double {
        display: flex; flex-direction: column; align-items: flex-start;
    }
    
    .info-box { background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 0.75rem; }
    .recent-activity-item { padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9; }
    .recent-activity-item:last-child { border-bottom: none; }
    
    .dot { width: 5px; height: 5px; border-radius: 50%; display: inline-block; margin-right: 5px; }
    
    /* GCash logo imitation */
    .gcash-logo { color: #005ce6; font-weight: 800; font-style: italic; letter-spacing: -0.5px; font-size: 0.7rem; }
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
        <h4 class="fw-bold mb-1 text-dark">Manage Payments</h4>
        <p class="text-muted mb-0" style="font-size: 0.75rem;">Track and manage all tenant payments and verify manual GCash payments.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="export_payments.php" class="btn btn-primary fw-semibold btn-sm px-2 py-1 rounded-2" style="font-size:0.75rem;"><i class="fa-solid fa-download me-1"></i> Export Data</a>
    </div>
</div>

<!-- Top Stats Row -->
<div class="row g-2 mb-3">
    <!-- Total Collected -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-primary bg-opacity-10 text-primary me-2 flex-shrink-0">
                    <i class="fa-solid fa-wallet"></i>
                </div>
                <div>
                    <div class="card-title-sm">Total Collected (This Month)</div>
                    <h5 class="fw-bold mb-0 text-dark">₱<?= number_format($totalCollected, 2) ?></h5>
                    <div class="trend-text <?= $totalCollected >= $lastMonthCollected ? 'text-success' : 'text-danger' ?> mt-1"><?= $collectedTrend ?></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Due Soon -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-warning bg-opacity-10 text-warning me-2 flex-shrink-0">
                    <i class="fa-regular fa-clock"></i>
                </div>
                <div>
                    <div class="card-title-sm">Due Soon</div>
                    <h5 class="fw-bold mb-0 text-dark">₱<?= number_format($dueSoonAmount, 2) ?></h5>
                    <div class="trend-text text-warning mt-1"><?= $dueSoonCount ?> tenants · due <?= date('M j', strtotime(billingDueDate(date('Y-m')))) ?></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Overdue -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-danger bg-opacity-10 text-danger me-2 flex-shrink-0">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <div class="card-title-sm">Overdue</div>
                    <h5 class="fw-bold mb-0 text-dark">₱<?= number_format($actualOverdueAmount, 2) ?></h5>
                    <div class="trend-text text-danger mt-1"><?= $actualOverdueCount ?> tenants</div>
                </div>
            </div>
        </div>
    </div>
    <!-- Paid Payments -->
    <div class="col-sm-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm h-100 rounded-3 p-1">
            <div class="card-body p-2 d-flex align-items-center">
                <div class="icon-box-lg bg-success bg-opacity-10 text-success me-2 flex-shrink-0">
                    <i class="fa-regular fa-circle-check"></i>
                </div>
                <div>
                    <div class="card-title-sm">Paid Payments</div>
                    <h5 class="fw-bold mb-0 text-dark"><?= $paidCount ?></h5>
                    <div class="trend-text text-success mt-1"><span class="dot bg-success"></span> This month</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Grid -->
<div class="row g-2">
    
    <!-- Left Column: Table -->
    <div class="col-lg-8">
                <!-- Filter Bar -->
        <form method="GET" id="filterForm" class="d-flex flex-wrap gap-2 mb-2 align-items-center bg-white p-2 rounded-3 shadow-sm border-0">
            <div class="input-group input-group-sm rounded-2 border flex-grow-1 bg-white" style="max-width:300px;">
                <span class="input-group-text bg-transparent border-0 pe-1"><i class="fa-solid fa-magnifying-glass text-muted" style="font-size:0.65rem;"></i></span>
                <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($filterSearch) ?>" class="form-control border-0 shadow-none px-1" placeholder="Search tenant name or reference..." style="font-size:0.7rem;">
            </div>
            <select name="status" class="form-select form-select-sm border rounded-2 shadow-none text-muted" style="width:110px; font-size:0.7rem;" onchange="this.form.submit()">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="verified" <?= $filterStatus === 'verified' ? 'selected' : '' ?>>Verified</option>
                <option value="unpaid" <?= $filterStatus === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
            </select>
            <div class="input-group input-group-sm rounded-2 border bg-white" style="width:140px;">
                <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>" class="form-control border-0 shadow-none px-2 text-muted" style="font-size:0.7rem;" onchange="this.form.submit()">
            </div>
            <button type="submit" class="btn btn-sm btn-primary rounded-2 px-2 py-1" style="font-size:0.65rem;"><i class="fa-solid fa-magnifying-glass me-1"></i> Search</button>
            <a href="payments.php" class="btn btn-link btn-sm text-primary text-decoration-none fw-semibold ms-auto" style="font-size:0.65rem;"><i class="fa-solid fa-rotate-right me-1"></i> Clear Filters</a>
        </form>
        
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white border-0 p-3 pb-0">
                <h6 class="fw-bold text-dark mb-0" style="font-size:0.75rem;"><i class="fa-solid fa-list text-primary me-2"></i> Payments List</h6>
            </div>
            <div class="card-body p-0 mt-2 overflow-auto">
                <table id="paymentsTable" class="table table-compact mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 border-0">Date</th>
                            <th class="border-0">Tenant</th>
                            <th class="border-0">Room</th>
                            <th class="border-0">Amount</th>
                            <th class="border-0">Payment Method</th>
                            <th class="border-0">Reference</th>
                            <th class="border-0">Status</th>
                            <th class="border-0 text-center">Screenshot</th>
                            <th class="border-0 text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($payments) === 0): ?>
                            <tr><td colspan="9" class="text-center py-5 text-muted">No payments found.</td></tr>
                        <?php endif; ?>
                        
                        <?php foreach($payments as $p): 
                            $date = date('M d, Y', strtotime($p['payment_date']));
                            $time = date('h:i A', strtotime($p['created_at'] ?? $p['payment_date']));
                            $isVerified = $p['status'] == 'verified';
                            
                            $statusTextTop = $isVerified ? 'Paid' : 'Pending';
                            $statusTextBot = $isVerified ? 'Verified' : 'Unpaid';
                            $statusColor = $isVerified ? 'success' : 'warning';
                        ?>
                        <tr class="payment-row" data-date="<?= date('Y-m-d', strtotime($p['payment_date'])) ?>">
                            <td class="ps-3">
                                <div class="text-dark fw-semibold" style="font-size:0.65rem;"><?= $date ?></div>
                                <div class="text-muted" style="font-size:0.55rem;"><?= $time ?></div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?= avatarHtml($p['first_name'] . ' ' . $p['last_name'], 22, 'me-2 shadow-sm', $p['profile_picture'] ?? null, '../') ?>
                                    <div>
                                        <div class="fw-bold text-dark" style="font-size:0.65rem; line-height:1.1;"><?= htmlspecialchars($p['first_name'].' '.$p['last_name']) ?></div>
                                        <div class="text-muted" style="font-size:0.55rem;"><?= htmlspecialchars($p['contact_number']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted fw-semibold" style="font-size:0.65rem;">Rm <?= htmlspecialchars($p['room_number']) ?></td>
                            <td class="fw-bold text-dark" style="font-size:0.65rem;">₱<?= number_format($p['amount'], 2) ?></td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="gcash-logo"><i class="fa-solid fa-g text-primary"></i>Cash</span>
                                    <span class="text-muted" style="font-size:0.55rem;">Manual</span>
                                </div>
                            </td>
                            <td class="text-muted" style="font-size:0.65rem;"><?= htmlspecialchars($p['reference_number'] ?: 'N/A') ?></td>
                            <td>
                                <div class="status-double">
                                    <span class="badge bg-<?= $statusColor ?>-subtle text-<?= $statusColor ?> border border-<?= $statusColor ?>-subtle px-1 py-0 mb-1 rounded-1" style="font-size:0.5rem;"><?= $statusTextTop ?></span>
                                    <span class="text-muted" style="font-size:0.55rem;"><?= $statusTextBot ?></span>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php if(!empty($p['screenshot_path'])): 
                                    $imgPath = $p['screenshot_path'];
                                    $isUrl = str_starts_with($imgPath, 'http');
                                    $imgSrc = $isUrl ? htmlspecialchars($imgPath) : '../' . htmlspecialchars($imgPath);
                                ?>
                                    <a href="<?= $imgSrc ?>" target="_blank" title="View Full Image">
                                        <div class="screenshot-thumb" style="background-image: url('<?= $imgSrc ?>'); background-size: cover; background-position: center; cursor: pointer;">
                                            <?php if($isVerified): ?><div class="screenshot-verified"><i class="fa-solid fa-check"></i></div><?php endif; ?>
                                        </div>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.6rem;">None</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-3">
                                <?php if ($p['status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="verify">
                                        <button type="submit" class="btn btn-sm btn-success rounded-2 px-1 py-0 me-1" title="Verify Payment" onclick="return confirm('Verify this payment?');"><i class="fa-solid fa-check" style="font-size:0.6rem;"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn btn-sm btn-danger rounded-2 px-1 py-0" title="Reject Payment" onclick="return confirm('Reject this payment?');"><i class="fa-solid fa-xmark" style="font-size:0.6rem;"></i></button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.65rem;">Done</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top p-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted" style="font-size:0.65rem;"><?= paginationSummary($pager, 'payments') ?></span>
                <nav>
                    <ul class="pagination pagination-sm mb-0 shadow-sm" style="font-size:0.65rem;"><?= paginationControls($pager) ?></ul>
                </nav>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Sidebar -->
    <div class="col-lg-4">
        
        <!-- Payment Overview Donut -->
        <div class="card border-0 shadow-sm rounded-3 mb-2">
            <div class="card-body p-3">
                <h6 class="fw-bold text-dark mb-3" style="font-size:0.75rem;">Payment Overview (This Month)</h6>
                
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="chart-container-donut ms-2">
                        <canvas id="paymentStatusChart"></canvas>
                        <div class="donut-center-text">
                            <div class="text-muted" style="font-size:0.5rem; font-weight:600;">Total</div>
                            <div class="fw-bold text-dark" style="font-size:1rem; line-height:1;"><?= $paidCount + $dueSoonCount + $actualOverdueCount + $pendingCount ?></div>
                            <div class="text-muted" style="font-size:0.5rem;">Items</div>
                        </div>
                    </div>
                    
                    <div class="ps-3 pe-2 flex-grow-1">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark" style="font-size:0.65rem;"><span class="dot bg-success"></span> Paid</span>
                            <span class="text-muted" style="font-size:0.6rem;"><?= $paidCount ?> (<?= round($paidCount / $chartTotal * 100) ?>%)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark" style="font-size:0.65rem;"><span class="dot bg-warning"></span> Due Soon</span>
                            <span class="text-muted" style="font-size:0.6rem;"><?= $dueSoonCount ?> (<?= round($dueSoonCount / $chartTotal * 100) ?>%)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold text-dark" style="font-size:0.65rem;"><span class="dot bg-danger"></span> Overdue</span>
                            <span class="text-muted" style="font-size:0.6rem;"><?= $actualOverdueCount ?> (<?= round($actualOverdueCount / $chartTotal * 100) ?>%)</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-dark" style="font-size:0.65rem;"><span class="dot bg-secondary"></span> Pending</span>
                            <span class="text-muted" style="font-size:0.6rem;"><?= $pendingCount ?> (<?= round($pendingCount / $chartTotal * 100) ?>%)</span>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-3 pt-2 border-top">
                    <a href="#" class="text-primary text-decoration-none fw-semibold" style="font-size: 0.65rem;">View Full Report &rarr;</a>
                </div>
            </div>
        </div>
        
        <!-- Info Box -->
        <div class="info-box mb-2">
            <h6 class="fw-bold text-dark mb-1" style="font-size:0.7rem;">About Manual GCash Payments</h6>
            <div class="d-flex align-items-start text-muted mt-1" style="font-size:0.6rem;">
                <i class="fa-solid fa-circle-info text-primary mt-1 me-2" style="font-size:0.6rem;"></i>
                <span>Tenants pay through GCash outside the system. Ask them to enter the reference number and upload a screenshot as proof of payment.</span>
            </div>
        </div>
        
        
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Payment Donut Chart
    const payCtx = document.getElementById('paymentStatusChart').getContext('2d');
    new Chart(payCtx, {
        type: 'doughnut',
        data: {
            labels: ['Paid', 'Due Soon', 'Overdue', 'Pending'],
            datasets: [{
                data: [<?= $paidCount ?>, <?= $dueSoonCount ?>, <?= $actualOverdueCount ?>, <?= $pendingCount ?>],
                backgroundColor: ['#22c55e', '#f59e0b', '#ef4444', '#64748b'],
                borderWidth: 0,
                cutout: '80%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true }
            }
        }
    });
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

<?php require_once 'footer.php'; ?>
