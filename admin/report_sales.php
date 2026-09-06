<?php
// ============================================================
// HATCH — Hiney's Automated Tracking Commerce and Hub
// File: admin/report_sales.php
//
// Sales report (on-screen viewer). Revenue basis: PAID,
// non-cancelled orders in the selected date range — matching
// report_sales_print.php exactly.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$today = date('Y-m-d');

// ── Date range + status filter ────────────────────────────────
$dateFrom     = trim($_GET['from']   ?? date('Y-m-01'));
$dateTo       = trim($_GET['to']     ?? $today);
$statusFilter = trim($_GET['status'] ?? '');

// Period shortcut: daily / weekly / monthly (overrides from/to)
$period = trim($_GET['period'] ?? '');
if ($period === 'daily') {
    $dateFrom = date('Y-m-d');
    $dateTo = date('Y-m-d');
} elseif ($period === 'weekly') {
    $dateFrom = date('Y-m-d', strtotime('monday this week'));
    $dateTo = date('Y-m-d');
} elseif ($period === 'monthly') {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-d');
}
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$dateFromSql = $conn->real_escape_string($dateFrom);
$dateToSql   = $conn->real_escape_string($dateTo);

// Revenue basis: paid, non-cancelled orders in range.
// (Status filter narrows further when chosen.)
$paidWhere = "WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
              AND o.status <> 'cancelled' AND o.payment_status = 'paid'";
if ($statusFilter !== '') {
    $paidWhere .= " AND o.status = '" . $conn->real_escape_string($statusFilter) . "'";
}

