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
    <title>Contact Messages — Hiney's Admin</title>
    <style>
        /* Page-specific only — shared system comes from admin.css */

        /* Toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--s3);
            flex-wrap: wrap;
            margin-bottom: var(--s4);
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: var(--s3);
            flex: 1;
            flex-wrap: wrap;
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: var(--s2);
            flex-shrink: 0;
        }

        .toolbar-title {
            font-size: var(--fs-h3);
            font-weight: var(--fw-semi);
            color: var(--ink);
            white-space: nowrap;
        }

        .count-pill {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            background: var(--brand-tint);
            color: var(--brand-strong);
            padding: 2px 10px;
            border-radius: var(--r-pill);
        }

        .unread-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            background: var(--danger-tint);
            color: #b23c34;
            padding: 2px 10px;
            border-radius: var(--r-pill);
        }

        /* Filter tabs */
        .filter-tab {
            padding: 5px 13px;
            border-radius: var(--r-pill);
            font-size: var(--fs-sm);
            font-weight: var(--fw-med);
            color: var(--ink-2);
            white-space: nowrap;
            border: 1px solid var(--line-strong);
            transition: all 0.14s;
        }

        .filter-tab:hover {
            border-color: var(--ink-3);
            color: var(--ink);
        }

        .filter-tab.active {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
            font-weight: var(--fw-semi);
        }

        .search-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-wrap svg {
            position: absolute;
            left: 11px;
            color: var(--ink-3);
            pointer-events: none;
        }

        .search-input {
            padding: 8px 12px 8px 33px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            width: 200px;
            background: var(--surface);
            color: var(--ink);
            outline: none;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .search-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .clear-link {
            font-size: var(--fs-sm);
            color: var(--brand);
            font-weight: var(--fw-med);
            white-space: nowrap;
        }

        .clear-link:hover {
            text-decoration: underline;
        }

        .btn-mark-all {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px var(--s4);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            cursor: pointer;
            font-family: inherit;
            white-space: nowrap;
            background: var(--ok);
            color: #fff;
            border: 1px solid transparent;
            transition: background 0.14s;
        }

        .btn-mark-all:hover {
            background: #278a52;
        }

        /* Sender cell */
        .sender-cell {
            display: flex;
            align-items: center;
            gap: var(--s3);
        }

        .sender-name {
            font-weight: var(--fw-semi);
            color: var(--ink);
            font-size: var(--fs-sm);
        }

        .sender-email {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        .subject-cell {
            font-weight: var(--fw-med);
            color: var(--ink);
            font-size: var(--fs-sm);
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .msg-preview {
            color: var(--ink-3);
            font-size: var(--fs-xs);
            max-width: 240px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Read badge */
        .read-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            white-space: nowrap;
        }

        .read-badge::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .rb-unread {
            background: var(--danger-tint);
            color: #b23c34;
        }

        .rb-read {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        /* Unread row emphasis */
        tr.unread td {
            background: var(--brand-tint);
        }

        tr.unread:hover td {
            background: var(--brand-tint-2);
        }

        tr.unread .sender-name {
            font-weight: var(--fw-bold);
        }

        /* Row actions — icon by default, expand to label on hover (matches Products) */
        .row-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .kact {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 32px;
            width: 32px;
            padding: 0;
            border-radius: var(--r-sm);
            cursor: pointer;
            border: 1px solid;
            background: transparent;
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            font-family: inherit;
            white-space: nowrap;
            overflow: hidden;
            transition: width 0.22s cubic-bezier(0.4, 0, 0.2, 1), background 0.14s, color 0.14s, border-color 0.14s, padding 0.22s;
        }

        .kact svg,
        .kact i {
            flex-shrink: 0;
        }

        .kact .act-label {
            max-width: 0;
            opacity: 0;
            margin-left: 0;
            transition: max-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.18s, margin-left 0.22s;
        }

        .kact:hover {
            width: auto;
            padding: 0 11px;
        }

        .kact:hover .act-label {
            max-width: 90px;
            opacity: 1;
            margin-left: 5px;
        }

        .kact-view {
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .kact-view:hover {
            background: var(--ink-2);
            color: #fff;
            border-color: var(--ink-2);
        }

        .kact-read {
            color: var(--ok);
            border-color: #a7dcbc;
        }

        .kact-read:hover {
            background: var(--ok);
            color: #fff;
            border-color: var(--ok);
        }

        .kact-unread {
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .kact-unread:hover {
            background: var(--ink-2);
            color: #fff;
            border-color: var(--ink-2);
        }

        .kact-del {
            color: var(--danger);
            border-color: #f0c4c0;
        }

        .kact-del:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
        }

        /* form wrapper shouldn't add layout */
        .row-actions form {
            display: inline-flex;
        }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: var(--s4) var(--s5);
            border-top: 1px solid var(--line);
            font-size: var(--fs-sm);
            color: var(--ink-2);
            flex-wrap: wrap;
            gap: var(--s2);
        }

        .pagination-pages {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .pg-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            border-radius: var(--r-sm);
            border: 1px solid var(--line-strong);
            background: var(--surface);
            color: var(--ink);
            font-size: var(--fs-sm);
            font-weight: var(--fw-med);
            cursor: pointer;
            text-decoration: none;
            transition: background 0.14s, border-color 0.14s;
        }

        .pg-btn:hover {
            background: var(--surface-2);
            border-color: var(--ink-3);
        }

        .pg-btn.active {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
            font-weight: var(--fw-bold);
        }

        .pg-btn.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* ── Modals ── */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(35, 32, 28, 0.45);
            backdrop-filter: blur(3px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-card {
            background: var(--surface);
            border-radius: var(--r-lg);
            width: 100%;
            max-width: 580px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: var(--shadow-md);
            animation: modalIn 0.22s cubic-bezier(0.34, 1.4, 0.64, 1) both;
        }

        .modal-card.sm {
            max-width: 440px;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(16px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: var(--s5) var(--s6) var(--s4);
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
            background: var(--surface);
            z-index: 1;
            border-radius: var(--r-lg) var(--r-lg) 0 0;
        }

        .modal-title {
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: var(--s2);
        }

        .modal-title i {
            color: var(--brand);
        }

        .modal-close {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: var(--surface-2);
            border-radius: var(--r-sm);
            cursor: pointer;
            color: var(--ink-3);
            font-size: 1rem;
            transition: background 0.14s, color 0.14s;
        }

        .modal-close:hover {
            background: var(--danger-tint);
            color: var(--danger);
        }

        .modal-body {
            padding: var(--s5) var(--s6);
        }

        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: var(--s3);
            padding: var(--s4) var(--s6);
            border-top: 1px solid var(--line);
            background: var(--surface-2);
            border-radius: 0 0 var(--r-lg) var(--r-lg);
            position: sticky;
            bottom: 0;
        }

        /* Message meta grid (View modal) */
        .msg-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--s3);
            margin-bottom: var(--s4);
        }

        .msg-meta-item {
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--r-sm);
            padding: 10px 14px;
        }

        .msg-meta-label {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--ink-3);
            margin-bottom: 4px;
        }

        .msg-meta-val {
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            color: var(--ink);
            word-break: break-word;
        }

        .msg-meta-val a {
            color: var(--brand-strong);
            text-decoration: none;
        }

        .msg-meta-val a:hover {
            text-decoration: underline;
        }

        .msg-body-label {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--ink-3);
            margin-bottom: 6px;
        }

        .msg-body-text {
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--r-sm);
            padding: var(--s4);
            font-size: var(--fs-sm);
            color: var(--ink);
            line-height: 1.65;
            white-space: pre-wrap;
        }

        /* Delete modal bits */
        .del-icon-wrap {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: var(--danger-tint);
            color: var(--danger);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin: 0 auto var(--s3);
        }

        .del-title {
            font-size: 1rem;
            font-weight: var(--fw-bold);
            color: var(--ink);
            margin-bottom: var(--s2);
            text-align: center;
        }

        .del-text {
            font-size: var(--fs-sm);
            color: var(--ink-2);
            line-height: 1.6;
            text-align: center;
        }

        .del-name {
            font-weight: var(--fw-bold);
            color: var(--ink);
        }

        /* Buttons inside modals */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--s2);
            padding: 9px var(--s4);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            font-family: inherit;
            cursor: pointer;
            border: 1px solid transparent;
            transition: background 0.14s, border-color 0.14s;
            white-space: nowrap;
        }

        .btn svg {
            flex-shrink: 0;
        }

        .btn-ghost {
            background: transparent;
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .btn-ghost:hover {
            background: var(--surface-2);
            color: var(--ink);
        }

        .btn-success {
            background: var(--ok);
            color: #fff;
        }

        .btn-success:hover {
            background: #278a52;
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
        }

        .btn-danger:hover {
            background: #c0433b;
        }

        @media (max-width: 768px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .toolbar-right {
                justify-content: flex-end;
            }

            .search-input {
                width: 100%;
            }

            .msg-meta-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="admin-layout">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">

            <!-- Mobile topbar -->
            <div class="mobile-topbar">
                <div class="mobile-brand">
                    <div class="mobile-brand-icon"><i class="fa-solid fa-egg"></i></div>
                    Hiney's Admin
                </div>
                <button class="icon-btn" onclick="openSidebar()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Contact Messages</h1>
                    <div class="page-title-sub">Customer inquiries and messages submitted via the Contact page</div>
                </div>
            </div>

            <?= flash() ?>

            <!-- Stat cards -->
            <?php
            $totalRes  = $conn->query("SELECT COUNT(*) AS cnt FROM contacts");
            $totalAll  = (int)($totalRes->fetch_assoc()['cnt'] ?? 0);
            $readRes   = $conn->query("SELECT COUNT(*) AS cnt FROM contacts WHERE is_read = 1");
            $readAll   = (int)($readRes->fetch_assoc()['cnt'] ?? 0);
            ?>
            <div class="grid cols-3 mb-6">
                <div class="stat-card tone-blue">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Messages</span>
                        <div class="stat-icon"><i class="fa-solid fa-inbox"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalAll) ?></div>
                    <div class="stat-foot">All inquiries received</div>
                </div>
                <div class="stat-card tone-red">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Unread</span>
                        <div class="stat-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php if ($unreadCount > 0): ?><span class="pulse"><span class="pulse-dot"></span><?= number_format($unreadCount) ?></span><?php else: ?><?= number_format($unreadCount) ?><?php endif; ?>
                    </div>
                    <div class="stat-foot">Awaiting your attention</div>
                </div>
                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Read</span>
                        <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($readAll) ?></div>
                    <div class="stat-foot">Reviewed messages</div>
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
                    <?php $tabBase = '?q=' . urlencode($search); ?>
                    <a href="<?= $tabBase ?>" class="filter-tab <?= $filterRead === '' ? 'active' : '' ?>">All</a>
                    <a href="<?= $tabBase ?>&read=0" class="filter-tab <?= $filterRead === '0' ? 'active' : '' ?>">Unread</a>
                    <a href="<?= $tabBase ?>&read=1" class="filter-tab <?= $filterRead === '1' ? 'active' : '' ?>">Read</a>

                    <!-- Search -->
                    <form method="GET" style="display:flex;gap:var(--s2);align-items:center;">
                        <?php if ($filterRead !== ''): ?><input type="hidden" name="read" value="<?= htmlspecialchars($filterRead) ?>"><?php endif; ?>
                        <div class="search-wrap">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                            <input type="text" name="q" class="search-input" placeholder="Search messages…" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <?php if ($search): ?>
                            <a href="?read=<?= urlencode($filterRead) ?>" class="clear-link">✕ Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="toolbar-right">
                    <?php if ($unreadCount > 0): ?>
                        <form method="POST" action="contacts.php">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn-mark-all">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12" />
                                </svg>
                                Mark All Read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Table -->
            <div class="table-card">
                <?php if ($messages && $messages->num_rows > 0): ?>
                    <div class="table-scroll">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th style="width:40px;">#</th>
                                    <th>Sender</th>
                                    <th>Phone</th>
                                    <th>Subject</th>
                                    <th>Preview</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th style="text-align:center;min-width:120px;">Actions</th>
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
                                    <tr class="<?= $isUnread ? 'unread' : '' ?>" id="row-<?= $m['id'] ?>">
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);font-weight:var(--fw-semi);">
                                            <?= $rowNum++ ?>
                                        </td>
                                        <td>
                                            <div class="sender-cell">
                                                <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                                                <div>
                                                    <div class="sender-name"><?= htmlspecialchars($m['name']) ?></div>
                                                    <div class="sender-email"><?= htmlspecialchars($m['email'] ?: '—') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-size:var(--fs-sm);color:var(--ink-2);">
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
                                        <td style="font-size:var(--fs-xs);color:var(--ink-3);white-space:nowrap;">
                                            <?= date('M j, Y', strtotime($m['created_at'])) ?><br>
                                            <span style="font-size:0.7rem;"><?= date('g:i A', strtotime($m['created_at'])) ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="row-actions">
                                                <!-- View -->
                                                <button class="kact kact-view"
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
                                                    <i class="fa-solid fa-eye"></i><span class="act-label">View</span>
                                                </button>

                                                <!-- Mark read/unread -->
                                                <form method="POST" action="contacts.php">
                                                    <input type="hidden" name="action" value="<?= $isUnread ? 'mark_read' : 'mark_unread' ?>">
                                                    <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                                    <?php if ($isUnread): ?>
                                                        <button type="submit" class="kact kact-read" title="Mark as read">
                                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round">
                                                                <polyline points="20 6 9 17 4 12" />
                                                            </svg><span class="act-label">Read</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" class="kact kact-unread" title="Mark as unread">
                                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                                <polyline points="1 4 1 10 7 10" />
                                                                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" />
                                                            </svg><span class="act-label">Unread</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </form>

                                                <!-- Delete -->
                                                <button class="kact kact-del"
                                                    onclick="openDelete(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['name'])) ?>')">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6" />
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                                    </svg><span class="act-label">Delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

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
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-inbox"></i></div>
                        <div class="empty-title">No messages found</div>
                        <div class="empty-text"><?= $search ? 'Try different search terms.' : 'No contact messages yet.' ?></div>
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
                <div style="margin-top:14px;padding:12px 14px;background:var(--info-tint);border-radius:9px;border:1px solid #bcd6f5;font-size:0.82rem;color:#2b62ad;display:flex;align-items:center;gap:8px;">
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
                <div class="modal-title" style="color:var(--danger);">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <line x1="12" y1="9" x2="12" y2="13" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg>
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
        function openModal(id) {
            document.getElementById(id).classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
            document.body.style.overflow = '';
        }

        function backdropClose(e, id) {
            if (e.target === document.getElementById(id)) closeModal(id);
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                ['viewModal', 'deleteModal'].forEach(id => document.getElementById(id)?.classList.remove('open'));
                document.body.style.overflow = '';
            }
        });

        // ── Open View Modal ───────────────────────────────────────────
        function openView(data) {
            document.getElementById('view_id').value = data.id;
            document.getElementById('view_name').textContent = data.name || '—';
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
            document.getElementById('view_date').textContent = isNaN(d) ?
                data.created_at :
                d.toLocaleDateString('en-PH', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });

            // Mark read/unread toggle button
            const markBtn = document.getElementById('viewMarkBtn');
            const actionInput = document.getElementById('view_action');
            if (data.is_read) {
                actionInput.value = 'mark_unread';
                markBtn.textContent = '<i class="fa-solid fa-rotate-left"></i> Mark as Unread';
                markBtn.className = 'btn btn-ghost';
            } else {
                actionInput.value = 'mark_read';
                markBtn.textContent = '✓ Mark as Read';
                markBtn.className = 'btn btn-success';
            }

            openModal('viewModal');
        }

        // ── Open Delete Modal ─────────────────────────────────────────
        function openDelete(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_name').textContent = name;
            openModal('deleteModal');
        }
    </script>
</body>

</html>