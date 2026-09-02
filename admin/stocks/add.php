<?php
session_start();
require_once '../../config/db.php';
requireAdmin();

$activePage = 'stocks_add';

$products = [];
$res = $conn->query("
    SELECT p.id, p.name, p.unit, c.name AS category
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    ORDER BY c.name ASC, p.name ASC
");
while ($row = $res->fetch_assoc()) $products[] = $row;

$tree = [];
foreach ($products as $p) $tree[$p['category']][$p['unit']][] = $p;

function getExpiry($category, $unit)
{
    $cat = strtolower($category);
    $u   = strtolower($unit);
    if (str_contains($cat, 'crack'))  return 2;
    if (str_contains($cat, 'egg'))    return 21;
    if (str_contains($u, 'alive'))    return 1;
    if (str_contains($u, 'process')) return 3;
    if (str_contains($cat, 'chicken')) return 1;
    return 21;
}

function generateBatchCode($product_name, $unit, $category, $conn)
{
    $date = date('Ymd');
    $cat  = strtolower($category);
    $u    = strtolower($unit);
    $name = strtoupper(preg_replace('/\s+/', '', $product_name));
    if (str_contains($cat, 'crack'))     $prefix = "CRACKED";
    elseif (str_contains($u, 'tray'))    $prefix = "TRAY-{$name}";
    elseif (str_contains($u, 'piece'))   $prefix = "EGG-{$name}";
    elseif (str_contains($u, 'alive'))   $prefix = "CHK-ALIVE";
    elseif (str_contains($u, 'process')) $prefix = "CHK-PROC";
    else                                 $prefix = strtoupper(substr($name, 0, 8));
    $seq = 1;
    do {
        $candidate     = "{$prefix}-{$date}-" . str_pad($seq, 3, '0', STR_PAD_LEFT);
        $safeCandidate = $conn->real_escape_string($candidate);
        $check         = $conn->query("SELECT id FROM stock_batches WHERE batch_code = '{$safeCandidate}' LIMIT 1");
        if ($check && $check->num_rows > 0) $seq++;
        else break;
    } while (true);
    return $candidate;
}

function isTray($unit)
{
    return str_contains(strtolower($unit), 'tray');
}

$successBatches = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $notes      = clean($_POST['notes'] ?? '', $conn);
    $uid        = (int)$_SESSION['user_id'];

    $prow = null;
    foreach ($products as $p) {
        if ($p['id'] == $product_id) {
            $prow = $p;
            break;
        }
    }

    if (!$prow) {
        $error = 'Please select a valid product.';
    } else {
        $expiry_days = getExpiry($prow['category'], $prow['unit']);
        $expires_at  = date('Y-m-d', strtotime("+{$expiry_days} days"));
        $unit        = $conn->real_escape_string($prow['unit']);
        $notesEsc    = $conn->real_escape_string($notes);

        if (isTray($prow['unit'])) {
            // ── Option B: ONE batch per add, remaining = number of trays ──
            $tray_count = (int)($_POST['tray_count'] ?? 0);
            if ($tray_count <= 0) {
                $error = 'Please enter how many trays you are adding.';
            } else {
                $bc = $conn->real_escape_string(generateBatchCode($prow['name'], $prow['unit'], $prow['category'], $conn));
                $conn->query("INSERT INTO stock_batches (batch_code,product_id,quantity,remaining,unit,notes,expires_at,created_by,created_at,status)
                    VALUES ('{$bc}',{$product_id},{$tray_count},{$tray_count},'{$unit}','{$notesEsc}','{$expires_at}',{$uid},NOW(),'active')");
                if ($conn->insert_id) {
                    $conn->query("UPDATE inventory SET quantity=(SELECT COALESCE(SUM(remaining),0) FROM stock_batches WHERE product_id={$product_id} AND status='active'),last_updated=NOW() WHERE product_id={$product_id}");
                    $conn->query("INSERT INTO inventory_logs (product_id,type,quantity,reason,created_by,created_at) VALUES ({$product_id},'in',{$tray_count},'Batch {$bc} — {$tray_count} tray(s)',{$uid},NOW())");
                    $successBatches = ['codes' => [$bc], 'product' => $prow['name'], 'category' => $prow['category'], 'unit' => $prow['unit'], 'tray_count' => $tray_count, 'expires_at' => $expires_at, 'expiry_days' => $expiry_days];
                } else {
                    $error = 'Failed to save batch. Try again.';
                }
            }
        } else {
            $quantity = (int)($_POST['quantity'] ?? 0);
            if ($quantity <= 0) {
                $error = 'Quantity must be greater than 0.';
            } else {
                $bc = $conn->real_escape_string(generateBatchCode($prow['name'], $prow['unit'], $prow['category'], $conn));
                $conn->query("INSERT INTO stock_batches (batch_code,product_id,quantity,remaining,unit,notes,expires_at,created_by,created_at,status)
                    VALUES ('{$bc}',{$product_id},{$quantity},{$quantity},'{$unit}','{$notesEsc}','{$expires_at}',{$uid},NOW(),'active')");
                if ($conn->insert_id) {
                    $conn->query("UPDATE inventory SET quantity=(SELECT COALESCE(SUM(remaining),0) FROM stock_batches WHERE product_id={$product_id} AND status='active'),last_updated=NOW() WHERE product_id={$product_id}");
                    $conn->query("INSERT INTO inventory_logs (product_id,type,quantity,reason,created_by,created_at) VALUES ({$product_id},'in',{$quantity},'Batch {$bc}',{$uid},NOW())");
                    $successBatches = ['codes' => [$bc], 'product' => $prow['name'], 'category' => $prow['category'], 'unit' => $prow['unit'], 'tray_count' => $quantity, 'expires_at' => $expires_at, 'expiry_days' => $expiry_days];
                } else {
                    $error = 'Failed to save batch. Try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Stock Batch — HATCH Admin</title>
    <style>
        /* Page-specific only — shared system comes from admin.css */

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: var(--fs-sm);
            font-weight: var(--fw-med);
            color: var(--ink-2);
            margin-bottom: var(--s4);
            transition: color 0.14s;
        }

        .back-link:hover {
            color: var(--brand);
        }

        /* Two-column layout: form + reference panel */
        .add-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: var(--s5);
            align-items: start;
        }

        @media (max-width: 900px) {
            .add-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Card */
        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            overflow: hidden;
        }

        .card-head {
            padding: var(--s5) var(--s6) var(--s4);
            border-bottom: 1px solid var(--line);
        }

        .card-title {
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: var(--s2);
        }

        .card-title i {
            color: var(--brand);
        }

        .card-sub {
            font-size: var(--fs-sm);
            color: var(--ink-3);
            margin-top: 3px;
        }

        .card-body {
            padding: var(--s5) var(--s6);
        }

        /* Error alert */
        .alert-error {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--danger-tint);
            border: 1px solid #f0c4c0;
            color: #b23c34;
            border-radius: var(--r-sm);
            padding: 10px 14px;
            font-size: var(--fs-sm);
            margin-bottom: var(--s4);
        }

        /* Form fields — clean, no numbered steps */
        .field {
            margin-bottom: var(--s5);
        }

        .field:last-child {
            margin-bottom: 0;
        }

        .field-label {
            display: flex;
            align-items: center;
            gap: var(--s2);
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            color: var(--ink);
            margin-bottom: 7px;
        }

        .tag {
            font-size: 0.66rem;
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 2px 7px;
            border-radius: var(--r-pill);
        }

        .tag-req {
            background: var(--brand-tint);
            color: var(--brand-strong);
        }

        .tag-opt {
            background: var(--surface-2);
            color: var(--ink-3);
        }

        .form-select,
        .form-input,
        .form-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            color: var(--ink);
            background: #fff;
            outline: none;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-select:focus,
        .form-input:focus,
        .form-textarea:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .form-select {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239c968c' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 34px;
        }

        .form-textarea {
            resize: vertical;
            min-height: 70px;
        }

        .form-hint {
            font-size: var(--fs-xs);
            color: var(--ink-3);
            margin-top: 5px;
        }

        /* Tray box (shown only for tray units) — JS toggles .show */
        .tray-box {
            display: none;
        }

        .tray-box.show {
            display: block;
        }

        .tray-box-row {
            display: flex;
            align-items: center;
            gap: var(--s3);
        }

        .tray-box-label {
            font-size: var(--fs-sm);
            color: var(--ink-2);
            white-space: nowrap;
        }

        .tray-count-input {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            color: var(--ink);
            background: #fff;
            outline: none;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .tray-count-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .tray-preview-text {
            font-size: var(--fs-xs);
            color: var(--ink-3);
            margin-top: 7px;
        }

        /* qtyGroup — JS toggles style.display; hidden by default */
        #qtyGroup {
            display: none;
        }

        /* notesStep — JS sets display:flex; hidden by default. Make flex lay out cleanly. */
        #notesStep {
            display: none;
            flex-direction: column;
        }

        /* Batch code preview — JS toggles style.display; hidden by default */
        .preview-box {
            display: none;
            margin-top: var(--s5);
            background: var(--brand-tint);
            border: 1px solid var(--brand-tint-2);
            border-radius: var(--r-sm);
            padding: var(--s4);
        }

        .preview-box-label {
            font-size: 0.66rem;
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--brand-strong);
            margin-bottom: 6px;
        }

        .preview-code {
            font-family: 'SF Mono', 'Consolas', monospace;
            font-size: 1rem;
            font-weight: var(--fw-bold);
            color: var(--ink);
            letter-spacing: 0.02em;
        }

        .preview-expiry {
            font-size: var(--fs-xs);
            color: var(--ink-2);
            margin-top: 4px;
        }

        /* Submit — JS sets display:flex; hidden by default */
        .btn-submit {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-top: var(--s5);
            padding: 12px;
            background: var(--brand);
            color: #fff;
            border: none;
            border-radius: var(--r-sm);
            font-size: var(--fs-base);
            font-weight: var(--fw-semi);
            cursor: pointer;
            font-family: inherit;
            transition: background 0.14s;
        }

        .btn-submit:hover {
            background: var(--brand-strong);
        }

        /* ── Success card ── */
        .success-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            overflow: hidden;
        }

        .success-top {
            text-align: center;
            padding: var(--s6) var(--s6) var(--s5);
            background: var(--ok-tint);
            border-bottom: 1px solid #a7dcbc;
        }

        .success-icon {
            width: 54px;
            height: 54px;
            margin: 0 auto var(--s3);
            background: var(--ok);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .success-title {
            font-size: var(--fs-h2);
            font-weight: var(--fw-bold);
            color: #1f7a48;
        }

        .success-sub {
            font-size: var(--fs-sm);
            color: #2b7a52;
            margin-top: 3px;
        }

        .success-meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--s2);
            padding: var(--s5) var(--s6);
        }

        .meta-pill {
            font-size: var(--fs-xs);
            color: var(--ink-2);
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--r-pill);
            padding: 5px 12px;
        }

        .meta-pill strong {
            color: var(--ink);
            font-weight: var(--fw-semi);
        }

        .expiry-badge {
            display: inline-block;
            margin-left: 4px;
            padding: 1px 7px;
            border-radius: var(--r-pill);
            font-size: 0.66rem;
            font-weight: var(--fw-bold);
        }

        .expiry-warn {
            background: var(--warn-tint);
            color: #8a5a0c;
        }

        .expiry-ok {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .codes-section {
            padding: 0 var(--s6) var(--s5);
        }

        .codes-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--s3);
        }

        .codes-label {
            font-size: 0.66rem;
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--ink-3);
        }

        .btn-copy-all {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 11px;
            border-radius: var(--r-sm);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            background: var(--surface);
            color: var(--ink-2);
            border: 1px solid var(--line-strong);
            cursor: pointer;
            font-family: inherit;
            transition: all 0.14s;
        }

        .btn-copy-all:hover {
            background: var(--surface-2);
            color: var(--ink);
            border-color: var(--ink-3);
        }

        .codes-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .code-item {
            display: flex;
            align-items: center;
            gap: var(--s3);
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--r-sm);
            padding: 9px 12px;
        }

        .code-num {
            font-size: var(--fs-xs);
            color: var(--ink-3);
            font-weight: var(--fw-semi);
        }

        .code-val {
            flex: 1;
            font-family: 'SF Mono', 'Consolas', monospace;
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            color: var(--ink);
        }

        .btn-copy-single {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 1px solid var(--line-strong);
            border-radius: 6px;
            color: var(--ink-3);
            cursor: pointer;
            transition: all 0.14s;
        }

        .btn-copy-single:hover {
            background: var(--brand-tint);
            color: var(--brand-strong);
            border-color: var(--brand);
        }

        .btn-copy-single.copied {
            background: var(--ok-tint);
            color: #1f7a48;
            border-color: #a7dcbc;
        }

        .btn-add-another {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 0 var(--s6) var(--s6);
            padding: 11px;
            background: var(--brand);
            color: #fff;
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            transition: background 0.14s;
        }

        .btn-add-another:hover {
            background: var(--brand-strong);
        }

        /* ── Right reference panel ── */
        .side-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            overflow: hidden;
            margin-bottom: var(--s4);
        }

        .side-head {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: var(--s4) var(--s5);
            border-bottom: 1px solid var(--line);
            font-size: var(--fs-sm);
            font-weight: var(--fw-bold);
            color: var(--ink);
        }

        .side-body {
            padding: var(--s4) var(--s5);
        }

        .rem-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 0;
            font-size: var(--fs-sm);
            border-bottom: 1px solid var(--line);
        }

        .rem-row:last-child {
            border-bottom: none;
        }

        .rem-key {
            color: var(--ink-2);
        }

        .rem-val {
            font-weight: var(--fw-semi);
            color: var(--ink);
        }

        .fmt-note {
            font-size: var(--fs-xs);
            color: var(--ink-2);
            line-height: 1.6;
            margin-bottom: var(--s3);
        }

        .fmt-note strong {
            color: var(--ink);
        }

        .fmt-examples {
            font-family: 'SF Mono', 'Consolas', monospace;
            font-size: var(--fs-xs);
            color: var(--ink-2);
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--r-sm);
            padding: 10px 12px;
            line-height: 1.8;
            margin-bottom: var(--s3);
        }

        .fmt-physical {
            display: flex;
            align-items: flex-start;
            gap: 7px;
            font-size: var(--fs-xs);
            color: var(--ink-2);
            line-height: 1.5;
            background: var(--warn-tint);
            border: 1px solid #f2ddb0;
            border-radius: var(--r-sm);
            padding: 9px 12px;
        }

        .fmt-physical i {
            color: #8a5a0c;
            margin-top: 2px;
            flex-shrink: 0;
        }
    </style>
