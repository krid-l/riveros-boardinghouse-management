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
//  - rooms.price_per_month is the price of the WHOLE room. The tenants living in that room
//    split it equally, so each one is charged price_per_month / (active tenants in the room).
//    A room at PHP 8,000 with 4 tenants bills PHP 2,000 each; with 2 tenants, PHP 4,000 each.
//    When someone moves in or out, the current month's shares are recomputed for everyone
//    still in that room, so two tenants of the same room are never charged different amounts
//    for the same month. Months whose bills already came due are left as they were.
//  - The deposit follows the share. A roommate moving in lowers everyone's share, so part of
//    each deposit is credited back; if that happens in the same month someone paid their
//    advance, the advance is re-split and part of it comes back too. A roommate moving out
//    raises the share, and the deposit rises with it so it still covers a full last month.
//    Credits sit on the balance and count toward the next payment.
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

// The first month is paid in advance, so it - and the deposit - fall due on the move-in day.
function firstMonthDueDate(string $moveInDate): string {
    return date('Y-m-d', strtotime($moveInDate));
}

const DEPOSIT_KIND = 'deposit';
const DEPOSIT_APPLIED_KIND = 'deposit_applied';

function depositDescription(float $roomPrice, int $occupants): string {
    return "Deposit, held for the last month of stay"
        . ($occupants > 1 ? " (one month's share of the PHP " . number_format($roomPrice, 2) . " room, split $occupants ways)" : '');
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

    $billedRooms = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tenantId) {
        $ownTransaction = !$pdo->inTransaction();
        try {
            if ($ownTransaction) $pdo->beginTransaction();
            $billed = billTenant($pdo, (int)$tenantId, $today, $currentMonth);
            if ($billed) {
                $roomId = $billed['room_id'];
                $billedRooms[$roomId] = isset($billedRooms[$roomId])
                    ? min($billedRooms[$roomId], $billed['from_month'])
                    : $billed['from_month'];
            }
            if ($ownTransaction) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Billing error for tenant $tenantId: " . $e->getMessage());
            if (!$ownTransaction) throw $e;
        }
    }

    // A tenant billed just now changes what their roommates owe for the same month.
    foreach ($billedRooms as $roomId => $fromMonth) {
        $ownTransaction = !$pdo->inTransaction();
        try {
            if ($ownTransaction) $pdo->beginTransaction();
            resplitRoomRent($pdo, (int)$roomId, $fromMonth);
            if ($ownTransaction) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Rent re-split error for room $roomId: " . $e->getMessage());
            if (!$ownTransaction) throw $e;
        }
    }
}

function billTenant(PDO $pdo, int $tenantId, string $today, string $currentMonth): ?array {
    $stmt = $pdo->prepare("
        SELECT t.status, t.room_id, t.move_in_date, t.last_billed_month, r.price_per_month
        FROM tenants t LEFT JOIN rooms r ON r.id = t.room_id
        WHERE t.id = ?
        " . sqlForUpdateOf('t') . "
    ");
    $stmt->execute([$tenantId]);
    $t = $stmt->fetch();
    if (!$t || $t['status'] !== 'active' || !$t['move_in_date'] || $t['move_in_date'] > $today) {
        return null;
    }
    if ($t['last_billed_month'] !== null && $t['last_billed_month'] >= $currentMonth) {
        return null; // another request billed this tenant while we waited for the lock
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

    // The room price covers the whole room; this tenant owes their equal share of it.
    $roomPrice = (float)($t['price_per_month'] ?? 0);
    $occupants = empty($t['room_id']) ? 0 : roomOccupantCount($pdo, (int)$t['room_id']);
    $rent = empty($t['room_id']) ? 0.0 : rentShare($roomPrice, $occupants);
    $firstMonthPosted = null;
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
            $firstMonthPosted = $firstMonthPosted ?? $month;

            // The deposit is taken with the advance, and only then: tying it to a newly posted
            // first month means a tenant who was here before deposits existed isn't suddenly
            // billed one. It is a full month's share, never halved - it pays for a whole last
            // month whatever day they moved in.
            if ($month === $moveInMonth) {
                $insDeposit->execute([$tenantId, $t['room_id'], $moveInMonth,
                                      depositDescription($roomPrice, $occupants), $rent, $due]);
                if ($insDeposit->rowCount() === 1) {
                    $addBalance->execute([$rent, $tenantId]);
                }
            }
        }
    }

    $pdo->prepare("UPDATE tenants SET last_billed_month = ? WHERE id = ?")->execute([$currentMonth, $tenantId]);

    if (empty($t['room_id']) || $firstMonthPosted === null) {
        return null;
    }
    return ['room_id' => (int)$t['room_id'], 'from_month' => $firstMonthPosted];
}

