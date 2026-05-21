<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/cart.php
// Purpose: Shopping cart page
// ============================================================

session_start();
require_once '../config/db.php';
requireCustomer();

$activePage = 'cart';
$uid        = (int)$_SESSION['user_id'];
$cartItems  = cartCount($conn);

// ── Fetch cart items ──────────────────────────────────────────
$cartRows = $conn->query("
    SELECT c.id AS cart_id, c.quantity, c.added_at,
           p.id AS product_id, p.name, p.price, p.unit, p.description,
           cat.name AS category,
           COALESCE(i.quantity, 0) AS stock
    FROM cart c
    JOIN products p ON p.id = c.product_id
    JOIN categories cat ON cat.id = p.category_id
    LEFT JOIN inventory i ON i.product_id = p.id
    WHERE c.user_id = {$uid}
    ORDER BY c.added_at DESC
");

$cartData  = [];
$cartTotal = 0.0;
$itemCount = 0;

while ($row = $cartRows->fetch_assoc()) {
    $row['subtotal'] = (float)$row['price'] * (int)$row['quantity'];
    $cartTotal      += $row['subtotal'];
    $itemCount      += (int)$row['quantity'];
    $cartData[]      = $row;
}

$deliveryFee = count($cartData) > 0 ? 50.00 : 0.00;
$grandTotal  = $cartTotal + $deliveryFee;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Cart — Hiney's Eggs &amp; Live Chicken</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --primary:      #e67e22;
    --primary-dark: #cf6d17;
    --primary-light:#fef3e8;
    --secondary:    #f39c12;
    --dark:         #1a1a2e;
    --dark2:        #2c3e50;
    --text:         #374151;
    --text-muted:   #6b7280;
    --bg:           #faf9f7;
    --card-bg:      #ffffff;
    --border:       #e5e7eb;
    --danger:       #ef4444;
    --success:      #10b981;
    --radius:       14px;
    --shadow:       0 2px 8px rgba(0,0,0,0.06), 0 8px 24px rgba(0,0,0,0.05);
    --navbar-h:     68px;
    --transition:   0.2s ease;
}

html { scroll-behavior: smooth; }
body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
}
a { text-decoration: none; color: inherit; }


