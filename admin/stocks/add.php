<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/stocks/add.php
// Purpose: Log new stock batch(es)
// ============================================================

session_start();
require_once '../../config/db.php';
requireAdmin();

$activePage = 'stocks_add';

// ── Fetch products ────────────────────────────────────────────
$products = [];
$res = $conn->query("
    SELECT p.id, p.name, p.unit, c.name AS category
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    ORDER BY c.name ASC, p.name ASC
");
while ($row = $res->fetch_assoc()) $products[] = $row;

$grouped = [];
foreach ($products as $p) $grouped[$p['category']][] = $p;

// ── Helpers ───────────────────────────────────────────────────
function getExpiry($category, $unit)
{
    $cat = strtolower($category);
    $u   = strtolower($unit);
    if (str_contains($cat, 'crack'))   return 2;
    if (str_contains($cat, 'egg'))     return 21;
    if (str_contains($u,   'alive'))   return 1;
    if (str_contains($u,   'process')) return 3;
    if (str_contains($cat, 'chicken')) return 1;
    return 21;
}

function generateBatchCode($product_name, $unit, $category, $conn, $offset = 0)
{
    $date = date('Ymd');
    $cat  = strtolower($category);
    $u    = strtolower($unit);
    $name = strtoupper(preg_replace('/\s+/', '', $product_name));

    if (str_contains($cat, 'crack'))        $prefix = "CRACKED";
    elseif (str_contains($u, 'tray'))       $prefix = "TRAY-{$name}";
    elseif (str_contains($u, 'piece'))      $prefix = "EGG-{$name}";
    elseif (str_contains($u, 'alive'))      $prefix = "CHK-ALIVE";
    elseif (str_contains($u, 'process'))    $prefix = "CHK-PROC";
    else                                     $prefix = strtoupper(substr($name, 0, 8));

    $safe = $conn->real_escape_string("{$prefix}-{$date}");
    $r    = $conn->query("SELECT COUNT(*) AS cnt FROM stock_batches WHERE batch_code LIKE '{$safe}%'");
    $cnt  = (int)($r->fetch_assoc()['cnt'] ?? 0);
    $seq  = str_pad($cnt + 1 + $offset, 3, '0', STR_PAD_LEFT);

    return "{$prefix}-{$date}-{$seq}";
}

function isTray($unit)
{
    return str_contains(strtolower($unit), 'tray');
}

// ── POST Handler ──────────────────────────────────────────────
$successBatches = null; // array of batch codes on success
$error          = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id  = (int)($_POST['product_id'] ?? 0);
    $notes       = clean($_POST['notes'] ?? '', $conn);
    $uid         = (int)$_SESSION['user_id'];

    // Find product
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
            // ── TRAY: add N trays, each gets its own batch code ──
            $tray_count = (int)($_POST['tray_count'] ?? 0);
            if ($tray_count <= 0) {
                $error = 'Please enter how many trays you are adding.';
            } else {
                $generatedCodes = [];
                for ($i = 0; $i < $tray_count; $i++) {
                    $batch_code = generateBatchCode($prow['name'], $prow['unit'], $prow['category'], $conn, $i);
                    $bc         = $conn->real_escape_string($batch_code);
                    $conn->query("INSERT INTO stock_batches
                        (batch_code, product_id, quantity, remaining, unit, notes, expires_at, created_by, created_at, status)
                        VALUES ('{$bc}', {$product_id}, 30, 30, '{$unit}', '{$notesEsc}', '{$expires_at}', {$uid}, NOW(), 'active')");
                    if ($conn->insert_id) {
                        $generatedCodes[] = $batch_code;
                        $conn->query("INSERT INTO inventory_logs (product_id, type, quantity, reason, created_by, created_at)
                            VALUES ({$product_id}, 'in', 30, 'Batch {$bc}', {$uid}, NOW())");
                    }
                }
                if (count($generatedCodes) > 0) {
                    // Update inventory cache
                    $conn->query("UPDATE inventory
                        SET quantity = (SELECT COALESCE(SUM(remaining),0) FROM stock_batches WHERE product_id = {$product_id} AND status='active'),
                            last_updated = NOW()
                        WHERE product_id = {$product_id}");
                    $successBatches = [
                        'codes'       => $generatedCodes,
                        'product'     => $prow['name'],
                        'category'    => $prow['category'],
                        'unit'        => $prow['unit'],
                        'tray_count'  => count($generatedCodes),
                        'expires_at'  => $expires_at,
                        'expiry_days' => $expiry_days,
                    ];
                } else {
                    $error = 'Failed to save batches. Try again.';
                }
            }
        } else {
            // ── NON-TRAY: single batch with entered quantity ──
            $quantity = (int)($_POST['quantity'] ?? 0);
            if ($quantity <= 0) {
                $error = 'Quantity must be greater than 0.';
            } else {
                $batch_code = generateBatchCode($prow['name'], $prow['unit'], $prow['category'], $conn);
                $bc         = $conn->real_escape_string($batch_code);
                $conn->query("INSERT INTO stock_batches
                    (batch_code, product_id, quantity, remaining, unit, notes, expires_at, created_by, created_at, status)
                    VALUES ('{$bc}', {$product_id}, {$quantity}, {$quantity}, '{$unit}', '{$notesEsc}', '{$expires_at}', {$uid}, NOW(), 'active')");
                if ($conn->insert_id) {
                    $conn->query("UPDATE inventory
                        SET quantity = (SELECT COALESCE(SUM(remaining),0) FROM stock_batches WHERE product_id = {$product_id} AND status='active'),
                            last_updated = NOW()
                        WHERE product_id = {$product_id}");
                    $conn->query("INSERT INTO inventory_logs (product_id, type, quantity, reason, created_by, created_at)
                        VALUES ({$product_id}, 'in', {$quantity}, 'Batch {$bc}', {$uid}, NOW())");
                    $successBatches = [
                        'codes'       => [$batch_code],
                        'product'     => $prow['name'],
                        'category'    => $prow['category'],
                        'unit'        => $prow['unit'],
                        'tray_count'  => 1,
                        'expires_at'  => $expires_at,
                        'expiry_days' => $expiry_days,
                    ];
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <title>Add Stock Batch — Hiney's Admin</title>
    <style>
        :root {
            --card-border: #e5e7eb;
        }

        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1;
            padding: 32px 32px 48px;
            min-height: 100vh;
            background: var(--page-bg);
            transition: margin-left 0.3s ease;
            box-sizing: border-box;
            width: calc(100% - var(--sidebar-w));
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.02em;
        }

        .page-title-sub {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            color: var(--text-muted);
            text-decoration: none;
            margin-bottom: 20px;
            transition: color 0.15s;
        }

        .back-link:hover {
            color: var(--primary);
        }

        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            background: var(--card-bg);
            cursor: pointer;
            color: var(--dark);
            flex-shrink: 0;
        }

        .add-layout {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 24px;
            align-items: start;
        }

        @media(max-width:900px) {
            .add-layout {
                grid-template-columns: 1fr;
            }

            .main-content {
                margin-left: 0;
                padding: 16px 16px 48px;
                width: 100%;
            }

            .mobile-menu-btn {
                display: flex;
            }
        }

        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .form-card-header {
            padding: 20px 24px 16px;
            border-bottom: 1px solid var(--card-border);
        }

        .form-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-card-sub {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .form-body {
            padding: 24px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 6px;
        }

        .form-label .req {
            color: #ef4444;
            margin-left: 2px;
        }

        .form-hint {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid var(--card-border);
            border-radius: 9px;
            font-size: 0.88rem;
            font-family: inherit;
            color: var(--text);
            background: #fff;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.12);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 32px;
            cursor: pointer;
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
            line-height: 1.6;
        }

        /* Tray count box */
        .tray-count-box {
            background: #fffbeb;
            border: 2px solid #f59e0b;
            border-radius: 10px;
            padding: 16px;
            margin-top: 10px;
            display: none;
        }

        .tray-count-box.show {
            display: block;
        }

        .tray-count-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #92400e;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tray-count-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tray-count-row label {
            font-size: 0.82rem;
            color: #78350f;
            font-weight: 600;
            white-space: nowrap;
        }

        .tray-count-input {
            width: 90px;
            padding: 7px 10px;
            border: 1.5px solid #f59e0b;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--dark);
            text-align: center;
            outline: none;
            background: #fff;
        }

        .tray-count-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.12);
        }

        .tray-preview {
            font-size: 0.78rem;
            color: #92400e;
            margin-top: 8px;
            font-style: italic;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.88rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Preview */
        #previewWrap {
            display: none;
            background: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 9px;
            padding: 12px 14px;
            margin-bottom: 4px;
        }

        .preview-label {
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        #previewCode {
            font-family: monospace;
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
        }

        #previewExpiry {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .btn-submit {
            width: 100%;
            padding: 12px;
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }

        .btn-submit:hover {
            background: var(--primary);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(230, 126, 34, 0.35);
        }

        /* Success */
        .success-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .success-card-top {
            background: linear-gradient(135deg, #065f46, #047857);
            padding: 24px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .success-card-top::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 70% 30%, rgba(255, 255, 255, 0.1), transparent 60%);
        }

        .success-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .success-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #fff;
            position: relative;
            z-index: 1;
            margin-bottom: 4px;
        }

        .success-sub {
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.75);
            position: relative;
            z-index: 1;
        }

        /* Batch codes list */
        .batch-codes-section {
            padding: 20px;
        }

        .batch-codes-label {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .batch-codes-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 300px;
            overflow-y: auto;
        }

        .batch-code-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 10px 14px;
        }

        .batch-code-num {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            min-width: 28px;
        }

        .batch-code-val {
            font-family: monospace;
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--dark);
            flex: 1;
        }

        .btn-copy-single {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            padding: 4px;
            border-radius: 4px;
            transition: color 0.15s;
        }

        .btn-copy-single:hover {
            color: var(--primary);
        }

        .btn-copy-single.copied {
            color: #10b981;
        }

        .btn-copy-all {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--primary);
            border-radius: 7px;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }

        .btn-copy-all:hover {
            background: var(--primary);
            color: #fff;
        }

        .batch-summary {
            padding: 0 20px 16px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .summary-pill {
            background: #f3f4f6;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 0.82rem;
        }

        .summary-pill strong {
            color: var(--dark);
        }

        .summary-pill span {
            color: var(--text-muted);
        }

        .expiry-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .expiry-warn {
            background: #fef3c7;
            color: #92400e;
        }

        .expiry-ok {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-add-another {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: calc(100% - 40px);
            margin: 0 20px 20px;
            padding: 10px;
            background: transparent;
            border: 1.5px solid var(--card-border);
            border-radius: 9px;
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text);
            cursor: pointer;
            font-family: inherit;
            transition: all 0.15s;
            text-decoration: none;
        }

        .btn-add-another:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Info panel */
        .info-panel {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 18px;
            box-shadow: var(--shadow);
        }

        .info-card-title {
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .expiry-rule {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 7px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.83rem;
        }

        .expiry-rule:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .expiry-rule-name {
            color: var(--text);
            font-weight: 500;
        }

        .expiry-rule-days {
            color: var(--primary);
            font-weight: 700;
        }

        .code-example {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px 12px;
            font-family: monospace;
            font-size: 0.78rem;
            color: var(--dark2);
            line-height: 1.8;
            border: 1px solid var(--card-border);
        }
    </style>
</head>

<body>
    <div class="admin-layout">
        <?php include '../../includes/sidebar.php'; ?>
        <div class="main-content">

            <a href="index.php" class="back-link">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="15 18 9 12 15 6" />
                </svg>
                Back to Stock Batches
            </a>

            <div class="page-header">
                <div>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                        <button class="mobile-menu-btn" onclick="openSidebar()">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="3" y1="6" x2="21" y2="6" />
                                <line x1="3" y1="12" x2="21" y2="12" />
                                <line x1="3" y1="18" x2="21" y2="18" />
                            </svg>
                        </button>
                        <h1 class="page-title">Add Stock Batch</h1>
                    </div>
                    <div class="page-title-sub">Log new stock — system generates batch IDs automatically.</div>
                </div>
            </div>

            <div class="add-layout">

                <!-- LEFT: Form or Success -->
                <div>
                    <?php if ($successBatches): ?>

                        <!-- SUCCESS -->
                        <div class="success-card">
                            <div class="success-card-top">
                                <div class="success-icon">✅</div>
                                <div class="success-title">
                                    <?= $successBatches['tray_count'] ?> Batch<?= $successBatches['tray_count'] > 1 ? 'es' : '' ?> Logged!
                                </div>
                                <div class="success-sub">
                                    Write each code on its physical <?= htmlspecialchars($successBatches['unit']) ?>
                                </div>
                            </div>

                            <div class="batch-summary">
                                <div class="summary-pill"><strong><?= htmlspecialchars($successBatches['product']) ?></strong> <span>(<?= htmlspecialchars($successBatches['category']) ?>)</span></div>
                                <div class="summary-pill"><strong><?= htmlspecialchars($successBatches['unit']) ?></strong></div>
                                <div class="summary-pill">
                                    Expires: <strong><?= date('M j, Y', strtotime($successBatches['expires_at'])) ?></strong>
                                    <span class="expiry-badge <?= $successBatches['expiry_days'] <= 3 ? 'expiry-warn' : 'expiry-ok' ?>">
                                        <?= $successBatches['expiry_days'] ?>d
                                    </span>
                                </div>
                            </div>

                            <div class="batch-codes-section">
                                <div class="batch-codes-label">
                                    <span>Batch Codes — write on physical trays</span>
                                    <button class="btn-copy-all" onclick="copyAll()">
                                        <i class="fa-solid fa-copy"></i> Copy All
                                    </button>
                                </div>
                                <div class="batch-codes-list" id="batchCodesList">
                                    <?php foreach ($successBatches['codes'] as $i => $code): ?>
                                        <div class="batch-code-item">
                                            <span class="batch-code-num"><?= $i + 1 ?>.</span>
                                            <span class="batch-code-val"><?= htmlspecialchars($code) ?></span>
                                            <button class="btn-copy-single" onclick="copySingle(this, '<?= htmlspecialchars($code) ?>')" title="Copy">
                                                <i class="fa-solid fa-copy"></i>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <a href="add.php" class="btn-add-another">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="12" y1="5" x2="12" y2="19" />
                                    <line x1="5" y1="12" x2="19" y2="12" />
                                </svg>
                                Add Another Batch
                            </a>
                        </div>

                    <?php else: ?>

                        <!-- FORM -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <div class="form-card-title"><i class="fa-solid fa-box-open"></i> New Stock Batch</div>
                                <div class="form-card-sub">Select a product. For trays, enter how many trays you're adding — each gets its own batch ID.</div>
                            </div>
                            <div class="form-body">
                                <?php if ($error): ?>
                                    <div class="alert-error"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
                                <?php endif; ?>

                                <form method="POST" action="add.php" id="batchForm">
                                    <div class="form-group">
                                        <label class="form-label">Product <span class="req">*</span></label>
                                        <select name="product_id" id="productSel" class="form-select" required onchange="onProductChange(this)">
                                            <option value="">— Select Product —</option>
                                            <?php foreach ($grouped as $catName => $prods): ?>
                                                <optgroup label="<?= htmlspecialchars($catName) ?>">
                                                    <?php foreach ($prods as $p): ?>
                                                        <option value="<?= $p['id'] ?>"
                                                            data-unit="<?= htmlspecialchars($p['unit']) ?>"
                                                            data-category="<?= htmlspecialchars($p['category']) ?>"
                                                            data-name="<?= htmlspecialchars($p['name']) ?>"
                                                            <?= (isset($_POST['product_id']) && $_POST['product_id'] == $p['id']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($p['name']) ?> — <?= htmlspecialchars($p['unit']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- TRAY: how many trays -->
                                    <div class="tray-count-box" id="trayCountBox">
                                        <div class="tray-count-title">
                                            <i class="fa-solid fa-layer-group" style="color:#f59e0b;"></i>
                                            How many trays are you adding?
                                        </div>
                                        <div class="tray-count-row">
                                            <label>Number of trays:</label>
                                            <input type="number" name="tray_count" id="trayCountInput" class="tray-count-input" value="1" min="1" max="100" oninput="updateTrayPreview()">
                                        </div>
                                        <div class="tray-preview" id="trayPreview">
                                            1 tray × 30 eggs = 30 eggs total — 1 batch ID will be generated
                                        </div>
                                    </div>

                                    <!-- NON-TRAY: quantity -->
                                    <div class="form-group" id="qtyGroup" style="display:none;">
                                        <label class="form-label" id="qtyLabel">Quantity <span class="req">*</span></label>
                                        <input type="number" name="quantity" id="qtyInput" class="form-input" min="1" placeholder="0">
                                        <div class="form-hint" id="qtyHint"></div>
                                    </div>

                                    <div class="form-group" id="notesGroup" style="display:none;">
                                        <label class="form-label">Notes (optional)</label>
                                        <textarea name="notes" class="form-textarea" placeholder="e.g. Morning collection, slightly smaller than usual…"></textarea>
                                    </div>

                                    <!-- Batch code preview -->
                                    <div id="previewWrap">
                                        <div class="preview-label">Batch Code Preview</div>
                                        <div id="previewCode"></div>
                                        <div id="previewExpiry"></div>
                                    </div>

                                    <button type="submit" class="btn-submit" id="submitBtn" style="display:none;">
                                        <i class="fa-solid fa-circle-check"></i>
                                        <span id="submitLabel">Log Batch</span>
                                    </button>
                                </form>
                            </div>
                        </div>

                    <?php endif; ?>
                </div>

                <!-- RIGHT: Info panel -->
                <div class="info-panel">
                    <div class="info-card">
                        <div class="info-card-title"><i class="fa-solid fa-clock"></i> Expiry Rules</div>
                        <div class="expiry-rule"><span class="expiry-rule-name">Eggs (any size/unit)</span><span class="expiry-rule-days">21 days</span></div>
                        <div class="expiry-rule"><span class="expiry-rule-name">Cracked Eggs</span><span class="expiry-rule-days">2 days</span></div>
                        <div class="expiry-rule"><span class="expiry-rule-name">Alive Chicken</span><span class="expiry-rule-days">1 day</span></div>
                        <div class="expiry-rule"><span class="expiry-rule-name">Dressed Chicken</span><span class="expiry-rule-days">3 days</span></div>
                    </div>
                    <div class="info-card">
                        <div class="info-card-title"><i class="fa-solid fa-tag"></i> Batch Code Format</div>
                        <div class="code-example">
                            TRAY-LARGE-20260603-001<br>
                            TRAY-LARGE-20260603-002<br>
                            TRAY-LARGE-20260603-003<br>
                            EGG-JUMBO-20260603-001<br>
                            CRACKED-20260603-001<br>
                            CHK-ALIVE-20260603-001
                        </div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:8px;">Each tray gets its own unique sequential code.</div>
                    </div>
                    <div class="info-card">
                        <div class="info-card-title"><i class="fa-solid fa-arrows-rotate"></i> FIFO Order</div>
                        <div style="font-size:0.83rem;color:var(--text-muted);line-height:1.7;">
                            Oldest batch is always sold first. Batch codes on physical trays help staff match stock to the system.
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

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
            const n = name.toUpperCase().replace(/\s+/g, '');
            const u = unit.toLowerCase(),
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
            const unit = opt.dataset.unit || '';
            const category = opt.dataset.category || '';
            const name = opt.dataset.name || '';
            const isTray = unit.toLowerCase().includes('tray');
            const hasVal = sel.value !== '';

            document.getElementById('trayCountBox').classList.toggle('show', isTray && hasVal);
            document.getElementById('qtyGroup').style.display = (!isTray && hasVal) ? 'block' : 'none';
            document.getElementById('notesGroup').style.display = hasVal ? 'block' : 'none';
            document.getElementById('submitBtn').style.display = hasVal ? 'flex' : 'none';

            if (!isTray && hasVal) {
                const lbl = document.getElementById('qtyLabel');
                const hint = document.getElementById('qtyHint');
                if (unit.toLowerCase().includes('piece')) {
                    lbl.innerHTML = 'Number of Pieces <span class="req">*</span>';
                    hint.textContent = 'How many individual eggs in this batch?';
                } else if (unit.toLowerCase().includes('alive')) {
                    lbl.innerHTML = 'Number of Chickens <span class="req">*</span>';
                    hint.textContent = 'How many alive chickens in this group?';
                } else if (unit.toLowerCase().includes('process')) {
                    lbl.innerHTML = 'Number of Dressed Chickens <span class="req">*</span>';
                    hint.textContent = 'How many dressed chickens?';
                } else {
                    lbl.innerHTML = 'Quantity <span class="req">*</span>';
                    hint.textContent = '';
                }
                document.getElementById('submitLabel').textContent = 'Log Batch';
            }

            if (isTray && hasVal) {
                updateTrayPreview();
            }

            if (hasVal) {
                const preview = getPreviewCode(name, unit, category);
                const expiryDays = getExpiryDays(category, unit);
                const expDate = new Date();
                expDate.setDate(expDate.getDate() + expiryDays);
                const expStr = expDate.toLocaleDateString('en-PH', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
                document.getElementById('previewCode').textContent = preview;
                document.getElementById('previewExpiry').textContent = `Expires: ${expStr} (${expiryDays} days)`;
                document.getElementById('previewWrap').style.display = 'block';
            } else {
                document.getElementById('previewWrap').style.display = 'none';
            }
        }

        function updateTrayPreview() {
            const count = parseInt(document.getElementById('trayCountInput').value) || 1;
            const total = count * 30;
            const plural = count > 1 ? 'es' : '';
            document.getElementById('trayPreview').textContent =
                `${count} tray${plural} × 30 eggs = ${total} eggs total — ${count} batch ID${plural} will be generated`;
            document.getElementById('submitLabel').textContent = count > 1 ? `Log ${count} Batches` : 'Log Batch';
        }

        // Copy single
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

        // Copy all codes
        function copyAll() {
            const codes = [...document.querySelectorAll('.batch-code-val')].map(el => el.textContent).join('\n');
            navigator.clipboard.writeText(codes).then(() => {
                const btn = document.querySelector('.btn-copy-all');
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy All';
                }, 2000);
            });
        }

        // Restore on error
        document.addEventListener('DOMContentLoaded', () => {
            const sel = document.getElementById('productSel');
            if (sel && sel.value) onProductChange(sel);
        });
    </script>
</body>

</html>