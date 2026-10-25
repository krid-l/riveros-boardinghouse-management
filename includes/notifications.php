<?php
// includes/notifications.php
//
// The admin's notification bell: things that happened in the system that the admin should
// know about - a new tenant, a payment to verify, a complaint, a room filling up or freeing a
// bed, rent coming due. Each notice links to the page where it can be dealt with.
//
// A notice can carry a ref, a key that stops the same thing being announced twice (e.g. one
// "rent due" notice per day). Times are written by PHP, in Manila time.

const NOTIFICATION_TYPES = [
    // type               => [icon,                    colour]
    'tenant_added'        => ['fa-user-plus',          'primary'],
    'payment_submitted'   => ['fa-money-bill-wave',    'warning'],
    'payment_verified'    => ['fa-circle-check',       'success'],
    'payment_rejected'    => ['fa-circle-xmark',       'danger'],
    'complaint'           => ['fa-comment-dots',       'danger'],
    'room_available'      => ['fa-door-open',          'success'],
    'room_full'           => ['fa-door-closed',        'secondary'],
    'room_maintenance'    => ['fa-screwdriver-wrench', 'secondary'],
    'rent_due'            => ['fa-calendar-day',       'warning'],
    'deposit_request'     => ['fa-vault',              'info'],
    'reminders_sent'      => ['fa-comment-sms',        'info'],
];

/**
 * Add a notice for the admin.
 *
 * @param string  $link   page to open, relative to admin/ (e.g. "payments.php?status=pending")
 * @param ?string $ref    a key: a notice with the same ref is never added twice
 * @param bool    $read   true for things the admin did themselves (kept in the list, not counted)
 */
function notifyAdmin(PDO $pdo, string $type, string $title, string $message = '', string $link = '', ?string $ref = null, bool $read = false): void {
    try {
        if ($ref !== null) {
            $seen = $pdo->prepare("SELECT 1 FROM notifications WHERE ref = ?");
            $seen->execute([$ref]);
            if ($seen->fetchColumn()) {
                return;
            }
        }
        $pdo->prepare("INSERT INTO notifications (type, title, message, link, ref, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$type, mb_substr($title, 0, 150), mb_substr($message, 0, 255), mb_substr($link, 0, 255), $ref, dbBool($read), date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        error_log('Could not add a notification: ' . $e->getMessage());   // never let this stop the real work
    }
}

/** Mark the notices with this ref read, e.g. "a payment to verify" once it has been verified. */
function markNotificationsRead(PDO $pdo, string $ref): void {
    $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE ref = ?")->execute([$ref]);
}

function unreadNotificationCount(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = FALSE")->fetchColumn();
}

/** The latest notices, newest first. */
function recentNotifications(PDO $pdo, int $limit = 8): array {
    return $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit))->fetchAll();
}

function notificationIcon(string $type): array {
    return NOTIFICATION_TYPES[$type] ?? ['fa-bell', 'secondary'];
}

/** "just now", "5m ago", "3h ago", "yesterday", "Oct 2" */
function timeAgo(?string $when): string {
    if (!$when) return '';
    $secs = time() - strtotime($when);
    if ($secs < 60) return 'just now';
    if ($secs < 3600) return floor($secs / 60) . 'm ago';
    if ($secs < 86400) return floor($secs / 3600) . 'h ago';
    if ($secs < 172800) return 'yesterday';
    return date(date('Y', strtotime($when)) === date('Y') ? 'M j' : 'M j, Y', strtotime($when));
}

/** Only links inside the admin pages may be followed from a notice (no off-site redirects). */
function safeNotificationLink(?string $link): string {
    $link = trim((string)$link);
    return preg_match('#^[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?(\#[A-Za-z0-9_\-]*)?$#', $link) ? $link : 'notifications.php';
}

/**
 * Room availability: called after something changed who lives in a room. Compares the
 * occupancy before and after and says so when a room has just filled up or freed a bed.
 *
 * @param array $before room id => active tenants before the change
 */
function notifyRoomAvailabilityChanges(PDO $pdo, array $before): void {
    foreach ($before as $roomId => $was) {
        if (!$roomId) continue;
        $stmt = $pdo->prepare("SELECT room_number, capacity, status, (SELECT COUNT(*) FROM tenants WHERE room_id = rooms.id AND status = 'active') AS occ FROM rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $r = $stmt->fetch();
        if (!$r || $r['status'] === 'maintenance') continue;
        $now = (int)$r['occ'];
        $cap = max(1, (int)$r['capacity']);
        $stamp = date('YmdHis');
        if ($was < $cap && $now >= $cap) {
            notifyAdmin($pdo, 'room_full', "Room {$r['room_number']} is now full", "$now of $cap " . ($cap === 1 ? 'bed' : 'beds') . " taken.", "rooms.php?room=$roomId", "room_full:$roomId:$stamp");
        } elseif ($was >= $cap && $now < $cap) {
            $free = $cap - $now;
            notifyAdmin($pdo, 'room_available', "Room {$r['room_number']} has " . ($free === 1 ? 'a free bed' : "$free free beds"),
                "$now of $cap " . ($cap === 1 ? 'bed' : 'beds') . " taken.", "rooms.php?room=$roomId", "room_available:$roomId:$stamp");
        }
    }
}

/** Active tenants per room right now, for notifyRoomAvailabilityChanges(). */
function roomOccupancySnapshot(PDO $pdo, array $roomIds): array {
    $out = [];
    foreach (array_unique(array_filter(array_map('intval', $roomIds))) as $roomId) {
        $out[$roomId] = roomOccupantCount($pdo, $roomId);
    }
    return $out;
}

/**
 * Once a day: one notice listing the tenants whose rent falls due within the next few days
 * (the same window as the SMS reminders). Cheap after the first call of the day.
 */
function notifyUpcomingRentDue(PDO $pdo): void {
    $ref = 'rent_due:' . date('Y-m-d');
    $seen = $pdo->prepare("SELECT 1 FROM notifications WHERE ref = ?");
    $seen->execute([$ref]);
    if ($seen->fetchColumn()) {
        return;
    }
    require_once __DIR__ . '/sms_reminders.php';   // REMINDER_DAYS_BEFORE
    $limit = date('Y-m-d', strtotime('+' . REMINDER_DAYS_BEFORE . ' days'));
    $notYetDue = chargesNotYetDue($pdo);
    $names = [];
    $total = 0.0;
    $tenants = $pdo->query("SELECT id, first_name, last_name, balance, status FROM tenants
                            WHERE status = 'active' AND balance > 0 ORDER BY first_name, last_name")->fetchAll();
    foreach ($tenants as $t) {
        if (tenantBillingStatus($t, $notYetDue)['key'] !== 'due') continue;
        $due = upcomingDueDate($pdo, (int)$t['id']);
        if (!$due || $due > $limit) continue;
        $names[] = trim($t['first_name'] . ' ' . $t['last_name']) . ' (' . dueDateLabel($due) . ')';
        $total += (float)$t['balance'];
    }
    if (!$names) {
        return;
    }
    $count = count($names);
    notifyAdmin($pdo, 'rent_due',
        $count === 1 ? '1 tenant has rent due soon' : "$count tenants have rent due soon",
        'PHP ' . number_format($total, 2) . ' due: ' . implode(', ', $names),
        'tenants.php?status=due', $ref);
}
