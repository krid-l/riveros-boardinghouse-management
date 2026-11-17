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
//  - Sole tenant pays the whole room (a per-room setting, rooms.solo_pays_full, off by default):
//    in a room with more than one bed, a tenant who is the only one in it when a month's rent
//    is posted pays the whole room price for that month instead of one bed's share. Their first
//    month in the room is always one bed's share, which gives the room a month to find another
//    tenant. When someone else moves in, a whole-room charge for that month (or later) goes back
//    to one bed's share: they pay the whole room only for months nobody joined them. The
//    deposit stays one bed's share.
//
// Appliance charges (a monthly fee per appliance a tenant brings) follow the same calendar; see
// appliances.php.
//
// tenants.balance is the running total (charges minus payments). Every rent charge is also
// recorded in the charges table, so the ledger can always be rebuilt and checked against it.

require_once __DIR__ . '/sql_compat.php';
require_once __DIR__ . '/appliances.php';

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

// SQL for the number of beds taken in a room: active tenants whose main room it is, plus active
// tenants renting it as an additional room (tenant_rooms). $roomIdExpr is the room id column or
// a placeholder, e.g. "r.id" or "?" (a "?" is used twice, so pass the id twice).
function roomBedsTakenSql(string $roomIdExpr): string {
    return "((SELECT COUNT(*) FROM tenants occ_t WHERE occ_t.room_id = $roomIdExpr AND occ_t.status = 'active')
           + (SELECT COUNT(*) FROM tenant_rooms occ_x JOIN tenants occ_xt ON occ_xt.id = occ_x.tenant_id
              WHERE occ_x.room_id = $roomIdExpr AND occ_x.ended_at IS NULL AND occ_xt.status = 'active'))";
}

// How many beds of a room are taken right now (main-room tenants and additional-room renters).
function roomOccupantCount(PDO $pdo, int $roomId): int {
    $stmt = $pdo->prepare("SELECT " . roomBedsTakenSql('?'));
    $stmt->execute([$roomId, $roomId]);
    return (int)$stmt->fetchColumn();
}

// One tenant's monthly rent: one bed's part of the room price. It depends on how many beds the
// room has, not on how many are taken.
function rentShare(float $roomPrice, int $capacity): float {
    return round($roomPrice / max(1, $capacity), 2);
}

// Whether the "sole tenant pays the whole room" rule applies to a room right now: it is switched
// on for the room, the room has more than one bed, and only one bed is taken.
// $room needs capacity and solo_pays_full.
function soloPaysWholeRoom(array $room, int $bedsTaken): bool {
    return !empty($room['solo_pays_full']) && (int)($room['capacity'] ?? 1) > 1 && $bedsTaken <= 1;
}

// A tenant's regular monthly rent for a room: the whole room price when they are its only tenant
// and the room is set to charge that, otherwise one bed's share. (A tenant's first month in a room
// is always one bed's share; this is the rate for the months after it.)
function bedRent(array $room, int $bedsTaken): float {
    return soloPaysWholeRoom($room, $bedsTaken)
        ? round((float)$room['price_per_month'], 2)
        : rentShare((float)$room['price_per_month'], (int)$room['capacity']);
}

const WHOLE_ROOM_NOTE = ' (whole room: the only tenant in it)';

/**
 * Someone moved into a room on $date: the tenants already there are no longer alone, so any
 * whole-room charge they have for that month or later goes back to one bed's share. Call inside
 * a transaction, after the newcomer is recorded.
 * @return float how much was taken off the other tenants' balances in total
 */