/* ── Page Banner ── */
.page-banner {
    background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
    padding: 36px 0 40px;
    position: relative; overflow: hidden;
}
.page-banner::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse 500px 300px at 80% 50%, rgba(230,126,34,0.14), transparent 70%);
}
.page-banner-inner {
    max-width: 1200px; margin: 0 auto; padding: 0 32px;
    position: relative; z-index: 1;
}
.breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 0.78rem; color: #6b7a99; margin-bottom: 12px;
}
.breadcrumb a { color: #8fa3b3; }
.breadcrumb a:hover { color: var(--secondary); }
.page-banner-title {
    font-size: clamp(1.5rem, 3vw, 2rem);
    font-weight: 800; color: #fff; letter-spacing: -0.025em; margin-bottom: 4px;
}
.page-banner-sub { font-size: 0.9rem; color: #8fa3b3; }

/* ── Layout ── */
.container { max-width: 1200px; margin: 0 auto; padding: 0 32px; }
@media(max-width:600px) { .container { padding: 0 16px; } }

.cart-layout {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 28px;
    padding: 32px 0 60px;
    align-items: start;
}
@media(max-width:960px) { .cart-layout { grid-template-columns: 1fr; } }

/* ── Cart Card ── */
.cart-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.cart-card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid var(--border);
}

.cart-card-title {
    font-size: 1rem; font-weight: 800; color: var(--dark2);
    display: flex; align-items: center; gap: 8px;
}

.cart-count-pill {
    background: var(--primary); color: #fff;
    font-size: 0.72rem; font-weight: 700;
    padding: 2px 9px; border-radius: 20px;
}

.btn-clear-cart {
    display: flex; align-items: center; gap: 5px;
    padding: 6px 12px;
    border: 1.5px solid #fecaca; border-radius: 8px;
    background: #fff; color: #ef4444;
    font-size: 0.78rem; font-weight: 600;
    cursor: pointer; font-family: inherit;
    transition: all var(--transition);
}
.btn-clear-cart:hover { background: #fef2f2; border-color: #ef4444; }

/* ── Cart Item ── */
.cart-item {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 22px;
    border-bottom: 1px solid #f3f4f6;
    transition: background var(--transition), opacity 0.3s, transform 0.3s;
    position: relative;
}
.cart-item:last-child { border-bottom: none; }
.cart-item:hover { background: #fefefe; }
.cart-item.removing { opacity: 0; transform: translateX(20px); }

/* Thumb */
.cart-item-thumb {
    width: 72px; height: 72px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; flex-shrink: 0;
    border: 1px solid var(--border);
}
.cart-thumb-egg   { background: linear-gradient(135deg, #fef9ee, #fdeec8); }
.cart-thumb-chick { background: linear-gradient(135deg, #f0fdf4, #bbf7d0); }

/* Info */
.cart-item-info { flex: 1; min-width: 0; }
.cart-item-category {
    font-size: 0.68rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--primary); margin-bottom: 2px;
}
.cart-item-name {
    font-size: 0.95rem; font-weight: 700; color: var(--dark2);
    margin-bottom: 3px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cart-item-unit-price { font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }
.stock-warning {
    font-size: 0.72rem; color: #f59e0b; font-weight: 600;
    margin-top: 3px; display: flex; align-items: center; gap: 4px;
}

/* ── Qty Control — FIXED ── */
.cart-qty-control {
    display: flex; align-items: center;
    border: 1.5px solid var(--border);
    border-radius: 9px; overflow: hidden; flex-shrink: 0;
}

.cart-qty-btn {
    width: 34px; height: 34px;
    background: #f9fafb;
    border: none; cursor: pointer;
    font-size: 1.1rem; font-weight: 700;
    color: var(--dark2);
    transition: background var(--transition), color var(--transition);
    display: flex; align-items: center; justify-content: center;
    /* REMOVED disabled styling — we handle it via JS */
    user-select: none;
}
.cart-qty-btn:hover:not([data-disabled="true"]) {
    background: var(--primary-light); color: var(--primary);
}
.cart-qty-btn[data-disabled="true"] {
    opacity: 0.3; cursor: not-allowed;
}

.cart-qty-input {
    width: 44px; height: 34px;
    text-align: center;
    border: none;
    border-left: 1px solid var(--border);
    border-right: 1px solid var(--border);
    font-size: 0.9rem; font-weight: 700;
    color: var(--dark2); background: #fff;
    font-family: inherit; outline: none;
}
.cart-qty-input::-webkit-outer-spin-button,
.cart-qty-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.cart-qty-input[type=number] { -moz-appearance: textfield; }

/* Subtotal */
.cart-item-subtotal {
    font-size: 1rem; font-weight: 800; color: var(--primary);
    min-width: 80px; text-align: right;
    letter-spacing: -0.02em; flex-shrink: 0;
}

/* Remove */
.cart-item-remove {
    width: 30px; height: 30px;
    background: none; border: 1.5px solid #fecaca;
    border-radius: 8px; cursor: pointer; color: #ef4444;
    font-size: 0.9rem; display: flex; align-items: center; justify-content: center;
    transition: all var(--transition); flex-shrink: 0;
}
.cart-item-remove:hover { background: #fef2f2; border-color: #ef4444; }

/* ── Empty Cart ── */
.empty-cart { text-align: center; padding: 64px 32px; }
.empty-cart-icon { font-size: 4rem; display: block; margin-bottom: 16px; }
.empty-cart-title { font-size: 1.15rem; font-weight: 800; color: var(--dark2); margin-bottom: 8px; }
.empty-cart-sub   { font-size: 0.88rem; color: var(--text-muted); margin-bottom: 24px; }
.btn-shop-now {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 24px; background: var(--primary); color: #fff;
    border-radius: 10px; font-size: 0.9rem; font-weight: 700;
    transition: all var(--transition);
}
.btn-shop-now:hover { background: var(--primary-dark); transform: translateY(-1px); }

/* ── Summary Card ── */
.summary-card {
    background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow);
    overflow: hidden; position: sticky; top: calc(var(--navbar-h) + 20px);
}
.summary-header {
    padding: 18px 22px; border-bottom: 1px solid var(--border);
    font-size: 1rem; font-weight: 800; color: var(--dark2);
}
.summary-body { padding: 20px 22px; }
.summary-row {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 0.88rem; padding: 8px 0;
    border-bottom: 1px solid #f3f4f6;
}
.summary-row:last-of-type { border-bottom: none; }
.summary-row-label { color: var(--text-muted); font-weight: 500; }
.summary-row-value { font-weight: 600; color: var(--dark2); }
.summary-divider   { height: 1px; background: var(--border); margin: 12px 0; }
.summary-total-row {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
}
.summary-total-label { font-size: 0.95rem; font-weight: 700; color: var(--dark2); }
.summary-total-value { font-size: 1.35rem; font-weight: 900; color: var(--primary); letter-spacing: -0.03em; }

.delivery-note {
    display: flex; align-items: flex-start; gap: 8px;
    background: var(--primary-light); border-radius: 9px;
    padding: 10px 12px; font-size: 0.78rem; color: #7a4f00;
    margin-bottom: 16px; line-height: 1.5;
}
.delivery-note a { color: var(--primary); font-weight: 700; }

.btn-checkout {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 14px;
    background: var(--primary); color: #fff; border: none;
    border-radius: 10px; font-size: 1rem; font-weight: 800;
    cursor: pointer; font-family: inherit; transition: all var(--transition);
}
.btn-checkout:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(230,126,34,0.4); }
.btn-checkout:disabled { background: #d1d5db; cursor: not-allowed; transform: none; box-shadow: none; }

.btn-continue {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    width: 100%; padding: 11px; background: transparent;
    border: 1.5px solid var(--border); border-radius: 10px;
    font-size: 0.88rem; font-weight: 600; cursor: pointer;
    font-family: inherit; color: var(--text-muted); margin-top: 10px;
    transition: all var(--transition);
}
.btn-continue:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-light); }

.trust-row {
    display: flex; justify-content: center; gap: 12px; margin-top: 16px; flex-wrap: wrap;
}
.trust-item { display: flex; align-items: center; gap: 4px; font-size: 0.72rem; color: var(--text-muted); }

/* ── Qty updating state ── */
.cart-qty-control.loading .cart-qty-btn { pointer-events: none; opacity: 0.4; }
.cart-qty-control.loading .cart-qty-input { color: #aaa; }

/* ── Toast ── */
.toast-wrap {
    position: fixed; bottom: 28px; right: 28px;
    z-index: 9999; display: flex; flex-direction: column; gap: 10px; pointer-events: none;
}
.toast {
    background: #1f2937; color: #f9fafb;
    padding: 12px 18px; border-radius: 10px;
    font-size: 0.85rem; font-weight: 500;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    display: flex; align-items: center; gap: 10px;
    animation: toastIn 0.3s ease, toastOut 0.3s ease 2.7s forwards;
    pointer-events: auto; min-width: 220px; max-width: 340px;
}
.toast.toast-success { border-left: 4px solid #10b981; }
.toast.toast-error   { border-left: 4px solid #ef4444; }
@keyframes toastIn  { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }
@keyframes toastOut { from { opacity:1; } to { opacity:0; } }

/* ── Footer ── */
.site-footer {
    background: #1a1a2e; color: #6b7280;
    text-align: center; padding: 24px 32px; font-size: 0.82rem; line-height: 1.7;
}
.site-footer a { color: var(--primary); }
</style>
</head>
<body>
<div class="page-body">

<?php include '../includes/navbar.php'; ?>

<!-- Banner -->
<div class="page-banner">
    <div class="page-banner-inner">
        <div class="breadcrumb">
            <a href="home.php">Home</a>
            <span style="opacity:.4"> › </span>
            <span>My Cart</span>
        </div>
        <div class="page-banner-title">🛒 My Cart</div>
        <div class="page-banner-sub">
            <?= count($cartData) > 0
                ? count($cartData) . ' item type' . (count($cartData) !== 1 ? 's' : '') . ' · ' . $itemCount . ' total units'
                : 'Your cart is empty' ?>
        </div>
    </div>
</div>

<?= flash() ?>

<div class="container">
<div class="cart-layout">

    <!-- LEFT: Cart items -->
    <div>
        <div class="cart-card">
            <div class="cart-card-header">
                <div class="cart-card-title">
                    Cart Items
                    <?php if (count($cartData) > 0): ?>
                        <span class="cart-count-pill"><?= $itemCount ?> units</span>
                    <?php endif; ?>
                </div>
                <?php if (count($cartData) > 0): ?>
                <button class="btn-clear-cart" onclick="clearCart()">🗑 Clear All</button>
                <?php endif; ?>
            </div>

            <?php if (count($cartData) > 0): ?>
                <?php foreach ($cartData as $item):
                    $isEgg    = stripos($item['category'], 'egg') !== false;
                    $emoji    = $isEgg ? '🥚' : '🐔';
                    $thumbCls = $isEgg ? 'cart-thumb-egg' : 'cart-thumb-chick';
                    $stock    = (int)$item['stock'];
                    $qty      = (int)$item['quantity'];
                ?>
                <div class="cart-item" id="cartItem<?= $item['cart_id'] ?>">

                    <div class="cart-item-thumb <?= $thumbCls ?>"><?= $emoji ?></div>

                    <div class="cart-item-info">
                        <div class="cart-item-category"><?= htmlspecialchars($item['category']) ?></div>
                        <div class="cart-item-name"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="cart-item-unit-price">
                            ₱<?= number_format((float)$item['price'], 2) ?> / <?= htmlspecialchars($item['unit']) ?>
                        </div>
                        <?php if ($stock > 0 && $stock <= 10): ?>
                            <div class="stock-warning">⚠️ Only <?= $stock ?> left</div>
                        <?php elseif ($stock <= 0): ?>
                            <div class="stock-warning" style="color:#ef4444;">⛔ Out of stock</div>
                        <?php endif; ?>
                    </div>

                    <!-- Qty control — data attributes carry the state -->
                    <div class="cart-qty-control"
                         id="qtyCtrl<?= $item['cart_id'] ?>"
                         data-cart-id="<?= $item['cart_id'] ?>"
                         data-stock="<?= $stock ?>"
                         data-qty="<?= $qty ?>">

                        <button class="cart-qty-btn"
                                id="btnMinus<?= $item['cart_id'] ?>"
                                data-cart-id="<?= $item['cart_id'] ?>"
                                data-delta="-1"
                                data-disabled="<?= $qty <= 1 ? 'true' : 'false' ?>"
                                onclick="handleQtyBtn(this)">−</button>

                        <input type="number"
                               class="cart-qty-input"
                               id="qtyInput<?= $item['cart_id'] ?>"
                               value="<?= $qty ?>"
                               min="1"
                               max="<?= $stock ?>">

                        <button class="cart-qty-btn"
                                id="btnPlus<?= $item['cart_id'] ?>"
                                data-cart-id="<?= $item['cart_id'] ?>"
                                data-delta="1"
                                data-disabled="<?= $qty >= $stock ? 'true' : 'false' ?>"
                                onclick="handleQtyBtn(this)">+</button>
                    </div>

                    <div class="cart-item-subtotal" id="sub<?= $item['cart_id'] ?>">
                        ₱<?= number_format($item['subtotal'], 2) ?>
                    </div>

                    <button class="cart-item-remove"
                            onclick="removeItem(<?= $item['cart_id'] ?>)" title="Remove">✕</button>
                </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="empty-cart">
                    <span class="empty-cart-icon">🛒</span>
                    <div class="empty-cart-title">Your cart is empty</div>
                    <div class="empty-cart-sub">Add some fresh eggs or chicken to get started!</div>
                    <a href="products.php" class="btn-shop-now">🥚 Browse Products</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: Summary -->
    <div class="summary-card">
        <div class="summary-header">Order Summary</div>
        <div class="summary-body">
            <div class="summary-row">
                <span class="summary-row-label">Subtotal (<span id="unitCountLabel"><?= $itemCount ?></span> units)</span>
                <span class="summary-row-value" id="summarySubtotal">₱<?= number_format($cartTotal, 2) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-row-label">Delivery Fee</span>
                <span class="summary-row-value">₱<?= number_format($deliveryFee, 2) ?></span>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-total-row">
                <span class="summary-total-label">Total</span>
                <span class="summary-total-value" id="summaryTotal">₱<?= number_format($grandTotal, 2) ?></span>
            </div>
            <div class="delivery-note">
                🚚 Delivery within Bohol. For bulk orders, please <a href="contact.php">contact us</a>.
            </div>
            <button class="btn-checkout"
                    id="checkoutBtn"
                    <?= count($cartData) === 0 ? 'disabled' : '' ?>
                    onclick="location.href='checkout.php'">
                Proceed to Checkout →
            </button>
            <button class="btn-continue" onclick="location.href='products.php'">
                ← Continue Shopping
            </button>
            <div class="trust-row">
                <div class="trust-item">🌿 Farm Fresh</div>
                <div class="trust-item">🔒 Secure</div>
                <div class="trust-item">💯 Guaranteed</div>
            </div>
        </div>
    </div>

</div>
</div>

<footer class="site-footer">
    &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business &nbsp;·&nbsp;
    Loreto Cortes, Bohol 🌴 &nbsp;·&nbsp;
    <a href="contact.php">Contact Us</a>
</footer>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const DELIVERY_FEE = <?= $deliveryFee ?>;

// ── Core qty update — sends to server, updates UI ─────────────
function setQty(cartId, newQty) {
    const ctrl     = document.getElementById('qtyCtrl' + cartId);
    const input    = document.getElementById('qtyInput' + cartId);
    const stock    = parseInt(ctrl.dataset.stock);
    const btnMinus = document.getElementById('btnMinus' + cartId);
    const btnPlus  = document.getElementById('btnPlus' + cartId);

    // Clamp
    newQty = Math.max(1, Math.min(stock, parseInt(newQty) || 1));

    // Optimistic UI update
    input.value = newQty;
    ctrl.dataset.qty = newQty;

    // Update button disabled state
    btnMinus.dataset.disabled = newQty <= 1 ? 'true' : 'false';
    btnPlus.dataset.disabled  = newQty >= stock ? 'true' : 'false';

    // Loading state
    ctrl.classList.add('loading');

    fetch('cart_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update&cart_id=' + cartId + '&quantity=' + newQty
    })
    .then(r => r.json())
    .then(data => {
        ctrl.classList.remove('loading');
        if (data.success) {
            // Confirmed qty from server
            const confirmedQty = parseInt(data.quantity);
            input.value = confirmedQty;
            ctrl.dataset.qty = confirmedQty;
            btnMinus.dataset.disabled = confirmedQty <= 1 ? 'true' : 'false';
            btnPlus.dataset.disabled  = confirmedQty >= stock ? 'true' : 'false';

            // Update subtotal for this row
            document.getElementById('sub' + cartId).textContent = '₱' + data.subtotal;

            // Update summary totals
            const rawTotal = parseFloat(data.cart_total.replace(/,/g, ''));
            document.getElementById('summarySubtotal').textContent = '₱' + data.cart_total;
            const grand = rawTotal + DELIVERY_FEE;
            document.getElementById('summaryTotal').textContent =
                '₱' + grand.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            // Update navbar badge
            updateBadge(data.cart_count);
        } else {
            // Revert to last known good qty
            const prevQty = parseInt(ctrl.dataset.qty);
            input.value = prevQty;
            showToast('❌ ' + (data.message || 'Could not update.'), 'error');
        }
    })
    .catch(() => {
        ctrl.classList.remove('loading');
        showToast('❌ Network error. Try again.', 'error');
    });
}

// ── Handle +/− button click ───────────────────────────────────
function handleQtyBtn(btn) {
    if (btn.dataset.disabled === 'true') return; // honour disabled state without HTML disabled attr

    const cartId  = parseInt(btn.dataset.cartId);
    const delta   = parseInt(btn.dataset.delta);
    const ctrl    = document.getElementById('qtyCtrl' + cartId);
    const current = parseInt(ctrl.dataset.qty) || 1;

    setQty(cartId, current + delta);
}

// ── Remove item ───────────────────────────────────────────────
function removeItem(cartId) {
    const row = document.getElementById('cartItem' + cartId);
    if (row) row.classList.add('removing');

    fetch('cart_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove&cart_id=' + cartId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            setTimeout(() => {
                if (row) row.remove();

                const rawTotal = parseFloat(data.cart_total.replace(/,/g, ''));
                document.getElementById('summarySubtotal').textContent = '₱' + data.cart_total;
                const grand = rawTotal + DELIVERY_FEE;
                document.getElementById('summaryTotal').textContent =
                    '₱' + grand.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                updateBadge(data.cart_count);

                if (data.cart_count === 0) location.reload();
            }, 300);
        }
    })
    .catch(() => showToast('❌ Could not remove item.', 'error'));
}

// ── Clear all ─────────────────────────────────────────────────
function clearCart() {
    if (!confirm('Remove all items from your cart?')) return;
    fetch('cart_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear'
    })
    .then(r => r.json())
    .then(data => { if (data.success) location.reload(); })
    .catch(() => showToast('❌ Could not clear cart.', 'error'));
}

// ── Navbar badge ──────────────────────────────────────────────
function updateBadge(count) {
    const badge = document.getElementById('cartBadge');
    if (!badge) return;
    badge.textContent = count;
    badge.style.display = count > 0 ? 'flex' : 'none';
}

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    const wrap = document.getElementById('toastWrap');
    const t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.textContent = msg;
    wrap.appendChild(t);
    setTimeout(() => t.remove(), 3200);
}
</script>
</body>
</html>