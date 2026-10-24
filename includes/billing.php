<?php
// includes/billing.php
//
// Billing rules:
//  - Rent is due on the 30th of every month (the last day in February).
//  - Moving in, a tenant pays two things up front, both due on the move-in day:
//      * the first month in advance: day 1-15 pays the full month, day 16 onward pays half;
//      * a deposit of one full month's share, held to pay for their last month of stay.
//  - Every month after that is full rent, charged on the 1st and due on the 30th.
//  - Moving out, the deposit is applied to that last month, so they don't pay for it twice.
//  - A room change takes effect on the next month's charge.
//  - Each tenant pays for one bed. rooms.price_per_month is the price of the whole room when
//    it is full, and a tenant's monthly rent is price_per_month / capacity, however many people
//    actually live there. A PHP 4,000 room for 2 bills PHP 2,000 to each tenant, even while
//    one bed is empty; a PHP 6,000 room for 3 bills PHP 2,000 each, whether 1, 2 or 3 live there.
//    Roommates moving in or out never change what anyone else pays.
//  - Someone who moves in after the 15th pays half of their own rent for that first month.
//  - The deposit is one month of the tenant's own rent. Moving to a room with a different rent
//    brings it in line (the difference is charged or credited), so it still covers a full last
//    month. Credits sit on the balance and count toward the next payment.
//  - A change to a room's price or capacity applies from the next month's rent.
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

// How many active tenants live in a room right now.
function roomOccupantCount(PDO $pdo, int $roomId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE room_id = ? AND status = 'active'");
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn();
}

// One tenant's monthly rent: one bed's part of the room price. It depends on how many beds the
// room has, not on how many are taken.
function rentShare(float $roomPrice, int $capacity): float {
    return round($roomPrice / max(1, $capacity), 2);
}

// " (1 of 2 beds in the PHP 4,000.00 room)" for charge descriptions; nothing for a single room.
function rentNote(float $roomPrice, int $capacity): string {
    return $capacity > 1 ? ' (1 of ' . $capacity . ' beds in the PHP ' . number_format($roomPrice, 2) . ' room)' : '';
}

// The first month is paid in advance, so it - and the deposit - fall due on the move-in day.
function firstMonthDueDate(string $moveInDate): string {
    return date('Y-m-d', strtotime($moveInDate));
}

const DEPOSIT_KIND = 'deposit';
const DEPOSIT_APPLIED_KIND = 'deposit_applied';

function depositDescription(float $roomPrice, int $capacity): string {
    return "Deposit, held for the last month of stay" . rentNote($roomPrice, $capacity);
}

/**
 * The deposit on record for a tenant's current stay, or 0 when there is none.
 * Each stay has its own deposit, filed under the month that stay began; a tenant who moves
 * out and comes back later starts a new stay with a new deposit.
 */
function tenantDeposit(PDO $pdo, array $tenant): float {
    if (($tenant['status'] ?? 'active') !== 'active' || empty($tenant['move_in_date'])) {
        return 0.0;
    }
    $stmt = $pdo->prepare("SELECT amount FROM charges WHERE tenant_id = ? AND kind = ? AND billing_month = ?");
    $stmt->execute([$tenant['id'], DEPOSIT_KIND, date('Y-m', strtotime($tenant['move_in_date']))]);
    $amount = $stmt->fetchColumn();
    return $amount === false ? 0.0 : (float)$amount;
}

/**
 * Moving out: spend the deposit on the last month's rent.
 *
 * Posts a credit equal to the deposit for the current stay, against the month they leave.
 * Their final month's rent has already been posted by then (billing runs on the 1st), so the
 * two cancel and the last month costs them nothing more. Anything the credit doesn't use stays
 * on the balance as money owed back to them. Call before the tenant's move-in date changes.
 *
 * @return float The amount applied; 0 when the stay had no deposit.
 */
