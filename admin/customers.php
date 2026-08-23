<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/customers.php
// Purpose: Customer accounts — view, edit status, view orders
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

// ── POST Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // Toggle active/inactive
    if ($action === 'toggle_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = (int)($_POST['is_active'] ?? 0);
        if ($id) {
            $conn->query("UPDATE users SET is_active = {$status} WHERE id = {$id} AND role = 'customer'");
            $label = $status ? 'activated' : 'deactivated';
            redirect('customers.php', 'success', "Customer account {$label} successfully.");
        }
    }

    // Edit customer info
    if ($action === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = clean($_POST['full_name'] ?? '', $conn);
        $email   = clean($_POST['email'] ?? '', $conn);
        $phone   = clean($_POST['phone'] ?? '', $conn);
        $address = clean($_POST['address'] ?? '', $conn);
        $status  = (int)($_POST['is_active'] ?? 1);

        if ($id && $name && $email) {
            // Check email uniqueness (exclude self)
            $chk = $conn->query("SELECT id FROM users WHERE email='{$email}' AND id != {$id} LIMIT 1");
            if ($chk->num_rows > 0) {
                redirect('customers.php', 'error', 'That email is already used by another account.');
            }
            $conn->query("UPDATE users SET
                full_name = '{$name}',
                email     = '{$email}',
                phone     = '{$phone}',
                address   = '{$address}',
                is_active = {$status}
                WHERE id = {$id} AND role = 'customer'");
            redirect('customers.php', 'success', 'Customer updated successfully.');
        } else {
            redirect('customers.php', 'error', 'Name and email are required.');
        }
    }

    // Reset password
    if ($action === 'reset_password') {
        $id       = (int)($_POST['id'] ?? 0);
        $new_pass = trim($_POST['new_password'] ?? '');
        if ($id && strlen($new_pass) >= 6) {
            $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
            $conn->query("UPDATE users SET password = '{$hashed}' WHERE id = {$id} AND role = 'customer'");
            redirect('customers.php', 'success', 'Password reset successfully.');
        } else {
            redirect('customers.php', 'error', 'Password must be at least 6 characters.');
        }
    }
}

