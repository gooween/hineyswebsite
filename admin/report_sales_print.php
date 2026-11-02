<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_sales_print.php
//
// Clean print view of the Sales report. Opened in a new tab
// from report_sales.php, carrying the same ?from &to filters.
//
// Sales figures count DELIVERED + PAID orders — matching
// the revenue basis used on the sales report page.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$today = date('Y-m-d');

// ── Same date filter as report_sales.php ──────────────────────
$dateFrom = trim($_GET['from'] ?? date('Y-m-01'));
$dateTo   = trim($_GET['to']   ?? $today);
$statusFilter = trim($_GET['status'] ?? '');
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$dateFromSql = $conn->real_escape_string($dateFrom);
$dateToSql   = $conn->real_escape_string($dateTo);

// Revenue basis: paid, non-cancelled orders in range
$paidWhere = "WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
              AND o.status = 'delivered' AND o.payment_status = 'paid'";
if ($statusFilter !== '') {
    $paidWhere .= " AND o.status = '" . $conn->real_escape_string($statusFilter) . "'";
}

// ── Category filter (Eggs / Live Chicken / …) ─────────────────
$catFilter  = (int)($_GET['cat'] ?? 0);
$categories = [];
$catLabel   = 'All categories';
$crs = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($crs && $crow = $crs->fetch_assoc()) {
    $categories[] = $crow;
    if ((int)$crow['id'] === $catFilter) $catLabel = $crow['name'];
}
if ($catLabel === 'All categories') $catFilter = 0;   // unknown id → no filter
// When a category is chosen, figures are counted from that category's
// order items (so mixed orders only contribute their matching items).
$catCond = $catFilter ? " AND p.category_id = {$catFilter}" : '';

// ── KPIs ──────────────────────────────────────────────────────
if ($catFilter) {
    $kSql = "
        SELECT COALESCE(SUM(oi.subtotal),0) AS revenue,
               COUNT(DISTINCT o.id) AS paid_orders,
               COALESCE(SUM(oi.subtotal) / NULLIF(COUNT(DISTINCT o.id),0),0) AS avg_order
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        {$paidWhere}{$catCond}
    ";
} else {
    $kSql = "
        SELECT COALESCE(SUM(o.total_amount),0) AS revenue,
               COUNT(*) AS paid_orders,
               COALESCE(AVG(o.total_amount),0) AS avg_order
        FROM orders o {$paidWhere}
    ";
}
$k = $conn->query($kSql)->fetch_assoc();
$totalRevenue = (float)($k['revenue'] ?? 0);
$paidOrders   = (int)($k['paid_orders'] ?? 0);
$avgOrder     = (float)($k['avg_order'] ?? 0);

