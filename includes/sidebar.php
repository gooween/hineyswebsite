<?php
// ============================================================
// Hiney's Eggs & Live Chicken Business
// File: includes/sidebar.php
//
// Admin sidebar — light theme, orange accents. Pulls shared
// styles from admin/assets/admin.css; only sidebar-specific
// tweaks live inline here.
//
// Set $activePage before including to highlight the current nav
// item, e.g.  $activePage = 'dashboard';
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
];

$storeItems = [
    ['key' => 'gcash_settings', 'icon' => 'fa-credit-card', 'label' => 'Payment Settings', 'href' => 'gcash_settings.php'],
    ['key' => 'delivery_zones', 'icon' => 'fa-truck',       'label' => 'Delivery Zones',   'href' => 'delivery_zones.php'],
];

$stockItems = [
    ['key' => 'stocks',     'icon' => 'fa-boxes-stacked', 'label' => 'Stock Batches', 'href' => 'stocks/index.php'],
    ['key' => 'stocks_add', 'icon' => 'fa-plus-circle',   'label' => 'Add Batch',     'href' => 'stocks/add.php'],
];

$reportItems = [
    ['key' => 'report_sales',     'icon' => 'fa-chart-line',     'label' => 'Sales Report',     'href' => 'report_sales.php'],
    ['key' => 'report_inventory', 'icon' => 'fa-boxes-stacked',  'label' => 'Inventory Report', 'href' => 'report_inventory.php'],
    ['key' => 'report_orders',    'icon' => 'fa-clipboard-list', 'label' => 'Orders Report',    'href' => 'report_orders.php'],
];

