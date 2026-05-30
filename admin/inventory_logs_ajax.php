<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/inventory_logs_ajax.php
// Purpose: Returns stock history HTML for a product (AJAX)
// Called by: inventory.php → openLogs() JS function
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) {
    echo '<div style="padding:32px;text-align:center;color:#ef4444;">Invalid product.</div>';
    exit;
}

// Get product name
$pr = $conn->query("SELECT name, unit FROM products WHERE id = {$product_id} LIMIT 1");
$product = $pr ? $pr->fetch_assoc() : null;
if (!$product) {
    echo '<div style="padding:32px;text-align:center;color:#ef4444;">Product not found.</div>';
    exit;
}

// Get all logs for this product
$logs = $conn->query("
    SELECT il.*, u.full_name AS by_name
    FROM inventory_logs il
    LEFT JOIN users u ON u.id = il.created_by
    WHERE il.product_id = {$product_id}
    ORDER BY il.created_at DESC
    LIMIT 50
");
?>
<style>
.logs-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.logs-table thead th {
    background: #111827; color: #e5e7eb;
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.07em;
    padding: 10px 14px; text-align: left; white-space: nowrap;
}
.logs-table tbody tr:nth-child(even) { background: #faf9f7; }
.logs-table tbody tr:hover { background: #fef9f4; }
.logs-table tbody td {
    padding: 10px 14px; border-bottom: 1px solid #f3f2f0;
    color: #1f2937; vertical-align: middle;
}
.logs-table tbody tr:last-child td { border-bottom: none; }
.log-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px; border-radius: 20px;
    font-size: 0.72rem; font-weight: 700; white-space: nowrap;
}
.log-in         { background: #d1fae5; color: #065f46; }
.log-out        { background: #fee2e2; color: #991b1b; }
.log-adjustment { background: #dbeafe; color: #1e40af; }
.logs-summary {
    display: flex; gap: 12px; padding: 14px 16px;
    border-bottom: 1px solid #e9e8e4;
    background: #f8f7f4; flex-wrap: wrap;
}
.logs-summary-chip {
    display: flex; flex-direction: column;
    align-items: center; gap: 2px;
    background: #fff; border: 1px solid #e5e7eb;
    border-radius: 8px; padding: 8px 14px; min-width: 80px;
}
.chip-val   { font-size: 1.1rem; font-weight: 800; color: #111827; }
.chip-label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; }
.empty-logs { padding: 40px 20px; text-align: center; color: #6b7280; }
.empty-logs-icon { font-size: 2.2rem; margin-bottom: 10px; }
</style>

<?php
// Summary stats
$totalIn  = 0; $totalOut = 0; $adjustments = 0; $entries = 0;
if ($logs) {
    $logsArr = [];
    while ($l = $logs->fetch_assoc()) { $logsArr[] = $l; }

    foreach ($logsArr as $l) {
        $entries++;
        if ($l['type'] === 'in')         $totalIn  += $l['quantity'];
        elseif ($l['type'] === 'out')    $totalOut += $l['quantity'];
        elseif ($l['type'] === 'adjustment') $adjustments++;
    }
}
?>

<!-- Summary chips -->
<div class="logs-summary">
    <div class="logs-summary-chip">
        <span class="chip-val" style="color:#065f46;"><?= number_format($totalIn) ?></span>
        <span class="chip-label">Total In</span>
    </div>
    <div class="logs-summary-chip">
        <span class="chip-val" style="color:#991b1b;"><?= number_format($totalOut) ?></span>
        <span class="chip-label">Total Out</span>
    </div>
    <div class="logs-summary-chip">
        <span class="chip-val" style="color:#1e40af;"><?= number_format($adjustments) ?></span>
        <span class="chip-label">Adjustments</span>
    </div>
    <div class="logs-summary-chip">
        <span class="chip-val"><?= number_format($entries) ?></span>
        <span class="chip-label">Log Entries</span>
    </div>
    <div class="logs-summary-chip" style="flex:1;min-width:120px;">
        <span class="chip-val" style="font-size:0.85rem;font-weight:600;color:#6b7280;"><?= htmlspecialchars($product['name']) ?></span>
        <span class="chip-label"><?= htmlspecialchars($product['unit']) ?></span>
    </div>
</div>

<?php if (!empty($logsArr)): ?>
<div style="overflow-x:auto;">
<table class="logs-table">
    <thead>
        <tr>
            <th>Date & Time</th>
            <th>Type</th>
            <th>Quantity</th>
            <th>Reason / Notes</th>
            <th>By</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($logsArr as $log):
        $typeClass = match($log['type']) {
            'in'         => 'log-in',
            'out'        => 'log-out',
            'adjustment' => 'log-adjustment',
            default      => 'log-in'
        };
        $typeLabel = match($log['type']) {
            'in'         => '<i class="fa-solid fa-arrow-up"></i> Stock In',
            'out'        => '<i class="fa-solid fa-arrow-down"></i> Stock Out',
            'adjustment' => '⇄ Set to',
            default      => $log['type']
        };
    ?>
    <tr>
        <td style="white-space:nowrap;font-size:0.8rem;color:#6b7280;">
            <?= date('M j, Y', strtotime($log['created_at'])) ?><br>
            <span style="font-size:0.75rem;"><?= date('g:i A', strtotime($log['created_at'])) ?></span>
        </td>
        <td><span class="log-badge <?= $typeClass ?>"><?= $typeLabel ?></span></td>
        <td style="font-weight:700;font-size:0.95rem;">
            <?php if ($log['type'] === 'in'): ?>
                <span style="color:#065f46;">+<?= number_format($log['quantity']) ?></span>
            <?php elseif ($log['type'] === 'out'): ?>
                <span style="color:#991b1b;">−<?= number_format($log['quantity']) ?></span>
            <?php else: ?>
                <span style="color:#1e40af;"><?= number_format($log['quantity']) ?></span>
            <?php endif; ?>
        </td>
        <td style="color:#374151;font-size:0.83rem;">
            <?= htmlspecialchars($log['reason'] ?? '—') ?>
        </td>
        <td style="font-size:0.8rem;color:#6b7280;">
            <?= htmlspecialchars($log['by_name'] ?? 'System') ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php else: ?>
<div class="empty-logs">
    <div class="empty-logs-icon"><i class="fa-solid fa-clipboard-list"></i></div>
    <div>No stock history found for this product.</div>
</div>
<?php endif; ?>