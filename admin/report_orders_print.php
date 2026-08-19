<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/report_orders_print.php
//
// Clean print view of the Orders report. Opened in a new tab
// from report_orders.php, carrying the same ?from &to &status
// filters so the printout matches the on-screen report.
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$today    = date('Y-m-d');
$thisYear = date('Y');

// ── Same filters as report_orders.php ─────────────────────────
$dateFrom     = trim($_GET['from']   ?? date('Y-m-01'));
$dateTo       = trim($_GET['to']     ?? $today);
$statusFilter = trim($_GET['status'] ?? '');
if ($dateFrom > $dateTo) $dateFrom = $dateTo;

$dateFromSql = $conn->real_escape_string($dateFrom);
$dateToSql   = $conn->real_escape_string($dateTo);

$baseWhere = "WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'";
if ($statusFilter) {
    $baseWhere .= " AND o.status = '" . $conn->real_escape_string($statusFilter) . "'";
}

// KPIs
$totalOrders     = (int)($conn->query("SELECT COUNT(*) c FROM orders o {$baseWhere}")->fetch_assoc()['c'] ?? 0);
$deliveredOrders = (int)($conn->query("SELECT COUNT(*) c FROM orders o WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}' AND o.status='delivered'")->fetch_assoc()['c'] ?? 0);
$pendingOrders   = (int)($conn->query("SELECT COUNT(*) c FROM orders o WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}' AND o.status='pending'")->fetch_assoc()['c'] ?? 0);
$cancelledOrders = (int)($conn->query("SELECT COUNT(*) c FROM orders o WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}' AND o.status='cancelled'")->fetch_assoc()['c'] ?? 0);

// Revenue in range (paid, non-cancelled) — a useful printed figure
$revRow = $conn->query("
    SELECT COALESCE(SUM(total_amount),0) AS rev
    FROM orders o
    WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
      AND o.status <> 'cancelled' AND o.payment_status = 'paid'
")->fetch_assoc();
$rangeRevenue = (float)($revRow['rev'] ?? 0);

// Status breakdown (for the summary section)
$statusBreak = [];
$sb = $conn->query("
    SELECT o.status, COUNT(*) AS cnt, COALESCE(SUM(o.total_amount),0) AS amt
    FROM orders o
    WHERE DATE(o.created_at) BETWEEN '{$dateFromSql}' AND '{$dateToSql}'
    GROUP BY o.status
    ORDER BY cnt DESC
");
while ($row = $sb->fetch_assoc()) $statusBreak[] = $row;

// Orders list (same shape as the report; a touch higher cap for print)
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
    LIMIT 200
");

$statusLabelMap = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'processing' => 'Processing',
    'out_for_delivery' => 'Out for Delivery',
    'delivered' => 'Delivered',
    'cancelled' => 'Cancelled',
];
function orderStatusPill(string $s): string
{
    $map = [
        'pending' => 'pill-amber',
        'confirmed' => 'pill-blue',
        'processing' => 'pill-purple',
        'out_for_delivery' => 'pill-orange',
        'delivered' => 'pill-green',
        'cancelled' => 'pill-red',
    ];
    return $map[$s] ?? 'pill-gray';
}

// ── Print chrome ──────────────────────────────────────────────
$printTitle    = 'Orders Report';
$printSubtitle = date('M j, Y', strtotime($dateFrom)) . ' – ' . date('M j, Y', strtotime($dateTo));
$printMeta     = [
    ['label' => 'Period',  'value' => date('M j, Y', strtotime($dateFrom)) . ' to ' . date('M j, Y', strtotime($dateTo))],
    ['label' => 'Status',  'value' => $statusFilter ? ($statusLabelMap[$statusFilter] ?? ucfirst($statusFilter)) : 'All statuses'],
    ['label' => 'Total Orders', 'value' => number_format($totalOrders)],
];

require '../admin/report_print_header.php';
?>

<!-- KPI summary -->
<div class="rp-kpis">
    <div class="rp-kpi accent-blue">
        <div class="k-label">Total Orders</div>
        <div class="k-value"><?= number_format($totalOrders) ?></div>
        <div class="k-sub">In selected period</div>
    </div>
    <div class="rp-kpi accent-green">
        <div class="k-label">Delivered</div>
        <div class="k-value"><?= number_format($deliveredOrders) ?></div>
        <div class="k-sub"><?= $totalOrders > 0 ? round(($deliveredOrders / $totalOrders) * 100) . '% delivery rate' : '—' ?></div>
    </div>
    <div class="rp-kpi accent-amber">
        <div class="k-label">Pending</div>
        <div class="k-value"><?= number_format($pendingOrders) ?></div>
        <div class="k-sub">Awaiting confirmation</div>
    </div>
    <div class="rp-kpi accent-red">
        <div class="k-label">Cancelled</div>
        <div class="k-value"><?= number_format($cancelledOrders) ?></div>
        <div class="k-sub"><?= $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100) . '% cancellation' : '—' ?></div>
    </div>
