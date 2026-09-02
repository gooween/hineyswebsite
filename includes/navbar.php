<?php
// ============================================================
// HATCH — Hiney's Automated Tracking Commerce and Hub
// File: includes/navbar.php
// ============================================================

if (!isset($activePage)) $activePage = '';
if (session_status() === PHP_SESSION_NONE) session_start();

$isLoggedIn  = !empty($_SESSION['user_id']);
$userName    = $_SESSION['full_name'] ?? '';
$userInitial = $isLoggedIn ? strtoupper(substr($userName, 0, 1)) : '';

$cartItems = 0;
if ($isLoggedIn && isset($conn) && function_exists('cartCount')) {
    $cartItems = cartCount($conn);
}

$base = '../';
?>
<link rel="icon" type="image/png" href="<?= $base ?>assets/images/hineys_logo.png">
<link rel="apple-touch-icon" href="<?= $base ?>assets/images/hineys_logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700;750;800&display=swap" rel="stylesheet">
<style id="hineys-icon-colors">
    /* Icons inherit their parent's color inside buttons, badges, chips, etc. */
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

    /* Standalone semantic icons — toned to the calmer admin palette */
    .fa-egg {
        color: #e67e22
    }

    .fa-drumstick-bite {
        color: #b06a35
    }

    .fa-circle-check,
    .fa-check,
    .fa-shield-halved,
    .fa-leaf,
    .fa-seedling,
    .fa-phone {
        color: #2f9e60
    }

    .fa-circle-xmark,
    .fa-xmark,
    .fa-trash,
    .fa-ban,
    .fa-location-dot {
        color: #d94f46
    }

    .fa-cart-shopping,
    .fa-bag-shopping,
    .fa-store,
    .fa-shop {
        color: #e67e22
    }

    .fa-truck {
        color: #d98a17
    }

    .fa-triangle-exclamation,
    .fa-circle-exclamation,
    .fa-clock,
    .fa-star {
        color: #d98a17
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
        color: #3b7dd8
    }

    .fa-sack-dollar,
    .fa-money-bill,
    .fa-money-bill-transfer {
        color: #2f9e60
    }

    .fa-users,
    .fa-user,
    .fa-user-plus {
        color: #6a5bc0
    }

    .fa-box,
    .fa-box-open,
    .fa-boxes-stacked,
    .fa-warehouse,
    .fa-receipt,
    .fa-clipboard-list,
    .fa-file-lines {
        color: #6a5bc0
    }

    .fa-chart-bar,
    .fa-chart-line,
    .fa-chart-pie,
    .fa-gauge-high {
        color: #3b7dd8
    }

    .fa-heart {
        color: #d94f46
    }

    .fa-gear {
        color: #6f6a62
    }

    .fa-lightbulb {
        color: #d98a17
    }
</style>

