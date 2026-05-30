<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/order_detail_ajax.php
// Purpose: Returns full order detail HTML for the View modal
// Called by: orders.php → openView() JS function
//
// Schema notes (hineys_system verified):
//   orders: id, user_id, status, total_amount, payment_method,
//           payment_status, delivery_address, notes, created_at, updated_at
//   order_items: id, order_id, product_id, quantity, unit_price, subtotal
//   transactions: id, order_id, amount, payment_method,
//                 reference_no, transaction_date, notes
//   products: id, category_id, name, description, price, unit
//   users: id, full_name, email, phone, address
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo '<div style="padding:32px;text-align:center;color:#ef4444;">Invalid order ID.</div>';
    exit;
}

// Fetch order + customer
$stmt = $conn->prepare("
    SELECT o.id, o.status, o.total_amount, o.payment_method,
           o.payment_status, o.delivery_address, o.notes,
           o.created_at, o.updated_at,
           u.id AS user_id, u.full_name, u.email, u.phone, u.address AS customer_address
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE o.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$o = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$o) {
    echo '<div style="padding:32px;text-align:center;color:#ef4444;">Order not found.</div>';
    exit;
}

// Fetch order items + product info
$items = $conn->query("
    SELECT oi.id, oi.quantity, oi.unit_price, oi.subtotal,
           p.name AS product_name, p.unit,
           c.name AS category_name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE oi.order_id = {$id}
    ORDER BY oi.id ASC
");

// Fetch transactions (reference_no lives here, NOT in orders)
$transactions = $conn->query("
    SELECT id, amount, payment_method, reference_no, transaction_date, notes
    FROM transactions
    WHERE order_id = {$id}
    ORDER BY transaction_date DESC
");

// Status display map
$statusMap = [
    'pending'          => ['<i class="fa-solid fa-clock"></i>', '#92400e', '#fef3c7'],
    'confirmed'        => ['✓', '#1e40af', '#dbeafe'],
    'processing'       => ['<i class="fa-solid fa-gear"></i>', '#5b21b6', '#ede9fe'],
    'out_for_delivery' => ['<i class="fa-solid fa-truck"></i>', '#9a3412', '#ffedd5'],
    'delivered'        => ['<i class="fa-solid fa-champagne-glasses"></i>', '#065f46', '#d1fae5'],
    'cancelled'        => ['✕', '#991b1b', '#fee2e2'],
];
[$sEmoji, $sColor, $sBg] = $statusMap[$o['status']] ?? ['❓', '#374151', '#f3f4f6'];
$statusLabel = ucwords(str_replace('_', ' ', $o['status']));
?>
<style>
/* All styles scoped to this AJAX partial */
.od-status-hero {
    display:flex; align-items:center; gap:12px;
    padding:14px 18px; border-radius:10px; margin-bottom:18px;
}
.od-status-emoji { font-size:1.6rem; }
.od-status-name  { font-size:0.95rem; font-weight:800; }
.od-status-sub   { font-size:0.78rem; margin-top:2px; }

.od-chips { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
.od-chip {
    flex:1; min-width:120px; background:#f8f7f4;
    border:1px solid #e9e8e4; border-radius:9px; padding:12px 14px;
}
.od-chip-label { font-size:0.65rem; font-weight:800; text-transform:uppercase; letter-spacing:0.1em; color:#9ca3af; margin-bottom:4px; }
.od-chip-value { font-size:0.9rem; font-weight:700; color:#111827; }

.od-section { font-size:0.68rem; font-weight:800; text-transform:uppercase; letter-spacing:0.1em; color:#e67e22; margin:18px 0 10px; padding-bottom:5px; border-bottom:1px solid #fde9d0; }
.od-section:first-child { margin-top:0; }

.od-customer-row {
    display:flex; align-items:center; gap:12px;
    background:#f8f7f4; border:1px solid #e9e8e4; border-radius:10px;
    padding:12px 16px; margin-bottom:18px;
}
.od-avatar { width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#e67e22,#f39c12); display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:700; color:#fff; flex-shrink:0; }
.od-cust-name  { font-weight:700; color:#111827; font-size:0.9rem; }
.od-cust-sub   { font-size:0.78rem; color:#6b7280; margin-top:2px; }

.od-address { background:#f8f7f4; border:1px solid #e9e8e4; border-radius:8px; padding:10px 14px; font-size:0.85rem; color:#374151; line-height:1.6; margin-bottom:18px; }

.od-items-table { width:100%; border-collapse:collapse; font-size:0.84rem; }
.od-items-table thead th { background:#111827; color:#e5e7eb; font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.07em; padding:8px 12px; text-align:left; }
.od-items-table tbody tr:nth-child(even) { background:#faf9f7; }
.od-items-table tbody tr:hover { background:#fef9f4; }
.od-items-table tbody td { padding:10px 12px; border-bottom:1px solid #f3f2f0; color:#1f2937; vertical-align:middle; }
.od-items-table tbody tr:last-child td { border-bottom:none; }
.od-items-table tfoot td { padding:10px 12px; font-weight:700; color:#111827; border-top:2px solid #e5e7eb; }

.od-product-thumb { display:flex; align-items:center; gap:8px; }
.od-thumb { width:30px; height:30px; border-radius:6px; background:linear-gradient(135deg,#fef3e8,#fde9d0); display:flex; align-items:center; justify-content:center; font-size:14px; border:1px solid #fddcb5; flex-shrink:0; }
.od-product-name { font-weight:600; color:#111827; }
.od-product-unit { font-size:0.72rem; color:#6b7280; }

.od-pay-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:700; }
.od-pay-paid   { background:#d1fae5; color:#065f46; }
.od-pay-unpaid { background:#fee2e2; color:#991b1b; }
.od-method-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:6px; background:#f3f4f6; color:#374151; font-size:0.75rem; font-weight:600; }

.od-txn-row { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 14px; display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.od-txn-amount { font-size:1rem; font-weight:800; color:#065f46; margin-left:auto; }
.od-txn-ref { display:inline-flex; align-items:center; gap:4px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:2px 8px; border-radius:6px; font-size:0.75rem; font-weight:600; }

.od-notes { background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:10px 14px; font-size:0.85rem; color:#92400e; line-height:1.6; margin-bottom:18px; }

@media(max-width:480px) { .od-chips { flex-direction:column; } }
</style>

<!-- Status hero -->
<div class="od-status-hero" style="background:<?= $sBg ?>;border:1px solid <?= $sColor ?>33;">
    <div class="od-status-emoji"><?= $sEmoji ?></div>
    <div style="flex:1;">
        <div class="od-status-name" style="color:<?= $sColor ?>;"><?= $statusLabel ?></div>
        <div class="od-status-sub" style="color:<?= $sColor ?>99;">Order #<?= str_pad($o['id'],4,'0',STR_PAD_LEFT) ?> · Placed <?= date('F j, Y \a\t g:i A', strtotime($o['created_at'])) ?></div>
    </div>
    <span class="od-pay-badge <?= $o['payment_status']==='paid'?'od-pay-paid':'od-pay-unpaid' ?>">
        <?= $o['payment_status']==='paid' ? '✓ Paid' : '<i class="fa-solid fa-clock"></i> Unpaid' ?>
    </span>
</div>

<!-- Quick info chips -->
<div class="od-chips">
    <div class="od-chip">
        <div class="od-chip-label">Order Total</div>
        <div class="od-chip-value" style="color:#e67e22;font-size:1.05rem;">₱<?= number_format((float)$o['total_amount'],2) ?></div>
    </div>
    <div class="od-chip">
        <div class="od-chip-label">Payment Method</div>
        <div class="od-chip-value">
            <span class="od-method-pill">
                <?= $o['payment_method']==='gcash'?'<i class="fa-solid fa-mobile-screen"></i>':'<i class="fa-solid fa-money-bill"></i>' ?>
                <?= strtoupper($o['payment_method']) ?>
            </span>
        </div>
    </div>
    <div class="od-chip">
        <div class="od-chip-label">Last Updated</div>
        <div class="od-chip-value" style="font-size:0.82rem;"><?= $o['updated_at'] ? date('M j, Y g:i A',strtotime($o['updated_at'])) : '—' ?></div>
    </div>
</div>

<!-- Customer -->
<div class="od-section">Customer</div>
<div class="od-customer-row">
    <div class="od-avatar"><?= strtoupper(substr($o['full_name'],0,1)) ?></div>
    <div>
        <div class="od-cust-name"><?= htmlspecialchars($o['full_name']) ?></div>
        <div class="od-cust-sub"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($o['email']) ?></div>
        <?php if ($o['phone']): ?>
        <div class="od-cust-sub"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($o['phone']) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- Delivery Address -->
<div class="od-section">Delivery Address</div>
<div class="od-address">
    <?= nl2br(htmlspecialchars($o['delivery_address'] ?: $o['customer_address'] ?: '— No address provided —')) ?>
</div>

<!-- Order Items -->
<div class="od-section">Order Items</div>
<?php if ($items && $items->num_rows > 0): ?>
<div style="overflow-x:auto;border-radius:8px;border:1px solid #e9e8e4;margin-bottom:18px;">
<table class="od-items-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Product</th>
            <th>Unit Price</th>
            <th>Qty</th>
            <th style="text-align:right;">Subtotal</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $n = 1;
    while ($item = $items->fetch_assoc()):
        $emoji = (stripos($item['product_name'],'chicken')!==false || stripos((string)($item['category_name']??''),'chicken')!==false) ? '<i class="fa-solid fa-drumstick-bite"></i>' : '<i class="fa-solid fa-egg"></i>';
    ?>
    <tr>
        <td style="color:#9ca3af;font-size:0.78rem;"><?= $n++ ?></td>
        <td>
            <div class="od-product-thumb">
                <div class="od-thumb"><?= $emoji ?></div>
                <div>
                    <div class="od-product-name"><?= htmlspecialchars($item['product_name']) ?></div>
                    <div class="od-product-unit"><?= htmlspecialchars($item['unit']) ?></div>
                </div>
            </div>
        </td>
        <td style="color:#6b7280;">₱<?= number_format((float)$item['unit_price'],2) ?></td>
        <td style="font-weight:700;">×<?= (int)$item['quantity'] ?></td>
        <td style="text-align:right;font-weight:700;color:#111827;">₱<?= number_format((float)$item['subtotal'],2) ?></td>
    </tr>
    <?php endwhile; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4" style="text-align:right;font-size:0.8rem;color:#6b7280;font-weight:400;">Order Total</td>
            <td style="text-align:right;font-size:1rem;color:#e67e22;">₱<?= number_format((float)$o['total_amount'],2) ?></td>
        </tr>
    </tfoot>
</table>
</div>
<?php else: ?>
<div style="text-align:center;padding:20px;color:#9ca3af;font-size:0.85rem;background:#f8f7f4;border-radius:8px;margin-bottom:18px;">No items found for this order.</div>
<?php endif; ?>

<!-- Order Notes -->
<?php if ($o['notes']): ?>
<div class="od-section">Order Notes</div>
<div class="od-notes" style="margin-bottom:18px;">
    <?= nl2br(htmlspecialchars($o['notes'])) ?>
</div>
<?php endif; ?>

<!-- Transactions (GCash ref lives here) -->
<div class="od-section">Payment Records</div>
<?php if ($transactions && $transactions->num_rows > 0): ?>
<?php while ($txn = $transactions->fetch_assoc()): ?>
<div class="od-txn-row">
    <div>✓</div>
    <div style="flex:1;">
        <div style="font-size:0.8rem;color:#065f46;font-weight:600;">
            Transaction #<?= str_pad($txn['id'],4,'0',STR_PAD_LEFT) ?>
            &nbsp;
            <span class="od-method-pill" style="font-size:0.7rem;"><?= strtoupper($txn['payment_method']) ?></span>
            <?php if ($txn['reference_no']): ?>
                <span class="od-txn-ref">Ref: <?= htmlspecialchars($txn['reference_no']) ?></span>
            <?php endif; ?>
        </div>
        <div style="font-size:0.73rem;color:#6b7280;margin-top:3px;"><?= date('M j, Y g:i A',strtotime($txn['transaction_date'])) ?></div>
        <?php if ($txn['notes']): ?>
        <div style="font-size:0.73rem;color:#6b7280;margin-top:2px;"><?= htmlspecialchars($txn['notes']) ?></div>
        <?php endif; ?>
    </div>
    <div class="od-txn-amount">₱<?= number_format((float)$txn['amount'],2) ?></div>
</div>
<?php endwhile; ?>
<?php else: ?>
<div style="text-align:center;padding:16px;color:#9ca3af;font-size:0.83rem;background:#f8f7f4;border-radius:8px;">
    No payment transaction recorded yet.
    <?= $o['payment_status']==='unpaid' ? '<br>Use the <strong>Update</strong> button and mark as <strong>Paid</strong> to auto-create one.' : '' ?>
</div>
<?php endif; ?>