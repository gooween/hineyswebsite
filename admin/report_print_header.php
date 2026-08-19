<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_print_header.php
//
// Shared print-view chrome for the report print pages
// (sales / inventory / orders). Included at the top of each
// *_print.php file AFTER it has computed its data.
//
// The including file must define, before including this:
//   $printTitle    e.g. "Sales Report"
//   $printSubtitle e.g. "Jan 1, 2026 – Jan 31, 2026"  (context line)
//   $printMeta     (optional) array of ['label'=>, 'value'=>] chips
//
// This file opens <html>…<body> and the report letterhead,
// then the including file renders its tables, then includes
// report_print_footer.php to close everything.
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

$bizName    = printSetting($conn, 'business_name', "Hiney's Eggs & Live Chicken Business");
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
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: #111827;
            background: #f3f4f6;
            line-height: 1.5;
            font-size: 12px;
        }

        /* The printable sheet */
        .sheet {
            background: #fff;
            max-width: 1000px;
            margin: 20px auto;
            padding: 32px 36px 40px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.12);
        }

        /* ── Letterhead ── */
        .rp-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            border-bottom: 3px solid #e67e22;
            padding-bottom: 16px;
            margin-bottom: 4px;
            gap: 20px;
        }

        .rp-brand-name {
            font-size: 1.55rem;
            font-weight: 800;
            color: #1a1a2e;
            letter-spacing: -0.02em;
        }

        .rp-brand-addr {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 2px;
        }

        .rp-brand-tag {
            font-size: 0.72rem;
            color: #9ca3af;
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
        }

        .rp-title-block {
            text-align: right;
            flex-shrink: 0;
        }

        .rp-report-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: #e67e22;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .rp-report-sub {
            font-size: 0.82rem;
            color: #374151;
            margin-top: 3px;
            font-weight: 600;
        }

        .rp-generated {
            font-size: 0.72rem;
            color: #9ca3af;
            margin-top: 8px;
        }

        /* ── Meta chips row ── */
        .rp-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 20px;
            padding: 12px 0 4px;
            margin-bottom: 18px;
            border-bottom: 1px solid #e5e7eb;
        }

        .rp-meta-item {
            font-size: 0.78rem;
            color: #374151;
        }

        .rp-meta-item .lbl {
            color: #9ca3af;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.68rem;
            letter-spacing: 0.05em;
            margin-right: 5px;
        }

        .rp-meta-item .val {
            font-weight: 700;
            color: #111827;
        }

        /* ── KPI summary strip ── */
        .rp-kpis {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 22px;
        }

        .rp-kpi {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px 14px;
            background: #fafafa;
        }

        .rp-kpi .k-label {
            font-size: 0.66rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .rp-kpi .k-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: #111827;
            line-height: 1;
        }

        .rp-kpi .k-sub {
            font-size: 0.68rem;
            color: #9ca3af;
            margin-top: 4px;
        }

        .rp-kpi.accent-green .k-value {
            color: #059669;
        }

        .rp-kpi.accent-red .k-value {
            color: #dc2626;
        }

        .rp-kpi.accent-amber .k-value {
            color: #d97706;
        }

        .rp-kpi.accent-blue .k-value {
            color: #2563eb;
        }

        /* ── Section titles ── */
        .rp-section-title {
            font-size: 0.92rem;
            font-weight: 800;
            color: #1a1a2e;
            margin: 24px 0 10px;
            padding-bottom: 6px;
            border-bottom: 2px solid #1a1a2e;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .rp-section-title .count {
            font-size: 0.72rem;
            font-weight: 600;
            color: #9ca3af;
        }

        /* ── Tables ── */
        table.rp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.78rem;
            margin-bottom: 6px;
        }

        table.rp-table thead th {
            background: #1a1a2e;
            color: #fff;
            font-size: 0.66rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 8px 10px;
            text-align: left;
            white-space: nowrap;
        }

        table.rp-table thead th.num {
            text-align: right;
        }

        table.rp-table tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        table.rp-table tbody td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        table.rp-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        table.rp-table tbody tr:last-child td {
            border-bottom: 1px solid #d1d5db;
        }

        table.rp-table tfoot td {
            padding: 9px 10px;
            font-weight: 800;
            background: #fef3e8;
            border-top: 2px solid #e67e22;
            font-size: 0.82rem;
        }

        table.rp-table tfoot td.num {
            text-align: right;
        }

        .rp-pill {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 10px;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .pill-green {
            background: #d1fae5;
            color: #065f46;
        }

        .pill-red {
            background: #fee2e2;
            color: #991b1b;
        }

        .pill-amber {
            background: #fef3c7;
            color: #92400e;
        }

        .pill-blue {
            background: #dbeafe;
            color: #1e40af;
        }

        .pill-gray {
            background: #f3f4f6;
            color: #4b5563;
        }

        .pill-purple {
            background: #ede9fe;
            color: #5b21b6;
        }

        .pill-orange {
            background: #ffedd5;
            color: #9a3412;
        }

        .text-up {
            color: #dc2626;
            font-weight: 700;
        }

        .text-down {
            color: #059669;
            font-weight: 700;
        }

        .muted {
            color: #9ca3af;
        }

        /* ── Signature block ── */
        .rp-signatures {
            display: flex;
            gap: 60px;
            margin-top: 40px;
            padding-top: 8px;
        }

        .rp-sig {
            flex: 1;
        }

        .rp-sig-line {
            border-top: 1.5px solid #374151;
            padding-top: 5px;
            margin-top: 34px;
        }

        .rp-sig-role {
            font-size: 0.72rem;
            color: #6b7280;
        }

        .rp-sig-name {
            font-size: 0.82rem;
            font-weight: 700;
            color: #111827;
        }

        /* ── Footer note ── */
        .rp-foot {
            margin-top: 28px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            font-size: 0.68rem;
            color: #9ca3af;
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
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-family: inherit;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .rp-btn-print {
            background: #e67e22;
            color: #fff;
        }

        .rp-btn-print:hover {
            background: #cf6d17;
        }

        .rp-btn-close {
            background: #fff;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }

        .rp-btn-close:hover {
            background: #f9fafb;
            color: #111827;
        }

        .rp-empty {
            padding: 40px 20px;
            text-align: center;
            color: #9ca3af;
            border: 1px dashed #d1d5db;
            border-radius: 8px;
            margin: 10px 0;
        }

        /* ══ PRINT RULES ══ */
        @media print {
            @page {
                size: A4;
                margin: 12mm 10mm;
            }

            body {
                background: #fff;
                font-size: 11px;
            }

            .sheet {
                box-shadow: none;
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

            .rp-kpis {
                page-break-inside: avoid;
            }

            .rp-signatures {
                page-break-inside: avoid;
            }

            /* force accurate colors on print */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>

<body>

    <div class="rp-actions">
        <button class="rp-btn rp-btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
        <button class="rp-btn rp-btn-close" onclick="window.close()">✕ Close</button>
    </div>

    <div class="sheet">

        <!-- Letterhead -->
        <div class="rp-head">
            <div>
                <div class="rp-brand-name"><?= htmlspecialchars($bizName) ?></div>
                <div class="rp-brand-addr"><?= htmlspecialchars($bizAddress) ?></div>
                <div class="rp-brand-tag">Fresh Eggs &amp; Live Chicken</div>
            </div>
            <div class="rp-title-block">
                <div class="rp-report-title"><?= htmlspecialchars($printTitle) ?></div>
                <?php if ($printSubtitle): ?><div class="rp-report-sub"><?= htmlspecialchars($printSubtitle) ?></div><?php endif; ?>
                <div class="rp-generated">Generated <?= date('M j, Y \a\t g:i A') ?><?= $genBy ? ' by ' . htmlspecialchars($genBy) : '' ?></div>
            </div>
        </div>

        <?php if (!empty($printMeta)): ?>
            <div class="rp-meta">
                <?php foreach ($printMeta as $m): ?>
                    <div class="rp-meta-item"><span class="lbl"><?= htmlspecialchars($m['label']) ?></span><span class="val"><?= htmlspecialchars($m['value']) ?></span></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>