<?php
// ============================================================
// HATCH — Create a PayMongo Checkout Session for an order
// File: payment/create_session.php
//
// Called right after a PayMongo order is placed. Builds the
// hosted checkout (GCash + QR Ph), saves the checkout id +
// reference on the order, then redirects the customer to pay.
// ============================================================

session_start();
require_once '../config/db.php';
require_once '../config/paymongo.php';
requireCustomer();

$uid     = (int)$_SESSION['user_id'];
$orderId = (int)($_GET['order_id'] ?? 0);

if (!$orderId) {
    redirect('../user/orders.php', 'error', 'Invalid order.');
}

// Load the order — must belong to this customer, be PayMongo + unpaid
$stmt = $conn->prepare("
    SELECT id, total_amount, payment_method, payment_status
    FROM orders WHERE id = ? AND user_id = ? LIMIT 1
");
$stmt->bind_param('ii', $orderId, $uid);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    redirect('../user/orders.php', 'error', 'Order not found.');
}
if ($order['payment_method'] !== 'paymongo') {
    redirect('../user/orders.php', 'error', 'This order is not a PayMongo order.');
}
if ($order['payment_status'] === 'paid') {
    redirect('../user/orders.php', 'success', 'This order is already paid.');
}

// Build line items from order_items (amounts in CENTAVOS: ₱250.00 -> 25000)
$items = [];
$li = $conn->prepare("
    SELECT oi.quantity, oi.unit_price, p.name
    FROM order_items oi JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
");
$li->bind_param('i', $orderId);
$li->execute();
$liRes = $li->get_result();
while ($row = $liRes->fetch_assoc()) {
    $items[] = [
        'name'     => $row['name'],
        'quantity' => (int)$row['quantity'],
        'amount'   => (int) round(((float)$row['unit_price']) * 100), // centavos
        'currency' => 'PHP',
    ];
}
$li->close();

// Delivery fee as its own line item, if any
$feeRow = $conn->query("SELECT delivery_fee FROM orders WHERE id = {$orderId}")->fetch_assoc();
$deliveryFee = (float)($feeRow['delivery_fee'] ?? 0);
if ($deliveryFee > 0) {
    $items[] = [
        'name'     => 'Delivery Fee',
        'quantity' => 1,
        'amount'   => (int) round($deliveryFee * 100),
        'currency' => 'PHP',
    ];
}

if (empty($items)) {
    redirect('../user/orders.php', 'error', 'Order has no items to pay for.');
}

$reference = 'HATCH-' . $orderId . '-' . time();

$payload = [
    'data' => [
        'attributes' => [
            'line_items'          => $items,
            'payment_method_types' => ['gcash', 'paymaya', 'qrph'],
            'reference_number'    => $reference,
            'description'         => 'HATCH Order #' . str_pad($orderId, 4, '0', STR_PAD_LEFT),
            'success_url'         => SITE_BASE_URL . '/payment/success.php?order_id=' . $orderId,
            'cancel_url'          => SITE_BASE_URL . '/payment/cancel.php?order_id=' . $orderId,
        ],
    ],
];

[$code, $data, $raw] = paymongoRequest('POST', '/checkout_sessions', $payload);

if ($code < 200 || $code >= 300 || empty($data['data'])) {
    $msg = $data['errors'][0]['detail'] ?? 'Could not start the payment. Please try again.';
    redirect('../user/orders.php', 'error', 'Payment error: ' . $msg);
}

$checkoutId  = $data['data']['id'];
$checkoutUrl = $data['data']['attributes']['checkout_url'] ?? '';

// Save the checkout id + reference on the order
$cEsc = $conn->real_escape_string($checkoutId);
$rEsc = $conn->real_escape_string($reference);
$conn->query("UPDATE orders SET paymongo_checkout_id='{$cEsc}', paymongo_reference='{$rEsc}', updated_at=NOW() WHERE id={$orderId}");

if (!$checkoutUrl) {
    redirect('../user/orders.php', 'error', 'Payment link was not returned. Please try again.');
}

// Off to PayMongo's hosted checkout
header('Location: ' . $checkoutUrl);
exit;
