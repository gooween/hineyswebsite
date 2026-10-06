<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/order_receipt_pdf.php
// Lets a logged-in customer download the PDF receipt of THEIR OWN order.
// Usage: order_receipt_pdf.php?id=123            (downloads)
//        order_receipt_pdf.php?id=123&view=1     (opens inline in the browser)
// ============================================================

session_start();
require_once '../config/db.php';
require_once __DIR__ . '/../includes/receipt_pdf.php';
requireCustomer();

$uid = (int)$_SESSION['user_id'];
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('Missing order id.');
}

// Scoped to the logged-in customer, so nobody can fetch another customer's receipt.
$data = hatch_receipt_load($conn, $id, $uid);
if (!$data) {
    http_response_code(404);
    die('Order not found.');
}
// Receipts are only available once the order has been paid.
$pq = $conn->prepare("SELECT payment_status FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
$pq->bind_param('ii', $id, $uid);
$pq->execute();
$pRow = $pq->get_result()->fetch_assoc();
$pq->close();
if (!$pRow || $pRow['payment_status'] !== 'paid') {
    http_response_code(403);
    die('A receipt is available once the order has been paid.');
}
if ($data['order']['status'] === 'cancelled') {
    http_response_code(403);
    die('No receipt is available for a cancelled order.');
}

$pdf      = hatch_receipt_pdf($data);
$filename = 'Receipt-' . $data['transaction_no'] . '.pdf';
$inline   = !empty($_GET['view']);

while (ob_get_level() > 0) ob_end_clean();   // make sure nothing precedes the PDF bytes
header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
exit;
