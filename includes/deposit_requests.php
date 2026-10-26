<?php
// includes/deposit_requests.php
//
// "Use my deposit for my last month": the system can't know which month a tenant's last is, so
// the tenant says so from their portal and the admin approves it. Approving posts the deposit
// as a credit against that month (the same 'deposit_applied' entry a move-out posts), so the
// month is paid. A deposit is used once per stay: moving out afterwards doesn't apply it again.

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/sms_templates.php';

/** The deposit already used during the tenant's current stay: ['billing_month', 'amount'], or null. */
function depositAppliedThisStay(PDO $pdo, array $tenant): ?array {
    if (empty($tenant['move_in_date'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT billing_month, -amount AS amount FROM charges
                           WHERE tenant_id = ? AND kind = ? AND billing_month >= ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenant['id'], DEPOSIT_APPLIED_KIND, date('Y-m', strtotime($tenant['move_in_date']))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function pendingDepositRequest(PDO $pdo, int $tenantId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM deposit_requests WHERE tenant_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenantId]);
    return $stmt->fetch() ?: null;
}

function latestDepositRequest(PDO $pdo, int $tenantId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM deposit_requests WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenantId]);
    return $stmt->fetch() ?: null;
}

/** The months a tenant can name as their last: this month and the next two. */
function depositMonthChoices(): array {
    $out = [];
    for ($i = 0; $i < 3; $i++) {
        $month = date('Y-m', strtotime(date('Y-m-01') . " +$i month"));
        $out[$month] = date('F Y', strtotime("$month-01"));
    }
    return $out;
}

/**
 * The tenant asks to use their deposit for $month.
 * @return string '' when the request was sent, otherwise why not
 */
function requestDepositUse(PDO $pdo, array $tenant, string $month): string {
    if (($tenant['status'] ?? '') !== 'active' || empty($tenant['room_id'])) {
        return 'Only tenants with a room can use their deposit.';
    }
    if (!array_key_exists($month, depositMonthChoices())) {
        return 'Please choose your last month.';
    }
    $deposit = tenantDeposit($pdo, $tenant);
    if ($deposit <= 0) {
        return 'There is no deposit on record for your stay.';
    }
    if (depositAppliedThisStay($pdo, $tenant)) {
        return 'Your deposit has already been used.';
    }
    if (pendingDepositRequest($pdo, (int)$tenant['id'])) {
        return 'You already have a request waiting for the admin.';
    }
    $requestId = insertReturningId($pdo, "INSERT INTO deposit_requests (tenant_id, billing_month, status, created_at) VALUES (?, ?, 'pending', ?)",
                                   [$tenant['id'], $month, date('Y-m-d H:i:s')]);
    $name = trim($tenant['first_name'] . ' ' . $tenant['last_name']);
    notifyAdmin($pdo, 'deposit_request', "Deposit request: $name",
        'Wants their PHP ' . number_format($deposit, 2) . ' deposit to pay for ' . date('F Y', strtotime("$month-01")) . ' (last month).',
        'tenant_details.php?id=' . (int)$tenant['id'] . '#deposit', "deposit_request:$requestId");
    return '';
}

/** The tenant takes back a request the admin hasn't answered yet. */
function cancelDepositRequest(PDO $pdo, int $tenantId): void {
    $pending = pendingDepositRequest($pdo, $tenantId);
    if ($pending) {
        $pdo->prepare("UPDATE deposit_requests SET status = 'cancelled', decided_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $pending['id']]);
        markNotificationsRead($pdo, 'deposit_request:' . $pending['id']);
    }
}

/**
 * The admin approves or declines a tenant's pending request.
 * @return array ['error' => string, 'applied' => float, 'sms' => array|null]
 */
function decideDepositRequest(PDO $pdo, int $requestId, bool $approve, string $note = ''): array {
    $note = mb_substr(trim($note), 0, 255);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT r.*, t.first_name, t.last_name, t.contact_number, t.status AS tenant_status, t.move_in_date, t.room_id
                               FROM deposit_requests r JOIN tenants t ON t.id = r.tenant_id WHERE r.id = ? FOR UPDATE");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req || $req['status'] !== 'pending') {
            throw new Exception('This request was already answered.');
        }
        $tenant = ['id' => $req['tenant_id'], 'status' => $req['tenant_status'], 'move_in_date' => $req['move_in_date']];
        $applied = 0.0;
        if ($approve) {
            $deposit = tenantDeposit($pdo, $tenant);
            if ($deposit <= 0) throw new Exception('This tenant has no deposit on record.');
            if (depositAppliedThisStay($pdo, $tenant)) throw new Exception('This tenant\'s deposit has already been used.');
            $month = $req['billing_month'];
            $pdo->prepare("INSERT INTO charges (tenant_id, room_id, kind, billing_month, description, amount, due_date) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$req['tenant_id'], $req['room_id'], DEPOSIT_APPLIED_KIND, $month,
                           'Deposit applied to the last month\'s rent (' . date('F Y', strtotime("$month-01")) . ')', -$deposit, billingDueDate($month)]);
            $pdo->prepare("UPDATE tenants SET balance = balance - ? WHERE id = ?")->execute([$deposit, $req['tenant_id']]);
            $applied = $deposit;
        }
        $pdo->prepare("UPDATE deposit_requests SET status = ?, note = ?, decided_at = ? WHERE id = ?")
            ->execute([$approve ? 'approved' : 'declined', $note !== '' ? $note : null, date('Y-m-d H:i:s'), $requestId]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => $e->getMessage(), 'applied' => 0.0, 'sms' => null];
    }

    $name = trim($req['first_name'] . ' ' . $req['last_name']);
    $monthLabel = date('F Y', strtotime($req['billing_month'] . '-01'));
    markNotificationsRead($pdo, 'deposit_request:' . $requestId);
    if ($approve) {
        $bal = $pdo->prepare("SELECT balance FROM tenants WHERE id = ?");
        $bal->execute([$req['tenant_id']]);
        $message = smsDepositApprovedMessage($pdo, $name, $applied, $monthLabel, (float)$bal->fetchColumn());
    } else {
        $message = smsDepositDeclinedMessage($pdo, $name, $monthLabel, $note);
    }
    $sms = sendSMS($pdo, $req['contact_number'], $message, 'deposit_request', (int)$req['tenant_id']);
    return ['error' => '', 'applied' => $applied, 'sms' => $sms];
}
