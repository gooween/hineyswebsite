<?php
// ============================================================
// includes/receipt_pdf.php
// Shared receipt helpers for Hiney's / HATCH:
//   - hatch_receipt_load()  -> order + items + txn (same tables as orders.php)
//   - hatch_receipt_pdf()   -> PDF bytes (dependency-free; needs zlib, and GD for the logo)
//   - hatch_logo_escpos()   -> ESC/POS raster bytes of the logo for the 58mm thermal printer
// No schema changes, no Composer packages.
// ============================================================

const HATCH_LOGO_PATH = __DIR__ . '/../assets/images/hineys_logo.png';

// ── Payment method label ─────────────────────────────────────
/**
 * Human-readable payment method. For PayMongo orders the order row only says
 * "paymongo", so look at the actual channel PayMongo reported
 * (orders.paymongo_method, or transactions.payment_method) — GCash, Maya, QR Ph, Card...
 */
function hatch_payment_label(array $order, ?array $txn = null): string
{
    $map = [
        'cod' => 'Cash on Delivery',
        'gcash' => 'GCash',
        'paymaya' => 'Maya',
        'maya' => 'Maya',
        'qrph' => 'QR Ph',
        'qr_ph' => 'QR Ph',
        'card' => 'Credit/Debit Card',
        'grab_pay' => 'GrabPay',
        'grabpay' => 'GrabPay',
        'billease' => 'BillEase',
        'dob' => 'Online Banking',
        'dob_ubp' => 'UnionBank Online',
        'brankas_bdo' => 'BDO Online',
        'brankas_landbank' => 'Landbank Online',
        'brankas_metrobank' => 'Metrobank Online',
    ];
    $norm = fn($v) => strtolower(trim((string)$v));
    $method = $norm($order['payment_method'] ?? '');
    $candidates = [$norm($order['paymongo_method'] ?? ''), $norm($txn['payment_method'] ?? ''), $method];
    foreach ($candidates as $c) {
        if ($c === '' || $c === 'paymongo') continue;
        return $map[$c] ?? ucwords(str_replace('_', ' ', $c));
    }
    return $method === 'paymongo' ? 'PayMongo (online)' : strtoupper($method);
}

// ── Data ─────────────────────────────────────────────────────
/**
 * Loads everything a receipt needs. Pass $userId to restrict to that
 * customer's own orders (customer side); pass null for admin use.
 */
function hatch_receipt_load(mysqli $conn, int $orderId, ?int $userId = null): ?array
{
    $sql = "SELECT o.id, o.status, o.payment_status, o.payment_method, o.paymongo_method,
                   o.total_amount, o.delivery_fee, o.delivery_address, o.created_at,
                   u.full_name, u.phone, u.email
            FROM orders o JOIN users u ON u.id = o.user_id
            WHERE o.id = ?" . ($userId !== null ? " AND o.user_id = ?" : "") . " LIMIT 1";
    $st = $conn->prepare($sql);
    if ($userId !== null) $st->bind_param('ii', $orderId, $userId);
    else $st->bind_param('i', $orderId);
    $st->execute();
    $order = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$order) return null;

    $st = $conn->prepare("SELECT oi.quantity, oi.unit_price, oi.subtotal, p.name, p.unit
                          FROM order_items oi JOIN products p ON p.id = oi.product_id
                          WHERE oi.order_id = ? ORDER BY oi.id ASC");
    $st->bind_param('i', $orderId);
    $st->execute();
    $items = [];
    $itemsSubtotal = 0.0;
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $qty = (int)$r['quantity'];
        $sub = (float)$r['subtotal'];
        $unit = $r['unit_price'] !== null ? (float)$r['unit_price'] : ($qty > 0 ? $sub / $qty : 0.0);
        $items[] = ['name' => $r['name'], 'unit_label' => $r['unit'] ?? '', 'qty' => $qty, 'unit_price' => $unit, 'subtotal' => $sub];
        $itemsSubtotal += $sub;
    }
    $st->close();

    $st = $conn->prepare("SELECT reference_no, payment_method, transaction_date FROM transactions WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('i', $orderId);
    $st->execute();
    $txn = $st->get_result()->fetch_assoc() ?: null;
    $st->close();

    $method = hatch_payment_label($order, $txn);

    return [
        'transaction_no' => 'HATCH-ORD-' . str_pad((string)$order['id'], 6, '0', STR_PAD_LEFT),
        'order_no'       => '#' . str_pad((string)$order['id'], 4, '0', STR_PAD_LEFT),
        'order'          => $order,
        'items'          => $items,
        'items_subtotal' => $itemsSubtotal,
        'txn'            => $txn,
        'method'         => $method,
    ];
}

