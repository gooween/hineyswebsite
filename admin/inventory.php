<?php
// ============================================================
// Hiney's Eggs & Live Chicken Business
// File: admin/inventory.php
//
// Inventory overview — stock levels (from stock_batches),
// low/out alerts, reorder levels, and recent stock activity.
// Rebuilt on the shared design system (admin/assets/admin.css).
//
// Stock is managed via Stock Batches; this page is read-oriented
// plus reorder-level editing and a per-product logs modal.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'reorder') {
        $product_id    = (int)($_POST['product_id'] ?? 0);
        $reorder_level = (int)($_POST['reorder_level'] ?? 0);
        if ($product_id) {
            $conn->query("UPDATE inventory SET reorder_level = {$reorder_level}, last_updated = NOW() WHERE product_id = {$product_id}");
            redirect('inventory.php', 'success', 'Reorder level updated.');
        }
    }
}

$search      = trim($_GET['q'] ?? '');
$filterStock = trim($_GET['stock'] ?? '');
$filterCat   = (int)($_GET['cat'] ?? 0);

$stockExpr = "COALESCE((
    SELECT SUM(sb.remaining)
    FROM stock_batches sb
    WHERE sb.product_id = p.id AND sb.status = 'active'
), 0)";

$where = "WHERE p.is_active = 1";
if ($search)    $where .= " AND p.name LIKE '%{$conn->real_escape_string($search)}%'";
if ($filterCat) $where .= " AND p.category_id = {$filterCat}";

