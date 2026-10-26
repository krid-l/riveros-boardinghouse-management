<?php
// includes/daily_jobs.php
//
// Things that happen once a day by themselves, without the owner pressing anything:
//   - rent reminders by SMS (3 days before the due date, on the due date, weekly when overdue)
//   - the admin's "rent due soon" notice
//
// There is no scheduler to rely on (WAMP has none; on Railway it would be a separate service),
// so the first page anyone opens after DAILY_JOBS_HOUR each day starts the run. Only one request
// gets to start it (the date is claimed in the settings table), and the texts go out in the
// background where possible so nobody waits for them:
//   - PHP's built-in server on Linux (Railway): a separate PHP process (cron/daily.php);
//   - PHP-FPM: after the page has been sent (fastcgi_finish_request);
//   - anything else (Apache under WAMP): right after the page, in the same request.
// cron/daily.php can also be run from a real scheduler; it is safe to run more than once.

require_once __DIR__ . '/sms_reminders.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/sql_compat.php';

const DAILY_JOBS_HOUR = 8;   // 8:00 AM Manila time, so nobody is texted in the middle of the night

/** Called on every page load (db.php): starts today's run once it's time. Cheap otherwise. */
function triggerDailyJobs(PDO $pdo): void {
    if (PHP_SAPI === 'cli' || (int)date('G') < DAILY_JOBS_HOUR) {
        return;
    }
    $today = date('Y-m-d');
    try {
        $last = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'daily_jobs_date'")->fetchColumn();
        if ($last === $today) {
            return;
        }
        if ($last === false) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('daily_jobs_date', '') " . sqlUpsert(['setting_key'], ['setting_value']))
                ->execute();
        }
        // Claim today's run: of two requests arriving together, only one changes the row.
        $claim = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'daily_jobs_date' AND setting_value <> ?");
        $claim->execute([$today, $today]);
        if ($claim->rowCount() !== 1) {
            return;
        }
    } catch (PDOException $e) {
        error_log('Daily jobs: could not claim the run: ' . $e->getMessage());
        return;
    }

    if (PHP_SAPI === 'cli-server' && PHP_OS_FAMILY !== 'Windows' && function_exists('exec') && is_executable(PHP_BINARY)) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../cron/daily.php') . ' > /dev/null 2>&1 &');
        return;
    }
    register_shutdown_function(function () use ($pdo) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();   // the visitor has the page; the rest happens on our time
        }
        ignore_user_abort(true);
        set_time_limit(300);
        runDailyJobs($pdo);
    });
}

/**
 * Today's work. Safe to run more than once: reminders already sent are never sent again.
 * @return array what sendRentReminders() returned
 */
function runDailyJobs(PDO $pdo): array {
    $result = mergeSmsResults() + ['due' => 0, 'overdue' => 0];
    try {
        notifyUpcomingRentDue($pdo);
        if (smsConfigured($pdo)) {
            $result = sendRentReminders($pdo);
            if ($result['sent'] > 0) {
                notifyAdmin($pdo, 'reminders_sent', 'Rent reminders sent automatically',
                    "{$result['due']} due soon or today, {$result['overdue']} overdue."
                    . ($result['failed'] + $result['skipped'] > 0 ? ' ' . ($result['failed'] + $result['skipped']) . ' not sent.' : ''),
                    'payments.php', 'reminders_sent:' . date('Y-m-d'));
            }
        }
        $summary = date('Y-m-d H:i:s') . '|' . $result['sent'] . '|' . ($result['failed'] + $result['skipped']);
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('daily_jobs_last', ?) " . sqlUpsert(['setting_key'], ['setting_value']))
            ->execute([$summary]);
    } catch (Throwable $e) {
        error_log('Daily jobs failed: ' . $e->getMessage());
    }
    return $result;
}

/** For Settings: when the automatic reminders last ran, how many went out and how many didn't. */
function lastDailyRun(PDO $pdo): ?array {
    $value = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'daily_jobs_last'")->fetchColumn();
    if (!$value || substr_count($value, '|') !== 2) {
        return null;
    }
    [$at, $sent, $notSent] = explode('|', $value);
    return ['at' => $at, 'sent' => (int)$sent, 'not_sent' => (int)$notSent];
}
