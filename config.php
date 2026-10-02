<?php
// config.php
//
// Local configuration for running the system on your own machine (WAMP / XAMPP).
// These are the WAMP defaults; edit them if your MySQL login differs.
//
// This file is committed so everyone gets a working local setup straight from a clone.
// Keep real credentials out of it: it holds local development values only.
// The deployed server ignores this file entirely: when DATABASE_URL is set it connects
// from that, and individual DB_* environment variables override either source. So this
// file cannot misdirect production.

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
