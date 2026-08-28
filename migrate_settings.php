<?php
require_once 'includes/db.php';

try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT
    );
    ");

    $defaults = [
        'boarding_house_name' => 'RIVEROS BOARDING HOUSE',
        'address' => "123 National Highway, Brgy. Poblacion,\nCity of Naga, Cebu 6000",
        'contact_number' => '032 123 4567',
        'gcash_name' => 'Boarding House',
        'gcash_number' => '0917 123 4567',
        'gcash_instructions' => 'Pay via GCash and send the reference number and screenshot through the payment submission form. Thank you!',
        'sms_provider' => 'Local SMS Gateway',
        'sms_api_key' => '1234567890',
        'sms_sender_id' => 'BOARDINGHOUSE',
        'rent_due_date' => '1st',
        'currency' => 'Philippine Peso (PHP)',
        'date_format' => 'Aug 31, 2025 (MMM DD, YYYY)',
        'time_zone' => '(UTC+08:00) Asia/Manila'
    ];

    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT (setting_key) DO NOTHING");
    foreach ($defaults as $key => $val) {
        $stmt->execute([$key, $val]);
    }
    
    echo "Settings table created and seeded successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
