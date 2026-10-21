<?php
// order_receipt_print.php
// A plain, fixed-width (32-char, 58mm) text receipt meant to be printed via
// the browser's own native Print function to a "Generic / Text Only"
// Windows driver — that kind of driver can't render CSS/graphics, so this
// page is built as pre-formatted monospace text instead of styled HTML.
// Reuses the same tables/joins as orders.php — no schema changes.

session_start();
require_once '../config/db.php';
require_once __DIR__ . '/../includes/receipt_pdf.php';   // hatch_payment_label()
requireAdmin();

const LINE_WIDTH = 32;

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

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    die('Missing order id.');
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
    die('Order not found.');
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

// Build the receipt as an array of already-aligned, <=32-char lines.
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

// Receipt ends right at the thank-you line — no trailing blank lines.
$receiptText = implode("\n", $lines);

// Exact paper length so the printer stops at the thank-you line.
// 7pt font * 1.3 line-height = ~3.21mm per line.
const PRINT_LINE_MM = 3.21;
const PRINT_LOGO_MM = 24;     // logo block height (logo + its padding)
const PRINT_FEED_MM = 6;      // small tail so the last line clears the cutter
$logoCandidates = [];
if (defined('HATCH_LOGO_PATH')) $logoCandidates[] = HATCH_LOGO_PATH;
$logoCandidates[] = __DIR__ . '/../assets/images/hineys_logo.png';
$logoCandidates[] = __DIR__ . '/assets/images/hineys_logo.png';
foreach ((array)glob(__DIR__ . '/../assets/images/*logo*.*') as $g) $logoCandidates[] = $g;
$logoData = '';
foreach ($logoCandidates as $logoPath) {
    if ($logoPath && is_file($logoPath)) {
        $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : ($ext === 'webp' ? 'image/webp' : ($ext === 'svg' ? 'image/svg+xml' : 'image/png'));
        $logoData = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
        break;
    }
}
// Logo block is always reserved/rendered (falls back to the URL if no file was found server-side).
$logoSrc = $logoData ?: '../assets/images/hineys_logo.png';
$pageHeightMm = (int)ceil(count($lines) * PRINT_LINE_MM + PRINT_LOGO_MM + PRINT_FEED_MM);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= htmlspecialchars($transactionNo) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f2f2f2;
            margin: 0;
            padding: 24px 12px 40px;
        }

        h2 {
            width: 280px;
            margin: 0 auto 10px;
            font-size: 15px;
            text-align: center;
            color: #333;
        }

        /* A plain <pre> block mirrors exactly what the printer will receive —
       every space and line break here is what actually gets printed. */
        pre.receipt {
            width: 280px;
            margin: 0 auto;
            background: #fff;
            padding: 16px 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 13px;
            line-height: 1.35;
            white-space: pre-wrap;
        }

        /* Logo sits on top of the receipt "paper" in the preview. Greyscale
       approximates how it comes out on the thermal printer. */
        .receipt-logo {
            width: 280px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 6px 6px 0 0;
            padding: 14px 14px 0;
            text-align: center;
        }

        .receipt-logo img {
            display: block;
            margin: 0 auto;
            max-width: 150px;
            max-height: 70px;
            filter: grayscale(1) contrast(1.4);
        }

        .receipt-logo+pre.receipt {
            border-top: none;
            border-radius: 0 0 6px 6px;
            padding-top: 6px;
        }

        .actions {
            width: 280px;
            margin: 16px auto 0;
            display: flex;
            gap: 8px;
        }

        .actions button {
            flex: 1;
            padding: 12px;
            font-size: 15px;
            border-radius: 6px;
            border: 1px solid #ddd;
            background: #fff;
            cursor: pointer;
        }

        .actions button.primary {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
            font-weight: 600;
        }

        .actions button:disabled {
            opacity: 0.6;
            cursor: default;
        }

        .status {
            width: 280px;
            margin: 10px auto 0;
            text-align: center;
            font-size: 14px;
        }

        .status.ok {
            color: #16a34a;
        }

        .status.err {
            color: #c0392b;
        }

        /* ── Browser printing (58mm thermal paper) ──────────────────────
           Without @page, Chrome prints on a Letter/A4 page, which is what
           made the old printout "way too long". */
        @page {
            size: 58mm <?= (int)$pageHeightMm ?>mm;
            margin: 0;
        }

        @media print {

            html,
            body {
                background: #fff;
                margin: 0;
                padding: 0;
                width: 58mm;
                height: <?= (int)$pageHeightMm ?>mm;
                overflow: hidden;
            }

            h2,
            .actions,
            .status {
                display: none !important;
            }

            .receipt-logo,
            pre.receipt {
                width: 58mm;
                margin: 0;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                background: #fff;
            }

            .receipt-logo {
                height: <?= (int)PRINT_LOGO_MM ?>mm;
                padding: 2mm 0 0;
                text-align: center;
            }

            .receipt-logo img {
                display: block;
                margin: 0 auto;
                max-width: 40mm;
                max-height: 20mm;
                filter: grayscale(1) contrast(1.6);
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* 32 chars must fit the ~48mm printable width. Tweak 7pt up/down
               if lines wrap or leave too much empty space on the right. */
            pre.receipt {
                margin: 0;
                padding: 0 4mm;
                font-size: 7pt;
                line-height: 1.3;
                white-space: pre;
                overflow: hidden;
            }
        }
    </style>
</head>

<body>
    <h2>Preview — confirm before printing</h2>
    <div class="receipt-logo"><img id="receiptLogo" src="<?= $logoSrc ?>" alt="Hiney's logo"></div>
    <pre class="receipt"><?= htmlspecialchars($receiptText) ?></pre>

    <div class="actions">
        <button type="button" id="closeBtn" onclick="window.close()">Close</button>
        <button type="button" id="browserBtn" onclick="browserPrint()">Browser print</button>
        <button type="button" class="primary" id="printBtn" onclick="confirmPrint()">Confirm &amp; Print</button>
    </div>
    <div class="status" id="status"></div>

    <script>
        // How printing works from the hosted site:
        // 1) Fetch the ESC/POS bytes from the server (order_receipt_bytes.php).
        // 2) Send them to local_print_bridge.php running on the shop PC, which
        //    copies them straight to the printer share (exact length, real logo,
        //    stops right after "Thank you").
        // 3) If the bridge fails, the real reason is shown; "Browser print" is a manual fallback.
        const BRIDGE_URL = 'http://127.0.0.1:8765/';
        const BRIDGE_TOKEN = 'dfshdvssbdidbksk'; // same as local_print_bridge.php

        async function confirmPrint() {
            var printBtn = document.getElementById('printBtn');
            var statusEl = document.getElementById('status');
            printBtn.disabled = true;
            printBtn.textContent = 'Printing…';
            statusEl.textContent = '';
            statusEl.className = 'status';
            try {
                const res = await fetch('order_receipt_bytes.php?id=<?= (int)$id ?>');
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Could not build receipt.');

                const ctl = new AbortController();
                const t = setTimeout(function() {
                    ctl.abort();
                }, 8000);
                const pr = await fetch(BRIDGE_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Bridge-Token': BRIDGE_TOKEN
                    },
                    body: JSON.stringify({
                        data: data.data
                    }),
                    signal: ctl.signal
                });
                clearTimeout(t);
                const out = await pr.json();
                if (!out.ok) throw new Error(out.error || 'Printer error.');

                statusEl.textContent = 'Sent to the printer.';
                statusEl.className = 'status ok';
                printBtn.textContent = 'Printed';
                setTimeout(function() {
                    window.close();
                }, 1200);
            } catch (err) {
                var msg = (err && err.name === 'AbortError') ? 'the bridge did not answer in time' :
                    (err instanceof TypeError) ? 'cannot reach the print bridge at ' + BRIDGE_URL + ' (is local_print_bridge.php running? did the browser block local network access for this site?)' :
                    (err && err.message ? err.message : 'unknown error');
                statusEl.textContent = 'Printing failed: ' + msg;
                statusEl.className = 'status err';
                printBtn.disabled = false;
                printBtn.textContent = 'Confirm & Print';
            }
        }

        // Plain browser print: needs a real printer driver for the logo/58mm paper,
        // and "Headers and footers" must be unticked in the print dialog.
        async function browserPrint() {
            var logo = document.getElementById('receiptLogo');
            if (logo && !logo.complete) {
                await new Promise(function(r) {
                    logo.onload = logo.onerror = r;
                });
            }
            var oldTitle = document.title;
            document.title = ' ';
            window.print();
            document.title = oldTitle;
        }
    </script>
</body>

</html>