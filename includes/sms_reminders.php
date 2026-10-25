<?php
// includes/sms_reminders.php
//
// Rent reminders by SMS (wording in sms_templates.php).
//
//   3 days before - the tenant owes something, nothing is late yet, and the bill falls due within
//                   REMINDER_DAYS_BEFORE days. Sent once per due date.
//   On the day    - the same bill, on its due date. Sent once.
//   Overdue       - part of the balance is past its due date. Sent at most once a week until it
//                   is paid.
//
// They go out by themselves every morning (includes/daily_jobs.php), when the admin presses
// "Send rent reminders" on the Payments page, or from cron/send_reminders.php. Either way a tenant
// is never texted twice for the same thing.

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/sms_templates.php';

const REMINDER_DAYS_BEFORE = 3;

/** Has a message with this ref already gone out? */
function smsAlreadySent(PDO $pdo, string $ref): bool {
    // A reminder PhilSMS didn't answer for may well have arrived; better not to send it twice.
    $stmt = $pdo->prepare("SELECT 1 FROM sms_log WHERE ref = ? AND status IN ('sent', 'unknown')");
    $stmt->execute([$ref]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Who should get a reminder right now, and what it says.
 * @return array list of [tenant_id, name, phone, kind ('due'|'due_today'|'overdue'), amount, ref, message]
 */
function pendingRentReminders(PDO $pdo): array {
    $notYetDue = chargesNotYetDue($pdo);
    $today = date('Y-m-d');
    $soonLimit = date('Y-m-d', strtotime('+' . REMINDER_DAYS_BEFORE . ' days'));

    $tenants = $pdo->query("SELECT id, first_name, last_name, contact_number, balance, status, room_id, move_in_date
                            FROM tenants WHERE status = 'active' AND balance > 0
                            ORDER BY first_name, last_name")->fetchAll();
    $dueOn = $pdo->prepare("SELECT SUM(amount) FROM charges WHERE tenant_id = ? AND due_date = ? AND amount > 0");
    $reminders = [];
    foreach ($tenants as $t) {
        $status = tenantBillingStatus($t, $notYetDue);
        $name = trim($t['first_name'] . ' ' . $t['last_name']);

        if ($status['key'] === 'overdue') {
            $since = oldestUnpaidDueDate($pdo, (int)$t['id'], $status['balance']);
            // One a week: the ref changes every ISO week while the debt stays.
            $reminders[] = ['tenant_id' => (int)$t['id'], 'name' => $name, 'phone' => $t['contact_number'],
                            'kind' => 'overdue', 'amount' => $status['overdue'], 'ref' => 'overdue:' . $t['id'] . ':' . date('o-W'),
                            'message' => smsOverdueMessage($pdo, $name, $status['overdue'], $since)];
        } elseif ($status['key'] === 'due') {
            $due = upcomingDueDate($pdo, (int)$t['id']);
            if (!$due || $due > $soonLimit) {
                continue;
            }
            $dueOn->execute([$t['id'], $due]);
            $rent = (float)$dueOn->fetchColumn() ?: $status['balance'];   // the bill falling due that day
            // Two reminders per bill: one in the days before, one on the day itself.
            $kind = $due === $today ? 'due_today' : 'due';
            $reminders[] = ['tenant_id' => (int)$t['id'], 'name' => $name, 'phone' => $t['contact_number'],
                            'kind' => $kind, 'amount' => $rent, 'ref' => ($kind === 'due_today' ? 'due_today:' : 'due:') . $t['id'] . ':' . $due,
                            'message' => smsDueReminderMessage($pdo, $name, $rent, $due, $status['balance'])];
        }
    }

    return array_values(array_filter($reminders, fn($r) => !smsAlreadySent($pdo, $r['ref'])));
}

/**
 * Send every pending reminder.
 * @return array the counts of sendBulkSMS(), plus due (before or on the due date) and overdue (reminders sent)
 */
function sendRentReminders(PDO $pdo): array {
    $total = mergeSmsResults() + ['due' => 0, 'overdue' => 0];
    foreach (pendingRentReminders($pdo) as $r) {
        $result = sendSMS($pdo, $r['phone'], $r['message'], 'reminder_' . $r['kind'], $r['tenant_id'], $r['ref']);
        $total = array_merge($total, mergeSmsResults($total, $result));
        if ($result['sent'] > 0) {
            $total[$r['kind'] === 'overdue' ? 'overdue' : 'due']++;
        }
    }
    return $total;
}
