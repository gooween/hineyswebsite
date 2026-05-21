<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
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
<style>
:root {
    --primary: #e67e22;
    --primary-dark: #cf6d17;
    --primary-bg: #fef3e8;
    --dark: #111827;
    --text: #374151;
    --muted: #6b7280;
    --border: #e5e7eb;
    --bg: #f8f7f4;
    --danger: #ef4444;
    --radius: 10px;
    --nav-h: 64px;
    --shadow-nav: 0 1px 0 #e5e7eb, 0 4px 16px rgba(0,0,0,0.04);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); }

/* ── Navbar ── */
.navbar {
    position: sticky;
    top: 0;
    height: var(--nav-h);
    background: #fff;
    display: flex;
    align-items: center;
    padding: 0 28px;
    gap: 8px;
    box-shadow: var(--shadow-nav);
    z-index: 1000;
}

/* Brand */
.nav-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    margin-right: 16px;
    flex-shrink: 0;
}

.nav-brand-icon {
    width: 36px; height: 36px;
    background: var(--primary);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    box-shadow: 0 3px 10px rgba(230,126,34,0.3);
    flex-shrink: 0;
}

.nav-brand-text { line-height: 1.2; }
.nav-brand-name { font-size: 1rem; font-weight: 800; color: var(--dark); }
.nav-brand-sub  { font-size: 0.65rem; color: var(--muted); letter-spacing: 0.04em; text-transform: uppercase; }

/* Links */
.nav-links {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    list-style: none;
    gap: 2px;
}

.nav-links a {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    text-decoration: none;
    color: var(--muted);
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 500;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}

.nav-links a:hover {
    background: var(--primary-bg);
    color: var(--primary);
}

.nav-links a.active {
    background: var(--primary-bg);
    color: var(--primary);
    font-weight: 600;
}

/* Actions */
.nav-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: 16px;
}

/* Cart */
.nav-cart {
    position: relative;
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 14px;
    background: var(--primary-bg);
    border-radius: 8px;
    text-decoration: none;
    color: var(--primary);
    font-size: 0.88rem;
    font-weight: 600;
    transition: background 0.15s;
}

.nav-cart:hover { background: #fde9d0; }

.cart-badge {
    min-width: 18px; height: 18px;
    background: var(--danger);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 700;
    border-radius: 9px;
    padding: 0 5px;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #fff;
    position: absolute;
    top: -5px; right: -5px;
}

.cart-badge.hidden { display: none; }

/* User chip */
.user-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 14px 5px 6px;
    border: 1px solid var(--border);
    border-radius: 24px;
    text-decoration: none;
    font-size: 0.85rem;
    color: var(--text);
    font-weight: 500;
    transition: border-color 0.15s, background 0.15s;
}

.user-chip:hover { border-color: var(--primary); background: var(--primary-bg); color: var(--primary); }

.user-avatar {
    width: 28px; height: 28px;
    background: linear-gradient(135deg, var(--primary), #f39c12);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%;
    font-size: 0.75rem;
    font-weight: 700;
    flex-shrink: 0;
}

.btn-login {
    padding: 7px 18px;
    background: var(--primary);
    color: #fff;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.88rem;
    font-weight: 600;
    transition: background 0.15s;
}

.btn-login:hover { background: var(--primary-dark); }

/* Hamburger */
.hamburger {
    display: none;
    flex-direction: column;
    justify-content: center;
    gap: 5px;
    width: 36px; height: 36px;
    padding: 6px;
    background: none;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    margin-left: auto;
}

.hamburger:hover { background: var(--bg); }
.hamburger span { display: block; width: 18px; height: 2px; background: var(--dark); border-radius: 2px; transition: transform 0.2s; }

/* Mobile drawer */
.mobile-drawer {
    display: none;
    position: fixed;
    top: var(--nav-h);
    left: 0; right: 0;
    background: #fff;
    border-bottom: 1px solid var(--border);
    padding: 8px 16px 16px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    z-index: 999;
}

.mobile-drawer.open { display: block; }

.mobile-drawer a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 12px;
    text-decoration: none;
    color: var(--text);
    font-size: 0.9rem;
    font-weight: 500;
    border-radius: 8px;
    transition: background 0.15s;
}

