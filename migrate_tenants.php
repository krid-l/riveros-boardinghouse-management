<?php
require_once 'includes/db.php';

try {
    // Add missing columns to tenants table
    $pdo->exec("
        ALTER TABLE tenants 
        ADD COLUMN IF NOT EXISTS occupation VARCHAR(100),
        ADD COLUMN IF NOT EXISTS emergency_contact VARCHAR(100),
        ADD COLUMN IF NOT EXISTS address TEXT,
        ADD COLUMN IF NOT EXISTS date_of_birth DATE
    ");
    echo "Tenants table updated successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
