<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: includes/sidebar.php
// ============================================================

if (!isset($activePage)) $activePage = '';
if (session_status() === PHP_SESSION_NONE) session_start();

$base = '../';
$adminName    = $_SESSION['full_name'] ?? 'Admin';
$adminInitial = strtoupper(substr($adminName, 0, 1));

$navItems = [
    ['key'=>'dashboard',    'icon'=>'grid',        'label'=>'Dashboard',    'href'=>'dashboard.php'],
    ['key'=>'products',     'icon'=>'box',         'label'=>'Products',     'href'=>'products.php'],
    ['key'=>'inventory',    'icon'=>'archive',     'label'=>'Inventory',    'href'=>'inventory.php'],
    ['key'=>'orders',       'icon'=>'receipt',     'label'=>'Orders',       'href'=>'orders.php'],
    ['key'=>'customers',    'icon'=>'users',       'label'=>'Customers',    'href'=>'customers.php'],
    ['key'=>'transactions', 'icon'=>'transaction', 'label'=>'Transactions', 'href'=>'transactions.php'],
    ['key'=>'contacts', 'icon'=>'mail', 'label'=>'Messages', 'href'=>'contacts.php'],
    // In the $navItems array, add:
['key'=>'gcash_settings', 'icon'=>'card', 'label'=>'Payment Settings', 'href'=>'gcash_settings.php'],
];

$reportItems = [
    ['key'=>'report_sales',     'label'=>'Sales Report',     'href'=>'report_sales.php'],
    ['key'=>'report_inventory', 'label'=>'Inventory Report', 'href'=>'report_inventory.php'],
    ['key'=>'report_orders',    'label'=>'Orders Report',    'href'=>'report_orders.php'],
];

$isReportPage = in_array($activePage, ['report_sales','report_inventory','report_orders']);
?>
<style>
:root {
    --sidebar-w: 248px;
    --primary: #e67e22;
    --primary-hover: #f39535;
    --primary-glow: rgba(230,126,34,0.15);
    --sidebar-bg: #111827;
    --sidebar-surface: #1f2937;
    --sidebar-border: rgba(255,255,255,0.06);
    --sidebar-text: #9ca3af;
    --sidebar-text-active: #f9fafb;
    --page-bg: #f8f7f4;
    --card-bg: #ffffff;
    --card-border: #e9e8e4;
    --text: #1f2937;
    --text-muted: #6b7280;
    --dark: #111827;
    --radius: 12px;
    --shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 12px rgba(0,0,0,0.1), 0 12px 32px rgba(0,0,0,0.08);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: var(--page-bg);
    color: var(--text);
    line-height: 1.5;
}

/* ── Layout ── */
.admin-layout {
    display: flex;
    min-height: 100vh;
}

/* ── Sidebar ── */
.sidebar {
    width: var(--sidebar-w);
    height: 100vh;
    position: fixed;
    top: 0; left: 0;
    background: var(--sidebar-bg);
    display: flex;
    flex-direction: column;
    z-index: 800;
    border-right: 1px solid var(--sidebar-border);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Brand */
.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 22px 20px 18px;
    border-bottom: 1px solid var(--sidebar-border);
    text-decoration: none;
    flex-shrink: 0;
}

.sidebar-brand-icon {
    width: 36px; height: 36px;
    background: var(--primary);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(230,126,34,0.35);
}

.sidebar-brand-text { display: flex; flex-direction: column; }

.sidebar-brand-name {
    font-size: 0.95rem; font-weight: 700;
    color: #f9fafb; letter-spacing: -0.01em; line-height: 1.2;
}

.sidebar-brand-sub {
    font-size: 0.68rem; color: var(--sidebar-text);
    letter-spacing: 0.04em; text-transform: uppercase;
}

/* User chip */
.sidebar-user {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--sidebar-border);
    flex-shrink: 0;
}

