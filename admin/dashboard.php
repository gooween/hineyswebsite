<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/dashboard.php
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$activePage = 'dashboard';

// ── Stats ─────────────────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE is_active = 1");
$totalProducts = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE DATE(created_at) = CURDATE()");
$ordersToday = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS total FROM orders WHERE DATE(created_at) = CURDATE() AND status != 'cancelled'");
$salesToday = (float)($r->fetch_assoc()['total'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM inventory WHERE quantity <= reorder_level");
$lowStock = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'pending'");
$pendingOrders = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'customer'");
$totalCustomers = (int)($r->fetch_assoc()['cnt'] ?? 0);

// ── Latest 5 orders ───────────────────────────────────────────
$latestOrders = $conn->query("
    SELECT o.id, u.full_name, o.total_amount, o.status,
           o.payment_status, o.created_at,
           COUNT(oi.id) AS item_count
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 5
");

// ── Low stock products ────────────────────────────────────────
$lowStockProducts = $conn->query("
    SELECT p.name, i.quantity, i.reorder_level, c.name AS category
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE i.quantity <= i.reorder_level AND p.is_active = 1
    ORDER BY i.quantity ASC
    LIMIT 8
");

// ── Sales this week ───────────────────────────────────────────
$salesWeekData = [];
$salesWeekLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-{$i} days"));
    $label = date('D', strtotime($date));
    $r = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS s FROM orders WHERE DATE(created_at)='{$date}' AND status != 'cancelled'");
    $salesWeekData[]   = (float)($r->fetch_assoc()['s'] ?? 0);
    $salesWeekLabels[] = $label;
}

// ── Orders by status ──────────────────────────────────────────
$statusData = $statusLabels = $statusColors = [];
$statusMap = [
    'pending'          => '#f59e0b',
    'confirmed'        => '#3b82f6',
    'processing'       => '#8b5cf6',
    'out_for_delivery' => '#f97316',
    'delivered'        => '#10b981',
    'cancelled'        => '#ef4444',
];
$r = $conn->query("SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status ORDER BY cnt DESC");
while ($row = $r->fetch_assoc()) {
    $statusLabels[] = ucwords(str_replace('_', ' ', $row['status']));
    $statusData[]   = (int)$row['cnt'];
    $statusColors[] = $statusMap[$row['status']] ?? '#9ca3af';
}

// ── Monthly sales ─────────────────────────────────────────────
$monthlyLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$monthlyData   = array_fill(0, 12, 0);
$year = date('Y');
$r = $conn->query("SELECT MONTH(created_at) AS m, COALESCE(SUM(total_amount),0) AS s FROM orders WHERE YEAR(created_at)='{$year}' AND status != 'cancelled' GROUP BY m");
while ($row = $r->fetch_assoc()) {
    $monthlyData[(int)$row['m'] - 1] = (float)$row['s'];
}

$salesWeekJson    = json_encode($salesWeekData);
$salesWeekLblJson = json_encode($salesWeekLabels);
$statusDataJson   = json_encode($statusData);
$statusLblJson    = json_encode($statusLabels);
$statusColorJson  = json_encode($statusColors);
$monthlyDataJson  = json_encode($monthlyData);
$monthlyLblJson   = json_encode($monthlyLabels);
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
<title>Dashboard — Hiney's Admin</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ── Main content ── */
.main-content {
    margin-left: var(--sidebar-w);
    flex: 1;
    padding: 32px 32px 48px;
    min-height: 100vh;
    background: var(--page-bg);
    transition: margin-left 0.3s ease;
}

/* Page header */
.page-header {
    display: flex;
    align-items: center;
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

.page-date-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    padding: 8px 16px;
    border-radius: 24px;
    font-size: 0.82rem;
    color: var(--text-muted);
    box-shadow: var(--shadow);
}

.page-date-chip svg { flex-shrink: 0; opacity: 0.6; }

/* ── Stat cards ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

@media(max-width:1100px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:640px)  { .stats-grid { grid-template-columns: 1fr; } }

.stat-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    padding: 20px 20px 18px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-accent {
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 3px;
    border-radius: var(--radius) var(--radius) 0 0;
}

.stat-icon-wrap {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.stat-body { flex: 1; }

.stat-value {
    font-size: 1.85rem;
    font-weight: 800;
    line-height: 1;
    color: var(--dark);
    letter-spacing: -0.03em;
    margin-bottom: 4px;
}

.stat-value.money { font-size: 1.4rem; }

.stat-label {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.07em;
    font-weight: 700;
}

.stat-sub {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 4px;
}

/* Color variants */
.sc-orange .stat-card-accent { background: #e67e22; }
.sc-orange .stat-icon-wrap   { background: #fef3e8; color: #e67e22; }
.sc-blue   .stat-card-accent { background: #3b82f6; }
.sc-blue   .stat-icon-wrap   { background: #eff6ff; color: #3b82f6; }
.sc-green  .stat-card-accent { background: #10b981; }
.sc-green  .stat-icon-wrap   { background: #ecfdf5; color: #10b981; }
.sc-red    .stat-card-accent { background: #ef4444; }
.sc-red    .stat-icon-wrap   { background: #fef2f2; color: #ef4444; }
.sc-amber  .stat-card-accent { background: #f59e0b; }
.sc-amber  .stat-icon-wrap   { background: #fffbeb; color: #f59e0b; }
.sc-purple .stat-card-accent { background: #8b5cf6; }
.sc-purple .stat-icon-wrap   { background: #f5f3ff; color: #8b5cf6; }

/* Pulse for alerts */
.pulse-ring {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.pulse-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #ef4444;
    animation: ring 1.4s ease infinite;
}

.pulse-dot.amber { background: #f59e0b; }

@keyframes ring {
    0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); }
    50%      { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
}

/* ── Charts layout ── */
.charts-row {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}

@media(max-width:900px) { .charts-row { grid-template-columns: 1fr; } }

.chart-card {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    padding: 20px 22px;
    box-shadow: var(--shadow);
}

.chart-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
}

.chart-card-title {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 7px;
}

.chart-legend {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.chart-wrap {
    position: relative;
    width: 100%;
}

/* ── Monthly full ── */
.chart-full {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius);
    padding: 20px 22px;
    box-shadow: var(--shadow);
    margin-bottom: 24px;
}

/* ── Tables ── */
.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}

@media(max-width:900px) { .two-col { grid-template-columns: 1fr; } }

.mini-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.845rem;
}

.mini-table thead th {
    padding: 10px 16px;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    background: #f9f8f6;
    border-bottom: 1px solid var(--card-border);
    white-space: nowrap;
}

.mini-table thead th:first-child { border-radius: 0; }

.mini-table tbody tr {
    border-bottom: 1px solid #f3f2f0;
    transition: background 0.12s;
}

.mini-table tbody tr:last-child { border-bottom: none; }
.mini-table tbody tr:hover { background: #fef9f4; }

.mini-table tbody td {
    padding: 11px 16px;
    color: var(--text);
    vertical-align: middle;
}

/* View all */
.view-all {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--primary);
    text-decoration: none;
    padding: 4px 10px;
    border-radius: 6px;
    transition: background 0.15s;
}

.view-all:hover { background: #fef3e8; }

/* Empty state */
.empty-state {
    text-align: center;
    padding: 36px 20px;
    color: var(--text-muted);
}

.empty-state-icon {
    font-size: 2.2rem;
    display: block;
    margin-bottom: 10px;
}

.empty-state-text { font-size: 0.88rem; }

/* Badge count */
.count-badge {
    background: #fee2e2;
    color: #991b1b;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 12px;
    margin-left: 4px;
}

/* Mobile topbar */
.mobile-topbar {
    display: none;
    align-items: center;
    justify-content: space-between;
    background: var(--card-bg);
    padding: 12px 16px;
    box-shadow: 0 1px 0 var(--card-border), 0 4px 16px rgba(0,0,0,0.04);
    position: sticky; top: 0; z-index: 900;
    margin-bottom: 20px;
}

.mobile-topbar-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1rem;
    font-weight: 700;
    color: var(--dark);
}

.mobile-topbar-icon {
    width: 30px; height: 30px;
    background: var(--primary);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
}

.hamburger-btn {
    background: none;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    color: var(--dark);
    font-size: 1.1rem;
}

@media(max-width:768px) {
    .mobile-topbar { display: flex; }
    .main-content  { margin-left: 0; padding: 0 14px 32px; }
}
</style>
</head>
<body>
<div class="admin-layout">

<?php include '../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Mobile topbar -->
    <div class="mobile-topbar">
        <div class="mobile-topbar-brand">
            <div class="mobile-topbar-icon"><i class="fa-solid fa-egg"></i></div>
            Hiney's Admin
        </div>
        <button class="hamburger-btn" onclick="openSidebar()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
    </div>

    <!-- Page header -->
    <div class="page-header">
        <div>
            <div class="page-title">Dashboard</div>
            <div class="page-title-sub">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></div>
        </div>
        <div class="page-date-chip">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <?= date('l, F j, Y') ?> &nbsp;·&nbsp; <?= date('g:i A') ?>
        </div>
    </div>

    <?= flash() ?>

    <!-- ── Stat Cards ── -->
    <div class="stats-grid">

        <div class="stat-card sc-orange">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format($totalProducts) ?></div>
                <div class="stat-label">Active Products</div>
                <div class="stat-sub">All listed products</div>
            </div>
        </div>

        <div class="stat-card sc-blue">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format($ordersToday) ?></div>
                <div class="stat-label">Orders Today</div>
                <div class="stat-sub"><?= date('F j') ?></div>
            </div>
        </div>

        <div class="stat-card sc-green">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value money"><?= peso($salesToday) ?></div>
                <div class="stat-label">Sales Today</div>
                <div class="stat-sub">Excl. cancelled orders</div>
            </div>
        </div>

        <div class="stat-card sc-red">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value">
                    <?php if ($lowStock > 0): ?>
                        <span class="pulse-ring"><span class="pulse-dot"></span><?= number_format($lowStock) ?></span>
                    <?php else: ?>
                        <?= number_format($lowStock) ?>
                    <?php endif; ?>
                </div>
                <div class="stat-label">Low Stock Items</div>
                <div class="stat-sub">At or below reorder level</div>
            </div>
        </div>

        <div class="stat-card sc-amber">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value">
                    <?php if ($pendingOrders > 0): ?>
                        <span class="pulse-ring"><span class="pulse-dot amber"></span><?= number_format($pendingOrders) ?></span>
                    <?php else: ?>
                        <?= number_format($pendingOrders) ?>
                    <?php endif; ?>
                </div>
                <div class="stat-label">Pending Orders</div>
                <div class="stat-sub">Awaiting confirmation</div>
            </div>
        </div>

        <div class="stat-card sc-purple">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format($totalCustomers) ?></div>
                <div class="stat-label">Total Customers</div>
                <div class="stat-sub">Registered accounts</div>
            </div>
        </div>

    </div><!-- /.stats-grid -->

    <!-- ── Charts ── -->
    <div class="charts-row">
        <div class="chart-card">
            <div class="chart-card-header">
                <div class="chart-card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Sales This Week
                </div>
                <span class="chart-legend">Last 7 days</span>
            </div>
            <div class="chart-wrap" style="height:220px;">
                <canvas id="weeklyChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <div class="chart-card-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Orders by Status
                </div>
            </div>
            <div class="chart-wrap" style="height:220px;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ── Monthly ── -->
    <div class="chart-full">
        <div class="chart-card-header">
            <div class="chart-card-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Monthly Sales — <?= $year ?>
            </div>
            <span class="chart-legend">Jan – Dec</span>
        </div>
        <div class="chart-wrap" style="height:200px;">
            <canvas id="monthlyChart"></canvas>
        </div>
    </div>

    <!-- ── Tables ── -->
    <div class="two-col">

        <!-- Latest Orders -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/></svg>
                    Latest Orders
                </div>
                <a href="orders.php" class="view-all">
                    View All
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
            <?php if ($latestOrders && $latestOrders->num_rows > 0): ?>
            <table class="mini-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($o = $latestOrders->fetch_assoc()): ?>
                    <tr>
                        <td style="font-weight:700;color:var(--primary);font-size:0.8rem;">#<?= str_pad($o['id'],4,'0',STR_PAD_LEFT) ?></td>
                        <td style="font-weight:500;"><?= htmlspecialchars($o['full_name']) ?></td>
                        <td style="font-weight:700;"><?= peso((float)$o['total_amount']) ?></td>
                        <td><?= paymentStatusBadge($o['payment_status']) ?></td>
                        <td><?= orderStatusBadge($o['status']) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="empty-state">
                    <span class="empty-state-icon"><i class="fa-solid fa-cart-shopping"></i></span>
                    <div class="empty-state-text">No orders placed today.</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Low Stock -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Low Stock
                    <?php if ($lowStock > 0): ?>
                        <span class="count-badge"><?= $lowStock ?> items</span>
                    <?php endif; ?>
                </div>
                <a href="inventory.php" class="view-all">
                    Manage
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>
            <?php if ($lowStockProducts && $lowStockProducts->num_rows > 0): ?>
            <table class="mini-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Qty</th>
                        <th>Reorder</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($ls = $lowStockProducts->fetch_assoc()): ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($ls['name']) ?></td>
                        <td style="color:var(--text-muted);font-size:0.8rem;"><?= htmlspecialchars($ls['category']) ?></td>
                        <td>
                            <span style="font-weight:700;color:<?= (int)$ls['quantity'] <= 0 ? '#ef4444' : '#f59e0b' ?>;">
                                <?= number_format((int)$ls['quantity']) ?>
                            </span>
                        </td>
                        <td style="color:var(--text-muted);"><?= number_format((int)$ls['reorder_level']) ?></td>
                        <td><?= stockStatusBadge((int)$ls['quantity'], (int)$ls['reorder_level']) ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="empty-state">
                    <span class="empty-state-icon">✓</span>
                    <div class="empty-state-text">All stock levels are healthy!</div>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.two-col -->

</div><!-- /.main-content -->
</div><!-- /.admin-layout -->

<script>
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.color = '#9ca3af';

const currency = v => '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const compact  = v => v >= 1000 ? '₱' + (v/1000).toFixed(1) + 'k' : '₱' + v;

// Weekly
(function() {
    new Chart(document.getElementById('weeklyChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= $salesWeekLblJson ?>,
            datasets: [{
                label: 'Sales',
                data: <?= $salesWeekJson ?>,
                backgroundColor: ctx => {
                    const g = ctx.chart.ctx.createLinearGradient(0, 0, 0, 200);
                    g.addColorStop(0, 'rgba(230,126,34,0.85)');
                    g.addColorStop(1, 'rgba(243,156,18,0.35)');
                    return g;
                },
                borderColor: '#e67e22',
                borderWidth: 0,
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: c => ' ' + currency(c.parsed.y) },
                    backgroundColor: '#1f2937',
                    titleColor: '#f9fafb',
                    bodyColor: '#d1d5db',
                    padding: 10,
                    cornerRadius: 8,
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

// Status donut
(function() {
    new Chart(document.getElementById('statusChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: <?= $statusLblJson ?>,
            datasets: [{
                data: <?= $statusDataJson ?>,
                backgroundColor: <?= $statusColorJson ?>,
                borderWidth: 3,
                borderColor: '#fff',
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 12, boxWidth: 10, boxHeight: 10, borderRadius: 3 } },
                tooltip: {
                    callbacks: { label: c => '  ' + c.label + ': ' + c.parsed },
                    backgroundColor: '#1f2937',
                    titleColor: '#f9fafb',
                    bodyColor: '#d1d5db',
                    padding: 10,
                    cornerRadius: 8,
                }
            }
        }
    });
})();

// Monthly
(function() {
    new Chart(document.getElementById('monthlyChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: <?= $monthlyLblJson ?>,
            datasets: [{
                label: 'Sales',
                data: <?= $monthlyDataJson ?>,
                backgroundColor: ctx => {
                    const {chart} = ctx;
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
                    callbacks: { label: c => ' ' + currency(c.parsed.y) },
                    backgroundColor: '#1f2937',
                    titleColor: '#f9fafb',
                    bodyColor: '#d1d5db',
                    padding: 10,
                    cornerRadius: 8,
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
</script>
</body>
</html>