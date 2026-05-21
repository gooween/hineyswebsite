<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_orders.php
// Purpose: Orders analytics — status, trends, top customers
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$today    = date('Y-m-d');
$thisYear = date('Y');

// ── Date range filter ─────────────────────────────────────────
$dateFrom  = trim($_GET['from']   ?? date('Y-m-01'));
$dateTo    = trim($_GET['to']     ?? $today);
$statusFilter = trim($_GET['status'] ?? '');
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$dateFromSql = $conn->real_escape_string($dateFrom);
$dateToSql   = $conn->real_escape_string($dateTo);

$baseWhere = "WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'";
if ($statusFilter) {
    $baseWhere .= " AND o.status = '" . $conn->real_escape_string($statusFilter) . "'";
}

// ── KPI 1: Total orders in range ──────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders o {$baseWhere}");
$totalOrders = (int)($r->fetch_assoc()['cnt'] ?? 0);

// ── KPI 2: Delivered orders ───────────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders o
                   WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
                   AND o.status = 'delivered'");
$deliveredOrders = (int)($r->fetch_assoc()['cnt'] ?? 0);

// ── KPI 3: Pending orders ─────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders o
                   WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
                   AND o.status = 'pending'");
$pendingOrders = (int)($r->fetch_assoc()['cnt'] ?? 0);

// ── KPI 4: Cancelled orders ───────────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders o
                   WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
                   AND o.status = 'cancelled'");
$cancelledOrders = (int)($r->fetch_assoc()['cnt'] ?? 0);

// ── Chart 1: Orders by status (doughnut) ─────────────────────
$statusLabels = [];
$statusData   = [];
$statusColors = [];
$statusColorMap = [
    'pending'          => '#f59e0b',
    'confirmed'        => '#3b82f6',
    'processing'       => '#8b5cf6',
    'out_for_delivery' => '#f97316',
    'delivered'        => '#10b981',
    'cancelled'        => '#ef4444',
];
$r = $conn->query("
    SELECT o.status, COUNT(*) AS cnt
    FROM orders o
    WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
    GROUP BY o.status
    ORDER BY cnt DESC
");
while ($row = $r->fetch_assoc()) {
    $statusLabels[] = ucwords(str_replace('_', ' ', $row['status']));
    $statusData[]   = (int)$row['cnt'];
    $statusColors[] = $statusColorMap[$row['status']] ?? '#9ca3af';
}

// ── Chart 2: Orders per day this week ─────────────────────────
$weekLabels = [];
$weekData   = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $weekLabels[] = date('D', strtotime($d));
    $r2 = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE DATE(created_at) = '{$d}'");
    $weekData[] = (int)($r2->fetch_assoc()['cnt'] ?? 0);
}

