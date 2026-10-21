<?php
// local_print_bridge.php
// Runs ONLY on the shop PC (the Windows machine the printer is attached to).
// Start it with:   php -S 127.0.0.1:8765 local_print_bridge.php
// The hosted admin page sends it the receipt bytes; it copies them straight to
// the printer share — the same "copy /b" that order_receipt_rawprint.php used.

const PRINTER_SHARE = '\\\\localhost\\OFFICOM';
const BRIDGE_TOKEN  = 'dfshdvssbdidbksk';   // must match order_receipt_print.php
const ALLOWED_ORIGIN = 'https://hatch.rf.gd';   // must be the full origin: scheme + host, no trailing slash

header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Bridge-Token');
header('Access-Control-Allow-Private-Network: true');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => true, 'bridge' => 'ready']);
    exit;
}

function bridge_fail($msg, $code = 500, $extra = [])
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra));
    exit;
}

if (!hash_equals(BRIDGE_TOKEN, (string)($_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ''))) {
    bridge_fail('Bad bridge token.', 403);
}

$body = json_decode(file_get_contents('php://input'), true);
$bytes = base64_decode((string)($body['data'] ?? ''), true);
if ($bytes === false || $bytes === '') bridge_fail('No receipt data.', 400);

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hatch_receipt_' . time() . '_' . mt_rand(1000, 9999) . '.bin';
if (file_put_contents($tmp, $bytes) === false) bridge_fail('Could not write temp file.');

$out = shell_exec('copy /b ' . escapeshellarg($tmp) . ' ' . escapeshellarg(PRINTER_SHARE) . ' 2>&1');
@unlink($tmp);

if ($out === null || stripos($out, 'copied') === false) {
    bridge_fail('The copy command did not report success.', 500, ['command_output' => trim((string)$out)]);
}
echo json_encode(['ok' => true]);
