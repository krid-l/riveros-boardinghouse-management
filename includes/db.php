<?php
// includes/db.php

// Due dates and billing months follow Philippine time, not the server's UTC clock.
date_default_timezone_set('Asia/Manila');

// Connection details come from environment variables (Railway → Variables).
// DATABASE_URL is what Railway's Postgres service provides; individual DB_* variables
// override parts of it, and PG* (Railway/psql defaults) are used as a last resort.
$conn = [
    'host' => null,
    'port' => '5432',
    'dbname' => 'postgres',
    'user' => 'postgres',
    'pass' => null,
];

$databaseUrl = getenv('DATABASE_URL') ?: getenv('DATABASE_PUBLIC_URL');
if ($databaseUrl) {
    $parts = parse_url($databaseUrl);
    if ($parts === false || empty($parts['host'])) {
        die("Database connection failed: DATABASE_URL is not a valid connection string.");
    }
    $conn['host'] = $parts['host'];
    $conn['port'] = (string)($parts['port'] ?? $conn['port']);
    $conn['user'] = isset($parts['user']) ? urldecode($parts['user']) : $conn['user'];
    $conn['pass'] = isset($parts['pass']) ? urldecode($parts['pass']) : null;
    $dbPath = trim($parts['path'] ?? '', '/');
    if ($dbPath !== '') $conn['dbname'] = $dbPath;
}

$overrides = [
    'host' => ['DB_HOST', 'PGHOST'],
    'port' => ['DB_PORT', 'PGPORT'],
    'dbname' => ['DB_NAME', 'PGDATABASE'],
    'user' => ['DB_USER', 'PGUSER'],
    'pass' => ['DB_PASSWORD', 'PGPASSWORD'],
];
foreach ($overrides as $key => $vars) {
    foreach ($vars as $var) {
        $value = getenv($var);
        if ($value !== false && $value !== '') {
            // An explicit DB_* wins over DATABASE_URL; PG* only fills what's still missing.
            if (str_starts_with($var, 'DB_') || $conn[$key] === null || !$databaseUrl) {
                $conn[$key] = $value;
            }
            break;
        }
    }
}

$host = $conn['host'];
$port = $conn['port'];
$dbname = $conn['dbname'];
$username = $conn['user'];
$password = $conn['pass'];

if (!$host) {
    die("Database connection failed: set DATABASE_URL (or DB_HOST) in your environment variables.");
}
if ($password === null || $password === '') {
    die("Database connection failed: no database password found in DATABASE_URL or DB_PASSWORD.");
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

    // No persistent connections: a pooled server closes idle connections, and PHP would
    // otherwise reuse a dead one ("server closed the connection unexpectedly").
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch(PDOException $e) {
    die("Database connection failed for $username@$host:$port/$dbname. Check DATABASE_URL / DB_* variables. Error: " . $e->getMessage());
}

require_once __DIR__ . '/migrations.php';
runMigrations($pdo);

// Post any rent charges that have come due, before any page reads balances.
runBilling($pdo);
?>
