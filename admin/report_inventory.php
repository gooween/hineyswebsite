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
$catFilter = (int)($_GET['cat'] ?? 0);

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
$distColors = ['#e67e22','#10b981','#3b82f6','#8b5cf6','#f59e0b','#ef4444'];
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

// ── Chart 3: Stock movement (in vs out) last 30 days ─────────
$mvLabels  = [];
$mvIn      = [];
$mvOut     = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
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
<title>Inventory Report — Hiney's Admin</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root { --card-border: #e9e8e4; }

.main-content {
    margin-left: var(--sidebar-w);
    flex: 1;
    padding: 32px 32px 56px;
    min-height: 100vh;
    background: var(--page-bg);
    box-sizing: border-box;
    width: calc(100% - var(--sidebar-w));
}

/* Page header */
.page-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 14px;
}
.page-title {
    font-size: 1.5rem; font-weight: 800; color: var(--dark);
    letter-spacing: -0.02em; display: flex; align-items: center; gap: 10px;
}
.page-title-icon {
    width: 38px; height: 38px;
    background: linear-gradient(135deg, #10b981, #059669);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.page-title-sub { font-size: 0.82rem; color: var(--text-muted); margin-top: 3px; }

/* Filter bar */
.filter-bar {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius); padding: 14px 18px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 24px; flex-wrap: wrap; box-shadow: var(--shadow);
}
.filter-label { font-size: 0.8rem; font-weight: 700; color: var(--dark); white-space: nowrap; }
.filter-select {
    padding: 7px 28px 7px 10px; border: 1px solid var(--card-border);
    border-radius: 8px; font-size: 0.85rem; background: var(--page-bg);
    color: var(--text); outline: none; cursor: pointer; appearance: none;
    font-family: inherit;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 9px center;
}
.filter-select:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(230,126,34,0.12); }
.btn-apply {
    display: flex; align-items: center; gap: 6px; padding: 8px 18px;
    background: var(--primary); color: #fff; border: none; border-radius: 8px;
    font-size: 0.85rem; font-weight: 600; cursor: pointer; font-family: inherit;
    transition: background 0.15s, transform 0.1s;
}
.btn-apply:hover { background: #cf6d17; transform: translateY(-1px); }

/* KPI cards */
.kpi-grid {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 16px; margin-bottom: 24px;
}
@media(max-width:1100px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:600px)  { .kpi-grid { grid-template-columns: 1fr; } }

.kpi-card {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius); padding: 20px 20px 18px;
    box-shadow: var(--shadow); position: relative; overflow: hidden;
    transition: transform 0.18s, box-shadow 0.18s;
}
.kpi-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.kpi-accent {
    position: absolute; top: 0; left: 0;
    width: 100%; height: 3px; border-radius: var(--radius) var(--radius) 0 0;
}
.kpi-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.kpi-icon   { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.kpi-label  { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-muted); }
.kpi-value  { font-size: 1.85rem; font-weight: 800; color: var(--dark); line-height: 1; letter-spacing: -0.03em; margin-bottom: 4px; }
.kpi-value.money { font-size: 1.35rem; }
.kpi-sub    { font-size: 0.74rem; color: var(--text-muted); }