<style>
    :root {
        /* Aligned to the admin design system (warm bone-white + orange) */
        --primary: #e67e22;
        --primary-dark: #d16b12;
        --primary-bg: #fef4ea;
        --dark: #23201c;
        --text: #23201c;
        --muted: #6f6a62;
        --border: #ebe8e3;
        --border-strong: #ddd8d0;
        --bg: #f7f6f3;
        --surface: #ffffff;
        --danger: #d94f46;
        --radius: 12px;
        --radius-sm: 8px;
        --nav-h: 64px;
        --shadow-nav: 0 1px 0 #ebe8e3, 0 6px 20px -8px rgba(35, 32, 28, 0.10);
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0
    }

    body {
        font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
        background: var(--bg);
        color: var(--text);
        -webkit-font-smoothing: antialiased;
        font-feature-settings: "cv02", "cv03", "cv04", "cv11"
    }

    .navbar {
        position: sticky;
        top: 0;
        height: var(--nav-h);
        background: var(--surface);
        display: flex;
        align-items: center;
        padding: 0 28px;
        gap: 8px;
        box-shadow: var(--shadow-nav);
        z-index: 1000
    }

    .nav-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        margin-right: 16px;
        flex-shrink: 0
    }

    .nav-brand-logo {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        object-fit: cover;
        box-shadow: 0 2px 8px rgba(35, 32, 28, 0.10)
    }

    .nav-brand-text {
        line-height: 1.2
    }

    .nav-brand-name {
        font-size: 1rem;
        font-weight: 800;
        color: var(--dark);
        letter-spacing: -0.01em
    }

    .nav-brand-sub {
        font-size: 0.64rem;
        color: var(--muted);
        letter-spacing: 0.04em;
        text-transform: uppercase;
        font-weight: 600
    }

    .nav-links {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        list-style: none;
        gap: 2px
    }

    .nav-links a {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        text-decoration: none;
        color: var(--muted);
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 550;
        transition: background 0.15s, color 0.15s;
        white-space: nowrap
    }

    .nav-links a i {
        font-size: 0.85rem
    }

    .nav-links a:hover {
        background: var(--primary-bg);
        color: var(--primary)
    }

    .nav-links a.active {
        background: var(--primary-bg);
        color: var(--primary);
        font-weight: 650
    }

    .nav-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-left: 16px
    }

    .nav-cart {
        position: relative;
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 7px 14px;
        background: var(--primary-bg);
        border-radius: var(--radius-sm);
        text-decoration: none;
        color: var(--primary);
        font-size: 0.875rem;
        font-weight: 650;
        transition: background 0.15s
    }

    .nav-cart:hover {
        background: #fde8d4
    }

    .cart-badge {
        min-width: 18px;
        height: 18px;
        background: var(--danger);
        color: #fff;
        font-size: 0.65rem;
        font-weight: 700;
        border-radius: 9px;
        padding: 0 5px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #fff;
        position: absolute;
        top: -5px;
        right: -5px
    }

    .cart-badge.hidden {
        display: none
    }

    .user-chip {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 5px 14px 5px 6px;
        border: 1px solid var(--border-strong);
        border-radius: 24px;
        text-decoration: none;
        font-size: 0.85rem;
        color: var(--text);
        font-weight: 550;
        transition: border-color 0.15s, background 0.15s
    }

    .user-chip:hover {
        border-color: var(--primary);
        background: var(--primary-bg);
        color: var(--primary)
    }

    .user-avatar {
        width: 28px;
        height: 28px;
        background: linear-gradient(135deg, var(--primary), #f0a340);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: 0.75rem;
        font-weight: 700;
        flex-shrink: 0
    }

    .btn-login {
        padding: 7px 18px;
        background: var(--primary);
        color: #fff;
        border-radius: var(--radius-sm);
        text-decoration: none;
        font-size: 0.875rem;
        font-weight: 650;
        transition: background 0.15s;
        display: flex;
        align-items: center;
        gap: 6px
    }

    .btn-login:hover {
        background: var(--primary-dark)
    }

    .hamburger {
        display: none;
        flex-direction: column;
        justify-content: center;
        gap: 5px;
        width: 36px;
        height: 36px;
        padding: 6px;
        background: none;
        border: none;
        border-radius: var(--radius-sm);
        cursor: pointer;
        margin-left: auto
    }

    .hamburger:hover {
        background: var(--bg)
    }

    .hamburger span {
        display: block;
        width: 18px;
        height: 2px;
        background: var(--dark);
        border-radius: 2px;
        transition: transform 0.2s
    }

    .mobile-drawer {
        display: none;
        position: fixed;
        top: var(--nav-h);
        left: 0;
        right: 0;
        background: var(--surface);
        border-bottom: 1px solid var(--border);
        padding: 8px 16px 16px;
        box-shadow: 0 16px 40px -12px rgba(35, 32, 28, 0.14);
        z-index: 999
    }

    .mobile-drawer.open {
        display: block
    }

    .mobile-drawer a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 11px 12px;
        text-decoration: none;
        color: var(--text);
        font-size: 0.9rem;
        font-weight: 550;
        border-radius: var(--radius-sm);
        transition: background 0.15s
    }

    .mobile-drawer a i {
        width: 18px;
        text-align: center;
        color: var(--muted)
    }

    .mobile-drawer a:hover,
    .mobile-drawer a.active {
        background: var(--primary-bg);
        color: var(--primary)
    }

    .mobile-drawer a.active i {
        color: var(--primary)
    }

    .mobile-drawer-divider {
        height: 1px;
        background: var(--border);
        margin: 8px 0
    }

    @media(max-width:860px) {

        .nav-links,
        .nav-actions {
            display: none
        }

        .hamburger {
            display: flex
        }

        .navbar {
            padding: 0 16px
        }
    }
</style>

