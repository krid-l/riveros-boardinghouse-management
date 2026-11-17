<?php
// includes/appliances.php
//
// Appliance charges: a monthly fee on top of the rent for each appliance a tenant brings
// (an electric fan, a rice cooker, a TV...).
//
//  - The admin keeps the list of appliances and their monthly fee in Settings -> Appliance
//    Charges. A fee change applies from the next month's charge. Removing an appliance from the
//    list only stops it being offered; tenants who already have it keep paying until it is taken
//    off them.
//  - A tenant's appliances are picked when they are added (Add Tenant) or later on their page.
//    The fee is per unit, so two fans cost twice one fan.
//  - Billing follows the rent: the first month is due on the day the appliance starts (the
//    move-in day when added with the tenant) and is half when that is after the 15th; every
//    month after that is charged on the 1st and due on the 30th.
//  - Taking an appliance off stops its charge after that month; anything already posted for
//    later months is taken back. Moving out takes them all off.
// Charges use kind 'appl_<tenant_appliances.id>', one line per appliance per month.
//
// Loaded by billing.php.

function applianceChargeKind(int $tenantApplianceId): string {
    return 'appl_' . $tenantApplianceId;
}

/** The appliances on offer (Settings), by name. */
function activeAppliances(PDO $pdo): array {
    return $pdo->query("SELECT id, name, monthly_fee FROM appliances WHERE active = " . (isPgsql() ? 'TRUE' : '1') . " ORDER BY name, id")->fetchAll();
}

/**
 * A tenant's appliances being charged now, oldest first.
 * Each row: id (tenant_appliances.id), appliance_id, name, quantity, unit_fee, monthly (fee x quantity), start_date.
 */
function tenantAppliances(PDO $pdo, int $tenantId): array {
    $stmt = $pdo->prepare("SELECT x.id, x.appliance_id, x.quantity, x.start_date, a.name, a.monthly_fee AS unit_fee
                           FROM tenant_appliances x JOIN appliances a ON a.id = x.appliance_id
                           WHERE x.tenant_id = ? AND x.ended_at IS NULL
                           ORDER BY x.id");
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['monthly'] = round((float)$row['unit_fee'] * max(1, (int)$row['quantity']), 2);
    }
    unset($row);
    return $rows;
}

/** "Electric fan" or "Electric fan x2", for charge lines and lists. */
function applianceLabel(string $name, int $quantity): string {
    return $quantity > 1 ? "$name x$quantity" : $name;
}

/**
 * Start charging a tenant for an appliance. Call inside a transaction.
 * $startDate null: from the tenant's move-in (for a tenant who has no room yet).
 * @return string the appliance's label
 */
function addTenantAppliance(PDO $pdo, int $tenantId, int $applianceId, int $quantity, ?string $startDate): string {
    $quantity = max(1, min(20, $quantity));
    $stmt = $pdo->prepare("SELECT name, active FROM appliances WHERE id = ?");
    $stmt->execute([$applianceId]);
    $a = $stmt->fetch();
    if (!$a || empty($a['active'])) throw new Exception("Choose an appliance from the list.");

    $t = $pdo->prepare("SELECT status, move_in_date FROM tenants WHERE id = ?");
    $t->execute([$tenantId]);
    $tenant = $t->fetch();
    if (!$tenant) throw new Exception("Tenant not found.");
    if ($tenant['status'] !== 'active') throw new Exception("Re-activate this tenant before adding appliances.");
    if ($startDate !== null && !empty($tenant['move_in_date']) && $startDate < $tenant['move_in_date']) {
        throw new Exception("The start date can't be before the tenant moved in (" . date('M j, Y', strtotime($tenant['move_in_date'])) . ").");
    }

    $dup = $pdo->prepare("SELECT COUNT(*) FROM tenant_appliances WHERE tenant_id = ? AND appliance_id = ? AND ended_at IS NULL");
    $dup->execute([$tenantId, $applianceId]);
    if ((int)$dup->fetchColumn() > 0) {
        throw new Exception("The tenant is already charged for {$a['name']}. Remove it first to change how many.");
    }

    $pdo->prepare("INSERT INTO tenant_appliances (tenant_id, appliance_id, quantity, start_date, created_at) VALUES (?, ?, ?, ?, ?)")
        ->execute([$tenantId, $applianceId, $quantity, $startDate, date('Y-m-d H:i:s')]);
    return applianceLabel($a['name'], $quantity);
}

/**
 * Stop charging a tenant for an appliance after $date's month. Call inside a transaction.
 * Charges already posted for later months are taken back; removed on its first day (or before it
 * started), it counts as never charged.
 * @return array label, refunded
 */