.sidebar-avatar {
    width: 34px; height: 34px;
    background: linear-gradient(135deg, var(--primary), #f39c12);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.85rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(230,126,34,0.4);
}

.sidebar-user-info { flex: 1; overflow: hidden; }

.sidebar-user-name {
    font-size: 0.83rem; font-weight: 600; color: #f3f4f6;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.sidebar-user-role {
    font-size: 0.68rem; color: var(--sidebar-text);
    text-transform: uppercase; letter-spacing: 0.05em;
}

/* Nav */
.sidebar-nav {
    flex: 1;
    padding: 10px 12px 8px;
    display: flex;
    flex-direction: column;
    gap: 1px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,0.1) transparent;
}

.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.sidebar-nav-label {
    font-size: 0.62rem; font-weight: 700; color: #4b5563;
    text-transform: uppercase; letter-spacing: 0.1em;
    padding: 10px 8px 5px;
}

.sidebar-link {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; border-radius: 8px;
    text-decoration: none; color: var(--sidebar-text);
    font-size: 0.87rem; font-weight: 500;
    transition: background 0.15s, color 0.15s;
    position: relative;
}

.sidebar-link:hover { background: var(--sidebar-surface); color: #e5e7eb; }

.sidebar-link.active {
    background: var(--primary-glow);
    color: var(--primary-hover);
    font-weight: 600;
}

.sidebar-link.active::before {
    content: '';
    position: absolute; left: 0; top: 50%; transform: translateY(-50%);
    width: 3px; height: 20px;
    background: var(--primary);
    border-radius: 0 3px 3px 0;
}

.sidebar-link-icon {
    width: 18px; height: 18px; opacity: 0.7; flex-shrink: 0;
}

.sidebar-link.active .sidebar-link-icon { opacity: 1; }

/* ── Reports section ── */
.reports-toggle {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; border-radius: 8px;
    cursor: pointer; color: var(--sidebar-text);
    font-size: 0.87rem; font-weight: 500;
    transition: background 0.15s, color 0.15s;
    user-select: none;
    position: relative;
}

.reports-toggle:hover { background: var(--sidebar-surface); color: #e5e7eb; }

.reports-toggle.open,
.reports-toggle.has-active {
    color: var(--primary-hover);
}

.reports-toggle.has-active::before {
    content: '';
    position: absolute; left: 0; top: 50%; transform: translateY(-50%);
    width: 3px; height: 20px;
    background: var(--primary);
    border-radius: 0 3px 3px 0;
}

.reports-chevron {
    margin-left: auto;
    width: 14px; height: 14px;
    transition: transform 0.2s;
    opacity: 0.5;
}

.reports-toggle.open .reports-chevron { transform: rotate(180deg); }

.reports-sub {
    display: none;
    flex-direction: column;
    gap: 1px;
    padding-left: 14px;
    margin-top: 1px;
}

.reports-sub.open { display: flex; }

.sidebar-sublink {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 12px; border-radius: 7px;
    text-decoration: none; color: var(--sidebar-text);
    font-size: 0.83rem; font-weight: 500;
    transition: background 0.15s, color 0.15s;
    position: relative;
}

.sidebar-sublink::before {
    content: '';
    width: 5px; height: 5px;
    border-radius: 50%;
    background: #4b5563;
    flex-shrink: 0;
    transition: background 0.15s;
}

.sidebar-sublink:hover { background: var(--sidebar-surface); color: #e5e7eb; }
.sidebar-sublink:hover::before { background: #9ca3af; }

.sidebar-sublink.active {
    background: var(--primary-glow);
    color: var(--primary-hover);
    font-weight: 600;
}
.sidebar-sublink.active::before { background: var(--primary); }

/* Footer */
.sidebar-footer {
    padding: 12px;
    border-top: 1px solid var(--sidebar-border);
    flex-shrink: 0;
}

.sidebar-logout {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; border-radius: 8px;
    text-decoration: none; color: #f87171;
    font-size: 0.87rem; font-weight: 500;
    transition: background 0.15s;
}

.sidebar-logout:hover { background: rgba(248,113,113,0.1); }

/* ── Reusable card styles ── */
.card {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden;
}

.card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--card-border);
}

.card-title {
    font-size: 0.9rem; font-weight: 700; color: var(--dark);
    display: flex; align-items: center; gap: 8px;
}

/* ── Status badges ── */
.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 20px;
    font-size: 0.72rem; font-weight: 600; letter-spacing: 0.02em; white-space: nowrap;
}
.badge-pending    { background: #fef3c7; color: #92400e; }
.badge-confirmed  { background: #dbeafe; color: #1e40af; }
.badge-processing { background: #ede9fe; color: #5b21b6; }
.badge-delivery   { background: #ffedd5; color: #9a3412; }
.badge-delivered  { background: #d1fae5; color: #065f46; }
.badge-cancelled  { background: #fee2e2; color: #991b1b; }
.badge-paid       { background: #d1fae5; color: #065f46; }
.badge-unpaid     { background: #fee2e2; color: #991b1b; }
.badge-partial    { background: #fef3c7; color: #92400e; }
.badge-out-of-stock { background: #fee2e2; color: #991b1b; }
.badge-low-stock  { background: #fef3c7; color: #92400e; }
.badge-in-stock   { background: #d1fae5; color: #065f46; }

/* ── Overlay (mobile) ── */
.sidebar-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 799;
    backdrop-filter: blur(2px);
}

@media(max-width:768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .sidebar-overlay.show { display: block; }
}
</style>

<!-- Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">

    <!-- Brand -->
    <a href="<?= $base ?>admin/dashboard.php" class="sidebar-brand">
        <div class="sidebar-brand-icon">🥚</div>
        <div class="sidebar-brand-text">
            <span class="sidebar-brand-name">Hiney's</span>
            <span class="sidebar-brand-sub">Admin Panel</span>
        </div>
    </a>

    <!-- User -->
    <div class="sidebar-user">
        <div class="sidebar-avatar"><?= htmlspecialchars($adminInitial) ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= htmlspecialchars($adminName) ?></div>
            <div class="sidebar-user-role">Administrator</div>
        </div>
    </div>

    <!-- Nav -->
    <nav class="sidebar-nav">

        <div class="sidebar-nav-label">Main Menu</div>

        <?php
        $icons = [
            'grid'    => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
            'box'     => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            'archive' => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
            'receipt' => '<path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/>',
            'transaction' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="12" cy="14" r="2"/>',
            'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
            'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            'users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        ];
        foreach ($navItems as $item):
        ?>
        <a href="<?= $base ?>admin/<?= $item['href'] ?>"
           class="sidebar-link <?= $activePage === $item['key'] ? 'active' : '' ?>">
            <svg class="sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <?= $icons[$item['icon']] ?? '' ?>
            </svg>
            <?= htmlspecialchars($item['label']) ?>
        </a>
        <?php endforeach; ?>

        <!-- ── Reports section (collapsible) ── -->
        <div class="sidebar-nav-label" style="margin-top:6px;">Reports</div>

        <div class="reports-toggle <?= $isReportPage ? 'has-active open' : '' ?>"
             id="reportsToggle" onclick="toggleReports()">
            <svg class="sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"/>
                <line x1="12" y1="20" x2="12" y2="4"/>
                <line x1="6"  y1="20" x2="6"  y2="14"/>
            </svg>
            Analytics
            <svg class="reports-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"/>
            </svg>
        </div>

        <div class="reports-sub <?= $isReportPage ? 'open' : '' ?>" id="reportsSub">
            <?php foreach ($reportItems as $ri): ?>
            <a href="<?= $base ?>admin/<?= $ri['href'] ?>"
               class="sidebar-sublink <?= $activePage === $ri['key'] ? 'active' : '' ?>">
                <?= htmlspecialchars($ri['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>

    </nav>

    <!-- Footer -->
    <div class="sidebar-footer">
        <a href="<?= $base ?>logout.php" class="sidebar-logout">
            <svg class="sidebar-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Logout
        </a>
    </div>

</aside>

<script>
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebarOverlay').classList.add('show');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
}
function toggleReports() {
    const toggle = document.getElementById('reportsToggle');
    const sub    = document.getElementById('reportsSub');
    toggle.classList.toggle('open');
    sub.classList.toggle('open');
}
</script>