<?php
// includes/sms.php
//
// Text messages through PhilSMS (https://app.philsms.com), API v3:
//   POST https://app.philsms.com/api/v3/sms/send   {recipient, sender_id, type, message}
//   GET  https://app.philsms.com/api/v3/balance
// Both take "Authorization: Bearer <API token>" and answer with JSON whose "status" is
// "success" or "error" (with a "message" saying why).
//
// The token and sender ID are entered by the admin under Settings > SMS. Until a token is
// saved nothing is sent: messages are written to the SMS log as "skipped", so the rest of the
// system works the same with or without SMS.
//
// Every message, sent or not, is recorded in the sms_log table, which the settings page shows.

const PHILSMS_API_BASE = 'https://app.philsms.com/api/v3';   // PHILSMS_API_BASE env var overrides it (testing)
const PHILSMS_DEFAULT_SENDER = 'PhilSMS';
const SMS_BULK_CHUNK = 100;   // recipients per request when one message goes to many numbers

/** Token and sender ID: the admin's settings, else PHILSMS_API_TOKEN / PHILSMS_SENDER_ID env vars. */
function smsConfig(PDO $pdo): array {
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings
                         WHERE setting_key IN ('sms_api_key', 'sms_sender_id', 'boarding_house_name')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

    $token = trim((string)($rows['sms_api_key'] ?? ''));
    if ($token === '') {
        $token = trim((string)(getenv('PHILSMS_API_TOKEN') ?: ''));
    }
    $sender = trim((string)($rows['sms_sender_id'] ?? ''));
    if ($sender === '') {
        $sender = trim((string)(getenv('PHILSMS_SENDER_ID') ?: '')) ?: PHILSMS_DEFAULT_SENDER;
    }
    return [
        'token'  => $token,
        'sender' => $sender,
        'name'   => trim((string)($rows['boarding_house_name'] ?? '')) ?: 'Riveros Boarding House',
    ];
}

function smsConfigured(PDO $pdo): bool {
    return smsConfig($pdo)['token'] !== '';
}

/**
 * A Philippine mobile number in the form PhilSMS wants (639XXXXXXXXX), or null if it isn't one.
 * Accepts the ways people write them: 0917 123 4567, +63 917-123-4567, 639171234567, 9171234567.
 */
function normalizePhMobile(?string $number): ?string {
    $digits = preg_replace('/\D+/', '', (string)$number);
    if (strlen($digits) === 11 && str_starts_with($digits, '09')) {
        $digits = '63' . substr($digits, 1);
    } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        $digits = '63' . $digits;
    }
    return preg_match('/^639\d{9}$/', $digits) ? $digits : null;
}

/**
 * A contact number entered in a form: blank is allowed, anything else has to be a mobile number
 * that can receive texts. Stored the way it was typed (trimmed); normalised only when sending.
 */
function validatedMobileInput($value): string {
    $value = trim(is_scalar($value) ? (string)$value : '');
    if ($value !== '' && normalizePhMobile($value) === null) {
        throw new Exception('The contact number must be a Philippine mobile number, like 0917 123 4567, so SMS notifications can reach it.');
    }
    return $value;
}

/** 639171234567 -> 0917 123 4567, for showing numbers back to people. */
function formatPhMobile(string $normalized): string {
    $local = '0' . substr($normalized, 2);
    return substr($local, 0, 4) . ' ' . substr($local, 4, 3) . ' ' . substr($local, 7);
}

/**
 * Where to find trusted root certificates for HTTPS, or null to use PHP's own setting.
 * PHP on Windows (WAMP) ships without any, so every HTTPS call fails with "unable to get local
 * issuer certificate". Rather than switch verification off - which would let anyone on the
 * network read the API token - the app carries the standard Mozilla root list.
 */
function smsCaBundle(): ?string {
    if (ini_get('curl.cainfo') || ini_get('openssl.cafile')) {
        return null;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return null;   // Linux servers (Railway) have the system certificate store
    }
    $bundle = __DIR__ . '/certs/cacert.pem';
    return is_file($bundle) ? $bundle : null;
}

/**
 * One call to the PhilSMS API.
 * @return array ok (bool), error (string), data (decoded JSON or null), http (int)
 */
