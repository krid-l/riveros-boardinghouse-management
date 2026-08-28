<?php
// includes/db.php

// Railway + Supabase Connection Setup
// Railway securely passes credentials via Environment Variables, typically as a DATABASE_URL.

$dbUrl = getenv('DATABASE_URL');

if ($dbUrl) {
    // Parse the URL provided by Railway/Supabase
    // Format: postgresql://username:password@host:port/dbname
    $parsedUrl = parse_url($dbUrl);
    
    $host = $parsedUrl['host'];
    $port = isset($parsedUrl['port']) ? $parsedUrl['port'] : '5432';
    $dbname = ltrim($parsedUrl['path'], '/');
    $username = $parsedUrl['user'];
    $password = $parsedUrl['pass'];
} else {
    // Fallback for local development if Environment Variables are not set
    $host = 'aws-0-ap-northeast-2.pooler.supabase.com';
    $port = '5432'; 
    $dbname = 'postgres';
    $username = 'postgres.edswwvalfxehdklaackx';
    $password = 'DirkLabiaga@20';
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true
    ]);
} catch(PDOException $e) {
    die("Database connection failed. Ensure your DATABASE_URL is set correctly. Error: " . $e->getMessage());
}
?>
