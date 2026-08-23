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

        if (!in_array($method, ['cash', 'gcash', 'cod'])) $method = 'cash';

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

        .filter-select,
        .date-input {
            padding: 8px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            background: var(--surface);
            color: var(--ink);
            outline: none;
            cursor: pointer;
            font-family: inherit;
        }

        .filter-select {
            padding-right: 30px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239c968c' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }

        .filter-select:focus,
        .date-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .date-input {
            color: var(--ink-2);
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

        /* Toolbar buttons */
        .btn-export,
        .btn-add {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px var(--s4);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            cursor: pointer;
            font-family: inherit;
            white-space: nowrap;
            border: 1px solid transparent;
            transition: background 0.14s, border-color 0.14s;
        }

        .btn-export {
            background: var(--surface);
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .btn-export:hover {
            background: var(--surface-2);
            color: var(--ink);
            border-color: var(--ink-3);
        }

        .btn-add {
            background: var(--brand);
            color: #fff;
        }

        .btn-add:hover {
            background: var(--brand-strong);
        }

        /* Cells */
        .txn-id {
            font-weight: var(--fw-bold);
            color: var(--ink);
            font-size: 0.9rem;
            font-variant-numeric: tabular-nums;
        }

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

        .customer-sub {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        .amount-cell {
            font-weight: var(--fw-bold);
            color: #1f7a48;
            font-variant-numeric: tabular-nums;
        }

        .ref-no {
            font-family: 'SF Mono', 'Consolas', monospace;
            font-size: var(--fs-xs);
            background: var(--surface-2);
            color: var(--ink-2);
            padding: 2px 8px;
            border-radius: 5px;
        }

        .ref-none {
            color: var(--ink-3);
        }

        /* Method badge */
        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            white-space: nowrap;
        }

        .m-cash {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .m-gcash {
            background: var(--info-tint);
            color: #2b62ad;
        }

        .m-cod {
            background: var(--brand-tint);
            color: var(--brand-strong);
        }

        /* Order status chip */
        .o-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            white-space: nowrap;
        }

        .o-status::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .os-delivered {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .os-other {
            background: var(--surface-2);
            color: var(--ink-2);
        }

        /* Row actions — icon by default, expand to label on hover (matches Products) */
        .row-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .tact {
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

        .tact svg,
        .tact i {
            flex-shrink: 0;
        }

        .tact .act-label {
            max-width: 0;
            opacity: 0;
            margin-left: 0;
            transition: max-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.18s, margin-left 0.22s;
        }

        .tact:hover {
            width: auto;
            padding: 0 11px;
        }

        .tact:hover .act-label {
            max-width: 80px;
            opacity: 1;
            margin-left: 5px;
        }

        .tact-view {
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .tact-view:hover {
            background: var(--ink-2);
            color: #fff;
            border-color: var(--ink-2);
        }

        .tact-edit {
            color: var(--brand);
            border-color: var(--brand);
        }

        .tact-edit:hover {
            background: var(--brand);
            color: #fff;
        }

        .tact-del {
            color: var(--danger);
            border-color: #f0c4c0;
        }

        .tact-del:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
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

        .form-hint {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        /* Order preview box in Add modal */
        .order-preview {
            display: none;
            margin-top: var(--s3);
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--r-sm);
            padding: var(--s3) var(--s4);
        }

        .order-preview.show {
            display: block;
        }

        .order-preview-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: var(--fs-sm);
            padding: 3px 0;
            color: var(--ink);
        }

        .order-preview-row:last-child {
            font-weight: var(--fw-bold);
            border-top: 1px solid var(--line);
            margin-top: 4px;
            padding-top: 7px;
        }

        /* Info callouts */
        .callout {
            border-radius: var(--r-sm);
            padding: 10px 14px;
            font-size: var(--fs-sm);
            margin-top: 4px;
            line-height: 1.5;
        }

        .callout-ok {
            background: var(--ok-tint);
            border: 1px solid #a7dcbc;
            color: #1f7a48;
        }

        .callout-warn {
            background: var(--warn-tint);
            border: 1px solid #f2ddb0;
            color: #8a5a0c;
        }

        /* Detail grid (View modal — built by JS) */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--s3);
            margin-bottom: var(--s4);
        }

        .detail-item {
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--r-sm);
            padding: 10px 14px;
        }

        .detail-label {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--ink-3);
            margin-bottom: 4px;
        }

        .detail-value {
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            color: var(--ink);
        }

        /* Delete modal bits */
        .delete-icon-wrap {
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

        .delete-title {
            font-size: 1rem;
            font-weight: var(--fw-bold);
            color: var(--ink);
            margin-bottom: var(--s2);
        }

        .delete-text {
            font-size: var(--fs-sm);
            color: var(--ink-2);
            line-height: 1.6;
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

            .form-grid,
            .detail-grid {
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
                    <h1 class="page-title">Transactions</h1>
                    <div class="page-title-sub">Record and manage all sales payment transactions</div>
                </div>
            </div>

            <?= flash() ?>

            <!-- Stat cards -->
            <div class="grid cols-2 mb-6" style="grid-template-columns:repeat(4,1fr);">
                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Revenue</span>
                        <div class="stat-icon"><i class="fa-solid fa-money-bill-trend-up"></i></div>
                    </div>
                    <div class="stat-value money">₱<?= number_format($totalRevenue, 0) ?></div>
                    <div class="stat-foot">All recorded payments</div>
                </div>
                <div class="stat-card tone-brand">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Today's Collection</span>
                        <div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div>
                    </div>
                    <div class="stat-value money">₱<?= number_format($todayTotal, 0) ?></div>
                    <div class="stat-foot"><?= date('F j') ?></div>
                </div>
                <div class="stat-card tone-violet">
                    <div class="stat-top">
                        <span class="stat-eyebrow">GCash Collected</span>
                        <div class="stat-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
                    </div>
                    <div class="stat-value money">₱<?= number_format($gcashTotal, 0) ?></div>
                    <div class="stat-foot">Digital payments</div>
                </div>
                <div class="stat-card tone-blue">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Transactions</span>
                        <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalTxns) ?></div>
                    <div class="stat-foot">Records logged</div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-title">All Transactions</span>
                    <span class="count-pill"><?= number_format($totalCount) ?></span>
                    <form method="GET" style="display:flex;gap:var(--s2);align-items:center;flex-wrap:wrap;" id="filterForm">
                        <div class="search-wrap">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                            <input type="text" name="q" class="search-input" placeholder="Customer, order #, ref…" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <select name="method" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Methods</option>
                            <option value="cash" <?= $filterMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="gcash" <?= $filterMethod === 'gcash' ? 'selected' : '' ?>>GCash</option>
                            <option value="cod" <?= $filterMethod === 'cod'  ? 'selected' : '' ?>>COD</option>
                        </select>
                        <input type="date" name="from" class="date-input" value="<?= htmlspecialchars($dateFrom) ?>" onchange="this.form.submit()" title="Date from">
                        <input type="date" name="to" class="date-input" value="<?= htmlspecialchars($dateTo) ?>" onchange="this.form.submit()" title="Date to">
                        <?php if ($search || $filterMethod || $dateFrom || $dateTo): ?>
                            <a href="transactions.php" class="clear-link">✕ Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="toolbar-right">
                    <button class="btn-export" onclick="exportCSV()" title="Export visible rows to CSV">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="7 10 12 15 17 10" />
                            <line x1="12" y1="15" x2="12" y2="3" />
                        </svg>
                        Export CSV
                    </button>
                    <button class="btn-add" onclick="openModal('addModal')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19" />
                            <line x1="5" y1="12" x2="19" y2="12" />
                        </svg>
                        Add Transaction
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-card">
                <?php if ($transactions && $transactions->num_rows > 0): ?>
                    <div class="table-scroll">
                        <table class="data" id="txnTable">
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
                                    <th style="text-align:center;min-width:120px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rowNum = $offset + 1;
                                while ($t = $transactions->fetch_assoc()):
                                    $initial = strtoupper(substr($t['full_name'], 0, 1));
                                    $mClass  = 'm-' . $t['payment_method'];
                                    $mIcon   = $t['payment_method'] === 'gcash' ? '<i class="fa-solid fa-mobile-screen"></i>' : ($t['payment_method'] === 'cod' ? '<i class="fa-solid fa-truck"></i>' : '<i class="fa-solid fa-money-bill"></i>');
                                    $osClass = $t['order_status'] === 'delivered' ? 'os-delivered' : 'os-other';
                                    $osLabel = ucwords(str_replace('_', ' ', $t['order_status']));
                                ?>
                                    <tr>
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);font-weight:var(--fw-semi);"><?= $rowNum++ ?></td>
                                        <td>
                                            <div class="txn-id">#TXN<?= str_pad($t['id'], 4, '0', STR_PAD_LEFT) ?></div>
                                        </td>
                                        <td>
                                            <div class="cell-lead">
                                                <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                                                <div>
                                                    <div class="customer-name"><?= htmlspecialchars($t['full_name']) ?></div>
                                                    <div class="customer-sub"><?= htmlspecialchars($t['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-weight:var(--fw-bold);color:var(--brand);">
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
                                        <td style="font-size:var(--fs-xs);color:var(--ink-3);white-space:nowrap;">
                                            <?= date('M j, Y', strtotime($t['transaction_date'])) ?><br>
                                            <span style="font-size:0.7rem;"><?= date('g:i A', strtotime($t['transaction_date'])) ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="row-actions">
                                                <button class="tact tact-view"
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
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                        <circle cx="12" cy="12" r="3" />
                                                    </svg><span class="act-label">View</span>
                                                </button>
                                                <button class="tact tact-edit"
                                                    onclick="openEdit(<?= htmlspecialchars(json_encode([
                                                                            'id'           => $t['id'],
                                                                            'reference_no' => $t['reference_no'] ?? '',
                                                                            'notes'        => $t['notes'] ?? '',
                                                                        ]), ENT_QUOTES) ?>)">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                                    </svg><span class="act-label">Edit</span>
                                                </button>
                                                <button class="tact tact-del"
                                                    onclick="openDelete(<?= $t['id'] ?>, <?= $t['order_id'] ?>)">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6" />
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                                    </svg><span class="act-label">Delete</span>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <div>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?> transactions</div>
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
                        <div class="empty-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                        <div class="empty-title">No transactions found</div>
                        <div class="empty-text"><?= ($search || $filterMethod || $dateFrom || $dateTo) ? 'Try adjusting your filters.' : 'Recorded payments will appear here.'; ?></div>
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
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
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
                                            #<?= str_pad($uo['id'], 4, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($uo['full_name']) ?> — ₱<?= number_format((float)$uo['total_amount'], 2) ?>
                                        </option>
                                <?php endwhile;
                                endif; ?>
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
                                <option value="cash"><i class="fa-solid fa-money-bill"></i> Cash</option>
                                <option value="gcash"><i class="fa-solid fa-mobile-screen"></i> GCash</option>
                                <option value="cod"><i class="fa-solid fa-truck"></i> COD</option>
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

                    <div class="callout callout-ok">
                        ✓ <strong>Auto-action:</strong> Recording this transaction will automatically mark the linked order as <strong>Paid</strong>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
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
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    Transaction Details
                </div>
                <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
            </div>
            <div class="modal-body">
                <div class="detail-grid" id="view_grid"></div>
                <div id="view_notes_wrap" style="display:none;">
                    <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--ink-3);margin-bottom:6px;">Notes</div>
                    <div id="view_notes" style="background:var(--surface-2);border:1px solid var(--line);border-radius:8px;padding:10px 14px;font-size:0.87rem;color:var(--ink);line-height:1.6;"></div>
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
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                    </svg>
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
                    <div class="callout callout-warn">
                        <i class="fa-solid fa-triangle-exclamation"></i> Amount, method, and order link cannot be changed. Delete and re-create if needed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
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
                <div class="modal-title" style="color:var(--danger);">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                    </svg>
                    Delete Transaction
                </div>
                <button class="modal-close" onclick="closeModal('deleteModal')">✕</button>
            </div>
            <form method="POST" action="transactions.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="del_id">
                <div class="modal-body" style="text-align:center;padding:28px 24px;">
                    <div class="delete-icon-wrap"><i class="fa-solid fa-trash"></i></div>
                    <div class="delete-title">Delete this transaction?</div>
                    <div class="delete-text">
                        Transaction <strong id="del_txn_label">#TXN0000</strong> linked to
                        Order <strong id="del_order_label">#0000</strong> will be permanently removed.<br><br>
                        <span style="color:var(--danger);font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> The linked order will be marked as Unpaid</span>
                        if no other transactions remain.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6" />
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                        </svg>
                        Delete
                    </button>
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
                ['addModal', 'viewModal', 'editModal', 'deleteModal'].forEach(id => document.getElementById(id).classList.remove('open'));
                document.body.style.overflow = '';
            }
        });

        // ── Order preview in Add modal ────────────────────────────────
        function previewOrder(sel) {
            const opt = sel.options[sel.selectedIndex];
            const prev = document.getElementById('orderPreview');
            const amount = document.getElementById('add_amount');
            if (!opt.value) {
                prev.classList.remove('show');
                amount.value = '';
                return;
            }

            const total = parseFloat(opt.dataset.total || 0);
            document.getElementById('prev_name').textContent = opt.dataset.name || '—';
            document.getElementById('prev_date').textContent = opt.dataset.date || '—';
            document.getElementById('prev_method').textContent = (opt.dataset.method || '').toUpperCase();
            document.getElementById('prev_total').textContent = '₱' + total.toLocaleString('en-PH', {
                minimumFractionDigits: 2
            });
            amount.value = total.toFixed(2);
            prev.classList.add('show');

            // Set payment method to match order
            const mSel = document.querySelector('[name="payment_method"]');
            if (mSel) {
                for (let o of mSel.options) {
                    if (o.value === opt.dataset.method) {
                        o.selected = true;
                        break;
                    }
                }
            }
        }

        // ── View modal ────────────────────────────────────────────────
        function openView(data) {
            const methodIcons = {
                gcash: '<i class="fa-solid fa-mobile-screen"></i> GCash',
                cash: '<i class="fa-solid fa-money-bill"></i> Cash',
                cod: '<i class="fa-solid fa-truck"></i> COD'
            };
            const statusColors = {
                delivered: ['#e6f4ec', '#1f7a48'],
                cancelled: ['#fbeae9', '#b23c34'],
                pending: ['#fbf1de', '#8a5a0c'],
                confirmed: ['#e8f0fb', '#2b62ad'],
                processing: ['#f0ecfa', '#6a4bc0'],
                out_for_delivery: ['#fde8d4', '#a4680c'],
            };
            const [sBg, sCol] = statusColors[data.order_status] || ['#f0eee9', '#6f6a62'];
            const sLbl = data.order_status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

            const items = [{
                    label: 'Transaction ID',
                    value: '#TXN' + String(data.id).padStart(4, '0')
                },
                {
                    label: 'Order #',
                    value: '#' + String(data.order_id).padStart(4, '0')
                },
                {
                    label: 'Customer',
                    value: data.full_name
                },
                {
                    label: 'Email',
                    value: data.email
                },
                {
                    label: 'Amount',
                    value: '₱' + parseFloat(data.amount).toLocaleString('en-PH', {
                        minimumFractionDigits: 2
                    }),
                    valueStyle: 'color:#1f7a48;font-size:1.1rem;'
                },
                {
                    label: 'Order Total',
                    value: '₱' + parseFloat(data.order_total).toLocaleString('en-PH', {
                        minimumFractionDigits: 2
                    })
                },
                {
                    label: 'Payment Method',
                    value: methodIcons[data.payment_method] || data.payment_method.toUpperCase()
                },
                {
                    label: 'Reference No.',
                    value: data.reference_no || '—'
                },
                {
                    label: 'Transaction Date',
                    value: new Date(data.transaction_date).toLocaleString('en-PH', {
                        dateStyle: 'medium',
                        timeStyle: 'short'
                    })
                },
                {
                    label: 'Order Status',
                    value: `<span style="background:${sBg};color:${sCol};padding:2px 10px;border-radius:12px;font-size:0.8rem;font-weight:600;">${sLbl}</span>`,
                    raw: true
                },
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
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_ref').value = data.reference_no || '';
            document.getElementById('edit_notes').value = data.notes || '';
            openModal('editModal');
        }

        // ── Delete modal ──────────────────────────────────────────────
        function openDelete(id, orderId) {
            document.getElementById('del_id').value = id;
            document.getElementById('del_txn_label').textContent = '#TXN' + String(id).padStart(4, '0');
            document.getElementById('del_order_label').textContent = '#' + String(orderId).padStart(4, '0');
            openModal('deleteModal');
        }

        // ── CSV Export ────────────────────────────────────────────────
        function exportCSV() {
            const table = document.getElementById('txnTable');
            if (!table) return;
            const rows = table.querySelectorAll('tr');
            const lines = [];
            rows.forEach(function(row) {
                const cells = row.querySelectorAll('th, td');
                const line = Array.from(cells).map(function(cell) {
                    const text = cell.innerText.replace(/\n/g, ' ').trim();
                    return '"' + text.replace(/"/g, '""') + '"';
                });
                lines.push(line.join(','));
            });
            const blob = new Blob(['\uFEFF' + lines.join('\n')], {
                type: 'text/csv;charset=utf-8;'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'transactions_<?= date('Y-m-d') ?>.csv';
            a.click();
            URL.revokeObjectURL(url);
        }

        // ── Search on Enter ───────────────────────────────────────────
        const si = document.querySelector('.search-input');
        if (si) si.addEventListener('keydown', e => {
            if (e.key === 'Enter') e.target.closest('form').submit();
        });
    </script>
</body>

</html>