// ── KPIs ──────────────────────────────────────────────────────
$k = $conn->query("
    SELECT COALESCE(SUM(o.total_amount),0) AS revenue,
           COUNT(*) AS paid_orders,
           COALESCE(AVG(o.total_amount),0) AS avg_order
    FROM orders o {$paidWhere}
")->fetch_assoc();
$totalRevenue = (float)($k['revenue'] ?? 0);
$paidOrders   = (int)($k['paid_orders'] ?? 0);
$avgOrder     = (float)($k['avg_order'] ?? 0);

// Units sold (from order_items on paid orders)
$unitsRow = $conn->query("
    SELECT COALESCE(SUM(oi.quantity),0) AS units
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    {$paidWhere}
")->fetch_assoc();
$totalUnits = (int)($unitsRow['units'] ?? 0);

// ── Top products by revenue ───────────────────────────────────
$topProducts = $conn->query("
    SELECT p.name, p.unit,
           SUM(oi.quantity) AS qty,
           SUM(oi.subtotal) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    {$paidWhere}
    GROUP BY oi.product_id
    ORDER BY revenue DESC
    LIMIT 15
");

// ── Category performance ──────────────────────────────────────
$catPerf = $conn->query("
    SELECT c.name AS category,
           SUM(oi.quantity) AS qty,
           SUM(oi.subtotal) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    {$paidWhere}
    GROUP BY c.id
    ORDER BY revenue DESC
");

// ── Payment method breakdown ──────────────────────────────────
$payBreak = $conn->query("
    SELECT o.payment_method,
           COUNT(*) AS cnt,
           COALESCE(SUM(o.total_amount),0) AS amt
    FROM orders o {$paidWhere}
    GROUP BY o.payment_method
    ORDER BY amt DESC
");

// ── Daily sales trend (for the chart) ─────────────────────────
$daily = $conn->query("
    SELECT DATE(o.created_at) AS d,
           COALESCE(SUM(o.total_amount),0) AS revenue
    FROM orders o {$paidWhere}
    GROUP BY DATE(o.created_at)
    ORDER BY d ASC
");
$trendLabels = [];
$trendData   = [];
if ($daily) {
    while ($row = $daily->fetch_assoc()) {
        $trendLabels[] = date('M j', strtotime($row['d']));
        $trendData[]   = round((float)$row['revenue'], 2);
    }
}
$trendLabelsJson = json_encode($trendLabels);
$trendDataJson   = json_encode($trendData);

// grand total of category revenue (for % column)
$catTotalRevenue = 0.0;
$catRows = [];
if ($catPerf) {
    while ($row = $catPerf->fetch_assoc()) {
        $catRows[] = $row;
        $catTotalRevenue += (float)$row['revenue'];
    }
}

// Status options for the filter
$allStatuses = [
    'pending'          => 'Pending',
    'approved'         => 'Approved',
    'processing'       => 'Processing',
    'out_for_delivery' => 'Out for Delivery',
    'delivered'        => 'Delivered',
];

$activePage = 'report_sales';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report — HATCH</title>
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

        .filter-input,
        .filter-select {
            padding: 7px 11px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            background: #fff;
            color: var(--ink);
            outline: none;
            font-family: inherit;
        }

        .filter-input:focus,
        .filter-select:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .filter-sep {
            width: 1px;
            height: 24px;
            background: var(--line-strong);
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

        .quick-ranges {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
        }

        .qr-link {
            padding: 5px 11px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-med);
            color: var(--ink-2);
            border: 1px solid var(--line-strong);
            white-space: nowrap;
            transition: all 0.14s;
        }

        .qr-link:hover {
            border-color: var(--ink-3);
            color: var(--ink);
        }

        .qr-link.active {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
            font-weight: var(--fw-semi);
        }

        .date-range-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-left: auto;
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            color: var(--ink-2);
            background: var(--surface-2);
            padding: 5px 11px;
            border-radius: var(--r-pill);
        }

        /* Action buttons */
        .report-actions {
            display: flex;
            align-items: center;
            gap: var(--s2);
        }

        .btn-print,
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

        .btn-print:hover,
        .btn-export:hover {
            background: var(--surface-2);
            color: var(--ink);
            border-color: var(--ink-3);
        }

        .btn-export {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
        }

        .btn-export:hover {
            background: var(--brand-strong);
            color: #fff;
            border-color: var(--brand-strong);
        }

        /* Section title */
        .section-title {
            font-size: var(--fs-h2);
            font-weight: var(--fw-bold);
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: var(--s2);
            margin: var(--s7) 0 var(--s4);
            letter-spacing: -0.01em;
        }

        .section-title i {
            color: var(--brand);
        }

        /* Chart card */
        .chart-card {
            padding: var(--s5);
        }

        .chart-wrap {
            position: relative;
            height: 300px;
        }

        /* Rank number */
        .rank {
            font-weight: var(--fw-bold);
            color: var(--ink-3);
            font-variant-numeric: tabular-nums;
        }

        .rank-1 {
            color: var(--brand-strong);
        }

        /* Bar cell (share of revenue) */
        .share-cell {
            min-width: 130px;
        }

        .share-bar {
            height: 6px;
            background: var(--line);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 4px;
        }

        .share-fill {
            height: 100%;
            background: var(--brand);
            border-radius: 3px;
        }

        .amt {
            font-weight: var(--fw-bold);
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        .pct {
            font-weight: var(--fw-semi);
            color: var(--ink-2);
            font-variant-numeric: tabular-nums;
        }

        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
        }

        .m-cash {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .m-gcash {
            background: var(--info-tint);
            color: #2b62ad;
        }

        .m-cod {
            background: var(--brand-tint);
            color: var(--brand-strong);
        }

        @media (max-width: 768px) {
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .date-range-badge {
                margin-left: 0;
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
                    <h1 class="page-title">Sales Report</h1>
                    <div class="page-title-sub">Revenue, top products, and category performance — based on paid orders</div>
                </div>
                <div class="report-actions">
                    <button class="btn-print" onclick="printReport()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 6 2 18 2 18 9" />
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                            <rect x="6" y="14" width="12" height="8" />
                        </svg>
                        Print
                    </button>
                    <button class="btn-export" onclick="exportCSV()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="7 10 12 15 17 10" />
                            <line x1="12" y1="15" x2="12" y2="3" />
                        </svg>
                        Export CSV
                    </button>
                </div>
            </div>

            <?= flash() ?>

            <!-- Filter Bar -->
            <form method="GET" action="report_sales.php">
                <div class="filter-bar">
                    <span class="filter-label">Date Range:</span>
                    <input type="date" name="from" class="filter-input" value="<?= htmlspecialchars($dateFrom) ?>">
                    <span style="font-size:var(--fs-sm);color:var(--ink-3);">to</span>
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
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <line x1="21" y1="21" x2="16.65" y2="16.65" />
                        </svg>
                        Apply
                    </button>

                    <div class="quick-ranges">
                        <?php
                        $ranges = [
                            'Today'      => ['daily',   [date('Y-m-d'), date('Y-m-d')]],
                            'This Week'  => ['weekly',  [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')]],
                            'This Month' => ['monthly', [date('Y-m-01'), date('Y-m-d')]],
                            'This Year'  => ['',        [date('Y-01-01'), date('Y-m-d')]],
                        ];
                        foreach ($ranges as $lbl => [$per, $ft]):
                            [$f, $t] = $ft;
                            $isActive = ($dateFrom === $f && $dateTo === $t && !$statusFilter);
                        ?>
                            <a href="report_sales.php?from=<?= $f ?>&to=<?= $t ?><?= $per ? '&period=' . $per : '' ?>" class="qr-link <?= $isActive ? 'active' : '' ?>"><?= $lbl ?></a>
                        <?php endforeach; ?>
                    </div>

                    <span class="date-range-badge">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                        </svg>
                        <?= date('M j, Y', strtotime($dateFrom)) ?> – <?= date('M j, Y', strtotime($dateTo)) ?>
                    </span>
                </div>
            </form>

            <!-- KPI cards -->
            <div class="grid cols-2 mb-6" style="grid-template-columns:repeat(4,1fr);">
                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Sales</span>
                        <div class="stat-icon"><i class="fa-solid fa-money-bill-trend-up"></i></div>
                    </div>
                    <div class="stat-value money">₱<?= number_format($totalRevenue, 0) ?></div>
                    <div class="stat-foot">Paid revenue in range</div>
                </div>
                <div class="stat-card tone-blue">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Paid Orders</span>
                        <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($paidOrders) ?></div>
                    <div class="stat-foot">Completed &amp; paid</div>
                </div>
                <div class="stat-card tone-amber">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Avg Order Value</span>
                        <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
                    </div>
                    <div class="stat-value money">₱<?= number_format($avgOrder, 0) ?></div>
                    <div class="stat-foot">Revenue ÷ orders</div>
                </div>
                <div class="stat-card tone-violet">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Units Sold</span>
                        <div class="stat-icon"><i class="fa-solid fa-cubes-stacked"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalUnits) ?></div>
                    <div class="stat-foot">Total items sold</div>
                </div>
            </div>

            <!-- Sales trend chart -->
            <div class="section-title"><i class="fa-solid fa-chart-line"></i> Sales Trend</div>
            <div class="table-card chart-card">
                <?php if (!empty($trendData)): ?>
                    <div class="chart-wrap"><canvas id="salesTrend"></canvas></div>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-chart-line"></i></div>
                        <div class="empty-title">No sales in this range</div>
                        <div class="empty-text">Try a different date range.</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top products -->
            <div class="section-title"><i class="fa-solid fa-ranking-star"></i> Top Products by Revenue</div>
            <div class="table-card">
                <?php if ($topProducts && $topProducts->num_rows > 0): ?>
                    <div class="table-scroll">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th style="width:44px;">#</th>
                                    <th>Product</th>
                                    <th>Unit</th>
                                    <th class="num">Qty Sold</th>
                                    <th class="num">Revenue</th>
                                    <th>Share of Sales</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rank = 1;
                                while ($p = $topProducts->fetch_assoc()):
                                    $rev = (float)$p['revenue'];
                                    $share = $totalRevenue > 0 ? ($rev / $totalRevenue) * 100 : 0;
                                ?>
                                    <tr>
                                        <td><span class="rank <?= $rank === 1 ? 'rank-1' : '' ?>"><?= $rank++ ?></span></td>
                                        <td style="font-weight:var(--fw-semi);color:var(--ink);"><?= htmlspecialchars($p['name']) ?></td>
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);"><?= htmlspecialchars($p['unit']) ?></td>
                                        <td class="num"><?= number_format((int)$p['qty']) ?></td>
                                        <td class="num amt">₱<?= number_format($rev, 2) ?></td>
                                        <td>
                                            <div class="share-cell">
                                                <span class="pct"><?= number_format($share, 1) ?>%</span>
                                                <div class="share-bar">
                                                    <div class="share-fill" style="width:<?= min(100, $share) ?>%;"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-box"></i></div>
                        <div class="empty-title">No product sales</div>
                        <div class="empty-text">No paid orders with items in this range.</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Category performance + Payment breakdown side by side -->
            <div class="grid cols-2" style="align-items:start;">
                <div>
                    <div class="section-title"><i class="fa-solid fa-layer-group"></i> Category Performance</div>
                    <div class="table-card">
                        <?php if (!empty($catRows)): ?>
                            <div class="table-scroll">
                                <table class="data">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th class="num">Units</th>
                                            <th class="num">Revenue</th>
                                            <th class="num">%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($catRows as $c):
                                            $rev = (float)$c['revenue'];
                                            $pct = $catTotalRevenue > 0 ? ($rev / $catTotalRevenue) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td style="font-weight:var(--fw-semi);color:var(--ink);"><?= htmlspecialchars($c['category']) ?></td>
                                                <td class="num"><?= number_format((int)$c['qty']) ?></td>
                                                <td class="num amt">₱<?= number_format($rev, 2) ?></td>
                                                <td class="num pct"><?= number_format($pct, 1) ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr style="border-top:2px solid var(--line-strong);">
                                            <td style="font-weight:var(--fw-bold);color:var(--ink);">Total</td>
                                            <td></td>
                                            <td class="num amt">₱<?= number_format($catTotalRevenue, 2) ?></td>
                                            <td class="num pct">100%</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                <div class="empty-icon"><i class="fa-solid fa-layer-group"></i></div>
                                <div class="empty-title">No category data</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="section-title"><i class="fa-solid fa-wallet"></i> Payment Methods</div>
                    <div class="table-card">
                        <?php if ($payBreak && $payBreak->num_rows > 0): ?>
                            <div class="table-scroll">
                                <table class="data">
                                    <thead>
                                        <tr>
                                            <th>Method</th>
                                            <th class="num">Orders</th>
                                            <th class="num">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($pm = $payBreak->fetch_assoc()):
                                            $m = $pm['payment_method'];
                                            $mClass = 'm-' . $m;
                                            $mIcon = $m === 'gcash' ? '<i class="fa-solid fa-mobile-screen"></i>' : ($m === 'cod' ? '<i class="fa-solid fa-truck"></i>' : '<i class="fa-solid fa-money-bill"></i>');
                                        ?>
                                            <tr>
                                                <td><span class="method-badge <?= $mClass ?>"><?= $mIcon ?> <?= strtoupper($m) ?></span></td>
                                                <td class="num"><?= number_format((int)$pm['cnt']) ?></td>
                                                <td class="num amt">₱<?= number_format((float)$pm['amt'], 2) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                <div class="empty-icon"><i class="fa-solid fa-wallet"></i></div>
                                <div class="empty-title">No payments</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /.main-content -->
    </div><!-- /.admin-layout -->

    <script>
        // Sales trend chart
        <?php if (!empty($trendData)): ?>
                (function() {
                    const ctx = document.getElementById('salesTrend');
                    if (!ctx) return;
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: <?= $trendLabelsJson ?>,
                            datasets: [{
                                label: 'Revenue',
                                data: <?= $trendDataJson ?>,
                                borderColor: '#e67e22',
                                backgroundColor: 'rgba(230,126,34,0.08)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.3,
                                pointBackgroundColor: '#e67e22',
                                pointRadius: 3,
                                pointHoverRadius: 5
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
                                    callbacks: {
                                        label: c => '₱' + c.parsed.y.toLocaleString('en-PH', {
                                            minimumFractionDigits: 2
                                        })
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: v => '₱' + v.toLocaleString('en-PH')
                                    },
                                    grid: {
                                        color: 'rgba(0,0,0,0.05)'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                })();
        <?php endif; ?>

        // Open the print view carrying the current filters
        function printReport() {
            const from = '<?= htmlspecialchars($dateFrom) ?>';
            const to = '<?= htmlspecialchars($dateTo) ?>';
            const status = '<?= htmlspecialchars($statusFilter) ?>';
            const pad = n => String(n).padStart(2, '0');
            const d = new Date();
            const todayStr = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            const mon = new Date(d);
            mon.setDate(d.getDate() - ((d.getDay() + 6) % 7));
            const weekStr = mon.getFullYear() + '-' + pad(mon.getMonth() + 1) + '-' + pad(mon.getDate());
            const monthStr = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-01';
            let period = '';
            if (from === todayStr && to === todayStr) period = 'daily';
            else if (from === weekStr && to === todayStr) period = 'weekly';
            else if (from === monthStr && to === todayStr) period = 'monthly';
            const params = new URLSearchParams();
            if (period) {
                params.set('period', period);
            } else {
                params.set('from', from);
                params.set('to', to);
            }
            if (status) params.set('status', status);
            window.open('report_sales_print.php?' + params.toString(), '_blank');
        }

        // Export the on-screen tables to CSV
        function exportCSV() {
            const rows = [];
            rows.push(['HATCH — Sales Report']);
            rows.push(['Period', '<?= date('M j, Y', strtotime($dateFrom)) ?> to <?= date('M j, Y', strtotime($dateTo)) ?>']);
            rows.push([]);
            rows.push(['Total Sales', '<?= $totalRevenue ?>']);
            rows.push(['Paid Orders', '<?= $paidOrders ?>']);
            rows.push(['Avg Order Value', '<?= round($avgOrder, 2) ?>']);
            rows.push(['Units Sold', '<?= $totalUnits ?>']);
            rows.push([]);
            rows.push(['Top Products by Revenue']);
            rows.push(['Rank', 'Product', 'Unit', 'Qty Sold', 'Revenue']);
            <?php
            $topProducts->data_seek(0);
            $rk = 1;
            while ($p = $topProducts->fetch_assoc()):
            ?>
                rows.push(['<?= $rk++ ?>', <?= json_encode($p['name']) ?>, <?= json_encode($p['unit']) ?>, '<?= (int)$p['qty'] ?>', '<?= round((float)$p['revenue'], 2) ?>']);
            <?php endwhile; ?>
            const csv = rows.map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n');
            const blob = new Blob(['\uFEFF' + csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sales_report_<?= date('Y-m-d') ?>.csv';
            a.click();
            URL.revokeObjectURL(url);
        }
    </script>
</body>

</html>