function philsmsRequest(string $method, string $path, string $token, ?array $body = null): array {
    $url = rtrim(getenv('PHILSMS_API_BASE') ?: PHILSMS_API_BASE, '/') . $path;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }
    $caBundle = smsCaBundle();
    $response = false;
    $http = 0;
    $transportError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        if ($caBundle) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
        $response = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $transportError = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($caBundle) {
            $ssl['cafile'] = $caBundle;
        }
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $payload ?? '',
                'timeout'       => 15,
                'ignore_errors' => true,   // still read the body of 4xx answers: it says what's wrong
            ],
            'ssl' => $ssl,
        ]);
        $response = @file_get_contents($url, false, $context);
        if (function_exists('http_get_last_response_headers')) {
            $responseHeaders = http_get_last_response_headers() ?? [];
        } else {
            // Named indirectly: newer PHP warns about the magic variable wherever it is written.
            $magic = 'http_response_header';
            $responseHeaders = $$magic ?? [];
        }
        if (!empty($responseHeaders[0]) && preg_match('{HTTP/\S+\s(\d{3})}', $responseHeaders[0], $m)) {
            $http = (int)$m[1];
        }
        if ($response === false) {
            $transportError = error_get_last()['message'] ?? 'could not connect';
        }
    }

    if ($response === false) {
        $hint = stripos($transportError, 'certificate') !== false
            ? ' (HTTPS certificate check failed; see SMS_SETUP.md, Troubleshooting)'
            : '';
        return ['ok' => false, 'error' => 'Could not reach PhilSMS: ' . $transportError . $hint, 'data' => null, 'http' => 0];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => "PhilSMS answered HTTP $http with something that isn't JSON.", 'data' => null, 'http' => $http];
    }
    $ok = $http >= 200 && $http < 300 && strtolower((string)($data['status'] ?? '')) === 'success';
    if ($ok) {
        return ['ok' => true, 'error' => '', 'data' => $data, 'http' => $http];
    }
    $reason = is_string($data['message'] ?? null) ? $data['message'] : "HTTP $http";
    if ($http === 401) {
        $reason = 'The API token was not accepted. Copy it again from PhilSMS (Developers > API Token).';
    }
    return ['ok' => false, 'error' => 'PhilSMS: ' . $reason, 'data' => $data, 'http' => $http];
}

/** Write one row of the SMS log. */
function logSms(PDO $pdo, ?int $tenantId, string $recipient, string $message, string $purpose, string $status, string $error = '', ?string $ref = null): void {
    try {
        $pdo->prepare("INSERT INTO sms_log (tenant_id, recipient, message, purpose, status, error, ref)
                       VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$tenantId, substr($recipient, 0, 30), $message, $purpose, $status, substr($error, 0, 255), $ref]);
    } catch (PDOException $e) {
        error_log('Could not write the SMS log: ' . $e->getMessage());   // never let logging stop a payment
    }
}

/**
 * Send one message to many people.
 *
 * @param array  $recipients tenant id (or any key) => phone number as typed
 * @param string $purpose    what it was for, shown in the log: payment_verified, reminder_due, ...
 * @param ?string $ref       a key that marks this message as sent, so reminders aren't repeated
 * @return array sent, failed, skipped, unreachable (no usable number; counted in skipped too),
 *               errors (why anything wasn't sent, one entry per distinct reason)
 */
function sendBulkSMS(PDO $pdo, array $recipients, string $message, string $purpose = 'general', ?string $ref = null): array {
    $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0, 'errors' => []];
    $config = smsConfig($pdo);

    // Numbers that can't receive a text are logged and left out of the request.
    $valid = [];
    $unreachable = 0;
    foreach ($recipients as $key => $number) {
        $tenantId = is_int($key) && $key > 0 ? $key : null;
        $normalized = normalizePhMobile($number);
        if ($normalized === null) {
            $why = trim((string)$number) === '' ? 'No mobile number on file' : 'Not a valid PH mobile number';
            logSms($pdo, $tenantId, (string)$number, $message, $purpose, 'skipped', $why, $ref);
            $result['skipped']++;
            $unreachable++;
            continue;
        }
        $valid[] = [$tenantId, $normalized];
    }
    $result['unreachable'] = $unreachable;

    if ($config['token'] === '') {
        foreach ($valid as [$tenantId, $number]) {
            logSms($pdo, $tenantId, $number, $message, $purpose, 'skipped', 'SMS is not set up yet (no PhilSMS API token)', $ref);
            $result['skipped']++;
        }
        if ($valid) {
            $result['errors']['setup'] = 'SMS is not set up yet. Add your PhilSMS API token in Settings.';
        }
        return $result;
    }

    // PhilSMS takes several numbers separated by commas, so one request covers a whole group.
    foreach (array_chunk($valid, SMS_BULK_CHUNK) as $chunk) {
        $response = philsmsRequest('POST', '/sms/send', $config['token'], [
            'recipient' => implode(',', array_unique(array_column($chunk, 1))),
            'sender_id' => $config['sender'],
            'type'      => 'plain',
            'message'   => $message,
        ]);
        foreach ($chunk as [$tenantId, $number]) {
            logSms($pdo, $tenantId, $number, $message, $purpose, $response['ok'] ? 'sent' : 'failed', $response['error'], $ref);
            $result[$response['ok'] ? 'sent' : 'failed']++;
        }
        if (!$response['ok']) {
            $result['errors'][$response['error']] = $response['error'];
            error_log('SMS failed: ' . $response['error']);
        }
    }
    return $result;
}

