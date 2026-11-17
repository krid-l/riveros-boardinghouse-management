<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/billing.php';
require_once '../includes/sms.php';
require_once '../includes/sms_reminders.php';
require_once '../includes/password_reset.php';
require_once '../includes/tenant_profile.php';
require_once '../includes/uploads.php';
require_once '../includes/sms_templates.php';
require_once '../includes/deposit_requests.php';
require_once '../includes/tenant_actions.php';
require_once '../includes/damages.php';
requireAdmin();

$reminderSuccess = '';
$reminderError   = '';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Tenant ID.");
}
$tenantId = (int)$_GET['id'];

// Reset Password: a new temporary password, shown here and texted to the tenant when SMS is set
// up. For a tenant who forgot theirs and can't get a code by SMS.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $tStmt = $pdo->prepare("SELECT t.user_id, t.first_name, t.contact_number, t.status, u.username FROM tenants t JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $tStmt->execute([$tenantId]);
    $tRow = $tStmt->fetch();
    if (!$tRow) {
        $reminderError = 'Tenant not found.';
    } else {
        $newPassword = generateTempPassword();
        $pdo->prepare("UPDATE users SET password_hash = ?, temp_password = ? WHERE id = ?")
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $newPassword, $tRow['user_id']]);
        $reminderSuccess = "Password reset. New temporary password: $newPassword";
        if ($tRow['status'] === 'active' && smsConfigured($pdo) && normalizePhMobile($tRow['contact_number'])) {
            $r = sendSMS($pdo, $tRow['contact_number'],
                smsConfig($pdo)['name'] . ": Hi {$tRow['first_name']}, your password was reset by the admin. Username: {$tRow['username']} Temporary password: $newPassword Please change it after you log in.",
                'password_reset', $tenantId);
            $reminderSuccess .= $r['sent'] > 0 ? ' (texted to the tenant).' : '. ' . smsOutcomeText($r);
        }
    }
}

// Edit the tenant's details: the same fields as Add Tenant and the tenant's Profile Settings.
$profileError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_profile' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    try {
        $profile = tenantProfileFromPost($_POST);
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($profile)));
        $pdo->prepare("UPDATE tenants SET $set WHERE id = ?")->execute(array_merge(array_values($profile), [$tenantId]));
        $reminderSuccess = 'Tenant details saved.';
    } catch (Exception $e) {
        $profileError = $e->getMessage();
        $reminderError = $profileError;
    }
}

// Documents: files the admin keeps for the tenant (a photo of an ID, a signed contract).
$docMessage = '';
$docError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120);
    $upload = isset($_FILES['document']) ? storeUploadedDocument($_FILES['document']) : ['path' => null, 'error' => 'Choose a file.'];
    if ($title === '') {
        $docError = 'Give the document a name, e.g. "Valid ID" or "Lease contract".';
        if ($upload['path']) deleteLocalUpload($upload['path']);
    } elseif ($upload['error'] || !$upload['path']) {
        $docError = $upload['error'] ?: 'Choose a file.';
    } else {
        $ext = strtolower(pathinfo(parse_url($upload['path'], PHP_URL_PATH) ?: $upload['path'], PATHINFO_EXTENSION));
        $pdo->prepare("INSERT INTO tenant_documents (tenant_id, title, file_path, file_type, uploaded_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$tenantId, $title, $upload['path'], $ext, date('Y-m-d H:i:s')]);
        $docMessage = 'Document saved.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_document' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $doc = $pdo->prepare("SELECT file_path FROM tenant_documents WHERE id = ? AND tenant_id = ?");
    $doc->execute([(int)($_POST['document_id'] ?? 0), $tenantId]);
    $path = $doc->fetchColumn();
    if ($path !== false) {
        $pdo->prepare("DELETE FROM tenant_documents WHERE id = ?")->execute([(int)$_POST['document_id']]);
        deleteLocalUpload($path);
        $docMessage = 'Document deleted.';
    }
}

// Rooms: rent the tenant another room on top of their main one, or release one of those.
// Appliances: start or stop a monthly appliance charge.
$roomMessage = '';
$roomError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['add_room', 'release_room', 'add_appliance', 'remove_appliance'], true)
    && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    ['success' => $roomMessage, 'error' => $roomError] = handleTenantAction($pdo);
}

// Damage charges: something found broken in the boarding house, charged once to this tenant.
$damageMessage = '';
$damageError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_damage' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $photo = ['path' => null, 'error' => null];
    if (isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $photo = storeUploadedImage($_FILES['photo'], 'payments/damages');
    }
    if ($photo['error']) {
        $damageError = $photo['error'];
    } else {
        try {
            // Split equally with the roommates ticked (only when "Split with roommates" is on).
            $splitWith = !empty($_POST['split']) ? array_map('intval', (array)($_POST['split_with'] ?? [])) : [];
            if (!empty($_POST['split']) && !$splitWith) {
                throw new Exception('Tick at least one roommate to split with, or turn off "Split with roommates".');
            }
            $added = addDamageCharge($pdo, $tenantId, [
                'item' => $_POST['item'] ?? '', 'note' => $_POST['note'] ?? '', 'amount' => $_POST['amount'] ?? 0,
                'found_on' => $_POST['found_on'] ?? '', 'due_date' => $_POST['due_date'] ?? '',
                'room_id' => $_POST['room_id'] ?? null, 'photo_path' => $photo['path'],
            ], $splitWith);
            $amount = round((float)$_POST['amount'], 2);
            $ways = count($added);
            $damageMessage = $ways > 1
                ? 'Damage charge of ₱' . number_format($amount, 2) . ' split ' . $ways . ' ways: ₱' . number_format($added[$tenantId]['share'], 2)
                  . ' on this tenant and ₱' . number_format(end($added)['share'], 2) . ' on each roommate, due ' . date('M j, Y', strtotime($_POST['due_date'])) . '.'
                : 'Damage charge of ₱' . number_format($amount, 2) . ' added to the balance, due ' . date('M j, Y', strtotime($_POST['due_date'])) . '.';
            if (!empty($_POST['notify'])) {
                $t = $pdo->prepare("SELECT first_name, contact_number, balance FROM tenants WHERE id = ?");
                $results = [];
                foreach ($added as $tid => $share) {
                    $t->execute([$tid]);
                    $tRow = $t->fetch();
                    $r = sendSMS($pdo, $tRow['contact_number'],
                        smsDamageChargeMessage($pdo, $tRow['first_name'], trim((string)$_POST['item']), $share['share'], $_POST['due_date'],
                                               (float)$tRow['balance'], $amount, $ways),
                        'damage_charge', $tid);
                    $results[] = $r;
                }
                $damageMessage .= ' ' . smsOutcomeText(mergeSmsResults(...$results));
            }
        } catch (Exception $e) {
            if ($photo['path']) deleteLocalUpload($photo['path']);
            $damageError = $e->getMessage();
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_damage' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $removed = removeDamageCharge($pdo, (int)($_POST['damage_id'] ?? 0), $tenantId);
    if ($removed) {
        deleteLocalUpload($removed['photo_path'] ?? null);
        $damageMessage = 'Damage charge for "' . $removed['item'] . '" removed and taken off the balance'
                       . ($removed['tenants'] > 1 ? ' of ' . ($removed['tenants'] === 2 ? 'both tenants' : 'all ' . $removed['tenants'] . ' tenants') . ' who shared it.' : '.');
    }
}

// The admin's own notes on the tenant (only the admin sees them).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $notes = trim((string)($_POST['admin_notes'] ?? ''));
    $pdo->prepare("UPDATE tenants SET admin_notes = ? WHERE id = ?")->execute([$notes === '' ? null : mb_substr($notes, 0, 5000), $tenantId]);
    $reminderSuccess = 'Notes saved.';
}

// The tenant asked to use their deposit for their last month: approve or decline it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposit_decide' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    $approve = ($_POST['decision'] ?? '') === 'approve';
    $r = decideDepositRequest($pdo, (int)($_POST['request_id'] ?? 0), $approve, (string)($_POST['note'] ?? ''));
    if ($r['error'] !== '') {
        $reminderError = $r['error'];
    } else {
        $reminderSuccess = ($approve
            ? 'Deposit of ₱' . number_format($r['applied'], 2) . ' applied to the requested month.'
            : 'Request declined.') . ' ' . smsOutcomeText($r['sms']);
    }
}

// Handle Send Reminder action before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_reminder' && (int)($_POST['tenant_id'] ?? 0) === $tenantId) {
    // Re-fetch minimal tenant info needed for the reminder message
    $tStmt = $pdo->prepare("SELECT id, first_name, last_name, contact_number, balance, status, room_id, move_in_date FROM tenants WHERE id = ?");
    $tStmt->execute([$tenantId]);
    $tRow = $tStmt->fetch();

    if (!$tRow || $tRow['status'] !== 'active') {
        $reminderError = 'Reminder can only be sent to active tenants.';
    } elseif ((float)$tRow['balance'] <= 0) {
        $reminderError = 'This tenant has no outstanding balance — no reminder needed.';
    } elseif (!smsConfigured($pdo)) {
        $reminderError = 'SMS is not set up yet. Add your PhilSMS API token in Settings.';
    } else {
        $billing = tenantBillingStatus($tRow, chargesNotYetDue($pdo, $tenantId));
        $name    = trim($tRow['first_name'] . ' ' . $tRow['last_name']);

        if ($billing['key'] === 'overdue') {
            $since   = oldestUnpaidDueDate($pdo, $tenantId, (float)$tRow['balance']);
            $ref     = 'overdue:' . $tenantId . ':' . date('o-W');
            $message = smsOverdueMessage($pdo, $name, $billing['overdue'], $since);
        } else {
            // Not late yet: the bill coming up, or whatever is owed if nothing is scheduled.
            $due  = upcomingDueDate($pdo, $tenantId) ?? date('Y-m-d');
            $rent = $pdo->prepare("SELECT SUM(amount) FROM charges WHERE tenant_id = ? AND due_date = ? AND amount > 0");
            $rent->execute([$tenantId, $due]);
            $ref     = 'due:manual:' . $tenantId . ':' . date('Y-m-d');
            $message = smsDueReminderMessage($pdo, $name, (float)$rent->fetchColumn() ?: (float)$tRow['balance'], $due, (float)$tRow['balance']);
        }

        $r = sendSMS($pdo, $tRow['contact_number'], $message, 'reminder_' . $billing['key'], $tenantId, $ref);
        if ($r['sent'] > 0) {
            $reminderSuccess = 'Reminder sent to ' . formatPhMobile(normalizePhMobile($tRow['contact_number'])) . '.';
        } else {
            $reminderError = 'Reminder not sent. ' . smsErrorText($r);
        }
    }
}

