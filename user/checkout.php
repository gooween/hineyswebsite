<?php
session_start();
require_once '../config/db.php';
requireCustomer();

$activePage = 'checkout';
$uid        = (int)$_SESSION['user_id'];
$cartItems  = cartCount($conn);

function getSetting(mysqli $conn, string $key, string $default = ''): string
{
    $k = $conn->real_escape_string($key);
    $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key = '{$k}' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) return $row['setting_value'] ?? $default;
    return $default;
}

$gcashNumber   = getSetting($conn, 'gcash_number',   '0917-XXX-XXXX');
$gcashName     = getSetting($conn, 'gcash_name',     "Hiney's Eggs & Live Chicken");
$gcashQrPath   = getSetting($conn, 'gcash_qr_path',  '');
$pickupAddress = getSetting($conn, 'pickup_address', "Hiney's Farm, Loreto Cortes, Bohol");
$deliveryFee   = (float)getSetting($conn, 'delivery_fee', '50.00');

$stmt = $conn->prepare("SELECT full_name, email, phone, address FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cartRows = $conn->query("
    SELECT c.id AS cart_id, c.quantity,
           p.id AS product_id, p.name, p.price, p.unit, p.image_url,
           cat.name AS category,
           COALESCE((
               SELECT CASE WHEN p2.unit='per tray' THEN COUNT(sb.id) ELSE SUM(sb.remaining) END
               FROM stock_batches sb JOIN products p2 ON p2.id=sb.product_id
               WHERE sb.product_id=p.id AND sb.status='active'
           ), 0) AS stock
    FROM cart c
    JOIN products p ON p.id = c.product_id
    JOIN categories cat ON cat.id = p.category_id
    WHERE c.user_id = {$uid}
    ORDER BY c.added_at ASC
");

$cartData  = [];
$cartTotal = 0.0;
while ($row = $cartRows->fetch_assoc()) {
    $row['subtotal'] = (float)$row['price'] * (int)$row['quantity'];
    $cartTotal      += $row['subtotal'];
    $cartData[]      = $row;
}

if (count($cartData) === 0) {
    redirect('cart.php', 'warning', 'Your cart is empty. Add some products first!');
}

// ── Handle POST ───────────────────────────────────────────────
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deliveryType    = trim($_POST['delivery_type']    ?? 'delivery');
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $paymentMethod   = trim($_POST['payment_method']   ?? '');
    $notes           = trim($_POST['notes']            ?? '');

    $actualDeliveryFee = $deliveryType === 'pickup' ? 0.00 : $deliveryFee;
    $grandTotal        = $cartTotal + $actualDeliveryFee;

    if ($deliveryType === 'delivery' && !$deliveryAddress) {
        $errors[] = 'Delivery address is required.';
    }
    if (!in_array($paymentMethod, ['cod', 'gcash'])) {
        $errors[] = 'Please select a payment method.';
    }

    // Stock check
    foreach ($cartData as $item) {
        if ((int)$item['quantity'] > (int)$item['stock']) {
            $errors[] = htmlspecialchars($item['name']) . ' only has ' . $item['stock'] . ' units available.';
        }
    }

    // Per piece minimum check
    foreach ($cartData as $item) {
        if (strtolower($item['unit']) === 'per piece' && (int)$item['quantity'] < 12) {
            $errors[] = htmlspecialchars($item['name']) . ' requires a minimum of 12 pieces (1 dozen). Currently: ' . $item['quantity'] . '.';
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $finalAddress = $deliveryType === 'pickup'
                ? 'PICKUP — ' . $pickupAddress
                : $deliveryAddress;

            $pmFull = $paymentMethod === 'gcash' ? 'gcash' : 'cod';

            $stmt = $conn->prepare("
                INSERT INTO orders (user_id, status, total_amount, delivery_fee, payment_method, payment_status, delivery_address, notes, created_at, updated_at)
                VALUES (?, 'pending', ?, ?, ?, 'unpaid', ?, ?, NOW(), NOW())
            ");
            $stmt->bind_param('iddsss', $uid, $grandTotal, $actualDeliveryFee, $pmFull, $finalAddress, $notes);
            $stmt->execute();
            $orderId = (int)$conn->insert_id;
            $stmt->close();

            foreach ($cartData as $item) {
                $pid      = (int)$item['product_id'];
                $qty      = (int)$item['quantity'];
                $price    = (float)$item['price'];
                $subtotal = $price * $qty;

                $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('iiidd', $orderId, $pid, $qty, $price, $subtotal);
                $stmt->execute();
                $stmt->close();

                $conn->query("UPDATE inventory SET quantity = quantity - {$qty} WHERE product_id = {$pid} AND quantity >= {$qty}");

                $reason = "Order #{$orderId} — {$item['name']} x{$qty}";
                $stmt = $conn->prepare("INSERT INTO inventory_logs (product_id, type, quantity, reason, created_by, created_at) VALUES (?, 'out', ?, ?, ?, NOW())");
                $stmt->bind_param('iisi', $pid, $qty, $reason, $uid);
                $stmt->execute();
                $stmt->close();
            }

            $conn->query("DELETE FROM cart WHERE user_id = {$uid}");
            $conn->commit();

            $orderNum = str_pad($orderId, 4, '0', STR_PAD_LEFT);
            if ($paymentMethod === 'gcash') {
                redirect('orders.php', 'success', "Order #{$orderNum} placed! Once admin approves, you'll be asked to upload your GCash payment proof.");
            } else {
                redirect('orders.php', 'success', "Order #{$orderNum} placed successfully! We'll confirm it shortly.");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Something went wrong. Please try again. (' . $e->getMessage() . ')';
        }
    }
}

$selectedDeliveryType = $_POST['delivery_type'] ?? 'delivery';
$displayDeliveryFee   = $selectedDeliveryType === 'pickup' ? 0.00 : $deliveryFee;
$grandTotal           = $cartTotal + $displayDeliveryFee;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style id="hineys-icon-colors">
        .navbar .fa-solid,
        .mobile-drawer .fa-solid,
        .sidebar .fa-solid,
        button .fa-solid,
        [class*="btn"] .fa-solid,
        .badge .fa-solid,
        .status-badge .fa-solid,
        .status-tab .fa-solid,
        .pay-badge .fa-solid,
        .page-banner .fa-solid,
        .page-header .fa-solid,
        .hero .fa-solid,
        .cta-card .fa-solid,
        .about-strip .fa-solid,
        .nav-cart .fa-solid,
        .user-chip .fa-solid,
        .info-card-top .fa-solid,
        .sidebar-logout .fa-solid {
            color: inherit !important
        }

        .fa-egg {
            color: #f4a72c
        }

        .fa-drumstick-bite {
            color: #c2703b
        }

        .fa-circle-check,
        .fa-check,
        .fa-shield-halved,
        .fa-leaf,
        .fa-seedling,
        .fa-phone {
            color: #10b981
        }

        .fa-circle-xmark,
        .fa-xmark,
        .fa-trash,
        .fa-ban,
        .fa-location-dot {
            color: #ef4444
        }

        .fa-cart-shopping,
        .fa-bag-shopping,
        .fa-store,
        .fa-shop {
            color: #e67e22
        }

        .fa-truck {
            color: #f97316
        }

        .fa-triangle-exclamation,
        .fa-circle-exclamation,
        .fa-clock,
        .fa-star {
            color: #f59e0b
        }

        .fa-info-circle,
        .fa-credit-card,
        .fa-mobile-screen,
        .fa-envelope,
        .fa-envelope-open,
        .fa-envelope-open-text,
        .fa-inbox,
        .fa-comment,
        .fa-map,
        .fa-paperclip {
            color: #3b82f6
        }

        .fa-sack-dollar,
        .fa-money-bill,
        .fa-money-bill-transfer {
            color: #16a34a
        }

        .fa-users,
        .fa-user,
        .fa-user-plus {
            color: #6366f1
        }

        .fa-box,
        .fa-box-open,
        .fa-boxes-stacked,
        .fa-warehouse,
        .fa-receipt,
        .fa-clipboard-list,
        .fa-file-lines {
            color: #8b5cf6
        }

        .fa-chart-bar,
        .fa-chart-line,
        .fa-chart-pie,
        .fa-gauge-high {
            color: #0ea5e9
        }

        .fa-heart {
            color: #ef4444
        }

        .fa-gear {
            color: #6b7280
        }

        .fa-lightbulb {
            color: #f59e0b
        }
    </style>
    <title>Checkout — Hiney's Eggs &amp; Live Chicken</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        :root {
            --primary: #e67e22;
            --primary-dark: #cf6d17;
            --primary-light: #fef3e8;
            --secondary: #f39c12;
            --dark: #1a1a2e;
            --dark2: #2c3e50;
            --text: #374151;
            --text-muted: #6b7280;
            --bg: #faf9f7;
            --card-bg: #ffffff;
            --border: #e5e7eb;
            --danger: #ef4444;
            --success: #10b981;
            --radius: 14px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 8px 24px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.10), 0 24px 48px rgba(0, 0, 0, 0.08);
            --navbar-h: 68px;
            --transition: 0.2s ease
        }

        html {
            scroll-behavior: smooth
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6
        }

        a {
            text-decoration: none;
            color: inherit
        }

        .page-body {
            padding-top: var(--navbar-h)
        }

        .page-banner {
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            padding: 36px 0 40px;
            position: relative;
            overflow: hidden
        }

        .page-banner::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 500px 300px at 80% 50%, rgba(230, 126, 34, 0.14), transparent 70%)
        }

        .page-banner-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 32px;
            position: relative;
            z-index: 1
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: #6b7a99;
            margin-bottom: 12px
        }

        .breadcrumb a {
            color: #8fa3b3
        }

        .breadcrumb a:hover {
            color: var(--secondary)
        }

        .breadcrumb-sep {
            opacity: 0.4
        }

        .page-banner-title {
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.025em;
            margin-bottom: 4px
        }

        .page-banner-sub {
            font-size: 0.9rem;
            color: #8fa3b3
        }

        .checkout-steps {
            display: flex;
            align-items: center;
            gap: 0;
            margin-top: 20px
        }

        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78rem;
            font-weight: 600
        }

        .step-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 800
        }

        .step.done .step-num {
            background: var(--success);
            color: #fff
        }

        .step.active .step-num {
            background: var(--primary);
            color: #fff
        }

        .step.pending .step-num {
            background: rgba(255, 255, 255, 0.15);
            color: #8fa3b3
        }

        .step.done .step-label {
            color: #6ee7b7
        }

        .step.active .step-label {
            color: #fff;
            font-weight: 700
        }

        .step.pending .step-label {
            color: #6b7a99
        }

        .step-line {
            height: 2px;
            width: 32px;
            background: rgba(255, 255, 255, 0.1);
            margin: 0 4px
        }

        .step-line.done {
            background: var(--success)
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 32px
        }

        @media(max-width:600px) {
            .container {
                padding: 0 16px
            }
        }

        .checkout-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 28px;
            padding: 32px 0 60px;
            align-items: start
        }

        @media(max-width:960px) {
            .checkout-layout {
                grid-template-columns: 1fr
            }
        }

        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            overflow: hidden
        }

        .form-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            background: #fafafa
        }

        .form-card-num {
            width: 26px;
            height: 26px;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
            flex-shrink: 0
        }

        .form-card-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--dark2)
        }

        .form-card-body {
            padding: 22px
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px
        }

        .form-row.single {
            grid-template-columns: 1fr
        }

        @media(max-width:600px) {
            .form-row {
                grid-template-columns: 1fr
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px
        }

        .form-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--dark2)
        }

        .form-label .req {
            color: var(--danger);
            margin-left: 2px
        }

        .form-input,
        .form-textarea {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 0.88rem;
            font-family: inherit;
            color: var(--text);
            background: #fafafa;
            outline: none;
            transition: border-color var(--transition), box-shadow var(--transition), background var(--transition)
        }

        .form-input:focus,
        .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.1);
            background: #fff
        }

        .form-input[readonly] {
            background: #f3f4f6;
            color: var(--text-muted);
            cursor: not-allowed
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px
        }

        .form-hint {
            font-size: 0.72rem;
            color: var(--text-muted)
        }

        .delivery-toggle {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px
        }

        .delivery-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            cursor: pointer;
            transition: all var(--transition)
        }

        .delivery-option:hover {
            border-color: rgba(230, 126, 34, 0.4);
            background: var(--primary-light)
        }

        .delivery-option.selected {
            border-color: var(--primary);
            background: var(--primary-light)
        }

        .delivery-option input[type="radio"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            flex-shrink: 0
        }

        .delivery-option-icon {
            font-size: 1.4rem;
            flex-shrink: 0
        }

        .delivery-option-name {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--dark2)
        }

        .delivery-option-desc {
            font-size: 0.72rem;
            color: var(--text-muted)
        }

        .payment-options {
            display: flex;
            flex-direction: column;
            gap: 12px
        }

        .payment-option {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 18px;
            border: 2px solid var(--border);
            border-radius: 12px;
            cursor: pointer;
            transition: all var(--transition)
        }

        .payment-option:hover {
            border-color: rgba(230, 126, 34, 0.4);
            background: var(--primary-light)
        }

        .payment-option.selected {
            border-color: var(--primary);
            background: var(--primary-light)
        }

        .payment-option input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            margin-top: 2px;
            flex-shrink: 0
        }

        .payment-option-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
            line-height: 1
        }

        .payment-option-name {
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--dark2);
            margin-bottom: 2px
        }

        .payment-option-desc {
            font-size: 0.78rem;
            color: var(--text-muted);
            line-height: 1.5
        }

        .gcash-info {
            display: none;
            margin-top: 16px;
            padding: 16px;
            background: #eff6ff;
            border: 1.5px solid #bfdbfe;
            border-radius: 10px
        }

        .gcash-info.show {
            display: block
        }

        .gcash-info-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px
        }

        .gcash-info-note {
            font-size: 0.8rem;
            color: #3b82f6;
            line-height: 1.6;
            margin-bottom: 12px
        }

        .gcash-steps {
            display: flex;
            flex-direction: column;
            gap: 8px
        }

        .gcash-step {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 0.82rem;
            color: #1e40af
        }

        .gcash-step-num {
            width: 22px;
            height: 22px;
            background: #3b82f6;
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.68rem;
            font-weight: 800;
            flex-shrink: 0;
            margin-top: 1px
        }

        .pickup-info {
            display: none;
            margin-top: 12px;
            padding: 14px;
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 10px
        }

        .pickup-info.show {
            display: block
        }

        .pickup-info-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: #065f46;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px
        }

        .pickup-info-address {
            font-size: 0.85rem;
            color: #065f46;
            line-height: 1.6;
            background: #fff;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 10px 14px
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-left: 4px solid var(--danger);
            border-radius: 9px;
            padding: 14px 16px;
            margin-bottom: 20px;
            font-size: 0.87rem;
            color: #991b1b
        }

        .alert-error ul {
            padding-left: 18px;
            margin-top: 6px
        }

        .alert-error li {
            margin-bottom: 4px
        }

        .summary-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: sticky;
            top: calc(var(--navbar-h) + 20px)
        }

        .summary-header {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--dark2);
            display: flex;
            align-items: center;
            gap: 8px
        }

        .summary-items {
            max-height: 260px;
            overflow-y: auto;
            border-bottom: 1px solid var(--border);
            scrollbar-width: thin
        }

        .summary-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid #f9fafb;
            font-size: 0.84rem
        }

        .summary-item:last-child {
            border-bottom: none
        }

        .summary-item-thumb {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
            overflow: hidden
        }

        .si-egg {
            background: linear-gradient(135deg, #fef9ee, #fdeec8)
        }

        .si-chick {
            background: linear-gradient(135deg, #f0fdf4, #bbf7d0)
        }

        .summary-item-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover
        }

        .summary-item-name {
            flex: 1;
            font-weight: 600;
            color: var(--dark2);
            line-height: 1.3
        }

        .summary-item-qty {
            font-size: 0.75rem;
            color: var(--text-muted)
        }

        .summary-item-price {
            font-weight: 700;
            color: var(--primary);
            white-space: nowrap
        }

        .summary-totals {
            padding: 16px 18px
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            padding: 6px 0
        }

        .total-row-label {
            color: var(--text-muted)
        }

        .total-row-value {
            font-weight: 600;
            color: var(--dark2)
        }

        .total-divider {
            height: 1px;
            background: var(--border);
            margin: 10px 0
        }

        .grand-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0 4px
        }

        .grand-total-label {
            font-size: 1rem;
            font-weight: 800;
            color: var(--dark2)
        }

        .grand-total-value {
            font-size: 1.4rem;
            font-weight: 900;
            color: var(--primary);
            letter-spacing: -0.03em
        }

        .summary-footer {
            padding: 16px 18px 20px;
            border-top: 1px solid var(--border)
        }

        .btn-place-order {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
            transition: all var(--transition)
        }

        .btn-place-order:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(230, 126, 34, 0.4)
        }

        .btn-place-order:disabled {
            background: #d1d5db;
            cursor: not-allowed;
            transform: none;
            box-shadow: none
        }

        .btn-back-cart {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            padding: 10px;
            background: transparent;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            color: var(--text-muted);
            margin-top: 10px;
            transition: all var(--transition)
        }

        .btn-back-cart:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light)
        }

        .trust-row {
            display: flex;
            justify-content: center;
            gap: 14px;
            margin-top: 14px;
            flex-wrap: wrap
        }

        .trust-item {
            font-size: 0.7rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px
        }

        .free-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #d1fae5;
            color: #065f46;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px
        }

        .site-footer {
            background: #1a1a2e;
            color: #6b7280;
            text-align: center;
            padding: 24px 32px;
            font-size: 0.82rem;
            line-height: 1.7
        }

        .site-footer a {
            color: var(--primary)
        }
    </style>