$inventory = $conn->query("
    SELECT p.id AS product_id, p.name, p.unit, p.image_url, c.name AS category,
           i.id AS inv_id, i.reorder_level, i.last_updated, i.notes,
           {$stockExpr} AS stock
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    {$where}
    ORDER BY ({$stockExpr}) ASC, p.name ASC
");

$totalItems = (int)($conn->query("SELECT COUNT(*) AS cnt FROM inventory i JOIN products p ON p.id = i.product_id WHERE p.is_active = 1")->fetch_assoc()['cnt'] ?? 0);

$lowStockCount = (int)($conn->query("
    SELECT COUNT(*) AS cnt FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
      AND (SELECT SUM(sb.remaining) FROM stock_batches sb WHERE sb.product_id = i.product_id AND sb.status = 'active') <= i.reorder_level
      AND (SELECT SUM(sb.remaining) FROM stock_batches sb WHERE sb.product_id = i.product_id AND sb.status = 'active') > 0
")->fetch_assoc()['cnt'] ?? 0);

$outOfStockCount = (int)($conn->query("
    SELECT COUNT(*) AS cnt FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
      AND COALESCE((SELECT SUM(sb.remaining) FROM stock_batches sb WHERE sb.product_id = i.product_id AND sb.status = 'active'), 0) = 0
")->fetch_assoc()['cnt'] ?? 0);

$totalUnits = (int)($conn->query("
    SELECT COALESCE(SUM(
        (SELECT COALESCE(SUM(remaining),0) FROM stock_batches sb WHERE sb.product_id = p.id AND sb.status = 'active')
    ), 0) AS total
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
")->fetch_assoc()['total'] ?? 0);

$categories = [];
$cr = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $cr->fetch_assoc()) $categories[] = $row;

$logs = $conn->query("
    SELECT il.*, p.name AS product_name, u.full_name AS by_name
    FROM inventory_logs il
    JOIN products p ON p.id = il.product_id
    LEFT JOIN users u ON u.id = il.created_by
    ORDER BY il.created_at DESC
    LIMIT 20
");

$activePage = 'inventory';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory — Hiney's Admin</title>
    <style>
        /* Page-specific only — shared system comes from admin.css */

        .info-notice {
            display: flex;
            align-items: center;
            gap: var(--s3);
            background: var(--info-tint);
            border: 1px solid #bcd6f5;
            border-radius: var(--r);
            padding: var(--s3) var(--s4);
            font-size: var(--fs-sm);
            color: #2b62ad;
            margin-bottom: var(--s6);
        }

        .info-notice i {
            color: #2b62ad;
        }

        .info-notice a {
            color: #2b62ad;
            font-weight: var(--fw-bold);
            text-decoration: underline;
        }

        /* Toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            gap: var(--s3);
            flex-wrap: wrap;
            margin-bottom: var(--s4);
        }

        .toolbar-title {
            font-size: var(--fs-h3);
            font-weight: var(--fw-semi);
            color: var(--ink);
            white-space: nowrap;
        }

        .search-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-wrap svg {
            position: absolute;
            left: 11px;
            color: var(--ink-3);
            pointer-events: none;
        }

        .search-input {
            padding: 8px 12px 8px 34px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            width: 210px;
            background: var(--surface);
            color: var(--ink);
            outline: none;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .search-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .filter-select {
            padding: 8px 30px 8px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            background: var(--surface);
            color: var(--ink);
            outline: none;
            cursor: pointer;
            appearance: none;
            font-family: inherit;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239c968c' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }

        .filter-select:focus {
            border-color: var(--brand);
        }

        .clear-link {
            font-size: var(--fs-sm);
            color: var(--brand);
            font-weight: var(--fw-med);
            white-space: nowrap;
        }

        .clear-link:hover {
            text-decoration: underline;
        }

        /* Product cell */
        .prod-thumb {
            width: 40px;
            height: 40px;
            border-radius: var(--r-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            overflow: hidden;
            border: 1px solid var(--brand-tint-2);
            background: linear-gradient(135deg, var(--brand-tint), var(--brand-tint-2));
        }

        .prod-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cat-tag {
            background: #f0eee9;
            color: var(--ink-2);
            padding: 2px 9px;
            border-radius: var(--r-sm);
            font-size: var(--fs-xs);
            font-weight: var(--fw-med);
        }

        /* Stock cell */
        .stock-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
            max-width: 130px;
        }

        .stock-top {
            display: flex;
            align-items: baseline;
            gap: 5px;
        }

        .stock-num {
            font-weight: var(--fw-bold);
            font-size: 0.95rem;
            font-variant-numeric: tabular-nums;
        }

        .stock-unit {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        .stock-bar {
            width: 100%;
            height: 4px;
            background: var(--line);
            border-radius: 2px;
            overflow: hidden;
        }

        .stock-bar-fill {
            height: 100%;
            border-radius: 2px;
        }

        /* Row actions — icon by default, expand to label on hover (matches Products) */
        .row-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .act {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 32px;
            width: 32px;
            padding: 0;
            border-radius: var(--r-sm);
            cursor: pointer;
            border: 1px solid;
            background: transparent;
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            font-family: inherit;
            white-space: nowrap;
            overflow: hidden;
            text-decoration: none;
            transition: width 0.22s cubic-bezier(0.4, 0, 0.2, 1), background 0.14s, color 0.14s, border-color 0.14s, padding 0.22s;
        }

        .act svg,
        .act i {
            flex-shrink: 0;
        }

        .act .act-label {
            max-width: 0;
            opacity: 0;
            margin-left: 0;
            transition: max-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.18s, margin-left 0.22s;
        }

        .act:hover {
            width: auto;
            padding: 0 11px;
        }

        .act:hover .act-label {
            max-width: 100px;
            opacity: 1;
            margin-left: 5px;
        }

        .act-add {
            color: var(--ok);
            border-color: #a7dcbc;
        }

        .act-add:hover {
            background: var(--ok);
            color: #fff;
            border-color: var(--ok);
        }

        .act-logs {
            color: #7c5cd0;
            border-color: #c9bbee;
        }

        .act-logs:hover {
            background: #7c5cd0;
            color: #fff;
            border-color: #7c5cd0;
        }

        /* Section header for the activity table */
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: var(--s7) 0 var(--s4);
        }

        .section-head-title {
            font-size: var(--fs-h2);
            font-weight: var(--fw-bold);
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: var(--s2);
            letter-spacing: -0.01em;
        }

        .section-head-title i {
            color: #7c5cd0;
        }

        .section-head-note {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        /* Log type badges */
        .log-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            white-space: nowrap;
        }

        .log-in {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .log-out {
            background: var(--danger-tint);
            color: #b23c34;
        }

        .log-adjustment {
            background: var(--info-tint);
            color: #2b62ad;
        }

        /* Logs modal */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(35, 32, 28, 0.45);
            backdrop-filter: blur(3px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-card {
            background: var(--surface);
            border-radius: var(--r-lg);
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-md);
            animation: modalIn 0.22s cubic-bezier(0.34, 1.4, 0.64, 1) both;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(16px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: var(--s5) var(--s6) var(--s4);
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
            background: var(--surface);
            border-radius: var(--r-lg) var(--r-lg) 0 0;
        }

        .modal-title {
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: var(--s2);
        }

        .modal-title svg {
            color: #7c5cd0;
        }

        .modal-close {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: var(--surface-2);
            border-radius: var(--r-sm);
            cursor: pointer;
            color: var(--ink-3);
            font-size: 1rem;
            transition: background 0.14s, color 0.14s;
        }

        .modal-close:hover {
            background: var(--danger-tint);
            color: var(--danger);
        }

        .modal-body {
            padding: var(--s5) var(--s6);
        }

        @media (max-width: 768px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-input {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="admin-layout">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">

            <!-- Mobile topbar -->
            <div class="mobile-topbar">
                <div class="mobile-brand">
                    <div class="mobile-brand-icon"><i class="fa-solid fa-egg"></i></div>
                    Hiney's Admin
                </div>
                <button class="icon-btn" onclick="openSidebar()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
            </div>

            <!-- Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Inventory</h1>
                    <div class="page-title-sub">Monitor stock levels and track changes</div>
                </div>
                <a href="stocks/add.php" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Add Stock Batch
                </a>
            </div>

            <?= flash() ?>

            <div class="info-notice">
                <i class="fa-solid fa-circle-info"></i>
                <span>Stock is managed through <strong>Stock Batches</strong>. To add stock, go to <a href="stocks/add.php">Add Stock Batch</a>. Stock deductions happen automatically when orders are <strong>approved</strong>.</span>
            </div>

            <!-- Stat cards -->
            <div class="grid cols-2 mb-6" style="grid-template-columns:repeat(4,1fr);">
                <div class="stat-card tone-blue">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Products</span>
                        <div class="stat-icon"><i class="fa-solid fa-box"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalItems) ?></div>
                    <div class="stat-foot">Active products tracked</div>
                </div>
                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Units in Stock</span>
                        <div class="stat-icon"><i class="fa-solid fa-cubes-stacked"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalUnits) ?></div>
                    <div class="stat-foot">Across all batches</div>
                </div>
                <div class="stat-card tone-amber">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Low Stock</span>
                        <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php if ($lowStockCount > 0): ?><span class="pulse"><span class="pulse-dot amber"></span><?= number_format($lowStockCount) ?></span><?php else: ?><?= number_format($lowStockCount) ?><?php endif; ?>
                    </div>
                    <div class="stat-foot">At or below reorder level</div>
                </div>
                <div class="stat-card tone-red">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Out of Stock</span>
                        <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php if ($outOfStockCount > 0): ?><span class="pulse"><span class="pulse-dot"></span><?= number_format($outOfStockCount) ?></span><?php else: ?><?= number_format($outOfStockCount) ?><?php endif; ?>
                    </div>
                    <div class="stat-foot">Zero units remaining</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <span class="toolbar-title">Stock Levels</span>
                <form method="GET" style="display:flex;gap:var(--s3);align-items:center;flex-wrap:wrap;">
                    <div class="search-wrap">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                        <input type="text" name="q" class="search-input" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <select name="cat" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?>
                    </select>
                    <select name="stock" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Stock</option>
                        <option value="ok" <?= $filterStock === 'ok' ? 'selected' : '' ?>>OK</option>
                        <option value="low" <?= $filterStock === 'low' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="out" <?= $filterStock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                    </select>
                    <?php if ($search || $filterCat || $filterStock): ?><a href="inventory.php" class="clear-link">✕ Clear</a><?php endif; ?>
                </form>
            </div>

            <!-- Stock levels table -->
            <div class="table-card">
                <?php
                $rows = [];
                if ($inventory) {
                    while ($row = $inventory->fetch_assoc()) {
                        $qty   = (int)$row['stock'];
                        $reord = (int)$row['reorder_level'];
                        if ($filterStock === 'low' && !($qty <= $reord && $qty > 0)) continue;
                        if ($filterStock === 'out' && $qty !== 0) continue;
                        if ($filterStock === 'ok'  && !($qty > $reord)) continue;
                        $rows[] = $row;
                    }
                }
                ?>
                <?php if (count($rows) > 0): ?>
                    <div class="table-scroll">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th style="width:44px;">#</th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Current Stock</th>
                                    <th class="num">Reorder Level</th>
                                    <th>Status</th>
                                    <th>Last Updated</th>
                                    <th style="text-align:center;width:120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $n = 1;
                                foreach ($rows as $inv):
                                    $qty   = (int)$inv['stock'];
                                    $reord = (int)$inv['reorder_level'];
                                    $isTray = strtolower($inv['unit']) === 'per tray';
                                    $isChicken = stripos($inv['category'], 'chicken') !== false;
                                    $emoji  = $isChicken ? '<i class="fa-solid fa-drumstick-bite"></i>' : '<i class="fa-solid fa-egg"></i>';
                                    $pct    = $reord > 0 ? min(100, round(($qty / max($reord * 2, 1)) * 100)) : 100;
                                    if ($qty <= 0) {
                                        $color = 'var(--danger)';
                                        $pillCls = 'pill-danger';
                                        $pillLbl = 'Out of Stock';
                                    } elseif ($qty <= $reord) {
                                        $color = 'var(--warn)';
                                        $pillCls = 'pill-warn';
                                        $pillLbl = 'Low Stock';
                                    } else {
                                        $color = 'var(--ok)';
                                        $pillCls = 'pill-ok';
                                        $pillLbl = 'OK';
                                    }
                                    $unitLbl = $isTray ? 'tray' . ($qty != 1 ? 's' : '') : htmlspecialchars($inv['unit']);
                                ?>
                                    <tr>
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);font-weight:var(--fw-semi);"><?= $n++ ?></td>
                                        <td>
                                            <div class="cell-lead">
                                                <div class="prod-thumb"><?php if (!empty($inv['image_url'])): ?><img src="<?= htmlspecialchars($inv['image_url']) ?>" alt=""><?php else: ?><?= $emoji ?><?php endif; ?></div>
                                                <div>
                                                    <div class="cell-title"><?= htmlspecialchars($inv['name']) ?></div>
                                                    <div class="cell-sub"><?= htmlspecialchars($inv['unit']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="cat-tag"><?= htmlspecialchars($inv['category']) ?></span></td>
                                        <td>
                                            <div class="stock-cell">
                                                <div class="stock-top">
                                                    <span class="stock-num" style="color:<?= $color ?>;"><?= number_format($qty) ?></span>
                                                    <span class="stock-unit"><?= $unitLbl ?></span>
                                                </div>
                                                <div class="stock-bar">
                                                    <div class="stock-bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="num" style="color:var(--ink-2);"><?= number_format($reord) ?></td>
                                        <td><span class="pill <?= $pillCls ?> pill-dot"><?= $pillLbl ?></span></td>
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);white-space:nowrap;"><?= date('M j, Y', strtotime($inv['last_updated'])) ?></td>
                                        <td style="text-align:center;">
                                            <div class="row-actions">
                                                <a href="stocks/add.php?product_id=<?= $inv['product_id'] ?>" class="act act-add">
                                                    <i class="fa-solid fa-plus" style="font-size:11px;"></i><span class="act-label">Add Stock</span>
                                                </a>
                                                <button class="act act-logs" onclick="openLogs(<?= $inv['product_id'] ?>,'<?= htmlspecialchars(addslashes($inv['name'])) ?>')">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z" />
                                                    </svg><span class="act-label">Logs</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-box"></i></div>
                        <div class="empty-title">No inventory records found</div>
                        <div class="empty-text">Try adjusting your search or filters.</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent activity -->
            <div class="section-head">
                <div class="section-head-title">
                    <i class="fa-solid fa-clipboard-list"></i> Recent Stock Activity
                </div>
                <span class="section-head-note">Last 20 entries</span>
            </div>
            <div class="table-card">
                <?php if ($logs && $logs->num_rows > 0): ?>
                    <div class="table-scroll">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Product</th>
                                    <th>Type</th>
                                    <th class="num">Quantity</th>
                                    <th>Reason</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($log = $logs->fetch_assoc()):
                                    $tc = match ($log['type']) {
                                        'in' => 'log-in',
                                        'out' => 'log-out',
                                        'adjustment' => 'log-adjustment',
                                        default => 'log-in'
                                    };
                                    $tl = match ($log['type']) {
                                        'in' => '<i class="fa-solid fa-arrow-up"></i> Stock In',
                                        'out' => '<i class="fa-solid fa-arrow-down"></i> Stock Out',
                                        'adjustment' => '<i class="fa-solid fa-right-left"></i> Adjusted',
                                        default => htmlspecialchars($log['type'])
                                    };
                                ?>
                                    <tr>
                                        <td style="font-size:var(--fs-xs);color:var(--ink-3);white-space:nowrap;"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
                                        <td style="font-weight:var(--fw-semi);"><?= htmlspecialchars($log['product_name']) ?></td>
                                        <td><span class="log-badge <?= $tc ?>"><?= $tl ?></span></td>
                                        <td class="num" style="font-weight:var(--fw-bold);"><?= number_format($log['quantity']) ?></td>
                                        <td style="color:var(--ink-2);font-size:var(--fs-sm);"><?= htmlspecialchars($log['reason'] ?? '—') ?></td>
                                        <td style="font-size:var(--fs-xs);color:var(--ink-3);"><?= htmlspecialchars($log['by_name'] ?? '—') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                        <div class="empty-title">No stock activity yet</div>
                        <div class="empty-text">Stock movements will be logged here.</div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- LOGS MODAL -->
    <div class="modal-backdrop" id="logsModal" onclick="backdropClose(event,'logsModal')">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z" />
                    </svg>
                    <span id="logs_title">Stock History</span>
                </div>
                <button class="modal-close" onclick="closeModal('logsModal')">✕</button>
            </div>
            <div class="modal-body">
                <div id="logs_content" style="padding:32px;text-align:center;color:var(--ink-3);">Loading…</div>
            </div>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
            document.body.style.overflow = '';
        }

        function backdropClose(e, id) {
            if (e.target === document.getElementById(id)) closeModal(id);
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(m => closeModal(m.id));
        });

        function openLogs(productId, productName) {
            document.getElementById('logs_title').textContent = productName + ' — Stock History';
            document.getElementById('logs_content').innerHTML = '<div style="padding:32px;text-align:center;color:var(--ink-3);">Loading…</div>';
            openModal('logsModal');
            fetch('inventory_logs_ajax.php?product_id=' + productId)
                .then(r => r.text())
                .then(html => {
                    document.getElementById('logs_content').innerHTML = html;
                })
                .catch(() => {
                    document.getElementById('logs_content').innerHTML = '<div style="padding:24px;text-align:center;color:var(--danger);">Failed to load logs.</div>';
                });
        }

        const si = document.querySelector('.search-input');
        if (si) si.addEventListener('keydown', e => {
            if (e.key === 'Enter') e.target.closest('form').submit();
        });
    </script>
</body>

</html>