.kc-blue   .kpi-accent { background: #3b82f6; } .kc-blue   .kpi-icon { background: #eff6ff; color: #3b82f6; }
.kc-green  .kpi-accent { background: #10b981; } .kc-green  .kpi-icon { background: #ecfdf5; color: #10b981; }
.kc-amber  .kpi-accent { background: #f59e0b; } .kc-amber  .kpi-icon { background: #fffbeb; color: #f59e0b; }
.kc-red    .kpi-accent { background: #ef4444; } .kc-red    .kpi-icon { background: #fef2f2; color: #ef4444; }

/* Charts */
.charts-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 16px; margin-bottom: 24px;
}
@media(max-width:900px) { .charts-grid { grid-template-columns: 1fr; } }

.chart-card {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius); padding: 20px 22px; box-shadow: var(--shadow);
}
.chart-card.wide { grid-column: span 2; }
@media(max-width:900px) { .chart-card.wide { grid-column: span 1; } }

.chart-header {
    display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px;
}
.chart-title {
    font-size: 0.9rem; font-weight: 700; color: var(--dark);
    display: flex; align-items: center; gap: 8px;
}
.chart-sub { font-size: 0.75rem; color: var(--text-muted); }

/* Status legend dots */
.legend-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 5px; font-size: 0.75rem; color: var(--text-muted); }
.legend-dot  { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

/* Table */
.section-header {
    display: flex; align-items: center; justify-content: space-between;
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius) var(--radius) 0 0;
    padding: 14px 18px; border-bottom: none;
}
.section-title {
    font-size: 0.9rem; font-weight: 700; color: var(--dark);
    display: flex; align-items: center; gap: 8px;
}
.table-wrapper {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: 0 0 var(--radius) var(--radius);
    overflow-x: auto; box-shadow: var(--shadow);
}
table.report-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
table.report-table thead th {
    background: var(--dark); color: #e5e7eb; font-size: 0.7rem;
    font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em;
    padding: 12px 16px; white-space: nowrap; text-align: left;
}
table.report-table thead th:last-child { text-align: right; }
table.report-table tbody tr:nth-child(even) { background: #faf9f7; }
table.report-table tbody tr:hover { background: #fef9f4; transition: background 0.12s; }
table.report-table tbody td {
    padding: 12px 16px; color: var(--text);
    border-bottom: 1px solid #f3f2f0; vertical-align: middle;
}
table.report-table tbody tr:last-child td { border-bottom: none; }
table.report-table tbody td:last-child { text-align: right; font-weight: 700; }

/* Stock status badge */
.stock-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 600;
}
.stock-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.sb-ok  { background: #d1fae5; color: #065f46; }
.sb-low { background: #fef3c7; color: #92400e; }
.sb-out { background: #fee2e2; color: #991b1b; }

/* Stock bar */
.qty-bar { display: flex; align-items: center; gap: 8px; }
.bar-track { width: 70px; height: 5px; background: #e5e7eb; border-radius: 3px; overflow: hidden; flex-shrink: 0; }
.bar-fill  { height: 100%; border-radius: 3px; }

/* Export button */
.btn-export {
    display: flex; align-items: center; gap: 6px; padding: 7px 14px;
    background: transparent; color: var(--text-muted);
    border: 1px solid var(--card-border); border-radius: 8px;
    font-size: 0.82rem; font-weight: 600; cursor: pointer;
    transition: all 0.15s; font-family: inherit;
}
.btn-export:hover { background: var(--page-bg); color: var(--dark); border-color: #aaa; }

/* Empty */
.empty-state { text-align: center; padding: 48px; color: var(--text-muted); font-size: 0.9rem; }

/* Mobile */
.mobile-menu-btn {
    display: none; align-items: center; justify-content: center;
    width: 36px; height: 36px; border: 1px solid var(--card-border);
    border-radius: 8px; background: var(--card-bg); cursor: pointer; color: var(--dark);
}
@media(max-width:768px) {
    .main-content    { margin-left: 0; padding: 16px 16px 48px; width: 100%; }
    .mobile-menu-btn { display: flex; }
    .filter-bar      { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>
<div class="admin-layout">
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <button class="mobile-menu-btn" onclick="openSidebar()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h1 class="page-title">
                    <div class="page-title-icon">📦</div>
                    Inventory Report
                </h1>
            </div>
            <div class="page-title-sub">Monitor stock levels, distribution, movement, and total inventory value</div>
        </div>
    </div>

    <?= flash() ?>

    <!-- Filter Bar -->
    <form method="GET" action="report_inventory.php">
        <div class="filter-bar">
            <span class="filter-label">Category:</span>
            <select name="cat" class="filter-select">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $catFilter==$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-apply">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Apply
            </button>
            <?php if ($catFilter): ?>
                <a href="report_inventory.php" style="font-size:0.8rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear</a>
            <?php endif; ?>
            <div style="margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <div class="legend-row">
                    <div class="legend-item"><div class="legend-dot" style="background:#10b981;"></div>OK</div>
                    <div class="legend-item"><div class="legend-dot" style="background:#f59e0b;"></div>Low Stock</div>
                    <div class="legend-item"><div class="legend-dot" style="background:#ef4444;"></div>Out of Stock</div>
                </div>
            </div>
        </div>
    </form>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card kc-blue">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Total Products</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                </div>
            </div>
            <div class="kpi-value"><?= number_format($totalProducts) ?></div>
            <div class="kpi-sub">Active products tracked</div>
        </div>

        <div class="kpi-card kc-amber">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Low Stock Items</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
            </div>
            <div class="kpi-value"><?= number_format($lowStockCount) ?></div>
            <div class="kpi-sub">At or below reorder level</div>
        </div>

        <div class="kpi-card kc-red">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Out of Stock</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
            </div>
            <div class="kpi-value"><?= number_format($outOfStockCount) ?></div>
            <div class="kpi-sub">Zero quantity products</div>
        </div>

        <div class="kpi-card kc-green">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Total Stock Value</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
            </div>
            <div class="kpi-value money"><?= peso($totalStockValue) ?></div>
            <div class="kpi-sub">Qty × price per product</div>
        </div>
    </div>

    <!-- Charts Row 1 -->
    <div class="charts-grid">
        <div class="chart-card wide">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    Current Stock Level per Product
                </div>
                <span class="chart-sub">Top 15 products · color = status</span>
            </div>
            <div style="position:relative;width:100%;height:<?= max(220, count($stockLabels) * 30) ?>px;">
                <canvas id="stockChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>
                    Stock by Category
                </div>
                <span class="chart-sub">Units distribution</span>
            </div>
            <div style="position:relative;width:100%;height:240px;">
                <canvas id="distChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Stock In vs Out (Last 30 Days)
                </div>
                <span class="chart-sub">Daily movement</span>
            </div>
            <div style="position:relative;width:100%;height:240px;">
                <canvas id="mvChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 -->
    <div class="charts-grid">
        <div class="chart-card wide">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                    Most Restocked Products
                </div>
                <span class="chart-sub">Total stock-in quantity (all time)</span>
            </div>
            <div style="position:relative;width:100%;height:200px;">
                <canvas id="restockChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="section-header">
        <div class="section-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/></svg>
            Full Inventory Snapshot
        </div>
        <button class="btn-export" onclick="exportCSV()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </button>
    </div>
    <div class="table-wrapper">
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
                <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $i+1 ?></td>
                <td style="font-weight:600;color:var(--dark);"><?= htmlspecialchars($r['name']) ?></td>
                <td><span style="background:#f3f4f6;color:var(--text-muted);padding:2px 8px;border-radius:6px;font-size:0.78rem;font-weight:500;"><?= htmlspecialchars($r['category']) ?></span></td>
                <td style="color:var(--text-muted);font-size:0.83rem;"><?= htmlspecialchars($r['unit']) ?></td>
                <td style="font-weight:600;"><?= peso((float)$r['price']) ?></td>
                <td>
                    <div class="qty-bar">
                        <span style="font-weight:700;color:<?= $color ?>;min-width:30px;"><?= number_format($qty) ?></span>
                        <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div></div>
                    </div>
                </td>
                <td style="color:var(--text-muted);"><?= number_format($rl) ?></td>
                <td><span class="stock-badge <?= $sCls ?>"><?= $sLabel ?></span></td>
                <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;"><?= date('M j, Y', strtotime($r['last_updated'])) ?></td>
                <td style="color:#10b981;"><?= peso((float)$r['stock_value']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <div style="font-size:2.5rem;margin-bottom:12px;">📦</div>
            <div>No inventory data found.</div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.main-content -->
</div><!-- /.admin-layout -->

<script>
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.color = '#9ca3af';

const tooltip = {
    backgroundColor: '#1f2937', titleColor: '#f9fafb',
    bodyColor: '#d1d5db', padding: 10, cornerRadius: 8,
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
            responsive: true, maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: { ...tooltip, callbacks: { label: c => '  ' + c.parsed.x.toLocaleString() + ' units' } }
            },
            scales: {
                x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 11 } } },
                y: { grid: { display: false }, ticks: { font: { size: 11 } } }
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
                borderWidth: 3, borderColor: '#fff', hoverOffset: 6,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 14, boxWidth: 10, boxHeight: 10 } },
                tooltip: { ...tooltip, callbacks: { label: c => '  ' + c.label + ': ' + c.parsed.toLocaleString() + ' units' } }
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
            datasets: [
                {
                    label: 'Stock In',
                    data: <?= $mvInJson ?>,
                    borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true, tension: 0.4, borderWidth: 2,
                    pointRadius: 2, pointHoverRadius: 5,
                    pointBackgroundColor: '#10b981',
                },
                {
                    label: 'Stock Out',
                    data: <?= $mvOutJson ?>,
                    borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.08)',
                    fill: true, tension: 0.4, borderWidth: 2,
                    pointRadius: 2, pointHoverRadius: 5,
                    pointBackgroundColor: '#ef4444',
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 14, boxWidth: 10 } },
                tooltip: { ...tooltip, callbacks: { label: c => '  ' + c.dataset.label + ': ' + c.parsed.y.toLocaleString() + ' units' } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 }, maxTicksLimit: 10 } },
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 11 } } }
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
                    const {chart} = context;
                    if (!chart.chartArea) return '#3b82f6';
                    const {top,bottom} = chart.chartArea;
                    const g = chart.ctx.createLinearGradient(0,top,0,bottom);
                    g.addColorStop(0,'rgba(59,130,246,0.85)');
                    g.addColorStop(1,'rgba(99,102,241,0.4)');
                    return g;
                },
                borderRadius: 6, borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { ...tooltip, callbacks: { label: c => '  ' + c.parsed.y.toLocaleString() + ' units' } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 11 } } }
            }
        }
    });
})();

// ── CSV Export ────────────────────────────────────────────────
function exportCSV() {
    const table = document.getElementById('invTable');
    if (!table) { alert('No data to export.'); return; }
    const rows  = table.querySelectorAll('tr');
    const lines = [];
    rows.forEach(function(row) {
        const cells = row.querySelectorAll('th, td');
        const line  = Array.from(cells).map(function(cell) {
            const text = cell.innerText.replace(/\n/g,' ').trim();
            return '"' + text.replace(/"/g,'""') + '"';
        });
        lines.push(line.join(','));
    });
    const blob = new Blob(['\uFEFF' + lines.join('\n')], {type:'text/csv;charset=utf-8;'});
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'inventory_report_<?= date('Y-m-d') ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>