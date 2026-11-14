<?php
// ============================================================
// rider/rider_auth.php — loads the signed-in rider + small helpers.
// Sign-in / sign-out use the SAME pages as every other account:
//   login  -> ../index.php      logout -> ../logout.php
// requireRider() itself lives in config/auth.php next to requireAdmin()/requireCustomer().
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';   // also loads config/auth.php

/** Guards the page, then returns the rider's row (with availability). */
function riderLoad(mysqli $conn): array
{
    requireRider();
    $uid = (int)$_SESSION['user_id'];
    $r = $conn->query("SELECT u.id, u.full_name, u.email, u.phone, u.address, u.municipality, u.barangay,
                              u.street_address, u.landmark, u.is_active, u.created_at, rd.availability
                       FROM users u JOIN riders rd ON rd.user_id = u.id
                       WHERE u.id = {$uid} AND u.role = 'rider' LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row || !$row['is_active']) {
        // Disabled (or removed) by the admin since they signed in
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash_type']    = 'error';
        $_SESSION['flash_message'] = 'Your rider account is no longer active. Please contact the shop.';
        header('Location: ../index.php');
        exit;
    }
    return $row;
}

function riderCsrf(): string
{
    if (empty($_SESSION['rider_csrf'])) $_SESSION['rider_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['rider_csrf'];
}

function riderCheckCsrf(): bool
{
    return !empty($_POST['csrf']) && !empty($_SESSION['rider_csrf'])
        && hash_equals($_SESSION['rider_csrf'], (string)$_POST['csrf']);
}

/** Cloudinary URLs are used as-is; legacy local paths are relative to the site root. */
function riderMedia(string $path): string
{
    if ($path === '') return '';
    return preg_match('#^https?://#', $path) ? $path : '../' . $path;
}
