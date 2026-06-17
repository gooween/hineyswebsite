<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/products.php
// Purpose: Browse all products — PUBLIC (login only for cart)
// ============================================================

session_start();
require_once '../config/db.php';
$isLoggedIn = !empty($_SESSION['user_id']);

$activePage = 'products';
$cartItems  = $isLoggedIn ? cartCount($conn) : 0;

$search     = trim($_GET['search']   ?? '');
$categoryId = (int)($_GET['category'] ?? 0);
$sort       = $_GET['sort'] ?? 'name_asc';

$categories = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");

$where = ["p.is_active = 1"];
$params = [];
$types  = '';

if ($search !== '') {
    $where[]  = "(p.name LIKE ? OR p.description LIKE ?)";
    $s = '%' . $search . '%';
    $params[] = $s;
    $params[] = $s;
    $types   .= 'ss';
}

if ($categoryId > 0) {
    $where[]  = "p.category_id = ?";
    $params[] = $categoryId;
    $types   .= 'i';
}

$whereSQL = implode(' AND ', $where);

$orderSQL = match ($sort) {
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'newest'     => 'p.created_at DESC',
    default      => 'p.name ASC',
};

$sql = "
    SELECT p.id, p.name, p.description, p.price, p.unit,
           p.image_url,
           c.id AS cat_id, c.name AS category,
           COALESCE((
               SELECT SUM(sb.remaining)
               FROM stock_batches sb WHERE sb.product_id=p.id AND sb.status='active'
           ), 0) AS stock
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory i ON i.product_id = p.id
    WHERE {$whereSQL}
    ORDER BY {$orderSQL}
";

if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $products = $stmt->get_result();
} else {
    $products = $conn->query($sql);
}

