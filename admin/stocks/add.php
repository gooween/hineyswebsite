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

$products = [];
$res = $conn->query("
    SELECT p.id, p.name, p.unit, c.name AS category
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    ORDER BY c.name ASC, p.name ASC
");
while ($row = $res->fetch_assoc()) $products[] = $row;

// Tree: category → unit → products
$tree = [];
foreach ($products as $p) $tree[$p['category']][$p['unit']][] = $p;

function getExpiry($category, $unit)
{
    $cat = strtolower($category);
    $u = strtolower($unit);
    if (str_contains($cat, 'crack'))   return 2;
    if (str_contains($cat, 'egg'))     return 21;
    if (str_contains($u, 'alive'))     return 1;
    if (str_contains($u, 'process'))   return 3;
    if (str_contains($cat, 'chicken')) return 1;
    return 21;
}

function generateBatchCode($product_name, $unit, $category, $conn)
{
    $date = date('Ymd');
    $cat  = strtolower($category);
    $u = strtolower($unit);
    $name = strtoupper(preg_replace('/\s+/', '', $product_name));
    if (str_contains($cat, 'crack'))       $prefix = "CRACKED";
    elseif (str_contains($u, 'tray'))      $prefix = "TRAY-{$name}";
    elseif (str_contains($u, 'piece'))     $prefix = "EGG-{$name}";
    elseif (str_contains($u, 'alive'))     $prefix = "CHK-ALIVE";
    elseif (str_contains($u, 'process'))   $prefix = "CHK-PROC";
    else                                   $prefix = strtoupper(substr($name, 0, 8));

    // Find next available sequence number
    $seq = 1;
    do {
        $candidate     = "{$prefix}-{$date}-" . str_pad($seq, 3, '0', STR_PAD_LEFT);
        $safeCandidate = $conn->real_escape_string($candidate);
        $check         = $conn->query("SELECT id FROM stock_batches WHERE batch_code = '{$safeCandidate}' LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $seq++;
        } else {
            break;
        }
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
            $tray_count = (int)($_POST['tray_count'] ?? 0);
            if ($tray_count <= 0) {
                $error = 'Please enter how many trays you are adding.';
            } else {
                $generatedCodes = [];
                for ($i = 0; $i < $tray_count; $i++) {
                    $bc = $conn->real_escape_string(generateBatchCode($prow['name'], $prow['unit'], $prow['category'], $conn));
                    $conn->query("INSERT INTO stock_batches (batch_code,product_id,quantity,remaining,unit,notes,expires_at,created_by,created_at,status)
                        VALUES ('{$bc}',{$product_id},30,30,'{$unit}','{$notesEsc}','{$expires_at}',{$uid},NOW(),'active')");
                    if ($conn->insert_id) {
                        $generatedCodes[] = $bc;
                        $conn->query("INSERT INTO inventory_logs (product_id,type,quantity,reason,created_by,created_at) VALUES ({$product_id},'in',30,'Batch {$bc}',{$uid},NOW())");
                    }
                }
                if (count($generatedCodes) > 0) {
                    $conn->query("UPDATE inventory SET quantity=(SELECT COALESCE(SUM(remaining),0) FROM stock_batches WHERE product_id={$product_id} AND status='active'),last_updated=NOW() WHERE product_id={$product_id}");
                    $successBatches = ['codes' => $generatedCodes, 'product' => $prow['name'], 'category' => $prow['category'], 'unit' => $prow['unit'], 'tray_count' => count($generatedCodes), 'expires_at' => $expires_at, 'expiry_days' => $expiry_days];
                } else {
                    $error = 'Failed to save batches. Try again.';
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
                    $successBatches = ['codes' => [$bc], 'product' => $prow['name'], 'category' => $prow['category'], 'unit' => $prow['unit'], 'tray_count' => 1, 'expires_at' => $expires_at, 'expiry_days' => $expiry_days];
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
            margin-bottom: 24px;
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

        /* Two-col layout matching dashboard */
        .add-layout {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 24px;
            align-items: start;
        }

        @media(max-width:960px) {
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

        /* Main form card — matches products/dashboard card style */
        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .form-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-card-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .form-card-sub {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .form-body {
            padding: 20px;
        }

        /* Step layout */
        .step-row {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .step-item {
            display: flex;
            gap: 14px;
        }

        .step-num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            font-size: 0.75rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .step-num.done {
            background: #10b981;
        }

        .step-content {
            flex: 1;
        }

        .step-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .step-label .step-tag {
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--text-muted);
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 10px;
        }

        .step-divider {
            width: 1px;
            background: var(--card-border);
            margin-left: 13px;
            min-height: 12px;
        }

        /* Form inputs — identical to products page */
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            font-size: 0.87rem;
            color: var(--text);
            background: #fff;
            outline: none;
            font-family: inherit;
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
            min-height: 72px;
            line-height: 1.6;
        }

        .form-hint {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        optgroup {
            font-weight: 700;
            color: var(--dark);
        }

        /* Tray count box */
        .tray-box {
            background: #fffbeb;
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 14px 16px;
            display: none;
        }

        .tray-box.show {
            display: block;
        }

        .tray-box-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .tray-box-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #78350f;
            white-space: nowrap;
        }

        .tray-count-input {
            width: 80px;
            padding: 7px 10px;
            border: 1.5px solid #f59e0b;
            border-radius: 8px;
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--dark);
            text-align: center;
            outline: none;
            background: #fff;
            font-family: inherit;
        }

        .tray-count-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.12);
        }

        .tray-preview-text {
            font-size: 0.75rem;
            color: #92400e;
            font-style: italic;
        }

        /* Qty group */
        #qtyGroup {
            display: none;
        }

        /* Batch preview box */
        .preview-box {
            background: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 12px 14px;
            display: none;
            margin-top: 4px;
        }

        .preview-box-label {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .preview-code {
            font-family: monospace;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark);
        }

        .preview-expiry {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* Alert */
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 11px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Submit button — outline → fill like other pages */
        .btn-submit {
            width: 100%;
            padding: 11px;
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 8px;
            font-size: 0.92rem;
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
            box-shadow: 0 4px 14px rgba(230, 126, 34, 0.3);
        }

        /* ── Right panel — same card style as dashboard ── */
        .right-panel {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        /* Reminder card */
        .reminder-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .reminder-card-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--dark);
        }

        .reminder-card-body {
            padding: 16px 18px;
        }

        .reminder-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 7px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.83rem;
        }

        .reminder-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .reminder-row:first-child {
            padding-top: 0;
        }

        .reminder-key {
            color: var(--text-muted);
        }

        .reminder-val {
            font-weight: 700;
            color: var(--primary);
        }

        /* ID format card */
        .id-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .id-card-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--dark);
        }

        .id-card-body {
            padding: 16px 18px;
        }

        .id-note {
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 12px;
        }

        .id-examples {
            background: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 7px;
            padding: 10px 12px;
            font-family: monospace;
            font-size: 0.78rem;
            color: var(--dark2);
            line-height: 2;
        }

        .id-physical-note {
            margin-top: 10px;
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            align-items: flex-start;
            gap: 6px;
            line-height: 1.55;
        }

        .id-physical-note i {
            color: var(--primary);
            margin-top: 1px;
            flex-shrink: 0;
        }

        /* ── SUCCESS STATE ── */
        .success-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .success-top {
            background: linear-gradient(135deg, #065f46, #047857);
            padding: 22px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .success-top::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 70% 30%, rgba(255, 255, 255, 0.1), transparent 60%);
        }

        .success-icon {
            font-size: 2.2rem;
            position: relative;
            z-index: 1;
            margin-bottom: 8px;
        }

        .success-title {
            font-size: 1rem;
            font-weight: 800;
            color: #fff;
            position: relative;
            z-index: 1;
            margin-bottom: 3px;
        }

        .success-sub {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.75);
            position: relative;
            z-index: 1;
        }

        .success-meta {
            display: flex;
            gap: 10px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--card-border);
            flex-wrap: wrap;
        }

        .meta-pill {
            background: #f3f4f6;
            border-radius: 7px;
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .meta-pill strong {
            color: var(--dark);
        }

        .meta-pill span {
            color: var(--text-muted);
        }

        .expiry-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .expiry-ok {
            background: #d1fae5;
            color: #065f46;
        }

        .expiry-warn {
            background: #fef3c7;
            color: #92400e;
        }

        .codes-section {
            padding: 16px 18px;
        }

        .codes-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .codes-label {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
        }

        .btn-copy-all {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--primary);
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }

        .btn-copy-all:hover {
            background: var(--primary);
            color: #fff;
        }

        .codes-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 280px;
            overflow-y: auto;
        }

        .code-item {
            display: flex;
            align-items: center;
            background: #f8f9fa;
            border: 1px solid var(--card-border);
            border-radius: 7px;
            padding: 9px 12px;
            gap: 10px;
        }

        .code-num {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-muted);
            min-width: 22px;
        }

        .code-val {
            font-family: monospace;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--dark);
            flex: 1;
        }

        .btn-copy-single {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            padding: 3px 6px;
            border-radius: 4px;
            transition: color 0.15s;
            font-size: 0.85rem;
        }

        .btn-copy-single:hover {
            color: var(--primary);
        }

        .btn-copy-single.copied {
            color: #10b981;
        }

        .btn-add-another {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin: 0 18px 18px;
            padding: 10px;
            background: transparent;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            font-family: inherit;
            transition: all 0.15s;
            text-decoration: none;
        }

        .btn-add-another:hover {
            border-color: var(--primary);
            color: var(--primary);
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
                    <div class="page-title-sub">Log new stock — system generates the batch ID automatically.</div>
                </div>
            </div>

            <div class="add-layout">

                <!-- LEFT: Form or Success -->
                <div>
                    <?php if ($successBatches): ?>

                        <div class="success-card">
                            <div class="success-top">
                                <div class="success-icon">✅</div>
                                <div class="success-title"><?= $successBatches['tray_count'] ?> Batch<?= $successBatches['tray_count'] > 1 ? 'es' : '' ?> Logged Successfully</div>
                                <div class="success-sub">Write each code on its physical <?= htmlspecialchars($successBatches['unit']) ?></div>
                            </div>
                            <div class="success-meta">
                                <div class="meta-pill"><strong><?= htmlspecialchars($successBatches['product']) ?></strong> <span>(<?= htmlspecialchars($successBatches['category']) ?>)</span></div>
                                <div class="meta-pill"><span><?= htmlspecialchars($successBatches['unit']) ?></span></div>
                                <div class="meta-pill">
                                    <strong><?= $successBatches['tray_count'] ?></strong>
                                    <span><?= isTray($successBatches['unit']) ? ' tray' . ($successBatches['tray_count'] > 1 ? 's' : '') : ' unit' . ($successBatches['tray_count'] > 1 ? 's' : '') ?> logged</span>
                                </div>
                                <div class="meta-pill">
                                    Expires <strong><?= date('M j, Y', strtotime($successBatches['expires_at'])) ?></strong>
                                    <span class="expiry-badge <?= $successBatches['expiry_days'] <= 3 ? 'expiry-warn' : 'expiry-ok' ?>"><?= $successBatches['expiry_days'] ?>d</span>
                                </div>
                            </div>
                            <div class="codes-section">
                                <div class="codes-header">
                                    <span class="codes-label">Batch Codes — write on physical <?= htmlspecialchars($successBatches['unit']) ?></span>
                                    <button class="btn-copy-all" onclick="copyAll()"><i class="fa-solid fa-copy"></i> Copy All</button>
                                </div>
                                <div class="codes-list">
                                    <?php foreach ($successBatches['codes'] as $i => $code): ?>
                                        <div class="code-item">
                                            <span class="code-num"><?= $i + 1 ?>.</span>
                                            <span class="code-val"><?= htmlspecialchars($code) ?></span>
                                            <button class="btn-copy-single" onclick="copySingle(this,'<?= htmlspecialchars($code) ?>')" title="Copy">
                                                <i class="fa-solid fa-copy"></i>
                                            </button>
                                        </div>
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

                        <div class="form-card">
                            <div class="form-card-header">
                                <div>
                                    <div class="form-card-title"><i class="fa-solid fa-box-open" style="color:var(--primary);"></i> New Stock Batch</div>
                                    <div class="form-card-sub">Select a product and enter the quantity to log.</div>
                                </div>
                            </div>
                            <div class="form-body">

                                <?php if ($error): ?>
                                    <div class="alert-error"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
                                <?php endif; ?>

                                <form method="POST" action="add.php" id="batchForm">

                                    <div class="step-row">

                                        <!-- Step 1: Product -->
                                        <div class="step-item">
                                            <div class="step-num">1</div>
                                            <div class="step-content">
                                                <div class="step-label">Select Product <span class="step-tag">Required</span></div>
                                                <select name="product_id" id="productSel" class="form-select" required onchange="onProductChange(this)">
                                                    <option value="">— Select Product —</option>
                                                    <?php foreach ($tree as $catName => $units): ?>
                                                        <?php foreach ($units as $unitName => $prods): ?>
                                                            <optgroup label="<?= htmlspecialchars($catName) ?> — <?= htmlspecialchars($unitName) ?>">
                                                                <?php foreach ($prods as $p): ?>
                                                                    <option value="<?= $p['id'] ?>"
                                                                        data-unit="<?= htmlspecialchars($p['unit']) ?>"
                                                                        data-category="<?= htmlspecialchars($p['category']) ?>"
                                                                        data-name="<?= htmlspecialchars($p['name']) ?>"
                                                                        <?= (isset($_POST['product_id']) && $_POST['product_id'] == $p['id']) ? 'selected' : '' ?>>
                                                                        <?= htmlspecialchars($p['name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                        <?php endforeach; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="step-divider"></div>

                                        <!-- Step 2: Quantity -->
                                        <div class="step-item">
                                            <div class="step-num" id="step2Num">2</div>
                                            <div class="step-content">
                                                <div class="step-label" id="step2Label">Quantity <span class="step-tag">Required</span></div>

                                                <!-- TRAY: how many trays -->
                                                <div class="tray-box" id="trayBox">
                                                    <div class="tray-box-row">
                                                        <span class="tray-box-label">How many trays?</span>
                                                        <input type="text" inputmode="numeric" pattern="[0-9]*" name="tray_count" id="trayCountInput" class="tray-count-input" placeholder="0" oninput="updateTrayPreview()">
                                                    </div>
                                                    <div class="tray-preview-text" id="trayPreview">1 tray × 30 eggs = 30 eggs — 1 batch ID will be generated</div>
                                                </div>

                                                <!-- NON-TRAY: quantity -->
                                                <div id="qtyGroup">
                                                    <input type="text" inputmode="numeric" pattern="[0-9]*" name="quantity" id="qtyInput" class="form-input" placeholder="Enter quantity">
                                                    <div class="form-hint" id="qtyHint"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="step-divider"></div>

                                        <!-- Step 3: Notes (optional) -->
                                        <div class="step-item" id="notesStep" style="display:none;">
                                            <div class="step-num">3</div>
                                            <div class="step-content">
                                                <div class="step-label">Notes <span class="step-tag">Optional</span></div>
                                                <textarea name="notes" class="form-textarea" placeholder="e.g. Morning collection, slightly smaller than usual…"></textarea>
                                            </div>
                                        </div>

                                    </div><!-- /.step-row -->

                                    <!-- Batch code preview -->
                                    <div class="preview-box" id="previewBox">
                                        <div class="preview-box-label">Batch Code Preview</div>
                                        <div class="preview-code" id="previewCode"></div>
                                        <div class="preview-expiry" id="previewExpiry"></div>
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

                <!-- RIGHT: Reminders -->
                <div class="right-panel">

                    <div class="reminder-card">
                        <div class="reminder-card-header">
                            <i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Expiry Reminders
                        </div>
                        <div class="reminder-card-body">
                            <div class="reminder-row"><span class="reminder-key">Eggs (any)</span><span class="reminder-val">21 days</span></div>
                            <div class="reminder-row"><span class="reminder-key">Cracked Eggs</span><span class="reminder-val">2 days</span></div>
                            <div class="reminder-row"><span class="reminder-key">Alive Chicken</span><span class="reminder-val">1 day</span></div>
                            <div class="reminder-row"><span class="reminder-key">Dressed Chicken</span><span class="reminder-val">3 days</span></div>
                        </div>
                    </div>

                    <div class="id-card">
                        <div class="id-card-header">
                            <i class="fa-solid fa-tag" style="color:var(--primary);"></i> Batch ID Format
                        </div>
                        <div class="id-card-body">
                            <div class="id-examples">
                                TRAY-LARGE-20260603-001<br>
                                TRAY-LARGE-20260603-002<br>
                                EGG-JUMBO-20260603-001<br>
                                CRACKED-20260603-001<br>
                                CHK-ALIVE-20260603-001<br>
                                CHK-PROC-20260603-001
                            </div>
                            <div class="id-physical-note">
                                <i class="fa-solid fa-pen"></i>
                                After logging, write the batch ID on the physical tray or container so staff can match it to the system.
                            </div>
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
            const p = n > 1 ? 'es' : '',
                bi = n > 1 ? 'es' : '';
            document.getElementById('trayPreview').textContent = `${n} tray${p} — ${n} batch ID${bi} will be generated`;
            document.getElementById('submitLabel').textContent = n > 1 ? `Log ${n} Batches` : 'Log Batch';
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