/** Send one message to one number. Same result as sendBulkSMS(). */
function sendSMS(PDO $pdo, ?string $phoneNumber, string $message, string $purpose = 'general', ?int $tenantId = null, ?string $ref = null): array {
    $key = $tenantId ?: 0;
    return sendBulkSMS($pdo, [$key => (string)$phoneNumber], $message, $purpose, $ref);
}

/** Add up several send results. */
function mergeSmsResults(array ...$results): array {
    $total = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'unreachable' => 0, 'errors' => []];
    foreach ($results as $r) {
        foreach (['sent', 'failed', 'skipped', 'unreachable'] as $k) {
            $total[$k] += $r[$k] ?? 0;
        }
        $total['errors'] += $r['errors'];
    }
    return $total;
}

/** Why some texts in a result weren't sent, as sentences. */
function smsErrorText(array $r): string {
    $reasons = array_values($r['errors']);
    $n = $r['unreachable'] ?? 0;
    if ($n > 0) {
        $reasons[] = $n === 1 ? 'One tenant has no valid mobile number on file.' : "$n tenants have no valid mobile number on file.";
    }
    return implode(' ', $reasons);
}

/** "SMS sent." / "SMS not sent: <why>" - for the admin's confirmation messages. */
function smsOutcomeText(array $r): string {
    $parts = [];
    if ($r['sent'] > 0) {
        $parts[] = $r['sent'] === 1 ? 'SMS sent.' : "{$r['sent']} SMS sent.";
    }
    $notSent = $r['failed'] + $r['skipped'];
    if ($notSent > 0) {
        $parts[] = ($notSent === 1 ? 'SMS not sent' : "$notSent SMS not sent") . ': ' . smsErrorText($r);
    }
    return implode(' ', $parts);
}

/**
 * Credits left on the PhilSMS account.
 * @return array ok, error, balance (string, e.g. "1,250"), expires (string|null)
 */
function philsmsBalance(PDO $pdo): array {
    $config = smsConfig($pdo);
    if ($config['token'] === '') {
        return ['ok' => false, 'error' => 'Add your PhilSMS API token first.', 'balance' => null, 'expires' => null];
    }
    $response = philsmsRequest('GET', '/balance', $config['token']);
    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error'], 'balance' => null, 'expires' => null];
    }
    $data = $response['data']['data'] ?? [];
    $balance = null;
    if (is_array($data)) {
        foreach (['remaining_balance', 'remaining_unit', 'remaining_units', 'balance', 'sms_unit', 'units'] as $k) {
            if (isset($data[$k]) && is_scalar($data[$k])) {
                $balance = (string)$data[$k];
                break;
            }
        }
    } elseif (is_scalar($data)) {
        $balance = (string)$data;
    }
    return [
        'ok'      => true,
        'error'   => '',
        'balance' => $balance ?? 'unknown',
        'expires' => is_array($data) && isset($data['expired_on']) && is_scalar($data['expired_on']) ? (string)$data['expired_on'] : null,
    ];
}

/** Shorten a message that would cost several SMS parts (each part holds about 153 characters). */
function smsTrim(string $text, int $max = 300): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    if ($len <= $max) {
        return $text;
    }
    $cut = function_exists('mb_substr') ? mb_substr($text, 0, $max - 3) : substr($text, 0, $max - 3);
    $space = strrpos($cut, ' ');
    if ($space !== false && $space > strlen($cut) * 0.7) {
        $cut = substr($cut, 0, $space);   // end on a whole word when one is near
    }
    return rtrim($cut, " ,.-") . '...';   // plain dots: one fancy character switches the whole SMS to the pricier Unicode encoding
}
