<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_sales.php
// Purpose: Sales analytics — charts, KPIs, CSV export
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

// ── Date range filter ─────────────────────────────────────────
$today    = date('Y-m-d');
$thisYear = date('Y');

$dateFrom = trim($_GET['from'] ?? date('Y-m-01'));   // default: first of this month
$dateTo   = trim($_GET['to']   ?? $today);
$catFilter = (int)($_GET['cat'] ?? 0);

// Clamp
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$dateFromSql = $conn->real_escape_string($dateFrom);
$dateToSql   = $conn->real_escape_string($dateTo);

$baseWhere  = "WHERE o.status != 'cancelled'
               AND DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'";
if ($catFilter) {
    $baseWhere .= " AND EXISTS (
        SELECT 1 FROM order_items oi2
        JOIN products p2 ON p2.id = oi2.product_id
        WHERE oi2.order_id = o.id AND p2.category_id = {$catFilter}
    )";
}

// ── KPI Cards ─────────────────────────────────────────────────

// Total sales
$r = $conn->query("SELECT COALESCE(SUM(o.total_amount),0) AS total FROM orders o {$baseWhere}");
$totalSales = (float)($r->fetch_assoc()['total'] ?? 0);

// Total orders
$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders o {$baseWhere}");
$totalOrders = (int)($r->fetch_assoc()['cnt'] ?? 0);

// Average order value
$avgOrder = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

