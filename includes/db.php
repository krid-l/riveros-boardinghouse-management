<?php
// includes/db.php

// Due dates and billing months follow Philippine time, not the server's UTC clock.
date_default_timezone_set('Asia/Manila');

// Credentials come from environment variables (set them in Railway → Variables).
$host = getenv('DB_HOST') ?: 'aws-0-ap-northeast-2.pooler.supabase.com';
$port = getenv('DB_PORT') ?: '5432';
$dbname = getenv('DB_NAME') ?: 'postgres';
$username = getenv('DB_USER') ?: 'postgres.edswwvalfxehdklaackx';
$password = getenv('DB_PASSWORD');

if ($password === false || $password === '') {
    die("Database connection failed: the DB_PASSWORD environment variable is not set.");
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

    // No persistent connections: the Supabase pooler closes idle connections,
    // and PHP would otherwise reuse a dead one ("server closed the connection unexpectedly").
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch(PDOException $e) {
    die("Database connection failed. Check the DB_* environment variables. Error: " . $e->getMessage());
}

require_once __DIR__ . '/migrations.php';
runMigrations($pdo);

// Post any rent charges that have come due, before any page reads balances.
runBilling($pdo);
?>
