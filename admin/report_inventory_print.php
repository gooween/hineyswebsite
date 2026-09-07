<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_inventory_print.php
//
// Clean print view of the Inventory report — plain tabular
// style (shared letterhead + tables, no charts). Stock is read
// from stock_batches (active batches) so it matches the shop.
// ?period (daily/weekly/monthly) scopes the movement figures.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$today     = date('Y-m-d');
$catFilter = (int)($_GET['cat'] ?? 0);
$catWhere  = $catFilter ? "AND p.category_id = {$catFilter}" : '';

// Current stock from active batches (per-tray aware) — matches shop/home
$BATCH = "(SELECT CASE WHEN p.unit='per tray' THEN COUNT(sb.id) ELSE COALESCE(SUM(sb.remaining),0) END FROM stock_batches sb WHERE sb.product_id=p.id AND sb.status='active')";

// Period
$period = trim($_GET['period'] ?? '');
if ($period === 'daily') {
    $pFrom = $today;
    $pTo = $today;
    $pLabel = 'Today';
} elseif ($period === 'weekly') {
    $pFrom = date('Y-m-d', strtotime('monday this week'));
    $pTo = $today;
    $pLabel = 'This Week';
} elseif ($period === 'monthly') {
    $pFrom = date('Y-m-01');
    $pTo = $today;
    $pLabel = 'This Month';
} else {
    $pFrom = date('Y-m-d', strtotime('-29 days'));
    $pTo = $today;
    $pLabel = 'Last 30 days';
}
$pFromSql = $conn->real_escape_string($pFrom);
$pToSql = $conn->real_escape_string($pTo);

// KPIs
$totalProducts = (int)($conn->query("SELECT COUNT(*) c FROM products p WHERE p.is_active=1 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$lowStockCount = (int)($conn->query("SELECT COUNT(*) c FROM products p WHERE p.is_active=1 AND {$BATCH} <= (SELECT reorder_level FROM inventory WHERE product_id=p.id LIMIT 1) AND {$BATCH} > 0 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$outOfStockCount = (int)($conn->query("SELECT COUNT(*) c FROM products p WHERE p.is_active=1 AND {$BATCH} = 0 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$totalStockValue = (float)($conn->query("SELECT COALESCE(SUM({$BATCH} * p.price),0) v FROM products p WHERE p.is_active=1 {$catWhere}")->fetch_assoc()['v'] ?? 0);

// Movement (period) — from inventory_logs
$moveIn  = (int)($conn->query("SELECT COALESCE(SUM(il.quantity),0) s FROM inventory_logs il JOIN products p ON p.id=il.product_id WHERE il.type='in' AND DATE(il.created_at) BETWEEN '{$pFromSql}' AND '{$pToSql}' {$catWhere}")->fetch_assoc()['s'] ?? 0);
$moveOut = (int)($conn->query("SELECT COALESCE(SUM(il.quantity),0) s FROM inventory_logs il JOIN products p ON p.id=il.product_id WHERE il.type='out' AND DATE(il.created_at) BETWEEN '{$pFromSql}' AND '{$pToSql}' {$catWhere}")->fetch_assoc()['s'] ?? 0);

// Category stock (current, from batches)
$catRows = [];
$cq = $conn->query("SELECT c.name category, COUNT(p.id) products, COALESCE(SUM({$BATCH}),0) units, COALESCE(SUM({$BATCH}*p.price),0) value FROM products p JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 {$catWhere} GROUP BY c.id ORDER BY value DESC");
if ($cq) while ($r = $cq->fetch_assoc()) $catRows[] = $r;

// Most restocked (period)
$restockRows = [];
$rq = $conn->query("SELECT p.name, p.unit, COALESCE(SUM(il.quantity),0) total_in FROM inventory_logs il JOIN products p ON p.id=il.product_id WHERE il.type='in' AND DATE(il.created_at) BETWEEN '{$pFromSql}' AND '{$pToSql}' {$catWhere} GROUP BY p.id ORDER BY total_in DESC LIMIT 10");
if ($rq) while ($r = $rq->fetch_assoc()) $restockRows[] = $r;

// Full snapshot (current, from batches)
$inventoryTable = $conn->query("SELECT p.name, p.unit, p.price, c.name category, {$BATCH} quantity, (SELECT reorder_level FROM inventory WHERE product_id=p.id LIMIT 1) reorder_level FROM products p JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 {$catWhere} ORDER BY quantity ASC, p.name ASC");

// Print chrome (shared plain letterhead)
$printTitle    = 'Inventory Report';
$printSubtitle = 'Stock snapshot as of ' . date('M j, Y') . ($period ? '  ·  Movement: ' . $pLabel : '');
$printMeta     = [
    ['label' => 'Generated', 'value' => date('M j, Y g:i A')],
    ['label' => 'Movement Period', 'value' => $pLabel . ' (' . date('M j', strtotime($pFrom)) . ' – ' . date('M j', strtotime($pTo)) . ')'],
    ['label' => 'Total Products', 'value' => number_format($totalProducts)],
    ['label' => 'Stock Value', 'value' => peso($totalStockValue)],
];
require '../admin/report_print_header.php';
?>

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

<div class="rp-section-title">Stock Movement <span class="count"><?= htmlspecialchars($pLabel) ?></span></div>
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
            <td class="muted"><?= date('M j, Y', strtotime($pFrom)) ?> &ndash; <?= date('M j, Y', strtotime($pTo)) ?></td>
        </tr>
        <tr>
            <td style="font-weight:600;">Stock Out (sold/consumed)</td>
            <td class="num" style="font-weight:700;"><?= number_format($moveOut) ?></td>
            <td class="muted"><?= date('M j, Y', strtotime($pFrom)) ?> &ndash; <?= date('M j, Y', strtotime($pTo)) ?></td>
        </tr>
    </tbody>
</table>

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
<?php else: ?><div class="rp-empty">No category data.</div><?php endif; ?>

<div class="rp-section-title">Most Restocked <span class="count"><?= htmlspecialchars($pLabel) ?></span></div>
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
<?php else: ?><div class="rp-empty">No restocking recorded in this period.</div><?php endif; ?>

<?php
$invRows = [];
if ($inventoryTable && $inventoryTable->num_rows > 0) while ($r = $inventoryTable->fetch_assoc()) $invRows[] = $r;
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
                    $c = 'pill-red';
                    $t = 'Out of Stock';
                } elseif ($qty <= $rl) {
                    $c = 'pill-amber';
                    $t = 'Low Stock';
                } else {
                    $c = 'pill-green';
                    $t = 'OK';
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
                    <td><span class="rp-pill <?= $c ?>"><?= $t ?></span></td>
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
<?php else: ?><div class="rp-empty">No inventory data found.</div><?php endif; ?>

<?php
$signRolePrepared = 'Prepared by';
$signRoleApproved = 'Verified by';
require '../admin/report_print_footer.php';