// Units sold (from order_items on paid orders)
$unitsRow = $conn->query("
    SELECT COALESCE(SUM(oi.quantity),0) AS units
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    {$paidWhere}{$catCond}
")->fetch_assoc();
$totalUnits = (int)($unitsRow['units'] ?? 0);

// ── Expired products (batches past their expiry date inside the range) ──
$expiredRows  = [];
$expiredQty   = 0;
$expiredValue = 0.0;
$expRes = $conn->query("
    SELECT p.name, p.unit, p.price,
           SUM(sb.remaining) AS qty,
           SUM(sb.remaining * p.price) AS total_price
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    WHERE sb.remaining > 0
      AND sb.expires_at IS NOT NULL
      AND sb.expires_at < CURDATE()
      AND sb.status IN ('expired','active')
      AND sb.expires_at BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
      " . ($catFilter ? "AND p.category_id = {$catFilter}" : '') . "
    GROUP BY p.id
    ORDER BY total_price DESC
");
if ($expRes) {
    while ($r = $expRes->fetch_assoc()) {
        $expiredRows[] = $r;
        $expiredQty   += (int)$r['qty'];
        $expiredValue += (float)$r['total_price'];
    }
}

// ── Top products by revenue ───────────────────────────────────
$topProducts = $conn->query("
    SELECT p.name, p.unit, p.price,
           SUM(oi.quantity) AS qty,
           SUM(oi.subtotal) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    {$paidWhere}{$catCond}
    GROUP BY oi.product_id
    ORDER BY revenue DESC
    LIMIT 15
");

// ── Payment method breakdown ──────────────────────────────────
if ($catFilter) {
    $paySql = "
        SELECT o.payment_method,
               COUNT(DISTINCT o.id) AS cnt,
               COALESCE(SUM(oi.subtotal),0) AS amt
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        {$paidWhere}{$catCond}
        GROUP BY o.payment_method
        ORDER BY amt DESC
    ";
} else {
    $paySql = "
        SELECT o.payment_method,
               COUNT(*) AS cnt,
               COALESCE(SUM(o.total_amount),0) AS amt
        FROM orders o {$paidWhere}
        GROUP BY o.payment_method
        ORDER BY amt DESC
    ";
}
$payBreak = $conn->query($paySql);

// ── Category performance ──────────────────────────────────────
$catPerf = $conn->query("
    SELECT c.name AS category,
           SUM(oi.quantity) AS qty,
           SUM(oi.subtotal) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN products p ON p.id = oi.product_id
    JOIN categories c ON c.id = p.category_id
    {$paidWhere}{$catCond}
    GROUP BY c.id
    ORDER BY revenue DESC
");

// ── Print chrome ──────────────────────────────────────────────
// Period is shown once (subtitle). A single-day range shows just that date.
$periodLabel   = ($dateFrom === $dateTo)
    ? date('F j, Y', strtotime($dateFrom))
    : date('M j, Y', strtotime($dateFrom)) . ' – ' . date('M j, Y', strtotime($dateTo));
$printTitle    = 'Sales Report';
$printSubtitle = $periodLabel;
$printMeta     = [
    ['label' => 'Category', 'value' => $catLabel],
    ['label' => 'Basis',       'value' => 'Delivered & paid orders'],
];
if ($statusFilter !== '') {
    $printMeta[] = ['label' => 'Status', 'value' => ucwords(str_replace('_', ' ', $statusFilter))];
}

require '../admin/report_print_header.php';
?>

<style>
    /* KPI summary: plain horizontal row (no cards) */
    .rp-kpis {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        margin: 14px 0 18px;
        padding-bottom: 10px;
        border-bottom: 1px solid #d1d5db;
    }

    .rp-kpi {
        flex: 1;
        text-align: left;
        border: 0;
        padding: 0;
        background: none;
    }

    .rp-kpi .k-label {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .rp-kpi .k-value {
        font-size: 1.1rem;
        font-weight: 800;
        margin: 2px 0;
    }

    .rp-kpi .k-sub {
        font-size: 0.68rem;
        color: #6b7280;
    }
</style>

<!-- KPI summary -->
<div class="rp-kpis">
    <div class="rp-kpi accent-green">
        <div class="k-label">Total Sales</div>
        <div class="k-value" style="font-size:1.05rem;"><?= peso($totalRevenue) ?></div>
        <div class="k-sub">Paid revenue in range</div>
    </div>
    <div class="rp-kpi accent-blue">
        <div class="k-label">Paid Orders</div>
        <div class="k-value"><?= number_format($paidOrders) ?></div>
        <div class="k-sub">Completed &amp; paid</div>
    </div>
    <div class="rp-kpi accent-blue">
        <div class="k-label">Units Sold</div>
        <div class="k-value"><?= number_format($totalUnits) ?></div>
        <div class="k-sub">Total items</div>
    </div>
    <div class="rp-kpi accent-red">
        <div class="k-label">Expired Products</div>
        <div class="k-value"><?= number_format($expiredQty) ?></div>
        <div class="k-sub">Worth <?= peso($expiredValue) ?></div>
    </div>
</div>

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
                <th class="num">Price</th>
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
                    <td class="num"><?= peso((float)$r['price']) ?></td>
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
$catTotalRevenue = 0.0;
$catTotalUnits   = 0;
if ($catPerf && $catPerf->num_rows > 0) {
    while ($row = $catPerf->fetch_assoc()) {
        $cp[] = $row;
        $catTotalRevenue += (float)$row['revenue'];
        $catTotalUnits   += (int)$row['qty'];
    }
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
                $pct = $catTotalRevenue > 0 ? round(((float)$r['revenue'] / $catTotalRevenue) * 100, 1) : 0;
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
                <td class="num"><?= number_format($catTotalUnits) ?></td>
                <td class="num"><?= peso($catTotalRevenue) ?></td>
                <td class="num">100%</td>
            </tr>
        </tfoot>
    </table>
<?php else: ?>
    <div class="rp-empty">No category data.</div>
<?php endif; ?>

<!-- Expired products -->
<div class="rp-section-title">Expired Products <span class="count"><?= count($expiredRows) ?> product<?= count($expiredRows) !== 1 ? 's' : '' ?></span></div>
<?php if (!empty($expiredRows)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Unit</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Total Price</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($expiredRows as $r): ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></td>
                    <td class="muted"><?= htmlspecialchars($r['unit']) ?></td>
                    <td class="num"><?= number_format((int)$r['qty']) ?></td>
                    <td class="num"><?= peso((float)$r['price']) ?></td>
                    <td class="num" style="font-weight:700;"><?= peso((float)$r['total_price']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total</td>
                <td class="num"><?= number_format($expiredQty) ?></td>
                <td></td>
                <td class="num"><?= peso($expiredValue) ?></td>
            </tr>
        </tfoot>
    </table>
<?php else: ?>
    <div class="rp-empty">No expired products in this period.</div>
<?php endif; ?>

<?php
$signRolePrepared = 'Prepared by';
$signNamePrepared = 'Georgia Dupit';   // printed under the signature line
$signPosPrepared  = 'Admin';
$signRoleApproved = 'Approved by';
$signNameApproved = 'Georgia Dupit';
$signPosApproved  = 'Admin';
require '../admin/report_print_footer.php';