</div>

<!-- Status breakdown -->
<div class="rp-section-title">Order Status Breakdown</div>
<?php if (!empty($statusBreak)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th>Status</th>
                <th class="num">Orders</th>
                <th class="num">% of Total</th>
                <th class="num">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($statusBreak as $row):
                $pct = $totalOrders > 0 ? round(($row['cnt'] / $totalOrders) * 100, 1) : 0;
            ?>
                <tr>
                    <td><span class="rp-pill <?= orderStatusPill($row['status']) ?>"><?= htmlspecialchars($statusLabelMap[$row['status']] ?? ucfirst($row['status'])) ?></span></td>
                    <td class="num"><?= number_format((int)$row['cnt']) ?></td>
                    <td class="num"><?= $pct ?>%</td>
                    <td class="num"><?= peso((float)$row['amt']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total (paid revenue)</td>
                <td class="num"><?= number_format($totalOrders) ?></td>
                <td class="num">100%</td>
                <td class="num"><?= peso($rangeRevenue) ?></td>
            </tr>
        </tfoot>
    </table>
<?php else: ?>
    <div class="rp-empty">No orders in this period.</div>
<?php endif; ?>

<?php
$ordersRows = [];
if ($ordersTable && $ordersTable->num_rows > 0) {
    while ($row = $ordersTable->fetch_assoc()) $ordersRows[] = $row;
}
?>
<!-- Orders detail -->
<div class="rp-section-title">
    Order Details
    <span class="count"><?= count($ordersRows) ?> order<?= count($ordersRows) !== 1 ? 's' : '' ?></span>
</div>
<?php if (!empty($ordersRows)): ?>
    <table class="rp-table">
        <thead>
            <tr>
                <th style="width:34px;">#</th>
                <th>Order ID</th>
                <th>Customer</th>
                <th class="num">Items</th>
                <th class="num">Total</th>
                <th>Payment</th>
                <th>Pay Status</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($ordersRows as $i => $o): ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td style="font-weight:700;">#<?= str_pad((string)$o['id'], 4, '0', STR_PAD_LEFT) ?></td>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($o['full_name']) ?></div>
                        <div class="muted" style="font-size:0.7rem;"><?= htmlspecialchars($o['email']) ?></div>
                    </td>
                    <td class="num"><?= (int)$o['item_count'] ?></td>
                    <td class="num" style="font-weight:700;"><?= peso((float)$o['total_amount']) ?></td>
                    <td><?= strtoupper(htmlspecialchars($o['payment_method'])) ?></td>
                    <td>
                        <?php if ($o['payment_status'] === 'paid'): ?>
                            <span class="rp-pill pill-green">Paid</span>
                        <?php else: ?>
                            <span class="rp-pill pill-red">Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="rp-pill <?= orderStatusPill($o['status']) ?>"><?= htmlspecialchars($statusLabelMap[$o['status']] ?? ucfirst($o['status'])) ?></span></td>
                    <td class="muted" style="white-space:nowrap;"><?= date('M j, Y', strtotime($o['created_at'])) ?><br><span style="font-size:0.68rem;"><?= date('g:i A', strtotime($o['created_at'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="muted" style="font-size:0.68rem;margin-top:6px;">Showing up to 200 most recent orders in range.</div>
<?php else: ?>
    <div class="rp-empty">No orders found for the selected period<?= $statusFilter ? ' and status filter' : '' ?>.</div>
<?php endif; ?>

<?php
$signRolePrepared = 'Prepared by';
$signRoleApproved = 'Verified by';
require '../admin/report_print_footer.php';
