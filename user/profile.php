<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/profile.php
// ============================================================

session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
require_once __DIR__ . '/../includes/address_helpers.php';
requireCustomer();

$activePage = 'profile';
$uid        = (int)$_SESSION['user_id'];
$cartItems  = cartCount($conn);


// ── Address CRUD (Shopee-style) ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with(($_POST['action'] ?? ''), 'addr_')) {
    $act = $_POST['action'];

    if ($act === 'addr_add' || $act === 'addr_edit') {
        $data = [
            'label'          => trim($_POST['label'] ?? ''),
            'recipient_name' => trim($_POST['recipient_name'] ?? ''),
            'phone'          => trim($_POST['phone'] ?? ''),
            'municipality'   => trim($_POST['municipality'] ?? ''),
            'barangay'       => trim($_POST['barangay'] ?? ''),
            'street_address' => trim($_POST['street_address'] ?? ''),
            'landmark'       => trim($_POST['landmark'] ?? ''),
            'is_default'     => !empty($_POST['is_default']) ? 1 : 0,
        ];
        if ($data['municipality'] === '' || $data['barangay'] === '') {
            redirect('profile.php#addresses', 'error', 'Please select a municipality and barangay.');
        }
        if ($act === 'addr_add') {
            addUserAddress($conn, $uid, $data);
            redirect('profile.php#addresses', 'success', 'Address added.');
        } else {
            $addrId = (int)($_POST['address_id'] ?? 0);
            updateUserAddress($conn, $uid, $addrId, $data);
            if (!empty($_POST['is_default'])) setDefaultAddress($conn, $uid, $addrId);
            redirect('profile.php#addresses', 'success', 'Address updated.');
        }
    }

    if ($act === 'addr_delete') {
        deleteUserAddress($conn, $uid, (int)($_POST['address_id'] ?? 0));
        redirect('profile.php#addresses', 'success', 'Address removed.');
    }

    if ($act === 'addr_default') {
        setDefaultAddress($conn, $uid, (int)($_POST['address_id'] ?? 0));
        redirect('profile.php#addresses', 'success', 'Default address updated.');
    }
}


// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // ── Update profile ────────────────────────────────────────
    if ($action === 'update_profile') {
        $fullName     = trim($_POST['full_name'] ?? '');
        $email        = trim($_POST['email']     ?? '');
        $phone        = trim($_POST['phone']     ?? '');

        if (!$fullName || !$email) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Full name and email are required.';
            header('Location: profile.php');
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Please enter a valid email address.';
            header('Location: profile.php');
            exit;
        }

        // Check email uniqueness (excluding self)
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $chk->bind_param('si', $email, $uid);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'That email address is already in use by another account.';
            header('Location: profile.php');
            exit;
        }
        $chk->close();

        $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?");
        $stmt->bind_param('sssi', $fullName, $email, $phone, $uid);
        $stmt->execute();
        $stmt->close();

        // Refresh session name
        $_SESSION['full_name'] = $fullName;

        $_SESSION['flash_type']    = 'success';
        $_SESSION['flash_message'] = '✓ Profile updated successfully.';
        header('Location: profile.php');
        exit;
    }

    // ── Change password ───────────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'All password fields are required.';
            header('Location: profile.php#password');
            exit;
        }

        if (strlen($new) < 8) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'New password must be at least 8 characters long.';
            header('Location: profile.php#password');
            exit;
        }

        if ($new !== $confirm) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'New passwords do not match.';
            header('Location: profile.php#password');
            exit;
        }

        $row = $conn->query("SELECT password FROM users WHERE id={$uid} LIMIT 1")->fetch_assoc();
        if (!password_verify($current, $row['password'])) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Your current password is incorrect.';
            header('Location: profile.php#password');
            exit;
        }

        $hash = password_hash($new, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si', $hash, $uid);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash_type']    = 'success';
        $_SESSION['flash_message'] = '<i class="fa-solid fa-lock"></i> Password changed successfully.';
        header('Location: profile.php');
        exit;
    }
}

// ── Fetch user data ───────────────────────────────────────────
$user = $conn->query("SELECT * FROM users WHERE id={$uid} LIMIT 1")->fetch_assoc();
$addresses = getUserAddresses($conn, $uid);

