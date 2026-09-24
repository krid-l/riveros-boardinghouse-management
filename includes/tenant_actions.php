<?php
// includes/tenant_actions.php
// Tenant add / edit / room change / deactivate / re-activate / delete.
// Shared by the Tenants tab and the Rooms tab so both behave identically.

require_once __DIR__ . '/billing.php';

function postedDate(string $field): string {
    $value = trim($_POST[$field] ?? '');
    if ($value === '') return date('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        throw new Exception("Invalid date.");
    }
    return $value;
}

/** Runs the posted tenant action. Returns ['action' =>, 'success' =>, 'error' =>]. */
function handleTenantAction(PDO $pdo): array {
    $error = '';
    $success = '';
    $action = $_POST['action'];
    $billTenantId = null;
    try {
        $pdo->beginTransaction();

        if ($action === 'add') {
            $firstName = trim($_POST['first_name']);
            $lastName = trim($_POST['last_name']);
            $roomId = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;
            $moveInDate = $roomId ? postedDate('move_in_date') : null;

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

            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, temp_password, role) VALUES (?, ?, ?, 'tenant') RETURNING id");
            $stmt->execute([$username, $password, $rawPassword]);
            $userId = $stmt->fetchColumn();

            // Create tenant profile
            $stmt = $pdo->prepare("INSERT INTO tenants (user_id, first_name, last_name, contact_number, room_id, occupation, emergency_contact, status, move_in_date, balance)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, 0) RETURNING id");
            $stmt->execute([
                $userId,
                $firstName,
                $lastName,
                $_POST['contact_number'],
                $roomId,
                !empty($_POST['occupation']) ? $_POST['occupation'] : null,
                !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null,
                $moveInDate
            ]);
            $tenantId = (int)$stmt->fetchColumn();
            if ($roomId) {
                logRoomTransfer($pdo, $tenantId, null, $roomId, $moveInDate, 'Moved in');
                $billTenantId = $tenantId;
            }

            $success = "Tenant added successfully! <br><strong>Username:</strong> " . htmlspecialchars($username) . " <br><strong>Password:</strong> " . htmlspecialchars($rawPassword) . " <br><small>Please save these credentials!</small>";
            if ($moveInDate) {
                $firstRent = isHalfMonthMoveIn($moveInDate) ? 'half' : 'full';
                $success .= "<br><small>First bill: $firstRent month's rent, due " . date('M j, Y', strtotime(firstMonthDueDate($moveInDate))) . ".</small>";
            }

        } elseif ($action === 'edit') {
            $stmt = $pdo->prepare("UPDATE tenants SET first_name = ?, last_name = ?, contact_number = ?, occupation = ?, emergency_contact = ? WHERE id = ?");
            $stmt->execute([
                trim($_POST['first_name']),
                trim($_POST['last_name']),
                $_POST['contact_number'],
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
                $success = "Room assigned. First bill is due " . date('M j, Y', strtotime(firstMonthDueDate($date))) . ".";
            } else {
                $pdo->prepare("UPDATE tenants SET room_id = ? WHERE id = ?")->execute([$newRoomId, $tenantId]);
                logRoomTransfer($pdo, $tenantId, $t['room_id'] ? (int)$t['room_id'] : null, $newRoomId, $date, 'Room change');
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

            // Keep the tenant's payments, receipts and any unpaid balance on record; just free the bed and block login.
            $pdo->prepare("UPDATE tenants SET status = 'deactivated', deactivated_at = ?, room_id = NULL WHERE id = ?")->execute([$date, $tenantId]);
            logRoomTransfer($pdo, $tenantId, $t['room_id'] ? (int)$t['room_id'] : null, null, $date, 'Moved out (account deactivated)');

            $success = "Tenant removed and account deactivated.";
            if ((float)$t['balance'] > 0) {
                $success .= " They still have an unpaid balance of ₱" . number_format((float)$t['balance'], 2) . ".";
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
            $success = "Tenant re-activated.";

        } elseif ($action === 'delete') {
            // Permanent delete is only for tenants added by mistake: anyone with payment history must be deactivated instead.
            $tenantId = (int)$_POST['tenant_id'];
            $stmt = $pdo->prepare("SELECT user_id, (SELECT COUNT(*) FROM payments WHERE tenant_id = tenants.id) AS pay_count FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $t = $stmt->fetch();
            if (!$t) throw new Exception("Tenant not found.");
            if ($t['pay_count'] > 0) throw new Exception("This tenant has payment records. Deactivate the account instead of deleting it.");
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$t['user_id']]);
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

    return ['action' => $action, 'success' => $success, 'error' => $error];
}
