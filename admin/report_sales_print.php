<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_sales_print.php
//
// Clean print view of the Sales report. Opened in a new tab
// from report_sales.php, carrying the same ?from &to filters.
//
// Sales figures count PAID, non-cancelled orders — matching
// the revenue basis used on the sales report page.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$today = date('Y-m-d');

// ── Same date filter as report_sales.php ──────────────────────
$dateFrom = trim($_GET['from'] ?? date('Y-m-01'));
$dateTo   = trim($_GET['to']   ?? $today);
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$dateFromSql = $conn->real_escape_string($dateFrom);
$dateToSql   = $conn->real_escape_string($dateTo);

// Revenue basis: paid, non-cancelled orders in range
$paidWhere = "WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
              AND o.status <> 'cancelled' AND o.payment_status = 'paid'";

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

// ── Payment method breakdown ──────────────────────────────────
$payBreak = $conn->query("
    SELECT o.payment_method,
           COUNT(*) AS cnt,
           COALESCE(SUM(o.total_amount),0) AS amt
    FROM orders o {$paidWhere}
    GROUP BY o.payment_method
    ORDER BY amt DESC
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

// ── Print chrome ──────────────────────────────────────────────
$printTitle    = 'Sales Report';
$printSubtitle = date('M j, Y', strtotime($dateFrom)) . ' – ' . date('M j, Y', strtotime($dateTo));
$printMeta     = [
    ['label' => 'Period',      'value' => date('M j, Y', strtotime($dateFrom)) . ' to ' . date('M j, Y', strtotime($dateTo))],
    ['label' => 'Total Sales', 'value' => peso($totalRevenue)],
    ['label' => 'Paid Orders', 'value' => number_format($paidOrders)],
    ['label' => 'Basis',       'value' => 'Paid, non-cancelled orders'],
];

require '../admin/report_print_header.php';
?>

<!-- Summary figures -->
<table class="rp-summary">
    <tr>
        <td>
            <div class="s-label">Total Sales</div>
            <div class="s-value"><?= peso($totalRevenue) ?></div>
            <div class="s-sub">Paid revenue in range</div>
        </td>
        <td>
            <div class="s-label">Paid Orders</div>
            <div class="s-value"><?= number_format($paidOrders) ?></div>
            <div class="s-sub">Completed &amp; paid</div>
        </td>
        <td>
            <div class="s-label">Avg Order Value</div>
            <div class="s-value"><?= peso($avgOrder) ?></div>
            <div class="s-sub">Revenue &divide; orders</div>
        </td>
        <td>
            <div class="s-label">Units Sold</div>
            <div class="s-value"><?= number_format($totalUnits) ?></div>
            <div class="s-sub">Total items</div>
        </td>
    </tr>
</table>

<!-- Top products -->
<div class="rp-section-title">Top Products by Revenue</div>
<?php
$tp = [];
if ($topProducts && $topProducts->num_rows > 0) {
    while ($row = $topProducts->fetch_assoc()) $tp[] = $row;
}
?>
<?php if (!empty($tp)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th style="width:34px;">#</th>
                <th>Product</th>
                <th>Unit</th>
                <th class="num">Qty Sold</th>
                <th class="num">Revenue</th>
                <th class="num">% of Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tp as $i => $r):
                $pct = $totalRevenue > 0 ? round(((float)$r['revenue'] / $totalRevenue) * 100, 1) : 0;
            ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></td>
                    <td class="muted"><?= htmlspecialchars($r['unit']) ?></td>
                    <td class="num"><?= number_format((int)$r['qty']) ?></td>
                    <td class="num" style="font-weight:700;"><?= peso((float)$r['revenue']) ?></td>
                    <td class="num"><?= $pct ?>%</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="rp-empty">No sales in this period.</div>
<?php endif; ?>

<!-- Two-column: payment + category -->
<div class="rp-section-title">Payment Method Breakdown</div>
<?php
$pb = [];
if ($payBreak && $payBreak->num_rows > 0) {
    while ($row = $payBreak->fetch_assoc()) $pb[] = $row;
}
?>
<?php if (!empty($pb)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th>Payment Method</th>
                <th class="num">Orders</th>
                <th class="num">Amount</th>
                <th class="num">% of Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pb as $r):
                $pct = $totalRevenue > 0 ? round(((float)$r['amt'] / $totalRevenue) * 100, 1) : 0;
            ?>
                <tr>
                    <td style="font-weight:600;"><?= strtoupper(htmlspecialchars($r['payment_method'])) ?></td>
                    <td class="num"><?= number_format((int)$r['cnt']) ?></td>
                    <td class="num" style="font-weight:700;"><?= peso((float)$r['amt']) ?></td>
                    <td class="num"><?= $pct ?>%</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="rp-empty">No payment data.</div>
<?php endif; ?>

<!-- Category performance -->
<div class="rp-section-title">Category Performance</div>
<?php
$cp = [];
if ($catPerf && $catPerf->num_rows > 0) {
    while ($row = $catPerf->fetch_assoc()) $cp[] = $row;
}
?>
<?php if (!empty($cp)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th>Category</th>
                <th class="num">Units Sold</th>
                <th class="num">Revenue</th>
                <th class="num">% of Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cp as $r):
                $pct = $totalRevenue > 0 ? round(((float)$r['revenue'] / $totalRevenue) * 100, 1) : 0;
            ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['category']) ?></td>
                    <td class="num"><?= number_format((int)$r['qty']) ?></td>
                    <td class="num" style="font-weight:700;"><?= peso((float)$r['revenue']) ?></td>
                    <td class="num"><?= $pct ?>%</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="num"><?= number_format($totalUnits) ?></td>
                <td class="num"><?= peso($totalRevenue) ?></td>
                <td class="num">100%</td>
            </tr>
        </tfoot>
    </table>
<?php else: ?>
    <div class="rp-empty">No category data.</div>
<?php endif; ?>

<?php
$signRolePrepared = 'Prepared by';
$signRoleApproved = 'Approved by';
require '../admin/report_print_footer.php';
