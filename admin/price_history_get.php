<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/price_history_get.php
//
// AJAX endpoint — returns a product's price change history
// (from the price_history table) as JSON, for the "History"
// button on the Products page.
//
//   GET price_history_get.php?product_id=5
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

header('Content-Type: application/json');

$productId = (int)($_GET['product_id'] ?? 0);

if ($productId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid product.']);
    exit;
}

// ── Current price + product name (also confirms the product exists) ──
$pStmt = $conn->prepare("SELECT name, price FROM products WHERE id = ? LIMIT 1");
$pStmt->bind_param('i', $productId);
$pStmt->execute();
$product = $pStmt->get_result()->fetch_assoc();
$pStmt->close();

if (!$product) {
    echo json_encode(['ok' => false, 'error' => 'Product not found.']);
    exit;
}

// ── Price history, newest first. LEFT JOIN users in case the
//    admin who made a change was later deleted — name shows as null then. ──
$hStmt = $conn->prepare("
    SELECT ph.old_price, ph.new_price, ph.reason, ph.changed_at,
           u.full_name AS changed_by_name
    FROM price_history ph
    LEFT JOIN users u ON u.id = ph.changed_by
    WHERE ph.product_id = ?
    ORDER BY ph.changed_at DESC, ph.id DESC
");
$hStmt->bind_param('i', $productId);
$hStmt->execute();
$res = $hStmt->get_result();

$history = [];
while ($row = $res->fetch_assoc()) {
    $history[] = [
        'old_price'         => (float)$row['old_price'],
        'new_price'         => (float)$row['new_price'],
        'reason'            => $row['reason'],
        'changed_by_name'   => $row['changed_by_name'],
        'changed_at_display' => date('M j, Y \a\t g:i A', strtotime($row['changed_at'])),
    ];
}
$hStmt->close();

echo json_encode([
    'ok'            => true,
    'product_name'  => $product['name'],
    'current_price' => (float)$product['price'],
    'history'       => $history,
]);
