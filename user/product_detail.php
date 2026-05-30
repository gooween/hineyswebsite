<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/product_detail.php
// Purpose: Single product detail page — PUBLIC (login only for cart)
// ============================================================

session_start();
require_once '../config/db.php';
// Public page — login not required to browse
$isLoggedIn = !empty($_SESSION['user_id']);

$activePage = 'products';
$cartItems  = $isLoggedIn ? cartCount($conn) : 0;

// ── Get product ID ────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: products.php');
    exit;
}

// ── Fetch product ─────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.description, p.price, p.unit,
           p.is_active, p.created_at,
           c.id AS cat_id, c.name AS category,
           c.description AS cat_desc,
           COALESCE(i.quantity, 0) AS stock,
           i.reorder_level
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory i ON i.product_id = p.id
    WHERE p.id = ? AND p.is_active = 1
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$p) {
    header('Location: products.php');
    exit;
}

// ── Related products (same category, exclude current) ─────────
$related = $conn->query("
    SELECT p.id, p.name, p.price, p.unit,
           c.name AS category,
           COALESCE(i.quantity, 0) AS stock
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory i ON i.product_id = p.id
    WHERE p.category_id = {$p['cat_id']}
      AND p.id != {$p['id']}
      AND p.is_active = 1
    ORDER BY p.price ASC
    LIMIT 4
");

// ── Stock status vars ─────────────────────────────────────────
$stock   = (int)$p['stock'];
$reorder = (int)($p['reorder_level'] ?? 10);
if ($stock <= 0) {
    $stockCls = 'out';
    $stockLbl = 'Out of Stock';
    $stockColor = '#ef4444';
} elseif ($stock <= $reorder) {
    $stockCls = 'low';
    $stockLbl = 'Low Stock';
    $stockColor = '#f59e0b';
} else {
    $stockCls = 'ok';
    $stockLbl = 'In Stock';
    $stockColor = '#10b981';
}

$isEgg    = stripos($p['category'], 'egg') !== false;
$emoji    = $isEgg ? '<i class="fa-solid fa-egg"></i>' : '<i class="fa-solid fa-drumstick-bite"></i>';
$thumbCls = $isEgg ? 'thumb-egg' : 'thumb-chick';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style id="hineys-icon-colors">
        /* === Hiney's icon colors === */
        /* Icons inside dark/colored or interactive areas keep their inherited color */
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
            color: inherit !important;
        }

        /* Semantic colors for standalone content icons */
        .fa-egg {
            color: #f4a72c;
        }

        .fa-drumstick-bite {
            color: #c2703b;
        }

        .fa-circle-check,
        .fa-check,
        .fa-shield-halved,
        .fa-leaf,
        .fa-seedling,
        .fa-phone {
            color: #10b981;
        }

        .fa-circle-xmark,
        .fa-xmark,
        .fa-trash,
        .fa-ban,
        .fa-location-dot {
            color: #ef4444;
        }

        .fa-cart-shopping,
        .fa-bag-shopping,
        .fa-store,
        .fa-shop {
            color: #e67e22;
        }

        .fa-truck {
            color: #f97316;
        }

        .fa-triangle-exclamation,
        .fa-circle-exclamation,
        .fa-clock,
        .fa-star {
            color: #f59e0b;
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
            color: #3b82f6;
        }

        .fa-sack-dollar,
        .fa-money-bill,
        .fa-money-bill-transfer {
            color: #16a34a;
        }

        .fa-users,
        .fa-user,
        .fa-user-plus {
            color: #6366f1;
        }

        .fa-box,
        .fa-box-open,
        .fa-boxes-stacked,
        .fa-warehouse,
        .fa-receipt,
        .fa-clipboard-list,
        .fa-file-lines {
            color: #8b5cf6;
        }

        .fa-chart-bar,
        .fa-chart-line,
        .fa-chart-pie,
        .fa-gauge-high {
            color: #0ea5e9;
        }

        .fa-heart {
            color: #ef4444;
        }

        .fa-gear {
            color: #6b7280;
        }

        .fa-lightbulb {
            color: #f59e0b;
        }
    </style>
    <title><?= htmlspecialchars($p['name']) ?> — Hiney's</title>
    <style>
        /* ══════════════════════════════════════════════
   RESET & ROOT
══════════════════════════════════════════════ */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
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
            --radius: 14px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 8px 24px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.10), 0 24px 48px rgba(0, 0, 0, 0.08);
            --navbar-h: 68px;
            --transition: 0.2s ease;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        a {
            text-decoration: none;
            color: inherit;
        }


        /* ══════════════════════════════════════════════
   BREADCRUMB STRIP
══════════════════════════════════════════════ */
        .breadcrumb-strip {
            background: var(--card-bg);
            border-bottom: 1px solid var(--border);
            padding: 12px 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 32px;
        }

        @media(max-width:600px) {
            .container {
                padding: 0 16px;
            }
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .breadcrumb a {
            color: var(--text-muted);
            transition: color var(--transition);
        }

        .breadcrumb a:hover {
            color: var(--primary);
        }

        .breadcrumb-sep {
            opacity: 0.4;
        }

        .breadcrumb-current {
            color: var(--dark2);
            font-weight: 600;
        }

        /* ══════════════════════════════════════════════
   PRODUCT DETAIL SECTION
══════════════════════════════════════════════ */
        .detail-section {
            padding: 40px 0 60px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            align-items: start;
        }

        @media(max-width:860px) {
            .detail-grid {
                grid-template-columns: 1fr;
                gap: 28px;
            }
        }

        /* ── Product Image Panel ── */
        .product-image-panel {
            position: sticky;
            top: calc(var(--navbar-h) + 20px);
        }

        .product-main-img {
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 8rem;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            margin-bottom: 16px;
            border: 1px solid var(--border);
        }

        .thumb-egg {
            background: linear-gradient(145deg, #fef9ee 0%, #fef0c7 50%, #fde8a4 100%);
        }

        .thumb-chick {
            background: linear-gradient(145deg, #f0fdf4 0%, #dcfce7 50%, #bbf7d0 100%);
        }

        .img-bg-blur {
            position: absolute;
            inset: 0;
            font-size: 18rem;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0.07;
            filter: blur(8px);
        }

        .img-main-emoji {
            position: relative;
            z-index: 1;
            filter: drop-shadow(0 16px 32px rgba(0, 0, 0, 0.15));
            animation: imgFloat 4s ease-in-out infinite;
        }

        @keyframes imgFloat {

            0%,
            100% {
                transform: translateY(0) rotate(-3deg);
            }

            50% {
                transform: translateY(-14px) rotate(3deg);
            }
        }

        /* Stock pill on image */
        .img-stock-pill {
            position: absolute;
            bottom: 16px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(8px);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 0.8rem;
            font-weight: 700;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
            white-space: nowrap;
        }

        .stock-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: <?= $stockColor ?>;
        }

        /* Thumbnail strip (decorative size variants) */
        .thumb-strip {
            display: flex;
            gap: 8px;
        }

        .thumb-mini {
            flex: 1;
            aspect-ratio: 1;
            border-radius: 10px;
            border: 2px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            cursor: pointer;
            transition: all var(--transition);
            background: var(--card-bg);
        }

        .thumb-mini:hover,
        .thumb-mini.active {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        /* ── Product Info Panel ── */
        .product-info-panel {}

        .product-category-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 14px;
        }

        .product-title {
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: 900;
            color: var(--dark2);
            letter-spacing: -0.03em;
            line-height: 1.2;
            margin-bottom: 16px;
        }

        /* Price block */
        .price-block {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            margin-bottom: 20px;
            padding: 18px 20px;
            background: var(--primary-light);
            border-radius: 12px;
            border: 1px solid rgba(230, 126, 34, 0.15);
        }

        .price-big {
            font-size: 2.4rem;
            font-weight: 900;
            color: var(--primary);
            letter-spacing: -0.04em;
            line-height: 1;
        }

        .price-unit {
            font-size: 0.88rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 4px;
        }

        /* Stock info row */
        .stock-info-row {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
            padding: 12px 16px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 0.85rem;
        }

        .stock-info-item {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .stock-info-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: <?= $stockColor ?>;
            flex-shrink: 0;
        }

        .stock-info-label {
            color: var(--text-muted);
        }

        .stock-info-value {
            font-weight: 700;
            color: var(--dark2);
        }

        .stock-divider {
            width: 1px;
            height: 20px;
            background: var(--border);
        }

        /* Description */
        .detail-section-label {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--text-muted);
            margin-bottom: 8px;
            margin-top: 24px;
        }

        .detail-section-label:first-of-type {
            margin-top: 0;
        }

        .product-description {
            font-size: 0.92rem;
            color: var(--text);
            line-height: 1.75;
            margin-bottom: 4px;
        }

        /* Key points */
        .key-points {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 4px;
        }

        .key-point {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 0.87rem;
            color: var(--text);
        }

        .key-point-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 0.7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* ── Quantity + Add to Cart ── */
        .order-block {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            margin-top: 24px;
            box-shadow: var(--shadow);
        }

        .order-block-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 14px;
        }

        .qty-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .qty-label {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--dark2);
            white-space: nowrap;
        }

        .qty-control {
            display: flex;
            align-items: center;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            background: var(--card-bg);
        }

        .qty-btn {
            width: 38px;
            height: 38px;
            background: #f9fafb;
            border: none;
            cursor: pointer;
            font-size: 1.1rem;
            color: var(--dark2);
            transition: background var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .qty-btn:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .qty-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .qty-input {
            width: 52px;
            text-align: center;
            border: none;
            border-left: 1px solid var(--border);
            border-right: 1px solid var(--border);
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark2);
            background: #fff;
            font-family: inherit;
            padding: 0;
            height: 38px;
            outline: none;
        }

        /* Remove number input arrows */
        .qty-input::-webkit-outer-spin-button,
        .qty-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .qty-input[type=number] {
            -moz-appearance: textfield;
        }

        .qty-max-note {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .order-actions {
            display: flex;
            gap: 10px;
        }

        .btn-add-cart-big {
            flex: 1;
            padding: 13px 0;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 800;
            cursor: pointer;
            font-family: inherit;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.01em;
        }

        .btn-add-cart-big:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(230, 126, 34, 0.35);
        }

        .btn-add-cart-big:disabled {
            background: #d1d5db;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-view-cart {
            padding: 13px 18px;
            background: transparent;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            color: var(--text);
            transition: all var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-view-cart:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        /* Subtotal live calc */
        .subtotal-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
            font-size: 0.88rem;
        }

        .subtotal-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .subtotal-value {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.02em;
        }

        /* Trust badges */
        .trust-badges {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .trust-badge {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            background: #f9fafb;
            border: 1px solid var(--border);
            padding: 5px 10px;
            border-radius: 20px;
        }

        /* ══════════════════════════════════════════════
   PRODUCT SPECS TABLE
══════════════════════════════════════════════ */
        .specs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            margin-top: 4px;
        }

        .specs-table tr {
            border-bottom: 1px solid var(--border);
        }

        .specs-table tr:last-child {
            border-bottom: none;
        }

        .specs-table td {
            padding: 10px 4px;
            vertical-align: top;
        }

        .specs-table td:first-child {
            width: 38%;
            color: var(--text-muted);
            font-weight: 600;
        }

        .specs-table td:last-child {
            color: var(--dark2);
            font-weight: 500;
        }

        /* ══════════════════════════════════════════════
   RELATED PRODUCTS
══════════════════════════════════════════════ */
        .related-section {
            padding: 48px 0 60px;
            background: #f3f4f6;
            border-top: 1px solid var(--border);
        }

        .related-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .related-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--dark2);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .related-link {
            font-size: 0.83rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 4px;
            transition: gap var(--transition);
        }

        .related-link:hover {
            gap: 8px;
        }

        .related-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        @media(max-width:1000px) {
            .related-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width:500px) {
            .related-grid {
                grid-template-columns: 1fr;
            }
        }

        .related-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform var(--transition), box-shadow var(--transition);
            cursor: pointer;
        }

        .related-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .related-thumb {
            height: 110px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            position: relative;
        }

        .related-thumb-egg {
            background: linear-gradient(135deg, #fef9ee, #fdeec8);
        }

        .related-thumb-chick {
            background: linear-gradient(135deg, #f0fdf4, #bbf7d0);
        }

        .related-body {
            padding: 12px 14px;
        }

        .related-name {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--dark2);
            margin-bottom: 4px;
            line-height: 1.3;
        }

        .related-price {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--primary);
        }

        .related-unit {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        /* ══════════════════════════════════════════════
   TOAST
══════════════════════════════════════════════ */
        .toast-wrap {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            background: #1f2937;
            color: #f9fafb;
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: toastIn 0.3s ease, toastOut 0.3s ease 2.7s forwards;
            pointer-events: auto;
            min-width: 220px;
            max-width: 340px;
        }

        .toast.toast-success {
            border-left: 4px solid #10b981;
        }

        .toast.toast-error {
            border-left: 4px solid #ef4444;
        }

        @keyframes toastIn {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes toastOut {
            from {
                opacity: 1;
            }

            to {
                opacity: 0;
            }
        }

        /* Login prompt modal */
        .login-prompt-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(3px);
            z-index: 9998;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-prompt-overlay.show {
            display: flex;
        }

        .login-prompt-box {
            background: #fff;
            border-radius: 16px;
            padding: 36px 32px;
            text-align: center;
            max-width: 380px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            position: relative;
        }

        .login-prompt-icon {
            font-size: 3rem;
            margin-bottom: 14px;
        }

        .login-prompt-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--dark2);
            margin-bottom: 8px;
        }

        .login-prompt-desc {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .login-prompt-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn-login-prompt {
            padding: 11px 24px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-login-prompt:hover {
            background: var(--primary-dark);
        }

        .btn-register-prompt {
            padding: 11px 24px;
            background: transparent;
            border: 1.5px solid var(--border);
            color: var(--text);
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-register-prompt:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-close-prompt {
            position: absolute;
            top: 12px;
            right: 14px;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--text-muted);
        }

        /* Footer */
        .site-footer {
            background: #1a1a2e;
            color: #6b7280;
            text-align: center;
            padding: 24px 32px;
            font-size: 0.82rem;
            line-height: 1.7;
        }

        .site-footer a {
            color: var(--primary);
        }
    </style>
</head>

<body>
    <div class="page-body">

        <?php include '../includes/navbar.php'; ?>

        <!-- Breadcrumb -->
        <div class="breadcrumb-strip">
            <div class="container">
                <div class="breadcrumb">
                    <a href="home.php">Home</a>
                    <span class="breadcrumb-sep">›</span>
                    <a href="products.php">Products</a>
                    <span class="breadcrumb-sep">›</span>
                    <a href="products.php?category=<?= $p['cat_id'] ?>"><?= htmlspecialchars($p['category']) ?></a>
                    <span class="breadcrumb-sep">›</span>
                    <span class="breadcrumb-current"><?= htmlspecialchars($p['name']) ?></span>
                </div>
            </div>
        </div>

        <?= flash() ?>

        <!-- ════════════════════════════════════
     PRODUCT DETAIL
════════════════════════════════════ -->
        <div class="detail-section">
            <div class="container">
                <div class="detail-grid">

                    <!-- ── LEFT: Image Panel ── -->
                    <div class="product-image-panel">
                        <div class="product-main-img <?= $thumbCls ?>">
                            <div class="img-bg-blur"><?= $emoji ?></div>
                            <div class="img-main-emoji"><?= $emoji ?></div>
                            <div class="img-stock-pill">
                                <span class="stock-dot"></span>
                                <span><?= $stockLbl ?></span>
                                <?php if ($stock > 0): ?>
                                    &nbsp;·&nbsp; <span style="color:var(--text-muted)"><?= number_format($stock) ?> available</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Decorative thumbnail strip -->
                        <div class="thumb-strip">
                            <div class="thumb-mini active"><?= $emoji ?></div>
                            <div class="thumb-mini"><?= $emoji ?></div>
                            <div class="thumb-mini"><?= $emoji ?></div>
                            <div class="thumb-mini"><?= $emoji ?></div>
                        </div>
                    </div>

                    <!-- ── RIGHT: Info Panel ── -->
                    <div class="product-info-panel">

                        <div class="product-category-tag">
                            <?= $emoji ?> <?= htmlspecialchars($p['category']) ?>
                        </div>

                        <h1 class="product-title"><?= htmlspecialchars($p['name']) ?></h1>

                        <!-- Price -->
                        <div class="price-block">
                            <div class="price-big">₱<?= number_format((float)$p['price'], 2) ?></div>
                            <div class="price-unit"><?= htmlspecialchars($p['unit']) ?></div>
                        </div>

                        <!-- Stock info -->
                        <div class="stock-info-row">
                            <div class="stock-info-item">
                                <span class="stock-info-dot"></span>
                                <span class="stock-info-label">Status:</span>
                                <span class="stock-info-value" style="color:<?= $stockColor ?>;"><?= $stockLbl ?></span>
                            </div>
                            <div class="stock-divider"></div>
                            <div class="stock-info-item">
                                <span class="stock-info-label">Available:</span>
                                <span class="stock-info-value"><?= number_format($stock) ?> units</span>
                            </div>
                            <div class="stock-divider"></div>
                            <div class="stock-info-item">
                                <span class="stock-info-label">Unit:</span>
                                <span class="stock-info-value"><?= htmlspecialchars($p['unit']) ?></span>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="detail-section-label">About this product</div>
                        <p class="product-description">
                            <?= nl2br(htmlspecialchars($p['description'] ?: 'Premium quality product from Hiney\'s farm. Raised with care, free from harmful additives.')) ?>
                        </p>

                        <!-- Key points -->
                        <div class="detail-section-label" style="margin-top:18px;">Why you'll love it</div>
                        <div class="key-points">
                            <div class="key-point">
                                <span class="key-point-dot">✓</span>
                                <span>100% farm fresh — harvested daily from Hiney's free-range farm</span>
                            </div>
                            <div class="key-point">
                                <span class="key-point-dot">✓</span>
                                <span>No preservatives, no additives — pure and natural quality</span>
                            </div>
                            <div class="key-point">
                                <span class="key-point-dot">✓</span>
                                <span>Fast local delivery within Loreto Cortes, Bohol</span>
                            </div>
                            <div class="key-point">
                                <span class="key-point-dot">✓</span>
                                <span>Satisfaction guaranteed — we stand behind every order</span>
                            </div>
                        </div>

                        <!-- Specs -->
                        <div class="detail-section-label" style="margin-top:20px;">Product Details</div>
                        <table class="specs-table">
                            <tr>
                                <td>Category</td>
                                <td><?= htmlspecialchars($p['category']) ?></td>
                            </tr>
                            <tr>
                                <td>Price</td>
                                <td>₱<?= number_format((float)$p['price'], 2) ?> <?= htmlspecialchars($p['unit']) ?></td>
                            </tr>
                            <tr>
                                <td>Unit</td>
                                <td><?= ucfirst(htmlspecialchars($p['unit'])) ?></td>
                            </tr>
                            <tr>
                                <td>Availability</td>
                                <td style="color:<?= $stockColor ?>;font-weight:700;"><?= $stockLbl ?></td>
                            </tr>
                            <tr>
                                <td>Stock</td>
                                <td><?= number_format($stock) ?> units</td>
                            </tr>
                            <tr>
                                <td>Added</td>
                                <td><?= date('F j, Y', strtotime($p['created_at'])) ?></td>
                            </tr>
                        </table>

                        <!-- ── Order Block ── -->
                        <div class="order-block">
                            <div class="order-block-title">Place Your Order</div>

                            <?php if ($stock > 0): ?>
                                <div class="qty-row">
                                    <span class="qty-label">Quantity</span>
                                    <div class="qty-control">
                                        <button class="qty-btn" id="btnMinus" onclick="adjustQty(-1)">−</button>
                                        <input type="number" class="qty-input" id="qtyInput"
                                            value="1" min="1" max="<?= $stock ?>"
                                            oninput="updateSubtotal()">
                                        <button class="qty-btn" id="btnPlus" onclick="adjustQty(1)">+</button>
                                    </div>
                                    <span class="qty-max-note">Max: <?= number_format($stock) ?></span>
                                </div>

                                <div class="order-actions">
                                    <button class="btn-add-cart-big" id="btnAddCart"
                                        onclick="addToCart(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')">
                                        <i class="fa-solid fa-cart-shopping"></i> Add to Cart
                                    </button>
                                    <button class="btn-view-cart" onclick="location.href='cart.php'">
                                        View Cart
                                    </button>
                                </div>

                                <div class="subtotal-row">
                                    <span class="subtotal-label">Estimated subtotal</span>
                                    <span class="subtotal-value" id="subtotalDisplay">
                                        ₱<?= number_format((float)$p['price'], 2) ?>
                                    </span>
                                </div>

                            <?php else: ?>
                                <div style="text-align:center;padding:20px;color:#ef4444;font-weight:700;">
                                    <i class="fa-solid fa-triangle-exclamation"></i> This product is currently out of stock.
                                </div>
                            <?php endif; ?>

                            <!-- Trust badges -->
                            <div class="trust-badges">
                                <span class="trust-badge"><i class="fa-solid fa-leaf"></i> Farm Fresh</span>
                                <span class="trust-badge"><i class="fa-solid fa-truck"></i> Fast Delivery</span>
                                <span class="trust-badge"><i class="fa-solid fa-shield-halved"></i> Quality Guaranteed</span>
                                <span class="trust-badge"><i class="fa-solid fa-lock"></i> Secure Order</span>
                            </div>
                        </div>

                    </div><!-- /.product-info-panel -->

                </div><!-- /.detail-grid -->
            </div><!-- /.container -->
        </div><!-- /.detail-section -->

        <!-- ════════════════════════════════════
     RELATED PRODUCTS
════════════════════════════════════ -->
        <?php if ($related && $related->num_rows > 0): ?>
            <section class="related-section">
                <div class="container">
                    <div class="related-header">
                        <div class="related-title">
                            <?= $emoji ?> More in <?= htmlspecialchars($p['category']) ?>
                        </div>
                        <a href="products.php?category=<?= $p['cat_id'] ?>" class="related-link">
                            View all →
                        </a>
                    </div>
                    <div class="related-grid">
                        <?php while ($r = $related->fetch_assoc()):
                            $rIsEgg   = stripos($r['category'], 'egg') !== false;
                            $rEmoji   = $rIsEgg ? '<i class="fa-solid fa-egg"></i>' : '<i class="fa-solid fa-drumstick-bite"></i>';
                            $rThumb   = $rIsEgg ? 'related-thumb-egg' : 'related-thumb-chick';
                        ?>
                            <div class="related-card" onclick="location.href='product_detail.php?id=<?= $r['id'] ?>'">
                                <div class="related-thumb <?= $rThumb ?>">
                                    <?= $rEmoji ?>
                                    <?php if ((int)$r['stock'] <= 0): ?>
                                        <span style="position:absolute;top:6px;right:6px;background:#fee2e2;color:#991b1b;font-size:0.6rem;font-weight:700;padding:2px 7px;border-radius:10px;text-transform:uppercase;">Out</span>
                                    <?php endif; ?>
                                </div>
                                <div class="related-body">
                                    <div class="related-name"><?= htmlspecialchars($r['name']) ?></div>
                                    <div class="related-price">₱<?= number_format((float)$r['price'], 2) ?></div>
                                    <div class="related-unit"><?= htmlspecialchars($r['unit']) ?></div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="site-footer">
            &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business &nbsp;·&nbsp;
            Loreto Cortes, Bohol &nbsp;·&nbsp;
            <a href="contact.php">Contact Us</a>
        </footer>

    </div><!-- /.page-body -->

    <!-- Toast -->
    <div class="toast-wrap" id="toastWrap"></div>

    <!-- Login prompt modal (guest tries to add to cart) -->
    <div class="login-prompt-overlay" id="loginPrompt" onclick="if(event.target===this)closeLoginPrompt()">
        <div class="login-prompt-box">
            <button class="btn-close-prompt" onclick="closeLoginPrompt()">✕</button>
            <div class="login-prompt-icon"><i class="fa-solid fa-lock"></i></div>
            <div class="login-prompt-title">Sign in to continue</div>
            <div class="login-prompt-desc">You need an account to add items to your cart and place orders.</div>
            <div class="login-prompt-actions">
                <button class="btn-login-prompt" onclick="location.href='../index.php?login=1'">Sign In</button>
                <button class="btn-register-prompt" onclick="location.href='../register.php'">Create Account</button>
            </div>
        </div>
    </div>

    <script>
        const PRICE = <?= (float)$p['price'] ?>;
        const MAX_STOCK = <?= $stock ?>;
        const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;

        function showLoginPrompt() {
            document.getElementById('loginPrompt').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeLoginPrompt() {
            document.getElementById('loginPrompt').classList.remove('show');
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeLoginPrompt();
        });

        // ── Quantity controls ──────────────────────────────────────────
        function adjustQty(delta) {
            const input = document.getElementById('qtyInput');
            let val = parseInt(input.value) || 1;
            val = Math.max(1, Math.min(MAX_STOCK, val + delta));
            input.value = val;
            updateSubtotal();
        }

        function updateSubtotal() {
            const input = document.getElementById('qtyInput');
            let val = parseInt(input.value) || 1;
            val = Math.max(1, Math.min(MAX_STOCK, val));
            input.value = val;

            const subtotal = PRICE * val;
            document.getElementById('subtotalDisplay').textContent =
                '₱' + subtotal.toLocaleString('en-PH', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

            // Disable +/− at bounds
            const btnM = document.getElementById('btnMinus');
            const btnP = document.getElementById('btnPlus');
            if (btnM) btnM.disabled = val <= 1;
            if (btnP) btnP.disabled = val >= MAX_STOCK;
        }

        // Init
        document.addEventListener('DOMContentLoaded', updateSubtotal);

        // ── Add to cart ────────────────────────────────────────────────
        function addToCart(productId, productName) {
            if (!IS_LOGGED_IN) {
                showLoginPrompt();
                return;
            }

            const qty = parseInt(document.getElementById('qtyInput')?.value) || 1;
            const btn = document.getElementById('btnAddCart');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Adding…';
            }

            fetch('cart_action.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=add&product_id=' + productId + '&quantity=' + qty
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('✓ ' + qty + 'x ' + productName + ' added to cart!', 'success');
                        const badge = document.getElementById('cartBadge');
                        if (badge && data.cart_count !== undefined) {
                            badge.textContent = data.cart_count;
                            badge.style.display = data.cart_count > 0 ? 'flex' : 'none';
                        }
                    } else {
                        showToast('✕ ' + (data.message || 'Could not add to cart.'), 'error');
                    }
                })
                .catch(() => showToast('✕ Network error. Please try again.', 'error'))
                .finally(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-cart-shopping"></i> Add to Cart';
                    }
                });
        }

        // ── Toast ──────────────────────────────────────────────────────
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