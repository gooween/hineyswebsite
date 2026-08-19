<?php
// ============================================================
// Hiney's Eggs & Live Chicken Business
// File: admin/dashboard.php
//
// Admin dashboard — rebuilt on the shared design system
// (admin/assets/admin.css). Light theme, orange accents.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$activePage = 'dashboard';

// ── Metrics ───────────────────────────────────────────────────
$totalProducts = (int)($conn->query("SELECT COUNT(*) AS cnt FROM products WHERE is_active = 1")->fetch_assoc()['cnt'] ?? 0);

$ordersToday = (int)($conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['cnt'] ?? 0);

// Sales today — approved/processing/out_for_delivery/delivered only (not pending)
$salesToday = (float)($conn->query("
    SELECT COALESCE(SUM(total_amount),0) AS total
    FROM orders
    WHERE DATE(created_at) = CURDATE()
      AND status IN ('approved','processing','out_for_delivery','delivered')
")->fetch_assoc()['total'] ?? 0);

// Low stock — from stock_batches, respecting per-tray vs per-piece counting
$lowStock = (int)($conn->query("
    SELECT COUNT(*) AS cnt FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
      AND COALESCE((
          SELECT CASE WHEN p.unit='per tray' THEN COUNT(sb.id) ELSE SUM(sb.remaining) END
          FROM stock_batches sb WHERE sb.product_id = i.product_id AND sb.status = 'active'
      ), 0) <= i.reorder_level
")->fetch_assoc()['cnt'] ?? 0);

$pendingOrders  = (int)($conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'pending'")->fetch_assoc()['cnt'] ?? 0);
$totalCustomers = (int)($conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'customer'")->fetch_assoc()['cnt'] ?? 0);

// Latest 5 orders
$latestOrders = $conn->query("
    SELECT o.id, u.full_name, o.total_amount, o.status, o.payment_status, o.created_at,
           COUNT(oi.id) AS item_count
    FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN order_items oi ON oi.order_id = o.id
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 5
");

// Low-stock products list
$lowStockProducts = $conn->query("
    SELECT p.name, p.unit,
           COALESCE((
               SELECT CASE WHEN p.unit='per tray' THEN COUNT(sb.id) ELSE SUM(sb.remaining) END
               FROM stock_batches sb WHERE sb.product_id = i.product_id AND sb.status = 'active'
           ), 0) AS quantity,
           i.reorder_level, c.name AS category
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    HAVING quantity <= i.reorder_level
    ORDER BY quantity ASC
    LIMIT 6
");

// Sales this week (for the chart)
$salesWeekData = [];
$salesWeekLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-{$i} days"));
    $salesWeekLabels[] = date('D', strtotime($date));
    $salesWeekData[] = (float)($conn->query("
        SELECT COALESCE(SUM(total_amount),0) AS s FROM orders
        WHERE DATE(created_at)='{$date}'
          AND status IN ('approved','processing','out_for_delivery','delivered')
    ")->fetch_assoc()['s'] ?? 0);
}

$salesWeekJson  = json_encode($salesWeekData);
$labelsWeekJson = json_encode($salesWeekLabels);

// Status → pill class map
function orderPill(string $s): array
{
    return [
        'pending'          => ['pill-warn',   'Pending'],
        'approved'         => ['pill-info',   'Approved'],
        'confirmed'        => ['pill-info',   'Confirmed'],
        'processing'       => ['pill-violet', 'Processing'],
        'out_for_delivery' => ['pill-brand',  'Out for Delivery'],
        'delivered'        => ['pill-ok',     'Delivered'],
        'cancelled'        => ['pill-danger', 'Cancelled'],
    ][$s] ?? ['pill-neutral', ucfirst($s)];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Hiney's Admin</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Page-specific only — everything else comes from admin.css */
        .dash-charts {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--s4);
            margin-bottom: var(--s6);
        }

        @media (max-width: 900px) {
            .dash-charts {
                grid-template-columns: 1fr;
            }
        }

        .chart-box {
            position: relative;
            width: 100%;
            height: 260px;
        }

        .lowstock-scroll {
            max-height: 300px;
            overflow-y: auto;
        }

        .qty-pill-cell {
            text-align: right;
            white-space: nowrap;
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
                    <h1 class="page-title">Dashboard</h1>
                    <div class="page-title-sub">Welcome back, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></div>
                </div>
                <div class="chip">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                    <?= date('l, F j, Y') ?> &nbsp;·&nbsp; <?= date('g:i A') ?>
                </div>
            </div>

            <?= flash() ?>

            <!-- Stat cards -->
            <div class="grid cols-3 mb-6">

                <div class="stat-card tone-brand">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Active Products</span>
                        <div class="stat-icon"><i class="fa-solid fa-box-open"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalProducts) ?></div>
                    <div class="stat-foot">All listed products</div>
                </div>

                <div class="stat-card tone-blue">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Orders Today</span>
                        <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($ordersToday) ?></div>
                    <div class="stat-foot"><?= date('F j') ?></div>
                </div>

                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Sales Today</span>
                        <div class="stat-icon"><i class="fa-solid fa-money-bill"></i></div>
                    </div>
                    <div class="stat-value money"><?= peso($salesToday) ?></div>
                    <div class="stat-foot">Approved orders only</div>
                </div>

                <div class="stat-card tone-red">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Low Stock</span>
                        <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php if ($lowStock > 0): ?><span class="pulse"><span class="pulse-dot"></span><?= number_format($lowStock) ?></span><?php else: ?><?= number_format($lowStock) ?><?php endif; ?>
                    </div>
                    <div class="stat-foot">At or below reorder level</div>
                </div>

                <div class="stat-card tone-amber">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Pending Orders</span>
                        <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php if ($pendingOrders > 0): ?><span class="pulse"><span class="pulse-dot amber"></span><?= number_format($pendingOrders) ?></span><?php else: ?><?= number_format($pendingOrders) ?><?php endif; ?>
                    </div>
                    <div class="stat-foot">Awaiting approval</div>
                </div>

                <div class="stat-card tone-violet">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Customers</span>
                        <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalCustomers) ?></div>
                    <div class="stat-foot">Registered accounts</div>
                </div>

            </div>

            <!-- Charts row -->
            <div class="dash-charts">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-chart-line" style="color:var(--brand)"></i> Sales this week</div>
                        <span style="font-size:var(--fs-xs);color:var(--ink-3)">Approved orders</span>
                    </div>
                    <div class="card-pad">
                        <div class="chart-box"><canvas id="salesChart"></canvas></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-triangle-exclamation" style="color:var(--warn)"></i> Low stock</div>
                        <a href="inventory.php" class="link-more">View <i class="fa-solid fa-arrow-right" style="font-size:10px"></i></a>
                    </div>
                    <div class="lowstock-scroll">
                        <?php if ($lowStockProducts && $lowStockProducts->num_rows > 0): ?>
                            <table class="data">
                                <tbody>
                                    <?php while ($ls = $lowStockProducts->fetch_assoc()):
                                        $isChicken = stripos($ls['category'], 'chicken') !== false;
                                        $q = (int)$ls['quantity'];
                                        $rl = (int)$ls['reorder_level'];
                                        $pillCls = $q === 0 ? 'pill-danger' : ($q <= $rl ? 'pill-warn' : 'pill-neutral');
                                        $qLabel = $q === 0 ? 'Out' : $q . ' left';
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="cell-lead">
                                                    <div class="avatar" style="background:var(--warn-tint);color:#a4680c;">
                                                        <i class="fa-solid <?= $isChicken ? 'fa-drumstick-bite' : 'fa-egg' ?>" style="font-size:12px;color:inherit;"></i>
                                                    </div>
                                                    <div>
                                                        <div class="cell-title"><?= htmlspecialchars($ls['name']) ?></div>
                                                        <div class="cell-sub"><?= htmlspecialchars($ls['category']) ?> · <?= htmlspecialchars($ls['unit']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="qty-pill-cell"><span class="pill <?= $pillCls ?> pill-dot"><?= $qLabel ?></span></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty">
                                <div class="empty-icon"><i class="fa-solid fa-circle-check" style="color:var(--ok)"></i></div>
                                <div class="empty-title">All stocked up</div>
                                <div class="empty-text">No products below reorder level.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Latest orders -->
            <div class="table-card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-receipt" style="color:#7c5cd0"></i> Latest orders</div>
                    <a href="orders.php" class="link-more">View all <i class="fa-solid fa-arrow-right" style="font-size:10px"></i></a>
                </div>
                <div class="table-scroll">
                    <?php if ($latestOrders && $latestOrders->num_rows > 0): ?>
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>Order</th>
                                    <th>Customer</th>
                                    <th class="num">Items</th>
                                    <th class="num">Total</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($o = $latestOrders->fetch_assoc()):
                                    [$pillCls, $pillLbl] = orderPill($o['status']);
                                    $initial = strtoupper(substr($o['full_name'], 0, 1));
                                ?>
                                    <tr>
                                        <td style="font-weight:650;color:var(--brand);">#<?= str_pad((string)$o['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                        <td>
                                            <div class="cell-lead">
                                                <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                                                <div class="cell-title"><?= htmlspecialchars($o['full_name']) ?></div>
                                            </div>
                                        </td>
                                        <td class="num"><?= (int)$o['item_count'] ?></td>
                                        <td class="num" style="font-weight:650;"><?= peso((float)$o['total_amount']) ?></td>
                                        <td>
                                            <?php if ($o['payment_status'] === 'paid'): ?>
                                                <span class="pill pill-ok pill-dot">Paid</span>
                                            <?php else: ?>
                                                <span class="pill pill-danger pill-dot">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="pill <?= $pillCls ?> pill-dot"><?= $pillLbl ?></span></td>
                                        <td style="color:var(--ink-3);white-space:nowrap;font-size:var(--fs-xs);">
                                            <?= date('M j, Y', strtotime($o['created_at'])) ?><br>
                                            <?= date('g:i A', strtotime($o['created_at'])) ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty">
                            <div class="empty-icon"><i class="fa-solid fa-receipt"></i></div>
                            <div class="empty-title">No orders yet</div>
                            <div class="empty-text">New orders will appear here.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script>
        (function() {
            const ctx = document.getElementById('salesChart');
            if (!ctx) return;
            Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
            Chart.defaults.color = '#9c968c';

            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?= $labelsWeekJson ?>,
                    datasets: [{
                        label: 'Sales',
                        data: <?= $salesWeekJson ?>,
                        backgroundColor: function(c) {
                            const {
                                chart
                            } = c;
                            if (!chart.chartArea) return '#e67e22';
                            const {
                                top,
                                bottom
                            } = chart.chartArea;
                            const g = chart.ctx.createLinearGradient(0, top, 0, bottom);
                            g.addColorStop(0, '#f0a04b');
                            g.addColorStop(1, '#e67e22');
                            return g;
                        },
                        borderRadius: 7,
                        borderSkipped: false,
                        maxBarThickness: 54,
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
                            backgroundColor: '#23201c',
                            titleColor: '#fff',
                            bodyColor: '#e5e1da',
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: c => '  ₱' + c.parsed.y.toLocaleString('en-PH')
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
                                    size: 12
                                }
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(35,32,28,0.05)'
                            },
                            ticks: {
                                font: {
                                    size: 11
                                },
                                callback: v => '₱' + (v >= 1000 ? (v / 1000) + 'k' : v)
                            }
                        }
                    }
                }
            });
        })();
    </script>
</body>

</html>