<?php
// includes/php_version_check.php
//
// Stops an old PHP with a readable message instead of a parse error.
//
// The rest of the project uses syntax and functions that older PHP cannot even parse
// (typed returns from 7.0, arrow functions from 7.4, str_starts_with from 8.0), so a server
// running PHP 5 fails while *reading* the file it was asked to include, long before any code
// of ours runs. That produces "syntax error, unexpected ':'" pointing at a line that is
// perfectly valid, which tells you nothing about the real problem.
//
// This file is deliberately written in very old PHP syntax so it parses anywhere, and is
// included before anything else, so it can say what is actually wrong.

define('APP_MIN_PHP_VERSION', '8.0.0');

if (version_compare(PHP_VERSION, APP_MIN_PHP_VERSION, '<')) {
    $running = PHP_VERSION;
    $needed = APP_MIN_PHP_VERSION;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>PHP version too old</title>';
    echo '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#1e293b;line-height:1.6}';
    echo 'h1{font-size:1.3rem;color:#b91c1c}code{background:#f1f5f9;padding:2px 6px;border-radius:4px}';
    echo 'li{margin-bottom:6px}.box{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin:20px 0}</style>';
    echo '</head><body>';
    echo '<h1>This system needs a newer PHP</h1>';
    echo '<div class="box">Running <strong>PHP ' . htmlspecialchars($running) . '</strong>';
    echo ' &mdash; it needs <strong>PHP ' . htmlspecialchars($needed) . '</strong> or newer.</div>';
    echo '<p>In WampServer you can switch without reinstalling anything:</p><ol>';
    echo '<li>Left-click the WampServer icon in the system tray</li>';
    echo '<li>Go to <strong>PHP</strong> &rarr; <strong>Version</strong></li>';
    echo '<li>Pick <strong>8.0</strong> or newer, and wait for the icon to turn green again</li></ol>';
    echo '<p>If no 8.x is offered, that copy of WampServer is too old to have one. Install the ';
    echo 'current 64-bit WampServer, which ships with PHP 8, and put the project in its ';
    echo '<code>www</code> folder. Setup steps are in <code>LOCAL_SETUP.md</code>.</p>';
    echo '</body></html>';
    exit;
}