// ── Minimal PDF writer ───────────────────────────────────────
class HatchPdf
{
    public float $w, $h;
    private array $pages = [];
    private array $images = [];   // [jpegBytes, wPx, hPx]
    private string $buf = '';
    // Helvetica / Helvetica-Bold advance widths for ASCII 32..126 (1000 units/em)
    private static array $reg = [32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private static array $bold = [32 => 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    public function __construct(float $w = 595.28, float $h = 841.89)
    {
        $this->w = $w;
        $this->h = $h;
    }
    public function addPage(): void
    {
        $this->pages[] = $this->buf;
        $this->buf = '';
    }
    private static function enc(string $s): string
    {
        $o = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $o === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $o;
    }
    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $t = $bold ? self::$bold : self::$reg;
        $w = 0;
        foreach (str_split(self::enc($s)) as $c) $w += $t[ord($c)] ?? 556;
        return $w * $size / 1000;
    }
    public function color(float $r, float $g, float $b, bool $stroke = false): void
    {
        $this->buf .= sprintf("%.3F %.3F %.3F %s\n", $r, $g, $b, $stroke ? 'RG' : 'rg');
    }
    public function hex(string $hex, bool $stroke = false): void
    {
        $hex = ltrim($hex, '#');
        $this->color(hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255, $stroke);
    }
    /** $align: L, R or C relative to $x. $y is the baseline, measured from the TOP of the page. */
    public function text(float $x, float $y, string $s, float $size = 9, bool $bold = false, string $align = 'L'): void
    {
        $w = $this->textWidth($s, $size, $bold);
        if ($align === 'R') $x -= $w;
        elseif ($align === 'C') $x -= $w / 2;
        $e = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::enc($s));
        $this->buf .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $x, $this->h - $y, $e);
    }
    public function wrap(string $s, float $maxW, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/', trim($s)) as $para) {
            $cur = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && $this->textWidth($try, $size, $bold) > $maxW) {
                    $lines[] = $cur;
                    $cur = $word;
                } else {
                    $cur = $try;
                }
                while (mb_strlen($cur) > 1 && $this->textWidth($cur, $size, $bold) > $maxW) { // very long single word
                    $k = mb_strlen($cur) - 1;
                    while ($k > 1 && $this->textWidth(mb_substr($cur, 0, $k), $size, $bold) > $maxW) $k--;
                    $lines[] = mb_substr($cur, 0, $k);
                    $cur = mb_substr($cur, $k);
                }
            }
            $lines[] = $cur;
        }
        return $lines ?: [''];
    }
    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.6): void
    {
        $this->buf .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $width, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }
    public function rect(float $x, float $y, float $w, float $h): void
    {
        $this->buf .= sprintf("%.2F %.2F %.2F %.2F re f\n", $x, $this->h - $y - $h, $w, $h);
    }
    /** Draws an image registered with addJpeg(); $y is the TOP edge. */
    public function image(int $idx, float $x, float $y, float $w, float $h): void
    {
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n", $w, $h, $x, $this->h - $y - $h, $idx + 1);
    }
    public function addJpeg(string $bytes, int $wPx, int $hPx): int
    {
        $this->images[] = [$bytes, $wPx, $hPx];
        return count($this->images) - 1;
    }

    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->buf;
        $nImg = count($this->images);
        $firstImg = 5;                       // 1 catalog, 2 pages, 3 F1, 4 F2, 5.. images
        $firstPage = $firstImg + $nImg;      // then (content, page) pairs
        $infoId = $firstPage + count($pages) * 2;

        $kids = [];
        foreach ($pages as $i => $_) $kids[] = ($firstPage + $i * 2 + 1) . ' 0 R';
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $xo = '';
        foreach ($this->images as $i => [$bytes, $wp, $hp]) {
            $objs[$firstImg + $i] = "<< /Type /XObject /Subtype /Image /Width $wp /Height $hp /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($bytes) . " >>\nstream\n" . $bytes . "\nendstream";
            $xo .= '/Im' . ($i + 1) . ' ' . ($firstImg + $i) . ' 0 R ';
        }
        foreach ($pages as $i => $content) {
            $c = gzcompress($content, 6);
            $cid = $firstPage + $i * 2;
            $objs[$cid] = '<< /Filter /FlateDecode /Length ' . strlen($c) . " >>\nstream\n" . $c . "\nendstream";
            $objs[$cid + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << %s>> >> >>', $this->w, $this->h, $cid, $xo);
        }
        $objs[$infoId] = '<< /Title (HATCH Order Receipt) /Producer (HATCH) >>';

        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($objs as $n => $body) {
            $offs[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) $pdf .= sprintf("%010d 00000 n \n", $offs[$n]);
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}

// ── Logo helpers (GD) ────────────────────────────────────────
function hatch_logo_gd(string $path)
{
    if (!function_exists('imagecreatefrompng') || !is_file($path)) return null;
    $img = @imagecreatefrompng($path);
    return $img ?: null;
}

/** Returns [jpegBytes, w, h] with transparency flattened onto white, or null if unavailable. */
function hatch_logo_jpeg(string $path = HATCH_LOGO_PATH, int $maxW = 480): ?array
{
    $src = hatch_logo_gd($path);
    if (!$src) return null;
    $sw = imagesx($src);
    $sh = imagesy($src);
    $w = min($sw, $maxW);
    $h = max(1, (int)round($sh * $w / $sw));
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
    ob_start();
    imagejpeg($dst, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    return [$bytes, $w, $h];
}

/**
 * ESC/POS "GS v 0" raster bytes of the logo, centred on a 58mm (384-dot) line,
 * followed by a line feed. Returns '' if the logo/GD isn't available.
 */
function hatch_logo_escpos(string $path = HATCH_LOGO_PATH, int $logoDots = 200, int $lineDots = 384): string
{
    $src = hatch_logo_gd($path);
    if (!$src) return '';
    $sw = imagesx($src);
    $sh = imagesy($src);
    $w = $logoDots;
    $h = max(1, (int)round($sh * $w / $sw));
    $canvas = imagecreatetruecolor($lineDots, $h);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $src, intdiv($lineDots - $w, 2), 0, 0, 0, $w, $h, $sw, $sh);
    $bytesPerRow = intdiv($lineDots, 8);
    $data = '';
    for ($y = 0; $y < $h; $y++) {
        for ($bx = 0; $bx < $bytesPerRow; $bx++) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) {
                $rgb = imagecolorat($canvas, $bx * 8 + $b, $y);
                $lum = 0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255);
                if ($lum < 140) $byte |= (0x80 >> $b);
            }
            $data .= chr($byte);
        }
    }
    imagedestroy($src);
    imagedestroy($canvas);
    return "\x1B\x61\x00" . "\x1D\x76\x30\x00" . pack('v', $bytesPerRow) . pack('v', $h) . $data . "\r\n";
}