function removeTenantAppliance(PDO $pdo, int $tenantApplianceId, int $tenantId, string $date): array {
    $stmt = $pdo->prepare("SELECT x.quantity, x.start_date, x.ended_at, a.name, t.move_in_date
                           FROM tenant_appliances x JOIN appliances a ON a.id = x.appliance_id JOIN tenants t ON t.id = x.tenant_id
                           WHERE x.id = ? AND x.tenant_id = ? " . sqlForUpdateOf('x'));
    $stmt->execute([$tenantApplianceId, $tenantId]);
    $x = $stmt->fetch();
    if (!$x) throw new Exception("That appliance is not charged to this tenant.");
    $label = applianceLabel($x['name'], (int)$x['quantity']);
    if ($x['ended_at'] !== null) throw new Exception("$label was already removed.");

    $start = $x['start_date'] ?? $x['move_in_date'];
    $cancelled = $start === null || $date <= $start;
    if ($start !== null && $date < $start) $date = $start;

    $kind = applianceChargeKind($tenantApplianceId);
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
    $pdo->prepare("UPDATE tenant_appliances SET ended_at = ? WHERE id = ?")->execute([$date, $tenantApplianceId]);
    return ['label' => $label, 'refunded' => $refunded, 'cancelled' => $cancelled];
}

/** Moving out: stop every appliance charge. Call inside a transaction. */
function removeAllTenantAppliances(PDO $pdo, int $tenantId, string $date): void {
    $stmt = $pdo->prepare("SELECT id FROM tenant_appliances WHERE tenant_id = ? AND ended_at IS NULL");
    $stmt->execute([$tenantId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        removeTenantAppliance($pdo, (int)$id, $tenantId, $date);
    }
}

/** Post every appliance charge that is due to be posted up to the current month. */
function billAppliances(PDO $pdo, ?int $onlyTenantId = null): void {
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');
    $sql = "SELECT x.id FROM tenant_appliances x JOIN tenants t ON t.id = x.tenant_id
            WHERE x.ended_at IS NULL AND t.status = 'active' AND t.move_in_date IS NOT NULL
              AND COALESCE(x.start_date, t.move_in_date) <= ?
              AND (x.last_billed_month IS NULL OR x.last_billed_month < ?)";
    $params = [$today, $currentMonth];
    if ($onlyTenantId !== null) {
        $sql .= " AND x.tenant_id = ?";
        $params[] = $onlyTenantId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ownTransaction = !$pdo->inTransaction();
        try {
            if ($ownTransaction) $pdo->beginTransaction();
            billTenantAppliance($pdo, (int)$id, $today, $currentMonth);
            if ($ownTransaction) $pdo->commit();
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            error_log("Billing error for tenant appliance $id: " . $e->getMessage());
            if (!$ownTransaction) throw $e;
        }
    }
}

// Post one appliance's charge for every month from its last billed month up to now.
function billTenantAppliance(PDO $pdo, int $id, string $today, string $currentMonth): void {
    $stmt = $pdo->prepare("
        SELECT x.tenant_id, x.quantity, x.start_date, x.ended_at, x.last_billed_month,
               t.status, t.move_in_date, a.name, a.monthly_fee
        FROM tenant_appliances x JOIN tenants t ON t.id = x.tenant_id JOIN appliances a ON a.id = x.appliance_id
        WHERE x.id = ?
        " . sqlForUpdateOf('x') . "
    ");
    $stmt->execute([$id]);
    $x = $stmt->fetch();
    if (!$x || $x['ended_at'] !== null || $x['status'] !== 'active' || empty($x['move_in_date'])) return;

    // Never before the tenant's move-in (a tenant who came back starts a new stay).
    $start = max((string)($x['start_date'] ?? ''), $x['move_in_date']);
    if ($start > $today) return;
    if ($x['last_billed_month'] !== null && $x['last_billed_month'] >= $currentMonth) return;

    $startMonth = date('Y-m', strtotime($start));
    $month = $x['last_billed_month'] === null ? $startMonth : nextBillingMonth($x['last_billed_month']);
    if ($month < $startMonth) $month = $startMonth;
    $quantity = max(1, (int)$x['quantity']);
    $fee = round((float)$x['monthly_fee'] * $quantity, 2);
    $label = 'Appliance: ' . applianceLabel($x['name'], $quantity);
    $kind = applianceChargeKind($id);

    $ins = $pdo->prepare("
        INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date)
        VALUES (?, NULL, ?, ?, ?, ?, ?)
        " . sqlInsertIgnore(['tenant_id', 'billing_month', 'kind'], 'tenant_id') . "
    ");
    $addBalance = $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?");

    for (; $fee > 0 && $month <= $currentMonth; $month = nextBillingMonth($month)) {
        $monthName = date('F Y', strtotime($month . '-01'));
        if ($month === $startMonth) {
            $amount = firstMonthRent($fee, $start);
            $due = firstMonthDueDate($start);
            $desc = "$label, $monthName (" . (isHalfMonthMoveIn($start) ? 'half' : 'full') . ' month, from ' . date('M j', strtotime($start)) . ')';
        } else {
            $amount = $fee;
            $due = billingDueDate($month);
            $desc = "$label, $monthName";
        }
        $ins->execute([$x['tenant_id'], $kind, $month, mb_substr($desc, 0, 255), $amount, $due]);
        if ($ins->rowCount() === 1) {
            $addBalance->execute([$amount, $x['tenant_id']]);
        }
    }
    $pdo->prepare("UPDATE tenant_appliances SET last_billed_month = ? WHERE id = ?")->execute([$currentMonth, $id]);
}