// ── Order stats ───────────────────────────────────────────────
$statsRow = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='delivered') AS delivered,
        SUM(status='pending')   AS pending,
        SUM(status='cancelled') AS cancelled,
        COALESCE(SUM(CASE WHEN status != 'cancelled' AND payment_status='paid' THEN total_amount ELSE 0 END), 0) AS total_spent
    FROM orders WHERE user_id={$uid}
")->fetch_assoc();

// ── Recent orders ─────────────────────────────────────────────
$recentOrders = $conn->query("
    SELECT o.id, o.status, o.total_amount, o.payment_method,
           o.payment_status, o.created_at,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    WHERE o.user_id = {$uid}
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
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

        .fa-map-location-dot,
        .fa-map-pin {
            color: #3b82f6;
        }
    </style>
    <title>My Profile — Hiney's Eggs &amp; Live Chicken</title>
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
            --dark: #1a1a2e;
            --dark2: #2c3e50;
            --text: #374151;
            --muted: #6b7280;
            --bg: #faf9f7;
            --card: #ffffff;
            --border: #e5e7eb;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --radius: 14px;
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.06), 0 8px 24px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.10);
            --navbar-h: 68px;
            --t: 0.2s ease;
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


        /* ── Banner ── */
        .page-banner {
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            padding: 40px 0 48px;
            position: relative;
            overflow: hidden;
        }

        .page-banner::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 600px 400px at 70% 50%, rgba(230, 126, 34, 0.12), transparent 70%);
        }

        .page-banner-inner {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 32px;
            position: relative;
            z-index: 1;
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
            color: #f39c12;
        }

        .banner-profile {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .banner-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #f39c12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 800;
            color: #fff;
            box-shadow: 0 4px 20px rgba(230, 126, 34, 0.4);
            flex-shrink: 0;
            border: 3px solid rgba(255, 255, 255, 0.2);
        }

        .banner-info {
            flex: 1;
        }

        .banner-name {
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }

        .banner-email {
            font-size: 0.88rem;
            color: #8fa3b3;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .banner-since {
            font-size: 0.75rem;
            color: #6b7a99;
            margin-top: 3px;
        }

        /* ── Layout ── */
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 32px;
        }

        @media(max-width:600px) {
            .container {
                padding: 0 16px;
            }
        }

        .profile-layout {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 24px;
            padding: 32px 0 64px;
            align-items: start;
        }

        @media(max-width:900px) {
            .profile-layout {
                grid-template-columns: 1fr;
            }
        }

        /* ── Section card ── */
        .section-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            background: #fafafa;
        }

        .section-icon {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .si-orange {
            background: #fef3e8;
        }

        .si-blue {
            background: #eff6ff;
        }

        .si-green {
            background: #ecfdf5;
        }

        .section-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--dark2);
        }

        .section-subtitle {
            font-size: 0.75rem;
            color: var(--muted);
            margin-top: 1px;
        }

        .section-body {
            padding: 22px;
        }

        /* ── Form ── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        @media(max-width:600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group.span-2 {
            grid-column: span 2;
        }

        @media(max-width:600px) {
            .form-group.span-2 {
                grid-column: span 1;
            }
        }

        .form-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--dark2);
        }

        .form-label .req {
            color: var(--danger);
            margin-left: 2px;
        }

        .form-input,
        .form-textarea,
        select.form-input {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 0.88rem;
            font-family: inherit;
            color: var(--text);
            background: #fafafa;
            outline: none;
            transition: border-color var(--t), box-shadow var(--t), background var(--t);
        }

        .form-input:focus,
        .form-textarea:focus,
        select.form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.1);
            background: #fff;
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-hint {
            font-size: 0.72rem;
            color: var(--muted);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 20px;
            border-radius: 9px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-family: inherit;
            transition: all var(--t);
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            box-shadow: 0 4px 14px rgba(230, 126, 34, 0.35);
        }

        .btn-ghost {
            background: transparent;
            border: 1.5px solid var(--border);
            color: var(--muted);
        }

        .btn-ghost:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        /* Password strength indicator */
        .strength-bar {
            display: flex;
            gap: 3px;
            margin-top: 6px;
        }

        .strength-seg {
            height: 3px;
            flex: 1;
            border-radius: 2px;
            background: #e5e7eb;
            transition: background 0.2s;
        }

        .strength-label {
            font-size: 0.72rem;
            margin-top: 4px;
            font-weight: 600;
        }

        /* ── Stat cards ── */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding: 18px 22px;
        }

        .stat-box {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            text-align: center;
        }

        .stat-box-value {
            font-size: 1.6rem;
            font-weight: 900;
            color: var(--dark2);
            letter-spacing: -0.03em;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-box-value.green {
            color: var(--success);
        }

        .stat-box-value.orange {
            color: var(--primary);
        }

        .stat-box-value.red {
            color: var(--danger);
        }

        .stat-box-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }

        /* ── Recent orders ── */
        .orders-mini {}

        .order-mini-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 22px;
            border-bottom: 1px solid #f3f4f6;
            gap: 12px;
            font-size: 0.84rem;
            transition: background var(--t);
        }

        .order-mini-row:last-child {
            border-bottom: none;
        }

        .order-mini-row:hover {
            background: #fafaf8;
        }

        .order-mini-id {
            font-weight: 700;
            color: var(--primary);
            white-space: nowrap;
        }

        .order-mini-date {
            font-size: 0.73rem;
            color: var(--muted);
        }

        .order-mini-total {
            font-weight: 700;
            color: var(--dark2);
            white-space: nowrap;
        }

        .status-dot {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .sd-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .sd-approved {
            background: #dbeafe;
            color: #1e40af;
        }

        .sd-confirmed {
            background: #dbeafe;
            color: #1e40af;
        }

        .sd-processing {
            background: #ede9fe;
            color: #5b21b6;
        }

        .sd-out_for_delivery {
            background: #ffedd5;
            color: #9a3412;
        }

        .sd-delivered {
            background: #d1fae5;
            color: #065f46;
        }

        .sd-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .view-all-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 12px;
            border-top: 1px solid var(--border);
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--primary);
            transition: background var(--t);
        }

        .view-all-link:hover {
            background: var(--primary-light);
        }

        /* ── Password toggle ── */
        .pw-toggle {
            position: relative;
        }

        .pw-toggle .form-input {
            padding-right: 40px;
        }

        .pw-eye {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--muted);
            display: flex;
            align-items: center;
            padding: 2px;
            transition: color var(--t);
        }

        .pw-eye:hover {
            color: var(--primary);
        }

        /* Deactivation danger zone */
        .danger-zone {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .danger-zone-info {}

        .danger-zone-title {
            font-size: 0.88rem;
            font-weight: 700;
            color: #991b1b;
            margin-bottom: 3px;
        }

        .danger-zone-desc {
            font-size: 0.78rem;
            color: #b91c1c;
            line-height: 1.5;
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .site-footer {
            background: #1a1a2e;
            color: #6b7280;
            text-align: center;
            padding: 24px 32px;
            font-size: 0.82rem;
        }

        .site-footer a {
            color: var(--primary);
        }

        /* My Addresses (Shopee-style) */
        .addr-empty {
            text-align: center;
            padding: 36px 20px;
            color: #9c968c;
        }

        .addr-empty i {
            font-size: 2.4rem;
            color: #d9d4cc;
            margin-bottom: 12px;
        }

        .addr-empty p {
            font-size: 0.9rem;
            margin-bottom: 16px;
        }

        .addr-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .addr-card {
            display: flex;
            gap: 14px;
            justify-content: space-between;
            border: 1px solid #ebe8e3;
            border-radius: 12px;
            padding: 16px;
            background: #fff;
            transition: border-color .15s;
        }

        .addr-card.is-default {
            border-color: #e67e22;
            background: #fffaf4;
        }

        .addr-card-main {
            flex: 1;
            min-width: 0;
        }

        .addr-card-top {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }

        .addr-recipient {
            font-weight: 700;
            color: #23201c;
            font-size: 0.95rem;
        }

        .addr-phone {
            color: #6f6a62;
            font-size: 0.85rem;
        }

        .addr-label {
            font-size: 0.68rem;
            font-weight: 700;
            background: #f0eee9;
            color: #6f6a62;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .addr-default-badge {
            font-size: 0.66rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            background: #fef4ea;
            color: #d16b12;
            padding: 3px 9px;
            border-radius: 20px;
            border: 1px solid #f5d9bb;
        }

        .addr-card-line {
            font-size: 0.88rem;
            color: #3a352f;
            line-height: 1.5;
        }

        .addr-card-landmark {
            font-size: 0.78rem;
            color: #9c968c;
            margin-top: 3px;
        }

        .addr-card-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: flex-end;
            flex-shrink: 0;
        }

        .addr-act-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #6f6a62;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px 6px;
            border-radius: 6px;
            font-family: inherit;
            white-space: nowrap;
        }

        .addr-act-btn:hover {
            color: #e67e22;
            background: #fef4ea;
        }

        .addr-act-danger:hover {
            color: #d94f46;
            background: #fbeae9;
        }

        .addr-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(35, 32, 28, 0.5);
            backdrop-filter: blur(3px);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .addr-modal-backdrop.show {
            display: flex;
        }

        .addr-modal {
            background: #fff;
            border-radius: 16px;
            max-width: 560px;
            width: 100%;
            max-height: 90vh;
            overflow: auto;
            box-shadow: 0 24px 60px rgba(35, 32, 28, 0.2);
        }

        .addr-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid #ebe8e3;
            font-weight: 800;
            font-size: 1.05rem;
            color: #23201c;
            position: sticky;
            top: 0;
            background: #fff;
        }

        .addr-modal-close {
            border: none;
            background: #f5f3ef;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            color: #9c968c;
            font-size: 0.9rem;
        }

        .addr-modal-close:hover {
            background: #fbeae9;
            color: #d94f46;
        }

        .addr-modal-body {
            padding: 20px 22px;
        }

        .addr-modal-foot {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding: 16px 22px;
            border-top: 1px solid #ebe8e3;
        }
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
                    <span>›</span>
                    <span>My Profile</span>
                </div>
                <div class="banner-profile">
                    <div class="banner-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
                    <div class="banner-info">
                        <div class="banner-name"><?= htmlspecialchars($user['full_name']) ?></div>
                        <div class="banner-email">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#8fa3b3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                                <polyline points="22,6 12,13 2,6" />
                            </svg>
                            <?= htmlspecialchars($user['email']) ?>
                        </div>
                        <div class="banner-since">Member since <?= date('F Y', strtotime($user['created_at'])) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <div class="profile-layout">

                <!-- LEFT COLUMN -->
                <div>
                    <?= flash() ?>

                    <!-- Edit Profile -->
                    <div class="section-card">
                        <div class="section-header">
                            <div class="section-icon si-orange"><i class="fa-solid fa-user"></i></div>
                            <div>
                                <div class="section-title">Personal Information</div>
                                <div class="section-subtitle">Update your name, email, and phone</div>
                            </div>
                        </div>
                        <div class="section-body">
                            <form method="POST" action="profile.php">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Full Name <span class="req">*</span></label>
                                        <input type="text" name="full_name" class="form-input"
                                            value="<?= htmlspecialchars($user['full_name']) ?>"
                                            placeholder="Your full name" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Email Address <span class="req">*</span></label>
                                        <input type="email" name="email" class="form-input"
                                            value="<?= htmlspecialchars($user['email']) ?>"
                                            placeholder="you@example.com" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Phone Number</label>
                                        <input type="text" name="phone" class="form-input"
                                            value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                            placeholder="e.g. 09171234567">
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                                            <polyline points="17 21 17 13 7 13 7 21" />
                                            <polyline points="7 3 7 8 15 8" />
                                        </svg>
                                        Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- My Addresses (Shopee-style) -->
                    <div class="section-card" id="addresses">
                        <div class="section-header">
                            <div class="section-icon si-orange"><i class="fa-solid fa-location-dot"></i></div>
                            <div>
                                <div class="section-title">My Addresses</div>
                                <div class="section-subtitle">Manage your delivery addresses — set a default, add, edit, or remove</div>
                            </div>
                            <button type="button" class="btn btn-primary" style="margin-left:auto;" onclick="openAddrModal('add')">
                                <i class="fa-solid fa-plus"></i> Add New Address
                            </button>
                        </div>
                        <div class="section-body">
                            <?php if (empty($addresses)): ?>
                                <div class="addr-empty">
                                    <i class="fa-solid fa-map-location-dot"></i>
                                    <p>No addresses yet. Add your first delivery address to check out faster.</p>
                                    <button type="button" class="btn btn-primary" onclick="openAddrModal('add')"><i class="fa-solid fa-plus"></i> Add Address</button>
                                </div>
                            <?php else: ?>
                                <div class="addr-list">
                                    <?php foreach ($addresses as $ad): ?>
                                        <div class="addr-card <?= $ad['is_default'] ? 'is-default' : '' ?>">
                                            <div class="addr-card-main">
                                                <div class="addr-card-top">
                                                    <span class="addr-recipient"><?= htmlspecialchars($ad['recipient_name'] ?: $user['full_name']) ?></span>
                                                    <?php if ($ad['phone']): ?><span class="addr-phone">· <?= htmlspecialchars($ad['phone']) ?></span><?php endif; ?>
                                                    <?php if ($ad['label']): ?><span class="addr-label"><?= htmlspecialchars($ad['label']) ?></span><?php endif; ?>
                                                    <?php if ($ad['is_default']): ?><span class="addr-default-badge">Default</span><?php endif; ?>
                                                </div>
                                                <div class="addr-card-line"><?= htmlspecialchars(formatAddress($ad)) ?></div>
                                                <?php if ($ad['landmark']): ?><div class="addr-card-landmark">Landmark: <?= htmlspecialchars($ad['landmark']) ?></div><?php endif; ?>
                                            </div>
                                            <div class="addr-card-actions">
                                                <button type="button" class="addr-act-btn" onclick='openAddrModal("edit", <?= json_encode($ad) ?>)'><i class="fa-solid fa-pen"></i> Edit</button>
                                                <?php if (!$ad['is_default']): ?>
                                                    <form method="POST" action="profile.php" style="display:inline;">
                                                        <input type="hidden" name="action" value="addr_default">
                                                        <input type="hidden" name="address_id" value="<?= $ad['id'] ?>">
                                                        <button type="submit" class="addr-act-btn"><i class="fa-solid fa-star"></i> Set Default</button>
                                                    </form>
                                                    <form method="POST" action="profile.php" style="display:inline;" onsubmit="return confirm('Remove this address?');">
                                                        <input type="hidden" name="action" value="addr_delete">
                                                        <input type="hidden" name="address_id" value="<?= $ad['id'] ?>">
                                                        <button type="submit" class="addr-act-btn addr-act-danger"><i class="fa-solid fa-trash"></i> Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="section-card" id="password">
                        <div class="section-header">
                            <div class="section-icon si-blue"><i class="fa-solid fa-lock"></i></div>
                            <div>
                                <div class="section-title">Change Password</div>
                                <div class="section-subtitle">Keep your account secure with a strong password</div>
                            </div>
                        </div>
                        <div class="section-body">
                            <form method="POST" action="profile.php" id="pwForm">
                                <input type="hidden" name="action" value="change_password">
                                <div class="form-grid">
                                    <div class="form-group span-2">
                                        <label class="form-label">Current Password <span class="req">*</span></label>
                                        <div class="pw-toggle">
                                            <input type="password" name="current_password" id="pw_current"
                                                class="form-input" placeholder="Enter current password" required>
                                            <button type="button" class="pw-eye" onclick="togglePw('pw_current',this)" tabindex="-1">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                    <circle cx="12" cy="12" r="3" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">New Password <span class="req">*</span></label>
                                        <div class="pw-toggle">
                                            <input type="password" name="new_password" id="pw_new"
                                                class="form-input" placeholder="Min. 8 characters"
                                                required oninput="checkStrength(this.value)">
                                            <button type="button" class="pw-eye" onclick="togglePw('pw_new',this)" tabindex="-1">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                    <circle cx="12" cy="12" r="3" />
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="strength-bar">
                                            <div class="strength-seg" id="seg1"></div>
                                            <div class="strength-seg" id="seg2"></div>
                                            <div class="strength-seg" id="seg3"></div>
                                            <div class="strength-seg" id="seg4"></div>
                                        </div>
                                        <div class="strength-label" id="strengthLabel" style="color:var(--muted);">Enter a password</div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Confirm New Password <span class="req">*</span></label>
                                        <div class="pw-toggle">
                                            <input type="password" name="confirm_password" id="pw_confirm"
                                                class="form-input" placeholder="Repeat new password"
                                                required oninput="checkMatch()">
                                            <button type="button" class="pw-eye" onclick="togglePw('pw_confirm',this)" tabindex="-1">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                    <circle cx="12" cy="12" r="3" />
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="form-hint" id="matchHint"></div>
                                    </div>
                                </div>
                                <div class="form-actions" style="gap:10px;">
                                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('pwForm').reset();resetStrength();">Cancel</button>
                                    <button type="submit" class="btn btn-primary">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                        </svg>
                                        Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>

                <!-- RIGHT COLUMN -->
                <div>

                    <!-- Order stats -->
                    <div class="section-card" style="margin-bottom:20px;">
                        <div class="section-header">
                            <div class="section-icon si-green"><i class="fa-solid fa-box"></i></div>
                            <div>
                                <div class="section-title">Order Summary</div>
                                <div class="section-subtitle">Your order activity at a glance</div>
                            </div>
                        </div>
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-box-value"><?= (int)$statsRow['total'] ?></div>
                                <div class="stat-box-label">Total Orders</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-box-value green"><?= (int)$statsRow['delivered'] ?></div>
                                <div class="stat-box-label">Delivered</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-box-value orange"><?= (int)$statsRow['pending'] ?></div>
                                <div class="stat-box-label">Pending</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-box-value red"><?= (int)$statsRow['cancelled'] ?></div>
                                <div class="stat-box-label">Cancelled</div>
                            </div>
                        </div>
                        <div style="padding:0 22px 16px;">
                            <div style="background:var(--primary-light);border-radius:10px;padding:14px 16px;display:flex;align-items:center;justify-content:space-between;">
                                <div style="font-size:0.78rem;font-weight:700;color:var(--primary-dark);text-transform:uppercase;letter-spacing:0.07em;">Total Spent</div>
                                <div style="font-size:1.3rem;font-weight:900;color:var(--primary);">₱<?= number_format((float)$statsRow['total_spent'], 2) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent orders -->
                    <div class="section-card">
                        <div class="section-header">
                            <div class="section-icon si-orange"><i class="fa-solid fa-cart-shopping"></i></div>
                            <div>
                                <div class="section-title">Recent Orders</div>
                                <div class="section-subtitle">Your last 5 orders</div>
                            </div>
                        </div>
                        <div class="orders-mini">
                            <?php
                            $hasOrders = false;
                            while ($row = $recentOrders->fetch_assoc()):
                                $hasOrders = true;
                                $sid = $row['status'];
                                $statusLabel = $sid === 'out_for_delivery' ? 'On the Way' : ucwords(str_replace('_', ' ', $sid));
                            ?>
                                <div class="order-mini-row">
                                    <div>
                                        <div class="order-mini-id">#<?= str_pad($row['id'], 4, '0', STR_PAD_LEFT) ?></div>
                                        <div class="order-mini-date"><?= date('M j, Y', strtotime($row['created_at'])) ?> · <?= (int)$row['item_count'] ?> item<?= (int)$row['item_count'] !== 1 ? 's' : '' ?></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="order-mini-total">₱<?= number_format((float)$row['total_amount'], 2) ?></div>
                                        <span class="status-dot sd-<?= $sid ?>"><?= $statusLabel ?></span>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            <?php if (!$hasOrders): ?>
                                <div style="text-align:center;padding:32px 20px;color:var(--muted);font-size:0.88rem;">
                                    <div style="font-size:2.5rem;margin-bottom:10px;"><i class="fa-solid fa-cart-shopping"></i></div>
                                    No orders yet.
                                </div>
                            <?php endif; ?>
                        </div>
                        <a href="orders.php" class="view-all-link">
                            View all orders
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="9 18 15 12 9 6" />
                            </svg>
                        </a>
                    </div>

                    <!-- Logout -->
                    <div class="section-card" style="margin-top:20px;">
                        <div class="section-body" style="padding:18px 22px;">
                            <div style="display:flex;align-items:center;gap:14px;">
                                <div style="width:40px;height:40px;border-radius:10px;background:#fef2f2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                        <polyline points="16 17 21 12 16 7" />
                                        <line x1="21" y1="12" x2="9" y2="12" />
                                    </svg>
                                </div>
                                <div style="flex:1;">
                                    <div style="font-size:0.88rem;font-weight:700;color:#991b1b;">Sign Out</div>
                                    <div style="font-size:0.75rem;color:#b91c1c;">End your current session</div>
                                </div>
                                <a href="../logout.php"
                                    onclick="return confirm('Are you sure you want to log out?')"
                                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fef2f2;border:1.5px solid #fecaca;color:#991b1b;border-radius:8px;font-size:0.82rem;font-weight:700;text-decoration:none;transition:all 0.2s;white-space:nowrap;"
                                    onmouseover="this.style.background='#ef4444';this.style.color='#fff';this.style.borderColor='#ef4444'"
                                    onmouseout="this.style.background='#fef2f2';this.style.color='#991b1b';this.style.borderColor='#fecaca'">
                                    Log Out
                                </a>
                            </div>
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

    <!-- ADDRESS ADD/EDIT MODAL -->
    <div class="addr-modal-backdrop" id="addrModal" onclick="if(event.target===this)closeAddrModal()">
        <div class="addr-modal">
            <div class="addr-modal-head">
                <span id="addrModalTitle">Add New Address</span>
                <button type="button" class="addr-modal-close" onclick="closeAddrModal()">✕</button>
            </div>
            <form method="POST" action="profile.php">
                <input type="hidden" name="action" id="addrFormAction" value="addr_add">
                <input type="hidden" name="address_id" id="addrFormId" value="">
                <div class="addr-modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Full Name <span class="req">*</span></label>
                            <input type="text" name="recipient_name" id="af_recipient" class="form-input" placeholder="Recipient name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone <span class="req">*</span></label>
                            <input type="text" name="phone" id="af_phone" class="form-input" placeholder="e.g. 09171234567" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Municipality / City <span class="req">*</span></label>
                            <select name="municipality" id="af_muni" class="form-input" onchange="afLoadBrgy(this.value)" required>
                                <option value="">Select municipality…</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Barangay <span class="req">*</span></label>
                            <select name="barangay" id="af_brgy" class="form-input" required>
                                <option value="">Select barangay…</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Street / House No. / Purok</label>
                            <input type="text" name="street_address" id="af_street" class="form-input" placeholder="e.g. Purok 3, House No. 12">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Landmark (optional)</label>
                            <input type="text" name="landmark" id="af_landmark" class="form-input" placeholder="e.g. near the chapel">
                        </div>
                        <div class="form-group span-2">
                            <label class="form-label">Label (optional)</label>
                            <input type="text" name="label" id="af_label" class="form-input" placeholder="e.g. Home, Work">
                        </div>
                        <div class="form-group span-2">
                            <label style="display:flex;align-items:center;gap:8px;font-size:0.88rem;cursor:pointer;">
                                <input type="checkbox" name="is_default" id="af_default" value="1" style="width:16px;height:16px;">
                                Set as default address
                            </label>
                        </div>
                    </div>
                </div>
                <div class="addr-modal-foot">
                    <button type="button" class="btn" style="background:#f0eee9;color:#6f6a62;" onclick="closeAddrModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Address</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ── My Addresses modal ──────────────────────────────────
        var AF_MUNI_LOADED = false;

        function afLoadMunis(cb) {
            var sel = document.getElementById('af_muni');
            if (AF_MUNI_LOADED) {
                if (cb) cb();
                return;
            }
            fetch('get_delivery_zones.php?action=municipalities')
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    if (d.ok) d.municipalities.forEach(function(m) {
                        var o = document.createElement('option');
                        o.value = m;
                        o.textContent = m;
                        sel.appendChild(o);
                    });
                    AF_MUNI_LOADED = true;
                    if (cb) cb();
                }).catch(function() {
                    if (cb) cb();
                });
        }

        function afLoadBrgy(muni, preselect, cb) {
            var sel = document.getElementById('af_brgy');
            sel.innerHTML = '<option value="">Select barangay…</option>';
            if (!muni) {
                if (cb) cb();
                return;
            }
            fetch('get_delivery_zones.php?action=barangays&m=' + encodeURIComponent(muni))
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    if (d.ok) d.barangays.forEach(function(b) {
                        var o = document.createElement('option');
                        o.value = b.barangay;
                        o.textContent = b.barangay;
                        if (preselect && b.barangay === preselect) o.selected = true;
                        sel.appendChild(o);
                    });
                    if (cb) cb();
                }).catch(function() {
                    if (cb) cb();
                });
        }

        function openAddrModal(mode, data) {
            var m = document.getElementById('addrModal');
            document.getElementById('addrModalTitle').textContent = (mode === 'edit') ? 'Edit Address' : 'Add New Address';
            document.getElementById('addrFormAction').value = (mode === 'edit') ? 'addr_edit' : 'addr_add';
            document.getElementById('addrFormId').value = (mode === 'edit' && data) ? data.id : '';
            // reset
            document.getElementById('af_recipient').value = (data && data.recipient_name) ? data.recipient_name : '';
            document.getElementById('af_phone').value = (data && data.phone) ? data.phone : '';
            document.getElementById('af_street').value = (data && data.street_address) ? data.street_address : '';
            document.getElementById('af_landmark').value = (data && data.landmark) ? data.landmark : '';
            document.getElementById('af_label').value = (data && data.label) ? data.label : '';
            document.getElementById('af_default').checked = (data && String(data.is_default) === '1');
            m.classList.add('show');
            afLoadMunis(function() {
                if (mode === 'edit' && data) {
                    document.getElementById('af_muni').value = data.municipality || '';
                    afLoadBrgy(data.municipality || '', data.barangay || '');
                } else {
                    document.getElementById('af_muni').value = '';
                    document.getElementById('af_brgy').innerHTML = '<option value="">Select barangay…</option>';
                }
            });
        }

        function closeAddrModal() {
            document.getElementById('addrModal').classList.remove('show');
        }

        // ── Password visibility toggle ─────────────────────────────────
        function togglePw(inputId, btn) {
            const input = document.getElementById(inputId);
            const isText = input.type === 'text';
            input.type = isText ? 'password' : 'text';
            btn.style.color = isText ? '' : 'var(--primary)';
        }

        // ── Password strength ──────────────────────────────────────────
        function checkStrength(val) {
            const segs = [1, 2, 3, 4].map(i => document.getElementById('seg' + i));
            const label = document.getElementById('strengthLabel');
            let score = 0;
            if (val.length >= 8) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            const configs = [{
                    color: '#e5e7eb',
                    text: 'Enter a password',
                    clr: 'var(--muted)'
                },
                {
                    color: '#ef4444',
                    text: 'Weak',
                    clr: '#ef4444'
                },
                {
                    color: '#f59e0b',
                    text: 'Fair',
                    clr: '#f59e0b'
                },
                {
                    color: '#3b82f6',
                    text: 'Good',
                    clr: '#3b82f6'
                },
                {
                    color: '#10b981',
                    text: 'Strong',
                    clr: '#10b981'
                },
            ];

            const cfg = configs[score];
            segs.forEach((seg, i) => {
                seg.style.background = i < score ? cfg.color : '#e5e7eb';
            });
            if (val.length === 0) {
                label.textContent = configs[0].text;
                label.style.color = configs[0].clr;
            } else {
                label.textContent = cfg.text;
                label.style.color = cfg.clr;
            }
        }

        function resetStrength() {
            [1, 2, 3, 4].forEach(i => document.getElementById('seg' + i).style.background = '#e5e7eb');
            const label = document.getElementById('strengthLabel');
            label.textContent = 'Enter a password';
            label.style.color = 'var(--muted)';
            document.getElementById('matchHint').textContent = '';
        }

        // ── Confirm-password match check ───────────────────────────────
        function checkMatch() {
            const np = document.getElementById('pw_new').value;
            const cp = document.getElementById('pw_confirm').value;
            const hint = document.getElementById('matchHint');
            if (cp.length === 0) {
                hint.textContent = '';
                return;
            }
            if (np === cp) {
                hint.textContent = '✓ Passwords match';
                hint.style.color = 'var(--success)';
            } else {
                hint.textContent = '✗ Passwords do not match';
                hint.style.color = 'var(--danger)';
            }
        }
    </script>
</body>

</html>