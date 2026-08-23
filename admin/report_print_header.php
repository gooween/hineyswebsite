<?php
// ============================================================
// HATCH — Hiney's Automated Tracking Commerce and Hub
// File: admin/report_print_header.php
//
// Shared print-view chrome for the report print pages
// (sales / inventory / orders). Included at the top of each
// *_print.php file AFTER it has computed its data.
//
// The including file must define, before including this:
//   $printTitle    e.g. "Sales Report"
//   $printSubtitle e.g. "Jan 1, 2026 - Jan 31, 2026"  (context line)
//   $printMeta     (optional) array of ['label'=>, 'value'=>] chips
//
// This file opens <html>...<body> and the report letterhead,
// then the including file renders its tables, then includes
// report_print_footer.php to close everything.
//
// Design: plain black-and-white business document. No colour,
// no shading, no rounded corners — photocopy/fax friendly.
// ============================================================

if (!isset($printTitle))    $printTitle    = 'Report';
if (!isset($printSubtitle)) $printSubtitle = '';
if (!isset($printMeta))     $printMeta     = [];

// Try to pull the business name/address from settings, with fallbacks
function printSetting(mysqli $conn, string $key, string $default): string
{
    $k = $conn->real_escape_string($key);
    $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key='{$k}' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $v = trim($row['setting_value'] ?? '');
        if ($v !== '') return $v;
    }
    return $default;
}

$bizName    = printSetting($conn, 'business_name', 'HATCH — Hiney\'s Automated Tracking Commerce and Hub');
$bizAddress = printSetting($conn, 'pickup_address', 'Loreto, Cortes, Bohol');

