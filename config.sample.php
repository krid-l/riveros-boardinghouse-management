<?php
// config.sample.php
//
// Local configuration for running the system on your own machine (WAMP / XAMPP).
// Copy this file to config.php in the same folder and edit the values below.
//
//   copy config.sample.php config.php      (Windows)
//   cp   config.sample.php config.php      (macOS / Linux)
//
// config.php is git-ignored, so your local credentials are never committed.
// On the deployed server this file does not exist and the values come from
// environment variables (DB_DRIVER, DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD,
// SUPABASE_URL, SUPABASE_SERVICE_KEY) instead. An environment variable always wins
// over the value set here.

return [
    // 'mysql' for WAMP/XAMPP, 'pgsql' for the Supabase database used in production.
    'db_driver'   => 'mysql',

    'db_host'     => '127.0.0.1',
    'db_port'     => '3306',
    'db_name'     => 'boardinghouse',

    // WAMP's default MySQL account is root with no password.
    'db_user'     => 'root',
    'db_password' => '',

    // Leave these empty locally: uploads (payment screenshots, profile pictures,
    // the GCash QR code) are then saved into the uploads/ folder instead of Supabase.
    'supabase_url'         => '',
    'supabase_service_key' => '',
];
