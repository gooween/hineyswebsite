<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/contacts.php
// Purpose: View & manage customer contact/inquiry messages
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

// ── POST Handler (PRG pattern) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'mark_read' && $id) {
        $conn->query("UPDATE contacts SET is_read = 1 WHERE id = {$id}");
        redirect('contacts.php', 'success', 'Message marked as read.');
    }

    if ($action === 'mark_unread' && $id) {
        $conn->query("UPDATE contacts SET is_read = 0 WHERE id = {$id}");
        redirect('contacts.php', 'success', 'Message marked as unread.');
    }

    if ($action === 'mark_all_read') {
        $conn->query("UPDATE contacts SET is_read = 1 WHERE is_read = 0");
        redirect('contacts.php', 'success', 'All messages marked as read.');
    }

    if ($action === 'delete' && $id) {
        $conn->query("DELETE FROM contacts WHERE id = {$id}");
        redirect('contacts.php', 'success', 'Message deleted.');
    }

    redirect('contacts.php', 'error', 'Invalid action.');
}

// ── Filters & pagination ──────────────────────────────────────
$perPage     = 15;
$page        = max(1, (int)($_GET['page'] ?? 1));
$search      = trim($_GET['q'] ?? '');
$filterRead  = $_GET['read'] ?? '';   // '' | '0' | '1'
$offset      = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where .= " AND (name LIKE '%{$s}%' OR email LIKE '%{$s}%' OR subject LIKE '%{$s}%' OR message LIKE '%{$s}%')";
}
if ($filterRead === '0') $where .= " AND is_read = 0";
if ($filterRead === '1') $where .= " AND is_read = 1";

$totalResult = $conn->query("SELECT COUNT(*) AS cnt FROM contacts {$where}");
$totalCount  = (int)($totalResult->fetch_assoc()['cnt'] ?? 0);
$totalPages  = max(1, (int)ceil($totalCount / $perPage));

