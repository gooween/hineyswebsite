<?php
// ============================================================
// HATCH — PayMongo Webhook (SOURCE OF TRUTH for payment)
// File: payment/webhook.php
//
// PayMongo calls this when a checkout session is paid. It
// verifies the signature, finds the order by reference, and
// marks it PAID. This is the ONLY place orders become paid.
//
// This URL must be publicly reachable (use ngrok / Cloudflare
// Tunnel for local testing) and registered in the PayMongo
// Dashboard → Developers → Webhooks, event:
//   checkout_session.payment.paid
// ============================================================

require_once '../config/db.php';
require_once '../config/paymongo.php';

// Read raw body (needed for signature verification)
$payload = file_get_contents('php://input');
http_response_code(200); // always ack quickly so PayMongo doesn't retry-storm

// ── Verify signature (Paymongo-Signature header) ──────────────
// Header format: t=<timestamp>,te=<test-sig>,li=<live-sig>
$sigHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
if (PAYMONGO_WEBHOOK_SECRET !== '' && $sigHeader !== '') {
    $parts = [];
    foreach (explode(',', $sigHeader) as $kv) {
        $p = explode('=', $kv, 2);
        if (count($p) === 2) $parts[trim($p[0])] = trim($p[1]);
    }
    $timestamp = $parts['t'] ?? '';
    // test key uses 'te', live uses 'li'
    $providedSig = $parts['te'] ?? ($parts['li'] ?? '');
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, PAYMONGO_WEBHOOK_SECRET);
    if (!hash_equals($expected, $providedSig)) {
        // Log details so we can diagnose (does NOT log the full secret)
        $dbg = date('c') . " SIGNATURE MISMATCH\n"
            . "  raw header: {$sigHeader}\n"
            . "  provided:   {$providedSig}\n"
            . "  expected:   {$expected}\n"
            . "  secret len: " . strlen(PAYMONGO_WEBHOOK_SECRET) . " starts: " . substr(PAYMONGO_WEBHOOK_SECRET, 0, 6) . "\n";
        file_put_contents(__DIR__ . '/webhook_log.txt', $dbg, FILE_APPEND);
        exit;
    }
}

$event = json_decode($payload, true);
$type  = $event['data']['attributes']['type'] ?? '';

// Log every event for debugging (optional — safe to remove later)
file_put_contents(__DIR__ . '/webhook_log.txt', date('c') . " {$type}\n", FILE_APPEND);

// We only act on a successful checkout payment
if ($type === 'checkout_session.payment.paid' || $type === 'payment.paid') {
    // Dig out the reference_number we set when creating the session
    $attr = $event['data']['attributes']['data']['attributes'] ?? [];
    $reference = $attr['reference_number'] ?? '';

    // payment.paid events may not carry the reference; fall back to checkout id
    $checkoutId = $event['data']['attributes']['data']['id'] ?? '';

    // Which method did PayMongo actually charge? (gcash / paymaya / qrph)
    // For a checkout session, it's in the payments array's source type.
    $method = '';
    $payments = $attr['payments'] ?? [];
    if (!empty($payments[0]['attributes'])) {
        $pa = $payments[0]['attributes'];
        $method = $pa['source']['type']
            ?? ($pa['payment_method_used'] ?? ($pa['method'] ?? ''));
    }
    // Fallback: some payloads expose it directly
    if ($method === '') {
        $method = $attr['payment_method_used'] ?? '';
    }
    // Normalize to a friendly label
    $methodMap = ['gcash' => 'GCash', 'paymaya' => 'Maya', 'qrph' => 'QR Ph'];
    $methodLabel = $methodMap[strtolower($method)] ?? ($method !== '' ? ucfirst($method) : 'PayMongo');

    $order = null;
    if ($reference !== '') {
        $rEsc = $conn->real_escape_string($reference);
        $res = $conn->query("SELECT id, payment_status FROM orders WHERE paymongo_reference='{$rEsc}' LIMIT 1");
        $order = $res ? $res->fetch_assoc() : null;
    }
    if (!$order && $checkoutId !== '') {
        $cEsc = $conn->real_escape_string($checkoutId);
        $res = $conn->query("SELECT id, payment_status FROM orders WHERE paymongo_checkout_id='{$cEsc}' LIMIT 1");
        $order = $res ? $res->fetch_assoc() : null;
    }

    if ($order && $order['payment_status'] !== 'paid') {
        $oid = (int)$order['id'];
        $mEsc = $conn->real_escape_string($methodLabel);
        $conn->query("UPDATE orders SET payment_status='paid', paid_at=NOW(), paymongo_method='{$mEsc}', updated_at=NOW() WHERE id={$oid}");

        // Create a transaction record if none exists (mirrors admin mark-paid)
        $chk = $conn->query("SELECT COUNT(*) AS c FROM transactions WHERE order_id={$oid}");
        if ($chk && (int)$chk->fetch_assoc()['c'] === 0) {
            $amtRes = $conn->query("SELECT total_amount FROM orders WHERE id={$oid}");
            $amt = (float)($amtRes->fetch_assoc()['total_amount'] ?? 0);
            $refEsc = $conn->real_escape_string($reference ?: $checkoutId);
            $txnMethod = $conn->real_escape_string(strtolower($method) ?: 'paymongo');
            $conn->query("INSERT INTO transactions (order_id, amount, payment_method, reference_no, transaction_date)
                          VALUES ({$oid}, {$amt}, '{$txnMethod}', '{$refEsc}', NOW())");
        }
    }
}
exit;