.mobile-drawer a:hover,
.mobile-drawer a.active { background: var(--primary-bg); color: var(--primary); }

.mobile-drawer-divider {
    height: 1px;
    background: var(--border);
    margin: 8px 0;
}

@media(max-width:860px) {
    .nav-links, .nav-actions { display: none; }
    .hamburger { display: flex; }
    .navbar { padding: 0 16px; }
}
</style>

<nav class="navbar">
    <a href="<?= $base ?>user/home.php" class="nav-brand">
        <div class="nav-brand-icon">🥚</div>
        <div class="nav-brand-text">
            <div class="nav-brand-name">Hiney's</div>
            <div class="nav-brand-sub">Eggs & Chicken</div>
        </div>
    </a>

    <ul class="nav-links">
        <li><a class="<?= $activePage=='home'?'active':'' ?>" href="<?= $base ?>user/home.php">Home</a></li>
        <li><a class="<?= $activePage=='products'?'active':'' ?>" href="<?= $base ?>user/products.php">Products</a></li>
        <li><a class="<?= $activePage=='about'?'active':'' ?>" href="<?= $base ?>user/about.php">About</a></li>
        <li><a class="<?= $activePage=='contact'?'active':'' ?>" href="<?= $base ?>user/contact.php">Contact</a></li>
    </ul>

    <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
            <a class="nav-cart" href="<?= $base ?>user/cart.php">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                Cart
                <span class="cart-badge <?= $cartItems == 0 ? 'hidden' : '' ?>"><?= $cartItems ?></span>
            </a>

            <a class="user-chip" href="<?= $base ?>user/profile.php">
                <div class="user-avatar"><?= htmlspecialchars($userInitial) ?></div>
                <?= htmlspecialchars($userName) ?>
            </a>
        <?php else: ?>
            <a class="btn-login" href="<?= $base ?>index.php">Login</a>
        <?php endif; ?>
    </div>

    <button class="hamburger" onclick="toggleDrawer(this)">
        <span></span><span></span><span></span>
    </button>
</nav>

<div class="mobile-drawer" id="mobileDrawer">
    <a class="<?= $activePage=='home'?'active':'' ?>" href="<?= $base ?>user/home.php">Home</a>
    <a class="<?= $activePage=='products'?'active':'' ?>" href="<?= $base ?>user/products.php">Products</a>
    <a class="<?= $activePage=='about'?'active':'' ?>" href="<?= $base ?>user/about.php">About</a>
    <a class="<?= $activePage=='contact'?'active':'' ?>" href="<?= $base ?>user/contact.php">Contact</a>
    <?php if ($isLoggedIn): ?>
        <div class="mobile-drawer-divider"></div>
        <a href="<?= $base ?>user/cart.php">Cart <?= $cartItems > 0 ? "($cartItems)" : '' ?></a>
        <a href="<?= $base ?>user/profile.php">My Profile</a>
    <?php else: ?>
        <div class="mobile-drawer-divider"></div>
        <a href="<?= $base ?>index.php">Login</a>
    <?php endif; ?>
</div>

<script>
function toggleDrawer(btn) {
    const drawer = document.getElementById('mobileDrawer');
    drawer.classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const drawer = document.getElementById('mobileDrawer');
    if (!drawer.contains(e.target) && !e.target.closest('.hamburger')) {
        drawer.classList.remove('open');
    }
});
// ── Realtime cart badge polling ───────────────────────────────
(function() {
    function syncBadge() {
        fetch('../user/cart_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=count'
        })
        .then(r => r.json())
        .then(data => {
            if (data.cart_count === undefined) return;
            const badge = document.querySelector('.cart-badge');
            if (!badge) return;
            badge.textContent = data.cart_count;
            badge.classList.toggle('hidden', data.cart_count === 0);
        })
        .catch(() => {}); // fail silently
    }

    syncBadge(); // run once on load
    setInterval(syncBadge, 10000); // then every 10 seconds
})();
</script>