<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/transactions.php
// Purpose: Sales transactions — view, add, filter by date/method
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

// ── POST Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // Add transaction manually
    if ($action === 'add') {
        $order_id   = (int)($_POST['order_id'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);
        $method     = clean($_POST['payment_method'] ?? 'cash', $conn);
        $ref_no     = clean($_POST['reference_no'] ?? '', $conn);
        $txn_date   = clean($_POST['transaction_date'] ?? date('Y-m-d H:i:s'), $conn);
        $notes      = clean($_POST['notes'] ?? '', $conn);

        if (!in_array($method, ['cash','gcash','cod'])) $method = 'cash';

        if ($order_id && $amount > 0) {
            // Check order exists
            $chk = $conn->query("SELECT id, total_amount FROM orders WHERE id = {$order_id} LIMIT 1");
            if ($chk && $chk->num_rows > 0) {
                $conn->query("INSERT INTO transactions
                    (order_id, amount, payment_method, reference_no, transaction_date, notes)
                    VALUES ({$order_id}, {$amount}, '{$method}', '{$ref_no}', '{$txn_date}', '{$notes}')");

                // Auto-mark order as paid
                $conn->query("UPDATE orders SET payment_status = 'paid', updated_at = NOW()
                              WHERE id = {$order_id}");

                redirect('transactions.php', 'success', 'Transaction recorded and order marked as paid.');
            } else {
                redirect('transactions.php', 'error', "Order #{$order_id} not found.");
            }
        } else {
            redirect('transactions.php', 'error', 'Order ID and amount are required.');
        }
    }

    // Delete transaction
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            // Get order_id before deleting
            $r = $conn->query("SELECT order_id FROM transactions WHERE id = {$id} LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) {
                $oid = (int)$row['order_id'];
                $conn->query("DELETE FROM transactions WHERE id = {$id}");
                // Recheck if order has any remaining transactions
                $remaining = $conn->query("SELECT id FROM transactions WHERE order_id = {$oid} LIMIT 1");
                if ($remaining && $remaining->num_rows === 0) {
                    $conn->query("UPDATE orders SET payment_status = 'unpaid', updated_at = NOW() WHERE id = {$oid}");
                }
                redirect('transactions.php', 'success', 'Transaction deleted.');
            } else {
                redirect('transactions.php', 'error', 'Transaction not found.');
            }
        }
    }

    // Edit/update transaction notes & reference
    if ($action === 'edit') {
        $id     = (int)($_POST['id'] ?? 0);
        $ref_no = clean($_POST['reference_no'] ?? '', $conn);
        $notes  = clean($_POST['notes'] ?? '', $conn);
        if ($id) {
            $conn->query("UPDATE transactions SET reference_no = '{$ref_no}', notes = '{$notes}' WHERE id = {$id}");
            redirect('transactions.php', 'success', 'Transaction updated.');
        }
    }
}

// ── Filters ──────────────────────────────────────────────────
$search      = trim($_GET['q'] ?? '');
$filterMethod = trim($_GET['method'] ?? '');
$dateFrom    = trim($_GET['from'] ?? '');
$dateTo      = trim($_GET['to'] ?? '');
$perPage     = 15;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (u.full_name LIKE '%{$s}%' OR t.reference_no LIKE '%{$s}%' OR t.order_id LIKE '%{$s}%')";
}
if ($filterMethod) $where .= " AND t.payment_method = '{$conn->real_escape_string($filterMethod)}'";
if ($dateFrom)     $where .= " AND DATE(t.transaction_date) >= '{$conn->real_escape_string($dateFrom)}'";
if ($dateTo)       $where .= " AND DATE(t.transaction_date) <= '{$conn->real_escape_string($dateTo)}'";

