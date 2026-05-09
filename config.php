<?php
declare(strict_types=1);

/** ProctorDesk: app config, constants, session bootstrap, security headers. */

// Output buffering with gzip when supported.
if (!ob_start('ob_gzhandler')) {
    ob_start();
}

// Errors: never leak in production.
const PROCTORDESK_DEBUG = false;
if (PROCTORDESK_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// DB credentials (override via env if desired).
const DB_HOST    = 'localhost';
const DB_NAME    = 'proctordesk';
const DB_USER    = 'proctordesk';
const DB_PASS    = 'change-me';
const DB_CHARSET = 'utf8mb4';

// App constants.
const APP_NAME            = 'ProctorDesk';
const APP_TIMEZONE        = 'UTC';
const SESSION_NAME        = 'PDSESS';
const CSRF_COOKIE         = 'pd_csrf';
const UPLOAD_DIR          = __DIR__ . '/uploads/exam-files';
const MAX_UPLOAD_BYTES    = 5 * 1024 * 1024;          // 5 MB reference files
const MAX_IMAGE_BYTES     = 500 * 1024;               // 500 KB question images
const HEARTBEAT_INTERVAL  = 30;                       // seconds
const HEARTBEAT_MISS_MAX  = 3;
const ANSWER_RATE_LIMIT   = 2;                        // 1 save per N seconds
const LOGIN_MAX_FAILS     = 5;
const LOGIN_FAIL_WINDOW   = 15 * 60;                  // 15 minutes

date_default_timezone_set(APP_TIMEZONE);

// Per-request CSP nonce.
if (!isset($GLOBALS['CSP_NONCE'])) {
    $GLOBALS['CSP_NONCE'] = bin2hex(random_bytes(16));
}

// Session config: HttpOnly + SameSite=Strict + Secure when on HTTPS.
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Security headers (CSP allows nonce'd inline + self only; no external).
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'nonce-{$GLOBALS['CSP_NONCE']}'; "
    . "style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data:; font-src 'self' data:; "
    . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// CSRF token bootstrap (double-submit cookie pattern).
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
if (empty($_COOKIE[CSRF_COOKIE]) || $_COOKIE[CSRF_COOKIE] !== $_SESSION['csrf']) {
    setcookie(CSRF_COOKIE, $_SESSION['csrf'], [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => false, // JS reads it for AJAX header
        'samesite' => 'Strict',
    ]);
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
