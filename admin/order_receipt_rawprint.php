<?php
// order_receipt_rawprint.php
// Builds the same 32-char (58mm) plain-text receipt as order_receipt_print.php,
// then sends it directly to the OFFICOM printer's Windows share as a raw
// print job — bypassing Chrome's print dialog and Windows' page-size
// handling entirely. This is what fixes the "way too long" print: there's
// no virtual Letter-sized page involved, just the exact text sent.
//
// Requirements this relies on (already set up):
//   - PHP's shell_exec() is available (true by default for `php -S`)
//   - The printer is shared locally as \\localhost\OFFICOM
//   - This PHP process runs on the same Windows machine the printer is on
//
// Reuses the same tables/joins as orders.php — no schema changes.

session_start();
require_once '../config/db.php';
require_once __DIR__ . '/../includes/receipt_pdf.php';   // hatch_logo_escpos()
requireAdmin();

header('Content-Type: application/json');

// ── Config — adjust if the share name or port ever changes ───────────
const PRINTER_SHARE = '\\\\localhost\\OFFICOM';
const LINE_WIDTH = 32;

// Print the Hiney's logo (assets/images/hineys_logo.png) at the top of the receipt
// as an ESC/POS raster image. Needs PHP's GD extension and a printer that
// understands ESC/POS "GS v 0" (virtually all 58mm thermal printers do).
// If the printer prints garbage characters at the top instead of a logo,
// set this to false — the text receipt prints exactly as before.
const PRINT_LOGO = true;
const LOGO_WIDTH_DOTS = 200;   // 384 dots = full 58mm width; smaller = smaller logo

function pad_right($s, $len)
{
    $s = mb_substr((string)$s, 0, $len);
    return $s . str_repeat(' ', max(0, $len - mb_strlen($s)));
}
function center_line($s, $width = LINE_WIDTH)
{
    $s = mb_substr((string)$s, 0, $width);
    $pad = max(0, (int)floor(($width - mb_strlen($s)) / 2));
    return str_repeat(' ', $pad) . $s;
}
function two_col($left, $right, $width = LINE_WIDTH)
{
    $left = (string)$left;
    $right = (string)$right;
    $space = $width - mb_strlen($left) - mb_strlen($right);
    if ($space < 1) {
        $left = mb_substr($left, 0, max(0, $width - mb_strlen($right) - 1));
        return $left . ' ' . $right;
    }
    return $left . str_repeat(' ', $space) . $right;
}
function rule_line($ch = '-', $width = LINE_WIDTH)
{
    return str_repeat($ch, $width);
}
function money($n)
{
    return 'P' . number_format((float)$n, 2);
}
function wrap_text($s, $width = LINE_WIDTH)
{
    $s = trim((string)$s);
    $out = [];
    while (mb_strlen($s) > $width) {
        $cut = mb_strrpos(mb_substr($s, 0, $width + 1), ' ');
        if ($cut === false || $cut === 0) $cut = $width;
        $out[] = trim(mb_substr($s, 0, $cut));
        $s = trim(mb_substr($s, $cut));
    }
    if ($s !== '') $out[] = $s;
    return $out ?: [''];
}

function fail($msg, $extra = [])
{
    http_response_code(500);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing order id.']);
    exit;
}

if (!function_exists('shell_exec') || stripos((string)ini_get('disable_functions'), 'shell_exec') !== false) {
    fail('shell_exec() is disabled on this PHP install, so raw printing cannot run. Check php.ini\'s disable_functions.');
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
    echo json_encode(['ok' => false, 'error' => 'Order not found.']);
    exit;
}
$order = $ord->fetch_assoc();

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
            'name'     => $row['name'],
            'qty'      => $qty,
            'unit'     => $qty > 0 ? $subtotal / $qty : 0,
            'subtotal' => $subtotal,
        ];
        $itemsSubtotal += $subtotal;
    }
}