$countResult = $conn->query("
    SELECT COUNT(*) AS cnt FROM transactions t
    JOIN orders o ON o.id = t.order_id
    JOIN users u ON u.id = o.user_id
    {$where}
");
$totalCount = (int)($countResult->fetch_assoc()['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$transactions = $conn->query("
    SELECT t.*,
           o.status AS order_status, o.total_amount AS order_total,
           u.full_name, u.email
    FROM transactions t
    JOIN orders o ON o.id = t.order_id
    JOIN users u ON u.id = o.user_id
    {$where}
    ORDER BY t.transaction_date DESC
    LIMIT {$perPage} OFFSET {$offset}
");

// ── Summary Stats ─────────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM transactions");
$row = $r->fetch_assoc();
$totalTxns    = (int)$row['cnt'];
$totalRevenue = (float)$row['total'];

$r = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM transactions WHERE payment_method = 'gcash'");
$gcashTotal = (float)($r->fetch_assoc()['total'] ?? 0);

$r = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM transactions WHERE payment_method IN ('cash','cod')");
$cashTotal = (float)($r->fetch_assoc()['total'] ?? 0);

$r = $conn->query("SELECT COALESCE(SUM(amount),0) AS total FROM transactions WHERE DATE(transaction_date) = CURDATE()");
$todayTotal = (float)($r->fetch_assoc()['total'] ?? 0);

// ── Orders for Add modal dropdown ────────────────────────────
// Show unpaid orders only (max 50 recent)
$unpaidOrders = $conn->query("
    SELECT o.id, u.full_name, o.total_amount, o.payment_method, o.created_at
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE o.payment_status = 'unpaid' AND o.status != 'cancelled'
    ORDER BY o.created_at DESC
    LIMIT 50
");

$activePage = 'transactions';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transactions — Hiney's Admin</title>
<style>
:root { --card-border: #e9e8e4; }

.main-content {
    margin-left: var(--sidebar-w); flex: 1;
    padding: 32px 32px 48px; min-height: 100vh;
    background: var(--page-bg); box-sizing: border-box;
    width: calc(100% - var(--sidebar-w));
}

.page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.page-title     { font-size:1.5rem; font-weight:800; color:var(--dark); letter-spacing:-0.02em; }
.page-title-sub { font-size:0.82rem; color:var(--text-muted); margin-top:2px; }

/* Stat cards */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
@media(max-width:1100px){ .stats-row{grid-template-columns:repeat(2,1fr);} }
@media(max-width:600px) { .stats-row{grid-template-columns:1fr;} }
.stat-card {
    background:var(--card-bg); border:1px solid var(--card-border);
    border-radius:var(--radius); padding:18px 20px;
    display:flex; align-items:center; gap:14px;
    box-shadow:var(--shadow); position:relative; overflow:hidden;
    transition:transform 0.18s, box-shadow 0.18s;
}
.stat-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
.stat-card-accent { position:absolute; top:0; left:0; width:100%; height:3px; }
.stat-icon-wrap { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.stat-body  { flex:1; min-width:0; }
.stat-value { font-size:1.35rem; font-weight:800; color:var(--dark); line-height:1; letter-spacing:-0.02em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.stat-label { font-size:0.73rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.07em; font-weight:700; margin-top:4px; }
.sc-green  .stat-card-accent{background:#10b981;} .sc-green  .stat-icon-wrap{background:#ecfdf5;color:#10b981;}
.sc-blue   .stat-card-accent{background:#3b82f6;} .sc-blue   .stat-icon-wrap{background:#eff6ff;color:#3b82f6;}
.sc-purple .stat-card-accent{background:#8b5cf6;} .sc-purple .stat-icon-wrap{background:#f5f3ff;color:#8b5cf6;}
.sc-orange .stat-card-accent{background:#e67e22;} .sc-orange .stat-icon-wrap{background:#fef3e8;color:#e67e22;}

/* Toolbar */
.toolbar {
    background:var(--card-bg); border:1px solid var(--card-border);
    border-radius:var(--radius) var(--radius) 0 0;
    padding:14px 18px; display:flex; align-items:center;
    gap:10px; flex-wrap:wrap; border-bottom:none; box-sizing:border-box;
}
.toolbar-left  { display:flex; align-items:center; gap:8px; flex:1; flex-wrap:wrap; }
.toolbar-right { display:flex; align-items:center; gap:8px; flex-shrink:0; }
.toolbar-title { font-size:0.95rem; font-weight:700; color:var(--dark); }
.count-pill { background:var(--primary); color:#fff; font-size:0.72rem; font-weight:700; padding:2px 10px; border-radius:20px; }

.search-wrap { position:relative; display:flex; align-items:center; }
.search-wrap svg { position:absolute; left:10px; color:var(--text-muted); pointer-events:none; }
.search-input {
    padding:7px 12px 7px 34px; border:1px solid var(--card-border);
    border-radius:8px; font-size:0.85rem; width:200px;
    background:var(--page-bg); color:var(--text); outline:none;
    transition:border-color 0.15s, box-shadow 0.15s;
}
.search-input:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(230,126,34,0.12); }

.filter-select {
    padding:7px 28px 7px 10px; border:1px solid var(--card-border);
    border-radius:8px; font-size:0.83rem; background:var(--page-bg);
    color:var(--text); outline:none; cursor:pointer; appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 9px center;
}
.filter-select:focus { border-color:var(--primary); outline:none; }

.date-input {
    padding:7px 10px; border:1px solid var(--card-border);
    border-radius:8px; font-size:0.83rem; background:var(--page-bg);
    color:var(--text); outline:none; cursor:pointer;
}
.date-input:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(230,126,34,0.12); }

.btn-add {
    display:flex; align-items:center; gap:6px; padding:8px 16px;
    background:var(--primary); color:#fff; border:none; border-radius:8px;
    font-size:0.85rem; font-weight:600; cursor:pointer;
    transition:background 0.15s, transform 0.1s; white-space:nowrap;
}
.btn-add:hover { background:#cf6d17; transform:translateY(-1px); }

/* Table */
.table-wrapper {
    background:var(--card-bg); border:1px solid var(--card-border);
    border-radius:0 0 var(--radius) var(--radius);
    overflow-x:auto; box-shadow:var(--shadow); box-sizing:border-box;
}
table.data-table { width:100%; border-collapse:collapse; font-size:0.87rem; }
table.data-table thead th {
    background:var(--dark); color:#e5e7eb; font-size:0.7rem;
    font-weight:700; text-transform:uppercase; letter-spacing:0.07em;
    padding:12px 14px; white-space:nowrap; text-align:left;
}
table.data-table tbody tr:nth-child(even) { background:#faf9f7; }
table.data-table tbody tr:hover { background:#fef9f4; transition:background 0.12s; }
table.data-table tbody td { padding:11px 14px; color:var(--text); border-bottom:1px solid #f3f2f0; vertical-align:middle; }
table.data-table tbody tr:last-child td { border-bottom:none; }

/* Transaction ID cell */
.txn-id    { font-weight:700; color:var(--primary); font-size:0.9rem; }
.txn-date  { font-size:0.75rem; color:var(--text-muted); margin-top:2px; }

/* Customer cell */
.customer-cell { display:flex; align-items:center; gap:8px; }
.customer-avatar {
    width:30px; height:30px; border-radius:50%;
    background:linear-gradient(135deg,#e67e22,#f39c12);
    display:flex; align-items:center; justify-content:center;
    font-size:0.75rem; font-weight:700; color:#fff; flex-shrink:0;
}
.customer-name { font-weight:600; color:var(--dark); font-size:0.86rem; }
.customer-sub  { font-size:0.72rem; color:var(--text-muted); }

/* Amount */
.amount-cell { font-size:1rem; font-weight:800; color:#065f46; }

/* Method badge */
.method-badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 10px; border-radius:20px;
    font-size:0.73rem; font-weight:700; white-space:nowrap;
}
.m-gcash { background:#ede9fe; color:#5b21b6; }
.m-cash  { background:#d1fae5; color:#065f46; }
.m-cod   { background:#dbeafe; color:#1e40af; }

/* Order status mini */
.o-status {
    display:inline-flex; align-items:center; gap:3px;
    padding:2px 8px; border-radius:12px; font-size:0.7rem; font-weight:600;
}
.os-delivered { background:#d1fae5; color:#065f46; }
.os-other     { background:#f3f4f6; color:#374151; }

/* Ref no */
.ref-no { font-family:monospace; font-size:0.82rem; color:var(--text); }
.ref-none { color:var(--text-muted); font-style:italic; font-size:0.8rem; }

/* Action buttons */
.btn-action {
    display:inline-flex; align-items:center; gap:4px;
    padding:5px 10px; border-radius:6px; font-size:0.75rem;
    font-weight:600; cursor:pointer; border:1px solid;
    background:transparent; transition:background 0.15s, color 0.15s; white-space:nowrap;
}
.btn-view-t       { color:#3b82f6; border-color:#3b82f6; }
.btn-view-t:hover { background:#3b82f6; color:#fff; }
.btn-edit-t       { color:var(--primary); border-color:var(--primary); }
.btn-edit-t:hover { background:var(--primary); color:#fff; }
.btn-del          { color:#ef4444; border-color:#ef4444; }
.btn-del:hover    { background:#ef4444; color:#fff; }

/* Export CSV button */
.btn-export {
    display:flex; align-items:center; gap:6px; padding:7px 14px;
    background:transparent; color:var(--text-muted);
    border:1px solid var(--card-border); border-radius:8px;
    font-size:0.83rem; font-weight:600; cursor:pointer;
    transition:all 0.15s;
}
.btn-export:hover { background:var(--page-bg); color:var(--dark); border-color:#aaa; }

/* Pagination */
.pagination {
    display:flex; align-items:center; justify-content:space-between;
    padding:14px 18px; border-top:1px solid var(--card-border);
    font-size:0.82rem; color:var(--text-muted); flex-wrap:wrap; gap:8px;
}
.pagination-pages { display:flex; align-items:center; gap:4px; }
.pg-btn {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:32px; height:32px; padding:0 8px; border-radius:6px;
    border:1px solid var(--card-border); background:var(--card-bg);
    color:var(--text); font-size:0.82rem; font-weight:500;
    cursor:pointer; text-decoration:none; transition:background 0.15s;
}
.pg-btn:hover    { background:var(--page-bg); }
.pg-btn.active   { background:var(--primary); color:#fff; border-color:var(--primary); font-weight:700; }
.pg-btn.disabled { opacity:0.4; pointer-events:none; }

/* Empty state */
.empty-state { padding:56px 20px; text-align:center; color:var(--text-muted); }
.empty-icon  { font-size:3rem; margin-bottom:12px; }

/* Modals */
.modal-backdrop {
    position:fixed; inset:0; background:rgba(0,0,0,0.45);
    backdrop-filter:blur(3px); z-index:1000;
    display:none; align-items:center; justify-content:center; padding:20px;
}
.modal-backdrop.open { display:flex; }
.modal-card {
    background:var(--card-bg); border-radius:14px; width:100%;
    max-width:580px; max-height:92vh; overflow-y:auto;
    box-shadow:0 20px 60px rgba(0,0,0,0.2);
    animation:modalIn 0.22s cubic-bezier(0.34,1.56,0.64,1) both;
}
.modal-card.sm { max-width:460px; }
@keyframes modalIn {
    from { opacity:0; transform:translateY(18px) scale(0.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
.modal-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:20px 24px 16px; border-bottom:1px solid var(--card-border);
    position:sticky; top:0; background:var(--card-bg); z-index:1;
    border-radius:14px 14px 0 0;
}
.modal-title { font-size:1rem; font-weight:700; color:var(--dark); display:flex; align-items:center; gap:8px; }
.modal-close {
    width:30px; height:30px; display:flex; align-items:center; justify-content:center;
    border:none; background:var(--page-bg); border-radius:7px; cursor:pointer;
    color:var(--text-muted); font-size:1rem; transition:background 0.15s, color 0.15s;
}
.modal-close:hover { background:#fee2e2; color:#ef4444; }
.modal-body   { padding:20px 24px; }
.modal-footer {
    display:flex; align-items:center; justify-content:flex-end; gap:10px;
    padding:14px 24px; border-top:1px solid var(--card-border);
    background:var(--page-bg); border-radius:0 0 14px 14px; position:sticky; bottom:0;
}

/* Form */
.form-section-label {
    font-size:0.68rem; font-weight:800; text-transform:uppercase;
    letter-spacing:0.1em; color:var(--primary);
    margin:18px 0 10px; padding-bottom:6px; border-bottom:1px solid #fde9d0;
}
.form-section-label:first-child { margin-top:0; }
.form-grid         { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.form-group        { display:flex; flex-direction:column; gap:5px; }
.form-group.span-2 { grid-column:span 2; }
.form-label        { font-size:0.8rem; font-weight:600; color:var(--dark); }
.form-label .req   { color:#ef4444; margin-left:2px; }
.form-input, .form-select, .form-textarea {
    padding:9px 12px; border:1px solid var(--card-border); border-radius:8px;
    font-size:0.87rem; color:var(--text); background:#fff; outline:none;
    font-family:inherit; width:100%; transition:border-color 0.15s, box-shadow 0.15s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color:var(--primary); box-shadow:0 0 0 3px rgba(230,126,34,0.12);
}
.form-input[readonly] { background:var(--page-bg); color:var(--text-muted); cursor:default; }
.form-textarea { resize:vertical; min-height:72px; }
.form-hint { font-size:0.72rem; color:var(--text-muted); }

/* Order summary box in Add modal */
.order-preview {
    background:var(--page-bg); border:1px solid var(--card-border);
    border-radius:8px; padding:12px 14px; margin-top:8px;
    font-size:0.85rem; display:none;
}
.order-preview.show { display:block; }
.order-preview-row  { display:flex; justify-content:space-between; margin-bottom:4px; }
.order-preview-row:last-child { margin-bottom:0; font-weight:700; color:var(--primary); }

/* View detail box */
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }
.detail-item { background:var(--page-bg); border:1px solid var(--card-border); border-radius:8px; padding:10px 14px; }
.detail-label { font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.07em; color:var(--text-muted); margin-bottom:3px; }
.detail-value { font-size:0.88rem; font-weight:600; color:var(--dark); }

/* Delete confirm */
.delete-icon-wrap { width:56px; height:56px; background:#fee2e2; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 14px; font-size:1.7rem; }
.delete-title     { text-align:center; font-size:1.05rem; font-weight:700; color:var(--dark); margin-bottom:8px; }
.delete-text      { text-align:center; font-size:0.87rem; color:var(--text-muted); line-height:1.6; }

/* Buttons */
.btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:9px 18px; border-radius:8px; font-size:0.88rem;
    font-weight:600; cursor:pointer; border:1px solid;
    transition:background 0.15s, transform 0.1s; font-family:inherit;
}
.btn:active        { transform:translateY(1px); }
.btn-primary       { background:var(--primary); color:#fff; border-color:var(--primary); }
.btn-primary:hover { background:#cf6d17; border-color:#cf6d17; }
.btn-ghost         { background:transparent; color:var(--text-muted); border-color:var(--card-border); }
.btn-ghost:hover   { background:var(--page-bg); color:var(--text); }
.btn-danger        { background:#ef4444; color:#fff; border-color:#ef4444; }
.btn-danger:hover  { background:#dc2626; }

/* Mobile */
.mobile-menu-btn {
    display:none; align-items:center; justify-content:center;
    width:36px; height:36px; border:1px solid var(--card-border);
    border-radius:8px; background:var(--card-bg); cursor:pointer; color:var(--dark);
}
@media(max-width:768px) {
    .main-content    { margin-left:0; padding:16px 16px 48px; width:100%; }
    .mobile-menu-btn { display:flex; }
    .form-grid       { grid-template-columns:1fr; }
    .form-group.span-2 { grid-column:span 1; }
    .detail-grid     { grid-template-columns:1fr; }
    .toolbar         { flex-direction:column; align-items:stretch; }
    .toolbar-right   { justify-content:flex-end; }
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
                <h1 class="page-title">Transactions</h1>
            </div>
            <div class="page-title-sub">Record and manage all sales payment transactions</div>
        </div>
    </div>

    <?= flash() ?>

    <!-- Stat Cards -->
    <div class="stats-row">
        <div class="stat-card sc-green">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value">₱<?= number_format($totalRevenue, 0) ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
        </div>
        <div class="stat-card sc-orange">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value">₱<?= number_format($todayTotal, 0) ?></div>
                <div class="stat-label">Today's Collection</div>
            </div>
        </div>
        <div class="stat-card sc-purple">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value">₱<?= number_format($gcashTotal, 0) ?></div>
                <div class="stat-label">GCash Collected</div>
            </div>
        </div>
        <div class="stat-card sc-blue">
            <div class="stat-card-accent"></div>
            <div class="stat-icon-wrap">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/></svg>
            </div>
            <div class="stat-body">
                <div class="stat-value"><?= number_format($totalTxns) ?></div>
                <div class="stat-label">Total Transactions</div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <span class="toolbar-title">All Transactions</span>
            <span class="count-pill"><?= number_format($totalCount) ?></span>
            <form method="GET" style="display:contents;" id="filterForm">
                <div class="search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" class="search-input" placeholder="Customer, order #, ref…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="method" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Methods</option>
                    <option value="cash"  <?= $filterMethod==='cash' ?'selected':'' ?>>💵 Cash</option>
                    <option value="gcash" <?= $filterMethod==='gcash'?'selected':'' ?>>📱 GCash</option>
                    <option value="cod"   <?= $filterMethod==='cod'  ?'selected':'' ?>>🚚 COD</option>
                </select>
                <input type="date" name="from" class="date-input" value="<?= htmlspecialchars($dateFrom) ?>" onchange="this.form.submit()" title="Date from">
                <input type="date" name="to"   class="date-input" value="<?= htmlspecialchars($dateTo) ?>"   onchange="this.form.submit()" title="Date to">
                <?php if ($search || $filterMethod || $dateFrom || $dateTo): ?>
                    <a href="transactions.php" style="font-size:0.8rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="toolbar-right">
            <button class="btn-export" onclick="exportCSV()" title="Export visible rows to CSV">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </button>
            <button class="btn-add" onclick="openModal('addModal')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Transaction
            </button>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <?php if ($transactions && $transactions->num_rows > 0): ?>
        <table class="data-table" id="txnTable">
            <thead>
                <tr>
                    <th style="width:44px;">#</th>
                    <th>Txn ID</th>
                    <th>Customer</th>
                    <th>Order #</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Reference No.</th>
                    <th>Order Status</th>
                    <th>Date</th>
                    <th style="text-align:center;width:140px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $rowNum = $offset + 1;
            while ($t = $transactions->fetch_assoc()):
                $initial = strtoupper(substr($t['full_name'], 0, 1));
                $mClass  = 'm-' . $t['payment_method'];
                $mIcon   = $t['payment_method'] === 'gcash' ? '📱' : ($t['payment_method'] === 'cod' ? '🚚' : '💵');
                $osClass = $t['order_status'] === 'delivered' ? 'os-delivered' : 'os-other';
                $osLabel = ucwords(str_replace('_', ' ', $t['order_status']));
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $rowNum++ ?></td>
                <td>
                    <div class="txn-id">#TXN<?= str_pad($t['id'], 4, '0', STR_PAD_LEFT) ?></div>
                </td>
                <td>
                    <div class="customer-cell">
                        <div class="customer-avatar"><?= htmlspecialchars($initial) ?></div>
                        <div>
                            <div class="customer-name"><?= htmlspecialchars($t['full_name']) ?></div>
                            <div class="customer-sub"><?= htmlspecialchars($t['email']) ?></div>
                        </div>
                    </div>
                </td>
                <td style="font-weight:700;color:var(--primary);">
                    #<?= str_pad($t['order_id'], 4, '0', STR_PAD_LEFT) ?>
                </td>
                <td class="amount-cell">₱<?= number_format((float)$t['amount'], 2) ?></td>
                <td>
                    <span class="method-badge <?= $mClass ?>"><?= $mIcon ?> <?= strtoupper($t['payment_method']) ?></span>
                </td>
                <td>
                    <?php if ($t['reference_no']): ?>
                        <span class="ref-no"><?= htmlspecialchars($t['reference_no']) ?></span>
                    <?php else: ?>
                        <span class="ref-none">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="o-status <?= $osClass ?>"><?= $osLabel ?></span>
                </td>
                <td style="font-size:0.8rem;color:var(--text-muted);white-space:nowrap;">
                    <?= date('M j, Y', strtotime($t['transaction_date'])) ?><br>
                    <span style="font-size:0.75rem;"><?= date('g:i A', strtotime($t['transaction_date'])) ?></span>
                </td>
                <td style="text-align:center;">
                    <div style="display:flex;align-items:center;justify-content:center;gap:5px;">
                        <button class="btn-action btn-view-t"
                            onclick="openView(<?= htmlspecialchars(json_encode([
                                'id'               => $t['id'],
                                'order_id'         => $t['order_id'],
                                'full_name'        => $t['full_name'],
                                'email'            => $t['email'],
                                'amount'           => $t['amount'],
                                'payment_method'   => $t['payment_method'],
                                'reference_no'     => $t['reference_no'] ?? '',
                                'transaction_date' => $t['transaction_date'],
                                'order_status'     => $t['order_status'],
                                'order_total'      => $t['order_total'],
                                'notes'            => $t['notes'] ?? '',
                            ]), ENT_QUOTES) ?>)">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            View
                        </button>
                        <button class="btn-action btn-edit-t"
                            onclick="openEdit(<?= htmlspecialchars(json_encode([
                                'id'           => $t['id'],
                                'reference_no' => $t['reference_no'] ?? '',
                                'notes'        => $t['notes'] ?? '',
                            ]), ENT_QUOTES) ?>)">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn-action btn-del"
                            onclick="openDelete(<?= $t['id'] ?>, <?= $t['order_id'] ?>)">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div>Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$perPage,$totalCount)) ?> of <?= number_format($totalCount) ?> transactions</div>
            <div class="pagination-pages">
                <?php
                $qs = http_build_query(array_merge($_GET,['page'=>max(1,$page-1)]));
                echo "<a href='?{$qs}' class='pg-btn".($page<=1?' disabled':'')."'>← Prev</a>";
                $s2=max(1,$page-2); $e2=min($totalPages,$page+2);
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
            <div class="empty-icon">💰</div>
            <div>No transactions found<?= ($search||$filterMethod||$dateFrom||$dateTo)?' — try adjusting filters.':'.'; ?></div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.main-content -->
</div><!-- /.admin-layout -->


<!-- ══ ADD TRANSACTION MODAL ══ -->
<div class="modal-backdrop" id="addModal" onclick="backdropClose(event,'addModal')">
    <div class="modal-card" role="dialog" aria-modal="true">
        <div class="modal-header">
            <div class="modal-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Record Transaction
            </div>
            <button class="modal-close" onclick="closeModal('addModal')">✕</button>
        </div>
        <form method="POST" action="transactions.php">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">

                <div class="form-section-label">Link to Order</div>
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label class="form-label">Unpaid Order <span class="req">*</span></label>
                        <select name="order_id" class="form-select" required onchange="previewOrder(this)">
                            <option value="">— Select an unpaid order —</option>
                            <?php
                            if ($unpaidOrders && $unpaidOrders->num_rows > 0):
                                while ($uo = $unpaidOrders->fetch_assoc()):
                            ?>
                            <option value="<?= $uo['id'] ?>"
                                data-total="<?= $uo['total_amount'] ?>"
                                data-method="<?= htmlspecialchars($uo['payment_method']) ?>"
                                data-name="<?= htmlspecialchars($uo['full_name']) ?>"
                                data-date="<?= date('M j, Y', strtotime($uo['created_at'])) ?>">
                                #<?= str_pad($uo['id'],4,'0',STR_PAD_LEFT) ?> — <?= htmlspecialchars($uo['full_name']) ?> — ₱<?= number_format((float)$uo['total_amount'],2) ?>
                            </option>
                            <?php endwhile; endif; ?>
                        </select>
                        <!-- Order preview -->
                        <div class="order-preview" id="orderPreview">
                            <div class="order-preview-row">
                                <span style="color:var(--text-muted);">Customer</span>
                                <span id="prev_name">—</span>
                            </div>
                            <div class="order-preview-row">
                                <span style="color:var(--text-muted);">Order Date</span>
                                <span id="prev_date">—</span>
                            </div>
                            <div class="order-preview-row">
                                <span style="color:var(--text-muted);">Method</span>
                                <span id="prev_method">—</span>
                            </div>
                            <div class="order-preview-row">
                                <span>Order Total</span>
                                <span id="prev_total">₱0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section-label">Payment Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Amount Paid (₱) <span class="req">*</span></label>
                        <input type="number" name="amount" id="add_amount" class="form-input" step="0.01" min="0.01" placeholder="0.00" required>
                        <span class="form-hint">Usually equals the order total</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Method <span class="req">*</span></label>
                        <select name="payment_method" class="form-select" required>
                            <option value="cash">💵 Cash</option>
                            <option value="gcash">📱 GCash</option>
                            <option value="cod">🚚 COD</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reference / Receipt No.</label>
                        <input type="text" name="reference_no" class="form-input" placeholder="e.g. GCX-12345678">
                        <span class="form-hint">GCash ref, receipt number, etc.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Transaction Date</label>
                        <input type="datetime-local" name="transaction_date" class="form-input"
                               value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-textarea" placeholder="Any additional notes…"></textarea>
                    </div>
                </div>

                <div style="background:#d1fae5;border:1px solid #a7f3d0;border-radius:8px;padding:10px 14px;font-size:0.82rem;color:#065f46;margin-top:4px;">
                    ✅ <strong>Auto-action:</strong> Recording this transaction will automatically mark the linked order as <strong>Paid</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Record Transaction
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══ VIEW MODAL ══ -->
<div class="modal-backdrop" id="viewModal" onclick="backdropClose(event,'viewModal')">
    <div class="modal-card" role="dialog" aria-modal="true">
        <div class="modal-header">
            <div class="modal-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Transaction Details
            </div>
            <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
        </div>
        <div class="modal-body">
            <div class="detail-grid" id="view_grid"></div>
            <div id="view_notes_wrap" style="display:none;">
                <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:6px;">Notes</div>
                <div id="view_notes" style="background:var(--page-bg);border:1px solid var(--card-border);border-radius:8px;padding:10px 14px;font-size:0.87rem;color:var(--text);line-height:1.6;"></div>
            </div>
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
                Edit Transaction
            </div>
            <button class="modal-close" onclick="closeModal('editModal')">✕</button>
        </div>
        <form method="POST" action="transactions.php">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-body">
                <div class="form-section-label">Editable Fields</div>
                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Reference / Receipt No.</label>
                    <input type="text" name="reference_no" id="edit_ref" class="form-input" placeholder="e.g. GCX-12345678">
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" id="edit_notes" class="form-textarea" placeholder="Additional notes…"></textarea>
                </div>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:9px 13px;font-size:0.8rem;color:#92400e;margin-top:4px;">
                    ⚠️ Amount, method, and order link cannot be changed. Delete and re-create if needed.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Save
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══ DELETE MODAL ══ -->
<div class="modal-backdrop" id="deleteModal" onclick="backdropClose(event,'deleteModal')">
    <div class="modal-card sm" role="dialog" aria-modal="true">
        <div class="modal-header">
            <div class="modal-title" style="color:#ef4444;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                Delete Transaction
            </div>
            <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
        </div>
        <form method="POST" action="transactions.php">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="del_id">
            <div class="modal-body" style="text-align:center;padding:28px 24px;">
                <div class="delete-icon-wrap">🗑️</div>
                <div class="delete-title">Delete this transaction?</div>
                <div class="delete-text">
                    Transaction <strong id="del_txn_label">#TXN0000</strong> linked to
                    Order <strong id="del_order_label">#0000</strong> will be permanently removed.<br><br>
                    <span style="color:#ef4444;font-weight:600;">⚠️ The linked order will be marked as Unpaid</span>
                    if no other transactions remain.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>


<script>
// ── Modal helpers ─────────────────────────────────────────────
function openModal(id)        { document.getElementById(id).classList.add('open');    document.body.style.overflow='hidden'; }
function closeModal(id)       { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function backdropClose(e, id) { if(e.target===document.getElementById(id)) closeModal(id); }
document.addEventListener('keydown', function(e) {
    if(e.key==='Escape') {
        ['addModal','viewModal','editModal','deleteModal'].forEach(id=>document.getElementById(id).classList.remove('open'));
        document.body.style.overflow='';
    }
});

// ── Order preview in Add modal ────────────────────────────────
function previewOrder(sel) {
    const opt    = sel.options[sel.selectedIndex];
    const prev   = document.getElementById('orderPreview');
    const amount = document.getElementById('add_amount');
    if (!opt.value) { prev.classList.remove('show'); amount.value=''; return; }

    const total  = parseFloat(opt.dataset.total || 0);
    document.getElementById('prev_name').textContent   = opt.dataset.name   || '—';
    document.getElementById('prev_date').textContent   = opt.dataset.date   || '—';
    document.getElementById('prev_method').textContent = (opt.dataset.method || '').toUpperCase();
    document.getElementById('prev_total').textContent  = '₱' + total.toLocaleString('en-PH',{minimumFractionDigits:2});
    amount.value = total.toFixed(2);
    prev.classList.add('show');

    // Set payment method to match order
    const mSel = document.querySelector('[name="payment_method"]');
    if (mSel) {
        for (let o of mSel.options) {
            if (o.value === opt.dataset.method) { o.selected=true; break; }
        }
    }
}

// ── View modal ────────────────────────────────────────────────
function openView(data) {
    const methodIcons = { gcash:'📱 GCash', cash:'💵 Cash', cod:'🚚 COD' };
    const statusColors = {
        delivered:        ['#d1fae5','#065f46'],
        cancelled:        ['#fee2e2','#991b1b'],
        pending:          ['#fef3c7','#92400e'],
        confirmed:        ['#dbeafe','#1e40af'],
        processing:       ['#ede9fe','#5b21b6'],
        out_for_delivery: ['#ffedd5','#9a3412'],
    };
    const [sBg, sCol] = statusColors[data.order_status] || ['#e5e7eb','#374151'];
    const sLbl = data.order_status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());

    const items = [
        { label:'Transaction ID',  value:'#TXN' + String(data.id).padStart(4,'0') },
        { label:'Order #',         value:'#' + String(data.order_id).padStart(4,'0') },
        { label:'Customer',        value:data.full_name },
        { label:'Email',           value:data.email },
        { label:'Amount',          value:'₱' + parseFloat(data.amount).toLocaleString('en-PH',{minimumFractionDigits:2}), valueStyle:'color:#065f46;font-size:1.1rem;' },
        { label:'Order Total',     value:'₱' + parseFloat(data.order_total).toLocaleString('en-PH',{minimumFractionDigits:2}) },
        { label:'Payment Method',  value:methodIcons[data.payment_method] || data.payment_method.toUpperCase() },
        { label:'Reference No.',   value:data.reference_no || '—' },
        { label:'Transaction Date',value:new Date(data.transaction_date).toLocaleString('en-PH',{dateStyle:'medium',timeStyle:'short'}) },
        { label:'Order Status',    value:`<span style="background:${sBg};color:${sCol};padding:2px 10px;border-radius:12px;font-size:0.8rem;font-weight:600;">${sLbl}</span>`, raw:true },
    ];

    const grid = document.getElementById('view_grid');
    grid.innerHTML = items.map(it => `
        <div class="detail-item">
            <div class="detail-label">${it.label}</div>
            <div class="detail-value" ${it.valueStyle?`style="${it.valueStyle}"`:''}>
                ${it.raw ? it.value : escHtml(it.value)}
            </div>
        </div>
    `).join('');

    const notesWrap = document.getElementById('view_notes_wrap');
    if (data.notes) {
        document.getElementById('view_notes').textContent = data.notes;
        notesWrap.style.display = 'block';
    } else {
        notesWrap.style.display = 'none';
    }

    openModal('viewModal');
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ── Edit modal ────────────────────────────────────────────────
function openEdit(data) {
    document.getElementById('edit_id').value    = data.id;
    document.getElementById('edit_ref').value   = data.reference_no || '';
    document.getElementById('edit_notes').value = data.notes || '';
    openModal('editModal');
}

// ── Delete modal ──────────────────────────────────────────────
function openDelete(id, orderId) {
    document.getElementById('del_id').value = id;
    document.getElementById('del_txn_label').textContent   = '#TXN' + String(id).padStart(4,'0');
    document.getElementById('del_order_label').textContent = '#'    + String(orderId).padStart(4,'0');
    openModal('deleteModal');
}

// ── CSV Export ────────────────────────────────────────────────
function exportCSV() {
    const table = document.getElementById('txnTable');
    if (!table) return;
    const rows  = table.querySelectorAll('tr');
    const lines = [];
    rows.forEach(function(row) {
        const cells = row.querySelectorAll('th, td');
        const line  = Array.from(cells).map(function(cell) {
            const text = cell.innerText.replace(/\n/g,' ').trim();
            return '"' + text.replace(/"/g,'""') + '"';
        });
        lines.push(line.join(','));
    });
    const blob = new Blob(['\uFEFF' + lines.join('\n')], { type:'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'transactions_<?= date('Y-m-d') ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
}

// ── Search on Enter ───────────────────────────────────────────
const si = document.querySelector('.search-input');
if (si) si.addEventListener('keydown', e => { if(e.key==='Enter') e.target.closest('form').submit(); });
</script>
</body>
</html>