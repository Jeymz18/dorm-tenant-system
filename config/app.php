<?php
/**
 * config/app.php
 * Bootstrap file — every page in the app starts with:
 *   require_once __DIR__ . '/../config/app.php';   (or the matching relative path)
 *
 * It starts the session, defines site-wide constants, and loads the
 * database connection + helper functions so nothing else has to.
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
error_reporting(E_ALL);
// Show errors on the screen only when running on your own computer
// (localhost). On any real server they go to the error log instead,
// so visitors never see file paths or database details.
$isLocalHost = PHP_SAPI === 'cli' || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
ini_set('display_errors', $isLocalHost ? '1' : '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If you rename the project folder in htdocs, update this to match,
// e.g. '' if the project sits directly at http://localhost/
define('BASE_URL', '/dorm-tenant-system');
define('SITE_NAME', 'Dorm Tenant Management System');

// Full public URL of the site (scheme + host + BASE_URL, no trailing
// slash). BASE_URL alone is just a path, but PayMongo's success_url /
// cancel_url and its webhook callback both need a real, reachable
// https:// URL — localhost doesn't work for either.
//   - Local testing: run `ngrok http 80` (or your dev port) and paste
//     the https://xxxx.ngrok-free.app URL here, e.g.
//     'https://xxxx.ngrok-free.app/dorm-tenant-system'
//   - Production: your real domain, e.g.
//     'https://yourdorm.com/dorm-tenant-system'
define('APP_URL', rtrim((string) (getenv('APP_URL') ?: ('http://localhost' . BASE_URL)), '/'));

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/paymongo.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/payment_invoice.php';
require_once __DIR__ . '/../includes/paymongo.php';