function applyDepositToLastMonth(PDO $pdo, int $tenantId, string $moveOutDate): float {
    $stmt = $pdo->prepare("SELECT id, status, move_in_date FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant) return 0.0;

    $deposit = tenantDeposit($pdo, $tenant);
    if ($deposit <= 0) return 0.0;

    $ins = $pdo->prepare("
        INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date)
        VALUES (?, NULL, ?, ?, ?, ?, ?)
        " . sqlInsertIgnore(['tenant_id', 'billing_month', 'kind'], 'tenant_id') . "
    ");
    $ins->execute([$tenantId, DEPOSIT_APPLIED_KIND, date('Y-m', strtotime($moveOutDate)),
                   "Deposit applied to the last month's rent", -$deposit, $moveOutDate]);
    if ($ins->rowCount() !== 1) return 0.0;   // already applied for this move-out

    $pdo->prepare("UPDATE tenants SET balance = balance - ? WHERE id = ?")->execute([$deposit, $tenantId]);
    return $deposit;
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

// Post one tenant's rent for every month from their last billed month up to now.
function billTenant(PDO $pdo, int $tenantId, string $today, string $currentMonth): void {
    $stmt = $pdo->prepare("
        SELECT t.status, t.room_id, t.move_in_date, t.last_billed_month, r.price_per_month, r.capacity
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
    $insDeposit = $pdo->prepare("
        INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date)
        VALUES (?, ?, '" . DEPOSIT_KIND . "', ?, ?, ?, ?)
        " . sqlInsertIgnore(['tenant_id', 'billing_month', 'kind'], 'tenant_id') . "
    ");
    $addBalance = $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?");

    // One bed's part of the room price, whoever else lives there.
    $roomPrice = (float)($t['price_per_month'] ?? 0);
    $capacity = max(1, (int)($t['capacity'] ?? 1));
    $rent = empty($t['room_id']) ? 0.0 : rentShare($roomPrice, $capacity);
    $shareNote = rentNote($roomPrice, $capacity);

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

            // The deposit is taken with the advance, and only then: tying it to a newly posted
            // first month means a tenant who was here before deposits existed isn't suddenly
            // billed one. It is a full month's rent, never halved - it pays for a whole last
            // month whatever day they moved in.
            if ($month === $moveInMonth) {
                $insDeposit->execute([$tenantId, $t['room_id'], $moveInMonth,
                                      depositDescription($roomPrice, $capacity), $rent, $due]);
                if ($insDeposit->rowCount() === 1) {
                    $addBalance->execute([$rent, $tenantId]);
                }
            }
        }
    }

    $pdo->prepare("UPDATE tenants SET last_billed_month = ? WHERE id = ?")->execute([$currentMonth, $tenantId]);
}

/**
 * Bring a tenant's deposit in line with the rent of the room they live in now, charging or
 * crediting the difference. The deposit pays for their last month, and that month is billed at
 * the current room's rent - so after a move to a dearer or cheaper room it has to follow.
 * A deposit already spent on a last month is left alone.
 *
 * @return float How much the deposit went up (negative: down); 0 when nothing changed.
 */
function syncDepositToRoom(PDO $pdo, int $tenantId): float {
    $stmt = $pdo->prepare("SELECT t.status, t.room_id, t.move_in_date, r.price_per_month, r.capacity
                           FROM tenants t LEFT JOIN rooms r ON r.id = t.room_id WHERE t.id = ?");
    $stmt->execute([$tenantId]);
    $t = $stmt->fetch();
    if (!$t || $t['status'] !== 'active' || empty($t['room_id']) || empty($t['move_in_date'])) {
        return 0.0;
    }
    $stayMonth = date('Y-m', strtotime($t['move_in_date']));

    $used = $pdo->prepare("SELECT COUNT(*) FROM charges WHERE tenant_id = ? AND kind = ? AND billing_month >= ?");
    $used->execute([$tenantId, DEPOSIT_APPLIED_KIND, $stayMonth]);
    if ((int)$used->fetchColumn() > 0) {
        return 0.0;
    }

    $find = $pdo->prepare("SELECT id, amount FROM charges WHERE tenant_id = ? AND kind = ? AND billing_month = ?");
    $find->execute([$tenantId, DEPOSIT_KIND, $stayMonth]);
    $deposit = $find->fetch();
    if (!$deposit) {
        return 0.0;   // a stay from before deposits existed
    }

    $capacity = max(1, (int)$t['capacity']);
    $rent = rentShare((float)$t['price_per_month'], $capacity);
    $delta = round($rent - (float)$deposit['amount'], 2);
    if (abs($delta) < 0.01) {
        return 0.0;
    }
    $pdo->prepare("UPDATE charges SET room_id = ?, amount = ?, description = ? WHERE id = ?")
        ->execute([$t['room_id'], $rent, depositDescription((float)$t['price_per_month'], $capacity), $deposit['id']]);
    $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?")->execute([$delta, $tenantId]);
    return $delta;
}

/**
 * Re-price a tenant's current month at their room's per-bed rent: that month's rent charge and
 * their deposit. Used once, when rent changed from splitting the room price among the tenants
 * living there to a fixed rent per bed. Months whose bills already came due keep the amounts
 * they were billed at; the current month hasn't fallen due yet.
 */
function repriceTenantToBedRent(PDO $pdo, int $tenantId): void {
    $stmt = $pdo->prepare("SELECT t.status, t.room_id, t.move_in_date, r.price_per_month, r.capacity
                           FROM tenants t JOIN rooms r ON r.id = t.room_id WHERE t.id = ?");
    $stmt->execute([$tenantId]);
    $t = $stmt->fetch();
    if (!$t || $t['status'] !== 'active' || empty($t['move_in_date'])) {
        return;
    }
    $month = date('Y-m');
    $roomPrice = (float)$t['price_per_month'];
    $capacity = max(1, (int)$t['capacity']);
    $rent = rentShare($roomPrice, $capacity);

    // Only a charge billed for the room they are in (a room change applies from next month).
    $find = $pdo->prepare("SELECT id, amount FROM charges WHERE tenant_id = ? AND billing_month = ? AND kind = 'rent' AND room_id = ?");
    $find->execute([$tenantId, $month, $t['room_id']]);
    $charge = $find->fetch();
    if ($charge) {
        $monthName = date('F Y', strtotime($month . '-01'));
        $movedInThisMonth = date('Y-m', strtotime($t['move_in_date'])) === $month;
        if ($movedInThisMonth) {
            $amount = firstMonthRent($rent, $t['move_in_date']);
            $desc = "First month rent, $monthName (" . (isHalfMonthMoveIn($t['move_in_date']) ? 'half' : 'full')
                  . ', moved in ' . date('M j', strtotime($t['move_in_date'])) . ')' . rentNote($roomPrice, $capacity);
        } else {
            $amount = $rent;
            $desc = "Monthly rent, $monthName" . rentNote($roomPrice, $capacity);
        }
        $delta = round($amount - (float)$charge['amount'], 2);
        $pdo->prepare("UPDATE charges SET amount = ?, description = ? WHERE id = ?")->execute([$amount, $desc, $charge['id']]);
        if (abs($delta) >= 0.01) {
            $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?")->execute([$delta, $tenantId]);
        }
    }
    syncDepositToRoom($pdo, $tenantId);
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

// The earliest due date among charges that haven't fallen due yet: when the bill a tenant is
// currently running up has to be paid. Null when nothing is pending.
function upcomingDueDate(PDO $pdo, int $tenantId): ?string {
    $stmt = $pdo->prepare("SELECT MIN(due_date) FROM charges WHERE tenant_id = ? AND due_date >= ? AND amount > 0");
    $stmt->execute([$tenantId, date('Y-m-d')]);
    $due = $stmt->fetchColumn();
    return $due ?: null;
}

/** "today", "tomorrow" or "Oct 30, 2026" */
function dueDateLabel(string $date): string {
    if ($date === date('Y-m-d')) return 'today';
    if ($date === date('Y-m-d', strtotime('+1 day'))) return 'tomorrow';
    return date('M j, Y', strtotime($date));
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
