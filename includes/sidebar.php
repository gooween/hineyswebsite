<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: includes/sidebar.php
// ============================================================

if (!isset($activePage)) $activePage = '';
if (session_status() === PHP_SESSION_NONE) session_start();

$base = '../';
// If included from a subdirectory inside admin (e.g. admin/stocks/), go up one more level
if (str_contains($_SERVER['PHP_SELF'], '/stocks/')) $base = '../../';
$adminName    = $_SESSION['full_name'] ?? 'Admin';
$adminInitial = strtoupper(substr($adminName, 0, 1));

$navItems = [
    ['key' => 'dashboard',      'icon' => 'fa-gauge-high',          'label' => 'Dashboard',        'href' => 'dashboard.php'],
    ['key' => 'products',       'icon' => 'fa-box-open',            'label' => 'Products',         'href' => 'products.php'],
    ['key' => 'inventory',      'icon' => 'fa-warehouse',           'label' => 'Inventory',        'href' => 'inventory.php'],
    ['key' => 'orders',         'icon' => 'fa-receipt',             'label' => 'Orders',           'href' => 'orders.php'],
    ['key' => 'customers',      'icon' => 'fa-users',               'label' => 'Customers',        'href' => 'customers.php'],
    ['key' => 'transactions',   'icon' => 'fa-money-bill-transfer', 'label' => 'Transactions',     'href' => 'transactions.php'],
    ['key' => 'contacts',       'icon' => 'fa-envelope-open-text',  'label' => 'Messages',         'href' => 'contacts.php'],
    ['key' => 'gcash_settings', 'icon' => 'fa-credit-card',         'label' => 'Payment Settings', 'href' => 'gcash_settings.php'],
];

$stockItems = [
    ['key' => 'stocks',     'icon' => 'fa-boxes-stacked', 'label' => 'Stock Batches', 'href' => 'stocks/index.php'],
    ['key' => 'stocks_add', 'icon' => 'fa-plus-circle',   'label' => 'Add Batch',     'href' => 'stocks/add.php'],
];

$reportItems = [
    ['key' => 'report_sales',     'icon' => 'fa-chart-line',    'label' => 'Sales Report',     'href' => 'report_sales.php'],
    ['key' => 'report_inventory', 'icon' => 'fa-boxes-stacked', 'label' => 'Inventory Report', 'href' => 'report_inventory.php'],
    ['key' => 'report_orders',    'icon' => 'fa-clipboard-list', 'label' => 'Orders Report',    'href' => 'report_orders.php'],
];

$isReportPage = in_array($activePage, ['report_sales', 'report_inventory', 'report_orders']);
$isStockPage  = in_array($activePage, ['stocks', 'stocks_add']);
?>
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

