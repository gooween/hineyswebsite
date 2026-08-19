<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_inventory_print.php
//
// Clean print view of the Inventory report. Opened in a new tab
// from report_inventory.php, carrying the same ?cat filter.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$catFilter = (int)($_GET['cat'] ?? 0);
$catWhere  = $catFilter ? "AND p.category_id = {$catFilter}" : '';

// Category name for the header
$catName = 'All Categories';
if ($catFilter) {
    $cr = $conn->query("SELECT name FROM categories WHERE id={$catFilter} LIMIT 1");
    if ($cr && $crow = $cr->fetch_assoc()) $catName = $crow['name'];
}

// KPIs
$totalProducts   = (int)($conn->query("SELECT COUNT(*) c FROM products p WHERE is_active=1 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$lowStockCount   = (int)($conn->query("SELECT COUNT(*) c FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.is_active=1 AND i.quantity<=i.reorder_level AND i.quantity>0 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$outOfStockCount = (int)($conn->query("SELECT COUNT(*) c FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.is_active=1 AND i.quantity=0 {$catWhere}")->fetch_assoc()['c'] ?? 0);
$totalStockValue = (float)($conn->query("SELECT COALESCE(SUM(i.quantity*p.price),0) v FROM inventory i JOIN products p ON p.id=i.product_id WHERE p.is_active=1 {$catWhere}")->fetch_assoc()['v'] ?? 0);

// Full inventory
$inventoryTable = $conn->query("
    SELECT p.name, p.unit, p.price, c.name AS category,
           i.quantity, i.reorder_level, i.last_updated,
           (i.quantity * p.price) AS stock_value
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    WHERE p.is_active = 1
    {$catWhere}
    ORDER BY i.quantity ASC, p.name ASC
");

// ── Print chrome ──────────────────────────────────────────────
$printTitle    = 'Inventory Report';
$printSubtitle = $catName;
$printMeta     = [
    ['label' => 'Category',        'value' => $catName],
    ['label' => 'Active Products', 'value' => number_format($totalProducts)],
    ['label' => 'Stock Value',     'value' => peso($totalStockValue)],
    ['label' => 'As of',           'value' => date('M j, Y')],
];

require '../admin/report_print_header.php';
?>

<!-- KPI summary -->
<div class="rp-kpis">
    <div class="rp-kpi accent-blue">
        <div class="k-label">Total Products</div>
        <div class="k-value"><?= number_format($totalProducts) ?></div>
        <div class="k-sub">Active &amp; tracked</div>
    </div>
    <div class="rp-kpi accent-amber">
        <div class="k-label">Low Stock</div>
        <div class="k-value"><?= number_format($lowStockCount) ?></div>
        <div class="k-sub">At/below reorder level</div>
    </div>
    <div class="rp-kpi accent-red">
        <div class="k-label">Out of Stock</div>
        <div class="k-value"><?= number_format($outOfStockCount) ?></div>
        <div class="k-sub">Zero quantity</div>
    </div>
    <div class="rp-kpi accent-green">
        <div class="k-label">Stock Value</div>
        <div class="k-value" style="font-size:1.05rem;"><?= peso($totalStockValue) ?></div>
        <div class="k-sub">Qty × price</div>
    </div>
</div>

<!-- Inventory table -->
<div class="rp-section-title">Full Inventory Snapshot</div>
<?php
$rows = [];
if ($inventoryTable && $inventoryTable->num_rows > 0) {
    while ($row = $inventoryTable->fetch_assoc()) $rows[] = $row;
}
$sumValue = 0.0;
foreach ($rows as $r) $sumValue += (float)$r['stock_value'];
?>
<?php if (!empty($rows)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th style="width:34px;">#</th>
                <th>Product</th>
                <th>Category</th>
                <th>Unit</th>
                <th class="num">Price</th>
                <th class="num">Stock</th>
                <th class="num">Reorder</th>
                <th>Status</th>
                <th>Last Updated</th>
                <th class="num">Stock Value</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $i => $r):
                $qty = (int)$r['quantity'];
                $rl  = (int)$r['reorder_level'];
                if ($qty === 0) {
                    $pill = 'pill-red';
                    $lbl = 'Out of Stock';
                } elseif ($qty <= $rl) {
                    $pill = 'pill-amber';
                    $lbl = 'Low Stock';
                } else {
                    $pill = 'pill-green';
                    $lbl = 'OK';
                }
            ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td style="font-weight:600;"><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= htmlspecialchars($r['category']) ?></td>
                    <td class="muted"><?= htmlspecialchars($r['unit']) ?></td>
                    <td class="num"><?= peso((float)$r['price']) ?></td>
                    <td class="num" style="font-weight:700;"><?= number_format($qty) ?></td>
                    <td class="num muted"><?= number_format($rl) ?></td>
                    <td><span class="rp-pill <?= $pill ?>"><?= $lbl ?></span></td>
                    <td class="muted" style="white-space:nowrap;"><?= date('M j, Y', strtotime($r['last_updated'])) ?></td>
                    <td class="num"><?= peso((float)$r['stock_value']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="9">Total Stock Value (<?= count($rows) ?> product<?= count($rows) !== 1 ? 's' : '' ?>)</td>
                <td class="num"><?= peso($sumValue) ?></td>
            </tr>
        </tfoot>
    </table>
<?php else: ?>
    <div class="rp-empty">No inventory data found<?= $catFilter ? ' for this category' : '' ?>.</div>
<?php endif; ?>

<?php
$signRolePrepared = 'Prepared by';
$signRoleApproved = 'Checked by';
require '../admin/report_print_footer.php';
