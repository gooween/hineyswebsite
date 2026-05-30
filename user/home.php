<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/home.php
// Purpose: Customer landing/home page — PUBLIC (no login required)
// ============================================================

session_start();
require_once '../config/db.php';
// NO requireCustomer() — page is public

$activePage = 'home';
$isLoggedIn = !empty($_SESSION['user_id']);
$cartItems  = $isLoggedIn ? cartCount($conn) : 0;

// ── Featured products (active, with stock) ───────────────────
$featured = $conn->query("
    SELECT p.id, p.name, p.description, p.price, p.unit,
           c.name AS category,
           COALESCE(i.quantity, 0) AS stock
    FROM products p
    JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory i ON i.product_id = p.id
    WHERE p.is_active = 1
    ORDER BY p.created_at DESC
    LIMIT 8
");

// ── Stats for the hero strip ──────────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE is_active = 1");
$totalProducts = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'customer' AND is_active = 1");
$totalCustomers = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'delivered'");
$totalDelivered = (int)($r->fetch_assoc()['cnt'] ?? 0);

$firstName = $isLoggedIn ? explode(' ', $_SESSION['full_name'] ?? 'there')[0] : 'there';
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
    <title>Home — Hiney's Eggs &amp; Live Chicken</title>
    <style>
        /* Keep all existing styles exactly the same */
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
            --success: #10b981;
            --radius: 14px;
            --radius-lg: 20px;
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

        img {
            max-width: 100%;
            display: block;
        }

        /* Hero */
        .hero {
            background: linear-gradient(135deg, #1a1a2e 0%, #2c3e50 50%, #1a2744 100%);
            position: relative;
            overflow: hidden;
            padding: 80px 0 90px;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 700px 500px at 80% 50%, rgba(230, 126, 34, 0.18) 0%, transparent 70%), radial-gradient(ellipse 400px 400px at 10% 80%, rgba(243, 156, 18, 0.10) 0%, transparent 60%);
        }

        .hero-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.25;
            animation: blobFloat 8s ease-in-out infinite;
        }

        .hero-blob-1 {
            width: 400px;
            height: 400px;
            background: var(--primary);
            top: -100px;
            right: 5%;
        }

        .hero-blob-2 {
            width: 300px;
            height: 300px;
            background: var(--secondary);
            bottom: -80px;
            right: 20%;
            animation-delay: -3s;
        }

        @keyframes blobFloat {

            0%,
            100% {
                transform: translateY(0) scale(1);
            }

            50% {
                transform: translateY(-30px) scale(1.05);
            }
        }

        .hero-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 32px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(230, 126, 34, 0.15);
            border: 1px solid rgba(230, 126, 34, 0.3);
            color: #fba860;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 6px 14px;
            border-radius: 20px;
            margin-bottom: 20px;
            width: fit-content;
        }

        .hero-title {
            margin-bottom: 24px;
        }

        .hero-title-brand {
            display: block;
            font-size: clamp(6rem, 12vw, 10rem);
            font-weight: 900;
            color: #f39c12;
            text-transform: uppercase;
            letter-spacing: -0.08em;
            line-height: 0.85;
            margin-bottom: 18px;
            text-shadow:
                0 4px 12px rgba(243, 156, 18, 0.3),
                0 10px 30px rgba(243, 156, 18, 0.2);
        }

        .hero-title-small {
            display: block;
            font-size: clamp(1.2rem, 2vw, 1.8rem);
            font-weight: 700;
            color: #ffffff;
            line-height: 1.25;
            letter-spacing: -0.02em;
        }

        .hero-title-accent {
            color: var(--secondary);
        }

        .hero-desc {
            font-size: 1.05rem;
            color: #9ca3b8;
            line-height: 1.75;
            margin-bottom: 36px;
            max-width: 480px;
        }

        .hero-actions {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .btn-hero-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 700;
            padding: 14px 28px;
            border-radius: 12px;
            transition: all var(--transition);
            box-shadow: 0 6px 24px rgba(230, 126, 34, 0.4);
        }

        .btn-hero-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(230, 126, 34, 0.5);
        }

        .btn-hero-ghost {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #e5e7eb;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 13px 22px;
            border-radius: 12px;
            transition: all var(--transition);
        }

        .btn-hero-ghost:hover {
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
        }

        .hero-visual {
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }

        .hero-egg-wrap {
            width: 340px;
            height: 340px;
            background: radial-gradient(circle at 35% 35%, rgba(230, 126, 34, 0.25), rgba(230, 126, 34, 0.06) 60%, transparent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(230, 126, 34, 0.2);
            position: relative;
            animation: eggPulse 4s ease-in-out infinite;
        }

        @keyframes eggPulse {

            0%,
            100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(230, 126, 34, 0.15);
            }

            50% {
                transform: scale(1.02);
                box-shadow: 0 0 0 20px rgba(230, 126, 34, 0);
            }
        }

        .hero-egg-inner {
            font-size: 8rem;
            filter: drop-shadow(0 20px 40px rgba(0, 0, 0, 0.3));
            animation: eggFloat 3s ease-in-out infinite;
        }

        @keyframes eggFloat {

            0%,
            100% {
                transform: translateY(0) rotate(-5deg);
            }

            50% {
                transform: translateY(-16px) rotate(5deg);
            }
        }

        .hero-float-card {
            position: absolute;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 10px 14px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--dark2);
            backdrop-filter: blur(10px);
            animation: cardBob 3s ease-in-out infinite;
        }

        .hero-float-card .fc-icon {
            font-size: 1.2rem;
        }

        .hero-float-card.fc-1 {
            top: 30px;
            left: -20px;
            animation-delay: -1s;
        }

        .hero-float-card.fc-2 {
            bottom: 50px;
            right: -20px;
            animation-delay: -2s;
        }

        .hero-float-card.fc-3 {
            top: 50%;
            left: -40px;
            transform: translateY(-50%);
            animation-delay: -0.5s;
        }

        @keyframes cardBob {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-8px);
            }
        }

        .hero-float-card.fc-3 {
            animation: cardBob3 3s ease-in-out infinite;
        }

        @keyframes cardBob3 {

            0%,
            100% {
                transform: translateY(-50%);
            }

            50% {
                transform: translateY(calc(-50% - 8px));
            }
        }

        .hero-stats {
            display: flex;
            gap: 28px;
            margin-top: 36px;
            padding-top: 28px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .hero-stat-item {
            text-align: left;
        }

        .hero-stat-num {
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.03em;
            line-height: 1;
        }

        .hero-stat-num span {
            color: var(--secondary);
        }

        .hero-stat-label {
            font-size: 0.75rem;
            color: #6b7a99;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .section {
            padding: 72px 0;
        }

        .section-alt {
            background: #f3f4f6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 32px;
        }

        .section-header {
            text-align: center;
            margin-bottom: 48px;
        }

        .section-eyebrow {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--primary);
            background: var(--primary-light);
            padding: 4px 14px;
            border-radius: 20px;
            margin-bottom: 12px;
        }

        .section-title {
            font-size: clamp(1.6rem, 3vw, 2.2rem);
            font-weight: 800;
            color: var(--dark2);
            letter-spacing: -0.025em;
            line-height: 1.2;
            margin-bottom: 12px;
        }

        .section-sub {
            font-size: 1rem;
            color: var(--text-muted);
            max-width: 540px;
            margin: 0 auto;
            line-height: 1.7;
        }

        /* Products grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
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

        .product-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform var(--transition), box-shadow var(--transition);
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .product-thumb {
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            position: relative;
            overflow: hidden;
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
            transform: scale(1.12) rotate(5deg);
        }

        .stock-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 0.65rem;
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

        .product-body {
            padding: 16px 16px 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .product-category {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .product-name {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark2);
            margin-bottom: 6px;
            line-height: 1.3;
        }

        .product-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.5;
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
            font-size: 0.72rem;
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

        .btn-view {
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

        .btn-view:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .btn-cart {
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

        .btn-cart:hover {
            background: var(--primary-dark);
        }

        .btn-cart:disabled {
            background: #d1d5db;
            cursor: not-allowed;
        }

        .view-all-wrap {
            text-align: center;
            margin-top: 36px;
        }

        .btn-view-all {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: #fff;
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 700;
            transition: all var(--transition);
            box-shadow: 0 4px 16px rgba(230, 126, 34, 0.3);
        }

        .btn-view-all:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        /* Features */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        @media(max-width:760px) {
            .features-grid {
                grid-template-columns: 1fr;
            }
        }

        .feature-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px 24px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: transform var(--transition), box-shadow var(--transition);
        }

        .feature-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .feature-icon-wrap {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 16px;
        }

        .fi-orange {
            background: #fef3e8;
        }

        .fi-green {
            background: #ecfdf5;
        }

        .fi-blue {
            background: #eff6ff;
        }

        .feature-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark2);
            margin-bottom: 8px;
        }

        .feature-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.65;
        }

        /* About strip */
        .about-strip {
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            padding: 72px 0;
            position: relative;
            overflow: hidden;
        }

        .about-strip::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 600px 400px at 90% 50%, rgba(230, 126, 34, 0.12) 0%, transparent 70%);
        }

        .about-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 32px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        @media(max-width:760px) {
            .about-inner {
                grid-template-columns: 1fr;
                gap: 32px;
            }
        }

        .about-eyebrow {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--secondary);
            margin-bottom: 14px;
        }

        .about-title {
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
            line-height: 1.25;
            margin-bottom: 16px;
        }

        .about-desc {
            font-size: 0.95rem;
            color: #8fa3b3;
            line-height: 1.8;
            margin-bottom: 28px;
        }

        .btn-about {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: #fff;
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 700;
            transition: all var(--transition);
        }

        .btn-about:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .about-stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .about-stat-card {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 20px 18px;
            text-align: center;
        }

        .about-stat-num {
            font-size: 2rem;
            font-weight: 800;
            color: var(--secondary);
            letter-spacing: -0.04em;
            line-height: 1;
        }

        .about-stat-label {
            font-size: 0.75rem;
            color: #6b7a99;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.07em;
        }

        /* CTA */
        .cta-section {
            padding: 72px 0;
            background: var(--bg);
        }

        .cta-card {
            max-width: 800px;
            margin: 0 auto;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: var(--radius-lg);
            padding: 52px 48px;
            text-align: center;
            position: relative;
            overflow: hidden;
            box-shadow: 0 16px 48px rgba(230, 126, 34, 0.35);
        }

        .cta-card::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.15), transparent 60%);
        }

        .cta-card-inner {
            position: relative;
            z-index: 1;
        }

        .cta-emoji {
            font-size: 3rem;
            margin-bottom: 16px;
            display: block;
        }

        .cta-title {
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
            margin-bottom: 10px;
        }

        .cta-desc {
            font-size: 0.98rem;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 28px;
            line-height: 1.65;
        }

        .btn-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            color: var(--primary);
            padding: 14px 32px;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 800;
            transition: all var(--transition);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        /* Footer + Toast */
        .site-footer {
            background: var(--dark);
            color: #6b7280;
            text-align: center;
            padding: 28px 32px;
            font-size: 0.82rem;
            line-height: 1.7;
        }

        .site-footer a {
            color: var(--primary);
            transition: color var(--transition);
        }

        .site-footer a:hover {
            color: var(--secondary);
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
            animation: modalIn 0.25s ease;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.97);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
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
            transition: all var(--transition);
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
            transition: all var(--transition);
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

        @media(max-width:900px) {
            .hero-inner {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 40px;
            }

            .hero-visual {
                display: none;
            }

            .hero-actions {
                justify-content: center;
            }

            .hero-stats {
                justify-content: center;
            }

            .hero-badge {
                margin: 0 auto 20px;
            }

            .hero-desc {
                margin: 0 auto 36px;
            }
        }

        @media(max-width:600px) {
            .container {
                padding: 0 16px;
            }

            .hero {
                padding: 52px 0 60px;
            }

            .cta-card {
                padding: 36px 20px;
            }

            .about-inner {
                padding: 0 16px;
            }
        }
    </style>
