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
:root { --card-border: #e9e8e4; }

.main-content {
    margin-left: var(--sidebar-w);
    flex: 1;
    padding: 32px 32px 48px;
    min-height: 100vh;
    background: var(--page-bg);
    box-sizing: border-box;
    width: calc(100% - var(--sidebar-w));
}

.page-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.page-title     { font-size: 1.5rem; font-weight: 800; color: var(--dark); letter-spacing: -0.02em; }
.page-title-sub { font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }

/* ── Stat cards ── */
.stats-row {
    display: grid; grid-template-columns: repeat(4,1fr);
    gap: 16px; margin-bottom: 24px;
}
@media(max-width:1100px){ .stats-row { grid-template-columns: repeat(2,1fr); } }
@media(max-width:600px) { .stats-row { grid-template-columns: 1fr; } }

.stat-card {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius); padding: 18px 20px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: var(--shadow); position: relative; overflow: hidden;
    transition: transform 0.18s, box-shadow 0.18s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
.stat-card-accent { position: absolute; top: 0; left: 0; width: 100%; height: 3px; }
.stat-icon-wrap { width: 44px; height: 44px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-body { flex: 1; }
.stat-value { font-size: 1.7rem; font-weight: 800; color: var(--dark); line-height: 1; letter-spacing: -0.03em; }
.stat-label { font-size: 0.73rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.07em; font-weight: 700; margin-top: 4px; }

.sc-purple .stat-card-accent { background: #8b5cf6; } .sc-purple .stat-icon-wrap { background: #f5f3ff; color: #8b5cf6; }
.sc-green  .stat-card-accent { background: #10b981; } .sc-green  .stat-icon-wrap { background: #ecfdf5; color: #10b981; }
.sc-red    .stat-card-accent { background: #ef4444; } .sc-red    .stat-icon-wrap { background: #fef2f2; color: #ef4444; }
.sc-blue   .stat-card-accent { background: #3b82f6; } .sc-blue   .stat-icon-wrap { background: #eff6ff; color: #3b82f6; }

/* ── Toolbar ── */
.toolbar {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius) var(--radius) 0 0;
    padding: 14px 18px; display: flex; align-items: center;
    gap: 10px; flex-wrap: wrap; border-bottom: none;
    width: 100%; box-sizing: border-box;
}
.toolbar-left  { display: flex; align-items: center; gap: 10px; flex: 1; flex-wrap: wrap; }
.toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.toolbar-title { font-size: 0.95rem; font-weight: 700; color: var(--dark); }
.count-pill { background: var(--primary); color: #fff; font-size: 0.72rem; font-weight: 700; padding: 2px 10px; border-radius: 20px; }

.search-wrap { position: relative; display: flex; align-items: center; }
.search-wrap svg { position: absolute; left: 10px; color: var(--text-muted); pointer-events: none; }
.search-input {
    padding: 7px 12px 7px 34px; border: 1px solid var(--card-border);
    border-radius: 8px; font-size: 0.85rem; width: 220px;
    background: var(--page-bg); color: var(--text); outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.12); }

.filter-select {
    padding: 7px 28px 7px 10px; border: 1px solid var(--card-border);
    border-radius: 8px; font-size: 0.85rem; background: var(--page-bg);
    color: var(--text); outline: none; cursor: pointer; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 9px center;
}
.filter-select:focus { border-color: var(--primary); outline: none; }

/* ── Table ── */
.table-wrapper {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: 0 0 var(--radius) var(--radius);
    overflow-x: auto; box-shadow: var(--shadow);
    width: 100%; box-sizing: border-box;
}
table.data-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
table.data-table thead th {
    background: var(--dark); color: #e5e7eb; font-size: 0.72rem;
    font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em;
    padding: 12px 14px; white-space: nowrap; text-align: left;
}
table.data-table tbody tr:nth-child(even) { background: #faf9f7; }
table.data-table tbody tr:hover { background: #fef9f4; transition: background 0.12s; }
table.data-table tbody td { padding: 12px 14px; color: var(--text); border-bottom: 1px solid #f3f2f0; vertical-align: middle; }
table.data-table tbody tr:last-child td { border-bottom: none; }

/* Customer cell */
.customer-cell { display: flex; align-items: center; gap: 10px; }
.customer-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, #e67e22, #f39c12);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.95rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
}
.customer-name  { font-weight: 600; color: var(--dark); font-size: 0.88rem; }
.customer-email { font-size: 0.75rem; color: var(--text-muted); margin-top: 1px; }

/* Status badge */
.status-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 20px;
    font-size: 0.73rem; font-weight: 600;
}
.status-badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.status-badge.active   { background: #d1fae5; color: #065f46; }
.status-badge.inactive { background: #fee2e2; color: #991b1b; }

/* Action buttons */
.btn-action {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 10px; border-radius: 6px; font-size: 0.75rem;
    font-weight: 600; cursor: pointer; border: 1px solid;
    background: transparent; transition: background 0.15s, color 0.15s; white-space: nowrap;
}
.btn-view         { color: #3b82f6; border-color: #3b82f6; }
.btn-view:hover   { background: #3b82f6; color: #fff; }
.btn-edit-c       { color: var(--primary); border-color: var(--primary); }
.btn-edit-c:hover { background: var(--primary); color: #fff; }
.btn-deact        { color: #ef4444; border-color: #ef4444; }
.btn-deact:hover  { background: #ef4444; color: #fff; }
.btn-activ        { color: #10b981; border-color: #10b981; }
.btn-activ:hover  { background: #10b981; color: #fff; }

/* Pagination */
.pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-top: 1px solid var(--card-border);
    font-size: 0.82rem; color: var(--text-muted); flex-wrap: wrap; gap: 8px;
}
.pagination-pages { display: flex; align-items: center; gap: 4px; }
.pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px;
    border: 1px solid var(--card-border); background: var(--card-bg);
    color: var(--text); font-size: 0.82rem; font-weight: 500;
    cursor: pointer; text-decoration: none; transition: background 0.15s, border-color 0.15s;
}
.pg-btn:hover    { background: var(--page-bg); }
.pg-btn.active   { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 700; }
.pg-btn.disabled { opacity: 0.4; pointer-events: none; }

/* Empty state */
.empty-state { padding: 56px 20px; text-align: center; color: var(--text-muted); }
.empty-icon  { font-size: 3rem; margin-bottom: 12px; }
.empty-text  { font-size: 0.9rem; }

/* ── Modals ── */
.modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,0.45);
    backdrop-filter: blur(3px); z-index: 1000;
    display: none; align-items: center; justify-content: center; padding: 20px;
}
.modal-backdrop.open { display: flex; }
.modal-card {
    background: var(--card-bg); border-radius: 14px; width: 100%;
    max-width: 620px; max-height: 92vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    animation: modalIn 0.22s cubic-bezier(0.34,1.56,0.64,1) both;
}
.modal-card.sm  { max-width: 480px; }
.modal-card.lg  { max-width: 760px; }
@keyframes modalIn {
    from { opacity: 0; transform: translateY(18px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 20px 24px 16px; border-bottom: 1px solid var(--card-border);
    position: sticky; top: 0; background: var(--card-bg); z-index: 1;
    border-radius: 14px 14px 0 0;
}
.modal-title { font-size: 1rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.modal-close {
    width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
    border: none; background: var(--page-bg); border-radius: 7px; cursor: pointer;
    color: var(--text-muted); font-size: 1rem; transition: background 0.15s, color 0.15s;
}
.modal-close:hover { background: #fee2e2; color: #ef4444; }
.modal-body   { padding: 20px 24px; }
.modal-footer {
    display: flex; align-items: center; justify-content: flex-end; gap: 10px;
    padding: 14px 24px; border-top: 1px solid var(--card-border);
    background: var(--page-bg); border-radius: 0 0 14px 14px;
    position: sticky; bottom: 0;
}

/* Form */
.form-section-label {
    font-size: 0.68rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.1em; color: var(--primary);
    margin: 18px 0 10px; padding-bottom: 6px; border-bottom: 1px solid #fde9d0;
}
.form-section-label:first-child { margin-top: 0; }
.form-grid         { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-group        { display: flex; flex-direction: column; gap: 5px; }
.form-group.span-2 { grid-column: span 2; }
.form-label        { font-size: 0.8rem; font-weight: 600; color: var(--dark); }
.form-label .req   { color: #ef4444; margin-left: 2px; }
.form-input, .form-select, .form-textarea {
    padding: 9px 12px; border: 1px solid var(--card-border); border-radius: 8px;
    font-size: 0.87rem; color: var(--text); background: #fff; outline: none;
    font-family: inherit; width: 100%; transition: border-color 0.15s, box-shadow 0.15s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.12);
}
.form-textarea { resize: vertical; min-height: 72px; }
.form-hint     { font-size: 0.72rem; color: var(--text-muted); }

/* Buttons */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 8px; font-size: 0.88rem;
    font-weight: 600; cursor: pointer; border: 1px solid;
    transition: background 0.15s, transform 0.1s; font-family: inherit;
}
.btn:active          { transform: translateY(1px); }
.btn-primary         { background: var(--primary); color: #fff; border-color: var(--primary); }
.btn-primary:hover   { background: #cf6d17; border-color: #cf6d17; }
.btn-ghost           { background: transparent; color: var(--text-muted); border-color: var(--card-border); }
.btn-ghost:hover     { background: var(--page-bg); color: var(--text); }
.btn-danger          { background: #ef4444; color: #fff; border-color: #ef4444; }
.btn-danger:hover    { background: #dc2626; }
.btn-success         { background: #10b981; color: #fff; border-color: #10b981; }
.btn-success:hover   { background: #059669; }

/* View modal details */
.customer-profile-header {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 20px; background: var(--page-bg);
    border-radius: 10px; margin-bottom: 20px;
    border: 1px solid var(--card-border);
}
.customer-profile-avatar {
    width: 54px; height: 54px; border-radius: 50%;
    background: linear-gradient(135deg, #e67e22, #f39c12);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.customer-profile-name  { font-size: 1rem; font-weight: 700; color: var(--dark); }
.customer-profile-email { font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }
.customer-profile-since { font-size: 0.75rem; color: var(--text-muted); margin-top: 4px; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
.detail-item { background: var(--page-bg); border: 1px solid var(--card-border); border-radius: 8px; padding: 10px 14px; }
.detail-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--text-muted); margin-bottom: 4px; }
.detail-value { font-size: 0.9rem; font-weight: 600; color: var(--dark); }

/* Order history in view modal */
.order-mini-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; margin-top: 10px; }
.order-mini-table thead th {
    background: #f3f4f6; color: var(--text-muted); font-size: 0.7rem;
    font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em;
    padding: 8px 12px; text-align: left;
}
.order-mini-table tbody tr:hover { background: #fef9f4; }
.order-mini-table tbody td { padding: 9px 12px; border-bottom: 1px solid #f3f2f0; color: var(--text); vertical-align: middle; }
.order-mini-table tbody tr:last-child td { border-bottom: none; }

/* Mobile */
.mobile-menu-btn {
    display: none; align-items: center; justify-content: center;
    width: 36px; height: 36px; border: 1px solid var(--card-border);
    border-radius: 8px; background: var(--card-bg); cursor: pointer; color: var(--dark);
}
@media(max-width:768px) {
    .main-content    { margin-left: 0; padding: 16px 16px 48px; width: 100%; }
    .mobile-menu-btn { display: flex; }
    .stats-row       { grid-template-columns: repeat(2,1fr); }
    .form-grid       { grid-template-columns: 1fr; }
    .form-group.span-2 { grid-column: span 1; }
    .detail-grid     { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="admin-layout">
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <button class="mobile-menu-btn" onclick="openSidebar()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h1 class="page-title">Customers</h1>
            </div>
            <div class="page-title-sub">Manage customer accounts, view order history, and control access</div>
        </div>
    </div>

    <?= flash() ?>

    <!-- Stat Cards -->
    <div class="stats-row">
        <div class="stat-card sc-purple">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format($totalCustomers) ?></div>
                <div class="stat-label">Total Customers</div>
            </div>
        </div>

        <div class="stat-card sc-green">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format($activeCustomers) ?></div>
                <div class="stat-label">Active Accounts</div>
            </div>
        </div>

        <div class="stat-card sc-red">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format($inactiveCustomers) ?></div>
                <div class="stat-label">Deactivated</div>
            </div>
        </div>

        <div class="stat-card sc-blue">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format($activeThisMonth) ?></div>
                <div class="stat-label">Ordered This Month</div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <span class="toolbar-title">All Customers</span>
            <span class="count-pill"><?= number_format($totalCount) ?></span>
            <form method="GET" style="display:contents;">
                <div class="search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" class="search-input" placeholder="Search name, email, phone…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <?php if ($search || $filterStatus): ?>
                    <a href="customers.php" style="font-size:0.8rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <?php if ($customers && $customers->num_rows > 0): ?>
        <table class="data-table">
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
                    <th style="text-align:center;width:160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $rowNum = $offset + 1;
            while ($c = $customers->fetch_assoc()):
                $initial = strtoupper(substr($c['full_name'], 0, 1));
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $rowNum++ ?></td>
                <td>
                    <div class="customer-cell">
                        <div class="customer-avatar"><?= htmlspecialchars($initial) ?></div>
                        <div>
                            <div class="customer-name"><?= htmlspecialchars($c['full_name']) ?></div>
                            <div class="customer-email"><?= htmlspecialchars($c['email']) ?></div>
                        </div>
                    </div>
                </td>
                <td style="color:var(--text-muted);font-size:0.84rem;"><?= $c['phone'] ? htmlspecialchars($c['phone']) : '—' ?></td>
                <td>
                    <span style="font-weight:700;color:var(--primary);"><?= number_format((int)$c['total_orders']) ?></span>
                    <span style="font-size:0.75rem;color:var(--text-muted);"> orders</span>
                </td>
                <td style="font-weight:600;"><?= peso((float)$c['total_spent']) ?></td>
                <td style="font-size:0.82rem;color:var(--text-muted);">
                    <?= $c['last_order_date'] ? date('M j, Y', strtotime($c['last_order_date'])) : '—' ?>
                </td>
                <td style="font-size:0.82rem;color:var(--text-muted);"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                <td>
                    <span class="status-badge <?= $c['is_active'] ? 'active' : 'inactive' ?>">
                        <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td style="text-align:center;">
                    <div style="display:flex;align-items:center;justify-content:center;gap:5px;">
                        <button class="btn-action btn-view"
                            onclick="openView(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['full_name'])) ?>')">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            View
                        </button>
                        <button class="btn-action btn-edit-c"
                            onclick="openEdit(<?= htmlspecialchars(json_encode([
                                'id'        => $c['id'],
                                'full_name' => $c['full_name'],
                                'email'     => $c['email'],
                                'phone'     => $c['phone'] ?? '',
                                'address'   => $c['address'] ?? '',
                                'is_active' => $c['is_active'],
                            ]), ENT_QUOTES) ?>)">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Edit
                        </button>
                        <?php if ($c['is_active']): ?>
                        <button class="btn-action btn-deact"
                            onclick="openToggle(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['full_name'])) ?>', 0)">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            Deactivate
                        </button>
                        <?php else: ?>
                        <button class="btn-action btn-activ"
                            onclick="openToggle(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['full_name'])) ?>', 1)">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            Activate
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div>Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$perPage,$totalCount)) ?> of <?= number_format($totalCount) ?> customers</div>
            <div class="pagination-pages">
                <?php
                $qs = http_build_query(array_merge($_GET,['page'=>max(1,$page-1)]));
                echo "<a href='?{$qs}' class='pg-btn".($page<=1?' disabled':'')."'>← Prev</a>";
                $s2 = max(1,$page-2); $e2 = min($totalPages,$page+2);
                if($s2>1){$q1=http_build_query(array_merge($_GET,['page'=>1]));echo"<a href='?{$q1}' class='pg-btn'>1</a>";if($s2>2)echo"<span class='pg-btn disabled'>…</span>";}
                for($i=$s2;$i<=$e2;$i++){$qi=http_build_query(array_merge($_GET,['page'=>$i]));echo"<a href='?{$qi}' class='pg-btn".($i==$page?' active':'')."'>{$i}</a>";}
                if($e2<$totalPages){if($e2<$totalPages-1)echo"<span class='pg-btn disabled'>…</span>";$qL=http_build_query(array_merge($_GET,['page'=>$totalPages]));echo"<a href='?{$qL}' class='pg-btn'>{$totalPages}</a>";}
                $qs2=http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)]));
                echo "<a href='?{$qs2}' class='pg-btn".($page>=$totalPages?' disabled':'')."'>Next →</a>";
                ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">👥</div>
            <div class="empty-text">No customers found<?= ($search||$filterStatus) ? ' — try adjusting filters.' : '.' ?></div>
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
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
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
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
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
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
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
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Change Account Status
            </div>
            <button class="modal-close" onclick="closeModal('toggleModal')">✕</button>
        </div>
        <form method="POST" action="customers.php">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="id" id="toggle_id">
            <input type="hidden" name="is_active" id="toggle_status_val">
            <div class="modal-body" style="text-align:center;padding:28px 24px;">
                <div style="font-size:3rem;margin-bottom:12px;" id="toggle_icon">⚠️</div>
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
function openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }
function backdropClose(e, id) { if (e.target === document.getElementById(id)) closeModal(id); }

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        ['viewModal','editModal','toggleModal'].forEach(id => document.getElementById(id).classList.remove('open'));
        document.body.style.overflow = '';
    }
});

// ── Edit Modal ────────────────────────────────────────────────
function openEdit(data) {
    document.getElementById('edit_id').value        = data.id;
    document.getElementById('edit_full_name').value = data.full_name;
    document.getElementById('edit_email').value     = data.email;
    document.getElementById('edit_phone').value     = data.phone || '';
    document.getElementById('edit_address').value   = data.address || '';
    document.getElementById('edit_is_active').value = data.is_active;
    openModal('editModal');
}

// ── Toggle Status Modal ───────────────────────────────────────
function openToggle(id, name, newStatus) {
    document.getElementById('toggle_id').value          = id;
    document.getElementById('toggle_status_val').value  = newStatus;
    document.getElementById('toggle_name').textContent  = name;

    const btn = document.getElementById('toggle_submit_btn');
    if (newStatus === 0) {
        document.getElementById('toggle_icon').textContent    = '🚫';
        document.getElementById('toggle_heading').textContent = 'Deactivate Account?';
        document.getElementById('toggle_desc').innerHTML      = `Are you sure you want to deactivate <strong>${name}</strong>? They will no longer be able to log in.`;
        btn.className  = 'btn btn-danger';
        btn.textContent = 'Deactivate';
    } else {
        document.getElementById('toggle_icon').textContent    = '✅';
        document.getElementById('toggle_heading').textContent = 'Activate Account?';
        document.getElementById('toggle_desc').innerHTML      = `Are you sure you want to reactivate <strong>${name}</strong>'s account?`;
        btn.className  = 'btn btn-success';
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
if (si) si.addEventListener('keydown', e => { if (e.key === 'Enter') e.target.closest('form').submit(); });
</script>
</body>
</html>