<style>
    :root {
        --sidebar-w: 248px;
        --primary: #e67e22;
        --primary-hover: #f39535;
        --primary-glow: rgba(230, 126, 34, 0.15);
        --sidebar-bg: #111827;
        --sidebar-surface: #1f2937;
        --sidebar-border: rgba(255, 255, 255, 0.06);
        --sidebar-text: #9ca3af;
        --sidebar-text-active: #f9fafb;
        --page-bg: #f8f7f4;
        --card-bg: #ffffff;
        --card-border: #e9e8e4;
        --text: #1f2937;
        --text-muted: #6b7280;
        --dark: #111827;
        --radius: 12px;
        --shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 4px 16px rgba(0, 0, 0, 0.06);
        --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1), 0 12px 32px rgba(0, 0, 0, 0.08);
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0
    }

    body {
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: var(--page-bg);
        color: var(--text);
        line-height: 1.5
    }

    .admin-layout {
        display: flex;
        min-height: 100vh
    }

    .sidebar {
        width: var(--sidebar-w);
        height: 100vh;
        position: fixed;
        top: 0;
        left: 0;
        background: var(--sidebar-bg);
        display: flex;
        flex-direction: column;
        z-index: 800;
        border-right: 1px solid var(--sidebar-border);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)
    }

    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 22px 20px 18px;
        border-bottom: 1px solid var(--sidebar-border);
        text-decoration: none;
        flex-shrink: 0
    }

    .sidebar-brand-logo {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        object-fit: cover;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3)
    }

    .sidebar-brand-text {
        display: flex;
        flex-direction: column
    }

    .sidebar-brand-name {
        font-size: 0.95rem;
        font-weight: 700;
        color: #f9fafb;
        letter-spacing: -0.01em;
        line-height: 1.2
    }

    .sidebar-brand-sub {
        font-size: 0.68rem;
        color: var(--sidebar-text);
        letter-spacing: 0.04em;
        text-transform: uppercase
    }

    .sidebar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 20px;
        border-bottom: 1px solid var(--sidebar-border);
        flex-shrink: 0
    }

    .sidebar-avatar {
        width: 34px;
        height: 34px;
        background: linear-gradient(135deg, var(--primary), #f39c12);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(230, 126, 34, 0.4)
    }

    .sidebar-user-info {
        flex: 1;
        overflow: hidden
    }

    .sidebar-user-name {
        font-size: 0.83rem;
        font-weight: 600;
        color: #f3f4f6;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .sidebar-user-role {
        font-size: 0.68rem;
        color: var(--sidebar-text);
        text-transform: uppercase;
        letter-spacing: 0.05em
    }

    .sidebar-nav {
        flex: 1;
        padding: 10px 12px 8px;
        display: flex;
        flex-direction: column;
        gap: 1px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(255, 255, 255, 0.1) transparent
    }

    .sidebar-nav::-webkit-scrollbar {
        width: 4px
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 2px
    }

    .sidebar-nav-label {
        font-size: 0.62rem;
        font-weight: 700;
        color: #4b5563;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        padding: 10px 8px 5px
    }

    .sidebar-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 12px;
        border-radius: 8px;
        text-decoration: none;
        color: var(--sidebar-text);
        font-size: 0.87rem;
        font-weight: 500;
        transition: background 0.15s, color 0.15s;
        position: relative
    }

    .sidebar-link:hover {
        background: var(--sidebar-surface);
        color: #e5e7eb
    }

    .sidebar-link.active {
        background: var(--primary-glow);
        color: var(--primary-hover);
        font-weight: 600
    }

    .sidebar-link.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 20px;
        background: var(--primary);
        border-radius: 0 3px 3px 0
    }

    .sidebar-link-icon {
        width: 18px;
        text-align: center;
        opacity: 0.7;
        flex-shrink: 0;
        font-size: 0.85rem
    }

    .sidebar-link.active .sidebar-link-icon {
        opacity: 1
    }

    /* Collapsible sections */
    .section-toggle {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 12px;
        border-radius: 8px;
        cursor: pointer;
        color: var(--sidebar-text);
        font-size: 0.87rem;
        font-weight: 500;
        transition: background 0.15s, color 0.15s;
        user-select: none;
        position: relative
    }

    .section-toggle:hover {
        background: var(--sidebar-surface);
        color: #e5e7eb
    }

    .section-toggle.open,
    .section-toggle.has-active {
        color: var(--primary-hover)
    }

    .section-toggle.has-active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 3px;
        height: 20px;
        background: var(--primary);
        border-radius: 0 3px 3px 0
    }

    .section-chevron {
        margin-left: auto;
        width: 14px;
        height: 14px;
        transition: transform 0.2s;
        opacity: 0.5
    }

    .section-toggle.open .section-chevron {
        transform: rotate(180deg)
    }

    .section-sub {
        display: none;
        flex-direction: column;
        gap: 1px;
        padding-left: 14px;
        margin-top: 1px
    }

    .section-sub.open {
        display: flex
    }

    .sidebar-sublink {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-radius: 7px;
        text-decoration: none;
        color: var(--sidebar-text);
        font-size: 0.83rem;
        font-weight: 500;
        transition: background 0.15s, color 0.15s;
        position: relative
    }

    .sidebar-sublink i {
        font-size: 0.75rem;
        width: 16px;
        text-align: center;
        opacity: 0.5
    }

    .sidebar-sublink:hover {
        background: var(--sidebar-surface);
        color: #e5e7eb
    }

    .sidebar-sublink:hover i {
        opacity: 0.8
    }

    .sidebar-sublink.active {
        background: var(--primary-glow);
        color: var(--primary-hover);
        font-weight: 600
    }

    .sidebar-sublink.active i {
        opacity: 1;
        color: var(--primary)
    }

    .sidebar-footer {
        padding: 12px;
        border-top: 1px solid var(--sidebar-border);
        flex-shrink: 0
    }

    .sidebar-logout {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 12px;
        border-radius: 8px;
        text-decoration: none;
        color: #f87171;
        font-size: 0.87rem;
        font-weight: 500;
        transition: background 0.15s
    }

    .sidebar-logout:hover {
        background: rgba(248, 113, 113, 0.1)
    }

    .sidebar-logout i {
        font-size: 0.85rem;
        width: 18px;
        text-align: center
    }

    /* Reusable card styles */
    .card {
        background: var(--card-bg);
        border: 1px solid var(--card-border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden
    }

    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid var(--card-border)
    }

    .card-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--dark);
        display: flex;
        align-items: center;
        gap: 8px
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        white-space: nowrap
    }

    .badge-pending {
        background: #fef3c7;
        color: #92400e
    }

    .badge-confirmed {
        background: #dbeafe;
        color: #1e40af
    }

    .badge-processing {
        background: #ede9fe;
        color: #5b21b6
    }

    .badge-delivery {
        background: #ffedd5;
        color: #9a3412
    }

    .badge-delivered {
        background: #d1fae5;
        color: #065f46
    }

    .badge-cancelled {
        background: #fee2e2;
        color: #991b1b
    }

    .badge-paid {
        background: #d1fae5;
        color: #065f46
    }

    .badge-unpaid {
        background: #fee2e2;
        color: #991b1b
    }

    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 799;
        backdrop-filter: blur(2px)
    }

    @media(max-width:768px) {
        .sidebar {
            transform: translateX(-100%)
        }

        .sidebar.open {
            transform: translateX(0)
        }

        .sidebar-overlay.show {
            display: block
        }
    }