// ── The customer-facing PDF receipt ──────────────────────────
function hatch_receipt_pdf(array $d, string $logoPath = HATCH_LOGO_PATH): string
{
    $o = $d['order'];
    $pdf = new HatchPdf(595.28, 841.89);   // A4 portrait
    $M = 48;                                 // margin
    $R = $pdf->w - $M;                       // right edge
    $money = fn($n) => 'PHP ' . number_format((float)$n, 2);
    $green = '#15803d';
    $ink = '#1f2937';
    $muted = '#6b7280';

    // Logo + brand
    $y = 30;
    if ($lg = hatch_logo_jpeg($logoPath)) {
        $lh = 62;
        $lw = $lh * $lg[1] / $lg[2];
        if ($lw > 200) {
            $lw = 200;
            $lh = $lw * $lg[2] / $lg[1];
        }
        $idx = $pdf->addJpeg($lg[0], $lg[1], $lg[2]);
        $pdf->image($idx, ($pdf->w - $lw) / 2, $y, $lw, $lh);
        $y += $lh + 16;
    } else {
        $y += 10;
    }
    $pdf->hex($green);
    $pdf->text($pdf->w / 2, $y, 'HATCH', 22, true, 'C');
    $y += 16;
    $pdf->hex($ink);
    $pdf->text($pdf->w / 2, $y, "Hiney's Automated Tracking Commerce and Hub", 10.5, true, 'C');
    $y += 13;
    $pdf->hex($muted);
    $pdf->text($pdf->w / 2, $y, "Hiney's Eggs & Live Chicken Business  |  Loreto Cortes, Bohol", 8.5, false, 'C');
    $y += 14;
    $pdf->hex($green, true);
    $pdf->line($M, $y, $R, $y, 1.4);
    $y += 20;
    $pdf->hex($green);
    $pdf->text($pdf->w / 2, $y, 'ORDER RECEIPT', 12, true, 'C');
    $y += 20;

    // Info block
    $rows = [
        ['Transaction No.', $d['transaction_no']],
        ['Order', $d['order_no']],
        ['Date', date('M j, Y  g:i A', strtotime($o['created_at']))],
        ['Customer', $o['full_name']],
    ];
    $contact = $o['phone'] ?: $o['email'];
    if ($contact) $rows[] = ['Contact', $contact];
    $rows[] = ['Payment', $d['method'] . ' (' . strtoupper($o['payment_status']) . ')'];
    $rows[] = ['Order Status', strtoupper(str_replace('_', ' ', $o['status']))];
    if (!empty($d['txn']['transaction_date'])) $rows[] = ['Paid At', date('M j, Y  g:i A', strtotime($d['txn']['transaction_date']))];
    if (!empty($d['txn']['reference_no'])) $rows[] = ['Reference No.', $d['txn']['reference_no']];
    foreach ($rows as [$k, $v]) {
        $pdf->hex($muted);
        $pdf->text($M, $y, $k, 8.5);
        $pdf->hex($ink);
        $pdf->text($M + 90, $y, (string)$v, 9, true);
        $y += 14;
    }
    if (!empty($o['delivery_address'])) {
        $pdf->hex($muted);
        $pdf->text($M, $y, 'Deliver To', 8.5);
        $pdf->hex($ink);
        foreach ($pdf->wrap($o['delivery_address'], $R - $M - 90, 9, true) as $ln) {
            $pdf->text($M + 90, $y, $ln, 9, true);
            $y += 12;
        }
        $y += 2;
    }
    $y += 10;

    // Items table
    $cQty = $R - 150;
    $cUnit = $R - 78;
    $drawHead = function () use (&$y, $pdf, $M, $R, $cQty, $cUnit) {
        $pdf->hex('#f0fdf4');
        $pdf->rect($M, $y - 11, $R - $M, 18);
        $pdf->hex('#15803d');
        $pdf->text($M + 6, $y + 1, 'ITEM', 8, true);
        $pdf->text($cQty, $y + 1, 'QTY', 8, true, 'R');
        $pdf->text($cUnit, $y + 1, 'PRICE', 8, true, 'R');
        $pdf->text($R - 6, $y + 1, 'AMOUNT', 8, true, 'R');
        $y += 22;
    };
    $drawHead();
    foreach ($d['items'] as $it) {
        $nameLines = $pdf->wrap($it['name'], $cQty - $M - 40, 9, true);
        $need = count($nameLines) * 12 + ($it['unit_label'] ? 11 : 0) + 8;
        if ($y + $need > $pdf->h - 60) {         // rows may use the page down to the footer zone
            $pdf->addPage();
            $y = 40;
            $drawHead();
        }
        $top = $y;
        $pdf->hex($ink);
        foreach ($nameLines as $ln) {
            $pdf->text($M + 6, $y, $ln, 9, true);
            $y += 12;
        }
        if ($it['unit_label']) {
            $pdf->hex($muted);
            $pdf->text($M + 6, $y - 1, (string)$it['unit_label'], 7.5);
            $y += 10;
        }
        $pdf->hex($ink);
        $pdf->text($cQty, $top, (string)$it['qty'], 9, false, 'R');
        $pdf->text($cUnit, $top, number_format($it['unit_price'], 2), 9, false, 'R');
        $pdf->text($R - 6, $top, number_format($it['subtotal'], 2), 9, true, 'R');
        $y += 3;
        $pdf->hex('#e5e7eb', true);
        $pdf->line($M, $y, $R, $y, 0.4);
        $y += 11;
    }

    // Totals
    if ($y > $pdf->h - 135) {                 // totals block (~75pt) + footer must fit
        $pdf->addPage();
        $y = 50;
    }
    $y += 4;
    $pdf->hex($muted);
    $pdf->text($R - 110, $y, 'Subtotal', 9, false, 'R');
    $pdf->hex($ink);
    $pdf->text($R - 6, $y, $money($d['items_subtotal']), 9, false, 'R');
    $y += 14;
    $pdf->hex($muted);
    $pdf->text($R - 110, $y, 'Delivery Fee', 9, false, 'R');
    $pdf->hex($ink);
    $pdf->text($R - 6, $y, $money($o['delivery_fee'] ?? 0), 9, false, 'R');
    $y += 10;
    $pdf->hex($green, true);
    $pdf->line($R - 190, $y, $R, $y, 1);
    $y += 18;
    $pdf->hex($green);
    $pdf->text($R - 110, $y, 'TOTAL', 11, true, 'R');
    $pdf->text($R - 6, $y, $money($o['total_amount']), 12, true, 'R');
    $y += 22;

    $paid = $o['payment_status'] === 'paid';
    $pdf->hex($paid ? '#15803d' : '#b91c1c');
    $pdf->text($R - 6, $y, $paid ? 'PAID' : 'PAYMENT PENDING', 10, true, 'R');

    // Footer
    $fy = $pdf->h - 44;
    $pdf->hex('#d1d5db', true);
    $pdf->line($M, $fy - 14, $R, $fy - 14, 0.5);
    $pdf->hex($ink);
    $pdf->text($pdf->w / 2, $fy, 'Thank you for your order!', 10, true, 'C');
    $pdf->hex($muted);
    $pdf->text($pdf->w / 2, $fy + 12, 'HATCH - system-generated receipt. Generated ' . date('M j, Y g:i A'), 7.5, false, 'C');

    return $pdf->output();
}
