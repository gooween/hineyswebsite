<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_inventory.php
// Purpose: Inventory analytics — stock levels, value, movement
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$thisYear  = date('Y');
$today     = date('Y-m-d');
$catFilter = (int)($_GET['cat'] ?? 0);

// This file IS the printout — always render clean and auto-print.
$printMode = true;

// Period: daily / weekly / monthly scopes the date-based charts.
$period = trim($_GET['period'] ?? '');
if ($period === 'daily') {
    $periodFrom = $today;
    $periodTo = $today;
    $periodLabel = 'Today';
} elseif ($period === 'weekly') {
    $periodFrom = date('Y-m-d', strtotime('monday this week'));
    $periodTo = $today;
    $periodLabel = 'This Week';
} elseif ($period === 'monthly') {
    $periodFrom = date('Y-m-01');
    $periodTo = $today;
    $periodLabel = 'This Month';
} else {
    $periodFrom = date('Y-m-d', strtotime('-29 days'));
    $periodTo = $today;
    $periodLabel = 'Last 30 days';
}
$pFromSql = $conn->real_escape_string($periodFrom);
$pToSql   = $conn->real_escape_string($periodTo);

// ── Categories for filter ─────────────────────────────────────
$categories = [];
$cr = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $cr->fetch_assoc()) $categories[] = $row;

$catWhere = $catFilter ? "AND p.category_id = {$catFilter}" : '';

// ── KPI 1: Total active products ─────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE is_active = 1 {$catWhere}");
$totalProducts = (int)($r->fetch_assoc()['cnt'] ?? 0);