$totalFound = $products ? $products->num_rows : 0;
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
            color: inherit !important;
        }

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
    <title>Products — Hiney's Eggs &amp; Live Chicken</title>
    <style>
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
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .page-banner {
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            padding: 40px 0 44px;
            position: relative;
            overflow: hidden;
        }

        .page-banner::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 600px 300px at 80% 50%, rgba(230, 126, 34, 0.15), transparent 70%);
            z-index: 0;
        }

        .page-banner-egg-bg {
            position: absolute;
            inset: 0;
            background: url('../assets/images/eggs.png') center center / cover no-repeat;
            opacity: 0.25;
            -webkit-mask-image:
                linear-gradient(to right, transparent 0%, black 15%, black 85%, transparent 100%),
                linear-gradient(to bottom, transparent 0%, black 15%, black 85%, transparent 100%);
            -webkit-mask-composite: destination-in;
            mask-image:
                linear-gradient(to right, transparent 0%, black 15%, black 85%, transparent 100%),
                linear-gradient(to bottom, transparent 0%, black 15%, black 85%, transparent 100%);
            mask-composite: intersect;
            z-index: 0;
        }

        .page-banner-inner {
            position: relative;
            z-index: 1;
        }

        .page-banner-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 32px;
            position: relative;
            z-index: 1;
        }

        .page-banner-eyebrow {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--secondary);
            margin-bottom: 8px;
        }

        .page-banner-title {
            font-size: clamp(1.6rem, 3vw, 2.2rem);
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.025em;
            margin-bottom: 6px;
        }

        .page-banner-sub {
            font-size: 0.95rem;
            color: #8fa3b3;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: #6b7a99;
            margin-bottom: 14px;
        }

        .breadcrumb a {
            color: #8fa3b3;
        }

        .breadcrumb a:hover {
            color: var(--secondary);
        }

        .breadcrumb-sep {
            opacity: 0.4;
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

        .products-layout {
            display: flex;
            flex-direction: column;
            gap: 20px;
            padding: 28px 0 56px;
        }

        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 160px;
        }

        .filter-group-label {
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--dark2);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .filter-search-wrap {
            position: relative;
        }

        .filter-search-wrap svg {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .filter-search {
            width: 100%;
            padding: 8px 12px 8px 32px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 0.85rem;
            font-family: inherit;
            color: var(--text);
            background: #fafafa;
            outline: none;
            transition: border-color var(--transition);
        }

        .filter-search:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.1);
            background: #fff;
        }

        .cat-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .cat-pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 20px;
            border: 1.5px solid var(--border);
            background: #fafafa;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            font-family: inherit;
            transition: all var(--transition);
            white-space: nowrap;
        }

        .cat-pill-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .cat-pill-btn.active {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
            font-weight: 700;
        }

        .filter-select {
            width: 100%;
            padding: 8px 32px 8px 10px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 0.85rem;
            font-family: inherit;
            color: var(--text);
            background: #fafafa;
            outline: none;
            cursor: pointer;
            transition: border-color var(--transition);
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }

        .filter-select:focus {
            border-color: var(--primary);
        }

        .btn-clear-filters {
            padding: 8px 14px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: var(--text-muted);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: all var(--transition);
            white-space: nowrap;
            align-self: flex-end;
        }

        .btn-clear-filters:hover {
            border-color: #ef4444;
            color: #ef4444;
            background: #fef2f2;
        }

        .products-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .toolbar-result {
            font-size: 0.88rem;
            color: var(--text-muted);
        }

        .toolbar-result strong {
            color: var(--dark2);
        }

        .toolbar-result-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            margin-left: 8px;
        }

        .toolbar-result-tag button {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--primary);
            font-size: 0.8rem;
            padding: 0;
            line-height: 1;
        }

        .view-toggle {
            display: flex;
            gap: 4px;
        }

        .view-btn {
            width: 34px;
            height: 34px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            transition: all var(--transition);
        }

        .view-btn.active,
        .view-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
        }

        @media(max-width:1100px) {
            .products-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media(max-width:760px) {
            .products-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width:480px) {
            .products-grid {
                grid-template-columns: 1fr;
            }
        }

        .products-grid.list-view {
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .products-grid.list-view .product-card {
            flex-direction: row;
            max-height: 130px;
        }

        .products-grid.list-view .product-thumb {
            width: 130px;
            min-width: 130px;
            height: 100%;
            border-radius: var(--radius) 0 0 var(--radius);
        }

        .products-grid.list-view .product-body {
            padding: 14px 16px;
        }

        .products-grid.list-view .product-desc {
            -webkit-line-clamp: 1;
        }

        .product-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform var(--transition), box-shadow var(--transition), border-color var(--transition);
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: rgba(230, 126, 34, 0.2);
        }

        .product-thumb {
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .product-thumb-egg {
            background: linear-gradient(135deg, #fef9ee, #fdeec8);
        }

        .product-thumb-chick {
            background: linear-gradient(135deg, #f0fdf4, #bbf7d0);
        }

        .product-thumb-bg {
            position: absolute;
            inset: 0;
            opacity: 0.07;
            font-size: 8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            filter: blur(4px);
        }

        .product-thumb-emoji {
            position: relative;
            z-index: 1;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            transition: transform 0.3s ease;
        }

        .product-card:hover .product-thumb-emoji {
            transform: scale(1.1) rotate(5deg);
        }

        .stock-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 0.62rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stock-ok {
            background: #d1fae5;
            color: #065f46;
        }

        .stock-low {
            background: #fef3c7;
            color: #92400e;
        }

        .stock-out {
            background: #fee2e2;
            color: #991b1b;
        }

        .cat-pill {
            position: absolute;
            top: 10px;
            left: 10px;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            background: rgba(255, 255, 255, 0.9);
            color: var(--dark2);
            backdrop-filter: blur(4px);
        }

        .product-body {
            padding: 16px 16px 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark2);
            margin-bottom: 5px;
            line-height: 1.3;
        }

        .product-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.55;
            flex: 1;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .product-price-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .product-price {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.02em;
        }

        .product-unit {
            font-size: 0.7rem;
            color: var(--text-muted);
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 6px;
            font-weight: 500;
        }

        .product-actions {
            display: flex;
            gap: 8px;
        }

        .btn-view-detail {
            flex: 1;
            padding: 8px 0;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: transparent;
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
            font-family: inherit;
        }

        .btn-view-detail:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .btn-add-cart {
            flex: 2;
            padding: 8px 0;
            border: none;
            border-radius: 8px;
            background: var(--primary);
            color: #fff;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--transition);
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .btn-add-cart:hover {
            background: var(--primary-dark);
        }

        .btn-add-cart:disabled {
            background: #d1d5db;
            cursor: not-allowed;
        }

        .empty-state {
            text-align: center;
            padding: 72px 20px;
            color: var(--text-muted);
            grid-column: 1 / -1;
        }

        .empty-state-icon {
            font-size: 3.5rem;
            display: block;
            margin-bottom: 16px;
        }

        .empty-state-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark2);
            margin-bottom: 8px;
        }

        .empty-state-sub {
            font-size: 0.88rem;
            margin-bottom: 24px;
        }

        .btn-reset {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: var(--primary);
            color: #fff;
            border-radius: 9px;
            font-size: 0.88rem;
            font-weight: 700;
        }

        .btn-reset:hover {
            background: var(--primary-dark);
        }

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

        <div class="page-banner">
            <div class="page-banner-egg-bg"></div>
            <div class="page-banner-inner">
                <div class="breadcrumb">
                    <a href="home.php">Home</a>
                    <span class="breadcrumb-sep">›</span>
                    <span>Products</span>
                </div>
                <div class="page-banner-eyebrow"><i class="fa-solid fa-egg"></i> Fresh from the Farm</div>
                <h1 class="page-banner-title">Our Products</h1>
                <p class="page-banner-sub">Eggs in every size, fresh live chickens — available daily from Hiney's farm.</p>
            </div>
        </div>

        <?= flash() ?>

        <div class="container">
            <div class="products-layout">

                <div class="filter-bar">

                    <div class="filter-group" style="min-width:200px;max-width:260px;">
                        <div class="filter-group-label">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                            Search
                        </div>
                        <form method="GET" action="products.php" id="filterForm">
                            <input type="hidden" name="category" value="<?= $categoryId ?>">
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                            <div class="filter-search-wrap">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8" />
                                    <line x1="21" y1="21" x2="16.65" y2="16.65" />
                                </svg>
                                <input type="text" name="search" class="filter-search" placeholder="Search products…"
                                    value="<?= htmlspecialchars($search) ?>" oninput="debounceSubmit()">
                            </div>
                        </form>
                    </div>

                    <div class="filter-group" style="flex:2;">
                        <div class="filter-group-label">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                            </svg>
                            Category
                        </div>
                        <div class="cat-pills">
                            <?php
                            $catCounts = [];
                            $cr = $conn->query("SELECT category_id, COUNT(*) AS cnt FROM products WHERE is_active=1 GROUP BY category_id");
                            while ($crow = $cr->fetch_assoc()) $catCounts[$crow['category_id']] = $crow['cnt'];
                            $totalActive = array_sum($catCounts);
                            ?>
                            <button class="cat-pill-btn <?= $categoryId === 0 ? 'active' : '' ?>" onclick="filterByCategory(0)">
                                All <span style="opacity:0.6;font-size:0.72rem;">(<?= $totalActive ?>)</span>
                            </button>
                            <?php
                            $categories->data_seek(0);
                            $catEmojis = ['Eggs' => '🥚', 'Live Chicken' => '🍗'];
                            while ($cat = $categories->fetch_assoc()):
                                $cnt = $catCounts[$cat['id']] ?? 0;
                            ?>
                                <button class="cat-pill-btn <?= $categoryId === (int)$cat['id'] ? 'active' : '' ?>"
                                    onclick="filterByCategory(<?= $cat['id'] ?>)">
                                    <?= htmlspecialchars($cat['name']) ?>
                                    <span style="opacity:0.6;font-size:0.72rem;">(<?= $cnt ?>)</span>
                                </button>
                            <?php endwhile; ?>
                        </div>
                    </div>

                    <div class="filter-group" style="min-width:160px;max-width:200px;">
                        <div class="filter-group-label">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="8" y1="6" x2="21" y2="6" />
                                <line x1="8" y1="12" x2="21" y2="12" />
                                <line x1="8" y1="18" x2="21" y2="18" />
                                <line x1="3" y1="6" x2="3.01" y2="6" />
                                <line x1="3" y1="12" x2="3.01" y2="12" />
                                <line x1="3" y1="18" x2="3.01" y2="18" />
                            </svg>
                            Sort By
                        </div>
                        <select class="filter-select" onchange="changeSort(this.value)">
                            <option value="name_asc" <?= $sort === 'name_asc'   ? 'selected' : '' ?>>Name A–Z</option>
                            <option value="price_asc" <?= $sort === 'price_asc'  ? 'selected' : '' ?>>Price: Low to High</option>
                            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                            <option value="newest" <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest First</option>
                        </select>
                    </div>

                    <?php if ($search || $categoryId || $sort !== 'name_asc'): ?>
                        <button class="btn-clear-filters" onclick="location.href='products.php'">✕ Clear</button>
                    <?php endif; ?>

                </div>

                <div>
                    <div class="products-toolbar" style="margin-bottom:16px;">
                        <div class="toolbar-result">
                            Showing <strong><?= $totalFound ?></strong> product<?= $totalFound !== 1 ? 's' : '' ?>
                            <?php if ($search): ?>
                                <span class="toolbar-result-tag">
                                    "<?= htmlspecialchars($search) ?>"
                                    <button onclick="clearSearch()" title="Clear">✕</button>
                                </span>
                            <?php endif; ?>
                            <?php if ($categoryId): ?>
                                <?php
                                $catNameDisplay = '';
                                $categories->data_seek(0);
                                while ($cc = $categories->fetch_assoc()) {
                                    if ((int)$cc['id'] === $categoryId) {
                                        $catNameDisplay = $cc['name'];
                                        break;
                                    }
                                }
                                ?>
                                <span class="toolbar-result-tag">
                                    <?= htmlspecialchars($catNameDisplay) ?>
                                    <button onclick="filterByCategory(0)" title="Clear">✕</button>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="view-toggle">
                            <button class="view-btn active" id="btnGrid" onclick="setView('grid')" title="Grid">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="7" height="7" />
                                    <rect x="14" y="3" width="7" height="7" />
                                    <rect x="14" y="14" width="7" height="7" />
                                    <rect x="3" y="14" width="7" height="7" />
                                </svg>
                            </button>
                            <button class="view-btn" id="btnList" onclick="setView('list')" title="List">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="8" y1="6" x2="21" y2="6" />
                                    <line x1="8" y1="12" x2="21" y2="12" />
                                    <line x1="8" y1="18" x2="21" y2="18" />
                                    <line x1="3" y1="6" x2="3.01" y2="6" />
                                    <line x1="3" y1="12" x2="3.01" y2="12" />
                                    <line x1="3" y1="18" x2="3.01" y2="18" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="products-grid" id="productsGrid">
                        <?php if ($totalFound > 0): ?>
                            <?php while ($p = $products->fetch_assoc()):
                                $isEgg    = stripos($p['category'], 'egg') !== false;
                                $thumbCls = $isEgg ? 'product-thumb-egg' : 'product-thumb-chick';
                                $emoji    = $isEgg ? '<i class="fa-solid fa-egg"></i>' : '<i class="fa-solid fa-drumstick-bite"></i>';
                                $stock    = (int)$p['stock'];
                                if ($stock <= 0) {
                                    $stockCls = 'stock-out';
                                    $stockLbl = 'Out of Stock';
                                } elseif ($stock <= 10) {
                                    $stockCls = 'stock-low';
                                    $stockLbl = 'Low Stock';
                                } else {
                                    $stockCls = 'stock-ok';
                                    $stockLbl = 'In Stock';
                                }
                            ?>
                                <div class="product-card">
                                    <div class="product-thumb <?= $thumbCls ?>">
                                        <?php if (!empty($p['image_url'])): ?>
                                            <img src="<?= htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;">
                                        <?php else: ?>
                                            <div class="product-thumb-bg"><?= $emoji ?></div>
                                            <div class="product-thumb-emoji"><?= $emoji ?></div>
                                        <?php endif; ?>
                                        <span class="stock-badge <?= $stockCls ?>"><?= $stockLbl ?></span>
                                        <span class="cat-pill"><?= htmlspecialchars($p['category']) ?></span>
                                    </div>
                                    <div class="product-body">
                                        <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                                        <div class="product-desc"><?= htmlspecialchars($p['description'] ?: 'Fresh from Hiney\'s farm.') ?></div>
                                        <div class="product-price-row">
                                            <div class="product-price">₱<?= number_format((float)$p['price'], 2) ?></div>
                                            <span class="product-unit"><?= htmlspecialchars($p['unit']) ?></span>
                                        </div>
                                        <div class="product-actions">
                                            <button class="btn-view-detail" onclick="location.href='product_detail.php?id=<?= $p['id'] ?>'">Details</button>
                                            <button class="btn-add-cart <?= $stock <= 0 ? 'disabled' : '' ?>" <?= $stock <= 0 ? 'disabled' : '' ?>
                                                onclick="addToCart(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', '<?= htmlspecialchars(addslashes($p['unit'])) ?>', <?= $stock ?>)">
                                                <i class="fa-solid fa-cart-shopping"></i> Add to Cart
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <span class="empty-state-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <div class="empty-state-title">No products found</div>
                                <div class="empty-state-sub"><?= $search ? 'No results for "' . htmlspecialchars($search) . '". Try a different keyword.' : 'No products available in this category right now.' ?></div>
                                <a href="products.php" class="btn-reset">View All Products</a>
                            </div>
                        <?php endif; ?>
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

    <div class="toast-wrap" id="toastWrap"></div>

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

        function getParams() {
            return new URLSearchParams(window.location.search);
        }

        function filterByCategory(catId) {
            const p = getParams();
            if (catId === 0) p.delete('category');
            else p.set('category', catId);
            window.location.href = 'products.php?' + p.toString();
        }

        function changeSort(val) {
            const p = getParams();
            p.set('sort', val);
            window.location.href = 'products.php?' + p.toString();
        }

        function clearSearch() {
            const p = getParams();
            p.delete('search');
            window.location.href = 'products.php?' + p.toString();
        }

        let _searchTimer;

        function debounceSubmit() {
            clearTimeout(_searchTimer);
            _searchTimer = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
        }

        function setView(mode) {
            const grid = document.getElementById('productsGrid');
            const btnG = document.getElementById('btnGrid');
            const btnL = document.getElementById('btnList');
            if (mode === 'list') {
                grid.classList.add('list-view');
                btnL.classList.add('active');
                btnG.classList.remove('active');
                localStorage.setItem('hineys_view', 'list');
            } else {
                grid.classList.remove('list-view');
                btnG.classList.add('active');
                btnL.classList.remove('active');
                localStorage.setItem('hineys_view', 'grid');
            }
        }
        (function() {
            const saved = localStorage.getItem('hineys_view');
            if (saved === 'list') setView('list');
        })();

        function addToCart(productId, productName, unit, stock) {
            if (!IS_LOGGED_IN) {
                showLoginPrompt();
                return;
            }
            fetch('cart_action.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=add&product_id=' + productId + '&quantity=1'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast('✓ ' + productName + ' added to cart!', 'success');
                        const badge = document.getElementById('cartBadge');
                        if (badge && data.cart_count !== undefined) {
                            badge.textContent = data.cart_count;
                            badge.style.display = data.cart_count > 0 ? 'flex' : 'none';
                        }
                    } else {
                        showToast('✕ ' + (data.message || 'Could not add to cart.'), 'error');
                    }
                })
                .catch(() => showToast('✕ Network error. Please try again.', 'error'));
        }

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