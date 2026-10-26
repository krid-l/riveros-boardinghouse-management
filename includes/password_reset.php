<?php
// includes/password_reset.php
//
// "Forgot password?": a 6-digit code texted to the account's mobile number, then a new
// password. Tenants get it on their contact number; an admin on the recovery mobile number set
// under Settings -> Administrator Profile.
//
// Only a hash of the code is stored. A code lasts PASSWORD_RESET_MINUTES, allows
// PASSWORD_RESET_TRIES wrong guesses, and an account can ask for at most
// PASSWORD_RESET_PER_HOUR codes an hour, so it can't be guessed or used to flood a phone.
//
// When an account has no usable number (or SMS isn't set up) no code is sent; the admin can
// still reset a tenant's password from the tenant's details page.

require_once __DIR__ . '/sms.php';

const PASSWORD_RESET_MINUTES = 10;
const PASSWORD_RESET_TRIES = 5;
const PASSWORD_RESET_PER_HOUR = 3;
const PASSWORD_MIN_LENGTH = 6;

/** The account with this username, and the mobile number a reset code would go to. */
function passwordResetAccount(PDO $pdo, string $username): ?array {
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.role, u.recovery_mobile, t.first_name, t.contact_number, t.status AS tenant_status
                           FROM users u LEFT JOIN tenants t ON t.user_id = u.id
                           WHERE u.username = ?");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    if ($user['role'] === 'tenant' && ($user['tenant_status'] ?? 'active') !== 'active') {
        return $user + ['mobile' => null];   // a moved-out tenant can't log in anyway
    }
    $number = $user['role'] === 'admin' ? $user['recovery_mobile'] : $user['contact_number'];
    return $user + ['mobile' => normalizePhMobile($number)];
}

/**
 * Text a reset code, if the account exists and has a number to send it to.
 * The caller shows the same message either way, so the page doesn't reveal which usernames exist.
 *
 * @return string 'sent', 'no_account', 'no_mobile', 'no_sms', 'too_many' or 'failed'
 */
function requestPasswordReset(PDO $pdo, string $username): string {
    $account = passwordResetAccount($pdo, $username);
    if (!$account) {
        return 'no_account';
    }
    if (!$account['mobile']) {
        return 'no_mobile';
    }
    if (!smsConfigured($pdo)) {
        return 'no_sms';
    }

    $hourAgo = date('Y-m-d H:i:s', time() - 3600);
    $recent = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at >= ?");
    $recent->execute([$account['id'], $hourAgo]);
    if ((int)$recent->fetchColumn() >= PASSWORD_RESET_PER_HOUR) {
        return 'too_many';
    }

    // A new code replaces any earlier one still waiting.
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL")->execute([$now, $account['id']]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare("INSERT INTO password_resets (user_id, code_hash, attempts, expires_at, created_at) VALUES (?, ?, 0, ?, ?)")
        ->execute([$account['id'], password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + PASSWORD_RESET_MINUTES * 60), $now]);

    $name = smsConfig($pdo)['name'];
    $result = sendSMS($pdo, $account['mobile'],
        "$name: Your password reset code is $code. It expires in " . PASSWORD_RESET_MINUTES . " minutes. If you did not ask for this, you can ignore this message.",
        'password_reset', null);
    return ($result['sent'] ?? 0) > 0 ? 'sent' : 'failed';
}

/**
 * Check a reset code and set the new password.
 * @return string '' on success, otherwise what went wrong (for the page)
 */
function completePasswordReset(PDO $pdo, string $username, string $code, string $password, string $confirm): string {
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'The new password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if ($password !== $confirm) {
        return 'The two passwords do not match.';
    }
    $account = passwordResetAccount($pdo, $username);
    if (!$account) {
        return 'That code is not valid. Ask for a new one.';
    }

    $stmt = $pdo->prepare("SELECT id, code_hash, attempts, expires_at FROM password_resets
                           WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$account['id']]);
    $reset = $stmt->fetch();
    if (!$reset || $reset['expires_at'] < date('Y-m-d H:i:s') || (int)$reset['attempts'] >= PASSWORD_RESET_TRIES) {
        return 'That code has expired or was used up. Ask for a new one.';
    }
    if (!password_verify(preg_replace('/\D/', '', $code), $reset['code_hash'])) {
        $pdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?")->execute([$reset['id']]);
        $left = PASSWORD_RESET_TRIES - (int)$reset['attempts'] - 1;
        return $left > 0
            ? "That code is not right. You have $left more " . ($left === 1 ? 'try' : 'tries') . '.'
            : 'That code is not right, and it can no longer be used. Ask for a new one.';
    }

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE users SET password_hash = ?, temp_password = NULL WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
    $pdo->prepare("UPDATE password_resets SET used_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $reset['id']]);
    $pdo->commit();
    return '';
}

/** A fresh temporary password, as tenants get when they are added. */
function generateTempPassword(): string {
    // No look-alike characters (0/O, 1/l/I), so it can be read out or typed from an SMS.
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}