// ── KPI 2: Low stock items ────────────────────────────────────
$r = $conn->query("
    SELECT COUNT(*) AS cnt FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1 AND i.quantity <= i.reorder_level AND i.quantity > 0
    {$catWhere}
");
$lowStockCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

// ── KPI 3: Out of stock ───────────────────────────────────────
$r = $conn->query("
    SELECT COUNT(*) AS cnt FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1 AND i.quantity = 0
    {$catWhere}
");
$outOfStockCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

// ── KPI 4: Total stock value (qty × price) ───────────────────
$r = $conn->query("
    SELECT COALESCE(SUM(i.quantity * p.price), 0) AS val
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
    {$catWhere}
");
$totalStockValue = (float)($r->fetch_assoc()['val'] ?? 0);

// ── Chart 1: Stock level per product (horizontal bar) ────────
$stockLabels = [];
$stockQtys   = [];
$stockColors = [];
$r = $conn->query("
    SELECT p.name, i.quantity, i.reorder_level
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
    {$catWhere}
    ORDER BY i.quantity DESC
    LIMIT 15
");
while ($row = $r->fetch_assoc()) {
    $stockLabels[] = $row['name'];
    $stockQtys[]   = (int)$row['quantity'];
    $qty = (int)$row['quantity'];
    $rl  = (int)$row['reorder_level'];
    $stockColors[] = $qty === 0 ? '#ef4444' : ($qty <= $rl ? '#f59e0b' : '#10b981');
}

// ── Chart 2: Stock distribution by category (doughnut) ───────
$distLabels = [];
$distData   = [];
$distColors = ['#e67e22', '#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444'];
$r = $conn->query("
    SELECT c.name AS cname, COALESCE(SUM(i.quantity), 0) AS total_qty
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    GROUP BY c.id
    ORDER BY total_qty DESC
");
$ci = 0;
while ($row = $r->fetch_assoc()) {
    $distLabels[] = $row['cname'];
    $distData[]   = (int)$row['total_qty'];
    $ci++;
}

// ── Chart 3: Stock movement (in vs out) over selected period ─────────
$mvLabels  = [];
$mvIn      = [];
$mvOut     = [];
$mvStart = strtotime($periodFrom);
$mvEnd   = strtotime($periodTo);
$dayCount = (int)floor(($mvEnd - $mvStart) / 86400);
if ($dayCount < 0) $dayCount = 0;
for ($i = 0; $i <= $dayCount; $i++) {
    $d = date('Y-m-d', strtotime("+{$i} days", $mvStart));
    $mvLabels[] = date('M j', strtotime($d));

    $ri = $conn->query("
        SELECT COALESCE(SUM(il.quantity),0) AS s
        FROM inventory_logs il
        JOIN products p ON p.id = il.product_id
        WHERE il.type='in' AND DATE(il.created_at)='{$d}'
        " . ($catFilter ? "AND p.category_id={$catFilter}" : "") . "
    ");
    $mvIn[] = (int)($ri->fetch_assoc()['s'] ?? 0);

    $ro = $conn->query("
        SELECT COALESCE(SUM(il.quantity),0) AS s
        FROM inventory_logs il
        JOIN products p ON p.id = il.product_id
        WHERE il.type='out' AND DATE(il.created_at)='{$d}'
        " . ($catFilter ? "AND p.category_id={$catFilter}" : "") . "
    ");
    $mvOut[] = (int)($ro->fetch_assoc()['s'] ?? 0);
}

// ── Chart 4: Most restocked products (top 10) ────────────────
$restockLabels = [];
$restockData   = [];
$r = $conn->query("
    SELECT p.name, COALESCE(SUM(il.quantity), 0) AS total_in
    FROM inventory_logs il
    JOIN products p ON p.id = il.product_id
    WHERE il.type = 'in'
    AND DATE(il.created_at) BETWEEN '{$pFromSql}' AND '{$pToSql}'
    " . ($catFilter ? "AND p.category_id={$catFilter}" : "") . "
    GROUP BY p.id
    ORDER BY total_in DESC
    LIMIT 10
");
while ($row = $r->fetch_assoc()) {
    $restockLabels[] = $row['name'];
    $restockData[]   = (int)$row['total_in'];
}

// ── Full inventory table ──────────────────────────────────────
$inventoryTable = $conn->query("
    SELECT p.name, p.unit, p.price,
           c.name AS category,
           i.quantity, i.reorder_level, i.last_updated,
           (i.quantity * p.price) AS stock_value
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    {$catWhere}
    ORDER BY i.quantity ASC, p.name ASC
");

// JSON
$stockLabelsJson  = json_encode($stockLabels);
$stockQtysJson    = json_encode($stockQtys);
$stockColorsJson  = json_encode($stockColors);
$distLabelsJson   = json_encode($distLabels);
$distDataJson     = json_encode($distData);
$distColorsJson   = json_encode(array_slice($distColors, 0, count($distLabels)));
$mvLabelsJson     = json_encode($mvLabels);
$mvInJson         = json_encode($mvIn);
$mvOutJson        = json_encode($mvOut);
$restockLblJson   = json_encode($restockLabels);
$restockDataJson  = json_encode($restockData);

$activePage = 'report_inventory';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Report — HATCH Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Page-specific only — shared system comes from admin.css */

        /* Filter bar */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: var(--s3);
            flex-wrap: wrap;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r);
            padding: var(--s3) var(--s4);
            margin-bottom: var(--s5);
        }

        .filter-label {
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            color: var(--ink-2);
            white-space: nowrap;
        }

        .filter-select {
            padding: 7px 30px 7px 11px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            background: #fff;
            color: var(--ink);
            outline: none;
            cursor: pointer;
            font-family: inherit;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239c968c' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }

        .filter-select:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .btn-apply {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            background: var(--brand);
            color: #fff;
            border: 1px solid transparent;
            cursor: pointer;
            font-family: inherit;
            white-space: nowrap;
            transition: background 0.14s;
        }

        .btn-apply:hover {
            background: var(--brand-strong);
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

        .legend-row {
            display: flex;
            align-items: center;
            gap: var(--s3);
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: var(--fs-xs);
            color: var(--ink-2);
        }

        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        /* Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: var(--s4);
            margin-bottom: var(--s4);
        }

        .charts-grid.single {
            grid-template-columns: 2fr 1fr;
        }

        .chart-card {
            padding: var(--s5);
        }

        .chart-card.wide {
            grid-column: span 1;
        }

        .chart-header {
            margin-bottom: var(--s4);
        }

        .chart-title {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: var(--fs-base);
            font-weight: var(--fw-bold);
            color: var(--ink);
        }

        .chart-title svg {
            color: var(--brand);
        }

        .chart-sub {
            font-size: var(--fs-xs);
            color: var(--ink-3);
            margin-top: 3px;
        }

        @media (max-width: 1000px) {

            .charts-grid,
            .charts-grid.single {
                grid-template-columns: 1fr;
            }
        }

        /* Section header + actions */
        .section-header {
            display: flex;
            align-items: center;
            gap: var(--s3);
            margin: var(--s7) 0 var(--s4);
            flex-wrap: wrap;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: var(--s2);
            font-size: var(--fs-h2);
            font-weight: var(--fw-bold);
            color: var(--ink);
            letter-spacing: -0.01em;
        }

        .section-title svg {
            color: var(--brand);
        }

        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 15px;
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            cursor: pointer;
            font-family: inherit;
            white-space: nowrap;
            border: 1px solid var(--line-strong);
            background: var(--surface);
            color: var(--ink-2);
            transition: background 0.14s, border-color 0.14s;
        }

        .btn-export:hover {
            background: var(--surface-2);
            color: var(--ink);
            border-color: var(--ink-3);
        }

        .btn-export.primary {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
        }

        .btn-export.primary:hover {
            background: var(--brand-strong);
            border-color: var(--brand-strong);
        }

        /* Report table */
        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table thead th {
            text-align: left;
            padding: 11px 14px;
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--ink-3);
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
            background: var(--surface-2);
        }

        .report-table tbody td {
            padding: 11px 14px;
            border-bottom: 1px solid var(--line);
            font-size: var(--fs-sm);
        }

        .report-table tbody tr:last-child td {
            border-bottom: none;
        }

        .report-table tbody tr:hover td {
            background: var(--surface-2);
        }

        .cat-tag {
            background: var(--surface-2);
            color: var(--ink-2);
            padding: 2px 9px;
            border-radius: 6px;
            font-size: var(--fs-xs);
            font-weight: var(--fw-med);
        }

        /* Qty bar in table */
        .qty-bar {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bar-track {
            flex: 1;
            min-width: 60px;
            height: 6px;
            background: var(--line);
            border-radius: 3px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: 3px;
        }

        /* Stock status badges */
        .stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            white-space: nowrap;
        }

        .stock-badge::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .sb-ok {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .sb-low {
            background: var(--warn-tint);
            color: #8a5a0c;
        }

        .sb-out {
            background: var(--danger-tint);
            color: #b23c34;
        }

        .val-pos {
            color: #1f7a48;
            font-weight: var(--fw-semi);
            font-variant-numeric: tabular-nums;
        }

        /* ── Clean print mode ── */
        .print-mode .sidebar,
        .print-mode .mobile-topbar,
        .print-mode form[action="report_inventory.php"],
        .print-mode .filter-bar,
        .print-mode .section-header>div[style*="margin-left:auto"] {
            display: none !important;
        }

        .print-mode .admin-layout {
            display: block;
        }

        .print-mode .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 20px 28px !important;
        }

        @media print {

            .sidebar,
            .mobile-topbar,
            form[action="report_inventory.php"],
            .filter-bar,
            .section-header>div[style*="margin-left:auto"] {
                display: none !important;
            }

            .admin-layout {
                display: block;
            }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 0 !important;
            }

            @page {
                size: A4 landscape;
                margin: 12mm;
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

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Inventory Report</h1>
                    <div class="page-title-sub">Stock levels, distribution, movement, and total inventory value</div>
                </div>
            </div>

            <?= flash() ?>

            <!-- Filter Bar -->
            <form method="GET" action="report_inventory.php">
                <div class="filter-bar">
                    <span class="filter-label">Category:</span>
                    <select name="cat" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $catFilter == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-apply">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                        Apply
                    </button>
                    <?php if ($catFilter): ?>
                        <a href="report_inventory.php" class="clear-link">✕ Clear</a>
                    <?php endif; ?>
                    <div style="margin-left:auto;">
                        <div class="legend-row">
                            <div class="legend-item">
                                <div class="legend-dot" style="background:#10b981;"></div>OK
                            </div>
                            <div class="legend-item">
                                <div class="legend-dot" style="background:#f59e0b;"></div>Low Stock
                            </div>
                            <div class="legend-item">
                                <div class="legend-dot" style="background:#ef4444;"></div>Out of Stock
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <!-- KPI Cards -->
            <div class="grid cols-2 mb-6" style="grid-template-columns:repeat(4,1fr);">
                <div class="stat-card tone-blue">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Products</span>
                        <div class="stat-icon"><i class="fa-solid fa-box"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalProducts) ?></div>
                    <div class="stat-foot">Active products tracked</div>
                </div>
                <div class="stat-card tone-amber">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Low Stock Items</span>
                        <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php if ($lowStockCount > 0): ?><span class="pulse amber"><span class="pulse-dot amber"></span><?= number_format($lowStockCount) ?></span><?php else: ?><?= number_format($lowStockCount) ?><?php endif; ?>
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
                    <div class="stat-foot">Zero quantity products</div>
                </div>
                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Stock Value</span>
                        <div class="stat-icon"><i class="fa-solid fa-peso-sign"></i></div>
                    </div>
                    <div class="stat-value money"><?= peso($totalStockValue) ?></div>
                    <div class="stat-foot">Qty × price per product</div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="charts-grid">
                <div class="table-card chart-card wide">
                    <div class="chart-header">
                        <div class="chart-title">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="20" x2="18" y2="10" />
                                <line x1="12" y1="20" x2="12" y2="4" />
                                <line x1="6" y1="20" x2="6" y2="14" />
                            </svg>
                            Current Stock Level per Product
                        </div>
                        <div class="chart-sub">Top 15 products · color = status</div>
                    </div>
                    <div style="position:relative;width:100%;height:<?= max(220, count($stockLabels) * 30) ?>px;">
                        <canvas id="stockChart"></canvas>
                    </div>
                </div>

                <div class="table-card chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10" />
                            </svg>
                            Stock by Category
                        </div>
                        <div class="chart-sub">Units distribution</div>
                    </div>
                    <div style="position:relative;width:100%;height:240px;">
                        <canvas id="distChart"></canvas>
                    </div>
                </div>

                <div class="table-card chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
                            </svg>
                            Stock In vs Out (<?= htmlspecialchars($periodLabel) ?>)
                        </div>
                        <div class="chart-sub"><?= date('M j', strtotime($periodFrom)) ?> – <?= date('M j', strtotime($periodTo)) ?></div>
                    </div>
                    <div style="position:relative;width:100%;height:240px;">
                        <canvas id="mvChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="charts-grid single">
                <div class="table-card chart-card wide">
                    <div class="chart-header">
                        <div class="chart-title">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="17 1 21 5 17 9" />
                                <path d="M3 11V9a4 4 0 0 1 4-4h14" />
                                <polyline points="7 23 3 19 7 15" />
                                <path d="M21 13v2a4 4 0 0 1-4 4H3" />
                            </svg>
                            Most Restocked Products
                        </div>
                        <div class="chart-sub">Stock-in during <?= htmlspecialchars(strtolower($periodLabel)) ?></div>
                    </div>
                    <div style="position:relative;width:100%;height:200px;">
                        <canvas id="restockChart"></canvas>
                    </div>
                </div>
                <div class="table-card chart-card" style="display:flex;flex-direction:column;justify-content:center;">
                    <div class="chart-header">
                        <div class="chart-title">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                            </svg>
                            Inventory Value
                        </div>
                        <div class="chart-sub">Total worth of current stock</div>
                    </div>
                    <div style="text-align:center;padding:var(--s5) 0;">
                        <div style="font-size:2.4rem;font-weight:var(--fw-bold);color:var(--ink);letter-spacing:-0.02em;"><?= peso($totalStockValue) ?></div>
                        <div style="font-size:var(--fs-sm);color:var(--ink-3);margin-top:6px;"><?= number_format($totalProducts) ?> products · <?= number_format($lowStockCount + $outOfStockCount) ?> need attention</div>
                    </div>
                </div>
            </div>

            <!-- Inventory Table -->
            <div class="section-header">
                <div class="section-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z" />
                    </svg>
                    Full Inventory Snapshot
                </div>
                <div style="margin-left:auto;display:flex;gap:var(--s2);">
                    <button class="btn-export primary" onclick="printReport()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 6 2 18 2 18 9" />
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                            <rect x="6" y="14" width="12" height="8" />
                        </svg>
                        Print Report
                    </button>
                    <button class="btn-export" onclick="exportCSV()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="7 10 12 15 17 10" />
                            <line x1="12" y1="15" x2="12" y2="3" />
                        </svg>
                        Export CSV
                    </button>
                </div>
            </div>
            <div class="table-card">
                <?php
                $rows = [];
                $maxQty = 1;
                if ($inventoryTable && $inventoryTable->num_rows > 0) {
                    while ($row = $inventoryTable->fetch_assoc()) {
                        $rows[] = $row;
                        if ((int)$row['quantity'] > $maxQty) $maxQty = (int)$row['quantity'];
                    }
                }
                ?>
                <?php if (!empty($rows)): ?>
                    <div class="table-scroll">
                        <table class="report-table" id="invTable">
                            <thead>
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Unit</th>
                                    <th>Price</th>
                                    <th>Stock Qty</th>
                                    <th>Reorder Level</th>
                                    <th>Status</th>
                                    <th>Last Updated</th>
                                    <th>Stock Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $i => $r):
                                    $qty  = (int)$r['quantity'];
                                    $rl   = (int)$r['reorder_level'];
                                    $pct  = $maxQty > 0 ? round(($qty / $maxQty) * 100) : 0;
                                    $color  = $qty === 0 ? '#ef4444' : ($qty <= $rl ? '#f59e0b' : '#10b981');
                                    $sCls   = $qty === 0 ? 'sb-out' : ($qty <= $rl ? 'sb-low' : 'sb-ok');
                                    $sLabel = $qty === 0 ? 'Out of Stock' : ($qty <= $rl ? 'Low Stock' : 'OK');
                                ?>
                                    <tr>
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);font-weight:var(--fw-semi);"><?= $i + 1 ?></td>
                                        <td style="font-weight:var(--fw-semi);color:var(--ink);"><?= htmlspecialchars($r['name']) ?></td>
                                        <td><span class="cat-tag"><?= htmlspecialchars($r['category']) ?></span></td>
                                        <td style="color:var(--ink-3);font-size:var(--fs-sm);"><?= htmlspecialchars($r['unit']) ?></td>
                                        <td style="font-weight:var(--fw-semi);color:var(--ink);"><?= peso((float)$r['price']) ?></td>
                                        <td>
                                            <div class="qty-bar">
                                                <span style="font-weight:var(--fw-bold);color:<?= $color ?>;min-width:30px;font-variant-numeric:tabular-nums;"><?= number_format($qty) ?></span>
                                                <div class="bar-track">
                                                    <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="color:var(--ink-2);"><?= number_format($rl) ?></td>
                                        <td><span class="stock-badge <?= $sCls ?>"><?= $sLabel ?></span></td>
                                        <td style="font-size:var(--fs-xs);color:var(--ink-3);white-space:nowrap;"><?= date('M j, Y', strtotime($r['last_updated'])) ?></td>
                                        <td class="val-pos"><?= peso((float)$r['stock_value']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-box"></i></div>
                        <div class="empty-title">No inventory data found</div>
                        <div class="empty-text">Add products and stock to see the report.</div>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /.main-content -->
    </div><!-- /.admin-layout -->

    <script>
        Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
        Chart.defaults.color = '#9ca3af';

        const tooltip = {
            backgroundColor: '#1f2937',
            titleColor: '#f9fafb',
            bodyColor: '#d1d5db',
            padding: 10,
            cornerRadius: 8,
        };

        // ── 1. Stock Level per Product (horizontal bar) ───────────────
        (function() {
            const ctx = document.getElementById('stockChart');
            if (!ctx) return;
            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= $stockLabelsJson ?>,
                    datasets: [{
                        label: 'Qty in Stock',
                        data: <?= $stockQtysJson ?>,
                        backgroundColor: <?= $stockColorsJson ?>,
                        borderRadius: 5,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...tooltip,
                            callbacks: {
                                label: c => '  ' + c.parsed.x.toLocaleString() + ' units'
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.04)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        })();

        // ── 2. Distribution by Category (doughnut) ───────────────────
        (function() {
            const ctx = document.getElementById('distChart');
            if (!ctx) return;
            new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: <?= $distLabelsJson ?>,
                    datasets: [{
                        data: <?= $distDataJson ?>,
                        backgroundColor: <?= $distColorsJson ?>,
                        borderWidth: 3,
                        borderColor: '#fff',
                        hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 11
                                },
                                padding: 14,
                                boxWidth: 10,
                                boxHeight: 10
                            }
                        },
                        tooltip: {
                            ...tooltip,
                            callbacks: {
                                label: c => '  ' + c.label + ': ' + c.parsed.toLocaleString() + ' units'
                            }
                        }
                    }
                }
            });
        })();

        // ── 3. Stock Movement — In vs Out (line) ─────────────────────
        (function() {
            const ctx = document.getElementById('mvChart');
            if (!ctx) return;
            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?= $mvLabelsJson ?>,
                    datasets: [{
                            label: 'Stock In',
                            data: <?= $mvInJson ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16,185,129,0.1)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#10b981',
                        },
                        {
                            label: 'Stock Out',
                            data: <?= $mvOutJson ?>,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239,68,68,0.08)',
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#ef4444',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: {
                                    size: 11
                                },
                                padding: 14,
                                boxWidth: 10
                            }
                        },
                        tooltip: {
                            ...tooltip,
                            callbacks: {
                                label: c => '  ' + c.dataset.label + ': ' + c.parsed.y.toLocaleString() + ' units'
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 10
                                },
                                maxTicksLimit: 10
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.04)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        })();

        // ── 4. Most Restocked (bar) ───────────────────────────────────
        (function() {
            const ctx = document.getElementById('restockChart');
            if (!ctx) return;
            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= $restockLblJson ?>,
                    datasets: [{
                        label: 'Total Units Restocked',
                        data: <?= $restockDataJson ?>,
                        backgroundColor: function(context) {
                            const {
                                chart
                            } = context;
                            if (!chart.chartArea) return '#3b82f6';
                            const {
                                top,
                                bottom
                            } = chart.chartArea;
                            const g = chart.ctx.createLinearGradient(0, top, 0, bottom);
                            g.addColorStop(0, 'rgba(59,130,246,0.85)');
                            g.addColorStop(1, 'rgba(99,102,241,0.4)');
                            return g;
                        },
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            ...tooltip,
                            callbacks: {
                                label: c => '  ' + c.parsed.y.toLocaleString() + ' units'
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.04)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        })();

        function printReport() {
            const params = new URLSearchParams({
                cat: '<?= (int)$catFilter ?>'
            });
            window.open('report_inventory_print.php?' + params.toString(), '_blank');
        }

        // ── CSV Export ────────────────────────────────────────────────
        function exportCSV() {
            const table = document.getElementById('invTable');
            if (!table) {
                alert('No data to export.');
                return;
            }
            const rows = table.querySelectorAll('tr');
            const lines = [];
            rows.forEach(function(row) {
                const cells = row.querySelectorAll('th, td');
                const line = Array.from(cells).map(function(cell) {
                    const text = cell.innerText.replace(/\n/g, ' ').trim();
                    return '"' + text.replace(/"/g, '""') + '"';
                });
                lines.push(line.join(','));
            });
            const blob = new Blob(['\uFEFF' + lines.join('\n')], {
                type: 'text/csv;charset=utf-8;'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'inventory_report_<?= date('Y-m-d') ?>.csv';
            a.click();
            URL.revokeObjectURL(url);
        }
    </script>
</body>

</html>