</head>

<body>
    <div class="admin-layout">
        <?php include '../../includes/sidebar.php'; ?>
        <div class="main-content">

            <!-- Mobile topbar -->
            <div class="mobile-topbar">
                <div class="mobile-brand">
                    <div class="mobile-brand-icon"><i class="fa-solid fa-egg"></i></div>
                    HATCH Admin
                </div>
                <button class="icon-btn" onclick="openSidebar()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
            </div>

            <a href="index.php" class="back-link">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6" />
                </svg>
                Back to Stock Batches
            </a>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Add Stock Batch</h1>
                    <div class="page-title-sub">Log new stock — the system generates the batch ID automatically.</div>
                </div>
            </div>

            <div class="add-layout">
                <div>
                    <?php if ($successBatches): ?>
                        <div class="success-card">
                            <div class="success-top">
                                <div class="success-icon"><i class="fa-solid fa-check"></i></div>
                                <div class="success-title">Batch Logged Successfully</div>
                                <div class="success-sub"><?= isTray($successBatches['unit']) ? $successBatches['tray_count'] . ' tray(s) added' : $successBatches['tray_count'] . ' unit(s) added' ?></div>
                            </div>
                            <div class="success-meta">
                                <div class="meta-pill"><strong><?= htmlspecialchars($successBatches['product']) ?></strong> <span>(<?= htmlspecialchars($successBatches['category']) ?>)</span></div>
                                <div class="meta-pill"><span><?= htmlspecialchars($successBatches['unit']) ?></span></div>
                                <div class="meta-pill"><strong><?= $successBatches['tray_count'] ?></strong> <span><?= isTray($successBatches['unit']) ? 'tray(s)' : 'unit(s)' ?> logged</span></div>
                                <div class="meta-pill">Expires <strong><?= date('M j, Y', strtotime($successBatches['expires_at'])) ?></strong> <span class="expiry-badge <?= $successBatches['expiry_days'] <= 3 ? 'expiry-warn' : 'expiry-ok' ?>"><?= $successBatches['expiry_days'] ?>d</span></div>
                            </div>
                            <div class="codes-section">
                                <div class="codes-header"><span class="codes-label">Batch Code</span><button class="btn-copy-all" onclick="copyAll()"><i class="fa-solid fa-copy"></i> Copy All</button></div>
                                <div class="codes-list">
                                    <?php foreach ($successBatches['codes'] as $i => $code): ?>
                                        <div class="code-item"><span class="code-num"><?= $i + 1 ?>.</span><span class="code-val"><?= htmlspecialchars($code) ?></span><button class="btn-copy-single" onclick="copySingle(this,'<?= htmlspecialchars($code) ?>')" title="Copy"><i class="fa-solid fa-copy"></i></button></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <a href="add.php" class="btn-add-another">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19" />
                                    <line x1="5" y1="12" x2="19" y2="12" />
                                </svg>
                                Add Another Batch
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-head">
                                <div class="card-title"><i class="fa-solid fa-box-open"></i> New Stock Batch</div>
                                <div class="card-sub">Select a product and enter the quantity to log.</div>
                            </div>
                            <div class="card-body">
                                <?php if ($error): ?><div class="alert-error"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
                                <form method="POST" action="add.php" id="batchForm">

                                    <!-- Product -->
                                    <div class="field">
                                        <div class="field-label">Product <span class="tag tag-req">Required</span></div>
                                        <select name="product_id" id="productSel" class="form-select" required onchange="onProductChange(this)">
                                            <option value="">— Select a product —</option>
                                            <?php foreach ($tree as $catName => $units): foreach ($units as $unitName => $prods): ?>
                                                    <optgroup label="<?= htmlspecialchars($catName) ?> — <?= htmlspecialchars($unitName) ?>">
                                                        <?php foreach ($prods as $p): ?>
                                                            <option value="<?= $p['id'] ?>" data-unit="<?= htmlspecialchars($p['unit']) ?>" data-category="<?= htmlspecialchars($p['category']) ?>" data-name="<?= htmlspecialchars($p['name']) ?>" <?= (isset($_POST['product_id']) && $_POST['product_id'] == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                            <?php endforeach;
                                            endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Quantity -->
                                    <div class="field">
                                        <div class="field-label" id="step2Label">Quantity <span class="tag tag-req" id="step2Num">Required</span></div>
                                        <!-- TRAY -->
                                        <div class="tray-box" id="trayBox">
                                            <div class="tray-box-row">
                                                <span class="tray-box-label">Number of trays:</span>
                                                <input type="text" inputmode="numeric" pattern="[0-9]*" name="tray_count" id="trayCountInput" class="tray-count-input" placeholder="0" oninput="updateTrayPreview()">
                                            </div>
                                            <div class="tray-preview-text" id="trayPreview">Enter number of trays to add</div>
                                        </div>
                                        <!-- NON-TRAY -->
                                        <div id="qtyGroup">
                                            <input type="text" inputmode="numeric" pattern="[0-9]*" name="quantity" id="qtyInput" class="form-input" placeholder="Enter quantity">
                                            <div class="form-hint" id="qtyHint"></div>
                                        </div>
                                    </div>

                                    <!-- Notes -->
                                    <div class="field" id="notesStep">
                                        <div class="field-label">Notes <span class="tag tag-opt">Optional</span></div>
                                        <textarea name="notes" class="form-textarea" placeholder="e.g. Morning collection, slightly smaller than usual…"></textarea>
                                    </div>

                                    <!-- Batch code preview -->
                                    <div class="preview-box" id="previewBox">
                                        <div class="preview-box-label">Batch Code Preview</div>
                                        <div class="preview-code" id="previewCode"></div>
                                        <div class="preview-expiry" id="previewExpiry"></div>
                                    </div>

                                    <button type="submit" class="btn-submit" id="submitBtn"><i class="fa-solid fa-circle-check"></i><span id="submitLabel">Log Batch</span></button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right reference panel -->
                <div>
                    <div class="side-card">
                        <div class="side-head"><i class="fa-solid fa-clock" style="color:var(--warn);"></i> Expiry Reminders</div>
                        <div class="side-body">
                            <div class="rem-row"><span class="rem-key">Eggs (any)</span><span class="rem-val">21 days</span></div>
                            <div class="rem-row"><span class="rem-key">Cracked Eggs</span><span class="rem-val">2 days</span></div>
                            <div class="rem-row"><span class="rem-key">Alive Chicken</span><span class="rem-val">1 day</span></div>
                            <div class="rem-row"><span class="rem-key">Dressed Chicken</span><span class="rem-val">3 days</span></div>
                        </div>
                    </div>
                    <div class="side-card">
                        <div class="side-head"><i class="fa-solid fa-tag" style="color:var(--brand);"></i> Batch ID Format</div>
                        <div class="side-body">
                            <div class="fmt-note">One batch record per entry. <strong>Trays:</strong> remaining = number of trays. <strong>Others:</strong> remaining = piece / unit count.</div>
                            <div class="fmt-examples">
                                TRAY-LARGE-20260603-001<br>
                                EGG-JUMBO-20260603-001<br>
                                CRACKED-20260603-001<br>
                                CHK-ALIVE-20260603-001<br>
                                CHK-PROC-20260603-001
                            </div>
                            <div class="fmt-physical"><i class="fa-solid fa-pen"></i> After logging, write the batch ID on the physical tray or container so staff can match it to the system.</div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /.main-content -->
    </div><!-- /.admin-layout -->

    <script>
        function getExpiryDays(category, unit) {
            const cat = category.toLowerCase(),
                u = unit.toLowerCase();
            if (cat.includes('crack')) return 2;
            if (cat.includes('egg')) return 21;
            if (u.includes('alive')) return 1;
            if (u.includes('process')) return 3;
            if (cat.includes('chicken')) return 1;
            return 21;
        }

        function getPreviewCode(name, unit, category) {
            const d = new Date();
            const date = d.getFullYear().toString() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
            const n = name.toUpperCase().replace(/\s+/g, ''),
                u = unit.toLowerCase(),
                cat = category.toLowerCase();
            let prefix;
            if (cat.includes('crack')) prefix = 'CRACKED';
            else if (u.includes('tray')) prefix = `TRAY-${n}`;
            else if (u.includes('piece')) prefix = `EGG-${n}`;
            else if (u.includes('alive')) prefix = 'CHK-ALIVE';
            else if (u.includes('process')) prefix = 'CHK-PROC';
            else prefix = n.substring(0, 8);
            return `${prefix}-${date}-XXX`;
        }

        function onProductChange(sel) {
            const opt = sel.options[sel.selectedIndex];
            const unit = opt.dataset.unit || '',
                category = opt.dataset.category || '',
                name = opt.dataset.name || '';
            const hasTray = unit.toLowerCase().includes('tray'),
                hasVal = sel.value !== '';
            document.getElementById('trayBox').classList.toggle('show', hasTray && hasVal);
            document.getElementById('qtyGroup').style.display = (!hasTray && hasVal) ? 'block' : 'none';
            document.getElementById('notesStep').style.display = hasVal ? 'flex' : 'none';
            document.getElementById('submitBtn').style.display = hasVal ? 'flex' : 'none';
            if (!hasTray && hasVal) {
                const hint = document.getElementById('qtyHint');
                if (unit.toLowerCase().includes('piece')) hint.textContent = 'How many individual eggs?';
                else if (unit.toLowerCase().includes('alive')) hint.textContent = 'How many alive chickens?';
                else if (unit.toLowerCase().includes('process')) hint.textContent = 'How many dressed chickens?';
                else hint.textContent = '';
                document.getElementById('submitLabel').textContent = 'Log Batch';
            }
            if (hasTray && hasVal) updateTrayPreview();
            if (hasVal) {
                const code = getPreviewCode(name, unit, category);
                const days = getExpiryDays(category, unit);
                const exp = new Date();
                exp.setDate(exp.getDate() + days);
                const expStr = exp.toLocaleDateString('en-PH', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
                document.getElementById('previewCode').textContent = code;
                document.getElementById('previewExpiry').textContent = `Expires: ${expStr} (${days} days)`;
                document.getElementById('previewBox').style.display = 'block';
            } else {
                document.getElementById('previewBox').style.display = 'none';
            }
        }

        function updateTrayPreview() {
            const n = parseInt(document.getElementById('trayCountInput').value) || 0;
            if (n <= 0) {
                document.getElementById('trayPreview').textContent = 'Enter number of trays to add';
                document.getElementById('submitLabel').textContent = 'Log Batch';
                return;
            }
            document.getElementById('trayPreview').textContent = `${n} tray${n>1?'s':''} — 1 batch record will be created`;
            document.getElementById('submitLabel').textContent = `Log ${n} Tray${n>1?'s':''}`;
        }

        function copySingle(btn, code) {
            navigator.clipboard.writeText(code).then(() => {
                btn.classList.add('copied');
                btn.innerHTML = '<i class="fa-solid fa-check"></i>';
                setTimeout(() => {
                    btn.classList.remove('copied');
                    btn.innerHTML = '<i class="fa-solid fa-copy"></i>';
                }, 2000);
            });
        }

        function copyAll() {
            const codes = [...document.querySelectorAll('.code-val')].map(e => e.textContent).join('\n');
            navigator.clipboard.writeText(codes).then(() => {
                const btn = document.querySelector('.btn-copy-all');
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy All';
                }, 2000);
            });
        }
        document.addEventListener('DOMContentLoaded', () => {
            const sel = document.getElementById('productSel');
            if (sel && sel.value) onProductChange(sel);
        });
    </script>
</body>

</html>