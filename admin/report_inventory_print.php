<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_inventory_print.php
//
// Clean print view of the Inventory report — same plain style
// as report_sales_print.php (shared letterhead + tables, no
// charts). Opened in a new tab from report_inventory.php.
//
// Current-stock figures are a live snapshot. ?period (daily /
// weekly / monthly) scopes the date-based movement & restock
// figures, matching the sales report's period behavior.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$today     = date('Y-m-d');
$catFilter = (int)($_GET['cat'] ?? 0);
$catWhere  = $catFilter ? "AND p.category_id = {$catFilter}" : '';

// Period shortcut
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

// ── KPIs (current snapshot) ───────────────────────────────────
$totalProducts = (int)($conn->query("SELECT COUNT(*) AS c FROM products p WHERE p.is_active = 1 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$lowStockCount = (int)($conn->query("SELECT COUNT(*) AS c FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.is_active=1 AND i.quantity<=i.reorder_level AND i.quantity>0 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$outOfStockCount = (int)($conn->query("SELECT COUNT(*) AS c FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.is_active=1 AND i.quantity=0 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$totalStockValue = (float)($conn->query("SELECT COALESCE(SUM(i.quantity*p.price),0) AS v FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.is_active=1 {$catWhere}")->fetch_assoc()['v'] ?? 0);

// ── Movement totals (period) ──────────────────────────────────
$moveIn  = (int)($conn->query("SELECT COALESCE(SUM(il.quantity),0) AS s FROM inventory_logs il JOIN products p ON p.id=il.product_id WHERE il.type='in' AND DATE(il.created_at) BETWEEN '{$pFromSql}' AND '{$pToSql}' {$catWhere}")->fetch_assoc()['s'] ?? 0);
$moveOut = (int)($conn->query("SELECT COALESCE(SUM(il.quantity),0) AS s FROM inventory_logs il JOIN products p ON p.id=il.product_id WHERE il.type='out' AND DATE(il.created_at) BETWEEN '{$pFromSql}' AND '{$pToSql}' {$catWhere}")->fetch_assoc()['s'] ?? 0);

// ── Category stock (current) ──────────────────────────────────
$catRows = [];
$cq = $conn->query("SELECT c.name AS category, COUNT(p.id) AS products, COALESCE(SUM(i.quantity),0) AS units, COALESCE(SUM(i.quantity*p.price),0) AS value FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN inventory i ON i.product_id=p.id WHERE p.is_active=1 {$catWhere} GROUP BY c.id ORDER BY value DESC");
if ($cq) while ($row = $cq->fetch_assoc()) $catRows[] = $row;

// ── Most restocked (period) ───────────────────────────────────
$restockRows = [];
$rq = $conn->query("SELECT p.name, p.unit, COALESCE(SUM(il.quantity),0) AS total_in FROM inventory_logs il JOIN products p ON p.id=il.product_id WHERE il.type='in' AND DATE(il.created_at) BETWEEN '{$pFromSql}' AND '{$pToSql}' {$catWhere} GROUP BY p.id ORDER BY total_in DESC LIMIT 10");
if ($rq) while ($row = $rq->fetch_assoc()) $restockRows[] = $row;

// ── Full inventory snapshot ───────────────────────────────────
$inventoryTable = $conn->query("SELECT p.name, p.unit, p.price, c.name AS category, i.quantity, i.reorder_level FROM inventory i JOIN products p ON p.id=i.product_id JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 {$catWhere} ORDER BY i.quantity ASC, p.name ASC");

// ── Print chrome (shared letterhead, like sales) ──────────────
$printTitle    = 'Inventory Report';
$printSubtitle = 'Stock snapshot as of ' . date('M j, Y') . ($period ? '  ·  Movement: ' . $periodLabel : '');
$printMeta     = [
    ['label' => 'Generated',       'value' => date('M j, Y g:i A')],
    ['label' => 'Movement Period', 'value' => $periodLabel . ' (' . date('M j', strtotime($periodFrom)) . ' – ' . date('M j', strtotime($periodTo)) . ')'],
    ['label' => 'Total Products',  'value' => number_format($totalProducts)],
    ['label' => 'Stock Value',     'value' => peso($totalStockValue)],
];

require '../admin/report_print_header.php';
?>

<!-- KPI summary -->
<div class="rp-kpis">
    <div class="rp-kpi accent-blue">
        <div class="k-label">Total Products</div>
        <div class="k-value"><?= number_format($totalProducts) ?></div>
        <div class="k-sub">Active products</div>
    </div>
    <div class="rp-kpi accent-amber">
        <div class="k-label">Low Stock</div>
        <div class="k-value"><?= number_format($lowStockCount) ?></div>
        <div class="k-sub">At/below reorder</div>
    </div>
    <div class="rp-kpi accent-red">
        <div class="k-label">Out of Stock</div>
        <div class="k-value"><?= number_format($outOfStockCount) ?></div>
        <div class="k-sub">Zero quantity</div>
    </div>
    <div class="rp-kpi accent-green">
        <div class="k-label">Total Stock Value</div>
        <div class="k-value" style="font-size:1.05rem;"><?= peso($totalStockValue) ?></div>
        <div class="k-sub">Qty &times; price</div>
    </div>
</div>

<!-- Stock movement for period -->
<div class="rp-section-title">Stock Movement <span class="count"><?= htmlspecialchars($periodLabel) ?></span></div>
<table class="rp-table">
    <thead>
        <tr>
            <th>Movement</th>
            <th class="num">Total Units</th>
            <th>Period</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td style="font-weight:600;">Stock In (restocked)</td>
            <td class="num" style="font-weight:700;"><?= number_format($moveIn) ?></td>
            <td class="muted"><?= date('M j, Y', strtotime($periodFrom)) ?> &ndash; <?= date('M j, Y', strtotime($periodTo)) ?></td>
        </tr>
        <tr>
            <td style="font-weight:600;">Stock Out (sold/consumed)</td>
            <td class="num" style="font-weight:700;"><?= number_format($moveOut) ?></td>
            <td class="muted"><?= date('M j, Y', strtotime($periodFrom)) ?> &ndash; <?= date('M j, Y', strtotime($periodTo)) ?></td>
        </tr>
    </tbody>
</table>

<!-- Category stock -->
<div class="rp-section-title">Stock by Category</div>
<?php if (!empty($catRows)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th>Category</th>
                <th class="num">Products</th>
                <th class="num">Units in Stock</th>
                <th class="num">Stock Value</th>
            </tr>
        </thead>
        <tbody>
            <?php $tU = 0;
            $tV = 0.0;
            foreach ($catRows as $r): $tU += (int)$r['units'];
                $tV += (float)$r['value']; ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['category']) ?></td>
                    <td class="num"><?= number_format((int)$r['products']) ?></td>
                    <td class="num"><?= number_format((int)$r['units']) ?></td>
                    <td class="num" style="font-weight:700;"><?= peso((float)$r['value']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="num"><?= number_format($totalProducts) ?></td>
                <td class="num"><?= number_format($tU) ?></td>
                <td class="num"><?= peso($tV) ?></td>
            </tr>
        </tfoot>
    </table>
<?php else: ?>
    <div class="rp-empty">No category data.</div>
<?php endif; ?>

<!-- Most restocked -->
<div class="rp-section-title">Most Restocked <span class="count"><?= htmlspecialchars($periodLabel) ?></span></div>
<?php if (!empty($restockRows)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th style="width:34px;">#</th>
                <th>Product</th>
                <th>Unit</th>
                <th class="num">Units Restocked</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($restockRows as $i => $r): ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></td>
                    <td class="muted"><?= htmlspecialchars($r['unit']) ?></td>
                    <td class="num" style="font-weight:700;"><?= number_format((int)$r['total_in']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="rp-empty">No restocking recorded in this period.</div>
<?php endif; ?>

<!-- Full snapshot -->
<?php
$invRows = [];
if ($inventoryTable && $inventoryTable->num_rows > 0) while ($row = $inventoryTable->fetch_assoc()) $invRows[] = $row;
?>
<div class="rp-section-title">Full Inventory Snapshot <span class="count"><?= count($invRows) ?> product<?= count($invRows) !== 1 ? 's' : '' ?></span></div>
<?php if (!empty($invRows)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th style="width:34px;">#</th>
                <th>Product</th>
                <th>Category</th>
                <th>Unit</th>
                <th class="num">Price</th>
                <th class="num">Stock Qty</th>
                <th class="num">Reorder</th>
                <th>Status</th>
                <th class="num">Stock Value</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invRows as $i => $r):
                $qty = (int)$r['quantity'];
                $rl = (int)$r['reorder_level'];
                $val = $qty * (float)$r['price'];
                if ($qty === 0) {
                    $stCls = 'pill-red';
                    $stTxt = 'Out of Stock';
                } elseif ($qty <= $rl) {
                    $stCls = 'pill-amber';
                    $stTxt = 'Low Stock';
                } else {
                    $stCls = 'pill-green';
                    $stTxt = 'OK';
                }
            ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></td>
                    <td class="muted"><?= htmlspecialchars($r['category']) ?></td>
                    <td class="muted"><?= htmlspecialchars($r['unit']) ?></td>
                    <td class="num"><?= peso((float)$r['price']) ?></td>
                    <td class="num" style="font-weight:700;"><?= number_format($qty) ?></td>
                    <td class="num"><?= number_format($rl) ?></td>
                    <td><span class="rp-pill <?= $stCls ?>"><?= $stTxt ?></span></td>
                    <td class="num" style="font-weight:700;"><?= peso($val) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="8">Total Stock Value</td>
                <td class="num"><?= peso($totalStockValue) ?></td>
            </tr>
        </tfoot>
    </table>
<?php else: ?>
    <div class="rp-empty">No inventory data found.</div>
<?php endif; ?>

<?php
$signRolePrepared = 'Prepared by';
$signRoleApproved = 'Verified by';
require '../admin/report_print_footer.php';
