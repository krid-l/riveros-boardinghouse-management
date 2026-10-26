<?php
// cron/daily.php
//
// The daily run (includes/daily_jobs.php): rent reminders by SMS and the admin's "rent due
// soon" notice. Started by itself every morning in the background by the app; it can also be
// put on a real scheduler (see cron/send_reminders.php for how). Running it more than once a
// day is harmless. Command line only.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/daily_jobs.php';

$r = runDailyJobs($pdo);
printf("[%s] Daily run: %d reminders sent (%d due, %d overdue), %d not sent.\n",
    date('Y-m-d H:i:s'), $r['sent'], $r['due'], $r['overdue'], $r['failed'] + $r['skipped']);