$isStockPage  = in_array($activePage, ['stocks', 'stocks_add']);
$isReportPage = in_array($activePage, ['report_sales', 'report_inventory', 'report_orders']);
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;550;600;650;700;750&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $base ?>admin/assets/admin.css">

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">

    <a href="<?= $base ?>admin/dashboard.php" class="sidebar-brand">
        <img src="<?= $base ?>assets/images/hineys_logo.png" alt="Hiney's"
            class="sidebar-brand-logo"
            onerror="this.outerHTML='<div class=\'sidebar-brand-fallback\'><i class=\'fa-solid fa-egg\'></i></div>'">
        <div class="sidebar-brand-text">
            <span class="sidebar-brand-name">HATCH</span>
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

        <div class="sidebar-nav-label">Main</div>
        <?php foreach ($navItems as $item): ?>
            <a href="<?= $base ?>admin/<?= $item['href'] ?>"
                class="sidebar-link <?= $activePage === $item['key'] ? 'active' : '' ?>">
                <i class="fa-solid <?= $item['icon'] ?> sidebar-link-icon"></i>
                <?= htmlspecialchars($item['label']) ?>
            </a>
        <?php endforeach; ?>

        <!-- Stocks (collapsible) -->
        <div class="sidebar-nav-label">Stock</div>
        <div class="section-toggle <?= $isStockPage ? 'has-active open' : '' ?>"
            id="stocksToggle" onclick="toggleSection('stocks')">
            <i class="fa-solid fa-layer-group sidebar-link-icon"></i>
            Stocks
            <svg class="section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9" />
            </svg>
        </div>
        <div class="section-sub <?= $isStockPage ? 'open' : '' ?>" id="stocksSub">
            <div class="section-sub-inner">
                <?php foreach ($stockItems as $si): ?>
                    <a href="<?= $base ?>admin/<?= $si['href'] ?>"
                        class="sidebar-sublink <?= $activePage === $si['key'] ? 'active' : '' ?>">
                        <i class="fa-solid <?= $si['icon'] ?>"></i>
                        <?= htmlspecialchars($si['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Reports (collapsible) -->
        <div class="sidebar-nav-label">Reports</div>
        <div class="section-toggle <?= $isReportPage ? 'has-active open' : '' ?>"
            id="reportsToggle" onclick="toggleSection('reports')">
            <i class="fa-solid fa-chart-pie sidebar-link-icon"></i>
            Analytics
            <svg class="section-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9" />
            </svg>
        </div>
        <div class="section-sub <?= $isReportPage ? 'open' : '' ?>" id="reportsSub">
            <div class="section-sub-inner">
                <?php foreach ($reportItems as $ri): ?>
                    <a href="<?= $base ?>admin/<?= $ri['href'] ?>"
                        class="sidebar-sublink <?= $activePage === $ri['key'] ? 'active' : '' ?>">
                        <i class="fa-solid <?= $ri['icon'] ?>"></i>
                        <?= htmlspecialchars($ri['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Store settings (collapsible) -->
        <div class="sidebar-nav-label">Store</div>
        <?php foreach ($storeItems as $item): ?>
            <a href="<?= $base ?>admin/<?= $item['href'] ?>"
                class="sidebar-link <?= $activePage === $item['key'] ? 'active' : '' ?>">
                <i class="fa-solid <?= $item['icon'] ?> sidebar-link-icon"></i>
                <?= htmlspecialchars($item['label']) ?>
            </a>
        <?php endforeach; ?>

    </nav>

    <div class="sidebar-footer">
        <button onclick="openChangePassword()" class="sidebar-logout secondary">
            <i class="fa-solid fa-key"></i>
            Change Password
        </button>
        <button onclick="openLogoutConfirm()" class="sidebar-logout">
            <i class="fa-solid fa-right-from-bracket"></i>
            Logout
        </button>
    </div>

</aside>

<!-- LOGOUT CONFIRMATION -->
<div id="logoutOverlay" style="position:fixed;inset:0;background:rgba(35,32,28,0);backdrop-filter:blur(0px);z-index:2000;display:none;align-items:center;justify-content:center;transition:background 0.3s,backdrop-filter 0.3s;padding:16px;">
    <div id="logoutCard" style="background:#fff;border-radius:16px;padding:30px 28px;width:100%;max-width:360px;text-align:center;box-shadow:0 24px 64px rgba(35,32,28,0.22);transform:scale(0.94) translateY(10px);opacity:0;transition:transform 0.28s cubic-bezier(0.34,1.4,0.64,1),opacity 0.22s ease;">
        <div style="width:56px;height:56px;background:var(--danger-tint);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.5rem;color:var(--danger);">
            <i class="fa-solid fa-right-from-bracket"></i>
        </div>
        <div style="font-size:1.05rem;font-weight:750;color:var(--ink);margin-bottom:6px;">Log out?</div>
        <div style="font-size:0.85rem;color:var(--ink-2);line-height:1.55;margin-bottom:22px;">You'll need to sign in again to access the admin panel.</div>
        <div style="display:flex;gap:10px;">
            <button onclick="closeLogoutConfirm()" class="btn btn-secondary" style="flex:1;">Cancel</button>
            <a href="<?= $base ?>logout.php" class="btn btn-danger" style="flex:1;">
                <i class="fa-solid fa-right-from-bracket"></i> Log out
            </a>
        </div>
    </div>
</div>

<!-- CHANGE PASSWORD MODAL -->
<div id="changePwOverlay" style="position:fixed;inset:0;background:rgba(35,32,28,0);backdrop-filter:blur(0px);z-index:2000;display:none;align-items:center;justify-content:center;transition:background 0.3s,backdrop-filter 0.3s;padding:16px;">
    <div id="changePwCard" style="background:#fff;border-radius:18px;width:100%;max-width:460px;box-shadow:0 24px 64px rgba(35,32,28,0.25);overflow:hidden;transform:scale(0.94) translateY(10px);opacity:0;transition:transform 0.28s cubic-bezier(0.34,1.4,0.64,1),opacity 0.22s ease;">
        <div style="padding:22px 26px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-size:0.66rem;font-weight:750;letter-spacing:0.1em;text-transform:uppercase;color:var(--brand);margin-bottom:4px;">Account Security</div>
                <div style="font-size:1.15rem;font-weight:750;color:var(--ink);letter-spacing:-0.02em;">Change Password</div>
            </div>
            <button onclick="closeChangePassword()" class="icon-btn" style="width:32px;height:32px;">✕</button>
        </div>

        <form method="POST" action="<?= $base ?>admin/change_password.php" id="changePwForm" style="padding:22px 26px;">
            <div id="changePwError" style="display:none;background:var(--danger-tint);border:1px solid #f3c9c5;color:#a4322a;padding:10px 14px;border-radius:9px;font-size:0.82rem;margin-bottom:18px;align-items:center;gap:8px;"></div>

            <div style="margin-bottom:16px;">
                <label style="font-size:0.77rem;font-weight:600;color:var(--ink-2);display:block;margin-bottom:6px;">Current Password <span style="color:var(--danger);">*</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--ink-3);pointer-events:none;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </span>
                    <input type="password" name="current_password" id="cpCurrent" required placeholder="Your current password"
                        style="width:100%;padding:11px 40px;border:1.5px solid var(--line-strong);border-radius:9px;font-size:0.875rem;font-family:inherit;color:var(--ink);background:var(--surface-2);outline:none;transition:border-color 0.18s,box-shadow 0.18s,background 0.18s;"
                        onfocus="this.style.borderColor='var(--brand)';this.style.boxShadow='0 0 0 3px var(--brand-ring)';this.style.background='#fff'"
                        onblur="this.style.borderColor='var(--line-strong)';this.style.boxShadow='none';this.style.background='var(--surface-2)'">
                    <button type="button" onclick="cpTogglePw('cpCurrent','cpToggle1')" id="cpToggle1" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink-3);display:flex;align-items:center;padding:3px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </button>
                </div>
            </div>

            <div style="margin-bottom:8px;">
                <label style="font-size:0.77rem;font-weight:600;color:var(--ink-2);display:block;margin-bottom:6px;">New Password <span style="color:var(--danger);">*</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--ink-3);pointer-events:none;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </span>
                    <input type="password" name="new_password" id="cpNew" required placeholder="Minimum 6 characters"
                        style="width:100%;padding:11px 40px;border:1.5px solid var(--line-strong);border-radius:9px;font-size:0.875rem;font-family:inherit;color:var(--ink);background:var(--surface-2);outline:none;transition:border-color 0.18s,box-shadow 0.18s,background 0.18s;"
                        onfocus="this.style.borderColor='var(--brand)';this.style.boxShadow='0 0 0 3px var(--brand-ring)';this.style.background='#fff'"
                        onblur="this.style.borderColor='var(--line-strong)';this.style.boxShadow='none';this.style.background='var(--surface-2)'"
                        oninput="cpCheckStrength(this.value);cpCheckMatch()">
                    <button type="button" onclick="cpTogglePw('cpNew','cpToggle2')" id="cpToggle2" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink-3);display:flex;align-items:center;padding:3px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </button>
                </div>
                <div style="height:3px;background:var(--line);border-radius:3px;margin-top:8px;overflow:hidden;">
                    <div id="cpStrengthFill" style="height:100%;border-radius:3px;transition:width 0.25s,background 0.25s;width:0%;"></div>
                </div>
                <div id="cpStrengthText" style="font-size:0.7rem;font-weight:600;margin-top:3px;color:var(--ink-2);min-height:14px;"></div>
            </div>

            <div style="margin-bottom:22px;">
                <label style="font-size:0.77rem;font-weight:600;color:var(--ink-2);display:block;margin-bottom:6px;">Confirm New Password <span style="color:var(--danger);">*</span></label>
                <div style="position:relative;">
                    <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--ink-3);pointer-events:none;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" />
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                    </span>
                    <input type="password" name="confirm_password" id="cpConfirm" required placeholder="Re-enter new password"
                        style="width:100%;padding:11px 40px;border:1.5px solid var(--line-strong);border-radius:9px;font-size:0.875rem;font-family:inherit;color:var(--ink);background:var(--surface-2);outline:none;transition:border-color 0.18s,box-shadow 0.18s,background 0.18s;"
                        onfocus="this.style.borderColor='var(--brand)';this.style.boxShadow='0 0 0 3px var(--brand-ring)';this.style.background='#fff'"
                        onblur="this.style.borderColor='var(--line-strong)';this.style.boxShadow='none';this.style.background='var(--surface-2)'"
                        oninput="cpCheckMatch()">
                    <button type="button" onclick="cpTogglePw('cpConfirm','cpToggle3')" id="cpToggle3" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink-3);display:flex;align-items:center;padding:3px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </button>
                </div>
                <div id="cpMatchErr" style="display:none;font-size:0.74rem;color:var(--danger);margin-top:4px;align-items:center;gap:4px;"><i class="fa-solid fa-triangle-exclamation"></i> Passwords do not match.</div>
                <div id="cpMatchOk" style="display:none;font-size:0.74rem;color:var(--ok);margin-top:4px;align-items:center;gap:4px;"><i class="fa-solid fa-circle-check"></i> Passwords match.</div>
            </div>

            <div style="display:flex;gap:10px;">
                <button type="button" onclick="closeChangePassword()" class="btn btn-secondary" style="flex:1;">Cancel</button>
                <button type="submit" id="cpSubmitBtn" class="btn btn-primary" style="flex:2;">
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
    // ── Sidebar mobile open/close ──
    function openSidebar() {
        document.getElementById('sidebar').classList.add('open');
        document.getElementById('sidebarOverlay').classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('sidebarOverlay').classList.remove('show');
        document.body.style.overflow = '';
    }

    // ── Collapsible nav sections ──
    function toggleSection(name) {
        document.getElementById(name + 'Toggle').classList.toggle('open');
        document.getElementById(name + 'Sub').classList.toggle('open');
    }

    // ── Logout confirm ──
    function openLogoutConfirm() {
        const o = document.getElementById('logoutOverlay');
        const c = document.getElementById('logoutCard');
        o.style.display = 'flex';
        requestAnimationFrame(() => {
            o.style.background = 'rgba(35,32,28,0.5)';
            o.style.backdropFilter = 'blur(4px)';
            c.style.transform = 'scale(1) translateY(0)';
            c.style.opacity = '1';
        });
    }

    function closeLogoutConfirm() {
        const o = document.getElementById('logoutOverlay');
        const c = document.getElementById('logoutCard');
        o.style.background = 'rgba(35,32,28,0)';
        o.style.backdropFilter = 'blur(0px)';
        c.style.transform = 'scale(0.94) translateY(10px)';
        c.style.opacity = '0';
        setTimeout(() => {
            o.style.display = 'none';
        }, 300);
    }
    document.getElementById('logoutOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeLogoutConfirm();
    });

    // ── Change password ──
    function openChangePassword() {
        const o = document.getElementById('changePwOverlay');
        const c = document.getElementById('changePwCard');
        o.style.display = 'flex';
        requestAnimationFrame(() => {
            o.style.background = 'rgba(35,32,28,0.5)';
            o.style.backdropFilter = 'blur(4px)';
            c.style.transform = 'scale(1) translateY(0)';
            c.style.opacity = '1';
        });
    }

    function closeChangePassword() {
        const o = document.getElementById('changePwOverlay');
        const c = document.getElementById('changePwCard');
        o.style.background = 'rgba(35,32,28,0)';
        o.style.backdropFilter = 'blur(0px)';
        c.style.transform = 'scale(0.94) translateY(10px)';
        c.style.opacity = '0';
        setTimeout(() => {
            o.style.display = 'none';
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
                bg: '#d94f46',
                label: 'Very weak',
                color: '#b23c34'
            },
            {
                pct: '40%',
                bg: '#e88a2a',
                label: 'Weak',
                color: '#a4680c'
            },
            {
                pct: '60%',
                bg: '#d9b017',
                label: 'Fair',
                color: '#8a7010'
            },
            {
                pct: '80%',
                bg: '#57a86b',
                label: 'Good',
                color: '#2f7a48'
            },
            {
                pct: '100%',
                bg: '#2f9e60',
                label: 'Strong',
                color: '#1f7a48'
            },
        ];
        const lvl = levels[Math.max(0, Math.min(score - 1, 4))];
        fill.style.width = lvl.pct;
        fill.style.background = lvl.bg;
        text.textContent = lvl.label;
        text.style.color = lvl.color;
    }

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
        try {
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
                toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#2f9e60;color:#fff;padding:13px 20px;border-radius:10px;font-size:0.88rem;font-weight:600;z-index:9999;box-shadow:0 8px 24px rgba(35,32,28,0.18);display:flex;align-items:center;gap:8px;';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 3000);
            } else {
                errEl.textContent = json.message || 'Failed to update password.';
                errEl.style.display = 'flex';
            }
        } catch (err) {
            document.getElementById('cpBtnText').textContent = 'Update Password';
            document.getElementById('cpBtnSpinner').style.display = 'none';
            document.getElementById('cpSubmitBtn').disabled = false;
            errEl.textContent = 'Something went wrong. Please try again.';
            errEl.style.display = 'flex';
        }
    });
</script>