<?php
session_start();
require_once '../config/db.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo '<div style="padding:32px;text-align:center;color:#d94f46;">Invalid order ID.</div>';
    exit;
}

$stmt = $conn->prepare("
    SELECT o.id, o.status, o.total_amount, o.delivery_fee, o.payment_method,
           o.payment_status, o.delivery_address, o.notes, o.gcash_proof,
           o.created_at, o.updated_at,
           u.id AS user_id, u.full_name, u.email, u.phone, u.address AS customer_address
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE o.id = ? LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$o = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$o) {
    echo '<div style="padding:32px;text-align:center;color:#d94f46;">Order not found.</div>';
    exit;
}

// Items + image_url
$items = $conn->query("
    SELECT oi.id, oi.quantity, oi.unit_price, oi.subtotal,
           p.name AS product_name, p.unit, p.image_url,
           c.name AS category_name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE oi.order_id = {$id}
    ORDER BY oi.id ASC
");

$transactions = $conn->query("
    SELECT id, amount, payment_method, reference_no, transaction_date, notes
    FROM transactions WHERE order_id = {$id}
    ORDER BY transaction_date DESC
");

$statusMap = [
    'pending'          => ['⏳', '#8a5a0c', '#fbf1de', 'Pending'],
    'approved'         => ['✓',  '#2b62ad', '#e8f0fb', 'Approved'],
    'processing'       => ['⚙',  '#6a4bc0', '#f0ecfa', 'Processing'],
    'out_for_delivery' => ['🚚', '#a4680c', '#fde8d4', 'Out for Delivery'],
    'delivered'        => ['✓',  '#1f7a48', '#e6f4ec', 'Delivered'],
    'cancelled'        => ['✕',  '#b23c34', '#fbeae9', 'Cancelled'],
];
[$sIcon, $sColor, $sBg, $sLabel] = $statusMap[$o['status']] ?? ['?', '#6f6a62', '#f0eee9', ucfirst($o['status'])];
?>
<style>
    .od-wrap {
        font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
        color: #4b4740;
    }

    /* Dark header banner */
    .od-banner {
        background: linear-gradient(135deg, #2b2823, #3d3833);
        padding: 20px 24px;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
    }

    .od-banner-left {}

    .od-banner-order {
        font-size: 1.25rem;
        font-weight: 900;
        color: #fff;
        letter-spacing: -0.02em;
        margin-bottom: 3px;
    }

    .od-banner-date {
        font-size: 0.75rem;
        color: #b0a99e;
    }

    .od-banner-right {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }

    .od-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 14px;
        border-radius: 20px;
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        border: 1.5px solid;
    }

    .od-pay-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 11px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .od-pay-paid {
        background: #e6f4ec;
        color: #1f7a48;
    }

    .od-pay-unpaid {
        background: #fbeae9;
        color: #b23c34;
    }

    /* Quick chips */
    .od-chips {
        display: flex;
        gap: 8px;
        padding: 16px 24px 0;
        flex-wrap: wrap;
    }

    .od-chip {
        flex: 1;
        min-width: 100px;
        background: #faf9f7;
        border: 1px solid #ebe8e3;
        border-radius: 10px;
        padding: 10px 14px;
    }

    .od-chip-label {
        font-size: 0.62rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #9c968c;
        margin-bottom: 3px;
    }

    .od-chip-value {
        font-size: 0.88rem;
        font-weight: 700;
        color: #23201c;
    }

    .od-chip-value.big {
        font-size: 1.05rem;
        color: #d16b12;
    }

    /* Section */
    .od-section {
        padding: 16px 24px 0;
    }

    .od-section-head {
        font-size: 0.65rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: #d16b12;
        margin-bottom: 10px;
        padding-bottom: 6px;
        border-bottom: 1px solid #fde8d4;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .od-section-body {
        padding-bottom: 4px;
    }

    /* Customer */
    .od-customer {
        display: flex;
        align-items: center;
        gap: 12px;
        background: #faf9f7;
        border: 1px solid #ebe8e3;
        border-radius: 10px;
        padding: 12px 16px;
    }

    .od-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: linear-gradient(135deg, #e67e22, #f0a04b);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
    }

    .od-cust-name {
        font-weight: 700;
        color: #23201c;
        font-size: 0.92rem;
    }

    .od-cust-sub {
        font-size: 0.76rem;
        color: #6f6a62;
        margin-top: 2px;
    }

    /* Address */
    .od-address {
        background: #faf9f7;
        border: 1px solid #ebe8e3;
        border-radius: 10px;
        padding: 12px 14px;
        font-size: 0.86rem;
        color: #4b4740;
        line-height: 1.65;
    }

    /* Items */
    .od-item-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #f0eee9;
    }

    .od-item-row:last-child {
        border-bottom: none;
    }

    .od-item-img {
        width: 44px;
        height: 44px;
        border-radius: 9px;
        object-fit: cover;
        flex-shrink: 0;
        border: 1px solid #ebe8e3;
    }

    .od-item-emoji {
        width: 44px;
        height: 44px;
        border-radius: 9px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
    }

    .od-item-info {
        flex: 1;
        min-width: 0;
    }

    .od-item-name {
        font-size: 0.88rem;
        font-weight: 700;
        color: #23201c;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .od-item-unit {
        font-size: 0.7rem;
        color: #6f6a62;
        margin-top: 1px;
    }

    .od-item-qty {
        font-size: 0.82rem;
        font-weight: 700;
        color: #4b4740;
        background: #f0eee9;
        border-radius: 6px;
        padding: 3px 8px;
        white-space: nowrap;
    }

    .od-item-price {
        font-size: 0.78rem;
        color: #6f6a62;
        min-width: 60px;
        text-align: right;
    }

    .od-item-sub {
        font-size: 0.92rem;
        font-weight: 800;
        color: #d16b12;
        min-width: 72px;
        text-align: right;
    }

    /* Totals */
    .od-totals {
        background: #faf9f7;
        border: 1px solid #ebe8e3;
        border-radius: 10px;
        padding: 12px 14px;
        margin-top: 8px;
    }

    .od-total-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.84rem;
        padding: 3px 0;
        color: #6f6a62;
    }

    .od-total-row span:last-child {
        font-weight: 600;
        color: #4b4740;
    }

    .od-total-divider {
        height: 1px;
        background: #ebe8e3;
        margin: 8px 0;
    }

    .od-grand {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .od-grand-label {
        font-size: 0.95rem;
        font-weight: 800;
        color: #23201c;
    }

    .od-grand-value {
        font-size: 1.2rem;
        font-weight: 900;
        color: #d16b12;
    }

    /* Notes */
    .od-notes {
        background: #fbf1de;
        border: 1px solid #f2ddb0;
        border-radius: 10px;
        padding: 12px 14px;
        font-size: 0.84rem;
        color: #8a5a0c;
        line-height: 1.6;
        white-space: pre-line;
    }

    .od-notes-label {
        font-size: 0.62rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #a4680c;
        margin-bottom: 5px;
    }

    /* Proof */
    .od-proof-img {
        width: 100%;
        border-radius: 10px;
        border: 1px solid #ebe8e3;
        box-shadow: 0 2px 12px rgba(35, 32, 28, 0.08);
        cursor: pointer;
        transition: transform 0.15s;
    }

    .od-proof-img:hover {
        transform: scale(1.01);
    }

    .od-proof-hint {
        font-size: 0.72rem;
        color: #9c968c;
        text-align: center;
        margin-top: 6px;
    }

    .od-no-proof {
        background: #e8f0fb;
        border: 1px solid #bcd6f5;
        border-radius: 10px;
        padding: 14px 16px;
        font-size: 0.82rem;
        color: #2b62ad;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* Transaction */
    .od-txn {
        background: #e6f4ec;
        border: 1px solid #a7dcbc;
        border-radius: 10px;
        padding: 12px 14px;
    }

    .od-txn-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.84rem;
        padding: 4px 0;
        border-bottom: 1px solid #cde9d7;
    }

    .od-txn-row:last-child {
        border-bottom: none;
    }

    .od-txn-label {
        color: #6f6a62;
    }

    .od-txn-value {
        font-weight: 700;
        color: #1f7a48;
    }

    .od-no-txn {
        background: #faf9f7;
        border: 1px solid #ebe8e3;
        border-radius: 10px;
        padding: 14px 16px;
        font-size: 0.82rem;
        color: #9c968c;
        text-align: center;
    }

    .od-spacer {
        height: 20px;
    }

    @media(max-width:500px) {
        .od-chips {
            flex-direction: column;
        }
    }
</style>

<div class="od-wrap">

    <!-- Dark header -->
    <div class="od-banner">
        <div class="od-banner-left">
            <div class="od-banner-order">Order #<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?></div>
            <div class="od-banner-date">Placed <?= date('F j, Y \a\t g:i A', strtotime($o['created_at'])) ?></div>
        </div>
        <div class="od-banner-right">
            <span class="od-status-pill" style="background:<?= $sBg ?>22;color:<?= $sColor ?>;border-color:<?= $sColor ?>44;">
                <?= $sIcon ?> <?= $sLabel ?>
            </span>
            <span class="od-pay-pill <?= $o['payment_status'] === 'paid' ? 'od-pay-paid' : 'od-pay-unpaid' ?>">
                <?= $o['payment_status'] === 'paid' ? '✓ Paid' : '⏳ Unpaid' ?>
            </span>
        </div>
    </div>

    <!-- Quick chips -->
    <div class="od-chips">
        <div class="od-chip">
            <div class="od-chip-label">Grand Total</div>
            <div class="od-chip-value big">₱<?= number_format((float)$o['total_amount'], 2) ?></div>
        </div>
        <div class="od-chip">
            <div class="od-chip-label">Payment</div>
            <div class="od-chip-value"><?= strtoupper($o['payment_method']) ?></div>
        </div>
        <?php if ($o['delivery_fee'] !== null): ?>
            <div class="od-chip">
                <div class="od-chip-label">Delivery Fee</div>
                <div class="od-chip-value">₱<?= number_format((float)$o['delivery_fee'], 2) ?></div>
            </div>
        <?php else: ?>
            <div class="od-chip">
                <div class="od-chip-label">Delivery Fee</div>
                <div class="od-chip-value" style="color:#a4680c;">Not set yet</div>
            </div>
        <?php endif; ?>
        <div class="od-chip">
            <div class="od-chip-label">Updated</div>
            <div class="od-chip-value" style="font-size:0.78rem;"><?= date('M j, Y g:i A', strtotime($o['updated_at'])) ?></div>
        </div>
    </div>

    <!-- Customer -->
    <div class="od-section">
        <div class="od-section-head"><i class="fa-solid fa-user"></i> Customer</div>
        <div class="od-section-body">
            <div class="od-customer">
                <div class="od-avatar"><?= strtoupper(substr($o['full_name'], 0, 1)) ?></div>
                <div>
                    <div class="od-cust-name"><?= htmlspecialchars($o['full_name']) ?></div>
                    <div class="od-cust-sub"><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($o['email']) ?></div>
                    <?php if ($o['phone']): ?>
                        <div class="od-cust-sub"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($o['phone']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Delivery -->
    <div class="od-section">
        <div class="od-section-head"><i class="fa-solid fa-location-dot"></i> Delivery / Pickup</div>
        <div class="od-section-body">
            <div class="od-address"><?= nl2br(htmlspecialchars($o['delivery_address'] ?: '— No address provided —')) ?></div>
        </div>
    </div>

    <!-- Items -->
    <div class="od-section">
        <div class="od-section-head"><i class="fa-solid fa-egg"></i> Items Ordered</div>
        <div class="od-section-body">
            <?php
            $itemsSubtotal = 0;
            $itemRows = '';
            if ($items && $items->num_rows > 0):
                while ($item = $items->fetch_assoc()):
                    $itemsSubtotal += (float)$item['subtotal'];
                    $isChick = stripos((string)($item['category_name'] ?? ''), 'chicken') !== false || stripos($item['product_name'], 'chicken') !== false;
                    $emoji   = $isChick ? '<i class="fa-solid fa-drumstick-bite"></i>' : '<i class="fa-solid fa-egg"></i>';
                    $emojiBg = $isChick ? 'background:linear-gradient(135deg,#e6f4ec,#cde9d7)' : 'background:linear-gradient(135deg,#fef4ea,#fde8d4)';
            ?>
                    <div class="od-item-row">
                        <?php if (!empty($item['image_url'])): ?>
                            <img src="<?= htmlspecialchars($item['image_url']) ?>" class="od-item-img" alt="<?= htmlspecialchars($item['product_name']) ?>">
                        <?php else: ?>
                            <div class="od-item-emoji" style="<?= $emojiBg ?>"><?= $emoji ?></div>
                        <?php endif; ?>
                        <div class="od-item-info">
                            <div class="od-item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                            <div class="od-item-unit"><?= htmlspecialchars($item['unit']) ?></div>
                        </div>
                        <span class="od-item-qty">×<?= (int)$item['quantity'] ?></span>
                        <div class="od-item-price">₱<?= number_format((float)$item['unit_price'], 2) ?><br><span style="font-size:0.65rem;color:#9c968c;">each</span></div>
                        <div class="od-item-sub">₱<?= number_format((float)$item['subtotal'], 2) ?></div>
                    </div>
            <?php endwhile;
            endif; ?>

            <!-- Totals -->
            <div class="od-totals">
                <div class="od-total-row"><span>Items subtotal</span><span>₱<?= number_format($itemsSubtotal, 2) ?></span></div>
                <div class="od-total-row">
                    <span>Delivery fee</span>
                    <span><?= $o['delivery_fee'] !== null ? '₱' . number_format((float)$o['delivery_fee'], 2) : '<span style="color:#a4680c;font-size:0.78rem;">Not set</span>' ?></span>
                </div>
                <div class="od-total-divider"></div>
                <div class="od-grand">
                    <span class="od-grand-label">Grand Total</span>
                    <span class="od-grand-value">₱<?= number_format((float)$o['total_amount'], 2) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Notes -->
    <?php if ($o['notes']): ?>
        <div class="od-section">
            <div class="od-section-head"><i class="fa-solid fa-file-lines"></i> Order Notes</div>
            <div class="od-section-body">
                <div class="od-notes">
                    <div class="od-notes-label">📝 Notes</div>
                    <?= htmlspecialchars($o['notes']) ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- GCash Proof -->
    <?php if ($o['payment_method'] === 'gcash'): ?>
        <div class="od-section">
            <div class="od-section-head"><i class="fa-solid fa-paperclip"></i> GCash Payment Proof</div>
            <div class="od-section-body">
                <?php if (!empty($o['gcash_proof'])): ?>
                    <img src="../<?= htmlspecialchars($o['gcash_proof']) ?>?v=<?= time() ?>"
                        class="od-proof-img"
                        alt="GCash Payment Proof"
                        onclick="viewProof('<?= htmlspecialchars(addslashes($o['gcash_proof'])) ?>', '<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?>')">
                    <div class="od-proof-hint">Click to enlarge</div>
                <?php else: ?>
                    <div class="od-no-proof">
                        <i class="fa-solid fa-clock" style="color:#3b82f6;flex-shrink:0;"></i>
                        Customer has not yet uploaded payment proof. It will appear here once submitted.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Transactions -->
    <div class="od-section">
        <div class="od-section-head"><i class="fa-solid fa-credit-card"></i> Payment Records</div>
        <div class="od-section-body">
            <?php if ($transactions && $transactions->num_rows > 0): ?>
                <?php while ($txn = $transactions->fetch_assoc()): ?>
                    <div class="od-txn" style="margin-bottom:8px;">
                        <div class="od-txn-row"><span class="od-txn-label">Transaction</span><span class="od-txn-value">#<?= str_pad($txn['id'], 4, '0', STR_PAD_LEFT) ?></span></div>
                        <div class="od-txn-row"><span class="od-txn-label">Method</span><span class="od-txn-value"><?= strtoupper($txn['payment_method']) ?></span></div>
                        <?php if ($txn['reference_no']): ?>
                            <div class="od-txn-row"><span class="od-txn-label">Reference No.</span><span class="od-txn-value"><?= htmlspecialchars($txn['reference_no']) ?></span></div>
                        <?php endif; ?>
                        <div class="od-txn-row"><span class="od-txn-label">Amount</span><span class="od-txn-value" style="font-size:1rem;">₱<?= number_format((float)$txn['amount'], 2) ?></span></div>
                        <div class="od-txn-row"><span class="od-txn-label">Date</span><span class="od-txn-value"><?= date('M j, Y g:i A', strtotime($txn['transaction_date'])) ?></span></div>
                        <?php if ($txn['notes']): ?>
                            <div class="od-txn-row"><span class="od-txn-label">Note</span><span class="od-txn-value"><?= htmlspecialchars($txn['notes']) ?></span></div>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="od-no-txn">
                    No payment transaction recorded yet.
                    <?= $o['payment_status'] === 'unpaid' ? '<br>Use the <strong>Update</strong> button and mark as <strong>Paid</strong> to auto-create one.' : '' ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="od-spacer"></div>
</div>