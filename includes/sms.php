<?php
// includes/sms.php

/**
 * PhilSMS API Integration
 */
function sendSMS($phoneNumber, $message) {
    global $pdo;

    // Fast check to ensure $pdo exists if included from contexts without global setup
    if (!$pdo && file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
    }

    // Fetch SMS Settings
    $settings = [];
    if ($pdo) {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('sms_api_key', 'sms_sender_id')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    
    $apiKey = $settings['sms_api_key'] ?? getenv('SMS_API_KEY');
    $senderId = $settings['sms_sender_id'] ?? getenv('SMS_SENDER_ID') ?: 'PhilSMS';

    if (empty($apiKey) || $apiKey === 'your_semaphore_key_here') {
        // Fallback to mock log if no API key is provided
        $logFile = __DIR__ . '/../sms_mock_log.txt';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] TO: $phoneNumber | MESSAGE: $message (No API Key - MOCKED)\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        return true;
    }

    $url = "https://app.philsms.com/api/v3/sms/send";
    
    $data = [
        "recipient" => $phoneNumber,
        "sender_id" => $senderId,
        "type" => "plain",
        "message" => $message
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
            "Accept: application/json"
        ]);

        // Disable SSL verification for strict local windows setups
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        // Fallback for environments without cURL
        $options = [
            'http' => [
                'header'  => "Authorization: Bearer $apiKey\r\n" .
                             "Content-Type: application/json\r\n" .
                             "Accept: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        $context  = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        
        $httpCode = 500;
        $headers = [];
        if (function_exists('http_get_last_response_headers')) {
            $headers = http_get_last_response_headers();
        } else {
            // Use variable-variable to prevent PHP 8.4+ parse-time deprecation warnings
            $varName = 'http_response_header';
            if (isset($$varName)) {
                $headers = $$varName;
            }
        }

        if (!empty($headers) && is_array($headers)) {
            preg_match('{HTTP\/\S*\s(\d{3})}', $headers[0], $match);
            $httpCode = (int)($match[1] ?? 500);
        }
    }

    if ($httpCode === 200 || $httpCode === 201) {
        return true;
    }

    // Log error
    error_log("PhilSMS Error HTTP $httpCode: " . print_r($response, true));
    return false;
}
?>
