<?php
// includes/db.php

// Due dates and billing months follow Philippine time, not the server's UTC clock.
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/sql_compat.php';

// Settings come from environment variables on a deployed server (Railway -> Variables) and
// from config.php when the app is run locally under WAMP/XAMPP. Copy config.sample.php to
// config.php and edit it; config.php is git-ignored so local credentials are never committed.
$configFile = __DIR__ . '/../config.php';
$config = file_exists($configFile) ? require $configFile : [];

function appConfig(string $key, $default = null) {
    global $config;
    $env = getenv(strtoupper($key));
    if ($env !== false && $env !== '') {
        return $env;
    }
    return array_key_exists($key, $config) && $config[$key] !== '' ? $config[$key] : $default;
}

// 'pgsql' (Supabase, the deployed database) or 'mysql' (WAMP/XAMPP, local testing).
$driver = strtolower((string)appConfig('db_driver', 'pgsql'));
if (!in_array($driver, ['pgsql', 'mysql'], true)) {
    die("Unsupported DB_DRIVER '" . htmlspecialchars($driver) . "'. Use 'pgsql' or 'mysql'.");
}
$GLOBALS['db_driver'] = $driver;

$isMysql = $driver === 'mysql';
$host = appConfig('db_host', $isMysql ? '127.0.0.1' : 'aws-0-ap-northeast-2.pooler.supabase.com');
$port = appConfig('db_port', $isMysql ? '3306' : '5432');
$dbname = appConfig('db_name', $isMysql ? 'boardinghouse' : 'postgres');
$username = appConfig('db_user', $isMysql ? 'root' : 'postgres.edswwvalfxehdklaackx');
$password = appConfig('db_password', $isMysql ? '' : null);

// MySQL under WAMP is normally root with an empty password, so only Postgres insists on one.
if (!$isMysql && ($password === null || $password === '')) {
    die("Database connection failed: the DB_PASSWORD environment variable is not set.");
}

try {
    $dsn = $isMysql
        ? "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4"
        : "pgsql:host=$host;port=$port;dbname=$dbname";

    // No persistent connections: the Supabase pooler closes idle connections,
    // and PHP would otherwise reuse a dead one ("server closed the connection unexpectedly").
    $pdo = new PDO($dsn, $username, (string)$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch(PDOException $e) {
    $where = $isMysql
        ? "Start MySQL in WAMP and check config.php (see LOCAL_SETUP.md)."
        : "Check the DB_* environment variables.";
    die("Database connection failed. $where Error: " . $e->getMessage());
}

require_once __DIR__ . '/migrations.php';
runMigrations($pdo);

// Post any rent charges that have come due, before any page reads balances.
runBilling($pdo);