function revertWholeRoomCharges(PDO $pdo, int $roomId, string $date, int $newcomerTenantId): float {
    $room = $pdo->prepare("SELECT price_per_month, capacity FROM rooms WHERE id = ?");
    $room->execute([$roomId]);
    $r = $room->fetch();
    if (!$r || (int)$r['capacity'] < 2) return 0.0;
    $share = rentShare((float)$r['price_per_month'], (int)$r['capacity']);
    $note = rentNote((float)$r['price_per_month'], (int)$r['capacity']);

    $stmt = $pdo->prepare("SELECT id, tenant_id, amount, description FROM charges
                           WHERE room_id = ? AND tenant_id <> ? AND billing_month >= ? AND description LIKE ?");
    $stmt->execute([$roomId, $newcomerTenantId, date('Y-m', strtotime($date)), '%' . WHOLE_ROOM_NOTE]);
    $upd = $pdo->prepare("UPDATE charges SET amount = ?, description = ? WHERE id = ?");
    $bal = $pdo->prepare("UPDATE tenants SET balance = balance - ? WHERE id = ?");
    $total = 0.0;
    foreach ($stmt->fetchAll() as $c) {
        $delta = round((float)$c['amount'] - $share, 2);
        $upd->execute([$share, mb_substr(str_replace(WHOLE_ROOM_NOTE, '', $c['description']) . $note, 0, 255), $c['id']]);
        if (abs($delta) >= 0.01) {
            $bal->execute([$delta, $c['tenant_id']]);
            $total += $delta;
        }
    }
    return round($total, 2);
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

    // Already used during this stay (the tenant asked to put it toward their last month): once is all.
    $used = $pdo->prepare("SELECT 1 FROM charges WHERE tenant_id = ? AND kind = ? AND billing_month >= ?");
    $used->execute([$tenantId, DEPOSIT_APPLIED_KIND, date('Y-m', strtotime($tenant['move_in_date']))]);
    if ($used->fetchColumn()) return 0.0;

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

    billAdditionalRooms($pdo, $onlyTenantId);
    billAppliances($pdo, $onlyTenantId);
}

// ---------------------------------------------------------------------------------------------
// Additional rooms
//
// A tenant lives in one main room (tenants.room_id) and can rent more rooms on top of it
// (tenant_rooms). Each additional room takes one bed and is billed on its own, at the same
// per-bed rent (or the whole room, when the sole-tenant rule applies) and on the same calendar
// as the main room:
//  - the first month is paid in advance and due the day the room is taken: day 1-15 pays the
//    full month, day 16 onward pays half;
//  - every month after that is charged on the 1st and due on the 30th;
//  - releasing the room stops its rent from the next month (the month it is released in stays
//    billed, as with a room change).
// There is no separate deposit for an additional room: the tenant's one deposit belongs to
// their stay and main room.
// Its charges use kind 'room_<tenant_rooms.id>', so each room gets its own line per month.
// ---------------------------------------------------------------------------------------------

function additionalRoomChargeKind(int $tenantRoomId): string {
    return 'room_' . $tenantRoomId;
}

/**
 * The additional rooms a tenant rents now (not released), oldest first, with their rent.
 * Each row: id (tenant_rooms.id), room_id, room_number, capacity, price_per_month, status,
 * start_date, beds_taken, whole_room (the sole-tenant rule applies), rent (the regular monthly rent).
 */
function tenantAdditionalRooms(PDO $pdo, int $tenantId): array {
    $stmt = $pdo->prepare("SELECT x.id, x.room_id, x.start_date, r.room_number, r.capacity, r.price_per_month, r.status, r.solo_pays_full
                           FROM tenant_rooms x JOIN rooms r ON r.id = x.room_id
                           WHERE x.tenant_id = ? AND x.ended_at IS NULL
                           ORDER BY x.start_date, x.id");
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['beds_taken'] = roomOccupantCount($pdo, (int)$row['room_id']);
        $row['whole_room'] = soloPaysWholeRoom($row, $row['beds_taken']);
        $row['rent'] = bedRent($row, $row['beds_taken']);
    }
    unset($row);
    return $rows;
}

/** Every room's additional renters (active tenants only): room id => list of tenant ids. */
function additionalRoomRenters(PDO $pdo): array {
    $rows = $pdo->query("SELECT x.room_id, x.tenant_id FROM tenant_rooms x JOIN tenants t ON t.id = x.tenant_id
                         WHERE x.ended_at IS NULL AND t.status = 'active'")->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['room_id']][] = (int)$row['tenant_id'];
    }
    return $out;
}

/** Post the rent of every additional room that is due to be posted up to the current month. */
function billAdditionalRooms(PDO $pdo, ?int $onlyTenantId = null): void {
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');

    $sql = "SELECT x.id FROM tenant_rooms x JOIN tenants t ON t.id = x.tenant_id
            WHERE x.ended_at IS NULL AND t.status = 'active' AND x.start_date <= ?
              AND (x.last_billed_month IS NULL OR x.last_billed_month < ?)";
    $params = [$today, $currentMonth];
    if ($onlyTenantId !== null) {
        $sql .= " AND x.tenant_id = ?";
        $params[] = $onlyTenantId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $tenantRoomId) {
        $ownTransaction = !$pdo->inTransaction();
        try {
            if ($ownTransaction) $pdo->beginTransaction();
            billAdditionalRoom($pdo, (int)$tenantRoomId, $today, $currentMonth);
            if ($ownTransaction) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Billing error for additional room $tenantRoomId: " . $e->getMessage());
            if (!$ownTransaction) throw $e;
        }
    }
}

// Post one additional room's rent for every month from its last billed month up to now.
function billAdditionalRoom(PDO $pdo, int $tenantRoomId, string $today, string $currentMonth): void {
    $stmt = $pdo->prepare("
        SELECT x.tenant_id, x.room_id, x.start_date, x.last_billed_month, x.ended_at, t.status,
               r.room_number, r.price_per_month, r.capacity, r.solo_pays_full
        FROM tenant_rooms x JOIN tenants t ON t.id = x.tenant_id JOIN rooms r ON r.id = x.room_id
        WHERE x.id = ?
        " . sqlForUpdateOf('x') . "
    ");
    $stmt->execute([$tenantRoomId]);
    $x = $stmt->fetch();
    if (!$x || $x['ended_at'] !== null || $x['status'] !== 'active' || $x['start_date'] > $today) {
        return;
    }
    if ($x['last_billed_month'] !== null && $x['last_billed_month'] >= $currentMonth) {
        return;   // billed by another request while we waited for the lock
    }

    $startMonth = date('Y-m', strtotime($x['start_date']));
    $month = $x['last_billed_month'] === null ? $startMonth : nextBillingMonth($x['last_billed_month']);
    $roomPrice = (float)$x['price_per_month'];
    $capacity = max(1, (int)$x['capacity']);
    $rent = rentShare($roomPrice, $capacity);
    $shareNote = rentNote($roomPrice, $capacity);
    $kind = additionalRoomChargeKind($tenantRoomId);

    $ins = $pdo->prepare("
        INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        " . sqlInsertIgnore(['tenant_id', 'billing_month', 'kind'], 'tenant_id') . "
    ");
    $addBalance = $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?");

    for (; $rent > 0 && $month <= $currentMonth; $month = nextBillingMonth($month)) {
        $monthName = date('F Y', strtotime($month . '-01'));
        $label = 'Room ' . $x['room_number'] . ' (additional room)';
        if ($month === $startMonth) {
            $amount = firstMonthRent($rent, $x['start_date']);
            $due = firstMonthDueDate($x['start_date']);
            $desc = "$label first month rent, $monthName (" . (isHalfMonthMoveIn($x['start_date']) ? 'half' : 'full')
                  . ', from ' . date('M j', strtotime($x['start_date'])) . ')' . $shareNote;
        } else {
            $amount = $rent;
            $due = billingDueDate($month);
            $desc = "$label rent, $monthName" . $shareNote;
            // The only tenant in a room set to charge them the whole room (after its first month).
            if (soloPaysWholeRoom($x, roomOccupantCount($pdo, (int)$x['room_id']))) {
                $amount = round($roomPrice, 2);
                $desc = "$label rent, $monthName" . WHOLE_ROOM_NOTE;
            }
        }
        $ins->execute([$x['tenant_id'], $x['room_id'], $kind, $month, mb_substr($desc, 0, 255), $amount, $due]);
        if ($ins->rowCount() === 1) {
            $addBalance->execute([$amount, $x['tenant_id']]);
        }
    }

    $pdo->prepare("UPDATE tenant_rooms SET last_billed_month = ? WHERE id = ?")->execute([$currentMonth, $tenantRoomId]);
}

// Post one tenant's rent for every month from their last billed month up to now.
function billTenant(PDO $pdo, int $tenantId, string $today, string $currentMonth): void {
    $stmt = $pdo->prepare("
        SELECT t.status, t.room_id, t.move_in_date, t.last_billed_month, r.price_per_month, r.capacity, r.solo_pays_full
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
            // Alone in a room set to charge a sole tenant the whole room, past their first month in it.
            if (soloPaysWholeRoom($t, roomOccupantCount($pdo, (int)$t['room_id']))
                && !isFirstMonthInMainRoom($pdo, $tenantId, (int)$t['room_id'], $t['move_in_date'], $month)) {
                $amount = round($roomPrice, 2);
                $desc = "Monthly rent, $monthName" . WHOLE_ROOM_NOTE;
            }
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
 * Whether $month is the tenant's first month of rent in their main room: no rent has been posted
 * for that room since they last came into it (moving in, or a room change, which is billed from
 * the next month).
 */
function isFirstMonthInMainRoom(PDO $pdo, int $tenantId, int $roomId, ?string $moveInDate, string $month): bool {
    $entered = $pdo->prepare("SELECT MAX(transferred_at) FROM room_transfers WHERE tenant_id = ? AND to_room_id = ?");
    $entered->execute([$tenantId, $roomId]);
    $since = $entered->fetchColumn() ?: $moveInDate;
    if (!$since) return true;
    $before = $pdo->prepare("SELECT COUNT(*) FROM charges WHERE tenant_id = ? AND kind = 'rent' AND room_id = ?
                             AND billing_month >= ? AND billing_month < ?");
    $before->execute([$tenantId, $roomId, date('Y-m', strtotime($since)), $month]);
    return (int)$before->fetchColumn() === 0;
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
// A room under maintenance takes nobody: that is an error, not just "full".
function roomHasSpace(PDO $pdo, int $roomId, ?int $ignoreTenantId = null): bool {
    $stmt = $pdo->prepare("SELECT capacity, status, room_number FROM rooms WHERE id = ? FOR UPDATE");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        throw new Exception("Selected room does not exist.");
    }
    if ($room['status'] === 'maintenance') {
        throw new Exception("Room {$room['room_number']} is under maintenance and can't take tenants.");
    }
    $capacity = $room['capacity'];
    // The ignored tenant's main-room bed doesn't count (they are moving out of it); a room they
    // rent as an additional room still does.
    $cnt = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM tenants WHERE room_id = ? AND status = 'active' AND id <> ?)
      + (SELECT COUNT(*) FROM tenant_rooms x JOIN tenants xt ON xt.id = x.tenant_id
         WHERE x.room_id = ? AND x.ended_at IS NULL AND xt.status = 'active')");
    $cnt->execute([$roomId, $ignoreTenantId ?? 0, $roomId]);
    return (int)$cnt->fetchColumn() < (int)$capacity;
}

/**
 * Rent an additional room to a tenant from $date. Call inside a transaction.
 * The tenant must be active and already have a main room; the room must have a free bed.
 * @return string the room number
 */
function addAdditionalRoom(PDO $pdo, int $tenantId, int $roomId, string $date): string {
    $stmt = $pdo->prepare("SELECT room_id, status, move_in_date FROM tenants WHERE id = ? FOR UPDATE");
    $stmt->execute([$tenantId]);
    $t = $stmt->fetch();
    if (!$t) throw new Exception("Tenant not found.");
    if ($t['status'] !== 'active') throw new Exception("Re-activate this tenant before renting them a room.");
    if (empty($t['room_id'])) throw new Exception("Assign the tenant's main room first, then add more rooms.");
    if (!$roomId) throw new Exception("Please choose a room.");
    if ((int)$t['room_id'] === $roomId) throw new Exception("That is already the tenant's main room.");
    if (!empty($t['move_in_date']) && $date < $t['move_in_date']) {
        throw new Exception("The start date can't be before the tenant moved in (" . date('M j, Y', strtotime($t['move_in_date'])) . ").");
    }

    $dup = $pdo->prepare("SELECT COUNT(*) FROM tenant_rooms WHERE tenant_id = ? AND room_id = ? AND ended_at IS NULL");
    $dup->execute([$tenantId, $roomId]);
    if ((int)$dup->fetchColumn() > 0) throw new Exception("The tenant already rents that room.");
    if (!roomHasSpace($pdo, $roomId)) throw new Exception("Selected room is already full.");

    $pdo->prepare("INSERT INTO tenant_rooms (tenant_id, room_id, start_date, created_at) VALUES (?, ?, ?, ?)")
        ->execute([$tenantId, $roomId, $date, date('Y-m-d H:i:s')]);
    logRoomTransfer($pdo, $tenantId, null, $roomId, $date, 'Rented an additional room');
    revertWholeRoomCharges($pdo, $roomId, $date, $tenantId);

    $no = $pdo->prepare("SELECT room_number FROM rooms WHERE id = ?");
    $no->execute([$roomId]);
    return (string)$no->fetchColumn();
}

/**
 * Stop renting an additional room on $date. Call inside a transaction.
 * Rent stops from the month after $date: anything already posted for later months is taken
 * back. Released on the day it was taken, it counts as never rented and all its rent is taken back.
 * @return array room_number, refunded (rent taken back off the balance), cancelled (released on its first day)
 */
function releaseAdditionalRoom(PDO $pdo, int $tenantRoomId, int $tenantId, string $date, string $note = 'Released an additional room'): array {
    $stmt = $pdo->prepare("SELECT x.room_id, x.start_date, x.ended_at, r.room_number
                           FROM tenant_rooms x JOIN rooms r ON r.id = x.room_id
                           WHERE x.id = ? AND x.tenant_id = ? " . sqlForUpdateOf('x'));
    $stmt->execute([$tenantRoomId, $tenantId]);
    $x = $stmt->fetch();
    if (!$x) throw new Exception("That room is not rented by this tenant.");
    if ($x['ended_at'] !== null) throw new Exception("Room {$x['room_number']} was already released.");
    if ($date < $x['start_date']) {
        $date = $x['start_date'];   // can't end before it began: treat as released on its first day
    }

    $kind = additionalRoomChargeKind($tenantRoomId);
    $cancelled = $date === $x['start_date'];
    $later = $pdo->prepare("SELECT id, amount FROM charges WHERE tenant_id = ? AND kind = ?" . ($cancelled ? '' : ' AND billing_month > ?'));
    $later->execute($cancelled ? [$tenantId, $kind] : [$tenantId, $kind, date('Y-m', strtotime($date))]);
    $refunded = 0.0;
    $del = $pdo->prepare("DELETE FROM charges WHERE id = ?");
    foreach ($later->fetchAll() as $c) {
        $del->execute([$c['id']]);
        $refunded += (float)$c['amount'];
    }
    $refunded = round($refunded, 2);
    if ($refunded != 0.0) {
        $pdo->prepare("UPDATE tenants SET balance = balance - ? WHERE id = ?")->execute([$refunded, $tenantId]);
    }

    $pdo->prepare("UPDATE tenant_rooms SET ended_at = ? WHERE id = ?")->execute([$date, $tenantRoomId]);
    logRoomTransfer($pdo, $tenantId, (int)$x['room_id'], null, $date, $note);
    return ['room_number' => (string)$x['room_number'], 'refunded' => $refunded, 'cancelled' => $cancelled];
}

/** Moving out: release every additional room the tenant rents. Call inside a transaction. */
function releaseAllAdditionalRooms(PDO $pdo, int $tenantId, string $date): void {
    $stmt = $pdo->prepare("SELECT id FROM tenant_rooms WHERE tenant_id = ? AND ended_at IS NULL");
    $stmt->execute([$tenantId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        releaseAdditionalRoom($pdo, (int)$id, $tenantId, $date, 'Released an additional room (moved out)');
    }
}

function logRoomTransfer(PDO $pdo, int $tenantId, ?int $fromRoom, ?int $toRoom, string $date, string $note): void {
    $pdo->prepare("INSERT INTO room_transfers (tenant_id, from_room_id, to_room_id, transferred_at, note) VALUES (?, ?, ?, ?, ?)")
        ->execute([$tenantId, $fromRoom, $toRoom, $date, $note]);
}

/** "Cash" or "GCash", for messages (a roommate's covered share keeps its "Covered by ..." text). */
function paymentMethodLabel(?string $method): string {
    $method = (string)$method;
    if ($method === 'cash') return 'Cash';
    if ($method === '' || $method === 'gcash') return 'GCash';
    return $method;
}

const MIN_PAYMENT_DEFAULT = 2000.0;

/**
 * The smallest payment a tenant may submit (Settings -> GCash Payment Information).
 * A balance smaller than this can still be paid off in full.
 */
function minimumPayment(PDO $pdo): float {
    $value = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'min_payment_amount'")->fetchColumn();
    return $value === false || $value === '' || !is_numeric($value) ? MIN_PAYMENT_DEFAULT : max(0.0, round((float)$value, 2));
}
