<?php
// cron/send_reminders.php
//
// Texts rent reminders (see includes/sms_reminders.php). Meant to run once a day on a schedule:
//
//   WAMP (Windows Task Scheduler), daily at 8:00 AM:
//     Program:   C:\wamp64\bin\php\php8.x.x\php.exe
//     Arguments: C:\wamp64\www\riveros-boardinghouse-management\cron\send_reminders.php
//
//   Linux cron:
//     0 8 * * *  php /path/to/app/cron/send_reminders.php
//
// Running it more often is harmless: nobody gets the same reminder twice.
// It only runs from the command line; opened in a browser it does nothing.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/sms_reminders.php';

$result = sendRentReminders($pdo);
printf(
    "[%s] Rent reminders: %d sent (%d due soon, %d overdue), %d failed, %d skipped.\n",
    date('Y-m-d H:i:s'), $result['sent'], $result['due'], $result['overdue'], $result['failed'], $result['skipped']
);
if ($result['failed'] + $result['skipped'] > 0) {
    echo '  Not sent: ' . smsErrorText($result) . "\n";
}
exit($result['failed'] > 0 ? 1 : 0);
