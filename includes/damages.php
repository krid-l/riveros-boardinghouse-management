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
//  - A charge entered by mistake can be removed: it is taken back off the balance.

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
 * Record a damage charge and post it to the tenant's balance. Runs its own transaction.
 * @param array $d item, note, amount, found_on, due_date, room_id (or null), photo_path (or null)
 * @return int the damage charge's id
 */
function addDamageCharge(PDO $pdo, int $tenantId, array $d): int {
    $item = mb_substr(trim((string)($d['item'] ?? '')), 0, 120);
    $note = trim((string)($d['note'] ?? ''));
    $amount = round((float)($d['amount'] ?? 0), 2);
    $foundOn = (string)($d['found_on'] ?? '');
    $dueDate = (string)($d['due_date'] ?? '');
    $roomId = !empty($d['room_id']) ? (int)$d['room_id'] : null;

    if ($item === '') throw new Exception('Say what was damaged, e.g. "Broken window".');
    if ($amount <= 0) throw new Exception('Enter the amount to charge.');
    foreach (['found' => $foundOn, 'due' => $dueDate] as $what => $date) {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) throw new Exception("Enter a valid $what date.");
    }
    if ($foundOn > date('Y-m-d')) throw new Exception("The date it was found can't be in the future.");
    if ($dueDate < $foundOn) throw new Exception("The due date can't be before the date it was found.");

    $pdo->beginTransaction();
    try {
        $t = $pdo->prepare("SELECT id FROM tenants WHERE id = ? FOR UPDATE");
        $t->execute([$tenantId]);
        if (!$t->fetchColumn()) throw new Exception("Tenant not found.");

        $id = insertReturningId($pdo,
            "INSERT INTO damage_charges (tenant_id, room_id, item, note, amount, found_on, due_date, photo_path, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$tenantId, $roomId, $item, $note !== '' ? mb_substr($note, 0, 2000) : null, $amount, $foundOn, $dueDate,
             $d['photo_path'] ?? null, date('Y-m-d H:i:s')]);

        $roomNo = null;
        if ($roomId) {
            $r = $pdo->prepare("SELECT room_number FROM rooms WHERE id = ?");
            $r->execute([$roomId]);
            $roomNo = $r->fetchColumn() ?: null;
        }
        $desc = 'Damage: ' . $item . ($roomNo ? " (Room $roomNo)" : '') . ', found ' . date('M j, Y', strtotime($foundOn));
        $pdo->prepare("INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tenantId, $roomId, damageChargeKind($id), date('Y-m', strtotime($dueDate)), mb_substr($desc, 0, 255), $amount, $dueDate]);
        $pdo->prepare("UPDATE tenants SET balance = balance + ? WHERE id = ?")->execute([$amount, $tenantId]);
        $pdo->commit();
        return $id;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Remove a damage charge entered by mistake: its charge comes off the balance.
 * @return ?array the removed row (for its item and photo), or null when there was none
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
        $charge = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM charges WHERE tenant_id = ? AND kind = ?");
        $charge->execute([$tenantId, damageChargeKind($damageId)]);
        $posted = round((float)$charge->fetchColumn(), 2);
        $pdo->prepare("DELETE FROM charges WHERE tenant_id = ? AND kind = ?")->execute([$tenantId, damageChargeKind($damageId)]);
        $pdo->prepare("UPDATE tenants SET balance = balance - ? WHERE id = ?")->execute([$posted, $tenantId]);
        $pdo->prepare("DELETE FROM damage_charges WHERE id = ?")->execute([$damageId]);
        $pdo->commit();
        return $d;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** The text telling a tenant about a damage charge. */
function smsDamageChargeMessage(PDO $pdo, string $name, string $item, float $amount, string $dueDate, float $balance): string {
    return smsHeader($pdo) . "\n\n"
         . "Hello, $name! A charge was added to your account for damage in the boarding house.\n\n"
         . "Item: $item\n"
         . 'Amount: ' . smsPeso($amount) . "\n"
         . 'Due Date: ' . smsDate($dueDate) . "\n"
         . 'Outstanding Balance: ' . smsPeso(max(0, $balance)) . "\n\n"
         . 'Please contact the management if you have questions. Thank you!';
}
