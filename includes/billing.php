<?php
// includes/billing.php
//
// Billing rules:
//  - Rent is due on the 30th of every month (the last day in February).
//  - First month: moving in on day 1-15 pays the full rent, day 16 onward pays half.
//    It is due on the 30th of the move-in month (or the move-in day itself if they move in on the 31st).
//  - Every month after that is full rent, charged on the 1st and due on the 30th.
//  - A room change takes effect on the next month's charge.
//  - rooms.price_per_month is the price of the WHOLE room. The tenants living in that room
//    split it equally, so each one is charged price_per_month / (active tenants in the room).
//    A room at PHP 8,000 with 4 tenants bills PHP 2,000 each; with 2 tenants, PHP 4,000 each.
//    The split is worked out when the month's rent is posted, so a roommate moving in or out
//    changes everyone's share from the following month onwards, not retroactively.
//
// tenants.balance is the running total (charges minus payments). Every rent charge is also
// recorded in the charges table, so the ledger can always be rebuilt and checked against it.

require_once __DIR__ . '/sql_compat.php';

const BILLING_DUE_DAY = 30;
const HALF_MONTH_CUTOFF_DAY = 15;

function billingDueDate(string $month): string {
    $daysInMonth = (int)date('t', strtotime($month . '-01'));
    return $month . '-' . str_pad((string)min(BILLING_DUE_DAY, $daysInMonth), 2, '0', STR_PAD_LEFT);
}

function nextBillingMonth(string $month): string {
    return date('Y-m', strtotime($month . '-01 +1 month'));
}

function isHalfMonthMoveIn(string $moveInDate): bool {
    return (int)date('j', strtotime($moveInDate)) > HALF_MONTH_CUTOFF_DAY;
}

function firstMonthRent(float $rent, string $moveInDate): float {
    return isHalfMonthMoveIn($moveInDate) ? round($rent / 2, 2) : $rent;
}

// How many active tenants share a room right now.
function roomOccupantCount(PDO $pdo, int $roomId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = ? AND status = 'active'");
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn();
}

// One tenant's share of a room's monthly price. An empty room falls back to the full price,
// so the first tenant to move in is never charged a division by zero.
function rentShare(float $roomPrice, int $occupants): float {
    return round($roomPrice / max(1, $occupants), 2);
}

function firstMonthDueDate(string $moveInDate): string {
    $due = billingDueDate(date('Y-m', strtotime($moveInDate)));
    return max($due, date('Y-m-d', strtotime($moveInDate)));
}

// Human label for the period a month's rent covers, e.g. "Sep 20 - Sep 30, 2026".
function billingPeriodLabel(string $month, ?string $moveInDate = null): string {
    $start = $month . '-01';
    if ($moveInDate && date('Y-m', strtotime($moveInDate)) === $month) {
        $start = date('Y-m-d', strtotime($moveInDate));
    }
    $end = date('Y-m-t', strtotime($month . '-01'));
    return date('M j', strtotime($start)) . ' - ' . date('M j, Y', strtotime($end));
}

// SQL for the total amount credited to a tenant by verified payments.
// A room payment's credit to the payer excludes the shares recorded on roommates.
function tenantCreditedSql(string $tenantAlias): string {
    return "(COALESCE((SELECT SUM(p.amount) FROM payments p
                       WHERE p.tenant_id = $tenantAlias.id AND p.status = 'verified'), 0)
           - COALESCE((SELECT SUM(k.amount) FROM payments k
                       JOIN payments p ON k.covered_by_payment_id = p.id
                       WHERE p.tenant_id = $tenantAlias.id AND p.status = 'verified'), 0))";
}

// Revenue = money actually received. Rows that only record a roommate's covered share are excluded.
const REVENUE_FILTER_SQL = "status = 'verified' AND covered_by_payment_id IS NULL";

// The same rule with the payments table named explicitly, for queries that join a table which
// also has a status column (tenants does), where the bare column name would be ambiguous.
function revenueFilterSql(string $alias): string {
    return "$alias.status = 'verified' AND $alias.covered_by_payment_id IS NULL";
}

