<?php
// includes/damages.php
//
// Damage charges: something in the boarding house the owner finds broken or damaged (a window, a
// door lock, a mattress...), charged once to the tenant responsible.
//
//  - The admin records it on the tenant's page (Damage Charges tab): what was damaged, the amount,
//    when it was found, when it is due, and optionally a note and a photo as proof.
//  - It is posted straight away as a charge on the tenant's balance (kind 'dmg_<id>'), so it shows
//    in their ledger, counts toward what they owe and turns overdue after its due date like rent.
//  - It can be charged to a tenant who has already moved out (damage found after they left).
//  - The tenant is texted about it (when SMS is set up and the admin leaves that ticked) and sees
//    it on their dashboard.
//  - Damage in a shared room can be split equally with the roommates living there: each gets
//    their own charge for their share, linked by split_group.
//  - A charge entered by mistake can be removed: it is taken back off the balance (a split
//    charge, off everyone sharing it).

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/sms_templates.php';

function damageChargeKind(int $damageId): string {
    return 'dmg_' . $damageId;
}

/** A tenant's damage charges, newest first, with the room number they were found in. */
function tenantDamageCharges(PDO $pdo, int $tenantId): array {
    $stmt = $pdo->prepare("SELECT d.*, r.room_number FROM damage_charges d LEFT JOIN rooms r ON r.id = d.room_id
                           WHERE d.tenant_id = ? ORDER BY d.found_on DESC, d.id DESC");
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll();
}

/**
 * Active tenants living in a room: those whose main room it is and those renting it as an
 * additional room. Each: id, first_name, last_name, contact_number. For splitting a damage.
 */
function roomOccupants(PDO $pdo, int $roomId): array {
    $stmt = $pdo->prepare("SELECT t.id, t.first_name, t.last_name, t.contact_number FROM tenants t
                           WHERE t.status = 'active' AND (t.room_id = ? OR EXISTS (
                               SELECT 1 FROM tenant_rooms x WHERE x.tenant_id = t.id AND x.room_id = ? AND x.ended_at IS NULL))
                           ORDER BY t.first_name, t.last_name");
    $stmt->execute([$roomId, $roomId]);
    return $stmt->fetchAll();
}

/**
 * Split an amount into $n equal shares in centavos; the first share takes any leftover centavo,
 * so the shares always add up to the amount exactly.
 * @return float[]
 */
function splitAmount(float $amount, int $n): array {
    $n = max(1, $n);
    $cents = (int)round($amount * 100);
    $each = intdiv($cents, $n);
    $shares = array_fill(0, $n, $each);
    $shares[0] += $cents - $each * $n;
    return array_map(fn($c) => $c / 100, $shares);
}

/**
 * Record a damage charge and post it to the balance: all of it to $tenantId, or split equally
 * between $tenantId and the roommates in $splitWith (tenant ids living in the same room).
 * Runs its own transaction.
 * @param array $d item, note, amount (the whole amount), found_on, due_date, room_id (or null), photo_path (or null)
 * @return array tenant id => [id (damage charge), share], $tenantId first
 */
function addDamageCharge(PDO $pdo, int $tenantId, array $d, array $splitWith = []): array {
    $item = mb_substr(trim((string)($d['item'] ?? '')), 0, 120);
    $note = trim((string)($d['note'] ?? ''));
    $amount = round((float)($d['amount'] ?? 0), 2);
    $foundOn = (string)($d['found_on'] ?? '');
    $dueDate = (string)($d['due_date'] ?? '');
    $roomId = !empty($d['room_id']) ? (int)$d['room_id'] : null;
    $splitWith = array_values(array_unique(array_filter(array_map('intval', $splitWith), fn($id) => $id > 0 && $id !== $tenantId)));

    if ($item === '') throw new Exception('Say what was damaged, e.g. "Broken window".');
    if ($amount <= 0) throw new Exception('Enter the amount to charge.');
    foreach (['found' => $foundOn, 'due' => $dueDate] as $what => $date) {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) throw new Exception("Enter a valid $what date.");
    }
    if ($foundOn > date('Y-m-d')) throw new Exception("The date it was found can't be in the future.");
    if ($dueDate < $foundOn) throw new Exception("The due date can't be before the date it was found.");
    if ($splitWith) {
        if (!$roomId) throw new Exception("Choose the room to split the charge with its tenants.");
        // Only tenants who live in that room now.
        $living = array_map(fn($o) => (int)$o['id'], roomOccupants($pdo, $roomId));
        foreach ($splitWith as $id) {
            if (!in_array($id, $living, true)) throw new Exception("Only tenants living in that room can share the charge.");
        }
        if ($amount < 0.01 * (count($splitWith) + 1)) throw new Exception("The amount is too small to split.");
    }

    $tenantIds = array_merge([$tenantId], $splitWith);
    $shares = splitAmount($amount, count($tenantIds));
    $group = $splitWith ? bin2hex(random_bytes(8)) : null;

    $pdo->beginTransaction();
    try {
        $roomNo = null;
        if ($roomId) {
            $r = $pdo->prepare("SELECT room_number FROM rooms WHERE id = ?");
            $r->execute([$roomId]);
            $roomNo = $r->fetchColumn() ?: null;
        }
        $lock = $pdo->prepare("SELECT id FROM tenants WHERE id = ? FOR UPDATE");
        $insDamage = "INSERT INTO damage_charges (tenant_id, room_id, item, note, amount, found_on, due_date, photo_path, split_group, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insCharge = $pdo->prepare("INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $addBalance = $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?");
        $desc = 'Damage: ' . $item . ($roomNo ? " (Room $roomNo)" : '') . ', found ' . date('M j, Y', strtotime($foundOn))
              . ($group ? ' - share of PHP ' . number_format($amount, 2) . ' split ' . count($tenantIds) . ' ways' : '');

        $out = [];
        foreach ($tenantIds as $i => $tid) {
            $lock->execute([$tid]);
            if (!$lock->fetchColumn()) throw new Exception("Tenant not found.");
            $share = $shares[$i];
            $id = insertReturningId($pdo, $insDamage,
                [$tid, $roomId, $item, $note !== '' ? mb_substr($note, 0, 2000) : null, $share, $foundOn, $dueDate,
                 $d['photo_path'] ?? null, $group, date('Y-m-d H:i:s')]);
            $insCharge->execute([$tid, $roomId, damageChargeKind($id), date('Y-m', strtotime($dueDate)), mb_substr($desc, 0, 255), $share, $dueDate]);
            $addBalance->execute([$share, $tid]);
            $out[$tid] = ['id' => $id, 'share' => $share];
        }
        $pdo->commit();
        return $out;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * The tenants sharing a split damage charge: split_group => list of [tenant_id, name, amount].
 * @param string[] $groups
 */
function damageSplitShares(PDO $pdo, array $groups): array {
    $groups = array_values(array_unique(array_filter($groups)));
    if (!$groups) return [];
    $stmt = $pdo->prepare("SELECT d.split_group, d.tenant_id, d.amount, t.first_name, t.last_name FROM damage_charges d
                           JOIN tenants t ON t.id = d.tenant_id
                           WHERE d.split_group IN (" . implode(',', array_fill(0, count($groups), '?')) . ")
                           ORDER BY d.id");
    $stmt->execute($groups);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['split_group']][] = ['tenant_id' => (int)$row['tenant_id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                                       'amount' => (float)$row['amount']];
    }
    return $out;
}

/**
 * Remove a damage charge entered by mistake: its charge comes off the balance. A split charge is
 * removed from every tenant sharing it.
 * @return ?array the removed row (item, photo_path, ...) plus 'tenants' (how many it was taken off),
 *                or null when there was none
 */
function removeDamageCharge(PDO $pdo, int $damageId, int $tenantId): ?array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM damage_charges WHERE id = ? AND tenant_id = ? FOR UPDATE");
        $stmt->execute([$damageId, $tenantId]);
        $d = $stmt->fetch();
        if (!$d) {
            $pdo->commit();
            return null;
        }
        $rows = [$d];
        if (!empty($d['split_group'])) {
            $all = $pdo->prepare("SELECT * FROM damage_charges WHERE split_group = ? FOR UPDATE");
            $all->execute([$d['split_group']]);
            $rows = $all->fetchAll();
        }
        $sum = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM charges WHERE tenant_id = ? AND kind = ?");
        $delCharges = $pdo->prepare("DELETE FROM charges WHERE tenant_id = ? AND kind = ?");
        $balance = $pdo->prepare("UPDATE tenants SET balance = balance - ? WHERE id = ?");
        $delDamage = $pdo->prepare("DELETE FROM damage_charges WHERE id = ?");
        foreach ($rows as $row) {
            $kind = damageChargeKind((int)$row['id']);
            $sum->execute([$row['tenant_id'], $kind]);
            $posted = round((float)$sum->fetchColumn(), 2);
            $delCharges->execute([$row['tenant_id'], $kind]);
            $balance->execute([$posted, $row['tenant_id']]);
            $delDamage->execute([$row['id']]);
        }
        $pdo->commit();
        $d['tenants'] = count($rows);
        return $d;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** The text telling a tenant about a damage charge. */
function smsDamageChargeMessage(PDO $pdo, string $name, string $item, float $amount, string $dueDate, float $balance,
                                float $total = 0.0, int $splitCount = 1): string {
    return smsHeader($pdo) . "\n\n"
         . "Hello, $name! A charge was added to your account for damage in the boarding house.\n\n"
         . "Item: $item\n"
         . ($splitCount > 1 ? 'Total: ' . smsPeso($total) . ", split among $splitCount tenants\n" : '')
         . ($splitCount > 1 ? 'Your Share: ' : 'Amount: ') . smsPeso($amount) . "\n"
         . 'Due Date: ' . smsDate($dueDate) . "\n"
         . 'Outstanding Balance: ' . smsPeso(max(0, $balance)) . "\n\n"
         . 'Please contact the management if you have questions. Thank you!';
}