// Who generated it
$genBy = '';
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $ur  = $conn->query("SELECT full_name FROM users WHERE id={$uid} LIMIT 1");
    if ($ur && $urow = $ur->fetch_assoc()) $genBy = $urow['full_name'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($printTitle) ?> — <?= htmlspecialchars($bizName) ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Times New Roman", Georgia, "Liberation Serif", serif;
            color: #000;
            background: #d9d9d9;
            line-height: 1.45;
            font-size: 12px;
        }

        /* The printable sheet */
        .sheet {
            background: #fff;
            max-width: 900px;
            margin: 24px auto;
            padding: 40px 44px 44px;
            border: 1px solid #bbb;
        }

        /* ── Letterhead ── */
        .rp-head {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .rp-brand-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #000;
            letter-spacing: 0.01em;
            line-height: 1.2;
        }

        .rp-brand-addr {
            font-size: 0.82rem;
            color: #000;
            margin-top: 4px;
        }

        .rp-title-block {
            margin-top: 12px;
        }

        .rp-report-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }

        .rp-report-sub {
            font-size: 0.85rem;
            color: #000;
            margin-top: 2px;
        }

        .rp-generated {
            font-size: 0.72rem;
            color: #333;
            margin-top: 6px;
            font-style: italic;
        }

        /* ── Meta row (plain key: value list) ── */
        .rp-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 28px;
            padding: 10px 0 12px;
            margin-bottom: 16px;
            border-bottom: 1px solid #000;
            justify-content: center;
        }

        .rp-meta-item {
            font-size: 0.78rem;
            color: #000;
        }

        .rp-meta-item .lbl {
            font-weight: 700;
            margin-right: 5px;
        }

        .rp-meta-item .val {
            font-weight: 400;
        }

        /* ── Summary figures (plain bordered table, not cards) ── */
        .rp-summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 22px;
        }

        .rp-summary td {
            border: 1px solid #000;
            padding: 9px 12px;
            text-align: center;
            width: 25%;
        }

        .rp-summary .s-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #000;
            margin-bottom: 4px;
        }

        .rp-summary .s-value {
            font-size: 1.15rem;
            font-weight: 700;
            color: #000;
            line-height: 1.1;
        }

        .rp-summary .s-sub {
            font-size: 0.66rem;
            color: #333;
            margin-top: 3px;
        }

        /* ── Section titles ── */
        .rp-section-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #000;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 22px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #000;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
        }

        .rp-section-title .count {
            font-size: 0.72rem;
            font-weight: 400;
            color: #333;
            text-transform: none;
            letter-spacing: 0;
        }

        /* ── Tables ── */
        table.rp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            margin-bottom: 8px;
        }

        table.rp-table thead th {
            background: #fff;
            color: #000;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 7px 10px;
            text-align: left;
            white-space: nowrap;
            border-bottom: 1.5px solid #000;
            border-top: 1.5px solid #000;
        }

        table.rp-table thead th.num {
            text-align: right;
        }

        table.rp-table tbody td {
            padding: 6px 10px;
            border-bottom: 1px solid #bbb;
            vertical-align: middle;
            color: #000;
        }

        table.rp-table tbody td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        table.rp-table tbody tr:last-child td {
            border-bottom: 1.5px solid #000;
        }

        table.rp-table tfoot td {
            padding: 8px 10px;
            font-weight: 700;
            border-bottom: 1.5px solid #000;
            font-size: 0.82rem;
            color: #000;
        }

        table.rp-table tfoot td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        /* Status text — plain, no coloured pills */
        .rp-pill,
        .pill-green,
        .pill-red,
        .pill-amber,
        .pill-blue,
        .pill-gray,
        .pill-purple,
        .pill-orange {
            display: inline;
            padding: 0;
            border-radius: 0;
            background: none;
            color: #000;
            font-size: inherit;
            font-weight: 600;
        }

        .text-up {
            color: #000;
            font-weight: 700;
        }

        .text-down {
            color: #000;
            font-weight: 700;
        }

        .muted {
            color: #333;
        }

        /* ── Signature block ── */
        .rp-signatures {
            display: flex;
            gap: 60px;
            margin-top: 48px;
            padding-top: 8px;
        }

        .rp-sig {
            flex: 1;
        }

        .rp-sig-line {
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 40px;
        }

        .rp-sig-role {
            font-size: 0.74rem;
            color: #000;
        }

        .rp-sig-name {
            font-size: 0.82rem;
            font-weight: 700;
            color: #000;
        }

        /* ── Footer note ── */
        .rp-foot {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #000;
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: #333;
        }

        /* ── Floating action bar (screen only) ── */
        .rp-actions {
            position: fixed;
            top: 16px;
            right: 16px;
            display: flex;
            gap: 8px;
            z-index: 50;
        }

        .rp-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 16px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid #000;
            font-family: Arial, Helvetica, sans-serif;
        }

        .rp-btn-print {
            background: #000;
            color: #fff;
        }

        .rp-btn-print:hover {
            background: #333;
        }

        .rp-btn-close {
            background: #fff;
            color: #000;
        }

        .rp-btn-close:hover {
            background: #eee;
        }

        .rp-empty {
            padding: 26px 20px;
            text-align: center;
            color: #333;
            border: 1px solid #000;
            margin: 8px 0;
            font-style: italic;
        }

        /* ══ PRINT RULES ══ */
        @media print {
            @page {
                size: A4;
                margin: 14mm 12mm;
            }

            body {
                background: #fff;
                font-size: 11px;
            }

            .sheet {
                border: none;
                margin: 0;
                max-width: 100%;
                padding: 0;
            }

            .rp-actions {
                display: none !important;
            }

            /* keep rows and sections from splitting badly */
            table.rp-table {
                page-break-inside: auto;
            }

            table.rp-table thead {
                display: table-header-group;
            }

            /* repeat header each page */
            table.rp-table tr {
                page-break-inside: avoid;
            }

            .rp-section-title {
                page-break-after: avoid;
            }

            .rp-summary {
                page-break-inside: avoid;
            }

            .rp-signatures {
                page-break-inside: avoid;
            }

            /* force plain black/white on print */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>

<body>

    <div class="rp-actions">
        <button class="rp-btn rp-btn-print" onclick="window.print()">Print / Save as PDF</button>
        <button class="rp-btn rp-btn-close" onclick="window.close()">Close</button>
    </div>

    <div class="sheet">

        <!-- Letterhead -->
        <div class="rp-head">
            <div class="rp-brand-name"><?= htmlspecialchars($bizName) ?></div>
            <div class="rp-brand-addr"><?= htmlspecialchars($bizAddress) ?></div>
            <div class="rp-title-block">
                <div class="rp-report-title"><?= htmlspecialchars($printTitle) ?></div>
                <?php if ($printSubtitle): ?><div class="rp-report-sub"><?= htmlspecialchars($printSubtitle) ?></div><?php endif; ?>

            </div>
        </div>

        <?php if (!empty($printMeta)): ?>
            <div class="rp-meta">
                <?php foreach ($printMeta as $m): ?>
                    <div class="rp-meta-item"><span class="lbl"><?= htmlspecialchars($m['label']) ?>:</span><span class="val"><?= htmlspecialchars($m['value']) ?></span></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>