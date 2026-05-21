<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: logout.php  (place in hineys_system/ root)
// Purpose: Safely destroy session, prevent Back-button re-entry
// ============================================================

// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,   // set true on HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── No-cache headers ─────────────────────────────────────────
// Critical: prevents the browser from caching any protected page
// so the Back button cannot display stale authenticated content.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

// ── Remember which role just logged out (for flash message) ──
$role = $_SESSION['role'] ?? '';

// ── 1. Unset all session variables ───────────────────────────
$_SESSION = [];

// ── 2. Delete the session cookie from the browser ────────────
// This stops the old session ID from being reused.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]
    );
}

// ── 3. Destroy the session on the server ─────────────────────
session_destroy();

// ── 4. Start a fresh session for the flash message ───────────
session_start();
$_SESSION['flash_type']    = 'success';
$_SESSION['flash_message'] = 'You have been logged out successfully.';

// ── 5. Redirect to login page ────────────────────────────────
header('Location: index.php');
exit;