<?php
// includes/sms_reminders.php
//
// Rent reminders by SMS.
//
//   Due soon  - the tenant owes something, nothing is late yet, and the bill falls due within
//               REMINDER_DAYS_BEFORE days. Sent once per due date.
//   Overdue   - part of the balance is past its due date. Sent at most once a week until it
//               is paid.
//
// Reminders go out when the admin presses "Send rent reminders" on the Payments page, or when
// cron/send_reminders.php runs on a schedule. Either way a tenant is never texted twice for the
// same thing, so pressing the button again (or running the job daily) is safe.

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/sms.php';

const REMINDER_DAYS_BEFORE = 3;

/** Has a message with this ref already gone out? */
function smsAlreadySent(PDO $pdo, string $ref): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM sms_log WHERE ref = ? AND status = 'sent'");
    $stmt->execute([$ref]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Who should get a reminder right now, and what it says.
 * @return array list of [tenant_id, name, phone, kind ('due'|'overdue'), amount, ref, message]
 */
function pendingRentReminders(PDO $pdo): array {
    $config = smsConfig($pdo);
    $notYetDue = chargesNotYetDue($pdo);
    $today = date('Y-m-d');
    $soonLimit = date('Y-m-d', strtotime('+' . REMINDER_DAYS_BEFORE . ' days'));

    $tenants = $pdo->query("SELECT id, first_name, last_name, contact_number, balance, status, room_id, move_in_date
                            FROM tenants WHERE status = 'active' AND balance > 0
                            ORDER BY first_name, last_name")->fetchAll();
    $reminders = [];
    foreach ($tenants as $t) {
        $status = tenantBillingStatus($t, $notYetDue);
        $name = trim($t['first_name'] . ' ' . $t['last_name']);

        if ($status['key'] === 'overdue') {
            $since = oldestUnpaidDueDate($pdo, (int)$t['id'], $status['balance']);
            // One a week: the ref changes every ISO week while the debt stays.
            $ref = 'overdue:' . $t['id'] . ':' . date('o-W');
            $message = sprintf(
                '%s: Hi %s, you have an overdue rent balance of PHP %s%s. Please settle it via GCash and upload the screenshot in the tenant portal. Thank you!',
                $config['name'], $t['first_name'], number_format($status['overdue'], 2),
                $since ? ' (due since ' . date('M j', strtotime($since)) . ')' : ''
            );
            $reminders[] = ['tenant_id' => (int)$t['id'], 'name' => $name, 'phone' => $t['contact_number'],
                            'kind' => 'overdue', 'amount' => $status['overdue'], 'ref' => $ref, 'message' => $message];
        } elseif ($status['key'] === 'due') {
            $due = upcomingDueDate($pdo, (int)$t['id']);
            if (!$due || $due > $soonLimit) {
                continue;
            }
            $ref = 'due:' . $t['id'] . ':' . $due;
            $when = $due === $today ? 'today' : ($due === date('Y-m-d', strtotime('+1 day')) ? 'tomorrow' : 'on ' . date('M j', strtotime($due)));
            $message = sprintf(
                '%s: Hi %s, your rent of PHP %s is due %s. Please pay via GCash and upload the screenshot in the tenant portal. Thank you!',
                $config['name'], $t['first_name'], number_format($status['balance'], 2), $when
            );
            $reminders[] = ['tenant_id' => (int)$t['id'], 'name' => $name, 'phone' => $t['contact_number'],
                            'kind' => 'due', 'amount' => $status['balance'], 'ref' => $ref, 'message' => $message];
        }
    }

    return array_values(array_filter($reminders, fn($r) => !smsAlreadySent($pdo, $r['ref'])));
}

/**
 * Send every pending reminder.
 * @return array the counts of sendBulkSMS(), plus due and overdue (reminders sent of each kind)
 */
function sendRentReminders(PDO $pdo): array {
    $total = mergeSmsResults() + ['due' => 0, 'overdue' => 0];
    foreach (pendingRentReminders($pdo) as $r) {
        $result = sendSMS($pdo, $r['phone'], $r['message'], 'reminder_' . $r['kind'], $r['tenant_id'], $r['ref']);
        $total = array_merge($total, mergeSmsResults($total, $result));
        if ($result['sent'] > 0) {
            $total[$r['kind']]++;
        }
    }
    return $total;
}
