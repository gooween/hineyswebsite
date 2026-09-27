<?php
// order_receipt_data.php
// Read-only JSON endpoint that feeds the 58mm Bluetooth thermal receipt printer.
// Reuses the exact same tables/joins as orders.php — no schema changes, no new tables.

session_start();
require_once '../config/db.php';
requireAdmin();

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing order id']);
    exit;
}

$ord = $conn->query("SELECT o.id, o.status, o.payment_status, o.payment_method, o.paymongo_method,
                             o.total_amount, o.delivery_fee, o.delivery_address, o.created_at,
                             u.full_name, u.phone, u.email
                      FROM orders o
                      JOIN users u ON u.id = o.user_id
                      WHERE o.id = {$id}
                      LIMIT 1");

if (!$ord || $ord->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found']);
    exit;
}
$order = $ord->fetch_assoc();

// Line items — unit price derived from subtotal/quantity since order_items
// stores subtotal (same source of truth used everywhere else in orders.php).
$itemsRes = $conn->query("SELECT oi.quantity, oi.subtotal, p.name
                           FROM order_items oi
                           JOIN products p ON p.id = oi.product_id
                           WHERE oi.order_id = {$id}
                           ORDER BY oi.id ASC");
$items = [];
$itemsSubtotal = 0.0;
if ($itemsRes) {
    while ($row = $itemsRes->fetch_assoc()) {
        $qty = (int)$row['quantity'];
        $subtotal = (float)$row['subtotal'];
        $items[] = [
            'name'       => $row['name'],
            'qty'        => $qty,
            'unit_price' => $qty > 0 ? round($subtotal / $qty, 2) : 0,
            'subtotal'   => $subtotal,
        ];
        $itemsSubtotal += $subtotal;
    }
}

// Most recent transaction record for this order (if paid), for Paid At.
$paidAt = null;
$txRes = $conn->query("SELECT transaction_date FROM transactions WHERE order_id = {$id} ORDER BY id DESC LIMIT 1");
if ($txRes && $row = $txRes->fetch_assoc()) {
    $paidAt = $row['transaction_date'];
}

$methodDisplay = $order['payment_method'] === 'paymongo'
    ? (!empty($order['paymongo_method']) ? strtoupper($order['paymongo_method']) : 'PAYMONGO')
    : strtoupper($order['payment_method']);

echo json_encode([
    'transaction_no'   => 'HATCH-ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT),
    'order_id'         => (int)$order['id'],
    'date'             => date('Y-m-d H:i', strtotime($order['created_at'])),
    'customer_name'    => $order['full_name'],
    'customer_contact' => $order['phone'] ?: $order['email'],
    'delivery_address' => $order['delivery_address'],
    'payment_method'   => $methodDisplay,
    'payment_status'   => strtoupper($order['payment_status']),
    'order_status'     => strtoupper(str_replace('_', ' ', $order['status'])),
    'items'            => $items,
    'items_subtotal'   => $itemsSubtotal,
    'delivery_fee'     => $order['delivery_fee'] !== null ? (float)$order['delivery_fee'] : 0.0,
    'discount'         => 0.0,
    'total'            => (float)$order['total_amount'],
    'paid_at'          => $paidAt,
]);
