<?php
// includes/tenant_actions.php
// Tenant add / edit / room change / deactivate / re-activate / delete.
// Shared by the Tenants tab and the Rooms tab so both behave identically.

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/pdf_generator.php';

function postedDate(string $field): string {
    $value = trim($_POST[$field] ?? '');
    if ($value === '') return date('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        throw new Exception("Invalid date.");
    }
    return $value;
}

/**
 * The money a tenant hands over when moving in: the first month in advance plus the deposit,
 * as posted for their move-in month and not yet paid. Zero when there is nothing left to pay.
 * @return array advance, deposit, amount (what is still owed of the two)
 */
function moveInAmountDue(PDO $pdo, int $tenantId): array {
    $stmt = $pdo->prepare("SELECT move_in_date, balance, status FROM tenants WHERE id = ?");
    $stmt->execute([$tenantId]);
    $t = $stmt->fetch();
    if (!$t || $t['status'] !== 'active' || empty($t['move_in_date'])) {
        return ['advance' => 0.0, 'deposit' => 0.0, 'amount' => 0.0];
    }
    $charges = $pdo->prepare("SELECT kind, SUM(amount) FROM charges
                              WHERE tenant_id = ? AND billing_month = ? AND kind IN ('rent', ?)
                              GROUP BY kind");
    $charges->execute([$tenantId, date('Y-m', strtotime($t['move_in_date'])), DEPOSIT_KIND]);
    $byKind = $charges->fetchAll(PDO::FETCH_KEY_PAIR);
    $advance = round((float)($byKind['rent'] ?? 0), 2);
    $deposit = round((float)($byKind[DEPOSIT_KIND] ?? 0), 2);
    // Payments come off the oldest charges first, and these are the oldest of this stay, so
    // whatever is still owed (up to their total) is the unpaid part of them.
    $amount = round(min($advance + $deposit, max(0, (float)$t['balance'])), 2);
    return ['advance' => $advance, 'deposit' => $deposit, 'amount' => $amount];
}

/**
 * Record the advance and deposit as received at move-in: a verified payment with a receipt,
 * so the tenant starts at a zero balance and the money counts as collected.
 *
 * @return ?array the payment (id, amount, advance, deposit, sms), or null if nothing was owed
 */
function recordMoveInPayment(PDO $pdo, int $tenantId, string $method, string $reference): ?array {
    $method = $method === 'gcash' ? 'gcash' : 'cash';
    $reference = trim($reference);

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT first_name, last_name, contact_number, move_in_date FROM tenants WHERE id = ? FOR UPDATE");
        $lock->execute([$tenantId]);
        $tenant = $lock->fetch();
        $due = $tenant ? moveInAmountDue($pdo, $tenantId) : ['amount' => 0];
        if ($due['amount'] <= 0) {
            $pdo->commit();
            return null;
        }
        // Paid on the move-in day; a move-in entered for a future date was paid today.
        $paidOn = min($tenant['move_in_date'], date('Y-m-d'));
        $paymentId = insertReturningId($pdo,
            "INSERT INTO payments (tenant_id, amount, payment_date, reference_number, screenshot_path, payment_method, status, pay_for_room)
             VALUES (?, ?, ?, ?, NULL, ?, 'verified', ?)",
            [$tenantId, $due['amount'], $paidOn, $reference !== '' ? substr($reference, 0, 100) : 'MOVE-IN', $method, dbBool(false)]
        );
        $pdo->prepare("UPDATE tenants SET balance = balance - ? WHERE id = ?")->execute([$due['amount'], $tenantId]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $name = $tenant['first_name'] . ' ' . $tenant['last_name'];
    try {
        $receiptPath = generateReceipt($paymentId, $name, $due['amount'], $paidOn,
                                       $reference !== '' ? $reference : 'MOVE-IN', $method, 'Advance + Deposit',
                                       date('Y-m', strtotime($tenant['move_in_date'])));
        $pdo->prepare("UPDATE payments SET receipt_path = ? WHERE id = ?")->execute([$receiptPath, $paymentId]);
    } catch (Exception $e) {
        error_log("Move-in receipt for payment $paymentId failed: " . $e->getMessage());   // the payment itself stands
    }

    $parts = 'advance PHP ' . number_format($due['advance'], 2)
           . ($due['deposit'] > 0 ? ' + deposit PHP ' . number_format($due['deposit'], 2) : '');
    $sms = sendSMS($pdo, $tenant['contact_number'],
        smsConfig($pdo)['name'] . ": Hi {$tenant['first_name']}, welcome! We received your move-in payment of PHP "
        . number_format($due['amount'], 2) . " ($parts). Receipt: RCP-" . str_pad($paymentId, 6, '0', STR_PAD_LEFT) . '.',
        'move_in_payment', $tenantId);

    return ['id' => $paymentId, 'amount' => $due['amount'], 'advance' => $due['advance'], 'deposit' => $due['deposit'], 'sms' => $sms];
}

/** "Move-in payment of ₱8,000.00 recorded (...). Receipt RCP-000012." for the admin. */
function moveInPaymentMessage(?array $payment): string {
    if ($payment === null) {
        return '';
    }
    // Less than advance + deposit when part of it was already covered, e.g. by a credit.
    $covered = round($payment['advance'] + $payment['deposit'] - $payment['amount'], 2);
    $text = 'Move-in payment of ₱' . number_format($payment['amount'], 2) . ' recorded as paid ('
          . '₱' . number_format($payment['advance'], 2) . ' advance'
          . ($payment['deposit'] > 0 ? ' + ₱' . number_format($payment['deposit'], 2) . ' deposit' : '')
          . ($covered > 0 ? ', less ₱' . number_format($covered, 2) . ' already paid or credited' : '')
          . '). Receipt RCP-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT) . '.';
    $smsText = smsOutcomeText($payment['sms']);
    // Only mention SMS when it was actually set up; otherwise every move-in would nag about it.
    if ($payment['sms']['sent'] > 0 || $payment['sms']['failed'] > 0) {
        $text .= ' ' . $smsText;
    }
    return $text;
}

/** Runs the posted tenant action. Returns ['action' =>, 'success' =>, 'error' =>]. */
function handleTenantAction(PDO $pdo): array {
    $error = '';
    $success = '';
    $action = $_POST['action'];
    $billTenantId = null;
    $resplitRooms = [];   // rooms whose occupancy changed: their rent shares need recomputing
    $moveInTenantId = null;   // a tenant moving in: their advance and deposit can be marked received
    $moveInDueNote = '';      // what to tell the admin when they weren't

    // The advance and deposit, received after the fact (the tenant was added without ticking it).
    if ($action === 'movein_payment') {
        try {
            $payment = recordMoveInPayment($pdo, (int)($_POST['tenant_id'] ?? 0),
                                           (string)($_POST['movein_method'] ?? 'cash'), (string)($_POST['movein_reference'] ?? ''));
            if ($payment === null) {
                return ['action' => $action, 'success' => '', 'error' => 'Error: There is no unpaid advance or deposit for this tenant.'];
            }
            return ['action' => $action, 'success' => htmlspecialchars(moveInPaymentMessage($payment)), 'error' => ''];
        } catch (Exception $e) {
            return ['action' => $action, 'success' => '', 'error' => 'Error: ' . $e->getMessage()];
        }
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'add') {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $roomId = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
            $moveInDate = $roomId ? postedDate('move_in_date') : null;
            $contactNumber = validatedMobileInput($_POST['contact_number'] ?? '');

            if ($roomId && !roomHasSpace($pdo, $roomId)) {
                throw new Exception("Cannot assign tenant: Room is already full.");
            }

            // Auto-generate username (e.g., juan.delacruz)
            $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName) . '.' . preg_replace('/[^a-zA-Z0-9]/', '', $lastName));
            $username = $baseUsername;

            // Ensure username uniqueness
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $checkStmt->execute([$username]);
            $counter = 1;
            while ($checkStmt->fetchColumn() > 0) {
                $username = $baseUsername . $counter;
                $checkStmt->execute([$username]);
                $counter++;
            }

            // Auto-generate a random 8-character password
            $rawPassword = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%'), 0, 8);
            $password = password_hash($rawPassword, PASSWORD_DEFAULT);

            $userId = insertReturningId(
                $pdo,
                "INSERT INTO users (username, password_hash, temp_password, role) VALUES (?, ?, ?, 'tenant')",
                [$username, $password, $rawPassword]
            );

            // Create tenant profile
            $tenantId = insertReturningId(
                $pdo,
                "INSERT INTO tenants (user_id, first_name, last_name, contact_number, room_id, occupation, emergency_contact, status, move_in_date, balance)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, 0)",
                [
                    $userId,
                    $firstName,
                    $lastName,
                    $contactNumber,
                    $roomId,
                    !empty($_POST['occupation']) ? $_POST['occupation'] : null,
                    !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null,
                    $moveInDate
                ]
            );
            if ($roomId) {
                logRoomTransfer($pdo, $tenantId, null, $roomId, $moveInDate, 'Moved in');
                $billTenantId = $tenantId;
                $moveInTenantId = $tenantId;
            }

            $success = "Tenant added successfully! <br><strong>Username:</strong> " . htmlspecialchars($username) . " <br><strong>Password:</strong> " . htmlspecialchars($rawPassword) . " <br><small>Please save these credentials!</small>";
            if ($moveInDate && $roomId) {
                $firstRent = isHalfMonthMoveIn($moveInDate) ? 'half' : 'full';
                $moveInDueNote = "<br><small>Due on moving in (" . date('M j, Y', strtotime(firstMonthDueDate($moveInDate))) . "): "
                               . "the first month in advance ($firstRent month) plus a deposit of one month's share, "
                               . "which pays for their last month.</small>";
            }

        } elseif ($action === 'edit') {
            $stmt = $pdo->prepare("UPDATE tenants SET first_name = ?, last_name = ?, contact_number = ?, occupation = ?, emergency_contact = ? WHERE id = ?");
            $stmt->execute([
                trim($_POST['first_name'] ?? ''),
                trim($_POST['last_name'] ?? ''),
                validatedMobileInput($_POST['contact_number'] ?? ''),
                !empty($_POST['occupation']) ? $_POST['occupation'] : null,
                !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null,
                (int)$_POST['tenant_id']
            ]);
            $success = "Tenant updated successfully.";

        } elseif ($action === 'change_room') {
            $tenantId = (int)$_POST['tenant_id'];
            $newRoomId = (int)($_POST['room_id'] ?? 0);
            $date = postedDate('effective_date');

            $stmt = $pdo->prepare("SELECT room_id, status, move_in_date FROM tenants WHERE id = ? FOR UPDATE");
            $stmt->execute([$tenantId]);
            $t = $stmt->fetch();
            if (!$t) throw new Exception("Tenant not found.");
            if ($t['status'] !== 'active') throw new Exception("Re-activate this tenant before assigning a room.");
            if (!$newRoomId) throw new Exception("Please choose a room.");
            if ((int)$t['room_id'] === $newRoomId) throw new Exception("The tenant is already in that room.");
            if (!roomHasSpace($pdo, $newRoomId, $tenantId)) throw new Exception("Selected room is already full.");

            if (empty($t['move_in_date'])) {
                // First room assignment: this is their move-in, so first-month billing starts here.
                $pdo->prepare("UPDATE tenants SET room_id = ?, move_in_date = ? WHERE id = ?")->execute([$newRoomId, $date, $tenantId]);
                logRoomTransfer($pdo, $tenantId, null, $newRoomId, $date, 'Moved in');
                $billTenantId = $tenantId;
                $moveInTenantId = $tenantId;
                $resplitRooms[] = $newRoomId;
                $success = "Room assigned.";
                $moveInDueNote = " The first month in advance and the deposit are due "
                               . date('M j, Y', strtotime(firstMonthDueDate($date))) . ".";
            } else {
                $pdo->prepare("UPDATE tenants SET room_id = ? WHERE id = ?")->execute([$newRoomId, $tenantId]);
                logRoomTransfer($pdo, $tenantId, $t['room_id'] ? (int)$t['room_id'] : null, $newRoomId, $date, 'Room change');
                $resplitRooms[] = $newRoomId;
                if ($t['room_id']) $resplitRooms[] = (int)$t['room_id'];
                $success = "Room changed. The new room's rent applies from next month's bill.";
            }

        } elseif ($action === 'deactivate') {
            $tenantId = (int)$_POST['tenant_id'];
            $date = postedDate('deactivated_at');

            $stmt = $pdo->prepare("SELECT room_id, status, balance FROM tenants WHERE id = ? FOR UPDATE");
            $stmt->execute([$tenantId]);
            $t = $stmt->fetch();
            if (!$t) throw new Exception("Tenant not found.");
            if ($t['status'] === 'deactivated') throw new Exception("Tenant is already deactivated.");

            // The deposit pays for the month they leave. Done before the status changes, while the
            // stay it belongs to is still the current one.
            $depositApplied = applyDepositToLastMonth($pdo, $tenantId, $date);

            // Keep the tenant's payments, receipts and any unpaid balance on record; just free the bed and block login.
            $pdo->prepare("UPDATE tenants SET status = 'deactivated', deactivated_at = ?, room_id = NULL WHERE id = ?")->execute([$date, $tenantId]);
            logRoomTransfer($pdo, $tenantId, $t['room_id'] ? (int)$t['room_id'] : null, null, $date, 'Moved out (account deactivated)');
            if ($t['room_id']) $resplitRooms[] = (int)$t['room_id'];

            $balStmt = $pdo->prepare("SELECT balance FROM tenants WHERE id = ?");
            $balStmt->execute([$tenantId]);
            $finalBalance = round((float)$balStmt->fetchColumn(), 2);

            $success = "Tenant removed and account deactivated.";
            if ($depositApplied > 0) {
                $success .= " Their ₱" . number_format($depositApplied, 2) . " deposit was applied to the last month.";
            }
            if ($finalBalance > 0) {
                $success .= " They still have an unpaid balance of ₱" . number_format($finalBalance, 2) . ".";
            } elseif ($finalBalance < 0) {
                $success .= " ₱" . number_format(-$finalBalance, 2) . " is owed back to them.";
            }

        } elseif ($action === 'reactivate') {
            $tenantId = (int)$_POST['tenant_id'];
            $roomId = (int)($_POST['room_id'] ?? 0);
            $date = postedDate('move_in_date');

            $stmt = $pdo->prepare("SELECT status FROM tenants WHERE id = ? FOR UPDATE");
            $stmt->execute([$tenantId]);
            $status = $stmt->fetchColumn();
            if ($status === false) throw new Exception("Tenant not found.");
            if ($status !== 'deactivated') throw new Exception("Tenant is already active.");
            if (!$roomId) throw new Exception("Please choose a room.");
            if (!roomHasSpace($pdo, $roomId, $tenantId)) throw new Exception("Selected room is already full.");

            // A return is a new move-in: the first-month (full/half) rule applies again.
            $pdo->prepare("UPDATE tenants SET status = 'active', deactivated_at = NULL, room_id = ?, move_in_date = ? WHERE id = ?")
                ->execute([$roomId, $date, $tenantId]);
            logRoomTransfer($pdo, $tenantId, null, $roomId, $date, 'Moved in again (account re-activated)');
            $billTenantId = $tenantId;
            $moveInTenantId = $tenantId;
            $resplitRooms[] = $roomId;
            $success = "Tenant re-activated.";
            $moveInDueNote = " The first month in advance and the deposit are due "
                           . date('M j, Y', strtotime(firstMonthDueDate($date))) . ".";

        } elseif ($action === 'delete') {
            // Permanent delete is only for tenants added by mistake: anyone with payment history must be deactivated instead.
            $tenantId = (int)$_POST['tenant_id'];
            $stmt = $pdo->prepare("SELECT user_id, (SELECT COUNT(*) FROM payments WHERE tenant_id = tenants.id) AS pay_count FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $t = $stmt->fetch();
            if (!$t) throw new Exception("Tenant not found.");
            if ($t['pay_count'] > 0) throw new Exception("This tenant has payment records. Deactivate the account instead of deleting it.");
            $roomStmt = $pdo->prepare("SELECT room_id FROM tenants WHERE id = ?");
            $roomStmt->execute([$tenantId]);
            $freedRoom = $roomStmt->fetchColumn();
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$t['user_id']]);
            if ($freedRoom) $resplitRooms[] = (int)$freedRoom;
            $success = "Tenant deleted.";

        } else {
            throw new Exception("Unknown action.");
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
        $success = '';
    }

    // Post the first-month charge right away instead of waiting for the next page load.
    if (!$error && $billTenantId) {
        runBilling($pdo, $billTenantId);
    }

    // Everyone sharing an affected room now owes an equal share of it for this month.
    if (!$error) {
        foreach (array_unique($resplitRooms) as $roomId) {
            try {
                resplitRoomRent($pdo, (int)$roomId);
            } catch (Exception $e) {
                error_log("Rent re-split error for room $roomId: " . $e->getMessage());
            }
        }
    }

    // Moving in means handing over the advance and deposit. Recorded last, once billing and the
    // room's re-split have settled what this tenant's share actually is.
    if (!$error && $moveInTenantId) {
        $payment = null;
        if (!empty($_POST['movein_paid'])) {
            try {
                $payment = recordMoveInPayment($pdo, $moveInTenantId,
                                               (string)($_POST['movein_method'] ?? 'cash'), (string)($_POST['movein_reference'] ?? ''));
            } catch (Exception $e) {
                error_log("Move-in payment for tenant $moveInTenantId failed: " . $e->getMessage());
                $success .= ' <br><strong>The move-in payment could not be recorded:</strong> ' . htmlspecialchars($e->getMessage());
            }
        }
        if ($payment !== null) {
            $success .= ($action === 'add' ? '<br>' : ' ') . htmlspecialchars(moveInPaymentMessage($payment));
        } else {
            $success .= $moveInDueNote;
        }
    }

    return ['action' => $action, 'success' => $success, 'error' => $error];
}