// Best selling product (by quantity)
$r = $conn->query("
    SELECT p.name, SUM(oi.quantity) AS total_qty
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN orders o ON o.id = oi.order_id
    {$baseWhere}
    GROUP BY p.id
    ORDER BY total_qty DESC
    LIMIT 1
");
$bestProduct = '—';
$bestQty     = 0;
if ($r && $row = $r->fetch_assoc()) {
    $bestProduct = $row['name'];
    $bestQty     = (int)$row['total_qty'];
}

// ── Chart 1: Daily sales trend ────────────────────────────────
$dailyLabels = [];
$dailyData   = [];
$start = new DateTime($dateFrom);
$end   = new DateTime($dateTo);
$interval = new DateInterval('P1D');
$period = new DatePeriod($start, $interval, $end->modify('+1 day'));
foreach ($period as $dt) {
    $d = $dt->format('Y-m-d');
    $dailyLabels[] = $dt->format('M j');
    $r2 = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS s FROM orders WHERE status!='cancelled' AND DATE(created_at)='{$d}'");
    $dailyData[] = (float)($r2->fetch_assoc()['s'] ?? 0);
}

// ── Chart 2: Sales per category ───────────────────────────────
$catNames  = [];
$catTotals = [];
$r = $conn->query("
    SELECT c.name AS cname, COALESCE(SUM(oi.subtotal),0) AS total
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    JOIN orders o ON o.id = oi.order_id
    {$baseWhere}
    GROUP BY c.id
    ORDER BY total DESC
");
while ($row = $r->fetch_assoc()) {
    $catNames[]  = $row['cname'];
    $catTotals[] = (float)$row['total'];
}

// ── Chart 3: Monthly sales this year ─────────────────────────
$monthlyLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthlyData   = array_fill(0, 12, 0);
$r = $conn->query("
    SELECT MONTH(created_at) AS m, COALESCE(SUM(total_amount),0) AS s
    FROM orders
    WHERE status!='cancelled' AND YEAR(created_at)='{$thisYear}'
    GROUP BY m
");
while ($row = $r->fetch_assoc()) {
    $monthlyData[(int)$row['m'] - 1] = (float)$row['s'];
}

// ── Chart 4: Payment method breakdown ────────────────────────
$methodLabels = [];
$methodData   = [];
$methodColors = [];
$mColorMap    = ['cash'=>'#10b981','gcash'=>'#8b5cf6','cod'=>'#3b82f6'];
$r = $conn->query("
    SELECT payment_method, COUNT(*) AS cnt
    FROM orders o
    {$baseWhere}
    GROUP BY payment_method
");
while ($row = $r->fetch_assoc()) {
    $methodLabels[] = strtoupper($row['payment_method']);
    $methodData[]   = (int)$row['cnt'];
    $methodColors[] = $mColorMap[$row['payment_method']] ?? '#9ca3af';
}

// ── Summary table: Top products ───────────────────────────────
$topProducts = $conn->query("
    SELECT p.name, c.name AS category,
           SUM(oi.quantity) AS total_qty,
           SUM(oi.subtotal) AS total_sales,
           COUNT(DISTINCT oi.order_id) AS order_count
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    JOIN orders o ON o.id = oi.order_id
    {$baseWhere}
    GROUP BY p.id
    ORDER BY total_sales DESC
    LIMIT 20
");

// ── Categories for filter ─────────────────────────────────────
$categories = [];
$cr = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $cr->fetch_assoc()) $categories[] = $row;

// JSON encode
$dailyLblJson   = json_encode($dailyLabels);
$dailyDataJson  = json_encode($dailyData);
$catNamesJson   = json_encode($catNames);
$catTotalsJson  = json_encode($catTotals);
$monthlyLblJson = json_encode($monthlyLabels);
$monthlyDataJson= json_encode($monthlyData);
$methodLblJson  = json_encode($methodLabels);
$methodDataJson = json_encode($methodData);
$methodColorJson= json_encode($methodColors);

$activePage = 'report_sales';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style id="hineys-icon-colors">
/* === Hiney's icon colors === */
/* Icons inside dark/colored or interactive areas keep their inherited color */
.navbar .fa-solid, .mobile-drawer .fa-solid, .sidebar .fa-solid,
button .fa-solid, [class*="btn"] .fa-solid, .badge .fa-solid,
.status-badge .fa-solid, .status-tab .fa-solid, .pay-badge .fa-solid,
.page-banner .fa-solid, .page-header .fa-solid, .hero .fa-solid,
.cta-card .fa-solid, .about-strip .fa-solid, .nav-cart .fa-solid,
.user-chip .fa-solid, .info-card-top .fa-solid, .sidebar-logout .fa-solid {
    color: inherit !important;
}
/* Semantic colors for standalone content icons */
.fa-egg { color: #f4a72c; }
.fa-drumstick-bite { color: #c2703b; }
.fa-circle-check, .fa-check, .fa-shield-halved,
.fa-leaf, .fa-seedling, .fa-phone { color: #10b981; }
.fa-circle-xmark, .fa-xmark, .fa-trash, .fa-ban,
.fa-location-dot { color: #ef4444; }
.fa-cart-shopping, .fa-bag-shopping, .fa-store, .fa-shop { color: #e67e22; }
.fa-truck { color: #f97316; }
.fa-triangle-exclamation, .fa-circle-exclamation,
.fa-clock, .fa-star { color: #f59e0b; }
.fa-info-circle, .fa-credit-card, .fa-mobile-screen,
.fa-envelope, .fa-envelope-open, .fa-envelope-open-text,
.fa-inbox, .fa-comment, .fa-map, .fa-paperclip { color: #3b82f6; }
.fa-sack-dollar, .fa-money-bill, .fa-money-bill-transfer { color: #16a34a; }
.fa-users, .fa-user, .fa-user-plus { color: #6366f1; }
.fa-box, .fa-box-open, .fa-boxes-stacked, .fa-warehouse,
.fa-receipt, .fa-clipboard-list, .fa-file-lines { color: #8b5cf6; }
.fa-chart-bar, .fa-chart-line, .fa-chart-pie,
.fa-gauge-high { color: #0ea5e9; }
.fa-heart { color: #ef4444; }
.fa-gear { color: #6b7280; }
.fa-lightbulb { color: #f59e0b; }
</style>
<title>Sales Report — Hiney's Admin</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ── Reset & root ── */
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

/* ── Page header ── */
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 14px;
}

.page-title-wrap {}
.page-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--dark);
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 10px;
}
.page-title-icon {
    width: 38px; height: 38px;
    background: linear-gradient(135deg,#e67e22,#f39c12);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}
.page-title-sub {
    font-size: 0.82rem;
    color: var(--text-muted);
    margin-top: 3px;
}

/* ── Filter bar ── */
.filter-bar {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
    flex-wrap: wrap;
    box-shadow: var(--shadow);
}

.filter-label {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--dark);
    white-space: nowrap;
}

.filter-input {
    padding: 7px 11px;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    font-size: 0.85rem;
    color: var(--text);
    background: var(--page-bg);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    font-family: inherit;
}
.filter-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(230,126,34,0.12);
}

.filter-select {
    padding: 7px 28px 7px 10px;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--page-bg);
    color: var(--text);
    outline: none;
    cursor: pointer;
    appearance: none;
    font-family: inherit;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 9px center;
}
.filter-select:focus { border-color: var(--primary); outline: none; }

.filter-sep {
    height: 24px;
    width: 1px;
    background: var(--card-border);
}

.btn-apply {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 18px;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s, transform 0.1s;
    font-family: inherit;
}
.btn-apply:hover  { background: #cf6d17; transform: translateY(-1px); }
.btn-apply:active { transform: translateY(0); }

.date-range-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: #fef3e8;
    border: 1px solid #fddcb5;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--primary);
    margin-left: auto;
    white-space: nowrap;
}

/* ── KPI Cards ── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media(max-width:1100px) { .kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:600px)  { .kpi-grid { grid-template-columns: 1fr; } }

.kpi-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    padding: 20px 20px 18px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    transition: transform 0.18s, box-shadow 0.18s;
}
.kpi-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

.kpi-accent {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 3px;
    border-radius: var(--radius) var(--radius) 0 0;
}

.kpi-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.kpi-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.kpi-label {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
}
.kpi-value {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--dark);
    line-height: 1;
    letter-spacing: -0.03em;
    margin-bottom: 4px;
}
.kpi-value.lg  { font-size: 1.4rem; }
.kpi-value.sm  { font-size: 1.2rem; }
.kpi-sub {
    font-size: 0.74rem;
    color: var(--text-muted);
    line-height: 1.4;
}
.kpi-sub strong { color: var(--primary); }

.kc-green  .kpi-accent { background: #10b981; } .kc-green  .kpi-icon { background:#ecfdf5; color:#10b981; }
.kc-blue   .kpi-accent { background: #3b82f6; } .kc-blue   .kpi-icon { background:#eff6ff; color:#3b82f6; }
.kc-orange .kpi-accent { background: #e67e22; } .kc-orange .kpi-icon { background:#fef3e8; color:#e67e22; }
.kc-purple .kpi-accent { background: #8b5cf6; } .kc-purple .kpi-icon { background:#f5f3ff; color:#8b5cf6; }

/* ── Charts grid ── */
.charts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
@media(max-width:900px) { .charts-grid { grid-template-columns: 1fr; } }

.chart-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    padding: 20px 22px;
    box-shadow: var(--shadow);
}

.chart-card.wide {
    grid-column: span 2;
}
@media(max-width:900px) { .chart-card.wide { grid-column: span 1; } }

.chart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
}
.chart-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 8px;
}
.chart-sub {
    font-size: 0.75rem;
    color: var(--text-muted);
}
.chart-wrap {
    position: relative;
    width: 100%;
}

/* ── Summary table ── */
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0;
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius) var(--radius) 0 0;
    padding: 14px 18px;
    border-bottom: none;
}
.section-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

.table-wrapper {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 0 0 var(--radius) var(--radius);
    overflow-x: auto;
    box-shadow: var(--shadow);
}
table.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.87rem;
}
table.report-table thead th {
    background: var(--dark);
    color: #e5e7eb;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    padding: 12px 16px;
    white-space: nowrap;
    text-align: left;
}
table.report-table thead th:last-child { text-align: right; }
table.report-table tbody tr:nth-child(even) { background: #faf9f7; }
table.report-table tbody tr:hover { background: #fef9f4; transition: background 0.12s; }
table.report-table tbody td {
    padding: 12px 16px;
    color: var(--text);
    border-bottom: 1px solid #f3f2f0;
    vertical-align: middle;
}
table.report-table tbody tr:last-child td { border-bottom: none; }
table.report-table tbody td:last-child { text-align: right; font-weight: 700; color: #10b981; }

/* Sales bar in table */
.sales-bar {
    display: flex;
    align-items: center;
    gap: 8px;
}
.bar-track {
    flex: 1;
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
    min-width: 60px;
}
.bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #e67e22, #f39c12);
    border-radius: 3px;
}
.bar-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-muted);
    white-space: nowrap;
}

/* Rank badge */
.rank-badge {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px;
    border-radius: 6px;
    font-size: 0.72rem; font-weight: 700;
}
.rank-1 { background: #fef3c7; color: #92400e; }
.rank-2 { background: #f3f4f6; color: #374151; }
.rank-3 { background: #fff7ed; color: #7c2d12; }
.rank-n { background: var(--page-bg); color: var(--text-muted); }

/* Export button */
.btn-export {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    background: transparent;
    color: var(--text-muted);
    border: 1px solid var(--card-border);
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    font-family: inherit;
}
.btn-export:hover { background: var(--page-bg); color: var(--dark); border-color: #aaa; }

/* Empty */
.empty-state {
    text-align: center;
    padding: 48px;
    color: var(--text-muted);
    font-size: 0.9rem;
}

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
    .date-range-badge { margin-left: 0; }
}
</style>
</head>
<body>
<div class="admin-layout">
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-title-wrap">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <button class="mobile-menu-btn" onclick="openSidebar()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h1 class="page-title">
                    <div class="page-title-icon"><i class="fa-solid fa-chart-line"></i></div>
                    Sales Report
                </h1>
            </div>
            <div class="page-title-sub">Analyze revenue, order trends, and product performance</div>
        </div>
    </div>

    <?= flash() ?>

    <!-- Filter Bar -->
    <form method="GET" action="report_sales.php">
        <div class="filter-bar">
            <span class="filter-label">Date Range:</span>
            <input type="date" name="from" class="filter-input" value="<?= htmlspecialchars($dateFrom) ?>">
            <span style="font-size:0.82rem;color:var(--text-muted);">to</span>
            <input type="date" name="to" class="filter-input" value="<?= htmlspecialchars($dateTo) ?>">

            <div class="filter-sep"></div>

            <span class="filter-label">Category:</span>
            <select name="cat" class="filter-select">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $catFilter==$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-apply">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Apply Filters
            </button>

            <!-- Quick ranges -->
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php
                $ranges = [
                    'Today'      => [date('Y-m-d'), date('Y-m-d')],
                    'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
                    'This Month' => [date('Y-m-01'), date('Y-m-d')],
                    'This Year'  => [date('Y-01-01'), date('Y-m-d')],
                ];
                foreach ($ranges as $lbl => [$f, $t]):
                    $isActive = ($dateFrom === $f && $dateTo === $t);
                ?>
                <a href="report_sales.php?from=<?= $f ?>&to=<?= $t ?>&cat=<?= $catFilter ?>"
                   style="padding:5px 11px;border-radius:6px;font-size:0.77rem;font-weight:600;text-decoration:none;
                          <?= $isActive ? 'background:var(--primary);color:#fff;' : 'background:var(--page-bg);color:var(--text-muted);border:1px solid var(--card-border);' ?>">
                    <?= $lbl ?>
                </a>
                <?php endforeach; ?>
            </div>

            <span class="date-range-badge">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <?= date('M j, Y', strtotime($dateFrom)) ?> – <?= date('M j, Y', strtotime($dateTo)) ?>
            </span>
        </div>
    </form>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card kc-green">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Total Sales</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
            </div>
            <div class="kpi-value lg"><?= peso($totalSales) ?></div>
            <div class="kpi-sub">Revenue in selected period</div>
        </div>

        <div class="kpi-card kc-blue">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Total Orders</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                </div>
            </div>
            <div class="kpi-value"><?= number_format($totalOrders) ?></div>
            <div class="kpi-sub">Non-cancelled orders</div>
        </div>

        <div class="kpi-card kc-orange">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Avg. Order Value</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
            </div>
            <div class="kpi-value lg"><?= peso($avgOrder) ?></div>
            <div class="kpi-sub">Per order average</div>
        </div>

        <div class="kpi-card kc-purple">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Best Seller</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                </div>
            </div>
            <div class="kpi-value sm" style="font-size:1rem;line-height:1.3;">
                <?= htmlspecialchars($bestProduct) ?>
            </div>
            <div class="kpi-sub"><strong><?= number_format($bestQty) ?></strong> units sold</div>
        </div>
    </div>

    <!-- Charts Row 1: Daily trend + Payment method -->
    <div class="charts-grid">
        <div class="chart-card wide">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Daily Sales Trend
                </div>
                <span class="chart-sub"><?= date('M j, Y', strtotime($dateFrom)) ?> – <?= date('M j, Y', strtotime($dateTo)) ?></span>
            </div>
            <div class="chart-wrap" style="height:230px;">
                <canvas id="dailyChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    Sales by Category
                </div>
                <span class="chart-sub">Revenue split</span>
            </div>
            <div class="chart-wrap" style="height:220px;">
                <canvas id="catChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Payment Method Breakdown
                </div>
                <span class="chart-sub">Order count</span>
            </div>
            <div class="chart-wrap" style="height:220px;">
                <canvas id="methodChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Chart Row 2: Monthly year view -->
    <div class="charts-grid" style="margin-bottom:24px;">
        <div class="chart-card wide">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Monthly Sales — <?= $thisYear ?>
                </div>
                <span class="chart-sub">Full year overview</span>
            </div>
            <div class="chart-wrap" style="height:200px;">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Summary Table -->
    <div class="section-header">
        <div class="section-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/></svg>
            Top Products by Revenue
        </div>
        <button class="btn-export" onclick="exportCSV()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </button>
    </div>
    <div class="table-wrapper">
        <?php
        $tableRows = [];
        $maxSales  = 0;
        if ($topProducts && $topProducts->num_rows > 0) {
            while ($tp = $topProducts->fetch_assoc()) {
                $tableRows[] = $tp;
                if ((float)$tp['total_sales'] > $maxSales) $maxSales = (float)$tp['total_sales'];
            }
        }
        ?>
        <?php if (!empty($tableRows)): ?>
        <table class="report-table" id="summaryTable">
            <thead>
                <tr>
                    <th style="width:44px;">Rank</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Orders</th>
                    <th>Units Sold</th>
                    <th>Revenue Share</th>
                    <th>Total Sales</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tableRows as $i => $tp):
                $rank   = $i + 1;
                $rClass = $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-n'));
                $pct    = $maxSales > 0 ? round(((float)$tp['total_sales'] / $maxSales) * 100) : 0;
            ?>
            <tr>
                <td><span class="rank-badge <?= $rClass ?>"><?= $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : "#{$rank}")) ?></span></td>
                <td style="font-weight:600;color:var(--dark);"><?= htmlspecialchars($tp['name']) ?></td>
                <td>
                    <span style="background:#f3f4f6;color:var(--text-muted);padding:2px 8px;border-radius:6px;font-size:0.78rem;font-weight:500;">
                        <?= htmlspecialchars($tp['category']) ?>
                    </span>
                </td>
                <td style="font-weight:600;"><?= number_format((int)$tp['order_count']) ?></td>
                <td style="font-weight:600;"><?= number_format((int)$tp['total_qty']) ?></td>
                <td>
                    <div class="sales-bar">
                        <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;"></div></div>
                        <span class="bar-label"><?= $pct ?>%</span>
                    </div>
                </td>
                <td><?= peso((float)$tp['total_sales']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <div style="font-size:2.5rem;margin-bottom:12px;"><i class="fa-solid fa-chart-bar"></i></div>
            <div>No sales data found for the selected period.</div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.main-content -->
</div><!-- /.admin-layout -->

<script>
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.color = '#9ca3af';

const peso    = v => '₱' + parseFloat(v).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
const compact = v => v >= 1000000 ? '₱' + (v/1000000).toFixed(1)+'M' : (v >= 1000 ? '₱' + (v/1000).toFixed(1)+'k' : '₱' + v);

const tooltipStyle = {
    backgroundColor: '#1f2937',
    titleColor: '#f9fafb',
    bodyColor: '#d1d5db',
    padding: 10,
    cornerRadius: 8,
    displayColors: true,
};

// ── Daily Sales Line Chart ────────────────────────────────────
(function() {
    const ctx = document.getElementById('dailyChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: <?= $dailyLblJson ?>,
            datasets: [{
                label: 'Sales',
                data: <?= $dailyDataJson ?>,
                fill: true,
                backgroundColor: function(context) {
                    const chart = context.chart;
                    const {ctx: c, chartArea} = chart;
                    if (!chartArea) return 'transparent';
                    const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradient.addColorStop(0, 'rgba(230,126,34,0.25)');
                    gradient.addColorStop(1, 'rgba(230,126,34,0.02)');
                    return gradient;
                },
                borderColor: '#e67e22',
                borderWidth: 2.5,
                pointBackgroundColor: '#e67e22',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.4,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: { label: c => '  ' + peso(c.parsed.y) }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 }, maxTicksLimit: 14 } },
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' },
                     ticks: { font: { size: 11 }, callback: compact } }
            }
        }
    });
})();

// ── Category Bar Chart ────────────────────────────────────────
(function() {
    const ctx = document.getElementById('catChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= $catNamesJson ?>,
            datasets: [{
                label: 'Revenue',
                data: <?= $catTotalsJson ?>,
                backgroundColor: ['rgba(230,126,34,0.85)', 'rgba(243,156,18,0.85)', 'rgba(16,185,129,0.85)', 'rgba(59,130,246,0.85)', 'rgba(139,92,246,0.85)'],
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: { label: c => '  ' + peso(c.parsed.x) }
                }
            },
            scales: {
                x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { callback: compact, font: { size: 11 } } },
                y: { grid: { display: false }, ticks: { font: { size: 12 } } }
            }
        }
    });
})();

// ── Payment Method Doughnut ───────────────────────────────────
(function() {
    const ctx = document.getElementById('methodChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?= $methodLblJson ?>,
            datasets: [{
                data: <?= $methodDataJson ?>,
                backgroundColor: <?= $methodColorJson ?>,
                borderWidth: 3,
                borderColor: '#fff',
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '66%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 11 }, padding: 14, boxWidth: 10, boxHeight: 10, borderRadius: 3 }
                },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: {
                        label: c => '  ' + c.label + ': ' + c.parsed + ' orders'
                    }
                }
            }
        }
    });
})();

// ── Monthly Bar Chart ─────────────────────────────────────────
(function() {
    const ctx = document.getElementById('monthlyChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= $monthlyLblJson ?>,
            datasets: [{
                label: 'Sales',
                data: <?= $monthlyDataJson ?>,
                backgroundColor: function(context) {
                    const {chart} = context;
                    if (!chart.chartArea) return '#e67e22';
                    const {top, bottom} = chart.chartArea;
                    const g = chart.ctx.createLinearGradient(0, top, 0, bottom);
                    g.addColorStop(0, 'rgba(230,126,34,0.88)');
                    g.addColorStop(1, 'rgba(243,156,18,0.3)');
                    return g;
                },
                borderColor: '#e67e22',
                borderWidth: 0,
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: { label: c => '  ' + peso(c.parsed.y) }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 12 } } },
                y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' },
                     ticks: { font: { size: 11 }, callback: compact } }
            }
        }
    });
})();

// ── CSV Export ────────────────────────────────────────────────
function exportCSV() {
    const table = document.getElementById('summaryTable');
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
    const blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'sales_report_<?= $dateFrom ?>_to_<?= $dateTo ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>