// Fetch Tenant & Room details
$stmt = $pdo->prepare("
    SELECT t.*, u.username, u.temp_password, r.room_number, r.price_per_month, r.capacity AS room_capacity, r.solo_pays_full, u.created_at as account_created
    FROM tenants t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN rooms r ON t.room_id = r.id
    WHERE t.id = ?
");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die("Tenant not found.");
}

// Fetch Payments
$payStmt = $pdo->prepare("
    SELECT * FROM payments 
    WHERE tenant_id = ? 
    ORDER BY payment_date DESC, id DESC
");
$payStmt->execute([$tenantId]);
$payments = $payStmt->fetchAll();

// Amount each verified payment credited to THIS tenant (a room payment's roommate shares are credited to the roommates).
$creditStmt = $pdo->prepare("
    SELECT p.id, p.payment_date, p.amount, p.payment_method, p.covered_by_payment_id,
           p.amount - COALESCE((SELECT SUM(k.amount) FROM payments k WHERE k.covered_by_payment_id = p.id), 0) AS credited
    FROM payments p
    WHERE p.tenant_id = ? AND p.status = 'verified'
");
$creditStmt->execute([$tenantId]);
$credits = $creditStmt->fetchAll();

$chargeStmt = $pdo->prepare("SELECT * FROM charges WHERE tenant_id = ? ORDER BY due_date, id");
$chargeStmt->execute([$tenantId]);
$charges = $chargeStmt->fetchAll();

$totalCharges = array_sum(array_map(fn($c) => (float)$c['amount'], $charges));
$totalPayments = array_sum(array_map(fn($c) => (float)$c['credited'], $credits));

// Calculated fields for UI
// Rent is per bed: the room price divided by its capacity, however many beds are taken.
$roomPrice = (float)($tenant['price_per_month'] ?? 0);
$roomCapacity = max(1, (int)($tenant['room_capacity'] ?? 1));
// A room set to charge its only tenant the whole room: they pay the full price (after their first month).
$mainBedsTaken = $tenant['room_id'] ? roomOccupantCount($pdo, (int)$tenant['room_id']) : 0;
$mainRoomRow = ['price_per_month' => $roomPrice, 'capacity' => $roomCapacity, 'solo_pays_full' => $tenant['solo_pays_full'] ?? false];
$mainWholeRoom = $tenant['room_id'] && soloPaysWholeRoom($mainRoomRow, $mainBedsTaken);
$mainRoomRent = $tenant['room_id'] ? bedRent($mainRoomRow, $mainBedsTaken) : 0.0;
$rentSplitNote = $mainWholeRoom
    ? 'Whole room: the only tenant in it'
    : ($roomCapacity > 1 ? '1 of ' . $roomCapacity . ' beds in the ₱' . number_format($roomPrice, 2) . ' room' : '');
// Rooms rented on top of the main one: each adds its own rent.
$additionalRooms = tenantAdditionalRooms($pdo, $tenantId);
$monthlyRent = $mainRoomRent + array_sum(array_column($additionalRooms, 'rent'));
$roomCount = ($tenant['room_id'] ? 1 : 0) + count($additionalRooms);
if ($additionalRooms) {
    $rentSplitNote = 'For ' . $roomCount . ' rooms';
}
// Appliance charges on top of the rent.
$myAppliances = tenantAppliances($pdo, $tenantId);
$applianceTotal = array_sum(array_column($myAppliances, 'monthly'));
if ($applianceTotal > 0) {
    $rentSplitNote = ($rentSplitNote ? $rentSplitNote . ' · ' : '') . '+ ₱' . number_format($applianceTotal, 2) . ' appliances';
}
$currentBalance = round((float)($tenant['balance'] ?? 0), 2);
$depositHeld = tenantDeposit($pdo, $tenant);
$ledgerBalance = round($totalCharges - $totalPayments, 2);
$ledgerMismatch = abs($ledgerBalance - $currentBalance) >= 0.01;

$billing = tenantBillingStatus($tenant, chargesNotYetDue($pdo, $tenantId));
$isDeactivated = $billing['key'] === 'deactivated';
$nextDue = nextDueDate($tenant);
$overdueSince = $billing['overdue'] > 0 ? oldestUnpaidDueDate($pdo, $tenantId, $currentBalance) : null;
$moveInDisplay = $tenant['move_in_date'] ? date('M j, Y', strtotime($tenant['move_in_date'])) : 'Not moved in';

// Ledger rows: charges and payments by date, with a running balance
$ledger = [];
foreach ($charges as $c) {
    $chargeDate = $c['billing_month'] . '-01';
    if ($c['kind'] === 'opening') {
        $chargeDate = $tenant['move_in_date'] ?? $c['due_date'];
    } elseif (str_starts_with($c['kind'], 'dmg_') && !empty($c['created_at'])) {
        $chargeDate = substr($c['created_at'], 0, 10);   // a damage charge: the day it was added
    } elseif ($tenant['move_in_date'] && date('Y-m', strtotime($tenant['move_in_date'])) === $c['billing_month']) {
        $chargeDate = $tenant['move_in_date'];
    }
    // A deposit spent on the last month is a credit, so it belongs in the credit column
    // rather than showing as a negative charge.
    $amount = (float)$c['amount'];
    if ($amount < 0) {
        $ledger[] = ['date' => $c['due_date'], 'order' => 1, 'desc' => $c['description'],
                     'charge' => 0, 'payment' => -$amount];
        continue;
    }
    $ledger[] = ['date' => $chargeDate,
                 'order' => 0, 'desc' => $c['description'] . ' (due ' . date('M j, Y', strtotime($c['due_date'])) . ')',
                 'charge' => $amount, 'payment' => 0];
}
foreach ($credits as $c) {
    $label = $c['covered_by_payment_id'] ? htmlspecialchars($c['payment_method'] ?? '') : 'Payment received';
    $ledger[] = ['date' => $c['payment_date'], 'order' => 1,
                 'desc' => $label . ' (RCP-' . str_pad($c['id'], 6, '0', STR_PAD_LEFT) . ')',
                 'charge' => 0, 'payment' => (float)$c['credited']];
}
usort($ledger, fn($a, $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

$docStmt = $pdo->prepare("SELECT * FROM tenant_documents WHERE tenant_id = ? ORDER BY uploaded_at DESC, id DESC");
$docStmt->execute([$tenantId]);
$documents = $docStmt->fetchAll();
$openTab = ($docMessage || $docError) ? 'documents' : (($roomMessage || $roomError) ? 'rooms' : (($damageMessage || $damageError) ? 'damages' : 'overview'));
$damages = tenantDamageCharges($pdo, $tenantId);
$damageSplits = damageSplitShares($pdo, array_column($damages, 'split_group'));
$damageTotal = array_sum(array_map(fn($d) => (float)$d['amount'], $damages));

// Rooms the tenant could also rent: a free bed, not under maintenance, not one they already have.
$rentedRoomIds = array_merge($tenant['room_id'] ? [(int)$tenant['room_id']] : [], array_map('intval', array_column($additionalRooms, 'room_id')));
$roomChoices = $pdo->query("SELECT r.id, r.room_number, r.capacity, r.price_per_month, r.solo_pays_full, " . roomBedsTakenSql('r.id') . " AS taken
                            FROM rooms r WHERE r.status <> 'maintenance'")->fetchAll();
$roomChoices = array_values(array_filter($roomChoices, fn($r) => !in_array((int)$r['id'], $rentedRoomIds, true)));
usort($roomChoices, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));
$canAddRoom = $tenant['status'] === 'active' && !empty($tenant['room_id']);

// Beds taken in each room the tenant rents, for the Rooms tab.
$bedsTaken = [];
foreach ($rentedRoomIds as $rid) {
    $bedsTaken[$rid] = roomOccupantCount($pdo, $rid);
}

// Room history
$histStmt = $pdo->prepare("
    SELECT rt.*, rf.room_number AS from_room, rto.room_number AS to_room
    FROM room_transfers rt
    LEFT JOIN rooms rf ON rf.id = rt.from_room_id
    LEFT JOIN rooms rto ON rto.id = rt.to_room_id
    WHERE rt.tenant_id = ? ORDER BY rt.transferred_at DESC, rt.id DESC
");
$histStmt->execute([$tenantId]);
$roomHistory = $histStmt->fetchAll();

require_once 'header.php';
?>

<style>
    /* Header: who, standing, the four key facts, then the balance strip */
    .profile-card { border-radius: 12px; border: 1px solid #eef2f7; }
    .avatar-wrapper { position: relative; width: 84px; height: 84px; flex: 0 0 84px; }
    /* Fits either an uploaded photo or the initials placeholder to the wrapper. */
    .avatar-wrapper img, .avatar-wrapper .avatar-initials {
        width: 100% !important; height: 100% !important; object-fit: cover; border-radius: 50%;
    }
    .standing-badge { display: inline-block; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; padding: 0.3em 0.9em; border-radius: 999px; }
    .profile-id { min-width: 0; }
    .fact-box { border: 1px solid #eef2f7; border-radius: 10px; padding: 0.75rem 0.85rem; height: 100%; background: #fff; }
    .fact-icon { width: 32px; height: 32px; border-radius: 8px; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: 0.8rem; margin-bottom: 0.4rem; }
    .fact-label { font-size: 0.72rem; color: #64748b; }
    .fact-value { font-size: 0.95rem; font-weight: 700; color: #0f172a; line-height: 1.3; }
    .fact-sub { font-size: 0.66rem; color: #64748b; line-height: 1.3; margin-top: 2px; }

    .balance-strip { border-radius: 10px; padding: 0.85rem 1.1rem; display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; border: 1px solid transparent; }
    .strip-icon { width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.95rem; flex-shrink: 0; }
    .strip-label { font-size: 0.75rem; font-weight: 600; }
    .strip-amount { font-size: 1.35rem; font-weight: 800; line-height: 1.2; }
    .strip-divider { width: 1px; align-self: stretch; background: currentColor; opacity: 0.2; }
    .strip-detail { font-size: 0.82rem; }
    .strip-danger  { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .strip-danger .strip-icon  { background: #dc2626; color: #fff; }
    .strip-info    { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
    .strip-info .strip-icon    { background: #2563eb; color: #fff; }
    .strip-success { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .strip-success .strip-icon { background: #16a34a; color: #fff; }
    .strip-muted   { background: #f8fafc; border-color: #e2e8f0; color: #475569; }
    .strip-muted .strip-icon   { background: #64748b; color: #fff; }
    @media (max-width: 575.98px) {
        .balance-strip { gap: 0.75rem; }
        .strip-divider { display: none; }
        .strip-detail { flex-basis: 100%; }
    }

    .breadcrumb-sm { font-size: 0.75rem; }
    .breadcrumb-sm a { color: #0d6efd; text-decoration: none; }
    .breadcrumb-sm a:hover { text-decoration: underline; }

    /* Tabs: underlined, scroll sideways on phones */
    .tenant-tabs { border-bottom: 1px solid #e2e8f0; flex-wrap: nowrap; overflow-x: auto; gap: 0.25rem; scrollbar-width: none; }
    .tenant-tabs .nav-link { border: 0; border-bottom: 2px solid transparent; border-radius: 0; color: #64748b; font-size: 0.8rem; font-weight: 600; padding: 0.7rem 0.9rem; white-space: nowrap; background: none; }
    .tenant-tabs .nav-link:hover { color: #1e293b; }
    .tenant-tabs .nav-link.active { color: #2563eb; border-bottom-color: #2563eb; }

    /* Content blocks */
    .section-card { border-radius: 10px; border: 1px solid #eef2f7; background: white; margin-bottom: 1rem; }
    .section-header { padding: 0.85rem 1rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; }
    .section-title { font-size: 0.85rem; font-weight: 700; color: #1e293b; margin: 0; }

    .info-grid { padding: 1rem; display: grid; grid-template-columns: 150px 1fr; row-gap: 0.7rem; column-gap: 1rem; font-size: 0.8rem; }
    .info-label { color: #64748b; }
    .info-value { color: #1e293b; font-weight: 500; min-width: 0; }
    @media (max-width: 575.98px) { .info-grid { grid-template-columns: 1fr; row-gap: 0.15rem; } .info-value { margin-bottom: 0.5rem; } }

    .mini-table>thead>tr>th { font-size: 0.68rem; padding: 0.6rem 0.75rem; text-transform: none; letter-spacing: 0; background: #f8fafc; }
    .mini-table>tbody>tr>td { font-size: 0.78rem; padding: 0.6rem 0.75rem; }
    .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 0.5rem; }
    .soft-pill { display: inline-block; font-size: 0.68rem; font-weight: 600; padding: 0.2em 0.7em; border-radius: 999px; white-space: nowrap; }

    .empty-state { text-align: center; padding: 1.75rem 1rem; font-size: 0.78rem; color: #64748b; }
    .empty-state > span { width: 52px; height: 52px; border-radius: 50%; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-bottom: 0.6rem; }

    /* Receipt cards */
    .receipt-scroll { display: flex; gap: 0.75rem; padding: 1rem; overflow-x: auto; }
    .receipt-card { min-width: 150px; border: 1px solid #eef2f7; border-radius: 8px; padding: 0.75rem; background: white; display: flex; flex-direction: column; }
    .receipt-thumb { height: 56px; background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 4px; margin: 0.4rem 0; display: flex; align-items: center; justify-content: center; }

    /* Balance summary */
    .balance-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem; padding: 0.6rem 0; border-bottom: 1px solid #f1f5f9; }
    .balance-row > span:last-child { font-size: 0.95rem; color: #0f172a; }
    .balance-row > span.text-danger:last-child { color: #dc2626 !important; }
    .balance-total { display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; font-weight: 700; border-radius: 8px; padding: 0.75rem 0.85rem; margin-top: 0.75rem; }
    .method-box { display: flex; align-items: center; gap: 0.75rem; border: 1px solid #eef2f7; border-radius: 8px; padding: 0.6rem 0.75rem; font-size: 0.82rem; }
    .method-icon { width: 34px; height: 34px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; }

    /* Documents */
    .doc-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.8rem; }
    .doc-row:last-child { border-bottom: 0; }
    .doc-icon { width: 38px; height: 38px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }

    /* Rooms */
    .rented-room { padding: 0.85rem 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.8rem; }
    .rented-room:last-child { border-bottom: 0; }
    .rented-room-icon { width: 40px; height: 40px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.95rem; }
    .rooms-total { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: #f8fafc; border-top: 1px solid #eef2f7; font-size: 0.82rem; font-weight: 700; border-radius: 0 0 10px 10px; }
</style>

<div class="breadcrumb-sm text-muted mb-3">
    <a href="dashboard.php">Dashboard</a> &rsaquo; <a href="tenants.php">Tenants</a> &rsaquo; <?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?>
</div>

<?php
// The tenant's standing, as the header badge and the balance strip show it.
$upcomingDue = upcomingDueDate($pdo, $tenantId);
[$standingLabel, $standingColor] = match ($billing['key']) {
    'overdue'     => ['OVERDUE', 'danger'],
    'due'         => ['NOT YET DUE', 'primary'],
    'deactivated' => ['MOVED OUT', 'secondary'],
    default       => ['PAID', 'success'],
};
?>

<!-- Top Profile Card -->
<div class="card profile-card shadow-sm mb-3 bg-white">
    <div class="card-body p-3 p-md-4">
        <div class="row g-3 align-items-center">
            <div class="col-xl-4 d-flex align-items-center gap-3 profile-id">
                <div class="avatar-wrapper"><?= avatarHtml($tenant['first_name'] . ' ' . $tenant['last_name'], 84, '', $tenant['profile_picture'] ?? null, '../') ?></div>
                <div style="min-width: 0;">
                    <h4 class="fw-bold mb-1 text-dark text-truncate"><?= htmlspecialchars($tenant['first_name'] . ' ' . $tenant['last_name']) ?></h4>
                    <div class="text-muted mb-2" style="font-size: 0.85rem;"><i class="fa-solid fa-phone me-2"></i><?= htmlspecialchars($tenant['contact_number'] ?: 'No number') ?></div>
                    <span class="standing-badge bg-<?= $standingColor ?>-subtle text-<?= $standingColor ?>-emphasis"><?= $standingLabel ?></span>
                </div>
            </div>
            <div class="col-xl-8">
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <div class="fact-box">
                            <span class="fact-icon"><i class="fa-solid fa-bed"></i></span>
                            <div class="fact-label"><?= $isDeactivated ? 'Room' : ($additionalRooms ? 'Assigned Rooms' : 'Assigned Room') ?></div>
                            <div class="fact-value"><?= $isDeactivated ? 'Moved out' : ($tenant['room_number'] ? 'Room ' . htmlspecialchars($tenant['room_number']) : 'Unassigned') ?></div>
                            <?php if (!$isDeactivated && $additionalRooms): ?>
                                <div class="fact-sub">+ <?= htmlspecialchars(implode(', ', array_map(fn($x) => 'Room ' . $x['room_number'], $additionalRooms))) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="fact-box">
                            <span class="fact-icon"><i class="fa-regular fa-calendar"></i></span>
                            <div class="fact-label"><?= $isDeactivated && $tenant['deactivated_at'] ? 'Moved Out' : 'Move-in Date' ?></div>
                            <div class="fact-value"><?= $isDeactivated && $tenant['deactivated_at'] ? date('M j, Y', strtotime($tenant['deactivated_at'])) : $moveInDisplay ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="fact-box">
                            <span class="fact-icon"><i class="fa-solid fa-circle-dollar-to-slot"></i></span>
                            <div class="fact-label">Monthly Rent</div>
                            <div class="fact-value">₱<?= number_format($monthlyRent, 2) ?></div>
                            <?php if ($rentSplitNote): ?><div class="fact-sub"><?= $rentSplitNote ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="fact-box">
                            <span class="fact-icon"><i class="fa-solid fa-shield-halved"></i></span>
                            <div class="fact-label">Deposit</div>
                            <div class="fact-value">₱<?= number_format($depositHeld, 2) ?></div>
                            <div class="fact-sub">For the last month</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance strip: red when something is overdue, blue while the next bill isn't due yet -->
        <?php
        $stripClass = match ($billing['key']) {
            'overdue' => 'strip-danger', 'due' => 'strip-info', 'deactivated' => 'strip-muted', default => 'strip-success',
        };
        ?>
        <div class="balance-strip <?= $stripClass ?> mt-3">
            <div class="d-flex align-items-center gap-3">
                <span class="strip-icon"><i class="fa-solid <?= $billing['key'] === 'overdue' ? 'fa-exclamation' : 'fa-wallet' ?>"></i></span>
                <div>
                    <div class="strip-label">Current Balance</div>
                    <div class="strip-amount">₱<?= number_format(max(0, $currentBalance), 2) ?></div>
                </div>
            </div>
            <div class="strip-divider"></div>
            <div class="d-flex align-items-center gap-2 strip-detail">
                <?php if ($billing['key'] === 'overdue'): ?>
                    <span><strong>Overdue: ₱<?= number_format($billing['overdue'], 2) ?></strong><?= $overdueSince ? ' since ' . date('M j, Y', strtotime($overdueSince)) : '' ?></span>
                <?php elseif ($billing['key'] === 'due'): ?>
                    <i class="fa-regular fa-calendar"></i>
                    <span>Next payment due:<br><strong><?= $upcomingDue ? date('M j, Y', strtotime($upcomingDue)) : '—' ?></strong></span>
                <?php elseif ($billing['key'] === 'deactivated'): ?>
                    <span><?= $currentBalance > 0 ? 'Still owes <strong>₱' . number_format($currentBalance, 2) . '</strong>' : ($currentBalance < 0 ? '<strong>₱' . number_format(-$currentBalance, 2) . '</strong> to refund' : 'Settled') ?></span>
                <?php else: ?>
                    <i class="fa-solid fa-circle-check"></i>
                    <span><strong>Fully paid</strong><?= $currentBalance < 0 ? ' · ₱' . number_format(-$currentBalance, 2) . ' credit for the next bill' : '' ?><?= $nextDue ? '<br>Next bill due ' . date('M j, Y', strtotime($nextDue)) : '' ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav tenant-tabs mb-3" id="tenantTabs" role="tablist">
    <?php foreach ([['overview', 'fa-regular fa-user', 'Overview'], ['transactions', 'fa-regular fa-file-lines', 'Transactions'],
                    ['payments', 'fa-solid fa-wallet', 'Payments & Receipts'], ['ledger', 'fa-solid fa-book-open', 'Ledger'],
                    ['documents', 'fa-regular fa-file', 'Documents' . ($documents ? ' (' . count($documents) . ')' : '')],
                    ['rooms', 'fa-solid fa-door-open', 'Rooms' . ($roomCount ? ' (' . $roomCount . ')' : '')],
                    ['damages', 'fa-solid fa-hammer', 'Damages' . ($damages ? ' (' . count($damages) . ')' : '')]] as [$id, $icon, $label]): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $openTab === $id ? 'active' : '' ?>" id="<?= $id ?>-tab" data-bs-toggle="tab" data-bs-target="#<?= $id ?>" type="button" role="tab"><i class="<?= $icon ?> me-2"></i><?= $label ?></button>
    </li>
    <?php endforeach; ?>
</ul>

<!-- Main Content Grid -->
<div class="tab-content" id="tenantTabsContent">

    <!-- OVERVIEW TAB -->
    <div class="tab-pane fade <?= $openTab === 'overview' ? 'show active' : '' ?>" id="overview" role="tabpanel" aria-labelledby="overview-tab">
        <div class="row g-3">

    <!-- Left: who they are, what they paid -->
    <div class="col-lg-7">

        <!-- Tenant Information -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-regular fa-user text-primary me-2"></i>Tenant Information</h6>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.7rem;" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fa-solid fa-pen me-1"></i>Edit</button>
            </div>
            <?php $na = '<span class="text-black-50 fst-italic fw-normal">Not provided</span>'; $age = tenantAge($tenant['date_of_birth'] ?? null); $emergency = tenantEmergencyText($tenant); ?>
            <div class="info-grid">
                <span class="info-label">Full Name</span><span class="info-value"><?= htmlspecialchars(trim($tenant['first_name'] . ' ' . ($tenant['middle_name'] ?? '') . ' ' . $tenant['last_name'])) ?></span>
                <span class="info-label">Contact Number</span><span class="info-value"><?= !empty($tenant['contact_number']) ? htmlspecialchars($tenant['contact_number']) : $na ?></span>
                <span class="info-label">Username</span>
                <span class="info-value d-flex align-items-center gap-2"><span id="usernameText"><?= htmlspecialchars($tenant['username'] ?? '') ?></span>
                    <button type="button" class="btn btn-link p-0 text-muted" title="Copy username" aria-label="Copy username" onclick="navigator.clipboard.writeText(document.getElementById('usernameText').textContent); this.innerHTML='<i class=&quot;fa-solid fa-check text-success&quot;></i>';"><i class="fa-regular fa-copy"></i></button></span>
                <span class="info-label">Occupation</span><span class="info-value"><?= !empty($tenant['occupation']) ? htmlspecialchars($tenant['occupation']) : $na ?></span>
                <span class="info-label">Emergency Contact</span><span class="info-value"><?= $emergency !== '' ? htmlspecialchars($emergency) : $na ?></span>
                <span class="info-label">Email</span><span class="info-value text-break"><?= !empty($tenant['email']) ? htmlspecialchars($tenant['email']) : $na ?></span>
                <span class="info-label">Gender</span><span class="info-value"><?= !empty($tenant['gender']) ? htmlspecialchars($tenant['gender']) : $na ?></span>
                <span class="info-label">Date of Birth</span><span class="info-value"><?= !empty($tenant['date_of_birth']) ? date('M j, Y', strtotime($tenant['date_of_birth'])) . " <span class=\"text-muted fw-normal\">($age years old)</span>" : $na ?></span>
                <span class="info-label">Home Address</span><span class="info-value"><?= !empty($tenant['address']) ? nl2br(htmlspecialchars($tenant['address'])) : $na ?></span>
                <span class="info-label">Temporary Password</span><span class="info-value"><?= !empty($tenant['temp_password']) ? '<span class="badge bg-warning text-dark fw-bold" style="font-family: monospace; font-size:0.75rem;">' . htmlspecialchars($tenant['temp_password'] ?? '') . '</span>' : '<span class="text-success fst-italic fw-semibold" style="font-size:0.75rem;"><i class="fa-solid fa-check"></i> Changed by tenant</span>' ?></span>
            </div>
        </div>

        <?php if ($profileError): ?>
        <script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('editProfileModal')).show());</script>
        <?php endif; ?>
        <!-- Edit tenant details -->
        <div class="modal fade" id="editProfileModal" tabindex="-1" aria-label="Edit tenant details">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit_profile">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <div class="modal-header bg-light border-0">
                            <h6 class="modal-title fw-bold"><i class="fa-solid fa-user-pen text-primary me-2"></i>Edit Tenant Details</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4 form-compact">
                            <?= tenantProfileFormFields($profileError ? $_POST : $tenant, 'td') ?>
                        </div>
                        <div class="modal-footer border-0 bg-light">
                            <button type="button" class="btn btn-light border" data-bs-dismiss="modal" style="font-size:0.8rem;">Cancel</button>
                            <button type="submit" class="btn btn-primary px-4" style="font-size:0.8rem;">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-arrows-rotate text-primary me-2"></i>Recent Transactions</h6>
                <a href="#transactions" onclick="document.getElementById('transactions-tab').click(); return false;" class="text-primary text-decoration-none" style="font-size:0.75rem; font-weight:600;">View All</a>
            </div>
            <?php if (empty($payments)): ?>
                <div class="empty-state"><span><i class="fa-regular fa-file-lines"></i></span><div class="fw-semibold text-dark">No transactions yet.</div><div>Payments will appear here once the tenant pays.</div></div>
            <?php else: ?>
            <div class="table-responsive px-2 pb-2">
                <table class="table mini-table mb-0">
                    <thead><tr><th>Type</th><th>Date</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($payments, 0, 5) as $p):
                        [$typeText, $typeDot] = match ($p['status']) {
                            'verified' => ['Payment Received', 'success'],
                            'rejected' => ['Payment Rejected', 'danger'],
                            default    => ['Payment Pending', 'warning'],
                        };
                        $desc = ($p['reference_number'] ?? '') === 'MOVE-IN' ? 'Advance + Deposit'
                              : (stripos($p['payment_method'] ?? '', 'covered by') === 0 ? $p['payment_method']
                              : (!empty($p['pay_for_room']) ? 'Room Rent (whole room)' : 'Room Rent'));
                        [$stText, $stColor] = match ($p['status']) {
                            'verified' => ['Paid', 'success'], 'rejected' => ['Rejected', 'danger'], default => ['Pending', 'warning'],
                        };
                    ?>
                        <tr>
                            <td class="text-nowrap"><span class="dot bg-<?= $typeDot ?>"></span><?= $typeText ?></td>
                            <td class="text-nowrap"><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($desc) ?></td>
                            <td class="text-nowrap fw-semibold">₱<?= number_format((float)$p['amount'], 2) ?></td>
                            <td><span class="soft-pill bg-<?= $stColor ?>-subtle text-<?= $stColor ?>-emphasis"><?= $stText ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Payment & Receipts -->
        <?php $verifiedPayments = array_values(array_filter($payments, fn($p) => $p['status'] === 'verified')); ?>
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-receipt text-primary me-2"></i>Payment & Receipts</h6>
                <a href="#payments" onclick="document.getElementById('payments-tab').click(); return false;" class="text-primary text-decoration-none" style="font-size:0.75rem; font-weight:600;">View All Receipts</a>
            </div>
            <?php if (!$verifiedPayments): ?>
                <div class="empty-state"><span><i class="fa-regular fa-credit-card"></i></span><div class="fw-semibold text-dark">No receipts available yet.</div><div>Receipts will appear here once a payment is made.</div></div>
            <?php else: ?>
            <div class="receipt-scroll">
                <?php foreach (array_slice($verifiedPayments, 0, 6) as $p): ?>
                <div class="receipt-card">
                    <span class="soft-pill bg-success-subtle text-success-emphasis align-self-start mb-1">Paid</span>
                    <div class="receipt-thumb"><i class="fa-solid fa-file-invoice text-primary opacity-50 fs-3"></i></div>
                    <div class="fw-bold text-dark mb-1" style="font-size:0.7rem;">RCP-<?= str_pad($p['id'], 6, '0', STR_PAD_LEFT) ?></div>
                    <div class="text-muted" style="font-size:0.68rem;"><?= date('M j, Y', strtotime($p['payment_date'])) ?></div>
                    <div class="fw-bold text-dark" style="font-size:0.8rem;">₱<?= number_format((float)$p['amount'], 2) ?></div>
                    <?php if (!empty($p['receipt_path'])): ?>
                    <a href="<?= htmlspecialchars(uploadSrc($p['receipt_path'], '../')) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm w-100 mt-2 py-0" style="font-size:0.68rem;">View Receipt</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: money, deposit, notes, actions -->
    <div class="col-lg-5">

        <!-- Balance Summary -->
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-wallet text-primary me-2"></i>Balance Summary</h6></div>
            <div class="p-3">
                <div class="balance-row"><span class="text-muted">Total Charges</span><span class="fw-semibold">₱<?= number_format($totalCharges, 2) ?></span></div>
                <div class="balance-row"><span class="text-muted">Total Payments</span><span class="fw-semibold">₱<?= number_format($totalPayments, 2) ?></span></div>
                <?php if ($billing['key'] === 'overdue'): ?>
                    <div class="balance-row"><span class="text-muted">Overdue</span><span class="fw-bold text-danger">₱<?= number_format($billing['overdue'], 2) ?></span></div>
                    <div class="balance-total bg-danger-subtle text-danger-emphasis"><span>Current Balance</span><span>₱<?= number_format($currentBalance, 2) ?></span></div>
                <?php else: ?>
                    <div class="balance-row"><span class="text-muted">Outstanding Balance</span><span class="fw-semibold">₱<?= number_format(max(0, $currentBalance), 2) ?></span></div>
                    <div class="balance-total bg-<?= $standingColor ?>-subtle text-<?= $standingColor ?>-emphasis">
                        <span><i class="fa-regular fa-calendar me-2"></i>Payment Status</span>
                        <span class="soft-pill bg-white text-<?= $standingColor ?>-emphasis"><?= ucwords(strtolower($standingLabel)) ?></span>
                    </div>
                    <?php if ($currentBalance < 0): ?>
                        <div class="text-success mt-2" style="font-size:0.75rem;">₱<?= number_format(-$currentBalance, 2) ?> <?= $isDeactivated ? 'to refund' : 'credit toward the next bill' ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($ledgerMismatch): ?>
                    <div class="alert alert-warning border-0 py-1 px-2 mt-2 mb-0" style="font-size:0.68rem;">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Ledger total (₱<?= number_format($ledgerBalance, 2) ?>) doesn't match the stored balance. Please review this account.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- How this tenant pays -->
        <?php
        $methodCounts = [];
        foreach ($payments as $p) {
            if ($p['status'] !== 'verified' || !empty($p['covered_by_payment_id'])) continue;
            $label = paymentMethodLabel($p['payment_method'] ?? 'gcash');
            $methodCounts[$label] = ($methodCounts[$label] ?? 0) + 1;
        }
        arsort($methodCounts);
        $usual = $methodCounts ? array_key_first($methodCounts) : null;
        ?>
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-regular fa-credit-card text-primary me-2"></i>Payment Method</h6></div>
            <div class="p-3">
                <?php if ($usual === null): ?>
                    <div class="text-muted" style="font-size:0.8rem;">No verified payments yet.</div>
                <?php else: $isCash = $usual === 'Cash'; ?>
                    <div class="method-box">
                        <span class="method-icon <?= $isCash ? 'bg-success-subtle text-success' : 'bg-primary text-white' ?>"><i class="fa-solid <?= $isCash ? 'fa-money-bill-wave' : 'fa-g' ?>"></i></span>
                        <div class="flex-grow-1 fw-semibold text-dark"><?= $isCash ? 'Cash' : 'GCash (Manual)' ?></div>
                        <span class="soft-pill <?= $isCash ? 'bg-success-subtle text-success-emphasis' : 'bg-primary-subtle text-primary-emphasis' ?>"><?= $methodCounts[$usual] ?> <?= $methodCounts[$usual] === 1 ? 'payment' : 'payments' ?></span>
                    </div>
                    <?php foreach (array_slice($methodCounts, 1, null, true) as $label => $n): ?>
                        <div class="d-flex justify-content-between mt-2" style="font-size:0.78rem;"><span class="text-muted">Also by <?= htmlspecialchars($label === 'GCash' ? 'GCash (Manual)' : $label) ?></span><span><?= $n ?> <?= $n === 1 ? 'payment' : 'payments' ?></span></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <!-- Deposit: held for the last month; the tenant can ask to use it -->
        <?php
        $depositUsed = depositAppliedThisStay($pdo, $tenant);
        $depositUnpaid = $depositUsed ? 0.0 : depositUnpaid($pdo, $tenant);
        $depositPending = pendingDepositRequest($pdo, $tenantId);
        ?>
        <div class="section-card shadow-sm" id="deposit">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-vault text-muted me-2"></i>Deposit</h6>
                <?php if ($depositPending): ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Request waiting</span><?php endif; ?>
            </div>
            <div class="p-3" style="font-size:0.75rem;">
                <?php if ($depositHeld <= 0 && !$depositUsed): ?>
                    <span class="text-muted">No deposit on record for this stay.</span>
                <?php elseif ($depositUsed): ?>
                    <div><strong>₱<?= number_format((float)$depositUsed['amount'], 2) ?></strong> used for the rent of <strong><?= date('F Y', strtotime($depositUsed['billing_month'] . '-01')) ?></strong>.</div>
                <?php else: ?>
                    <?php if ($depositUnpaid > 0): ?>
                        <div class="mb-2"><strong>₱<?= number_format($depositHeld, 2) ?></strong> deposit, <span class="text-danger fw-semibold">not paid yet</span> (₱<?= number_format($depositUnpaid, 2) ?> still due). It can't be used for the last month until it is paid.</div>
                    <?php else: ?>
                        <div class="mb-2"><strong>₱<?= number_format($depositHeld, 2) ?></strong> held for the last month of stay.</div>
                    <?php endif; ?>
                    <?php if ($depositPending): ?>
                        <div class="alert alert-warning py-2 px-2 mb-2" style="font-size:0.72rem;">
                            The tenant asked to use it for <strong><?= date('F Y', strtotime($depositPending['billing_month'] . '-01')) ?></strong>
                            (<?= date('M j, g:i A', strtotime($depositPending['created_at'])) ?>).
                        </div>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action" value="deposit_decide">
                            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                            <input type="hidden" name="request_id" value="<?= (int)$depositPending['id'] ?>">
                            <input type="text" name="note" class="form-control form-control-sm mb-2" maxlength="255" placeholder="Note to the tenant if declining (optional)">
                            <div class="d-flex gap-2">
                                <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success flex-grow-1"<?= $depositUnpaid > 0 ? ' disabled title="The deposit has not been paid yet"' : '' ?> onclick="return confirm('Apply the ₱<?= number_format($depositHeld, 2) ?> deposit to <?= date('F Y', strtotime($depositPending['billing_month'] . '-01')) ?>?');"><i class="fa-solid fa-check me-1"></i>Approve</button>
                                <button type="submit" name="decision" value="decline" class="btn btn-sm btn-outline-danger flex-grow-1"><i class="fa-solid fa-xmark me-1"></i>Decline</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <span class="text-muted">The tenant can ask to use it for their last month from their portal. If they move out without asking, it is applied automatically.</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Room History -->
        <div class="section-card shadow-sm">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-solid fa-clock-rotate-left text-muted me-2"></i>Room History</h6>
            </div>
            <div class="p-3" style="font-size:0.68rem;">
                <?php if (empty($roomHistory)): ?>
                    <span class="text-muted">No room changes recorded.</span>
                <?php else: foreach ($roomHistory as $h): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>
                            <span class="fw-semibold text-dark"><?= htmlspecialchars($h['note'] ?? '') ?></span><br>
                            <span class="text-muted"><?= $h['from_room'] ? 'Room ' . htmlspecialchars($h['from_room'] ?? '') : '—' ?> → <?= $h['to_room'] ? 'Room ' . htmlspecialchars($h['to_room'] ?? '') : '—' ?></span>
                        </span>
                        <span class="text-muted text-nowrap ms-2"><?= date('M j, Y', strtotime($h['transferred_at'])) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        
        <!-- Notes: the admin's own, not shown to the tenant -->
        <div class="section-card shadow-sm" id="notes">
            <div class="section-header">
                <h6 class="section-title"><i class="fa-regular fa-note-sticky text-muted me-2"></i>Notes</h6>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.6rem;" onclick="document.getElementById('notesView').classList.add('d-none'); document.getElementById('notesForm').classList.remove('d-none'); document.getElementById('notesText').focus();"><?= trim((string)($tenant['admin_notes'] ?? '')) !== '' ? 'Edit' : 'Add Note' ?></button>
            </div>
            <div class="p-3" style="font-size:0.7rem;">
                <div id="notesView" class="<?= trim((string)($tenant['admin_notes'] ?? '')) !== '' ? 'text-dark' : 'text-muted' ?>">
                    <?= trim((string)($tenant['admin_notes'] ?? '')) !== '' ? nl2br(htmlspecialchars($tenant['admin_notes'])) : 'No notes yet. Only you can see notes.' ?>
                </div>
                <form method="POST" id="notesForm" class="d-none m-0">
                    <input type="hidden" name="action" value="save_notes">
                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                    <textarea name="admin_notes" id="notesText" class="form-control form-control-sm mb-2" rows="4" maxlength="5000" placeholder="e.g., Pays on the 5th every month; parents in Davao."><?= htmlspecialchars($tenant['admin_notes'] ?? '') ?></textarea>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-sm btn-light border" onclick="document.getElementById('notesForm').classList.add('d-none'); document.getElementById('notesView').classList.remove('d-none');">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">Save notes</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="section-card shadow-sm">
            <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-user-gear text-muted me-2"></i>Actions</h6></div>
            <div class="p-3 d-flex flex-column gap-2">
                <?php if ($reminderSuccess): ?>
                <div class="alert alert-success py-1 px-2 mb-1" style="font-size:0.7rem;"><?= htmlspecialchars($reminderSuccess) ?></div>
                <?php elseif ($reminderError): ?>
                <div class="alert alert-danger py-1 px-2 mb-1" style="font-size:0.7rem;"><?= htmlspecialchars($reminderError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="send_reminder">
                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                    <button type="submit" class="btn btn-outline-primary btn-sm text-start w-100" style="font-size:0.7rem;"
                        <?= (!smsConfigured($pdo) || (float)($tenant['balance'] ?? 0) <= 0 || $tenant['status'] !== 'active') ? 'disabled title="' . (!smsConfigured($pdo) ? 'SMS not set up' : ((float)($tenant['balance'] ?? 0) <= 0 ? 'No outstanding balance' : 'Tenant is not active')) . '"' : '' ?>>
                        <i class="fa-regular fa-envelope me-2"></i>Send Reminder
                    </button>
                </form>
                <form method="POST" onsubmit="return confirm('Give this tenant a new temporary password? Their current password stops working.');">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm text-start w-100" style="font-size:0.7rem;">
                        <i class="fa-solid fa-key me-2"></i>Reset Password
                    </button>
                </form>
            </div>
        </div>
    </div> <!-- Close Right Column -->
    </div> <!-- Close Overview Tab Row -->
    </div> <!-- Close Overview Tab Pane -->

    <!-- TRANSACTIONS TAB -->
    <div class="tab-pane fade" id="transactions" role="tabpanel" aria-labelledby="transactions-tab">
        <div class="section-card shadow-sm p-4">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-arrow-right-arrow-left text-muted me-2"></i>All Transactions</h6>
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-0">Date</th>
                            <th class="border-0">Reference No.</th>
                            <th class="border-0">Method</th>
                            <th class="border-0">Amount</th>
                            <th class="border-0">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($payments)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted" style="font-size:0.75rem;">No transactions recorded.</td></tr>
                        <?php else: foreach($payments as $p): ?>
                            <tr>
                                <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M j, Y', strtotime($p['payment_date'])) ?></td>
                                <td class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($p['reference_number'] ?? '') ?></td>
                                <td class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars(ucfirst($p['payment_method'])) ?></td>
                                <td class="fw-bold text-dark" style="font-size:0.7rem;">PHP <?= number_format($p['amount'], 2) ?></td>
                                <td>
                                    <?php if($p['status'] == 'verified'): ?>
                                        <span class="badge bg-success-subtle text-success">Verified</span>
                                    <?php elseif($p['status'] == 'rejected'): ?>
                                        <span class="badge bg-danger-subtle text-danger">Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PAYMENTS & RECEIPTS TAB -->
    <div class="tab-pane fade" id="payments" role="tabpanel" aria-labelledby="payments-tab">
        <div class="section-card shadow-sm p-4">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-box-archive text-muted me-2"></i>Official Receipts</h6>
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-0">Date</th>
                            <th class="border-0">Receipt No.</th>
                            <th class="border-0">Amount</th>
                            <th class="border-0 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $verifiedPayments = array_filter($payments, fn($p) => $p['status'] === 'verified');
                        if(empty($verifiedPayments)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted" style="font-size:0.75rem;">No official receipts available yet.</td></tr>
                        <?php else: foreach($verifiedPayments as $vp): ?>
                            <tr>
                                <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M d, Y', strtotime($vp['payment_date'])) ?></td>
                                <td class="text-muted" style="font-size:0.7rem;">RCP-<?= str_pad($vp['id'], 6, '0', STR_PAD_LEFT) ?></td>
                                <td class="fw-bold text-dark" style="font-size:0.7rem;">PHP <?= number_format($vp['amount'], 2) ?></td>
                                <td class="text-end">
                                    <?php if (!empty($vp['receipt_path'])): ?>
                                    <a href="<?= htmlspecialchars(uploadSrc($vp['receipt_path'], '../')) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.65rem;"><i class="fa-solid fa-download me-1"></i> Download</a>
                                    <?php else: ?>
                                    <span class="text-muted" style="font-size:0.65rem;">No file</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- LEDGER TAB -->
    <div class="tab-pane fade" id="ledger" role="tabpanel" aria-labelledby="ledger-tab">
        <div class="section-card shadow-sm p-4">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-book text-muted me-2"></i>Account Ledger</h6>
            <div class="alert alert-info border-0 py-2 mb-3" style="font-size:0.75rem;">
                <i class="fa-solid fa-circle-info me-1"></i> Rent is charged on the 1st and due on the 30th. First month: full rent if moved in on day 1–15, half if day 16 or later.
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-compact align-middle">
                    <thead class="bg-light text-muted">
                        <tr>
                            <th class="border-0">Date</th>
                            <th class="border-0">Description</th>
                            <th class="border-0 text-end">Charge</th>
                            <th class="border-0 text-end">Payment</th>
                            <th class="border-0 text-end">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledger)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted" style="font-size:0.75rem;">No charges or payments yet.</td></tr>
                        <?php endif; ?>
                        <?php $running = 0; foreach ($ledger as $row): $running += $row['charge'] - $row['payment']; ?>
                        <tr>
                            <td class="fw-semibold text-dark" style="font-size:0.7rem;"><?= date('M d, Y', strtotime($row['date'])) ?></td>
                            <td class="text-muted" style="font-size:0.7rem;"><?= $row['desc'] ?></td>
                            <td class="text-end <?= $row['charge'] ? 'fw-bold text-danger' : 'text-muted' ?>" style="font-size:0.7rem;"><?= $row['charge'] ? 'PHP ' . number_format($row['charge'], 2) : '-' ?></td>
                            <td class="text-end <?= $row['payment'] ? 'fw-bold text-success' : 'text-muted' ?>" style="font-size:0.7rem;"><?= $row['payment'] ? 'PHP ' . number_format($row['payment'], 2) : '-' ?></td>
                            <td class="text-end fw-semibold <?= $running < 0 ? 'text-success' : 'text-dark' ?>" style="font-size:0.7rem;">PHP <?= number_format(abs($running), 2) ?><?= $running < 0 ? ' CR' : '' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DOCUMENTS TAB -->
    <div class="tab-pane fade <?= $openTab === 'documents' ? 'show active' : '' ?>" id="documents" role="tabpanel" aria-labelledby="documents-tab">
        <div class="row g-3">
            <div class="col-lg-5 order-lg-2">
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-upload text-primary me-2"></i>Add a Document</h6></div>
                    <form method="POST" enctype="multipart/form-data" class="p-3 form-compact">
                        <input type="hidden" name="action" value="upload_document">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <?php if ($docError): ?><div class="alert alert-danger py-2 px-3" style="font-size:0.78rem;"><?= htmlspecialchars($docError) ?></div><?php endif; ?>
                        <?php if ($docMessage): ?><div class="alert alert-success py-2 px-3" style="font-size:0.78rem;"><?= htmlspecialchars($docMessage) ?></div><?php endif; ?>
                        <div class="mb-2">
                            <label for="docTitle">Name</label>
                            <input type="text" id="docTitle" name="title" class="form-control" maxlength="120" required placeholder="e.g. Valid ID, Lease contract" value="<?= $docError ? htmlspecialchars($_POST['title'] ?? '') : '' ?>">
                        </div>
                        <div class="mb-3">
                            <label for="docFile">File</label>
                            <input type="file" id="docFile" name="document" class="form-control" required accept="image/jpeg,image/png,image/webp,application/pdf">
                            <div class="form-text" style="font-size:0.7rem;">Photo (JPG, PNG, WEBP) or PDF, up to 5MB. The tenant doesn't see these here.</div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-upload me-1"></i>Upload</button>
                    </form>
                </div>
            </div>
            <div class="col-lg-7 order-lg-1">
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-regular fa-folder-open text-primary me-2"></i>Documents</h6></div>
                    <?php if (!$documents): ?>
                        <div class="empty-state"><span><i class="fa-regular fa-file"></i></span><div class="fw-semibold text-dark">No documents yet.</div><div>Keep a copy of the tenant's ID or signed contract here.</div></div>
                    <?php else: foreach ($documents as $d): $isPdf = $d['file_type'] === 'pdf'; ?>
                        <div class="doc-row">
                            <span class="doc-icon <?= $isPdf ? 'bg-danger-subtle text-danger' : 'bg-primary-subtle text-primary' ?>"><i class="fa-regular <?= $isPdf ? 'fa-file-pdf' : 'fa-file-image' ?>"></i></span>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="fw-semibold text-dark text-truncate"><?= htmlspecialchars($d['title']) ?></div>
                                <div class="text-muted" style="font-size:0.7rem;"><?= strtoupper(htmlspecialchars($d['file_type'])) ?><?= $d['uploaded_at'] ? ' · Added ' . date('M j, Y', strtotime($d['uploaded_at'])) : '' ?></div>
                            </div>
                            <a href="<?= htmlspecialchars(uploadSrc($d['file_path'], '../')) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:0.7rem;">View</a>
                            <form method="POST" class="m-0" onsubmit="return confirm('Delete this document?');">
                                <input type="hidden" name="action" value="delete_document">
                                <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                <input type="hidden" name="document_id" value="<?= (int)$d['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.7rem;" aria-label="Delete <?= htmlspecialchars($d['title']) ?>"><i class="fa-regular fa-trash-can"></i></button>
                            </form>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ROOMS TAB: every room the tenant rents (the main room plus any additional ones) -->
    <div class="tab-pane fade <?= $openTab === 'rooms' ? 'show active' : '' ?>" id="rooms" role="tabpanel" aria-labelledby="rooms-tab">
        <?php if ($roomError): ?><div class="alert alert-danger py-2 px-3" style="font-size:0.78rem;"><?= htmlspecialchars(preg_replace('/^Error: /', '', $roomError)) ?></div><?php endif; ?>
        <?php if ($roomMessage): ?><div class="alert alert-success py-2 px-3" style="font-size:0.78rem;"><?= htmlspecialchars($roomMessage) ?></div><?php endif; ?>
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-door-open text-primary me-2"></i>Rented Rooms</h6></div>
                    <?php if (!$roomCount): ?>
                        <div class="empty-state"><span><i class="fa-solid fa-door-closed"></i></span><div class="fw-semibold text-dark"><?= $isDeactivated ? 'No rooms. The tenant has moved out.' : 'No room assigned yet.' ?></div><div>Rooms the tenant rents will appear here.</div></div>
                    <?php else:
                        // When the tenant came into their main room: their latest move into it.
                        $mainSince = $tenant['move_in_date'];
                        foreach ($roomHistory as $h) {
                            if ((int)($h['to_room_id'] ?? 0) === (int)$tenant['room_id']) { $mainSince = $h['transferred_at']; break; }
                        }
                        $rows = [];
                        if ($tenant['room_id']) {
                            $rows[] = ['id' => null, 'room_id' => (int)$tenant['room_id'], 'room_number' => $tenant['room_number'], 'capacity' => $roomCapacity,
                                       'price_per_month' => $roomPrice, 'rent' => $mainRoomRent, 'since' => $mainSince, 'main' => true,
                                       'whole_room' => $mainWholeRoom, 'solo_rule' => !empty($tenant['solo_pays_full'])];
                        }
                        foreach ($additionalRooms as $x) {
                            $rows[] = ['id' => (int)$x['id'], 'room_id' => (int)$x['room_id'], 'room_number' => $x['room_number'], 'capacity' => max(1, (int)$x['capacity']),
                                       'price_per_month' => (float)$x['price_per_month'], 'rent' => $x['rent'], 'since' => $x['start_date'], 'main' => false,
                                       'whole_room' => $x['whole_room'], 'solo_rule' => !empty($x['solo_pays_full'])];
                        }
                        foreach ($rows as $rr): ?>
                        <div class="rented-room">
                            <div class="d-flex align-items-center gap-3">
                                <span class="rented-room-icon <?= $rr['main'] ? 'bg-primary text-white' : 'bg-primary-subtle text-primary' ?>"><i class="fa-solid fa-bed"></i></span>
                                <div class="flex-grow-1" style="min-width:0;">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <a href="rooms.php?room=<?= $rr['room_id'] ?>" class="fw-bold text-dark text-decoration-none">Room <?= htmlspecialchars($rr['room_number']) ?></a>
                                        <span class="soft-pill <?= $rr['main'] ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>"><?= $rr['main'] ? 'Main room' : 'Additional room' ?></span>
                                    </div>
                                    <div class="text-muted" style="font-size:0.7rem;">
                                        <?= $rr['since'] ? 'Since ' . date('M j, Y', strtotime($rr['since'])) . ' · ' : '' ?><?= $bedsTaken[$rr['room_id']] ?? 0 ?> of <?= $rr['capacity'] ?> <?= $rr['capacity'] === 1 ? 'bed' : 'beds' ?> taken
                                        <?= $rr['whole_room'] ? '' : ($rr['capacity'] > 1 ? ' · 1 bed of the ₱' . number_format($rr['price_per_month'], 2) . ' room' : '') ?>
                                    </div>
                                    <?php if ($rr['whole_room']): ?>
                                        <div class="text-warning-emphasis" style="font-size:0.7rem;"><i class="fa-solid fa-circle-info me-1"></i>Pays the whole room: the only tenant in it (after their first month here), until someone else moves in.</div>
                                    <?php elseif ($rr['solo_rule'] && $rr['capacity'] > 1): ?>
                                        <div class="text-muted" style="font-size:0.7rem;"><i class="fa-solid fa-circle-info me-1"></i>Would pay the whole room if left as its only tenant.</div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    <div class="fw-bold text-dark">₱<?= number_format($rr['rent'], 2) ?></div>
                                    <div class="text-muted" style="font-size:0.66rem;">a month</div>
                                </div>
                            </div>
                            <?php if (!$rr['main']): ?>
                            <div class="mt-2 text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.7rem;" data-bs-toggle="collapse" data-bs-target="#release<?= $rr['id'] ?>" aria-expanded="false"><i class="fa-solid fa-right-from-bracket me-1"></i>Release room</button>
                            </div>
                            <div class="collapse" id="release<?= $rr['id'] ?>">
                                <form method="POST" class="mt-2 p-2 bg-light rounded-2 d-flex flex-wrap align-items-end gap-2" onsubmit="return confirm('Release Room <?= htmlspecialchars(addslashes($rr['room_number']), ENT_QUOTES) ?>? Its rent stops from the month after this date.');">
                                    <input type="hidden" name="action" value="release_room">
                                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                    <input type="hidden" name="tenant_room_id" value="<?= $rr['id'] ?>">
                                    <div class="flex-grow-1">
                                        <label for="releaseDate<?= $rr['id'] ?>" class="form-label mb-1" style="font-size:0.7rem;">Last day in the room</label>
                                        <input type="date" id="releaseDate<?= $rr['id'] ?>" name="end_date" class="form-control form-control-sm" required min="<?= htmlspecialchars($rr['since']) ?>" value="<?= date('Y-m-d') ?>">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-danger">Release</button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                        <div class="rooms-total"><span>Total monthly rent<?= $roomCount > 1 ? ' (' . $roomCount . ' rooms)' : '' ?></span><span>₱<?= number_format($monthlyRent, 2) ?></span></div>
                    <?php endif; ?>
                </div>

                <!-- Appliance charges: billed monthly with the rent -->
                <div class="section-card shadow-sm" id="appliances">
                    <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-plug text-primary me-2"></i>Appliance Charges</h6></div>
                    <?php if (!$myAppliances): ?>
                        <div class="empty-state"><span><i class="fa-solid fa-plug"></i></span><div class="fw-semibold text-dark">No appliance charges.</div><div>Appliances the tenant brings (fan, rice cooker...) are charged here every month.</div></div>
                    <?php else: foreach ($myAppliances as $ap): ?>
                        <div class="rented-room">
                            <div class="d-flex align-items-center gap-3">
                                <span class="rented-room-icon bg-warning-subtle text-warning-emphasis"><i class="fa-solid fa-plug"></i></span>
                                <div class="flex-grow-1" style="min-width:0;">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars(applianceLabel($ap['name'], (int)$ap['quantity'])) ?></div>
                                    <div class="text-muted" style="font-size:0.7rem;">
                                        <?= $ap['start_date'] ? 'Since ' . date('M j, Y', strtotime($ap['start_date'])) : 'From move-in' ?><?= (int)$ap['quantity'] > 1 ? ' · ₱' . number_format((float)$ap['unit_fee'], 2) . ' each' : '' ?>
                                    </div>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    <div class="fw-bold text-dark">₱<?= number_format($ap['monthly'], 2) ?></div>
                                    <div class="text-muted" style="font-size:0.66rem;">a month</div>
                                </div>
                            </div>
                            <div class="mt-2 text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.7rem;" data-bs-toggle="collapse" data-bs-target="#applRemove<?= (int)$ap['id'] ?>" aria-expanded="false"><i class="fa-regular fa-trash-can me-1"></i>Remove</button>
                            </div>
                            <div class="collapse" id="applRemove<?= (int)$ap['id'] ?>">
                                <form method="POST" class="mt-2 p-2 bg-light rounded-2 d-flex flex-wrap align-items-end gap-2" onsubmit="return confirm('Stop charging for <?= htmlspecialchars(addslashes($ap['name']), ENT_QUOTES) ?>? The charge stops after the month of this date.');">
                                    <input type="hidden" name="action" value="remove_appliance">
                                    <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                    <input type="hidden" name="tenant_appliance_id" value="<?= (int)$ap['id'] ?>">
                                    <div class="flex-grow-1">
                                        <label for="applEnd<?= (int)$ap['id'] ?>" class="form-label mb-1" style="font-size:0.7rem;">Last day it is used</label>
                                        <input type="date" id="applEnd<?= (int)$ap['id'] ?>" name="end_date" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                        <div class="rooms-total"><span>Total appliance charges</span><span>₱<?= number_format($applianceTotal, 2) ?></span></div>
                    <?php endif; ?>
                </div>
                <?php if ($applianceTotal > 0 && $roomCount): ?>
                <div class="section-card shadow-sm"><div class="rooms-total" style="border-radius:10px; border-top:0;"><span>Total monthly charges (rent + appliances)</span><span>₱<?= number_format($monthlyRent + $applianceTotal, 2) ?></span></div></div>
                <?php endif; ?>
                <div class="text-muted px-1" style="font-size:0.7rem;"><i class="fa-solid fa-circle-info me-1"></i>The tenant sees these rooms on their dashboard. To move them to a different main room, use Change room on the Tenants or Rooms page.</div>
            </div>
            <div class="col-lg-5">
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-plus text-primary me-2"></i>Rent Another Room</h6></div>
                    <div class="p-3 form-compact">
                        <?php if ($tenant['status'] !== 'active'): ?>
                            <div class="text-muted" style="font-size:0.78rem;">This tenant has moved out. Re-activate them from the Tenants page to rent them rooms again.</div>
                        <?php elseif (!$canAddRoom): ?>
                            <div class="text-muted" style="font-size:0.78rem;">Assign the tenant's main room first (Tenants page &rsaquo; Assign room). Then more rooms can be added here.</div>
                        <?php else:
                            $freeChoices = array_values(array_filter($roomChoices, fn($r) => (int)$r['taken'] < (int)$r['capacity'])); ?>
                            <?php if (!$freeChoices): ?>
                                <div class="text-muted" style="font-size:0.78rem;">No other room has a free bed right now.</div>
                            <?php else: ?>
                            <form method="POST" class="m-0" onsubmit="const o = this.room_id.selectedOptions[0]; return confirm('Rent ' + o.dataset.label + ' to this tenant as well, at ₱' + o.dataset.rent + ' a month?');">
                                <input type="hidden" name="action" value="add_room">
                                <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                <div class="mb-2">
                                    <label for="addRoomSelect">Room</label>
                                    <select id="addRoomSelect" name="room_id" class="form-select" required>
                                        <option value="">Choose a room</option>
                                        <?php foreach ($freeChoices as $r):
                                            // Their regular rent once they take a bed: the whole room if they'd be alone in a room set for it.
                                            $rent = bedRent($r, (int)$r['taken'] + 1);
                                            $free = (int)$r['capacity'] - (int)$r['taken']; ?>
                                            <option value="<?= (int)$r['id'] ?>" data-label="Room <?= htmlspecialchars($r['room_number']) ?>" data-rent="<?= number_format($rent, 2) ?>"
                                                <?= $roomError && (int)($_POST['room_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>>
                                                Room <?= htmlspecialchars($r['room_number']) ?> · ₱<?= number_format($rent, 2) ?>/month<?= soloPaysWholeRoom($r, (int)$r['taken'] + 1) ? ' (whole room while alone)' : '' ?> · <?= $free ?> free <?= $free === 1 ? 'bed' : 'beds' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label for="addRoomStart">Start date</label>
                                    <input type="date" id="addRoomStart" name="start_date" class="form-control" required
                                           min="<?= htmlspecialchars($tenant['move_in_date'] ?? '') ?>"
                                           value="<?= htmlspecialchars($roomError && !empty($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d')) ?>">
                                    <div class="form-text" style="font-size:0.7rem;">The tenant takes one bed in it. Its first month is due on this day (half rent if it is after the 15th), then it is billed every month with their other rent.</div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add Room</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-plug text-primary me-2"></i>Add an Appliance</h6></div>
                    <div class="p-3 form-compact">
                        <?php $applianceChoices = activeAppliances($pdo);
                              $chargedIds = array_map('intval', array_column($myAppliances, 'appliance_id'));
                              $applianceChoices = array_values(array_filter($applianceChoices, fn($a) => !in_array((int)$a['id'], $chargedIds, true))); ?>
                        <?php if ($tenant['status'] !== 'active'): ?>
                            <div class="text-muted" style="font-size:0.78rem;">This tenant has moved out.</div>
                        <?php elseif (!$applianceChoices): ?>
                            <div class="text-muted" style="font-size:0.78rem;"><?= $myAppliances ? 'Every appliance on the list is already charged.' : 'No appliance charges set up yet.' ?> Manage the list in <a href="settings.php#appliances">Settings &rsaquo; Appliance Charges</a>.</div>
                        <?php else: ?>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action" value="add_appliance">
                            <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                            <div class="row g-2 mb-2">
                                <div class="col-8">
                                    <label for="applSelect">Appliance</label>
                                    <select id="applSelect" name="appliance_id" class="form-select" required>
                                        <option value="">Choose</option>
                                        <?php foreach ($applianceChoices as $a): ?>
                                            <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['name']) ?> · ₱<?= number_format((float)$a['monthly_fee'], 2) ?>/mo</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label for="applQty">How many</label>
                                    <input type="number" id="applQty" name="quantity" class="form-control" min="1" max="20" value="1" required>
                                </div>
                            </div>
                            <?php if (!empty($tenant['move_in_date'])): ?>
                            <div class="mb-3">
                                <label for="applStart">Start date</label>
                                <input type="date" id="applStart" name="start_date" class="form-control" required min="<?= htmlspecialchars($tenant['move_in_date']) ?>" value="<?= date('Y-m-d') ?>">
                                <div class="form-text" style="font-size:0.7rem;">Its first month is due on this day (half if after the 15th), then it is charged every month with the rent.</div>
                            </div>
                            <?php else: ?>
                            <div class="form-text mb-3" style="font-size:0.7rem;">Charged from the tenant's move-in, once they get a room.</div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add Appliance</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- DAMAGES TAB: things found broken in the boarding house, charged once to the tenant -->
    <div class="tab-pane fade <?= $openTab === 'damages' ? 'show active' : '' ?>" id="damages" role="tabpanel" aria-labelledby="damages-tab">
        <?php if ($damageError): ?><div class="alert alert-danger py-2 px-3" style="font-size:0.78rem;"><?= htmlspecialchars($damageError) ?></div><?php endif; ?>
        <?php if ($damageMessage): ?><div class="alert alert-success py-2 px-3" style="font-size:0.78rem;"><?= htmlspecialchars($damageMessage) ?></div><?php endif; ?>
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-hammer text-primary me-2"></i>Damage Charges</h6></div>
                    <?php if (!$damages): ?>
                        <div class="empty-state"><span><i class="fa-solid fa-hammer"></i></span><div class="fw-semibold text-dark">No damage charges.</div><div>If something in the boarding house is found broken, charge it here.</div></div>
                    <?php else: foreach ($damages as $d): $pastDue = $d['due_date'] < date('Y-m-d'); ?>
                        <div class="rented-room">
                            <div class="d-flex align-items-start gap-3">
                                <span class="rented-room-icon bg-danger-subtle text-danger"><i class="fa-solid fa-hammer"></i></span>
                                <div class="flex-grow-1" style="min-width:0;">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($d['item']) ?><?= $d['room_number'] ? ' <span class="text-muted fw-normal">· Room ' . htmlspecialchars($d['room_number']) . '</span>' : '' ?></div>
                                    <div class="text-muted" style="font-size:0.7rem;">Found <?= date('M j, Y', strtotime($d['found_on'])) ?> · Due <?= date('M j, Y', strtotime($d['due_date'])) ?></div>
                                    <?php if (!empty($d['split_group']) && !empty($damageSplits[$d['split_group']])):
                                        $shares = $damageSplits[$d['split_group']];
                                        $others = array_filter($shares, fn($x) => $x['tenant_id'] !== $tenantId); ?>
                                        <div class="mt-1" style="font-size:0.7rem;">
                                            <span class="soft-pill bg-primary-subtle text-primary-emphasis">Split <?= count($shares) ?> ways</span>
                                            <span class="text-muted">₱<?= number_format(array_sum(array_column($shares, 'amount')), 2) ?> total, shared with
                                            <?= implode(', ', array_map(fn($x) => '<a href="tenant_details.php?id=' . $x['tenant_id'] . '">' . htmlspecialchars($x['name']) . '</a>', $others)) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($d['note'])): ?><div class="text-dark mt-1" style="font-size:0.72rem;"><?= nl2br(htmlspecialchars($d['note'])) ?></div><?php endif; ?>
                                    <?php if (!empty($d['photo_path'])): ?>
                                        <a href="<?= htmlspecialchars(uploadSrc($d['photo_path'], '../')) ?>" target="_blank" rel="noopener" class="d-inline-block mt-2"><img src="<?= htmlspecialchars(uploadSrc($d['photo_path'], '../')) ?>" alt="Photo of the damage" style="height:64px; width:auto; max-width:120px; object-fit:cover; border-radius:6px; border:1px solid #e2e8f0;"></a>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end flex-shrink-0">
                                    <div class="fw-bold text-dark">₱<?= number_format((float)$d['amount'], 2) ?></div>
                                    <?php if (!empty($d['split_group'])): ?><div class="text-muted" style="font-size:0.66rem;">their share</div><?php endif; ?>
                                    <?php if ($pastDue): ?><div class="text-danger" style="font-size:0.66rem;">past due date</div><?php endif; ?>
                                    <form method="POST" class="mt-2" onsubmit="return confirm('<?= !empty($d['split_group']) ? 'Remove this split damage charge? It is taken off the balance of every tenant sharing it.' : 'Remove this damage charge? It is taken off the tenant\\\'s balance.' ?>');">
                                        <input type="hidden" name="action" value="remove_damage">
                                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                                        <input type="hidden" name="damage_id" value="<?= (int)$d['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.7rem;" aria-label="Remove damage charge for <?= htmlspecialchars($d['item']) ?>"><i class="fa-regular fa-trash-can"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                        <div class="rooms-total"><span>Total damage charges</span><span>₱<?= number_format($damageTotal, 2) ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="text-muted px-1" style="font-size:0.7rem;"><i class="fa-solid fa-circle-info me-1"></i>Damage charges go on the tenant's balance and show in the Ledger. Payments cover them like rent.</div>
            </div>
            <div class="col-lg-5">
                <div class="section-card shadow-sm">
                    <div class="section-header"><h6 class="section-title"><i class="fa-solid fa-plus text-primary me-2"></i>Charge for Damage</h6></div>
                    <?php $old = $damageError ? $_POST : []; ?>
                    <form method="POST" enctype="multipart/form-data" class="p-3 form-compact" onsubmit="return confirm('Add this damage charge to the tenant\'s balance?');">
                        <input type="hidden" name="action" value="add_damage">
                        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">
                        <div class="mb-2">
                            <label for="dmgItem">What was damaged</label>
                            <input type="text" id="dmgItem" name="item" class="form-control" maxlength="120" required placeholder="e.g. Broken window, damaged door lock" value="<?= htmlspecialchars($old['item'] ?? '') ?>">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label for="dmgAmount">Amount (₱)</label>
                                <input type="number" id="dmgAmount" name="amount" class="form-control" min="0.01" step="0.01" required value="<?= htmlspecialchars($old['amount'] ?? '') ?>">
                            </div>
                            <div class="col-6">
                                <label for="dmgRoom">Where</label>
                                <select id="dmgRoom" name="room_id" class="form-select">
                                    <option value="">Common area / other</option>
                                    <?php
                                    $allRoomsForDamage = $pdo->query("SELECT id, room_number FROM rooms")->fetchAll();
                                    usort($allRoomsForDamage, fn($a, $b) => strnatcasecmp((string)$a['room_number'], (string)$b['room_number']));
                                    $defaultDamageRoom = (int)($old['room_id'] ?? ($tenant['room_id'] ?? 0));
                                    foreach ($allRoomsForDamage as $r): ?>
                                        <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === $defaultDamageRoom ? 'selected' : '' ?>>Room <?= htmlspecialchars($r['room_number']) ?><?= in_array((int)$r['id'], $rentedRoomIds, true) ? ' (theirs)' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php
                        // Who lives in each room (besides this tenant), for "Split with roommates".
                        $roommatesByRoom = [];
                        foreach ($allRoomsForDamage as $r) {
                            $mates = array_values(array_filter(roomOccupants($pdo, (int)$r['id']), fn($o) => (int)$o['id'] !== $tenantId));
                            if ($mates) {
                                $roommatesByRoom[(int)$r['id']] = array_map(fn($o) => ['id' => (int)$o['id'], 'name' => trim($o['first_name'] . ' ' . $o['last_name'])], $mates);
                            }
                        }
                        $oldSplitWith = array_map('intval', (array)($old['split_with'] ?? []));
                        ?>
                        <div class="split-box mb-2 p-2 rounded-2 border bg-light d-none" id="dmgSplitBox">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="split" value="1" id="dmgSplit" <?= !empty($old['split']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="dmgSplit" style="font-size:0.75rem;">Split with roommates</label>
                            </div>
                            <div id="dmgSplitList" class="mt-1"></div>
                            <div id="dmgSplitPreview" class="text-primary fw-semibold" style="font-size:0.7rem;"></div>
                        </div>
                        <script>
                        (function () {
                            const roommates = <?= json_encode($roommatesByRoom) ?>;
                            const keep = <?= json_encode($oldSplitWith) ?>;
                            const form = document.getElementById('dmgItem').form;
                            const room = document.getElementById('dmgRoom'), box = document.getElementById('dmgSplitBox');
                            const toggle = document.getElementById('dmgSplit'), list = document.getElementById('dmgSplitList');
                            const preview = document.getElementById('dmgSplitPreview'), amount = document.getElementById('dmgAmount');
                            const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            let first = true;
                            function renderList() {
                                const mates = roommates[room.value] || [];
                                box.classList.toggle('d-none', mates.length === 0);
                                if (!mates.length) toggle.checked = false;
                                list.innerHTML = '';
                                mates.forEach(m => {
                                    const id = 'dmgMate' + m.id;
                                    const wrap = document.createElement('div');
                                    wrap.className = 'form-check mb-0';
                                    wrap.innerHTML = '<input class="form-check-input" type="checkbox" name="split_with[]" id="' + id + '">'
                                                   + '<label class="form-check-label" style="font-size:0.72rem;" for="' + id + '"></label>';
                                    const input = wrap.querySelector('input');
                                    input.value = m.id;
                                    input.checked = first && keep.length ? keep.includes(m.id) : true;
                                    wrap.querySelector('label').textContent = m.name;
                                    list.appendChild(wrap);
                                });
                                first = false;
                                update();
                            }
                            function update() {
                                list.classList.toggle('d-none', !toggle.checked);
                                list.querySelectorAll('input').forEach(i => i.disabled = !toggle.checked);
                                const n = toggle.checked ? list.querySelectorAll('input:checked').length + 1 : 1;
                                const total = parseFloat(amount.value);
                                preview.textContent = toggle.checked && n > 1 && total > 0
                                    ? n + ' tenants · about ' + peso(Math.floor(total * 100 / n) / 100) + ' each' : '';
                            }
                            room.addEventListener('change', renderList);
                            form.addEventListener('input', update);
                            form.addEventListener('change', e => { if (e.target !== room) update(); });
                            renderList();
                        })();
                        </script>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label for="dmgFound">Found on</label>
                                <input type="date" id="dmgFound" name="found_on" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($old['found_on'] ?? date('Y-m-d')) ?>">
                            </div>
                            <div class="col-6">
                                <label for="dmgDue">Due date</label>
                                <input type="date" id="dmgDue" name="due_date" class="form-control" required value="<?= htmlspecialchars($old['due_date'] ?? billingDueDate(date('Y-m'))) ?>">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label for="dmgNote">Note <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea id="dmgNote" name="note" class="form-control" rows="2" maxlength="2000" placeholder="What happened, how the amount was worked out"><?= htmlspecialchars($old['note'] ?? '') ?></textarea>
                        </div>
                        <div class="mb-2">
                            <label for="dmgPhoto">Photo <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="file" id="dmgPhoto" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                            <div class="form-text" style="font-size:0.7rem;">A picture of the damage, as proof. The tenant can see it.</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="notify" value="1" id="dmgNotify" <?= !$damageError || !empty($old['notify']) ? 'checked' : '' ?> <?= smsConfigured($pdo) ? '' : 'disabled' ?>>
                            <label class="form-check-label" for="dmgNotify" style="font-size:0.75rem;">Text the tenant about this charge<?= smsConfigured($pdo) ? '' : ' (SMS not set up)' ?></label>
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm w-100"><i class="fa-solid fa-plus me-1"></i>Add Damage Charge</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div> <!-- Close Tab Content -->

<?php require_once 'footer.php'; ?>