<nav class="navbar">
    <a href="<?= $base ?>user/home.php" class="nav-brand">
        <img src="<?= $base ?>assets/images/hineys_logo.png" alt="Hiney's" class="nav-brand-logo">
        <div class="nav-brand-text">
            <div class="nav-brand-name">HATCH</div>
            <div class="nav-brand-sub">Hiney's Automated Tracking Commerce and Hub</div>
        </div>
    </a>

    <ul class="nav-links">
        <li><a class="<?= $activePage == 'home' ? 'active' : '' ?>" href="<?= $base ?>user/home.php"><i class="fa-solid fa-house"></i> Home</a></li>
        <li><a class="<?= $activePage == 'products' ? 'active' : '' ?>" href="<?= $base ?>user/products.php"><i class="fa-solid fa-store"></i> Products</a></li>
        <?php if ($isLoggedIn): ?>
            <li><a class="<?= $activePage == 'orders' ? 'active' : '' ?>" href="<?= $base ?>user/orders.php"><i class="fa-solid fa-box"></i> My Orders</a></li>
        <?php endif; ?>
        <li><a class="<?= $activePage == 'about' ? 'active' : '' ?>" href="<?= $base ?>user/about.php"><i class="fa-solid fa-info-circle"></i> About</a></li>
        <li><a class="<?= $activePage == 'contact' ? 'active' : '' ?>" href="<?= $base ?>user/contact.php"><i class="fa-solid fa-envelope"></i> Contact</a></li>
    </ul>

    <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
            <a class="nav-cart" href="<?= $base ?>user/cart.php">
                <i class="fa-solid fa-cart-shopping"></i>
                Cart
                <span class="cart-badge <?= $cartItems == 0 ? 'hidden' : '' ?>" id="cartBadge"><?= $cartItems ?></span>
            </a>
            <a class="user-chip" href="<?= $base ?>user/profile.php">
                <div class="user-avatar"><?= htmlspecialchars($userInitial) ?></div>
                <?= htmlspecialchars($userName) ?>
            </a>
        <?php else: ?>
            <a class="btn-login" href="<?= $base ?>index.php?login=1">
                <i class="fa-solid fa-right-to-bracket"></i> Login
            </a>
        <?php endif; ?>
    </div>

    <button class="hamburger" onclick="toggleDrawer(this)">
        <span></span><span></span><span></span>
    </button>
</nav>

<div class="mobile-drawer" id="mobileDrawer">
    <a class="<?= $activePage == 'home' ? 'active' : '' ?>" href="<?= $base ?>user/home.php"><i class="fa-solid fa-house"></i> Home</a>
    <a class="<?= $activePage == 'products' ? 'active' : '' ?>" href="<?= $base ?>user/products.php"><i class="fa-solid fa-store"></i> Products</a>
    <a class="<?= $activePage == 'about' ? 'active' : '' ?>" href="<?= $base ?>user/about.php"><i class="fa-solid fa-info-circle"></i> About</a>
    <a class="<?= $activePage == 'contact' ? 'active' : '' ?>" href="<?= $base ?>user/contact.php"><i class="fa-solid fa-envelope"></i> Contact</a>
    <?php if ($isLoggedIn): ?>
        <div class="mobile-drawer-divider"></div>
        <a class="<?= $activePage == 'orders' ? 'active' : '' ?>" href="<?= $base ?>user/orders.php"><i class="fa-solid fa-box"></i> My Orders</a>
        <a href="<?= $base ?>user/cart.php"><i class="fa-solid fa-cart-shopping"></i> Cart <?= $cartItems > 0 ? "($cartItems)" : '' ?></a>
        <a href="<?= $base ?>user/profile.php"><i class="fa-solid fa-user"></i> My Profile</a>
    <?php else: ?>
        <div class="mobile-drawer-divider"></div>
        <a href="<?= $base ?>index.php?login=1"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
        <a href="<?= $base ?>register.php"><i class="fa-solid fa-user-plus"></i> Register</a>
    <?php endif; ?>
</div>

<script>
    function toggleDrawer(btn) {
        document.getElementById('mobileDrawer').classList.toggle('open');
    }
    document.addEventListener('click', function(e) {
        const drawer = document.getElementById('mobileDrawer');
        if (!drawer.contains(e.target) && !e.target.closest('.hamburger')) {
            drawer.classList.remove('open');
        }
    });
    <?php if ($isLoggedIn): ?>
            (function() {
                function syncBadge() {
                    fetch('<?= $base ?>user/cart_action.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'action=count'
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.cart_count === undefined) return;
                            const badge = document.querySelector('.cart-badge');
                            if (!badge) return;
                            badge.textContent = data.cart_count;
                            badge.classList.toggle('hidden', data.cart_count === 0);
                        }).catch(() => {});
                }
                syncBadge();
                setInterval(syncBadge, 10000);
            })();
    <?php endif; ?>
</script>