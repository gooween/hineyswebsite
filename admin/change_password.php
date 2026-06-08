<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/change_password.php
// ============================================================
session_start();
require_once '../config/db.php';
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$current  = $_POST['current_password'] ?? '';
$new      = $_POST['new_password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';
$uid      = (int)$_SESSION['user_id'];

if (!$current || !$new || !$confirm) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if (strlen($new) < 6) {
    echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
    exit;
}

if ($new !== $confirm) {
    echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
    exit;
}

// Fetch current password hash
$r = $conn->query("SELECT password FROM users WHERE id = {$uid} LIMIT 1");
if (!$r || $r->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

$user = $r->fetch_assoc();

// Verify current password
if (!password_verify($current, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
    exit;
}

// Update to new password
$hash = password_hash($new, PASSWORD_DEFAULT);
$hashEsc = $conn->real_escape_string($hash);
$conn->query("UPDATE users SET password = '{$hashEsc}' WHERE id = {$uid}");

if ($conn->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update password. Try again.']);
}
