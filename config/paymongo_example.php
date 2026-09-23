<?php
// ============================================================
// HATCH — PayMongo config
// File: config/paymongo.php   ** ADD THIS FILE TO .gitignore **
//
// Get your keys from: PayMongo Dashboard → Developers → API Keys.
// Use the TEST keys (sk_test_... / pk_test_...) while developing —
// test mode moves no real money.
// ============================================================

// Secret key (server-side only — NEVER expose to the browser)
define('PAYMONGO_SECRET_KEY', 'sk_test_PASTE_YOUR_TEST_SECRET_KEY_HERE');

// Webhook signing secret — from Dashboard → Developers → Webhooks
// (after you create the webhook). Leave blank until you have it.
define('PAYMONGO_WEBHOOK_SECRET', '');

// Base URL of your site. For local testing this is your XAMPP URL.
// success/cancel pages redirect here; must be reachable by the browser.
define('SITE_BASE_URL', 'http://localhost:8000');

// PayMongo API base
define('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1');

/**
 * Small cURL helper for PayMongo REST calls (Basic auth with the secret key).
 * Returns [httpCode, decodedArray|null, rawResponse|errorString].
 */
function paymongoRequest(string $method, string $endpoint, array $payload = null): array
{
    $ch = curl_init(PAYMONGO_API_BASE . $endpoint);
    $headers = [
        'Authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        // Same SSL-safe handling as our Cloudinary helper (XAMPP/Windows).
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return [0, null, "cURL error: {$err}"];
    return [$code, json_decode($resp, true), $resp];
}