$paidAt = null;
$txnRow = null;
$txRes = $conn->query("SELECT transaction_date, payment_method FROM transactions WHERE order_id = {$id} ORDER BY id DESC LIMIT 1");
if ($txRes && $row = $txRes->fetch_assoc()) {
    $paidAt = $row['transaction_date'];
    $txnRow = $row;
}

// Actual channel (GCash / Maya / QR Ph / Card ...) instead of just "PAYMONGO"
$methodDisplay = strtoupper(hatch_payment_label($order, $txnRow));

$transactionNo = 'HATCH-ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT);

// ── Build the receipt text (same layout as order_receipt_print.php) ──
$lines = [];
$lines[] = center_line('HATCH');
foreach (wrap_text("Hiney's Automated Tracking") as $l) $lines[] = center_line($l);
foreach (wrap_text("Commerce and Hub") as $l) $lines[] = center_line($l);
$lines[] = rule_line('=');
$lines[] = center_line('TRANSACTION / ORDER');
$lines[] = rule_line('=');
$lines[] = 'Txn No.: ' . $transactionNo;
$lines[] = 'Date: ' . date('Y-m-d H:i', strtotime($order['created_at']));
$lines[] = '';
$lines[] = 'Customer: ' . $order['full_name'];
if ($order['phone'] ?: $order['email']) {
    $lines[] = 'Contact:  ' . ($order['phone'] ?: $order['email']);
}
if (!empty($order['delivery_address'])) {
    $lines[] = 'Address:';
    foreach (wrap_text($order['delivery_address']) as $l) $lines[] = $l;
}
$lines[] = '';
foreach (wrap_text('Payment:  ' . $methodDisplay . ' (' . strtoupper($order['payment_status']) . ')') as $l) $lines[] = $l;
$lines[] = 'Status:   ' . strtoupper(str_replace('_', ' ', $order['status']));
$lines[] = rule_line('-');

foreach ($items as $it) {
    foreach (wrap_text($it['name']) as $l) $lines[] = $l;
    $lines[] = two_col('  ' . $it['qty'] . ' x ' . money($it['unit']), money($it['subtotal']));
}
$lines[] = rule_line('-');

$lines[] = two_col('Subtotal:', money($itemsSubtotal));
$lines[] = two_col('Delivery Fee:', money($order['delivery_fee'] ?? 0));
$lines[] = rule_line('-');
$lines[] = two_col('TOTAL:', money($order['total_amount']));
$lines[] = rule_line('=');

if ($paidAt) {
    $lines[] = 'Paid At: ' . $paidAt;
    $lines[] = '';
}

$lines[] = center_line('Thank you for your order!');
// A handful of trailing blank lines so the receipt clears the cutter —
// tune this up/down depending on how your printer feeds after text ends.
$lines[] = '';
$lines[] = '';
$lines[] = '';
$lines[] = '';

$receiptText = implode("\r\n", $lines) . "\r\n";

// Logo bytes go first (empty string if disabled / GD missing / logo file missing).
if (PRINT_LOGO) {
    $receiptText = hatch_logo_escpos(HATCH_LOGO_PATH, LOGO_WIDTH_DOTS) . $receiptText;
}

// ── Write to a temp file, then copy it straight to the printer share ──
$tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hatch_receipt_' . $id . '_' . time() . '.txt';
if (file_put_contents($tmpPath, $receiptText) === false) {
    fail('Could not write temp receipt file.');
}

// escapeshellarg on Windows wraps in double quotes, which is what `copy /b` needs.
$cmd = 'copy /b ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg(PRINTER_SHARE) . ' 2>&1';
$output = shell_exec($cmd);

@unlink($tmpPath);

$success = $output !== null && stripos($output, 'copied') !== false;

if (!$success) {
    fail('The copy command did not report success.', ['command_output' => trim((string)$output)]);
}

echo json_encode(['ok' => true, 'transaction_no' => $transactionNo]);