// ── Chart 3: Monthly order volume this year (line) ────────────
$monthlyLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthlyData   = array_fill(0, 12, 0);
$r = $conn->query("
    SELECT MONTH(created_at) AS m, COUNT(*) AS cnt
    FROM orders
    WHERE YEAR(created_at) = '{$thisYear}'
    GROUP BY m
");
while ($row = $r->fetch_assoc()) {
    $monthlyData[(int)$row['m'] - 1] = (int)$row['cnt'];
}

// ── Chart 4: Top customers by order count (bar) ───────────────
$custLabels = [];
$custData   = [];
$r = $conn->query("
    SELECT u.full_name, COUNT(o.id) AS order_count
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
    GROUP BY o.user_id
    ORDER BY order_count DESC
    LIMIT 10
");
while ($row = $r->fetch_assoc()) {
    $custLabels[] = $row['full_name'];
    $custData[]   = (int)$row['order_count'];
}

// ── Summary table: Orders list ────────────────────────────────
$ordersTable = $conn->query("
    SELECT o.id, o.status, o.total_amount, o.payment_method,
           o.payment_status, o.created_at,
           u.full_name, u.email,
           COUNT(oi.id) AS item_count
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    {$baseWhere}
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 50
");

// ── Status options for filter ─────────────────────────────────
$allStatuses = [
    'pending'          => 'Pending',
    'confirmed'        => 'Confirmed',
    'processing'       => 'Processing',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
];

// JSON encode
$statusLblJson   = json_encode($statusLabels);
$statusDataJson  = json_encode($statusData);
$statusColorJson = json_encode($statusColors);
$weekLblJson     = json_encode($weekLabels);
$weekDataJson    = json_encode($weekData);
$monthlyLblJson  = json_encode($monthlyLabels);
$monthlyDataJson = json_encode($monthlyData);
$custLblJson     = json_encode($custLabels);
$custDataJson    = json_encode($custData);

$activePage = 'report_orders';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Orders Report — Hiney's Admin</title>
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

/* ── Page header ── */
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
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.page-title-sub { font-size: 0.82rem; color: var(--text-muted); margin-top: 3px; }

/* ── Filter bar ── */
.filter-bar {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius); padding: 14px 18px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 24px; flex-wrap: wrap; box-shadow: var(--shadow);
}
.filter-label { font-size: 0.8rem; font-weight: 700; color: var(--dark); white-space: nowrap; }
.filter-input {
    padding: 7px 11px; border: 1px solid var(--card-border);
    border-radius: 8px; font-size: 0.85rem; color: var(--text);
    background: var(--page-bg); outline: none; font-family: inherit;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.filter-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.12); }
.filter-select {
    padding: 7px 28px 7px 10px; border: 1px solid var(--card-border);
    border-radius: 8px; font-size: 0.85rem; background: var(--page-bg);
    color: var(--text); outline: none; cursor: pointer; appearance: none;
    font-family: inherit;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 9px center;
}
.filter-select:focus { border-color: var(--primary); outline: none; }
.filter-sep { height: 24px; width: 1px; background: var(--card-border); }
.btn-apply {
    display: flex; align-items: center; gap: 6px; padding: 8px 18px;
    background: var(--primary); color: #fff; border: none; border-radius: 8px;
    font-size: 0.85rem; font-weight: 600; cursor: pointer; font-family: inherit;
    transition: background 0.15s, transform 0.1s;
}
.btn-apply:hover  { background: #cf6d17; transform: translateY(-1px); }
.btn-apply:active { transform: translateY(0); }
.date-range-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: 20px; font-size: 0.78rem; font-weight: 600; color: #1e40af;
    margin-left: auto; white-space: nowrap;
}
.quick-ranges { display: flex; gap: 6px; flex-wrap: wrap; }
.qr-link {
    padding: 5px 11px; border-radius: 6px; font-size: 0.77rem;
    font-weight: 600; text-decoration: none;
    border: 1px solid var(--card-border);
    background: var(--page-bg); color: var(--text-muted);
    transition: background 0.12s, color 0.12s;
}
.qr-link:hover { background: #e5e7eb; color: var(--dark); }
.qr-link.active { background: var(--primary); color: #fff; border-color: var(--primary); }

/* ── KPI Cards ── */
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
.kpi-value  { font-size: 1.9rem; font-weight: 800; color: var(--dark); line-height: 1; letter-spacing: -0.03em; margin-bottom: 4px; }
.kpi-sub    { font-size: 0.74rem; color: var(--text-muted); }
.kpi-sub .hi { font-weight: 700; }

.kc-blue   .kpi-accent { background: #3b82f6; } .kc-blue   .kpi-icon { background: #eff6ff; color: #3b82f6; }
.kc-green  .kpi-accent { background: #10b981; } .kc-green  .kpi-icon { background: #ecfdf5; color: #10b981; }
.kc-amber  .kpi-accent { background: #f59e0b; } .kc-amber  .kpi-icon { background: #fffbeb; color: #f59e0b; }
.kc-red    .kpi-accent { background: #ef4444; } .kc-red    .kpi-icon { background: #fef2f2; color: #ef4444; }

/* Delivery rate ring */
.kpi-rate {
    display: inline-block;
    background: #d1fae5; color: #065f46;
    font-size: 0.72rem; font-weight: 700;
    padding: 2px 8px; border-radius: 12px;
    margin-top: 5px;
}

/* ── Charts grid ── */
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

/* ── Summary table ── */
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
table.report-table tbody tr:nth-child(even) { background: #faf9f7; }
table.report-table tbody tr:hover { background: #fef9f4; transition: background 0.12s; }
table.report-table tbody td {
    padding: 11px 16px; color: var(--text);
    border-bottom: 1px solid #f3f2f0; vertical-align: middle;
}
table.report-table tbody tr:last-child td { border-bottom: none; }

/* Status badges */
.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 20px;
    font-size: 0.72rem; font-weight: 600; white-space: nowrap;
}
.badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.b-pending          { background:#fef3c7; color:#92400e; }
.b-confirmed        { background:#dbeafe; color:#1e40af; }
.b-processing       { background:#ede9fe; color:#5b21b6; }
.b-out_for_delivery { background:#ffedd5; color:#9a3412; }
.b-delivered        { background:#d1fae5; color:#065f46; }
.b-cancelled        { background:#fee2e2; color:#991b1b; }

/* Payment badge */
.pm-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 12px;
    font-size: 0.72rem; font-weight: 600;
}
.pm-gcash { background: #ede9fe; color: #5b21b6; }
.pm-cash  { background: #d1fae5; color: #065f46; }
.pm-cod   { background: #dbeafe; color: #1e40af; }

/* Customer cell */
.cust-cell { display: flex; align-items: center; gap: 8px; }
.cust-avatar {
    width: 30px; height: 30px; border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.cust-name { font-weight: 600; font-size: 0.86rem; color: var(--dark); }
.cust-email { font-size: 0.72rem; color: var(--text-muted); }

/* Conversion bar */
.conv-wrap { display: flex; align-items: center; gap: 8px; }
.conv-track {
    flex: 1; height: 5px; background: #e5e7eb;
    border-radius: 3px; overflow: hidden; min-width: 60px;
}
.conv-fill { height: 100%; border-radius: 3px; }

/* Export button */
.btn-export {
    display: flex; align-items: center; gap: 6px; padding: 7px 14px;
    background: transparent; color: var(--text-muted);
    border: 1px solid var(--card-border); border-radius: 8px;
    font-size: 0.82rem; font-weight: 600; cursor: pointer;
    transition: all 0.15s; font-family: inherit;
}
.btn-export:hover { background: var(--page-bg); color: var(--dark); border-color: #aaa; }

/* Completion rate strip */
.completion-strip {
    display: flex; align-items: center; gap: 0;
    border-radius: 8px; overflow: hidden;
    height: 8px; margin-bottom: 18px;
    background: var(--card-border);
}
.cs-segment { height: 100%; transition: width 0.4s ease; }

/* Empty state */
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
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <button class="mobile-menu-btn" onclick="openSidebar()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h1 class="page-title">
                    <div class="page-title-icon">📋</div>
                    Orders Report
                </h1>
            </div>
            <div class="page-title-sub">Track order volume, fulfillment rates, and top customers</div>
        </div>
    </div>

    <?= flash() ?>

    <!-- Filter Bar -->
    <form method="GET" action="report_orders.php">
        <div class="filter-bar">
            <span class="filter-label">Date Range:</span>
            <input type="date" name="from" class="filter-input" value="<?= htmlspecialchars($dateFrom) ?>">
            <span style="font-size:0.82rem;color:var(--text-muted);">to</span>
            <input type="date" name="to" class="filter-input" value="<?= htmlspecialchars($dateTo) ?>">

            <div class="filter-sep"></div>

            <span class="filter-label">Status:</span>
            <select name="status" class="filter-select">
                <option value="">All Statuses</option>
                <?php foreach ($allStatuses as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn-apply">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Apply Filters
            </button>

            <!-- Quick ranges -->
            <div class="quick-ranges">
                <?php
                $ranges = [
                    'Today'      => [date('Y-m-d'), date('Y-m-d')],
                    'This Week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
                    'This Month' => [date('Y-m-01'), date('Y-m-d')],
                    'This Year'  => [date('Y-01-01'), date('Y-m-d')],
                ];
                foreach ($ranges as $lbl => [$f, $t]):
                    $isActive = ($dateFrom === $f && $dateTo === $t && !$statusFilter);
                ?>
                <a href="report_orders.php?from=<?= $f ?>&to=<?= $t ?>"
                   class="qr-link <?= $isActive ? 'active' : '' ?>">
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
        <div class="kpi-card kc-blue">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Total Orders</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                </div>
            </div>
            <div class="kpi-value"><?= number_format($totalOrders) ?></div>
            <div class="kpi-sub">In selected period</div>
        </div>

        <div class="kpi-card kc-green">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Delivered</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
            </div>
            <div class="kpi-value"><?= number_format($deliveredOrders) ?></div>
            <div class="kpi-sub">
                <?php if ($totalOrders > 0): ?>
                    <span class="kpi-rate"><?= round(($deliveredOrders / $totalOrders) * 100) ?>% delivery rate</span>
                <?php else: ?>
                    No orders in range
                <?php endif; ?>
            </div>
        </div>

        <div class="kpi-card kc-amber">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Pending</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
            </div>
            <div class="kpi-value"><?= number_format($pendingOrders) ?></div>
            <div class="kpi-sub">Awaiting confirmation</div>
        </div>

        <div class="kpi-card kc-red">
            <div class="kpi-accent"></div>
            <div class="kpi-header">
                <div class="kpi-label">Cancelled</div>
                <div class="kpi-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
            </div>
            <div class="kpi-value"><?= number_format($cancelledOrders) ?></div>
            <div class="kpi-sub">
                <?php if ($totalOrders > 0): ?>
                    <span class="hi" style="color:#ef4444;"><?= round(($cancelledOrders / $totalOrders) * 100) ?>%</span> cancellation rate
                <?php else: ?>
                    No orders in range
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Status completion strip (visual) -->
    <?php if ($totalOrders > 0): ?>
    <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius);padding:16px 20px;margin-bottom:24px;box-shadow:var(--shadow);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <div style="font-size:0.88rem;font-weight:700;color:var(--dark);">Order Status Breakdown</div>
            <div style="font-size:0.78rem;color:var(--text-muted);"><?= number_format($totalOrders) ?> total orders</div>
        </div>
        <div class="completion-strip">
            <?php
            $colorMapStrip = [
                'delivered'        => '#10b981',
                'confirmed'        => '#3b82f6',
                'processing'       => '#8b5cf6',
                'out_for_delivery' => '#f97316',
                'pending'          => '#f59e0b',
                'cancelled'        => '#ef4444',
            ];
            foreach ($colorMapStrip as $st => $col):
                $rr = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE DATE(created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}' AND status='{$st}'");
                $cnt = (int)($rr->fetch_assoc()['cnt'] ?? 0);
                $pct = $totalOrders > 0 ? round(($cnt / $totalOrders) * 100) : 0;
                if ($pct <= 0) continue;
            ?>
            <div class="cs-segment" style="width:<?= $pct ?>%;background:<?= $col ?>;" title="<?= ucwords(str_replace('_',' ',$st)) ?>: <?= $cnt ?> (<?= $pct ?>%)"></div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;align-items:center;gap:14px;margin-top:10px;flex-wrap:wrap;">
            <?php foreach ($colorMapStrip as $st => $col):
                $rr2 = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE DATE(created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}' AND status='{$st}'");
                $cnt2 = (int)($rr2->fetch_assoc()['cnt'] ?? 0);
                if ($cnt2 === 0) continue;
                $pct2 = $totalOrders > 0 ? round(($cnt2 / $totalOrders) * 100) : 0;
            ?>
            <div style="display:flex;align-items:center;gap:5px;font-size:0.75rem;color:var(--text-muted);">
                <div style="width:8px;height:8px;border-radius:50%;background:<?= $col ?>;flex-shrink:0;"></div>
                <?= ucwords(str_replace('_',' ',$st)) ?>: <strong style="color:var(--dark);"><?= $cnt2 ?></strong> (<?= $pct2 ?>%)
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Charts Row 1: Status doughnut + Weekly bar -->
    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>
                    Orders by Status
                </div>
                <span class="chart-sub">Selected period</span>
            </div>
            <div style="position:relative;width:100%;height:240px;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    Orders This Week
                </div>
                <span class="chart-sub">Last 7 days</span>
            </div>
            <div style="position:relative;width:100%;height:240px;">
                <canvas id="weekChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Charts Row 2: Monthly trend + Top customers -->
    <div class="charts-grid" style="margin-bottom:24px;">
        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Order Volume — <?= $thisYear ?>
                </div>
                <span class="chart-sub">Monthly trend</span>
            </div>
            <div style="position:relative;width:100%;height:220px;">
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <div class="chart-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Top Customers by Orders
                </div>
                <span class="chart-sub">Most frequent buyers</span>
            </div>
            <div style="position:relative;width:100%;height:220px;">
                <canvas id="custChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Summary Table: Recent Orders -->
    <div class="section-header">
        <div class="section-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/></svg>
            Recent Orders
            <span style="background:var(--primary);color:#fff;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:12px;margin-left:4px;">
                Last 50
            </span>
        </div>
        <button class="btn-export" onclick="exportCSV()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </button>
    </div>
    <div class="table-wrapper">
        <?php
        $rows = [];
        if ($ordersTable && $ordersTable->num_rows > 0) {
            while ($row = $ordersTable->fetch_assoc()) $rows[] = $row;
        }

        // Pre-compute max total for bar
        $maxTotal = 1;
        foreach ($rows as $r) { if ((float)$r['total_amount'] > $maxTotal) $maxTotal = (float)$r['total_amount']; }
        ?>
        <?php if (!empty($rows)): ?>
        <table class="report-table" id="ordersTable">
            <thead>
                <tr>
                    <th style="width:44px;">#</th>
                    <th>Order ID</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Total Amount</th>
                    <th>Payment</th>
                    <th>Pay Status</th>
                    <th>Order Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $o):
                $initial  = strtoupper(substr($o['full_name'], 0, 1));
                $statusCls = 'b-' . $o['status'];
                $statusLbl = ucwords(str_replace('_', ' ', $o['status']));
                $pmCls     = 'pm-' . $o['payment_method'];
                $pmIcon    = $o['payment_method'] === 'gcash' ? '📱' : ($o['payment_method'] === 'cod' ? '🚚' : '💵');
                $pct       = $maxTotal > 0 ? round(((float)$o['total_amount'] / $maxTotal) * 100) : 0;
                $barColor  = $o['status'] === 'cancelled' ? '#ef4444' : ($o['status'] === 'delivered' ? '#10b981' : '#3b82f6');
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $i + 1 ?></td>
                <td style="font-weight:700;color:var(--primary);">#<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?></td>
                <td>
                    <div class="cust-cell">
                        <div class="cust-avatar"><?= htmlspecialchars($initial) ?></div>
                        <div>
                            <div class="cust-name"><?= htmlspecialchars($o['full_name']) ?></div>
                            <div class="cust-email"><?= htmlspecialchars($o['email']) ?></div>
                        </div>
                    </div>
                </td>
                <td style="font-weight:600;text-align:center;"><?= (int)$o['item_count'] ?></td>
                <td>
                    <div class="conv-wrap">
                        <span style="font-weight:700;color:var(--dark);white-space:nowrap;"><?= peso((float)$o['total_amount']) ?></span>
                        <div class="conv-track"><div class="conv-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div></div>
                    </div>
                </td>
                <td><span class="pm-badge <?= $pmCls ?>"><?= $pmIcon ?> <?= strtoupper($o['payment_method']) ?></span></td>
                <td>
                    <?php if ($o['payment_status'] === 'paid'): ?>
                        <span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:12px;font-size:0.72rem;font-weight:600;">✓ Paid</span>
                    <?php else: ?>
                        <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:12px;font-size:0.72rem;font-weight:600;">Unpaid</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $statusCls ?>"><?= $statusLbl ?></span></td>
                <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;">
                    <?= date('M j, Y', strtotime($o['created_at'])) ?><br>
                    <span style="font-size:0.72rem;"><?= date('g:i A', strtotime($o['created_at'])) ?></span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <div style="font-size:2.5rem;margin-bottom:12px;">📋</div>
            <div>No orders found for the selected period<?= $statusFilter ? ' and status filter' : '' ?>.</div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.main-content -->
</div><!-- /.admin-layout -->

<script>
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.color = '#9ca3af';

const tooltipStyle = {
    backgroundColor: '#1f2937',
    titleColor: '#f9fafb',
    bodyColor: '#d1d5db',
    padding: 10,
    cornerRadius: 8,
};

// ── 1. Orders by Status (doughnut) ───────────────────────────
(function() {
    const ctx = document.getElementById('statusChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?= $statusLblJson ?>,
            datasets: [{
                data: <?= $statusDataJson ?>,
                backgroundColor: <?= $statusColorJson ?>,
                borderWidth: 3,
                borderColor: '#fff',
                hoverOffset: 7,
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
                    callbacks: { label: c => '  ' + c.label + ': ' + c.parsed + ' orders' }
                }
            }
        }
    });
})();

// ── 2. Orders This Week (bar) ─────────────────────────────────
(function() {
    const ctx = document.getElementById('weekChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= $weekLblJson ?>,
            datasets: [{
                label: 'Orders',
                data: <?= $weekDataJson ?>,
                backgroundColor: function(context) {
                    const {chart} = context;
                    if (!chart.chartArea) return '#3b82f6';
                    const {top, bottom} = chart.chartArea;
                    const g = chart.ctx.createLinearGradient(0, top, 0, bottom);
                    g.addColorStop(0, 'rgba(59,130,246,0.88)');
                    g.addColorStop(1, 'rgba(99,102,241,0.35)');
                    return g;
                },
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipStyle,
                    callbacks: { label: c => '  ' + c.parsed.y + ' orders' }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 12 } } },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { font: { size: 11 }, stepSize: 1 }
                }
            }
        }
    });
})();

// ── 3. Monthly Order Volume (line) ────────────────────────────
(function() {
    const ctx = document.getElementById('monthlyChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: <?= $monthlyLblJson ?>,
            datasets: [{
                label: 'Orders',
                data: <?= $monthlyDataJson ?>,
                fill: true,
                backgroundColor: function(context) {
                    const {chart} = context;
                    const {ctx: c, chartArea} = chart;
                    if (!chartArea) return 'transparent';
                    const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    g.addColorStop(0, 'rgba(59,130,246,0.22)');
                    g.addColorStop(1, 'rgba(99,102,241,0.02)');
                    return g;
                },
                borderColor: '#3b82f6',
                borderWidth: 2.5,
                pointBackgroundColor: '#3b82f6',
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
                    callbacks: { label: c => '  ' + c.parsed.y + ' orders' }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 12 } } },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { font: { size: 11 }, stepSize: 1 }
                }
            }
        }
    });
})();

// ── 4. Top Customers (horizontal bar) ────────────────────────
(function() {
    const ctx = document.getElementById('custChart');
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= $custLblJson ?>,
            datasets: [{
                label: 'Orders',
                data: <?= $custDataJson ?>,
                backgroundColor: [
                    'rgba(230,126,34,0.85)','rgba(243,156,18,0.85)',
                    'rgba(16,185,129,0.85)','rgba(59,130,246,0.85)',
                    'rgba(139,92,246,0.85)','rgba(245,158,11,0.85)',
                    'rgba(239,68,68,0.75)', 'rgba(99,102,241,0.8)',
                    'rgba(20,184,166,0.85)','rgba(249,115,22,0.85)',
                ],
                borderRadius: 6,
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
                    callbacks: { label: c => '  ' + c.parsed.x + ' orders' }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { font: { size: 11 }, stepSize: 1 }
                },
                y: { grid: { display: false }, ticks: { font: { size: 11 } } }
            }
        }
    });
})();

// ── CSV Export ────────────────────────────────────────────────
function exportCSV() {
    const table = document.getElementById('ordersTable');
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
    a.download = 'orders_report_<?= $dateFrom ?>_to_<?= $dateTo ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>