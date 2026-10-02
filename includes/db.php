<?php
// includes/db.php

// Due dates and billing months follow Philippine time, not the server's UTC clock.
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/sql_compat.php';

// Where the connection details come from, in order of precedence:
//
//   1. DATABASE_URL / DATABASE_PUBLIC_URL - what Railway's Postgres service provides.
//   2. Individual DB_* environment variables, which override any part of that URL.
//   3. config.php, for running locally under WAMP/XAMPP. Copy config.sample.php to
//      config.php and edit it.
//   4. PG* variables (Railway/psql defaults), used only to fill what is still missing.
//
// So a deployed server needs nothing but its environment, and a local machine needs
// nothing but config.php.
$configFile = __DIR__ . '/../config.php';
$config = file_exists($configFile) ? require $configFile : [];

/** A setting from the environment first, then config.php, then the given default. */
function appConfig(string $key, $default = null) {
    global $config;
    $env = getenv(strtoupper($key));
    if ($env !== false && $env !== '') {
        return $env;
    }
    return array_key_exists($key, $config) && $config[$key] !== '' ? $config[$key] : $default;
}

/** An environment variable, or null when it is unset or empty. */
function envValue(string $name): ?string {
    $value = getenv($name);
    return ($value === false || $value === '') ? null : $value;
}

$conn = ['host' => null, 'port' => null, 'dbname' => null, 'user' => null, 'pass' => null];

// 1. Railway hands the whole connection over as one URL.
$databaseUrl = envValue('DATABASE_URL') ?: envValue('DATABASE_PUBLIC_URL');
if ($databaseUrl) {
    $parts = parse_url($databaseUrl);
    if ($parts === false || empty($parts['host'])) {
        die("Database connection failed: DATABASE_URL is not a valid connection string.");
    }
    $conn['host'] = $parts['host'];
    $conn['port'] = isset($parts['port']) ? (string)$parts['port'] : null;
    $conn['user'] = isset($parts['user']) ? urldecode($parts['user']) : null;
    $conn['pass'] = isset($parts['pass']) ? urldecode($parts['pass']) : null;
    $dbPath = trim($parts['path'] ?? '', '/');
    if ($dbPath !== '') $conn['dbname'] = $dbPath;
}

// A DATABASE_URL is always a Postgres URL, so it settles the driver. Otherwise the driver is
// whatever the environment or config.php asks for, defaulting to Postgres as deployed.
$driver = $databaseUrl ? 'pgsql' : strtolower((string)appConfig('db_driver', 'pgsql'));
if (!in_array($driver, ['pgsql', 'mysql'], true)) {
    die("Unsupported DB_DRIVER '" . htmlspecialchars($driver) . "'. Use 'pgsql' or 'mysql'.");
}
$GLOBALS['db_driver'] = $driver;
$isMysql = $driver === 'mysql';

$settings = ['host' => ['DB_HOST', 'db_host'], 'port' => ['DB_PORT', 'db_port'],
             'dbname' => ['DB_NAME', 'db_name'], 'user' => ['DB_USER', 'db_user'],
             'pass' => ['DB_PASSWORD', 'db_password']];

// 2. config.php fills in a local machine's details. A deployed server that has a DATABASE_URL
//    ignores it entirely, so a config.php left lying around can't redirect it elsewhere.
if (!$databaseUrl) {
    foreach ($settings as $key => [$envVar, $configKey]) {
        if (array_key_exists($configKey, $config) && $config[$configKey] !== '') {
            $conn[$key] = $config[$configKey];
        }
    }
}

// 3. An explicit DB_* always wins, over the URL and over config.php alike.
foreach ($settings as $key => [$envVar, $configKey]) {
    $value = envValue($envVar);
    if ($value !== null) $conn[$key] = $value;
}

// 4. PG* (Railway/psql defaults) only fills what is still missing.
foreach (['host' => 'PGHOST', 'port' => 'PGPORT', 'dbname' => 'PGDATABASE',
          'user' => 'PGUSER', 'pass' => 'PGPASSWORD'] as $key => $var) {
    if ($conn[$key] === null || $conn[$key] === '') {
        $conn[$key] = envValue($var) ?? $conn[$key];
    }
}

// 5. Whatever is still unset takes the driver's usual default.
$defaults = [
    'host'   => $isMysql ? '127.0.0.1' : null,
    'port'   => $isMysql ? '3306' : '5432',
    'dbname' => $isMysql ? 'boardinghouse' : 'postgres',
    'user'   => $isMysql ? 'root' : 'postgres',
    'pass'   => $isMysql ? '' : null,
];
foreach ($defaults as $key => $fallback) {
    if ($conn[$key] === null) $conn[$key] = $fallback;
}

$host = $conn['host'];
$port = $conn['port'];
$dbname = $conn['dbname'];
$username = $conn['user'];
$password = $conn['pass'];

if (!$host) {
    die("Database connection failed: set DATABASE_URL (or DB_HOST) in your environment variables.");
}
// WAMP's MySQL is normally root with no password, so only Postgres insists on one.
if (!$isMysql && ($password === null || $password === '')) {
    die("Database connection failed: no database password found in DATABASE_URL or DB_PASSWORD.");
}

try {
    $dsn = $isMysql
        ? "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4"
        : "pgsql:host=$host;port=$port;dbname=$dbname";

    // No persistent connections: a pooled server closes idle connections, and PHP would
    // otherwise reuse a dead one ("server closed the connection unexpectedly").
    $pdo = new PDO($dsn, $username, (string)$password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch(PDOException $e) {
    $where = $isMysql
        ? "Start MySQL in WAMP and check config.php (see LOCAL_SETUP.md)."
        : "Check DATABASE_URL / DB_* variables.";
    die("Database connection failed for $username@$host:$port/$dbname. $where Error: " . $e->getMessage());
}

require_once __DIR__ . '/migrations.php';
runMigrations($pdo);

// Post any rent charges that have come due, before any page reads balances.
runBilling($pdo);