// ── Filters & Pagination ─────────────────────────────────────
$search      = trim($_GET['q'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$perPage     = 15;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $perPage;

$where = "WHERE role = 'customer'";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (full_name LIKE '%{$s}%' OR email LIKE '%{$s}%' OR phone LIKE '%{$s}%')";
}
if ($filterStatus === 'active')   $where .= " AND is_active = 1";
if ($filterStatus === 'inactive') $where .= " AND is_active = 0";

$totalResult = $conn->query("SELECT COUNT(*) AS cnt FROM users {$where}");
$totalCount  = (int)($totalResult->fetch_assoc()['cnt'] ?? 0);
$totalPages  = max(1, (int)ceil($totalCount / $perPage));

// Fetch customers with order counts
$customers = $conn->query("
    SELECT u.*,
           COUNT(o.id)                            AS total_orders,
           COALESCE(SUM(o.total_amount), 0)       AS total_spent,
           MAX(o.created_at)                      AS last_order_date
    FROM users u
    LEFT JOIN orders o ON o.user_id = u.id
    {$where}
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");

// Summary stats
$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role='customer'");
$totalCustomers = (int)($r->fetch_assoc()['cnt'] ?? 0);
$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role='customer' AND is_active=1");
$activeCustomers = (int)($r->fetch_assoc()['cnt'] ?? 0);
$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role='customer' AND is_active=0");
$inactiveCustomers = (int)($r->fetch_assoc()['cnt'] ?? 0);
$r = $conn->query("SELECT COUNT(DISTINCT user_id) AS cnt FROM orders WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$activeThisMonth = (int)($r->fetch_assoc()['cnt'] ?? 0);

$activePage = 'customers';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers — Hiney's Admin</title>
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
            flex-wrap: wrap;
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
            padding: 8px 12px 8px 34px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            width: 240px;
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

        .filter-select {
            padding: 8px 30px 8px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            background: var(--surface);
            color: var(--ink);
            outline: none;
            cursor: pointer;
            appearance: none;
            font-family: inherit;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239c968c' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }

        .filter-select:focus {
            border-color: var(--brand);
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

        /* Customer cell */
        .customer-cell {
            display: flex;
            align-items: center;
            gap: var(--s3);
        }

        .customer-name {
            font-weight: var(--fw-semi);
            color: var(--ink);
            font-size: var(--fs-sm);
        }

        .customer-email {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            white-space: nowrap;
        }

        .status-badge::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-badge.active {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .status-badge.inactive {
            background: var(--danger-tint);
            color: #b23c34;
        }

        /* Row actions — icon by default, expand to label on hover (matches Products) */
        .row-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .cact {
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

        .cact svg,
        .cact i {
            flex-shrink: 0;
        }

        .cact .act-label {
            max-width: 0;
            opacity: 0;
            margin-left: 0;
            transition: max-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.18s, margin-left 0.22s;
        }

        .cact:hover {
            width: auto;
            padding: 0 11px;
        }

        .cact:hover .act-label {
            max-width: 100px;
            opacity: 1;
            margin-left: 5px;
        }

        .cact-view {
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .cact-view:hover {
            background: var(--ink-2);
            color: #fff;
            border-color: var(--ink-2);
        }

        .cact-edit {
            color: var(--brand);
            border-color: var(--brand);
        }

        .cact-edit:hover {
            background: var(--brand);
            color: #fff;
        }

        .cact-deact {
            color: var(--danger);
            border-color: #f0c4c0;
        }

        .cact-deact:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
        }

        .cact-activ {
            color: var(--ok);
            border-color: #a7dcbc;
        }

        .cact-activ:hover {
            background: var(--ok);
            color: #fff;
            border-color: var(--ok);
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
            max-width: 560px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: var(--shadow-md);
            animation: modalIn 0.22s cubic-bezier(0.34, 1.4, 0.64, 1) both;
        }

        .modal-card.sm {
            max-width: 440px;
        }

        .modal-card.lg {
            max-width: 720px;
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

        .modal-title svg {
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

        /* Forms */
        .form-section-label {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--ink-3);
            margin-bottom: var(--s3);
            padding-bottom: 5px;
            border-bottom: 1px solid var(--line);
        }

        .form-section-label:not(:first-child) {
            margin-top: var(--s5);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--s3);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group.span-2 {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            color: var(--ink);
        }

        .form-label .req {
            color: var(--danger);
        }

        .form-input,
        .form-select,
        .form-textarea {
            padding: 9px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            color: var(--ink);
            background: #fff;
            outline: none;
            font-family: inherit;
            width: 100%;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .form-textarea {
            resize: vertical;
            min-height: 70px;
        }

        .form-select {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239c968c' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
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

        .btn-primary {
            background: var(--brand);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--brand-strong);
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

            .search-input {
                width: 100%;
            }

            .form-grid {
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
                    <h1 class="page-title">Customers</h1>
                    <div class="page-title-sub">Manage customer accounts, view order history, and control access</div>
                </div>
            </div>

            <?= flash() ?>

            <!-- Stat cards -->
            <div class="grid cols-2 mb-6" style="grid-template-columns:repeat(4,1fr);">
                <div class="stat-card tone-violet">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Customers</span>
                        <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalCustomers) ?></div>
                    <div class="stat-foot">All registered accounts</div>
                </div>
                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Active Accounts</span>
                        <div class="stat-icon"><i class="fa-solid fa-user-check"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($activeCustomers) ?></div>
                    <div class="stat-foot">Can log in and order</div>
                </div>
                <div class="stat-card tone-red">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Deactivated</span>
                        <div class="stat-icon"><i class="fa-solid fa-user-slash"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($inactiveCustomers) ?></div>
                    <div class="stat-foot">Access disabled</div>
                </div>
                <div class="stat-card tone-blue">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Ordered This Month</span>
                        <div class="stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($activeThisMonth) ?></div>
                    <div class="stat-foot">Last 30 days</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-title">All Customers</span>
                    <span class="count-pill"><?= number_format($totalCount) ?></span>
                    <form method="GET" style="display:flex;gap:var(--s3);align-items:center;flex-wrap:wrap;">
                        <div class="search-wrap">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                            <input type="text" name="q" class="search-input" placeholder="Search name, email, phone…" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="active" <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <?php if ($search || $filterStatus): ?>
                            <a href="customers.php" class="clear-link">✕ Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="table-card">
                <?php if ($customers && $customers->num_rows > 0): ?>
                    <div class="table-scroll">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th style="width:44px;">#</th>
                                    <th>Customer</th>
                                    <th>Phone</th>
                                    <th>Orders</th>
                                    <th>Total Spent</th>
                                    <th>Last Order</th>
                                    <th>Joined</th>
                                    <th>Status</th>
                                    <th style="text-align:center;min-width:120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rowNum = $offset + 1;
                                while ($c = $customers->fetch_assoc()):
                                    $initial = strtoupper(substr($c['full_name'], 0, 1));
                                ?>
                                    <tr>
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);font-weight:var(--fw-semi);"><?= $rowNum++ ?></td>
                                        <td>
                                            <div class="cell-lead">
                                                <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                                                <div>
                                                    <div class="customer-name"><?= htmlspecialchars($c['full_name']) ?></div>
                                                    <div class="customer-email"><?= htmlspecialchars($c['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="color:var(--ink-2);font-size:var(--fs-sm);"><?= $c['phone'] ? htmlspecialchars($c['phone']) : '—' ?></td>
                                        <td>
                                            <span style="font-weight:var(--fw-bold);color:var(--brand);"><?= number_format((int)$c['total_orders']) ?></span>
                                            <span style="font-size:var(--fs-xs);color:var(--ink-3);"> orders</span>
                                        </td>
                                        <td style="font-weight:var(--fw-semi);color:var(--ink);"><?= peso((float)$c['total_spent']) ?></td>
                                        <td style="font-size:var(--fs-xs);color:var(--ink-3);">
                                            <?= $c['last_order_date'] ? date('M j, Y', strtotime($c['last_order_date'])) : '—' ?>
                                        </td>
                                        <td style="font-size:var(--fs-xs);color:var(--ink-3);"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                                        <td>
                                            <span class="status-badge <?= $c['is_active'] ? 'active' : 'inactive' ?>">
                                                <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="row-actions">
                                                <button class="cact cact-view"
                                                    onclick="openView(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['full_name'])) ?>')">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                        <circle cx="12" cy="12" r="3" />
                                                    </svg><span class="act-label">View</span>
                                                </button>
                                                <button class="cact cact-edit"
                                                    onclick="openEdit(<?= htmlspecialchars(json_encode([
                                                                            'id'        => $c['id'],
                                                                            'full_name' => $c['full_name'],
                                                                            'email'     => $c['email'],
                                                                            'phone'     => $c['phone'] ?? '',
                                                                            'address'   => $c['address'] ?? '',
                                                                            'is_active' => $c['is_active'],
                                                                        ]), ENT_QUOTES) ?>)">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                                    </svg><span class="act-label">Edit</span>
                                                </button>
                                                <?php if ($c['is_active']): ?>
                                                    <button class="cact cact-deact"
                                                        onclick="openToggle(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['full_name'])) ?>', 0)">
                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <circle cx="12" cy="12" r="10" />
                                                            <line x1="15" y1="9" x2="9" y2="15" />
                                                            <line x1="9" y1="9" x2="15" y2="15" />
                                                        </svg><span class="act-label">Deactivate</span>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="cact cact-activ"
                                                        onclick="openToggle(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['full_name'])) ?>', 1)">
                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="20 6 9 17 4 12" />
                                                        </svg><span class="act-label">Activate</span>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <div>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?> customers</div>
                            <div class="pagination-pages">
                                <?php
                                $qs = http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]));
                                echo "<a href='?{$qs}' class='pg-btn" . ($page <= 1 ? ' disabled' : '') . "'>← Prev</a>";
                                $s2 = max(1, $page - 2);
                                $e2 = min($totalPages, $page + 2);
                                if ($s2 > 1) {
                                    $q1 = http_build_query(array_merge($_GET, ['page' => 1]));
                                    echo "<a href='?{$q1}' class='pg-btn'>1</a>";
                                    if ($s2 > 2) echo "<span class='pg-btn disabled'>…</span>";
                                }
                                for ($i = $s2; $i <= $e2; $i++) {
                                    $qi = http_build_query(array_merge($_GET, ['page' => $i]));
                                    echo "<a href='?{$qi}' class='pg-btn" . ($i == $page ? ' active' : '') . "'>{$i}</a>";
                                }
                                if ($e2 < $totalPages) {
                                    if ($e2 < $totalPages - 1) echo "<span class='pg-btn disabled'>…</span>";
                                    $qL = http_build_query(array_merge($_GET, ['page' => $totalPages]));
                                    echo "<a href='?{$qL}' class='pg-btn'>{$totalPages}</a>";
                                }
                                $qs2 = http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)]));
                                echo "<a href='?{$qs2}' class='pg-btn" . ($page >= $totalPages ? ' disabled' : '') . "'>Next →</a>";
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-users"></i></div>
                        <div class="empty-title">No customers found</div>
                        <div class="empty-text"><?= ($search || $filterStatus) ? 'Try adjusting your filters.' : 'Customer accounts will appear here.' ?></div>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /.main-content -->
    </div><!-- /.admin-layout -->

    <!-- ══ VIEW MODAL ══ -->
    <div class="modal-backdrop" id="viewModal" onclick="backdropClose(event,'viewModal')">
        <div class="modal-card lg" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    <span id="view_title">Customer Details</span>
                </div>
                <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
            </div>
            <div class="modal-body">
                <div id="view_content" style="color:var(--text-muted);text-align:center;padding:32px;">Loading…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- ══ EDIT MODAL ══ -->
    <div class="modal-backdrop" id="editModal" onclick="backdropClose(event,'editModal')">
        <div class="modal-card" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                    </svg>
                    Edit Customer
                </div>
                <button class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>
            <form method="POST" action="customers.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="form-section-label">Personal Information</div>
                    <div class="form-grid">
                        <div class="form-group span-2">
                            <label class="form-label">Full Name <span class="req">*</span></label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email Address <span class="req">*</span></label>
                            <input type="email" name="email" id="edit_email" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" id="edit_phone" class="form-input" placeholder="09XXXXXXXXX">
                        </div>
                        <div class="form-group span-2">
                            <label class="form-label">Delivery Address</label>
                            <textarea name="address" id="edit_address" class="form-textarea" placeholder="House No., Street, Barangay, City"></textarea>
                        </div>
                    </div>
                    <div class="form-section-label">Account Status</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="is_active" id="edit_is_active" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive / Deactivated</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ TOGGLE STATUS MODAL ══ -->
    <div class="modal-backdrop" id="toggleModal" onclick="backdropClose(event,'toggleModal')">
        <div class="modal-card sm" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title" id="toggle_title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <line x1="12" y1="9" x2="12" y2="13" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg>
                    Change Account Status
                </div>
                <button class="modal-close" onclick="closeModal('toggleModal')">✕</button>
            </div>
            <form method="POST" action="customers.php">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" id="toggle_id">
                <input type="hidden" name="is_active" id="toggle_status_val">
                <div class="modal-body" style="text-align:center;padding:28px 24px;">
                    <div style="font-size:3rem;margin-bottom:12px;" id="toggle_icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <div style="font-size:1rem;font-weight:700;color:var(--dark);margin-bottom:8px;" id="toggle_heading">Deactivate Account?</div>
                    <div style="font-size:0.88rem;color:var(--text-muted);line-height:1.6;" id="toggle_desc">
                        Are you sure you want to deactivate <strong id="toggle_name"></strong>?
                        They will no longer be able to log in.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('toggleModal')">Cancel</button>
                    <button type="submit" class="btn" id="toggle_submit_btn">Confirm</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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
                ['viewModal', 'editModal', 'toggleModal'].forEach(id => document.getElementById(id).classList.remove('open'));
                document.body.style.overflow = '';
            }
        });

        // ── Edit Modal ────────────────────────────────────────────────
        function openEdit(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_full_name').value = data.full_name;
            document.getElementById('edit_email').value = data.email;
            document.getElementById('edit_phone').value = data.phone || '';
            document.getElementById('edit_address').value = data.address || '';
            document.getElementById('edit_is_active').value = data.is_active;
            openModal('editModal');
        }

        // ── Toggle Status Modal ───────────────────────────────────────
        function openToggle(id, name, newStatus) {
            document.getElementById('toggle_id').value = id;
            document.getElementById('toggle_status_val').value = newStatus;
            document.getElementById('toggle_name').textContent = name;

            const btn = document.getElementById('toggle_submit_btn');
            if (newStatus === 0) {
                document.getElementById('toggle_icon').textContent = '<i class="fa-solid fa-ban"></i>';
                document.getElementById('toggle_heading').textContent = 'Deactivate Account?';
                document.getElementById('toggle_desc').innerHTML = `Are you sure you want to deactivate <strong>${name}</strong>? They will no longer be able to log in.`;
                btn.className = 'btn btn-danger';
                btn.textContent = 'Deactivate';
            } else {
                document.getElementById('toggle_icon').textContent = '✓';
                document.getElementById('toggle_heading').textContent = 'Activate Account?';
                document.getElementById('toggle_desc').innerHTML = `Are you sure you want to reactivate <strong>${name}</strong>'s account?`;
                btn.className = 'btn btn-success';
                btn.textContent = 'Activate';
            }
            openModal('toggleModal');
        }

        // ── View Modal (AJAX load) ────────────────────────────────────
        function openView(customerId, customerName) {
            document.getElementById('view_title').textContent = customerName;
            document.getElementById('view_content').innerHTML = '<div style="text-align:center;padding:32px;color:var(--text-muted);">Loading…</div>';
            openModal('viewModal');

            fetch('customer_detail_ajax.php?id=' + customerId)
                .then(r => r.text())
                .then(html => {
                    document.getElementById('view_content').innerHTML = html;
                })
                .catch(() => {
                    // Fallback: show basic info from the table row
                    document.getElementById('view_content').innerHTML =
                        '<div style="text-align:center;padding:32px;color:var(--text-muted);">Could not load customer details.<br>Please create <code>customer_detail_ajax.php</code> for this feature.</div>';
                });
        }

        // Search
        const si = document.querySelector('.search-input');
        if (si) si.addEventListener('keydown', e => {
            if (e.key === 'Enter') e.target.closest('form').submit();
        });
    </script>
</body>
</body>

</html>