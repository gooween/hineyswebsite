<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: config/db.php
// ============================================================

define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    'hineysweb_db2026');
define('DB_NAME',    'hineys_db');
define('DB_CHARSET', 'utf8mb4');

// ── Connect FIRST, then load auth ────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    http_response_code(500);
    die('<div style="font-family:sans-serif;padding:40px;color:#991b1b;">
        <strong>Database connection failed.</strong><br><br>'
        . htmlspecialchars($conn->connect_error) . '</div>');
}

$conn->set_charset(DB_CHARSET);
$conn->query("SET time_zone = '+08:00'");

// ── Auth (session_start lives here, only called once) ────────
require_once __DIR__ . '/auth.php';

// ── Helpers ───────────────────────────────────────────────────

function clean(string $value, mysqli $conn): string
{
    return $conn->real_escape_string(trim($value));
}

function redirect(string $url, string $type = '', string $message = ''): void
{
    if ($type && $message) {
        $_SESSION['flash_type']    = $type;
        $_SESSION['flash_message'] = $message;
    }
    header('Location: ' . $url);
    exit;
}

function flash(): string
{
    if (empty($_SESSION['flash_message'])) return '';
    $type    = $_SESSION['flash_type']    ?? 'info';
    $message = $_SESSION['flash_message'] ?? '';
    unset($_SESSION['flash_type'], $_SESSION['flash_message']);
    $colors = [
        'success' => ['#d4edda', '#155724', '#28a745'],
        'error'   => ['#f8d7da', '#721c24', '#dc3545'],
        'warning' => ['#fff3cd', '#856404', '#ffc107'],
        'info'    => ['#d1ecf1', '#0c5460', '#17a2b8'],
    ];
    [$bg, $text, $border] = $colors[$type] ?? $colors['info'];
    return "<div style=\"background:{$bg};color:{$text};border-left:4px solid {$border};
        padding:12px 16px;border-radius:6px;margin-bottom:16px;font-size:0.9rem;font-weight:500;\">"
        . htmlspecialchars($message) . "</div>";
}

function peso(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function cartCount(mysqli $conn): int
{
    if (empty($_SESSION['user_id'])) return 0;
    $uid = (int)$_SESSION['user_id'];
    $res = $conn->query("SELECT COALESCE(SUM(quantity),0) AS cnt FROM cart WHERE user_id = {$uid}");
    return $res ? (int)($res->fetch_assoc()['cnt'] ?? 0) : 0;
}

function orderStatusBadge(string $status): string
{
    $map = [
        'pending'          => ['#fff3cd', '#856404'],
        'confirmed'        => ['#cce5ff', '#004085'],
        'processing'       => ['#e2d9f3', '#4a0f8a'],
        'out_for_delivery' => ['#ffe5b4', '#7a4f00'],
        'delivered'        => ['#d4edda', '#155724'],
        'cancelled'        => ['#f8d7da', '#721c24'],
    ];
    [$bg, $color] = $map[$status] ?? ['#e2e3e5', '#383d41'];
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span style=\"background:{$bg};color:{$color};padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:600;white-space:nowrap;\">{$label}</span>";
}

function paymentStatusBadge(string $status): string
{
    if ($status === 'paid')
        return "<span style=\"background:#d4edda;color:#155724;padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:600;\">Paid</span>";
    return "<span style=\"background:#f8d7da;color:#721c24;padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:600;\">Unpaid</span>";
}

function stockStatusBadge(int $qty, int $reorder): string
{
    if ($qty <= 0)
        return "<span style=\"background:#f8d7da;color:#721c24;padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:600;\">Out of Stock</span>";
    if ($qty <= $reorder)
        return "<span style=\"background:#fff3cd;color:#856404;padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:600;\">Low Stock</span>";
    return "<span style=\"background:#d4edda;color:#155724;padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:600;\">OK</span>";
}