</head>

<body>
    <div class="page-body">

        <?php include '../includes/navbar.php'; ?>

        <div class="page-banner">
            <div class="page-banner-inner">
                <div class="breadcrumb">
                    <a href="home.php">Home</a><span class="breadcrumb-sep">›</span>
                    <a href="cart.php">Cart</a><span class="breadcrumb-sep">›</span>
                    <span>Checkout</span>
                </div>
                <div class="page-banner-title">Checkout</div>
                <div class="page-banner-sub">Complete your order below</div>
                <div class="checkout-steps">
                    <div class="step done">
                        <div class="step-num">✓</div><span class="step-label">Cart</span>
                    </div>
                    <div class="step-line done"></div>
                    <div class="step active">
                        <div class="step-num">2</div><span class="step-label">Checkout</span>
                    </div>
                    <div class="step-line"></div>
                    <div class="step pending">
                        <div class="step-num">3</div><span class="step-label">Confirmation</span>
                    </div>
                </div>
            </div>
        </div>

        <?= flash() ?>

        <div class="container">
            <div class="checkout-layout">

                <!-- LEFT -->
                <div>
                    <?php if (!empty($errors)): ?>
                        <div class="alert-error">
                            <strong>Please fix the following errors:</strong>
                            <ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="checkout.php" id="checkoutForm">

                        <!-- 1. Contact Info -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <div class="form-card-num">1</div>
                                <div class="form-card-title">Contact Information</div>
                            </div>
                            <div class="form-card-body">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" class="form-input" readonly value="<?= htmlspecialchars($user['full_name'] ?? '') ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-input" readonly value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="form-row single">
                                    <div class="form-group">
                                        <label class="form-label">Phone Number</label>
                                        <input type="text" class="form-input" readonly value="<?= htmlspecialchars($user['phone'] ?? 'Not set') ?>">
                                    </div>
                                </div>
                                <div class="form-hint">To update contact info, go to <a href="profile.php" style="color:var(--primary);font-weight:600;">My Profile</a>.</div>
                            </div>
                        </div>

                        <!-- 2. Delivery -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <div class="form-card-num">2</div>
                                <div class="form-card-title">Delivery Method</div>
                            </div>
                            <div class="form-card-body">
                                <div class="delivery-toggle">
                                    <label class="delivery-option <?= $selectedDeliveryType !== 'pickup' ? 'selected' : '' ?>" onclick="selectDelivery('delivery')">
                                        <input type="radio" name="delivery_type" value="delivery" <?= $selectedDeliveryType !== 'pickup' ? 'checked' : '' ?>>
                                        <div class="delivery-option-icon"><i class="fa-solid fa-truck"></i></div>
                                        <div>
                                            <div class="delivery-option-name">Delivery</div>
                                            <div class="delivery-option-desc">We deliver to your door<br>+₱<?= number_format($deliveryFee, 2) ?> fee</div>
                                        </div>
                                    </label>
                                    <label class="delivery-option <?= $selectedDeliveryType === 'pickup' ? 'selected' : '' ?>" onclick="selectDelivery('pickup')">
                                        <input type="radio" name="delivery_type" value="pickup" <?= $selectedDeliveryType === 'pickup' ? 'checked' : '' ?>>
                                        <div class="delivery-option-icon"><i class="fa-solid fa-shop"></i></div>
                                        <div>
                                            <div class="delivery-option-name">Pick Up</div>
                                            <div class="delivery-option-desc">Pick up at the farm<br><span style="color:#10b981;font-weight:700;">FREE — no delivery fee</span></div>
                                        </div>
                                    </label>
                                </div>
                                <div id="deliveryAddressWrap">
                                    <div class="form-group">
                                        <label class="form-label">Delivery Address <span class="req">*</span></label>
                                        <textarea name="delivery_address" id="deliveryAddress" class="form-textarea" placeholder="House/Unit No., Street, Barangay, Loreto Cortes, Bohol"><?= htmlspecialchars($_POST['delivery_address'] ?? $user['address'] ?? '') ?></textarea>
                                        <span class="form-hint">Enter your full delivery address within Loreto Cortes, Bohol.</span>
                                    </div>
                                </div>
                                <div class="pickup-info <?= $selectedDeliveryType === 'pickup' ? 'show' : '' ?>" id="pickupInfo">
                                    <div class="pickup-info-title"><i class="fa-solid fa-location-dot"></i> Pickup Location</div>
                                    <div class="pickup-info-address"><?= htmlspecialchars($pickupAddress) ?></div>
                                    <div style="font-size:0.75rem;color:#065f46;margin-top:8px;"><i class="fa-solid fa-circle-check"></i> No delivery fee. Please coordinate pickup schedule via our contact page.</div>
                                </div>
                                <div class="form-group" style="margin-top:14px;">
                                    <label class="form-label">Order Notes (optional)</label>
                                    <textarea name="notes" class="form-textarea" placeholder="Special instructions, preferred pickup time…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Payment -->
                        <div class="form-card">
                            <div class="form-card-header">
                                <div class="form-card-num">3</div>
                                <div class="form-card-title">Payment Method</div>
                            </div>
                            <div class="form-card-body">
                                <div class="payment-options">
                                    <label class="payment-option <?= ($_POST['payment_method'] ?? 'cod') === 'cod' ? 'selected' : '' ?>">
                                        <input type="radio" name="payment_method" value="cod" <?= ($_POST['payment_method'] ?? 'cod') === 'cod' ? 'checked' : '' ?> onchange="selectPayment('cod')">
                                        <div class="payment-option-icon"><i class="fa-solid fa-money-bill"></i></div>
                                        <div>
                                            <div class="payment-option-name">Cash on Delivery / Pickup (COD)</div>
                                            <div class="payment-option-desc">Pay with cash when your order arrives or when you pick it up.</div>
                                        </div>
                                    </label>
                                    <label class="payment-option <?= ($_POST['payment_method'] ?? '') === 'gcash' ? 'selected' : '' ?>">
                                        <input type="radio" name="payment_method" value="gcash" <?= ($_POST['payment_method'] ?? '') === 'gcash' ? 'checked' : '' ?> onchange="selectPayment('gcash')">
                                        <div class="payment-option-icon"><i class="fa-solid fa-mobile-screen"></i></div>
                                        <div>
                                            <div class="payment-option-name">GCash</div>
                                            <div class="payment-option-desc">Place order now — upload payment proof after admin approval.</div>
                                        </div>
                                    </label>
                                </div>
                                <div class="gcash-info <?= ($_POST['payment_method'] ?? '') === 'gcash' ? 'show' : '' ?>" id="gcashInfo">
                                    <div class="gcash-info-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10" />
                                            <line x1="12" y1="8" x2="12" y2="12" />
                                            <line x1="12" y1="16" x2="12.01" y2="16" />
                                        </svg> How GCash Payment Works</div>
                                    <div class="gcash-info-note">No need to pay yet! Here's the process:</div>
                                    <div class="gcash-steps">
                                        <div class="gcash-step">
                                            <div class="gcash-step-num">1</div><span>Place your order — submitted as pending.</span>
                                        </div>
                                        <div class="gcash-step">
                                            <div class="gcash-step-num">2</div><span>Admin reviews and <strong>approves</strong> your order.</span>
                                        </div>
                                        <div class="gcash-step">
                                            <div class="gcash-step-num">3</div><span>Send payment to <strong><?= htmlspecialchars($gcashNumber) ?></strong> (<?= htmlspecialchars($gcashName) ?>) and upload screenshot in My Orders.</span>
                                        </div>
                                        <div class="gcash-step">
                                            <div class="gcash-step-num">4</div><span>Admin verifies and marks as paid. <i class="fa-solid fa-champagne-glasses"></i></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" id="hiddenSubmit" style="display:none;"></button>
                    </form>
                </div>

                <!-- RIGHT — Summary -->
                <div class="summary-card">
                    <div class="summary-header"><i class="fa-solid fa-cart-shopping"></i> Order Summary</div>
                    <div class="summary-items">
                        <?php foreach ($cartData as $item):
                            $isEgg = stripos($item['category'], 'egg') !== false;
                            $emoji = $isEgg ? '<i class="fa-solid fa-egg"></i>' : '<i class="fa-solid fa-drumstick-bite"></i>';
                            $siCls = $isEgg ? 'si-egg' : 'si-chick';
                        ?>
                            <div class="summary-item">
                                <div class="summary-item-thumb <?= $siCls ?>">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                                    <?php else: ?>
                                        <?= $emoji ?>
                                    <?php endif; ?>
                                </div>
                                <div class="summary-item-name">
                                    <?= htmlspecialchars($item['name']) ?>
                                    <div class="summary-item-qty">x<?= $item['quantity'] ?> · <?= htmlspecialchars($item['unit']) ?></div>
                                </div>
                                <div class="summary-item-price">₱<?= number_format($item['subtotal'], 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="summary-totals">
                        <div class="total-row">
                            <span class="total-row-label">Subtotal</span>
                            <span class="total-row-value">₱<?= number_format($cartTotal, 2) ?></span>
                        </div>
                        <div class="total-row">
                            <span class="total-row-label">Delivery Fee</span>
                            <span class="total-row-value" id="summaryDeliveryFee">
                                <?= $selectedDeliveryType === 'pickup' ? '<span class="free-badge">✓ FREE</span>' : '₱' . number_format($deliveryFee, 2) ?>
                            </span>
                        </div>
                        <div class="total-divider"></div>
                        <div class="grand-total-row">
                            <span class="grand-total-label">Total</span>
                            <span class="grand-total-value" id="summaryGrandTotal">₱<?= number_format($grandTotal, 2) ?></span>
                        </div>
                    </div>

                    <div class="summary-footer">
                        <button class="btn-place-order" id="btnPlaceOrder" onclick="submitOrder()">
                            ✓ Place Order — <span id="btnTotal">₱<?= number_format($grandTotal, 2) ?></span>
                        </button>
                        <button class="btn-back-cart" onclick="location.href='cart.php'">← Back to Cart</button>
                        <div class="trust-row">
                            <div class="trust-item"><i class="fa-solid fa-lock"></i> Secure</div>
                            <div class="trust-item"><i class="fa-solid fa-leaf"></i> Farm Fresh</div>
                            <div class="trust-item"><i class="fa-solid fa-shield-halved"></i> Guaranteed</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <footer class="site-footer">
            &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business &nbsp;·&nbsp;
            Loreto Cortes, Bohol &nbsp;·&nbsp;
            <a href="contact.php">Contact Us</a>
        </footer>
    </div>

    <script>
        const CART_TOTAL = <?= $cartTotal ?>;
        const DELIVERY_FEE = <?= $deliveryFee ?>;

        function selectDelivery(type) {
            document.querySelectorAll('.delivery-option').forEach(el => el.classList.remove('selected'));
            const radio = document.querySelector('input[name="delivery_type"][value="' + type + '"]');
            if (radio) {
                radio.checked = true;
                radio.closest('.delivery-option').classList.add('selected');
            }
            const addrWrap = document.getElementById('deliveryAddressWrap');
            const pickupDiv = document.getElementById('pickupInfo');
            const addrTA = document.getElementById('deliveryAddress');
            if (type === 'pickup') {
                addrWrap.style.display = 'none';
                pickupDiv.classList.add('show');
                addrTA.removeAttribute('required');
            } else {
                addrWrap.style.display = 'block';
                pickupDiv.classList.remove('show');
                addrTA.setAttribute('required', 'required');
            }
            updateTotals(type);
        }

        function updateTotals(type) {
            const fee = type === 'pickup' ? 0 : DELIVERY_FEE;
            const grand = CART_TOTAL + fee;
            const fmt = v => '₱' + v.toLocaleString('en-PH', {
                minimumFractionDigits: 2
            });
            document.getElementById('summaryDeliveryFee').innerHTML = type === 'pickup' ? '<span class="free-badge">✓ FREE</span>' : fmt(DELIVERY_FEE);
            document.getElementById('summaryGrandTotal').textContent = fmt(grand);
            document.getElementById('btnTotal').textContent = fmt(grand);
        }

        function selectPayment(method) {
            document.querySelectorAll('.payment-option').forEach(el => el.classList.remove('selected'));
            const radio = document.querySelector('input[name="payment_method"][value="' + method + '"]');
            if (radio) {
                radio.checked = true;
                radio.closest('.payment-option').classList.add('selected');
            }
            document.getElementById('gcashInfo').classList.toggle('show', method === 'gcash');
        }

        function submitOrder() {
            const btn = document.getElementById('btnPlaceOrder');
            btn.disabled = true;
            btn.textContent = 'Placing order…';
            document.getElementById('checkoutForm').submit();
        }

        document.addEventListener('DOMContentLoaded', function() {
            selectDelivery(document.querySelector('input[name="delivery_type"]:checked')?.value || 'delivery');
            selectPayment(document.querySelector('input[name="payment_method"]:checked')?.value || 'cod');
            document.querySelectorAll('.delivery-option').forEach(label => {
                label.addEventListener('click', function() {
                    const r = this.querySelector('input[type="radio"]');
                    if (r) selectDelivery(r.value);
                });
            });
            document.querySelectorAll('.payment-option').forEach(label => {
                label.addEventListener('click', function() {
                    const r = this.querySelector('input[type="radio"]');
                    if (r) selectPayment(r.value);
                });
            });
        });
    </script>
</body>

</html>