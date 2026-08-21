<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/customer_detail_ajax.php
// Purpose: Returns customer detail HTML for the View modal
// Called by: customers.php → openView() JS function
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo '<div style="padding:32px;text-align:center;color:#d94f46;">Invalid customer ID.</div>';
    exit;
}

// Get customer
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'customer' LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$c) {
    echo '<div style="padding:32px;text-align:center;color:#d94f46;">Customer not found.</div>';
    exit;
}

// Order summary
$r = $conn->query("
    SELECT COUNT(*) AS total_orders,
           COALESCE(SUM(total_amount),0) AS total_spent,
           SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered,
           SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) AS cancelled,
           SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) AS pending
    FROM orders WHERE user_id = {$id}
");
$stats = $r->fetch_assoc();

// Recent orders (last 8)
$orders = $conn->query("
    SELECT o.id, o.status, o.total_amount, o.payment_method,
           o.payment_status, o.created_at,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.user_id = {$id}
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 8
");

$initial = strtoupper(substr($c['full_name'], 0, 1));
?>
<style>
    .cp-header,
    .cp-stats,
    .cp-details,
    .cp-section,
    .cp-orders,
    .cp-address {
        font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    }

    .cp-header {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px 20px;
        background: #faf9f7;
        border-radius: 10px;
        margin-bottom: 20px;
        border: 1px solid #ebe8e3;
    }

    .cp-avatar {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: linear-gradient(135deg, #e67e22, #f0a04b);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 800;
        color: #fff;
        flex-shrink: 0;
    }

    .cp-name {
        font-size: 1.05rem;
        font-weight: 700;
        color: #23201c;
    }

    .cp-email {
        font-size: 0.82rem;
        color: #6f6a62;
        margin-top: 2px;
    }

    .cp-since {
        font-size: 0.73rem;
        color: #9c968c;
        margin-top: 3px;
    }

    .cp-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 0.72rem;
        font-weight: 600;
        margin-top: 5px;
    }

    .cp-status-badge.active {
        background: #e6f4ec;
        color: #1f7a48;
    }

    .cp-status-badge.inactive {
        background: #fbeae9;
        color: #b23c34;
    }

    /* Stat chips row */
    .cp-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-bottom: 18px;
    }

    .cp-stat {
        background: #faf9f7;
        border: 1px solid #ebe8e3;
        border-radius: 9px;
        padding: 12px 14px;
        text-align: center;
    }

    .cp-stat-val {
        font-size: 1.3rem;
        font-weight: 800;
        color: #23201c;
    }

    .cp-stat-label {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: #6f6a62;
        margin-top: 3px;
    }

    /* Detail grid */
    .cp-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 18px;
    }

    .cp-detail-item {
        background: #faf9f7;
        border: 1px solid #ebe8e3;
        border-radius: 8px;
        padding: 10px 14px;
    }

    .cp-detail-label {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        color: #9c968c;
        margin-bottom: 3px;
    }

    .cp-detail-value {
        font-size: 0.88rem;
        font-weight: 600;
        color: #23201c;
    }

    /* Section heading */
    .cp-section {
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #d16b12;
        margin-bottom: 8px;
        padding-bottom: 5px;
        border-bottom: 1px solid #fde8d4;
    }

    /* Orders mini table */
    .cp-orders {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.82rem;
    }

    .cp-orders thead th {
        background: #2b2823;
        color: #e8e3da;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.07em;
        padding: 8px 12px;
        text-align: left;
    }

    .cp-orders tbody tr:hover {
        background: #fdf6ee;
    }

    .cp-orders tbody td {
        padding: 9px 12px;
        border-bottom: 1px solid #f0eee9;
        color: #23201c;
        vertical-align: middle;
    }

    .cp-orders tbody tr:last-child td {
        border-bottom: none;
    }

    .o-badge {
        display: inline-flex;
        align-items: center;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 0.68rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .o-pending {
        background: #fbf1de;
        color: #8a5a0c;
    }

    .o-confirmed {
        background: #e8f0fb;
        color: #2b62ad;
    }

    .o-processing {
        background: #f0ecfa;
        color: #6a4bc0;
    }

    .o-out_for_delivery {
        background: #fde8d4;
        color: #a4680c;
    }

    .o-delivered {
        background: #e6f4ec;
        color: #1f7a48;
    }

    .o-cancelled {
        background: #fbeae9;
        color: #b23c34;
    }

    .cp-address {
        background: #faf9f7;
        border: 1px solid #ebe8e3;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 0.85rem;
        color: #4b4740;
        line-height: 1.5;
    }

    @media(max-width:500px) {
        .cp-stats {
            grid-template-columns: repeat(2, 1fr);
        }

        .cp-details {
            grid-template-columns: 1fr;
        }
    }
</style>

<!-- Profile header -->
<div class="cp-header">
    <div class="cp-avatar"><?= htmlspecialchars($initial) ?></div>
    <div>
        <div class="cp-name"><?= htmlspecialchars($c['full_name']) ?></div>
        <div class="cp-email"><?= htmlspecialchars($c['email']) ?></div>
        <div class="cp-since">Member since <?= date('F j, Y', strtotime($c['created_at'])) ?></div>
        <span class="cp-status-badge <?= $c['is_active'] ? 'active' : 'inactive' ?>">
            <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
    </div>
</div>

<!-- Stats row -->
<div class="cp-stats">
    <div class="cp-stat">
        <div class="cp-stat-val" style="color:#d16b12;"><?= number_format((int)$stats['total_orders']) ?></div>
        <div class="cp-stat-label">Total Orders</div>
    </div>
    <div class="cp-stat">
        <div class="cp-stat-val" style="color:#2f9e60;">₱<?= number_format((float)$stats['total_spent'], 0) ?></div>
        <div class="cp-stat-label">Total Spent</div>
    </div>
    <div class="cp-stat">
        <div class="cp-stat-val" style="color:#1f7a48;"><?= number_format((int)$stats['delivered']) ?></div>
        <div class="cp-stat-label">Delivered</div>
    </div>
    <div class="cp-stat">
        <div class="cp-stat-val" style="color:#b23c34;"><?= number_format((int)$stats['cancelled']) ?></div>
        <div class="cp-stat-label">Cancelled</div>
    </div>
</div>

<!-- Contact details -->
<div class="cp-section">Contact & Account Info</div>
<div class="cp-details" style="margin-bottom:16px;">
    <div class="cp-detail-item">
        <div class="cp-detail-label">Phone</div>
        <div class="cp-detail-value"><?= $c['phone'] ? htmlspecialchars($c['phone']) : '—' ?></div>
    </div>
    <div class="cp-detail-item">
        <div class="cp-detail-label">Account Status</div>
        <div class="cp-detail-value"><?= $c['is_active'] ? '✓ Active' : '<i class="fa-solid fa-ban"></i> Deactivated' ?></div>
    </div>
    <div class="cp-detail-item">
        <div class="cp-detail-label">Pending Orders</div>
        <div class="cp-detail-value"><?= number_format((int)$stats['pending']) ?> pending</div>
    </div>
    <div class="cp-detail-item">
        <div class="cp-detail-label">Customer ID</div>
        <div class="cp-detail-value">#<?= str_pad($c['id'], 4, '0', STR_PAD_LEFT) ?></div>
    </div>
</div>

<!-- Address -->
<?php if ($c['address']): ?>
    <div class="cp-section">Delivery Address</div>
    <div class="cp-address" style="margin-bottom:18px;">
        <?= nl2br(htmlspecialchars($c['address'])) ?>
    </div>
<?php endif; ?>

<!-- Order history -->
<div class="cp-section">Order History <?php if ($orders && $orders->num_rows > 0): ?><span style="color:#9c968c;font-weight:500;text-transform:none;letter-spacing:0;">(last 8)</span><?php endif; ?></div>
<?php if ($orders && $orders->num_rows > 0): ?>
    <div style="overflow-x:auto;border-radius:8px;border:1px solid #ebe8e3;">
        <table class="cp-orders">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($o = $orders->fetch_assoc()):
                    $sc = 'o-' . $o['status'];
                    $sl = ucwords(str_replace('_', ' ', $o['status']));
                ?>
                    <tr>
                        <td style="font-weight:700;color:#d16b12;">#<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?></td>
                        <td style="color:#6f6a62;font-size:0.8rem;"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                        <td style="color:#6f6a62;"><?= (int)$o['item_count'] ?> item<?= $o['item_count'] != 1 ? 's' : '' ?></td>
                        <td style="font-weight:700;">₱<?= number_format((float)$o['total_amount'], 2) ?></td>
                        <td style="font-size:0.8rem;text-transform:uppercase;color:#6f6a62;"><?= htmlspecialchars($o['payment_method']) ?></td>
                        <td><span class="o-badge <?= $sc ?>"><?= $sl ?></span></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div style="text-align:center;padding:24px;color:#9c968c;font-size:0.88rem;">
        <i class="fa-solid fa-cart-shopping"></i> This customer hasn't placed any orders yet.
    </div>
<?php endif; ?>