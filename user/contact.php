<?php
session_start();
require_once '../config/db.php';
$isLoggedIn = !empty($_SESSION['user_id']);

$activePage = 'contact';
$cartItems  = $isLoggedIn ? cartCount($conn) : 0;

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $phone   = trim($_POST['phone']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$name || !$message) {
        $_SESSION['contact_error'] = 'Name and message are required.';
    } else {
        $stmt = $conn->prepare("INSERT INTO contacts (name, email, phone, subject, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
        $stmt->bind_param('sssss', $name, $email, $phone, $subject, $message);
        if ($stmt->execute()) {
            $_SESSION['contact_success'] = 'Your message has been sent! We\'ll get back to you soon. 😊';
        } else {
            $_SESSION['contact_error'] = 'Something went wrong. Please try again.';
        }
        $stmt->close();
    }
    header('Location: contact.php');
    exit;
}

if (!empty($_SESSION['contact_success'])) {
    $success = $_SESSION['contact_success'];
    unset($_SESSION['contact_success']);
}
if (!empty($_SESSION['contact_error'])) {
    $error   = $_SESSION['contact_error'];
    unset($_SESSION['contact_error']);
}

$prefillName  = $_SESSION['full_name'] ?? '';
$prefillEmail = $_SESSION['email']     ?? '';
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
    <title>Contact Us — Hiney's Eggs &amp; Live Chicken</title>
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
            --success: #10b981;
            --success-bg: #ecfdf5;
            --danger: #ef4444;
            --danger-bg: #fef2f2;
            --radius: 14px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 8px 24px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.10), 0 24px 48px rgba(0, 0, 0, 0.08);
            --navbar-h: 64px;
            --transition: 0.2s ease;
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

        /* Page header */
        .page-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #2c3e50 100%);
            padding: 52px 0 56px;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 600px 400px at 80% 50%, rgba(230, 126, 34, 0.15) 0%, transparent 70%);
            z-index: 0;
        }

        /* chicken_meat.png background */
        .page-header-bg {
            position: absolute;
            inset: 0;
            background: url('../assets/images/chicken_meat.png') center center / cover no-repeat;
            opacity: 0.15;
            mix-blend-mode: luminosity;
            -webkit-mask-image:
                linear-gradient(to right, transparent 0%, black 15%, black 85%, transparent 100%),
                linear-gradient(to bottom, transparent 0%, black 10%, black 90%, transparent 100%);
            -webkit-mask-composite: destination-in;
            mask-image:
                linear-gradient(to right, transparent 0%, black 15%, black 85%, transparent 100%),
                linear-gradient(to bottom, transparent 0%, black 10%, black 90%, transparent 100%);
            mask-composite: intersect;
            z-index: 0;
        }

        .page-header-inner {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 32px;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }

        .page-breadcrumb {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: #6b7a99;
            margin-bottom: 12px
        }

        .page-breadcrumb a {
            color: var(--secondary)
        }

        .page-breadcrumb a:hover {
            color: #fff
        }

        .page-breadcrumb span {
            color: #3d4f61
        }

        .page-title {
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 900;
            color: #fff;
            letter-spacing: -0.03em;
            line-height: 1.1;
            margin-bottom: 8px
        }

        .page-title-accent {
            color: var(--secondary)
        }

        .page-sub {
            font-size: 0.95rem;
            color: #8fa3b3;
            line-height: 1.65
        }

        .page-header-emoji {
            font-size: 5rem;
            opacity: 0.25;
            flex-shrink: 0;
            animation: floatEmoji 4s ease-in-out infinite
        }

        @keyframes floatEmoji {

            0%,
            100% {
                transform: translateY(0) rotate(-5deg)
            }

            50% {
                transform: translateY(-12px) rotate(5deg)
            }
        }

        /* Layout */
        .page-body {
            max-width: 1100px;
            margin: 0 auto;
            padding: 48px 32px 72px;
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 32px;
            align-items: start
        }

        @media(max-width:900px) {
            .page-body {
                grid-template-columns: 1fr
            }

            .contact-sidebar {
                order: -1
            }
        }

        @media(max-width:600px) {
            .page-body {
                padding: 28px 16px 56px
            }

            .page-header-inner {
                padding: 0 16px
            }
        }

        /* Form card */
        .form-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden
        }

        .form-card-header {
            padding: 24px 28px 18px;
            border-bottom: 1px solid var(--border)
        }

        .form-card-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--dark2);
            display: flex;
            align-items: center;
            gap: 8px
        }

        .form-card-sub {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-top: 4px
        }

        .contact-form {
            padding: 24px 28px 28px
        }

        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
            line-height: 1.5
        }

        .alert-success {
            background: var(--success-bg);
            border: 1px solid #a7f3d0;
            color: #065f46
        }

        .alert-error {
            background: var(--danger-bg);
            border: 1px solid #fecaca;
            color: #991b1b
        }

        .alert-icon {
            font-size: 1.1rem;
            flex-shrink: 0;
            margin-top: 1px
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px
        }

        @media(max-width:520px) {
            .form-row {
                grid-template-columns: 1fr
            }
        }

        .form-group {
            margin-bottom: 16px
        }

        .form-group:last-child {
            margin-bottom: 0
        }

        .form-label {
            display: block;
            font-size: 0.83rem;
            font-weight: 600;
            color: var(--dark2);
            margin-bottom: 6px
        }

        .form-label .req {
            color: var(--danger);
            margin-left: 2px
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 0.9rem;
            font-family: inherit;
            color: var(--text);
            background: #fff;
            transition: border-color var(--transition), box-shadow var(--transition);
            outline: none
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.12)
        }

        .form-control::placeholder {
            color: #b0b7c0
        }

        textarea.form-control {
            resize: vertical;
            min-height: 130px;
            line-height: 1.6
        }

        select.form-control {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7280' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            appearance: none;
            padding-right: 36px
        }

        .char-counter {
            font-size: 0.72rem;
            color: var(--text-muted);
            text-align: right;
            margin-top: 4px
        }

        .char-counter.warn {
            color: var(--danger)
        }

        .btn-submit {
            width: 100%;
            padding: 13px 24px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
            box-shadow: 0 4px 16px rgba(230, 126, 34, 0.3)
        }

        .btn-submit:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(230, 126, 34, 0.4)
        }

        /* Sidebar */
        .contact-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px
        }

        .info-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden
        }

        .info-card-top {
            background: linear-gradient(135deg, var(--dark2), #1a252f);
            padding: 24px;
            position: relative;
            overflow: hidden
        }

        .info-card-top::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 80% 0%, rgba(230, 126, 34, 0.2), transparent 70%)
        }

        .info-card-top-inner {
            position: relative;
            z-index: 1
        }

        .info-card-top-title {
            font-size: 1rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 4px
        }

        .info-card-top-sub {
            font-size: 0.8rem;
            color: #8fa3b3;
            line-height: 1.5
        }

        .info-card-body {
            padding: 20px
        }

        .contact-info-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px 0;
            border-bottom: 1px solid var(--border)
        }

        .contact-info-item:last-child {
            border-bottom: none;
            padding-bottom: 0
        }

        .contact-info-item:first-child {
            padding-top: 0
        }

        .ci-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0
        }

        .ci-orange {
            background: var(--primary-light)
        }

        .ci-green {
            background: #ecfdf5
        }

        .ci-blue {
            background: #eff6ff
        }

        .ci-purple {
            background: #f5f3ff
        }

        .ci-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 2px
        }

        .ci-value {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--dark2);
            line-height: 1.4
        }

        .hours-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            padding: 20px
        }

        .hours-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--dark2);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 6px
        }

        .hours-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.85rem
        }

        .hours-row:last-child {
            border-bottom: none;
            padding-bottom: 0
        }

        .hours-day {
            color: var(--text-muted);
            font-weight: 500
        }

        .hours-time {
            color: var(--dark2);
            font-weight: 600
        }

        .badge-open {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ecfdf5;
            color: #065f46;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 20px;
            margin-left: 8px
        }

        .badge-open::before {
            content: '';
            width: 6px;
            height: 6px;
            background: var(--success);
            border-radius: 50%;
            animation: blink 1.5s ease-in-out infinite
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: 0.3
            }
        }

        .map-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden
        }

        .map-placeholder {
            height: 160px;
            background: linear-gradient(135deg, #e8f4fd, #dbeafe);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #3b82f6;
            position: relative;
            overflow: hidden
        }

        .map-placeholder::before {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(0deg, rgba(59, 130, 246, 0.06) 0, rgba(59, 130, 246, 0.06) 1px, transparent 1px, transparent 28px), repeating-linear-gradient(90deg, rgba(59, 130, 246, 0.06) 0, rgba(59, 130, 246, 0.06) 1px, transparent 1px, transparent 28px)
        }

        .map-emoji {
            font-size: 2.5rem;
            position: relative;
            z-index: 1
        }

        .map-label {
            font-size: 0.78rem;
            font-weight: 600;
            position: relative;
            z-index: 1;
            color: #1d4ed8
        }

        .map-card-body {
            padding: 14px 16px;
            font-size: 0.82rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px
        }

        .site-footer {
            background: var(--dark);
            color: #6b7280;
            text-align: center;
            padding: 24px 32px;
            font-size: 0.82rem;
            line-height: 1.7
        }

        .site-footer a {
            color: var(--primary)
        }

        .site-footer a:hover {
            color: var(--secondary)
        }
    </style>
</head>

<body>

    <?php include '../includes/navbar.php'; ?>

    <div class="page-header">
        <div class="page-header-bg"></div>
        <div class="page-header-inner">
            <div class="page-header-text">
                <div class="page-breadcrumb">
                    <a href="home.php">Home</a>
                    <span>/</span>
                    <span>Contact</span>
                </div>
                <h1 class="page-title">Get in <span class="page-title-accent">Touch</span></h1>
                <p class="page-sub">Questions, bulk orders, or feedback? We'd love to hear from you.</p>
            </div>
            <div class="page-header-emoji"><i class="fa-solid fa-inbox"></i></div>
        </div>
    </div>

    <div class="page-body">

        <!-- Form -->
        <div>
            <div class="form-card">
                <div class="form-card-header">
                    <div class="form-card-title"><i class="fa-solid fa-envelope"></i> Send Us a Message</div>
                    <div class="form-card-sub">Fill out the form below and we'll respond within 24 hours.</div>
                </div>
                <div class="contact-form">

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <span class="alert-icon"><i class="fa-solid fa-circle-check"></i></span>
                            <span><?= htmlspecialchars($success) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-error">
                            <span class="alert-icon"><i class="fa-solid fa-circle-xmark"></i></span>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="contact.php" novalidate id="contactForm">
                        <div class="form-row">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="name">Full Name <span class="req">*</span></label>
                                <input type="text" id="name" name="name" class="form-control" placeholder="e.g. Juan Dela Cruz" value="<?= htmlspecialchars($prefillName) ?>" required maxlength="150">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="email">Email Address</label>
                                <input type="email" id="email" name="email" class="form-control" placeholder="you@email.com" value="<?= htmlspecialchars($prefillEmail) ?>" maxlength="150">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="phone">Phone Number</label>
                                <input type="tel" id="phone" name="phone" class="form-control" placeholder="e.g. 09XX-XXX-XXXX" maxlength="30">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="subject">Subject</label>
                                <select id="subject" name="subject" class="form-control">
                                    <option value="">— Select a subject —</option>
                                    <option value="Product Inquiry">Product Inquiry</option>
                                    <option value="Bulk Order Inquiry">Bulk Order Inquiry</option>
                                    <option value="Delivery Question">Delivery Question</option>
                                    <option value="Order Issue">Order Issue</option>
                                    <option value="Pricing Question">Pricing Question</option>
                                    <option value="Partnership / Reseller">Partnership / Reseller</option>
                                    <option value="Feedback">Feedback</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="message">Message <span class="req">*</span></label>
                            <textarea id="message" name="message" class="form-control" placeholder="Write your message here…" required maxlength="2000" oninput="updateCounter(this)"></textarea>
                            <div class="char-counter" id="charCounter">0 / 2000</div>
                        </div>
                        <button type="submit" class="btn-submit" id="submitBtn">
                            <span id="submitIcon"><i class="fa-solid fa-upload"></i></span>
                            <span id="submitLabel">Send Message</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="contact-sidebar">
            <div class="info-card">
                <div class="info-card-top">
                    <div class="info-card-top-inner">
                        <div class="info-card-top-title"><i class="fa-solid fa-egg"></i> Hiney's Eggs &amp; Live Chicken</div>
                        <div class="info-card-top-sub">We're a family-run farm in Loreto, Cortes, Bohol. Reach us through any of the channels below.</div>
                    </div>
                </div>
                <div class="info-card-body">
                    <div class="contact-info-item">
                        <div class="ci-icon ci-orange"><i class="fa-solid fa-location-dot"></i></div>
                        <div class="ci-content">
                            <div class="ci-label">Address</div>
                            <div class="ci-value">Loreto Cortes, Bohol 6341</div>
                        </div>
                    </div>
                    <div class="contact-info-item">
                        <div class="ci-icon ci-green"><i class="fa-solid fa-phone"></i></div>
                        <div class="ci-content">
                            <div class="ci-label">Phone / SMS</div>
                            <div class="ci-value">0912-345-6789</div>
                        </div>
                    </div>
                    <div class="contact-info-item">
                        <div class="ci-icon ci-blue"><i class="fa-solid fa-envelope"></i></div>
                        <div class="ci-content">
                            <div class="ci-label">Email</div>
                            <div class="ci-value">hineys.eggs@gmail.com</div>
                        </div>
                    </div>
                    <div class="contact-info-item">
                        <div class="ci-icon ci-purple"><i class="fa-solid fa-comment"></i></div>
                        <div class="ci-content">
                            <div class="ci-label">Facebook</div>
                            <div class="ci-value">fb.com/HineysEggs</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hours-card">
                <div class="hours-title">
                    <i class="fa-solid fa-clock"></i> Business Hours
                    <span class="badge-open">Open Now</span>
                </div>
                <div class="hours-row"><span class="hours-day">Monday – Friday</span><span class="hours-time">6:00 AM – 6:00 PM</span></div>
                <div class="hours-row"><span class="hours-day">Saturday</span><span class="hours-time">6:00 AM – 5:00 PM</span></div>
                <div class="hours-row"><span class="hours-day">Sunday</span><span class="hours-time">7:00 AM – 12:00 PM</span></div>
            </div>

            <div class="map-card">
                <div class="map-placeholder">
                    <div class="map-emoji"><i class="fa-solid fa-map"></i></div>
                    <div class="map-label">Loreto, Cortes, Bohol</div>
                </div>
                <div class="map-card-body"><i class="fa-solid fa-location-dot"></i> We serve customers within Loreto, Cortes, Bohol. Delivery time may vary by barangay.</div>
            </div>
        </div>

    </div>

    <footer class="site-footer">
        &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business &nbsp;·&nbsp;
        Loreto Cortes, Bohol &nbsp;·&nbsp;
        <a href="contact.php">Contact Us</a>
    </footer>

    <script>
        function updateCounter(el) {
            const counter = document.getElementById('charCounter');
            const len = el.value.length;
            counter.textContent = len + ' / 2000';
            counter.classList.toggle('warn', len > 1800);
        }

        document.getElementById('contactForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.style.opacity = '0.75';
            document.getElementById('submitIcon').innerHTML = '<i class="fa-solid fa-clock"></i>';
            document.getElementById('submitLabel').textContent = 'Sending…';
        });

        const successAlert = document.querySelector('.alert-success');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.transition = 'opacity 0.5s ease';
                successAlert.style.opacity = '0';
                setTimeout(() => successAlert.remove(), 520);
            }, 6000);
        }

        (function() {
            const badge = document.querySelector('.badge-open');
            if (!badge) return;
            const now = new Date();
            const day = now.getDay();
            const hour = now.getHours() + now.getMinutes() / 60;
            let isOpen = false;
            if (day >= 1 && day <= 5) isOpen = hour >= 6 && hour < 18;
            else if (day === 6) isOpen = hour >= 6 && hour < 17;
            else if (day === 0) isOpen = hour >= 7 && hour < 12;
            if (!isOpen) {
                badge.style.background = '#fee2e2';
                badge.style.color = '#991b1b';
                badge.textContent = 'Closed';
                badge.style.animation = 'none';
            }
        })();
    </script>
</body>

</html>