</head>

<body>
    <div class="page-body">

        <?php include '../includes/navbar.php'; ?>

        <!-- HERO -->
        <section class="hero">
            <div class="hero-inner">
                <div class="hero-content">
                    <div class="hero-badge"><i class="fa-solid fa-egg"></i> Fresh &amp; Farm-Direct</div>
                    <h1 class="hero-title">
                        <?php if ($isLoggedIn): ?>
                            Welcome Back,<br>
                            <span class="hero-title-brand"><?= htmlspecialchars($firstName) ?>!</span>
                        <?php else: ?>
                            <span class="hero-title-brand">HINEY'S</span>
                            <span class="hero-title-small">Bohol's Trusted Source for</span>
                            <span class="hero-title-small">Fresh Eggs & Live Chickens</span>
                        <?php endif; ?>
                    </h1>

                    <p class="hero-desc">
                        Bringing farm-fresh eggs and healthy live chickens directly from
                        Hiney's farm to homes and businesses across Bohol. Quality,
                        freshness, and service you can trust.
                    </p>
                    <div class="hero-actions">
                        <a href="products.php" class="btn-hero-primary"><i class="fa-solid fa-cart-shopping"></i> Shop Now</a>
                        <?php if ($isLoggedIn): ?>
                            <a href="orders.php" class="btn-hero-ghost"><i class="fa-solid fa-box"></i> My Orders</a>
                        <?php else: ?>
                            <a href="../index.php" class="btn-hero-ghost"><i class="fa-solid fa-right-to-bracket"></i> Sign In</a>
                        <?php endif; ?>
                    </div>
                    <div class="hero-stats">
                        <div class="hero-stat-item">
                            <div class="hero-stat-num"><?= $totalProducts ?><span>+</span></div>
                            <div class="hero-stat-label">Products</div>
                        </div>
                        <div class="hero-stat-item">
                            <div class="hero-stat-num"><?= $totalDelivered ?><span>+</span></div>
                            <div class="hero-stat-label">Delivered</div>
                        </div>
                        <div class="hero-stat-item">
                            <div class="hero-stat-num"><?= $totalCustomers ?><span>+</span></div>
                            <div class="hero-stat-label">Happy Customers</div>
                        </div>
                    </div>
                </div>
                <div class="hero-visual">
                    <div class="hero-blob hero-blob-1"></div>
                    <div class="hero-blob hero-blob-2"></div>
                    <div class="hero-egg-wrap">
                        <img src="../assets/images/hineys_logo.png" alt="Hiney's" class="hero-egg-inner">

                        <div class="hero-float-card fc-1"><span class="fc-icon">✓</span><span>Farm Fresh</span></div>
                        <div class="hero-float-card fc-2"><span class="fc-icon"><i class="fa-solid fa-truck"></i></span><span>Fast Delivery</span></div>
                        <div class="hero-float-card fc-3"><span class="fc-icon"><i class="fa-solid fa-star"></i></span><span>Top Quality</span></div>
                    </div>
                </div>
            </div>
        </section>

        <?= flash() ?>

        <!-- FEATURED PRODUCTS -->
        <section class="section">
            <div class="container">
                <div class="section-header">
                    <span class="section-eyebrow">Fresh Picks</span>
                    <h2 class="section-title">Our Products</h2>
                    <p class="section-sub">Handpicked from our farm — eggs in every size and fresh live chickens available daily.</p>
                </div>

                <?php if ($featured && $featured->num_rows > 0): ?>
                    <div class="products-grid">
                        <?php while ($p = $featured->fetch_assoc()):
                            $isEgg   = stripos($p['category'], 'egg') !== false;
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
                                    <div class="product-thumb-bg"><?= $emoji ?></div>
                                    <div class="product-thumb-emoji"><?= $emoji ?></div>
                                    <span class="stock-badge <?= $stockCls ?>"><?= $stockLbl ?></span>
                                </div>
                                <div class="product-body">
                                    <div class="product-category"><?= htmlspecialchars($p['category']) ?></div>
                                    <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="product-desc"><?= htmlspecialchars($p['description'] ?: 'Premium quality product from Hiney\'s farm.') ?></div>
                                    <div class="product-price-row">
                                        <div class="product-price">₱<?= number_format((float)$p['price'], 2) ?></div>
                                        <span class="product-unit"><?= htmlspecialchars($p['unit']) ?></span>
                                    </div>
                                    <div class="product-actions">
                                        <button class="btn-view" onclick="location.href='product_detail.php?id=<?= $p['id'] ?>'">View</button>
                                        <button class="btn-cart <?= $stock <= 0 ? 'disabled' : '' ?>"
                                            <?= $stock <= 0 ? 'disabled' : '' ?>
                                            onclick="addToCart(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')">
                                            <i class="fa-solid fa-cart-shopping"></i> Add to Cart
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align:center;padding:48px;color:var(--text-muted);">
                        <div style="font-size:3rem;margin-bottom:12px;"><i class="fa-solid fa-egg"></i></div>
                        <div>No products available right now. Check back soon!</div>
                    </div>
                <?php endif; ?>

                <div class="view-all-wrap">
                    <a href="products.php" class="btn-view-all">Browse All Products →</a>
                </div>
            </div>
        </section>

        <!-- WHY CHOOSE US -->
        <section class="section section-alt">
            <div class="container">
                <div class="section-header">
                    <span class="section-eyebrow">Why Hiney's</span>
                    <h2 class="section-title">Why Choose Us?</h2>
                    <p class="section-sub">We've been raising healthy chickens and collecting fresh eggs for years.</p>
                </div>
                <div class="features-grid">
                    <div class="feature-card">
                        <div class="feature-icon-wrap fi-orange"><i class="fa-solid fa-leaf"></i></div>
                        <div class="feature-title">100% Farm Fresh</div>
                        <div class="feature-desc">Every egg and chicken comes straight from our farm. No middlemen, no cold storage delays.</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon-wrap fi-green"><i class="fa-solid fa-truck"></i></div>
                        <div class="feature-title">Fast Local Delivery</div>
                        <div class="feature-desc">We deliver right to your door within Bohol. Same or next day delivery.</div>
                    </div>
                    <div class="feature-card">
                        <div class="feature-icon-wrap fi-blue"><i class="fa-solid fa-shield-halved"></i></div>
                        <div class="feature-title">Quality Guaranteed</div>
                        <div class="feature-desc">Not satisfied? We'll make it right. Every product is quality-checked.</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ABOUT SNIPPET -->
        <section class="about-strip">
            <div class="about-inner">
                <div>
                    <span class="about-eyebrow">Our Story</span>
                    <h2 class="about-title">A Family Farm You Can Trust</h2>
                    <p class="about-desc">
                        Hiney's Eggs and Live Chicken Business started as a small backyard farm in Loreto Cortes, Bohol.
                        Over the years we've grown to become one of the most trusted local suppliers.
                    </p>
                    <a href="about.php" class="btn-about">Learn More About Us →</a>
                </div>
                <div class="about-stats-grid">
                    <div class="about-stat-card">
                        <div class="about-stat-num">5+</div>
                        <div class="about-stat-label">Years in Business</div>
                    </div>
                    <div class="about-stat-card">
                        <div class="about-stat-num"><?= $totalCustomers ?>+</div>
                        <div class="about-stat-label">Happy Customers</div>
                    </div>
                    <div class="about-stat-card">
                        <div class="about-stat-num"><?= $totalDelivered ?>+</div>
                        <div class="about-stat-label">Orders Delivered</div>
                    </div>
                    <div class="about-stat-card">
                        <div class="about-stat-num">100%</div>
                        <div class="about-stat-label">Farm Fresh</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="cta-section">
            <div class="container">
                <div class="cta-card">
                    <div class="cta-card-inner">
                        <span class="cta-emoji"><i class="fa-solid fa-drumstick-bite"></i></span>
                        <h2 class="cta-title">Ready to Order?</h2>
                        <p class="cta-desc">Fresh eggs and live chickens are available now.<br>Place your order and we'll deliver straight to your door!</p>
                        <a href="products.php" class="btn-cta"><i class="fa-solid fa-cart-shopping"></i> Shop Now</a>
                    </div>
                </div>
            </div>
        </section>

        <footer class="site-footer">
            &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business &nbsp;·&nbsp;
            Loreto Cortes, Bohol &nbsp;·&nbsp;
            <a href="contact.php">Contact Us</a>
        </footer>

    </div>

    <!-- Login prompt modal (shown when guest tries to add to cart) -->
    <div class="login-prompt-overlay" id="loginPrompt" onclick="if(event.target===this)closeLoginPrompt()">
        <div class="login-prompt-box" style="position:relative;">
            <button class="btn-close-prompt" onclick="closeLoginPrompt()">✕</button>
            <div class="login-prompt-icon"><i class="fa-solid fa-lock"></i></div>
            <div class="login-prompt-title">Sign in to continue</div>
            <div class="login-prompt-desc">You need an account to add items to your cart and place orders.</div>
            <div class="login-prompt-actions">
                <button class="btn-login-prompt" onclick="location.href='../index.php'">Sign In</button>
                <button class="btn-register-prompt" onclick="location.href='../register.php'">Create Account</button>
            </div>
        </div>
    </div>

    <div class="toast-wrap" id="toastWrap"></div>

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

        function addToCart(productId, productName) {
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
                        const badge = document.querySelector('.cart-badge');
                        if (badge && data.cart_count !== undefined) {
                            badge.textContent = data.cart_count;
                            badge.classList.toggle('hidden', data.cart_count === 0);
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