$messages = $conn->query("
    SELECT * FROM contacts
    {$where}
    ORDER BY is_read ASC, created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");

// ── Unread count (for badge) ──────────────────────────────────
$unreadRes   = $conn->query("SELECT COUNT(*) AS cnt FROM contacts WHERE is_read = 0");
$unreadCount = (int)($unreadRes->fetch_assoc()['cnt'] ?? 0);

$activePage = 'contacts';
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
.navbar .fa-solid, .mobile-drawer .fa-solid, .sidebar .fa-solid,
button .fa-solid, [class*="btn"] .fa-solid, .badge .fa-solid,
.status-badge .fa-solid, .status-tab .fa-solid, .pay-badge .fa-solid,
.page-banner .fa-solid, .page-header .fa-solid, .hero .fa-solid,
.cta-card .fa-solid, .about-strip .fa-solid, .nav-cart .fa-solid,
.user-chip .fa-solid, .info-card-top .fa-solid, .sidebar-logout .fa-solid {
    color: inherit !important;
}
/* Semantic colors for standalone content icons */
.fa-egg { color: #f4a72c; }
.fa-drumstick-bite { color: #c2703b; }
.fa-circle-check, .fa-check, .fa-shield-halved,
.fa-leaf, .fa-seedling, .fa-phone { color: #10b981; }
.fa-circle-xmark, .fa-xmark, .fa-trash, .fa-ban,
.fa-location-dot { color: #ef4444; }
.fa-cart-shopping, .fa-bag-shopping, .fa-store, .fa-shop { color: #e67e22; }
.fa-truck { color: #f97316; }
.fa-triangle-exclamation, .fa-circle-exclamation,
.fa-clock, .fa-star { color: #f59e0b; }
.fa-info-circle, .fa-credit-card, .fa-mobile-screen,
.fa-envelope, .fa-envelope-open, .fa-envelope-open-text,
.fa-inbox, .fa-comment, .fa-map, .fa-paperclip { color: #3b82f6; }
.fa-sack-dollar, .fa-money-bill, .fa-money-bill-transfer { color: #16a34a; }
.fa-users, .fa-user, .fa-user-plus { color: #6366f1; }
.fa-box, .fa-box-open, .fa-boxes-stacked, .fa-warehouse,
.fa-receipt, .fa-clipboard-list, .fa-file-lines { color: #8b5cf6; }
.fa-chart-bar, .fa-chart-line, .fa-chart-pie,
.fa-gauge-high { color: #0ea5e9; }
.fa-heart { color: #ef4444; }
.fa-gear { color: #6b7280; }
.fa-lightbulb { color: #f59e0b; }
</style>
<title>Contact Messages — Hiney's Admin</title>
<style>
:root { --card-border: #e9e8e4; }

/* ── Layout ── */
.main-content {
    margin-left: var(--sidebar-w);
    flex: 1;
    padding: 32px 32px 56px;
    min-height: 100vh;
    background: var(--page-bg);
    box-sizing: border-box;
    width: calc(100% - var(--sidebar-w));
}

/* ── Page header ── */
.page-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.page-header-left {}
.page-title {
    font-size: 1.5rem; font-weight: 800; color: var(--dark);
    letter-spacing: -0.02em; display: flex; align-items: center; gap: 10px;
}
.page-title-icon {
    width: 38px; height: 38px;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.page-title-sub { font-size: 0.82rem; color: var(--text-muted); margin-top: 3px; }

/* ── KPI strip ── */
.kpi-strip {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 14px; margin-bottom: 20px;
}
@media(max-width:700px) { .kpi-strip { grid-template-columns: 1fr 1fr; } }

.kpi-mini {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius); padding: 16px 18px;
    box-shadow: var(--shadow); display: flex; align-items: center; gap: 14px;
    position: relative; overflow: hidden;
}
.kpi-mini-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    flex-shrink: 0;
}
.kpi-mini-icon.blue   { background: #eff6ff; }
.kpi-mini-icon.red    { background: #fef2f2; }
.kpi-mini-icon.green  { background: #ecfdf5; }
.kpi-mini-val  { font-size: 1.6rem; font-weight: 800; color: var(--dark); letter-spacing: -0.03em; line-height: 1; }
.kpi-mini-lbl  { font-size: 0.72rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.07em; margin-top: 2px; }
.kpi-mini-accent {
    position: absolute; top: 0; left: 0; width: 100%; height: 3px;
    border-radius: var(--radius) var(--radius) 0 0;
}
.ac-blue  { background: #3b82f6; }
.ac-red   { background: #ef4444; }
.ac-green { background: #10b981; }

/* ── Toolbar ── */
.toolbar {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius) var(--radius) 0 0;
    padding: 12px 16px;
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; border-bottom: none;
}
.toolbar-left  { display: flex; align-items: center; gap: 8px; flex: 1; flex-wrap: wrap; }
.toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.toolbar-title { font-size: 0.9rem; font-weight: 700; color: var(--dark); white-space: nowrap; }
.count-pill {
    background: var(--primary); color: #fff;
    font-size: 0.72rem; font-weight: 700;
    padding: 2px 10px; border-radius: 20px;
}
.unread-pill {
    background: #fee2e2; color: #991b1b;
    font-size: 0.72rem; font-weight: 700;
    padding: 2px 10px; border-radius: 20px;
}

/* Search */
.search-wrap { position: relative; display: flex; align-items: center; }
.search-wrap svg { position: absolute; left: 9px; color: var(--text-muted); pointer-events: none; }
.search-input {
    padding: 7px 12px 7px 32px; border: 1px solid var(--card-border);
    border-radius: 8px; font-size: 0.85rem; width: 200px;
    background: var(--page-bg); color: var(--text); outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.12); }

.filter-tab {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 13px; border-radius: 20px;
    font-size: 0.8rem; font-weight: 600; cursor: pointer;
    border: 1px solid var(--card-border); background: var(--page-bg);
    color: var(--text-muted); text-decoration: none;
    transition: all 0.15s;
}
.filter-tab:hover  { border-color: var(--primary); color: var(--primary); background: var(--primary-light, #fef3e8); }
.filter-tab.active { background: var(--primary); border-color: var(--primary); color: #fff; }

.btn-mark-all {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 8px;
    background: transparent; color: var(--text-muted);
    border: 1px solid var(--card-border);
    font-size: 0.8rem; font-weight: 600; cursor: pointer;
    transition: all 0.15s; font-family: inherit;
    white-space: nowrap;
}
.btn-mark-all:hover { background: #ecfdf5; color: #065f46; border-color: #6ee7b7; }

/* ── Table ── */
.table-wrapper {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: 0 0 var(--radius) var(--radius);
    overflow-x: auto; box-shadow: var(--shadow);
}
table.data-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
table.data-table thead th {
    background: var(--dark); color: #e5e7eb;
    font-size: 0.7rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.07em;
    padding: 12px 14px; white-space: nowrap; text-align: left;
}
table.data-table tbody tr { transition: background 0.12s; }
table.data-table tbody tr.unread { background: #fefce8; }
table.data-table tbody tr.unread:hover { background: #fef9c3; }
table.data-table tbody tr:not(.unread):nth-child(even) { background: #faf9f7; }
table.data-table tbody tr:not(.unread):hover { background: #fdebd0; }
table.data-table tbody td {
    padding: 12px 14px; color: var(--text);
    border-bottom: 1px solid #f3f2f0; vertical-align: middle;
}
table.data-table tbody tr:last-child td { border-bottom: none; }

/* Sender cell */
.sender-cell { display: flex; align-items: center; gap: 10px; }
.sender-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    color: #fff; font-size: 0.85rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.sender-name { font-weight: 600; color: var(--dark); }
.sender-email { font-size: 0.75rem; color: var(--text-muted); margin-top: 1px; }

/* Subject truncate */
.subject-cell { max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; color: var(--dark); }
.msg-preview  { max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.8rem; color: var(--text-muted); }

/* Read badge */
.read-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px; border-radius: 20px; font-size: 0.72rem; font-weight: 600;
}
.read-badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; flex-shrink: 0; }
.rb-unread { background: #fef3c7; color: #92400e; }
.rb-read   { background: #f3f4f6; color: #6b7280; }

/* Action buttons */
.btn-action {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 9px; border-radius: 6px; font-size: 0.73rem;
    font-weight: 600; cursor: pointer; border: 1px solid;
    background: transparent; transition: background 0.15s, color 0.15s;
    white-space: nowrap; font-family: inherit;
}
.btn-view   { color: #3b82f6; border-color: #3b82f6; }
.btn-view:hover   { background: #3b82f6; color: #fff; }
.btn-toggle { color: #10b981; border-color: #10b981; }
.btn-toggle:hover { background: #10b981; color: #fff; }
.btn-toggle.unmark { color: #f59e0b; border-color: #f59e0b; }
.btn-toggle.unmark:hover { background: #f59e0b; color: #fff; }
.btn-del    { color: #ef4444; border-color: #ef4444; }
.btn-del:hover    { background: #ef4444; color: #fff; }

/* Empty state */
.empty-state { padding: 56px 20px; text-align: center; color: var(--text-muted); }
.empty-icon  { font-size: 3rem; margin-bottom: 12px; }

/* Pagination */
.pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 16px; border-top: 1px solid var(--card-border);
    font-size: 0.82rem; color: var(--text-muted); flex-wrap: wrap; gap: 8px;
}
.pagination-pages { display: flex; align-items: center; gap: 4px; }
.pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 8px;
    border-radius: 6px; border: 1px solid var(--card-border);
    background: var(--card-bg); color: var(--text);
    font-size: 0.82rem; font-weight: 500; cursor: pointer;
    text-decoration: none; transition: background 0.12s;
}
.pg-btn:hover  { background: var(--page-bg); }
.pg-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.pg-btn.disabled { opacity: 0.4; pointer-events: none; }

/* ── View Modal ── */
.modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); backdrop-filter: blur(3px);
    z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px;
}
.modal-backdrop.open { display: flex; }

.modal-card {
    background: var(--card-bg); border-radius: 14px;
    width: 100%; max-width: 580px; max-height: 92vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    animation: modalSlide 0.22s cubic-bezier(0.34,1.56,0.64,1) both;
}
.modal-card.sm { max-width: 440px; }

@keyframes modalSlide {
    from { opacity:0; transform: translateY(18px) scale(0.97); }
    to   { opacity:1; transform: translateY(0) scale(1); }
}

.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px 14px; border-bottom: 1px solid var(--card-border);
    position: sticky; top: 0; background: var(--card-bg); z-index: 1;
    border-radius: 14px 14px 0 0;
}
.modal-title { font-size: 1rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.modal-close {
    width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
    border: none; background: var(--page-bg); border-radius: 7px;
    cursor: pointer; color: var(--text-muted); font-size: 1rem;
    transition: background 0.15s;
}
.modal-close:hover { background: #fee2e2; color: #ef4444; }
.modal-body { padding: 20px 22px; }
.modal-footer {
    display: flex; align-items: center; justify-content: flex-end; gap: 10px;
    padding: 14px 22px; border-top: 1px solid var(--card-border);
    background: var(--page-bg); border-radius: 0 0 14px 14px;
}
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 0.87rem; font-weight: 600; cursor: pointer; border: 1px solid; font-family: inherit; transition: all 0.15s; }
.btn-ghost   { background: transparent; color: var(--text-muted); border-color: var(--card-border); }
.btn-ghost:hover { background: var(--page-bg); color: var(--text); }
.btn-success { background: #10b981; color: #fff; border-color: #10b981; }
.btn-success:hover { background: #059669; }
.btn-danger  { background: #ef4444; color: #fff; border-color: #ef4444; }
.btn-danger:hover { background: #dc2626; }

/* Message detail layout */
.msg-meta-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 20px;
}
.msg-meta-item {}
.msg-meta-label {
    font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.1em; color: var(--text-muted); margin-bottom: 3px;
}
.msg-meta-val { font-size: 0.9rem; font-weight: 600; color: var(--dark); }
.msg-meta-val a { color: var(--primary); text-decoration: none; }
.msg-meta-val a:hover { text-decoration: underline; }

.msg-body-label {
    font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.1em; color: var(--primary);
    border-bottom: 1px solid #fde9d0; padding-bottom: 6px; margin-bottom: 12px;
}

.msg-body-text {
    font-size: 0.9rem; color: var(--text); line-height: 1.75;
    background: var(--page-bg); border-radius: 10px; padding: 16px;
    border: 1px solid var(--card-border);
    white-space: pre-wrap; word-break: break-word;
}

/* Delete confirm modal */
.del-icon-wrap { width: 58px; height: 58px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.7rem; margin: 0 auto 14px; }
.del-title     { text-align: center; font-size: 1rem; font-weight: 700; color: var(--dark); margin-bottom: 8px; }
.del-text      { text-align: center; font-size: 0.87rem; color: var(--text-muted); line-height: 1.55; }
.del-name      { font-weight: 700; color: #ef4444; }

/* Mobile */
.mobile-menu-btn {
    display: none; align-items: center; justify-content: center;
    width: 36px; height: 36px; border: 1px solid var(--card-border);
    border-radius: 8px; background: var(--card-bg); cursor: pointer; color: var(--dark);
}
@media(max-width:768px) {
    .main-content    { margin-left: 0; padding: 16px 16px 48px; width: 100%; }
    .mobile-menu-btn { display: flex; }
    .toolbar         { flex-direction: column; align-items: stretch; }
    .toolbar-right   { justify-content: flex-end; }
    .msg-meta-grid   { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="admin-layout">
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <button class="mobile-menu-btn" onclick="openSidebar()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h1 class="page-title">
                    <div class="page-title-icon"><i class="fa-solid fa-inbox"></i></div>
                    Contact Messages
                </h1>
            </div>
            <div class="page-title-sub">Customer inquiries and messages submitted via the Contact page</div>
        </div>
    </div>

    <?= flash() ?>

    <!-- KPI Strip -->
    <?php
    $totalRes  = $conn->query("SELECT COUNT(*) AS cnt FROM contacts");
    $totalAll  = (int)($totalRes->fetch_assoc()['cnt'] ?? 0);
    $readRes   = $conn->query("SELECT COUNT(*) AS cnt FROM contacts WHERE is_read = 1");
    $readAll   = (int)($readRes->fetch_assoc()['cnt'] ?? 0);
    ?>
    <div class="kpi-strip">
        <div class="kpi-mini">
            <div class="kpi-mini-accent ac-blue"></div>
            <div class="kpi-mini-icon blue"><i class="fa-solid fa-inbox"></i></div>
            <div>
                <div class="kpi-mini-val"><?= number_format($totalAll) ?></div>
                <div class="kpi-mini-lbl">Total Messages</div>
            </div>
        </div>
        <div class="kpi-mini">
            <div class="kpi-mini-accent ac-red"></div>
            <div class="kpi-mini-icon red"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div>
                <div class="kpi-mini-val"><?= number_format($unreadCount) ?></div>
                <div class="kpi-mini-lbl">Unread</div>
            </div>
        </div>
        <div class="kpi-mini">
            <div class="kpi-mini-accent ac-green"></div>
            <div class="kpi-mini-icon green"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="kpi-mini-val"><?= number_format($readAll) ?></div>
                <div class="kpi-mini-lbl">Read</div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <span class="toolbar-title">All Messages</span>
            <span class="count-pill"><?= number_format($totalCount) ?></span>
            <?php if ($unreadCount > 0): ?>
                <span class="unread-pill"><i class="fa-solid fa-circle-exclamation"></i> <?= $unreadCount ?> unread</span>
            <?php endif; ?>

            <!-- Filter tabs -->
            <?php
            $tabBase = '?q=' . urlencode($search);
            ?>
            <a href="<?= $tabBase ?>" class="filter-tab <?= $filterRead === '' ? 'active' : '' ?>">All</a>
            <a href="<?= $tabBase ?>&read=0" class="filter-tab <?= $filterRead === '0' ? 'active' : '' ?>">Unread</a>
            <a href="<?= $tabBase ?>&read=1" class="filter-tab <?= $filterRead === '1' ? 'active' : '' ?>">Read</a>

            <!-- Search -->
            <form method="GET" style="display:contents;">
                <?php if ($filterRead !== ''): ?><input type="hidden" name="read" value="<?= htmlspecialchars($filterRead) ?>"><?php endif; ?>
                <div class="search-wrap">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" class="search-input" placeholder="Search messages…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <?php if ($search): ?>
                    <a href="?read=<?= urlencode($filterRead) ?>" style="font-size:0.78rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="toolbar-right">
            <?php if ($unreadCount > 0): ?>
            <form method="POST" action="contacts.php" style="display:contents;">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn-mark-all">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Mark All Read
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <?php if ($messages && $messages->num_rows > 0): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Sender</th>
                    <th>Phone</th>
                    <th>Subject</th>
                    <th>Preview</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="text-align:center;width:160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $rowNum = $offset + 1;
            $allRows = [];
            while ($m = $messages->fetch_assoc()) $allRows[] = $m;
            foreach ($allRows as $m):
                $initial = strtoupper(substr($m['name'], 0, 1));
                $isUnread = !(int)$m['is_read'];
            ?>
            <tr class="<?= $isUnread ? 'unread' : '' ?>"
                id="row-<?= $m['id'] ?>">
                <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;">
                    <?= $rowNum++ ?>
                    <?php if ($isUnread): ?>
                        <span style="display:inline-block;width:6px;height:6px;background:#ef4444;border-radius:50%;margin-left:4px;vertical-align:middle;"></span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="sender-cell">
                        <div class="sender-avatar"><?= htmlspecialchars($initial) ?></div>
                        <div>
                            <div class="sender-name"><?= htmlspecialchars($m['name']) ?></div>
                            <div class="sender-email"><?= htmlspecialchars($m['email'] ?: '—') ?></div>
                        </div>
                    </div>
                </td>
                <td style="font-size:0.85rem;color:var(--text-muted);">
                    <?= htmlspecialchars($m['phone'] ?: '—') ?>
                </td>
                <td>
                    <div class="subject-cell" title="<?= htmlspecialchars($m['subject'] ?? '') ?>">
                        <?= htmlspecialchars($m['subject'] ?: '(No subject)') ?>
                    </div>
                </td>
                <td>
                    <div class="msg-preview" title="<?= htmlspecialchars($m['message']) ?>">
                        <?= htmlspecialchars($m['message']) ?>
                    </div>
                </td>
                <td>
                    <span class="read-badge <?= $isUnread ? 'rb-unread' : 'rb-read' ?>">
                        <?= $isUnread ? 'Unread' : 'Read' ?>
                    </span>
                </td>
                <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;">
                    <?= date('M j, Y', strtotime($m['created_at'])) ?><br>
                    <span style="font-size:0.72rem;"><?= date('g:i A', strtotime($m['created_at'])) ?></span>
                </td>
                <td style="text-align:center;">
                    <div style="display:flex;align-items:center;justify-content:center;gap:5px;flex-wrap:wrap;">
                        <!-- View -->
                        <button class="btn-action btn-view"
                            onclick="openView(<?= htmlspecialchars(json_encode([
                                'id'         => $m['id'],
                                'name'       => $m['name'],
                                'email'      => $m['email'],
                                'phone'      => $m['phone'],
                                'subject'    => $m['subject'],
                                'message'    => $m['message'],
                                'is_read'    => (int)$m['is_read'],
                                'created_at' => $m['created_at'],
                            ]), ENT_QUOTES) ?>)">
                            <i class="fa-solid fa-eye"></i> View
                        </button>

                        <!-- Mark read/unread -->
                        <form method="POST" action="contacts.php" style="display:contents;">
                            <input type="hidden" name="action" value="<?= $isUnread ? 'mark_read' : 'mark_unread' ?>">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn-action btn-toggle <?= $isUnread ? '' : 'unmark' ?>">
                                <?= $isUnread ? '✓ Read' : '<i class="fa-solid fa-rotate-left"></i> Unread' ?>
                            </button>
                        </form>

                        <!-- Delete -->
                        <button class="btn-action btn-del"
                            onclick="openDelete(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['name'])) ?>')">
                            <i class="fa-solid fa-trash"></i> Del
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?> messages</div>
            <div class="pagination-pages">
                <?php
                $qs = http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]));
                echo "<a href='?{$qs}' class='pg-btn" . ($page <= 1 ? ' disabled' : '') . "'>← Prev</a>";
                for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
                    $qi = http_build_query(array_merge($_GET, ['page' => $i]));
                    echo "<a href='?{$qi}' class='pg-btn" . ($i == $page ? ' active' : '') . "'>{$i}</a>";
                }
                $qs2 = http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)]));
                echo "<a href='?{$qs2}' class='pg-btn" . ($page >= $totalPages ? ' disabled' : '') . "'>Next →</a>";
                ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-inbox"></i></div>
            <div style="font-size:0.9rem;font-weight:600;color:var(--dark);margin-bottom:4px;">No messages found</div>
            <div style="font-size:0.82rem;">
                <?= $search ? 'Try different search terms.' : 'No contact messages yet.' ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.main-content -->
</div><!-- /.admin-layout -->

<!-- ══════════════════ VIEW MODAL ══════════════════ -->
<div class="modal-backdrop" id="viewModal" onclick="backdropClose(event,'viewModal')">
    <div class="modal-card" role="dialog" aria-modal="true">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-envelope-open"></i> Message Details</div>
            <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
        </div>
        <div class="modal-body">

            <!-- Sender info grid -->
            <div class="msg-meta-grid">
                <div class="msg-meta-item">
                    <div class="msg-meta-label">From</div>
                    <div class="msg-meta-val" id="view_name">—</div>
                </div>
                <div class="msg-meta-item">
                    <div class="msg-meta-label">Email</div>
                    <div class="msg-meta-val" id="view_email">—</div>
                </div>
                <div class="msg-meta-item">
                    <div class="msg-meta-label">Phone</div>
                    <div class="msg-meta-val" id="view_phone">—</div>
                </div>
                <div class="msg-meta-item">
                    <div class="msg-meta-label">Date Received</div>
                    <div class="msg-meta-val" id="view_date">—</div>
                </div>
                <div class="msg-meta-item" style="grid-column:span 2;">
                    <div class="msg-meta-label">Subject</div>
                    <div class="msg-meta-val" id="view_subject">—</div>
                </div>
            </div>

            <!-- Message body -->
            <div class="msg-body-label">Message</div>
            <div class="msg-body-text" id="view_message">—</div>

            <!-- Reply shortcut -->
            <div style="margin-top:14px;padding:12px 14px;background:#eff6ff;border-radius:9px;border:1px solid #bfdbfe;font-size:0.82rem;color:#1e40af;display:flex;align-items:center;gap:8px;">
                <span><i class="fa-solid fa-lightbulb"></i></span>
                <span>To reply, email <strong id="view_reply_email">—</strong> or call <strong id="view_reply_phone">—</strong></span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('viewModal')">Close</button>
            <form method="POST" action="contacts.php" style="display:contents;" id="viewMarkForm">
                <input type="hidden" name="id" id="view_id">
                <input type="hidden" name="action" id="view_action">
                <button type="submit" class="btn btn-success" id="viewMarkBtn">✓ Mark as Read</button>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════ DELETE CONFIRM MODAL ══════════════════ -->
<div class="modal-backdrop" id="deleteModal" onclick="backdropClose(event,'deleteModal')">
    <div class="modal-card sm" role="dialog" aria-modal="true">
        <div class="modal-header">
            <div class="modal-title" style="color:#ef4444;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Delete Message
            </div>
            <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
        </div>
        <form method="POST" action="contacts.php">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_id">
            <div class="modal-body">
                <div class="del-icon-wrap"><i class="fa-solid fa-trash"></i></div>
                <div class="del-title">Delete this message?</div>
                <div class="del-text">
                    You are about to permanently delete the message from<br>
                    <span class="del-name" id="delete_name"></span>.<br><br>
                    This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete Permanently</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal helpers ─────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
function backdropClose(e, id) { if (e.target === document.getElementById(id)) closeModal(id); }

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['viewModal', 'deleteModal'].forEach(id => document.getElementById(id)?.classList.remove('open'));
        document.body.style.overflow = '';
    }
});

// ── Open View Modal ───────────────────────────────────────────
function openView(data) {
    document.getElementById('view_id').value      = data.id;
    document.getElementById('view_name').textContent    = data.name || '—';
    document.getElementById('view_subject').textContent = data.subject || '(No subject)';
    document.getElementById('view_message').textContent = data.message || '';
    document.getElementById('view_reply_email').textContent = data.email || '—';
    document.getElementById('view_reply_phone').textContent = data.phone || '—';

    // Email link
    const emailEl = document.getElementById('view_email');
    if (data.email) {
        emailEl.innerHTML = '<a href="mailto:' + data.email + '">' + data.email + '</a>';
    } else {
        emailEl.textContent = '—';
    }

    // Phone link
    const phoneEl = document.getElementById('view_phone');
    if (data.phone) {
        phoneEl.innerHTML = '<a href="tel:' + data.phone + '">' + data.phone + '</a>';
    } else {
        phoneEl.textContent = '—';
    }

    // Date formatting
    const d = new Date(data.created_at);
    document.getElementById('view_date').textContent = isNaN(d)
        ? data.created_at
        : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    // Mark read/unread toggle button
    const markBtn = document.getElementById('viewMarkBtn');
    const actionInput = document.getElementById('view_action');
    if (data.is_read) {
        actionInput.value    = 'mark_unread';
        markBtn.textContent  = '<i class="fa-solid fa-rotate-left"></i> Mark as Unread';
        markBtn.className    = 'btn btn-ghost';
    } else {
        actionInput.value    = 'mark_read';
        markBtn.textContent  = '✓ Mark as Read';
        markBtn.className    = 'btn btn-success';
    }

    openModal('viewModal');
}

// ── Open Delete Modal ─────────────────────────────────────────
function openDelete(id, name) {
    document.getElementById('delete_id').value       = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}
</script>
</body>
</html>