/**
 * Post any rent charges that are due to be posted up to the current month.
 * Safe to call on every page load: each tenant row is locked while it's billed and
 * the charges table's unique key stops a month being charged twice.
 */
function runBilling(PDO $pdo, ?int $onlyTenantId = null): void {
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');

    $sql = "SELECT id FROM tenants
            WHERE status = 'active' AND move_in_date IS NOT NULL AND move_in_date <= ?
              AND (last_billed_month IS NULL OR last_billed_month < ?)";
    $params = [$today, $currentMonth];
    if ($onlyTenantId !== null) {
        $sql .= " AND id = ?";
        $params[] = $onlyTenantId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tenantId) {
        $ownTransaction = !$pdo->inTransaction();
        try {
            if ($ownTransaction) $pdo->beginTransaction();
            billTenant($pdo, (int)$tenantId, $today, $currentMonth);
            if ($ownTransaction) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Billing error for tenant $tenantId: " . $e->getMessage());
            if (!$ownTransaction) throw $e;
        }
    }
}

function billTenant(PDO $pdo, int $tenantId, string $today, string $currentMonth): void {
    $stmt = $pdo->prepare("
        SELECT t.status, t.room_id, t.move_in_date, t.last_billed_month, r.price_per_month
        FROM tenants t LEFT JOIN rooms r ON r.id = t.room_id
        WHERE t.id = ?
        " . sqlForUpdateOf('t') . "
    ");
    $stmt->execute([$tenantId]);
    $t = $stmt->fetch();
    if (!$t || $t['status'] !== 'active' || !$t['move_in_date'] || $t['move_in_date'] > $today) {
        return;
    }
    if ($t['last_billed_month'] !== null && $t['last_billed_month'] >= $currentMonth) {
        return; // another request billed this tenant while we waited for the lock
    }

    $moveInMonth = date('Y-m', strtotime($t['move_in_date']));
    $month = $t['last_billed_month'] === null ? $moveInMonth : nextBillingMonth($t['last_billed_month']);
    // Re-activated tenant: their new tenancy starts at the new move-in month.
    if ($month < $moveInMonth) {
        $month = $moveInMonth;
    }

    $insCharge = $pdo->prepare("
        INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date)
        VALUES (?, ?, 'rent', ?, ?, ?, ?)
        " . sqlInsertIgnore(['tenant_id', 'billing_month', 'kind'], 'tenant_id') . "
    ");
    $addBalance = $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?");

    // The room price covers the whole room; this tenant owes their equal share of it.
    $roomPrice = (float)($t['price_per_month'] ?? 0);
    $occupants = empty($t['room_id']) ? 0 : roomOccupantCount($pdo, (int)$t['room_id']);
    $rent = empty($t['room_id']) ? 0.0 : rentShare($roomPrice, $occupants);
    $shareNote = $occupants > 1 ? " (share of PHP " . number_format($roomPrice, 2) . " room, split $occupants ways)" : '';

    for (; $month <= $currentMonth; $month = nextBillingMonth($month)) {
        if (empty($t['room_id']) || $rent <= 0) {
            continue; // no room that month: nothing to charge
        }
        $monthName = date('F Y', strtotime($month . '-01'));
        if ($month === $moveInMonth) {
            $amount = firstMonthRent($rent, $t['move_in_date']);
            $due = firstMonthDueDate($t['move_in_date']);
            $desc = "First month rent, $monthName (" . (isHalfMonthMoveIn($t['move_in_date']) ? 'half' : 'full')
                  . ', moved in ' . date('M j', strtotime($t['move_in_date'])) . ')' . $shareNote;
        } else {
            $amount = $rent;
            $due = billingDueDate($month);
            $desc = "Monthly rent, $monthName" . $shareNote;
        }

        $insCharge->execute([$tenantId, $t['room_id'], $month, $desc, $amount, $due]);
        if ($insCharge->rowCount() === 1) {
            $addBalance->execute([$amount, $tenantId]);
        }
    }

    $pdo->prepare("UPDATE tenants SET last_billed_month = ? WHERE id = ?")->execute([$currentMonth, $tenantId]);
}

/**
 * Charges that are posted but not yet past their due date, per tenant.
 * Anything a tenant owes beyond this amount is overdue.
 */
function chargesNotYetDue(PDO $pdo, ?int $tenantId = null): array {
    $sql = "SELECT tenant_id, SUM(amount) FROM charges WHERE due_date >= ?";
    $params = [date('Y-m-d')];
    if ($tenantId !== null) {
        $sql .= " AND tenant_id = ?";
        $params[] = $tenantId;
    }
    $stmt = $pdo->prepare($sql . " GROUP BY tenant_id");
    $stmt->execute($params);
    return array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Billing status of one tenant row (needs balance and status).
 * Returns key (paid|due|overdue|deactivated), label, color, balance, overdue amount.
 */
function tenantBillingStatus(array $tenant, array $notYetDue): array {
    $balance = round((float)$tenant['balance'], 2);
    $overdue = max(0, round($balance - ($notYetDue[$tenant['id']] ?? 0), 2));

    if (($tenant['status'] ?? 'active') === 'deactivated') {
        return ['key' => 'deactivated', 'label' => 'Deactivated', 'color' => 'secondary', 'balance' => $balance, 'overdue' => $overdue];
    }
    if ($balance <= 0) {
        return ['key' => 'paid', 'label' => 'Paid', 'color' => 'success', 'balance' => $balance, 'overdue' => 0];
    }
    if ($overdue > 0) {
        return ['key' => 'overdue', 'label' => 'Overdue', 'color' => 'danger', 'balance' => $balance, 'overdue' => $overdue];
    }
    return ['key' => 'due', 'label' => 'Unpaid', 'color' => 'warning', 'balance' => $balance, 'overdue' => 0];
}

// Next rent due date for an active tenant with a move-in date.
function nextDueDate(array $tenant): ?string {
    if (($tenant['status'] ?? 'active') !== 'active' || empty($tenant['move_in_date']) || empty($tenant['room_id'])) {
        return null;
    }
    $today = date('Y-m-d');
    $moveInMonth = date('Y-m', strtotime($tenant['move_in_date']));
    $month = max(date('Y-m'), $moveInMonth);
    $due = $month === $moveInMonth ? firstMonthDueDate($tenant['move_in_date']) : billingDueDate($month);
    if ($due < $today) {
        $due = billingDueDate(nextBillingMonth($month));
    }
    return $due;
}

// Oldest unpaid charge's due date, for "overdue since" messages. Payments settle the oldest charges first.
function oldestUnpaidDueDate(PDO $pdo, int $tenantId, float $balance): ?string {
    if ($balance <= 0) return null;
    $stmt = $pdo->prepare("SELECT amount, due_date FROM charges WHERE tenant_id = ? ORDER BY due_date DESC, id DESC");
    $stmt->execute([$tenantId]);
    $remaining = $balance;
    $oldest = null;
    foreach ($stmt->fetchAll() as $c) {
        if ($remaining <= 0) break;
        $oldest = $c['due_date'];
        $remaining -= (float)$c['amount'];
    }
    return $oldest;
}

// Room capacity check, counting active tenants only. Call inside a transaction.
function roomHasSpace(PDO $pdo, int $roomId, ?int $ignoreTenantId = null): bool {
    $stmt = $pdo->prepare("SELECT capacity FROM rooms WHERE id = ? FOR UPDATE");
    $stmt->execute([$roomId]);
    $capacity = $stmt->fetchColumn();
    if ($capacity === false) {
        throw new Exception("Selected room does not exist.");
    }
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = ? AND status = 'active' AND id <> ?");
    $cnt->execute([$roomId, $ignoreTenantId ?? 0]);
    return (int)$cnt->fetchColumn() < (int)$capacity;
}

function logRoomTransfer(PDO $pdo, int $tenantId, ?int $fromRoom, ?int $toRoom, string $date, string $note): void {
    $pdo->prepare("INSERT INTO room_transfers (tenant_id, from_room_id, to_room_id, transferred_at, note) VALUES (?, ?, ?, ?, ?)")
        ->execute([$tenantId, $fromRoom, $toRoom, $date, $note]);
}