</style>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">

    <a href="<?= $base ?>admin/dashboard.php" class="sidebar-brand">
        <img src="<?= $base ?>assets/images/hineys_logo.png" alt="Hiney's" class="sidebar-brand-logo">
        <div class="sidebar-brand-text">
            <span class="sidebar-brand-name">Hiney's</span>
            <span class="sidebar-brand-sub">Admin Panel</span>
        </div>
    </a>

    <div class="sidebar-user">
        <div class="sidebar-avatar"><?= htmlspecialchars($adminInitial) ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= htmlspecialchars($adminName) ?></div>
            <div class="sidebar-user-role">Administrator</div>
        </div>
    </div>

    <nav class="sidebar-nav">

        <div class="sidebar-nav-label">Main Menu</div>
        <?php foreach ($navItems as $item): ?>
            <a href="<?= $base ?>admin/<?= $item['href'] ?>"
                class="sidebar-link <?= $activePage === $item['key'] ? 'active' : '' ?>">
                <i class="fa-solid <?= $item['icon'] ?> sidebar-link-icon"></i>
                <?= htmlspecialchars($item['label']) ?>
            </a>
        <?php endforeach; ?>

        <!-- Stocks (collapsible) -->
        <div class="sidebar-nav-label" style="margin-top:6px;">Stock Management</div>
        <div class="section-toggle <?= $isStockPage ? 'has-active open' : '' ?>"
            id="stocksToggle" onclick="toggleSection('stocks')">
            <i class="fa-solid fa-layer-group sidebar-link-icon"></i>
            Stocks
            <svg class="section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9" />
            </svg>
        </div>
        <div class="section-sub <?= $isStockPage ? 'open' : '' ?>" id="stocksSub">
            <?php foreach ($stockItems as $si): ?>
                <a href="<?= $base ?>admin/<?= $si['href'] ?>"
                    class="sidebar-sublink <?= $activePage === $si['key'] ? 'active' : '' ?>">
                    <i class="fa-solid <?= $si['icon'] ?>"></i>
                    <?= htmlspecialchars($si['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Reports (collapsible) -->
        <div class="sidebar-nav-label" style="margin-top:6px;">Reports</div>
        <div class="section-toggle <?= $isReportPage ? 'has-active open' : '' ?>"
            id="reportsToggle" onclick="toggleSection('reports')">
            <i class="fa-solid fa-chart-pie sidebar-link-icon"></i>
            Analytics
            <svg class="section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9" />
            </svg>
        </div>
        <div class="section-sub <?= $isReportPage ? 'open' : '' ?>" id="reportsSub">
            <?php foreach ($reportItems as $ri): ?>
                <a href="<?= $base ?>admin/<?= $ri['href'] ?>"
                    class="sidebar-sublink <?= $activePage === $ri['key'] ? 'active' : '' ?>">
                    <i class="fa-solid <?= $ri['icon'] ?>"></i>
                    <?= htmlspecialchars($ri['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

    </nav>

    <div class="sidebar-footer">
        <button onclick="openLogoutConfirm()" class="sidebar-logout" style="width:100%;background:none;border:none;cursor:pointer;text-align:left;">
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </button>
        <button onclick="openChangePassword()" class="sidebar-logout" style="width:100%;background:none;border:none;cursor:pointer;text-align:left;color:#9ca3af;margin-top:2px;">
            <i class="fa-solid fa-key"></i>
            Change Password
        </button>
    </div>

</aside>

<!-- LOGOUT CONFIRMATION OVERLAY -->
<div id="logoutOverlay" style="
    position:fixed;inset:0;background:rgba(0,0,0,0);backdrop-filter:blur(0px);
    z-index:2000;display:none;align-items:center;justify-content:center;
    transition:background 0.3s,backdrop-filter 0.3s;">
    <div id="logoutCard" style="
        background:#fff;border-radius:16px;padding:32px 28px;width:100%;max-width:360px;
        text-align:center;box-shadow:0 24px 64px rgba(0,0,0,0.2);
        transform:scale(0.92) translateY(12px);opacity:0;
        transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.25s ease;margin:16px;">
        <div style="width:60px;height:60px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.6rem;">
            <i class="fa-solid fa-right-from-bracket" style="color:#ef4444;"></i>
        </div>
        <div style="font-size:1.05rem;font-weight:800;color:#111827;margin-bottom:8px;">Log out?</div>
        <div style="font-size:0.85rem;color:#6b7280;line-height:1.6;margin-bottom:24px;">
            Are you sure you want to logout?
        </div>
        <div style="display:flex;gap:10px;">
            <button onclick="closeLogoutConfirm()" style="
                flex:1;padding:10px;border:1.5px solid #e5e7eb;border-radius:9px;
                background:transparent;font-size:0.88rem;font-weight:600;color:#6b7280;
                cursor:pointer;font-family:inherit;transition:all 0.15s;">
                Cancel
            </button>       
            <a href="<?= $base ?>logout.php" id="logoutConfirmBtn" style="
                flex:1;padding:10px;border:none;border-radius:9px;
                background:#ef4444;font-size:0.88rem;font-weight:700;color:#fff;
                cursor:pointer;font-family:inherit;text-decoration:none;
                display:flex;align-items:center;justify-content:center;gap:6px;
                transition:background 0.15s;">
                <i class="fa-solid fa-right-from-bracket"></i> Log Out
            </a>
        </div>
    </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div id="changePwOverlay" style="
    position:fixed;inset:0;background:rgba(0,0,0,0);backdrop-filter:blur(0px);
    z-index:2000;display:none;align-items:center;justify-content:center;
    transition:background 0.3s,backdrop-filter 0.3s;padding:16px;">
    <div id="changePwCard" style="
        background:#fff;border-radius:18px;width:100%;max-width:480px;
        box-shadow:0 24px 64px rgba(0,0,0,0.25);overflow:hidden;
        transform:scale(0.92) translateY(12px);opacity:0;
        transition:transform 0.3s cubic-bezier(0.34,1.56,0.64,1),opacity 0.25s ease;">

        <!-- Header strip (dark, matching left panel of register) -->
        <div style="background:#111827;padding:24px 28px 20px;position:relative;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="font-size:0.68rem;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#f59e0b;margin-bottom:6px;">Account Security</div>
                    <div style="font-size:1.25rem;font-weight:800;color:#f9fafb;letter-spacing:-0.02em;">Change Password</div>
                </div>
                <button onclick="closeChangePassword()" style="width:32px;height:32px;border:none;background:rgba(255,255,255,0.08);border-radius:8px;cursor:pointer;font-size:1.1rem;color:#9ca3af;display:flex;align-items:center;justify-content:center;transition:background 0.15s;" onmouseover="this.style.background='rgba(239,68,68,0.2)';this.style.color='#ef4444'" onmouseout="this.style.background='rgba(255,255,255,0.08)';this.style.color='#9ca3af'">✕</button>
            </div>
        </div>

        <!-- Form body -->
        <form method="POST" action="<?= $base ?>admin/change_password.php" id="changePwForm" style="padding:24px 28px;">
            <div id="changePwError" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 14px;border-radius:9px;font-size:0.82rem;margin-bottom:18px;display:none;align-items:center;gap:8px;"></div>

            <!-- Current password -->
            <div style="margin-bottom:16px;">
                <label style="font-size:0.77rem;font-weight:600;color:#5c4a32;display:block;margin-bottom:6px;letter-spacing:0.02em;">Current Password <span style="color:#c0392b;">*</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#8b6f4e;pointer-events:none;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </span>
                    <input type="password" name="current_password" id="cpCurrent" required placeholder="Your current password"
                        style="width:100%;padding:11px 40px;border:1.5px solid #e8ddd0;border-radius:9px;font-size:0.875rem;font-family:inherit;color:#1c1410;background:#fdf8f0;outline:none;transition:border-color 0.18s,box-shadow 0.18s,background 0.18s;"
                        onfocus="this.style.borderColor='#d97706';this.style.boxShadow='0 0 0 3px rgba(217,119,6,0.1)';this.style.background='#fff'"
                        onblur="this.style.borderColor='#e8ddd0';this.style.boxShadow='none';this.style.background='#fdf8f0'">
                    <button type="button" onclick="cpTogglePw('cpCurrent','cpToggle1')" id="cpToggle1"
                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#8b6f4e;display:flex;align-items:center;padding:3px;"
                        onmouseover="this.style.color='#d97706'" onmouseout="this.style.color='#8b6f4e'">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- New password -->
            <div style="margin-bottom:8px;">
                <label style="font-size:0.77rem;font-weight:600;color:#5c4a32;display:block;margin-bottom:6px;letter-spacing:0.02em;">New Password <span style="color:#c0392b;">*</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#8b6f4e;pointer-events:none;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </span>
                    <input type="password" name="new_password" id="cpNew" required placeholder="Minimum 6 characters"
                        style="width:100%;padding:11px 40px;border:1.5px solid #e8ddd0;border-radius:9px;font-size:0.875rem;font-family:inherit;color:#1c1410;background:#fdf8f0;outline:none;transition:border-color 0.18s,box-shadow 0.18s,background 0.18s;"
                        onfocus="this.style.borderColor='#d97706';this.style.boxShadow='0 0 0 3px rgba(217,119,6,0.1)';this.style.background='#fff'"
                        onblur="this.style.borderColor='#e8ddd0';this.style.boxShadow='none';this.style.background='#fdf8f0'"
                        oninput="cpCheckStrength(this.value);cpCheckMatch()">
                    <button type="button" onclick="cpTogglePw('cpNew','cpToggle2')" id="cpToggle2"
                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#8b6f4e;display:flex;align-items:center;padding:3px;"
                        onmouseover="this.style.color='#d97706'" onmouseout="this.style.color='#8b6f4e'">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </button>
                </div>
                <!-- Strength bar -->
                <div style="height:3px;background:#f0ebe4;border-radius:3px;margin-top:8px;overflow:hidden;">
                    <div id="cpStrengthFill" style="height:100%;border-radius:3px;transition:width 0.25s,background 0.25s;width:0%;"></div>
                </div>
                <div id="cpStrengthText" style="font-size:0.7rem;font-weight:600;margin-top:3px;color:#7a6653;min-height:14px;"></div>
            </div>

            <!-- Confirm password -->
            <div style="margin-bottom:22px;">
                <label style="font-size:0.77rem;font-weight:600;color:#5c4a32;display:block;margin-bottom:6px;letter-spacing:0.02em;">Confirm New Password <span style="color:#c0392b;">*</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#8b6f4e;pointer-events:none;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </span>
                    <input type="password" name="confirm_password" id="cpConfirm" required placeholder="Re-enter new password"
                        style="width:100%;padding:11px 40px;border:1.5px solid #e8ddd0;border-radius:9px;font-size:0.875rem;font-family:inherit;color:#1c1410;background:#fdf8f0;outline:none;transition:border-color 0.18s,box-shadow 0.18s,background 0.18s;"
                        onfocus="this.style.borderColor='#d97706';this.style.boxShadow='0 0 0 3px rgba(217,119,6,0.1)';this.style.background='#fff'"
                        onblur="this.style.borderColor='#e8ddd0';this.style.boxShadow='none';this.style.background='#fdf8f0'"
                        oninput="cpCheckMatch()">
                    <button type="button" onclick="cpTogglePw('cpConfirm','cpToggle3')" id="cpToggle3"
                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#8b6f4e;display:flex;align-items:center;padding:3px;"
                        onmouseover="this.style.color='#d97706'" onmouseout="this.style.color='#8b6f4e'">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </button>
                </div>
                <div id="cpMatchErr" style="display:none;font-size:0.74rem;color:#c0392b;margin-top:4px;align-items:center;gap:4px;">
                    <i class="fa-solid fa-triangle-exclamation"></i> Passwords do not match.
                </div>
                <div id="cpMatchOk" style="display:none;font-size:0.74rem;color:#2d7a4f;margin-top:4px;align-items:center;gap:4px;">
                    <i class="fa-solid fa-circle-check"></i> Passwords match.
                </div>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:10px;">
                <button type="button" onclick="closeChangePassword()" style="flex:1;padding:12px;border:1.5px solid #e8ddd0;border-radius:10px;background:transparent;font-size:0.88rem;font-weight:600;color:#7a6653;cursor:pointer;font-family:inherit;transition:all 0.15s;" onmouseover="this.style.background='#fdf8f0'" onmouseout="this.style.background='transparent'">Cancel</button>
                <button type="submit" id="cpSubmitBtn" style="flex:2;padding:12px;border:none;border-radius:10px;background:#292015;font-size:0.88rem;font-weight:700;color:#fff;cursor:pointer;font-family:inherit;transition:all 0.18s;display:flex;align-items:center;justify-content:center;gap:8px;position:relative;overflow:hidden;" onmouseover="this.style.background='#b45309';this.style.transform='translateY(-1px)';this.style.boxShadow='0 8px 24px rgba(180,83,9,0.35)'" onmouseout="this.style.background='#292015';this.style.transform='none';this.style.boxShadow='none'">
                    <span id="cpBtnText">Update Password</span>
                    <span id="cpBtnSpinner" style="display:none;width:14px;height:14px;border:2px solid rgba(255,255,255,0.3);border-top-color:#fff;border-radius:50%;animation:cpSpin 0.65s linear infinite;"></span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    @keyframes cpSpin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<script>
    function openSidebar() {
        document.getElementById('sidebar').classList.add('open');
        document.getElementById('sidebarOverlay').classList.add('show');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
    }

    function toggleSection(name) {
        const toggle = document.getElementById(name + 'Toggle');
        const sub = document.getElementById(name + 'Sub');
        toggle.classList.toggle('open');
        sub.classList.toggle('open');
    }

    // Logout confirm
    function openLogoutConfirm() {
        const overlay = document.getElementById('logoutOverlay');
        const card = document.getElementById('logoutCard');
        overlay.style.display = 'flex';
        requestAnimationFrame(() => {
            overlay.style.background = 'rgba(0,0,0,0.5)';
            overlay.style.backdropFilter = 'blur(4px)';
            card.style.transform = 'scale(1) translateY(0)';
            card.style.opacity = '1';
        });
    }

    function closeLogoutConfirm() {
        const overlay = document.getElementById('logoutOverlay');
        const card = document.getElementById('logoutCard');
        overlay.style.background = 'rgba(0,0,0,0)';
        overlay.style.backdropFilter = 'blur(0px)';
        card.style.transform = 'scale(0.92) translateY(12px)';
        card.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
        }, 300);
    }
    document.getElementById('logoutOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeLogoutConfirm();
    });

    // Change password
    function openChangePassword() {
        const overlay = document.getElementById('changePwOverlay');
        const card = document.getElementById('changePwCard');
        overlay.style.display = 'flex';
        requestAnimationFrame(() => {
            overlay.style.background = 'rgba(0,0,0,0.55)';
            overlay.style.backdropFilter = 'blur(4px)';
            card.style.transform = 'scale(1) translateY(0)';
            card.style.opacity = '1';
        });
    }

    function closeChangePassword() {
        const overlay = document.getElementById('changePwOverlay');
        const card = document.getElementById('changePwCard');
        overlay.style.background = 'rgba(0,0,0,0)';
        overlay.style.backdropFilter = 'blur(0px)';
        card.style.transform = 'scale(0.92) translateY(12px)';
        card.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
            document.getElementById('changePwForm').reset();
            document.getElementById('changePwError').style.display = 'none';
            document.getElementById('cpStrengthFill').style.width = '0%';
            document.getElementById('cpStrengthText').textContent = '';
            document.getElementById('cpMatchErr').style.display = 'none';
            document.getElementById('cpMatchOk').style.display = 'none';
        }, 300);
    }
    document.getElementById('changePwOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeChangePassword();
    });

    // Eye toggle
    const cpEyeOpen = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
    const cpEyeClosed = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;

    function cpTogglePw(inputId, btnId) {
        const input = document.getElementById(inputId);
        const btn = document.getElementById(btnId);
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = cpEyeClosed;
        } else {
            input.type = 'password';
            btn.innerHTML = cpEyeOpen;
        }
    }

    // Strength meter
    function cpCheckStrength(val) {
        const fill = document.getElementById('cpStrengthFill');
        const text = document.getElementById('cpStrengthText');
        if (!val) {
            fill.style.width = '0%';
            text.textContent = '';
            return;
        }
        let score = 0;
        if (val.length >= 6) score++;
        if (val.length >= 10) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const levels = [{
                pct: '20%',
                bg: '#dc2626',
                label: 'Very weak',
                color: '#dc2626'
            },
            {
                pct: '40%',
                bg: '#d97706',
                label: 'Weak',
                color: '#d97706'
            },
            {
                pct: '60%',
                bg: '#f59e0b',
                label: 'Fair',
                color: '#b45309'
            },
            {
                pct: '80%',
                bg: '#16a34a',
                label: 'Strong',
                color: '#15803d'
            },
            {
                pct: '100%',
                bg: '#047857',
                label: 'Very strong',
                color: '#065f46'
            },
        ];
        const lvl = levels[Math.min(score - 1, 4)] || levels[0];
        fill.style.width = lvl.pct;
        fill.style.background = lvl.bg;
        text.textContent = lvl.label;
        text.style.color = lvl.color;
    }

    // Match check
    function cpCheckMatch() {
        const np = document.getElementById('cpNew').value;
        const cp = document.getElementById('cpConfirm').value;
        const err = document.getElementById('cpMatchErr');
        const ok = document.getElementById('cpMatchOk');
        if (!cp) {
            err.style.display = 'none';
            ok.style.display = 'none';
            return;
        }
        if (np !== cp) {
            err.style.display = 'flex';
            ok.style.display = 'none';
        } else {
            err.style.display = 'none';
            ok.style.display = 'flex';
        }
    }

    document.getElementById('changePwForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const errEl = document.getElementById('changePwError');
        const np = document.getElementById('cpNew').value;
        const cp = document.getElementById('cpConfirm').value;
        if (np.length < 6) {
            errEl.textContent = 'New password must be at least 6 characters.';
            errEl.style.display = 'flex';
            return;
        }
        if (np !== cp) {
            errEl.textContent = 'New passwords do not match.';
            errEl.style.display = 'flex';
            return;
        }
        errEl.style.display = 'none';
        document.getElementById('cpBtnText').textContent = 'Updating…';
        document.getElementById('cpBtnSpinner').style.display = 'inline-block';
        document.getElementById('cpSubmitBtn').disabled = true;
        const fd = new FormData(this);
        const res = await fetch(this.action, {
            method: 'POST',
            body: fd
        });
        const json = await res.json();
        document.getElementById('cpBtnText').textContent = 'Update Password';
        document.getElementById('cpBtnSpinner').style.display = 'none';
        document.getElementById('cpSubmitBtn').disabled = false;
        if (json.success) {
            closeChangePassword();
            const toast = document.createElement('div');
            toast.innerHTML = '<i class="fa-solid fa-circle-check"></i> Password updated successfully';
            toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#047857;color:#fff;padding:13px 20px;border-radius:10px;font-size:0.88rem;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,0.15);display:flex;align-items:center;gap:8px;';
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        } else {
            errEl.textContent = json.message || 'Failed to update password.';
            errEl.style.display = 'flex';
        }
    });
</script>