/**
 * Re-split a room's rent across everyone living in it now, for the months a change affects.
 *
 * price_per_month is the price of the whole room, so what a tenant owes depends on how many
 * people share it. A charge posted while the room held one tenant is stale the moment a second
 * moves in: the first was billed for the whole room. This recomputes the posted rent charges
 * and moves the balances by the difference, so two tenants of the same room are never charged
 * different amounts for the same month.
 *
 * $fromMonth bounds how far back to go and must be the first month the change affects - the
 * arriving tenant's move-in month, or the current month for a departure. Going back further
 * would rewrite months that were correctly split at the time, cutting the bills of tenants who
 * have owed that money since before this change.
 */
function resplitRoomRent(PDO $pdo, ?int $roomId, ?string $fromMonth = null): void {
    if (!$roomId) return;

    $currentMonth = date('Y-m');
    $month = $fromMonth ?: $currentMonth;
    if ($month > $currentMonth) return;

    $stmt = $pdo->prepare("SELECT price_per_month FROM rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $roomPrice = $stmt->fetchColumn();
    if ($roomPrice === false) return;

    $stmt = $pdo->prepare("SELECT id, move_in_date FROM tenants WHERE room_id = ? AND status = 'active' ORDER BY id");
    $stmt->execute([$roomId]);
    $occupants = $stmt->fetchAll();
    if (!$occupants) return;

    $share = rentShare((float)$roomPrice, count($occupants));
    $shareNote = count($occupants) > 1
        ? ' (share of PHP ' . number_format((float)$roomPrice, 2) . ' room, split ' . count($occupants) . ' ways)'
        : '';

    $findCharge = $pdo->prepare("SELECT id, amount FROM charges WHERE tenant_id = ? AND billing_month = ? AND kind = 'rent'");
    $updCharge = $pdo->prepare("UPDATE charges SET room_id = ?, amount = ?, description = ? WHERE id = ?");
    $addBalance = $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?");

    // Deposits always match the current share, whichever month the change falls in: the deposit
    // has to pay for one future month, and that month will be billed at the share as it stands.
    // Fewer roommates, bigger deposit; more roommates, part of it credited back.
    $findDeposit = $pdo->prepare("SELECT id, amount FROM charges WHERE tenant_id = ? AND kind = '" . DEPOSIT_KIND . "' AND billing_month = ?");
    $depositDesc = depositDescription((float)$roomPrice, count($occupants));
    foreach ($occupants as $o) {
        if (!$o['move_in_date']) continue;
        $findDeposit->execute([$o['id'], date('Y-m', strtotime($o['move_in_date']))]);
        $deposit = $findDeposit->fetch();
        if (!$deposit) continue;   // a stay from before deposits existed

        $delta = round($share - (float)$deposit['amount'], 2);
        $updCharge->execute([$roomId, $share, $depositDesc, $deposit['id']]);
        if (abs($delta) >= 0.01) {
            $addBalance->execute([$delta, $o['id']]);
        }
    }

    for (; $month <= $currentMonth; $month = nextBillingMonth($month)) {
        $monthName = date('F Y', strtotime($month . '-01'));

        foreach ($occupants as $o) {
            $findCharge->execute([$o['id'], $month]);
            $charge = $findCharge->fetch();
            if (!$charge) continue;   // nothing posted for this tenant that month

            // Someone who moved in during the month keeps their full/half first-month
            // treatment, applied to the new share.
            $movedInThisMonth = $o['move_in_date'] && date('Y-m', strtotime($o['move_in_date'])) === $month;
            if ($movedInThisMonth) {
                $amount = firstMonthRent($share, $o['move_in_date']);
                $desc = "First month rent, $monthName (" . (isHalfMonthMoveIn($o['move_in_date']) ? 'half' : 'full')
                      . ', moved in ' . date('M j', strtotime($o['move_in_date'])) . ')' . $shareNote;
            } else {
                $amount = $share;
                $desc = "Monthly rent, $monthName" . $shareNote;
            }

            $delta = round($amount - (float)$charge['amount'], 2);
            $updCharge->execute([$roomId, $amount, $desc, $charge['id']]);
            if (abs($delta) >= 0.01) {
                $addBalance->execute([$delta, $o['id']]);
            }
        }
    }
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
