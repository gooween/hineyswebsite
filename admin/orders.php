<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/orders.php
// Status flow: pending → approved → processing → out_for_delivery → delivered
//              pending → cancelled (reject)
// Delivery fee: set manually by admin per order
// GCash proof: viewable in order detail
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

// ── POST Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // ── Approve order ──
    if ($action === 'approve') {
        $id     = (int)($_POST['id'] ?? 0);
        $newFee = (isset($_POST['delivery_fee']) && $_POST['delivery_fee'] !== '')
            ? (float)$_POST['delivery_fee'] : null;

        if ($id) {
            if ($newFee !== null && $newFee >= 0) {
                $itemsResult = $conn->query("SELECT COALESCE(SUM(subtotal),0) AS t FROM order_items WHERE order_id={$id}");
                $itemsTotal  = (float)($itemsResult->fetch_assoc()['t'] ?? 0);
                $newTotal    = $itemsTotal + $newFee;

                $conn->query("UPDATE orders SET status='approved', delivery_fee={$newFee},
                              total_amount={$newTotal}, updated_at=NOW() WHERE id={$id}");

                $note = $conn->real_escape_string(
                    '[Admin] Order approved. Delivery fee: ₱' . number_format($newFee, 2) .
                    '. Total: ₱' . number_format($newTotal, 2) . '.'
                );
                $conn->query("UPDATE orders SET notes=CONCAT(IFNULL(notes,''),
                              IF(notes IS NULL OR notes='','','\n'),'{$note}') WHERE id={$id}");
            } else {
                $conn->query("UPDATE orders SET status='approved', updated_at=NOW() WHERE id={$id}");
                $note = $conn->real_escape_string('[Admin] Order approved.');
                $conn->query("UPDATE orders SET notes=CONCAT(IFNULL(notes,''),
                              IF(notes IS NULL OR notes='','','\n'),'{$note}') WHERE id={$id}");
            }
            redirect('orders.php', 'success', 'Order approved successfully.');
        }
    }

    // ── Reject order + restore inventory ──
    if ($action === 'reject') {
        $id     = (int)($_POST['id'] ?? 0);
        $reason = $conn->real_escape_string(trim($_POST['reject_reason'] ?? ''));
        if ($id) {
            $conn->query("UPDATE orders SET status='cancelled', updated_at=NOW() WHERE id={$id}");
            $note = '[Admin] Order rejected' . ($reason ? ': ' . $reason : '.') ;
            $note = $conn->real_escape_string($note);
            $conn->query("UPDATE orders SET notes=CONCAT(IFNULL(notes,''),
                          IF(notes IS NULL OR notes='','','\n'),'{$note}') WHERE id={$id}");

            $uid   = (int)$_SESSION['user_id'];
            $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id={$id}");
            if ($items) {
                while ($item = $items->fetch_assoc()) {
                    $pid = (int)$item['product_id'];
                    $qty = (int)$item['quantity'];
                    $r2  = $conn->real_escape_string('Restored from rejected order #' . str_pad($id, 4, '0', STR_PAD_LEFT));
                    $conn->query("UPDATE inventory SET quantity=quantity+{$qty}, last_updated=NOW() WHERE product_id={$pid}");
                    $conn->query("INSERT INTO inventory_logs (product_id,type,quantity,reason,created_by,created_at)
                                  VALUES ({$pid},'in',{$qty},'{$r2}',{$uid},NOW())");
                }
            }
            redirect('orders.php', 'success', 'Order rejected and inventory restored.');
        }
    }

    // ── Update order status + payment status ──
    if ($action === 'update_status') {
        $id             = (int)($_POST['id'] ?? 0);
        $status         = $conn->real_escape_string(trim($_POST['status'] ?? ''));
        $payment_status = $conn->real_escape_string(trim($_POST['payment_status'] ?? ''));
        $valid_statuses = ['pending','approved','processing','out_for_delivery','delivered','cancelled'];
        $valid_payment  = ['unpaid','paid'];

        if ($id && in_array($status, $valid_statuses) && in_array($payment_status, $valid_payment)) {
            $conn->query("UPDATE orders SET status='{$status}', payment_status='{$payment_status}',
                          updated_at=NOW() WHERE id={$id}");

            // Set delivery fee if provided
            $newFee = (isset($_POST['delivery_fee']) && $_POST['delivery_fee'] !== '')
                ? (float)$_POST['delivery_fee'] : null;
            if ($newFee !== null && $newFee >= 0) {
                $itemsResult = $conn->query("SELECT COALESCE(SUM(subtotal),0) AS t FROM order_items WHERE order_id={$id}");
                $itemsTotal  = (float)($itemsResult->fetch_assoc()['t'] ?? 0);
                $newTotal    = $itemsTotal + $newFee;
                $conn->query("UPDATE orders SET delivery_fee={$newFee}, total_amount={$newTotal},
                              updated_at=NOW() WHERE id={$id}");
                $feeNote = $conn->real_escape_string(
                    '[Admin] Delivery fee updated to ₱' . number_format($newFee, 2) .
                    '. New total: ₱' . number_format($newTotal, 2) . '.'
                );
                $conn->query("UPDATE orders SET notes=CONCAT(IFNULL(notes,''),
                              IF(notes IS NULL OR notes='','','\n'),'{$feeNote}') WHERE id={$id}");
            }

            // Auto-create transaction when marked paid
            if ($payment_status === 'paid') {
                $chk = $conn->query("SELECT id FROM transactions WHERE order_id={$id} LIMIT 1");
                if ($chk && $chk->num_rows === 0) {
                    $ord = $conn->query("SELECT total_amount, payment_method FROM orders WHERE id={$id} LIMIT 1");
                    if ($ord && $row = $ord->fetch_assoc()) {
                        $amt  = (float)$row['total_amount'];
                        $meth = $conn->real_escape_string($row['payment_method']);
                        $note = $conn->real_escape_string('Auto-recorded when order marked as paid');
                        $conn->query("INSERT INTO transactions (order_id,amount,payment_method,transaction_date,notes)
                                      VALUES ({$id},{$amt},'{$meth}',NOW(),'{$note}')");
                    }
                }
            }
            redirect('orders.php', 'success', 'Order updated successfully.');
        } else {
            redirect('orders.php', 'error', 'Invalid status values provided.');
        }
    }

    // ── Cancel order ──
    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("UPDATE orders SET status='cancelled', updated_at=NOW() WHERE id={$id}");
            $uid   = (int)$_SESSION['user_id'];
            $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id={$id}");
            if ($items) {
                while ($item = $items->fetch_assoc()) {
                    $pid    = (int)$item['product_id'];
                    $qty    = (int)$item['quantity'];
                    $reason = $conn->real_escape_string('Returned from cancelled order #' . str_pad($id, 4, '0', STR_PAD_LEFT));
                    $conn->query("UPDATE inventory SET quantity=quantity+{$qty}, last_updated=NOW() WHERE product_id={$pid}");
                    $conn->query("INSERT INTO inventory_logs (product_id,type,quantity,reason,created_by,created_at)
                                  VALUES ({$pid},'in',{$qty},'{$reason}',{$uid},NOW())");
                }
            }
            redirect('orders.php', 'success', 'Order cancelled and inventory restored.');
        }
    }
}

// ── Filters & Pagination ─────────────────────────────────────
$search        = trim($_GET['q'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');
$filterPayment = trim($_GET['payment'] ?? '');
$filterMethod  = trim($_GET['method'] ?? '');
$perPage       = 15;
$page          = max(1, (int)($_GET['page'] ?? 1));
$offset        = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($search) {
    $s      = $conn->real_escape_string($search);
    $where .= " AND (u.full_name LIKE '%{$s}%' OR o.id LIKE '%{$s}%' OR o.delivery_address LIKE '%{$s}%')";
}
if ($filterStatus)  $where .= " AND o.status='{$conn->real_escape_string($filterStatus)}'";
if ($filterPayment) $where .= " AND o.payment_status='{$conn->real_escape_string($filterPayment)}'";
if ($filterMethod)  $where .= " AND o.payment_method='{$conn->real_escape_string($filterMethod)}'";

$countResult = $conn->query("SELECT COUNT(*) AS cnt FROM orders o JOIN users u ON u.id=o.user_id {$where}");
$totalCount  = (int)($countResult->fetch_assoc()['cnt'] ?? 0);
$totalPages  = max(1, (int)ceil($totalCount / $perPage));

$orders = $conn->query("
    SELECT o.id, o.status, o.total_amount, o.delivery_fee, o.payment_method,
           o.payment_status, o.delivery_address, o.notes, o.gcash_proof,
           o.created_at, o.updated_at,
           u.full_name, u.email, u.phone,
           COUNT(oi.id) AS item_count,
           COALESCE(SUM(oi.subtotal),0) AS items_subtotal
    FROM orders o
    JOIN users u ON u.id=o.user_id
    LEFT JOIN order_items oi ON oi.order_id=o.id
    {$where}
    GROUP BY o.id,o.status,o.total_amount,o.delivery_fee,o.payment_method,
             o.payment_status,o.delivery_address,o.notes,o.gcash_proof,
             o.created_at,o.updated_at,u.full_name,u.email,u.phone
    ORDER BY o.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");

// ── Summary stats ─────────────────────────────────────────────
$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders");
$totalOrders = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status='pending'");
$pendingCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE DATE(created_at)=CURDATE()");
$todayCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS total FROM orders WHERE status!='cancelled' AND payment_status='paid'");
$totalRevenue = (float)($r->fetch_assoc()['total'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE delivery_fee IS NULL AND status NOT IN ('cancelled','delivered')");
$feePendingCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

// GCash proof pending (approved, gcash, no proof yet, unpaid)
$r = $conn->query("SELECT COUNT(*) AS cnt FROM orders WHERE status='approved' AND payment_method='gcash' AND (gcash_proof IS NULL OR gcash_proof='') AND payment_status='unpaid'");
$proofPendingCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

$statusCounts = [];
$sc = $conn->query("SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status");
while ($row = $sc->fetch_assoc()) $statusCounts[$row['status']] = (int)$row['cnt'];

$activePage = 'orders';
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
<title>Orders — Hiney's Admin</title>
<style>
:root { --card-border:#e9e8e4; }
.main-content {
    margin-left:var(--sidebar-w); flex:1;
    padding:32px 32px 48px; min-height:100vh;
    background:var(--page-bg); box-sizing:border-box;
    width:calc(100% - var(--sidebar-w));
}
.page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px; }
.page-title     { font-size:1.5rem; font-weight:800; color:var(--dark); letter-spacing:-0.02em; }
.page-title-sub { font-size:0.82rem; color:var(--text-muted); margin-top:2px; }

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
.stat-body { flex:1; }
.stat-value { font-size:1.7rem; font-weight:800; color:var(--dark); line-height:1; letter-spacing:-0.03em; }
.stat-label { font-size:0.73rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.07em; font-weight:700; margin-top:4px; }
.sc-orange .stat-card-accent{background:#e67e22;} .sc-orange .stat-icon-wrap{background:#fef3e8;color:#e67e22;}
.sc-blue   .stat-card-accent{background:#3b82f6;} .sc-blue   .stat-icon-wrap{background:#eff6ff;color:#3b82f6;}
.sc-amber  .stat-card-accent{background:#f59e0b;} .sc-amber  .stat-icon-wrap{background:#fffbeb;color:#f59e0b;}
.sc-green  .stat-card-accent{background:#10b981;} .sc-green  .stat-icon-wrap{background:#ecfdf5;color:#10b981;}

.alert-banner {
    display:flex; align-items:center; gap:10px;
    border-radius:9px; padding:12px 16px; margin-bottom:16px; font-size:0.87rem;
    border-left-width:4px; border-left-style:solid;
}
.alert-warn  { background:#fffbeb; border-color:#f59e0b; border:1px solid #fde68a; border-left:4px solid #f59e0b; color:#92400e; }
.alert-blue  { background:#eff6ff; border-color:#3b82f6; border:1px solid #bfdbfe; border-left:4px solid #3b82f6; color:#1e40af; }

.filter-tabs {
    display:flex; align-items:center; gap:6px;
    background:var(--card-bg); border:1px solid var(--card-border);
    border-radius:var(--radius) var(--radius) 0 0;
    padding:10px 16px; border-bottom:none; flex-wrap:wrap;
}
.filter-tab {
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 14px; border-radius:8px; font-size:0.82rem;
    font-weight:600; cursor:pointer; text-decoration:none;
    color:var(--text-muted); transition:background 0.15s, color 0.15s;
    border:1px solid transparent; white-space:nowrap;
}
.filter-tab:hover { background:var(--page-bg); color:var(--dark); }
.filter-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }
.tab-count {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:18px; height:18px; padding:0 5px;
    background:rgba(255,255,255,0.25); border-radius:10px; font-size:0.68rem; font-weight:700;
}
.filter-tab:not(.active) .tab-count { background:var(--page-bg); color:var(--text-muted); }

.toolbar {
    background:var(--card-bg); border:1px solid var(--card-border);
    padding:12px 16px; display:flex; align-items:center;
    gap:10px; flex-wrap:wrap; border-top:none; border-bottom:none; box-sizing:border-box;
}
.toolbar-left  { display:flex; align-items:center; gap:8px; flex:1; flex-wrap:wrap; }
.toolbar-right { display:flex; align-items:center; gap:8px; flex-shrink:0; }
.search-wrap   { position:relative; display:flex; align-items:center; }
.search-wrap svg { position:absolute; left:10px; color:var(--text-muted); pointer-events:none; }
.search-input {
    padding:7px 12px 7px 34px; border:1px solid var(--card-border);
    border-radius:8px; font-size:0.85rem; width:210px;
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

.customer-cell { display:flex; align-items:center; gap:8px; }
.customer-avatar {
    width:32px; height:32px; border-radius:50%;
    background:linear-gradient(135deg,#e67e22,#f39c12);
    display:flex; align-items:center; justify-content:center;
    font-size:0.8rem; font-weight:700; color:#fff; flex-shrink:0;
}
.customer-name { font-weight:600; color:var(--dark); font-size:0.87rem; }
.customer-sub  { font-size:0.73rem; color:var(--text-muted); }

.order-id   { font-weight:700; color:var(--primary); font-size:0.9rem; }
.order-date { font-size:0.75rem; color:var(--text-muted); margin-top:2px; }

.total-main      { font-weight:700; color:var(--dark); }
.total-fee-set   { font-size:0.72rem; color:#10b981; font-weight:600; margin-top:2px; }
.total-fee-unset { font-size:0.72rem; color:#f59e0b; font-weight:600; margin-top:2px; }

.status-badge {
    display:inline-flex; align-items:center; gap:4px;
    padding:3px 9px; border-radius:20px; font-size:0.72rem; font-weight:600; white-space:nowrap;
}
.status-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.s-pending          { background:#fef3c7; color:#92400e; }
.s-approved         { background:#dbeafe; color:#1e40af; }
.s-confirmed        { background:#dbeafe; color:#1e40af; }
.s-processing       { background:#ede9fe; color:#5b21b6; }
.s-out_for_delivery { background:#ffedd5; color:#9a3412; }
.s-delivered        { background:#d1fae5; color:#065f46; }
.s-cancelled        { background:#fee2e2; color:#991b1b; }
.pay-unpaid { background:#fee2e2; color:#991b1b; }
.pay-paid   { background:#d1fae5; color:#065f46; }
.method-badge {
    display:inline-flex; align-items:center; gap:3px;
    padding:2px 8px; border-radius:6px; font-size:0.72rem;
    font-weight:600; background:#f3f4f6; color:#374151; white-space:nowrap;
}
.proof-badge {
    display:inline-flex; align-items:center; gap:3px;
    padding:2px 8px; border-radius:6px; font-size:0.7rem;
    font-weight:600; white-space:nowrap; cursor:pointer;
}
.proof-yes { background:#d1fae5; color:#065f46; }
.proof-no  { background:#fef3c7; color:#92400e; }

.btn-action {
    display:inline-flex; align-items:center; gap:4px;
    padding:5px 10px; border-radius:6px; font-size:0.75rem;
    font-weight:600; cursor:pointer; border:1px solid;
    background:transparent; transition:background 0.15s, color 0.15s; white-space:nowrap;
}
.btn-view          { color:#3b82f6; border-color:#3b82f6; }
.btn-view:hover    { background:#3b82f6; color:#fff; }
.btn-approve       { color:#10b981; border-color:#10b981; }
.btn-approve:hover { background:#10b981; color:#fff; }
.btn-reject        { color:#ef4444; border-color:#ef4444; }
.btn-reject:hover  { background:#ef4444; color:#fff; }
.btn-edit-s        { color:var(--primary); border-color:var(--primary); }
.btn-edit-s:hover  { background:var(--primary); color:#fff; }
.btn-cancel        { color:#ef4444; border-color:#ef4444; }
.btn-cancel:hover  { background:#ef4444; color:#fff; }

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

.empty-state { padding:56px 20px; text-align:center; color:var(--text-muted); }
.empty-icon  { font-size:3rem; margin-bottom:12px; }

/* ── Modals ── */
.modal-backdrop {
    position:fixed; inset:0; background:rgba(0,0,0,0.45);
    backdrop-filter:blur(3px); z-index:1000;
    display:none; align-items:center; justify-content:center; padding:20px;
}
.modal-backdrop.open { display:flex; }
.modal-card {
    background:var(--card-bg); border-radius:14px; width:100%;
    max-width:600px; max-height:92vh; overflow-y:auto;
    box-shadow:0 20px 60px rgba(0,0,0,0.2);
    animation:modalIn 0.22s cubic-bezier(0.34,1.56,0.64,1) both;
}
.modal-card.lg { max-width:780px; }
.modal-card.sm { max-width:460px; }
@keyframes modalIn {
    from { opacity:0; transform:translateY(18px) scale(0.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
.modal-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:20px 24px 16px; border-bottom:1px solid var(--card-border);
    position:sticky; top:0; background:var(--card-bg); z-index:1; border-radius:14px 14px 0 0;
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

.order-summary-box {
    background:var(--page-bg); border:1px solid var(--card-border);
    border-radius:10px; padding:14px 16px; margin-bottom:16px;
    display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap;
}
.order-summary-id  { font-size:1.2rem; font-weight:800; color:var(--primary); }
.order-summary-sub { font-size:0.78rem; color:var(--text-muted); margin-top:2px; }

.form-grid  { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px; }
.form-group { display:flex; flex-direction:column; gap:5px; }
.form-label { font-size:0.8rem; font-weight:600; color:var(--dark); }
.form-label .req { color:#ef4444; margin-left:2px; }
.form-select, .form-input, .form-textarea {
    padding:9px 12px; border:1px solid var(--card-border); border-radius:8px;
    font-size:0.87rem; color:var(--text); background:#fff; outline:none;
    font-family:inherit; width:100%; transition:border-color 0.15s, box-shadow 0.15s;
    box-sizing:border-box;
}
.form-select:focus, .form-input:focus, .form-textarea:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(230,126,34,0.12); }
.form-textarea { resize:vertical; min-height:72px; }
.form-hint { font-size:0.72rem; color:var(--text-muted); }

.fee-section { background:#fafafa; border:1.5px solid var(--card-border); border-radius:10px; padding:14px 16px; margin-bottom:14px; }
.fee-section-title { font-size:0.72rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:var(--primary); margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.fee-preview { display:none; margin-top:8px; background:#ecfdf5; border:1px solid #6ee7b7; border-radius:7px; padding:8px 12px; font-size:0.82rem; color:#065f46; line-height:1.5; }
.fee-preview.show { display:block; }
.fee-current-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:6px; font-size:0.72rem; font-weight:700; background:#d1fae5; color:#065f46; margin-left:6px; }
.fee-unset-badge   { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:6px; font-size:0.72rem; font-weight:700; background:#fef3c7; color:#92400e; margin-left:6px; }

.info-note { display:flex; align-items:flex-start; gap:8px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 14px; font-size:0.82rem; color:#1e40af; margin-bottom:14px; line-height:1.5; }

/* Status tracker */
.status-steps { display:flex; align-items:center; background:var(--page-bg); border:1px solid var(--card-border); border-radius:10px; padding:16px 20px; margin-bottom:18px; overflow-x:auto; }
.status-step { display:flex; flex-direction:column; align-items:center; gap:4px; flex:1; min-width:56px; }
.status-step-dot { width:26px; height:26px; border-radius:50%; background:#e5e7eb; color:var(--text-muted); display:flex; align-items:center; justify-content:center; font-size:0.7rem; font-weight:700; position:relative; z-index:1; }
.status-step-dot.done    { background:#10b981; color:#fff; }
.status-step-dot.current { background:var(--primary); color:#fff; box-shadow:0 0 0 4px rgba(230,126,34,0.2); }
.status-step-label { font-size:0.58rem; font-weight:600; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); text-align:center; line-height:1.3; }
.status-step-label.done    { color:#10b981; }
.status-step-label.current { color:var(--primary); }
.status-step-connector { height:2px; background:#e5e7eb; flex:1; margin-bottom:16px; }
.status-step-connector.done { background:#10b981; }

/* Proof image in view modal */
.proof-img-wrap { margin-top:10px; }
.proof-img-wrap img { max-width:100%; border-radius:10px; border:1px solid var(--card-border); box-shadow:0 4px 16px rgba(0,0,0,0.08); }

.btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:9px 18px; border-radius:8px; font-size:0.88rem;
    font-weight:600; cursor:pointer; border:1px solid;
    transition:background 0.15s, transform 0.1s; font-family:inherit;
}
.btn:active         { transform:translateY(1px); }
.btn-primary        { background:var(--primary); color:#fff; border-color:var(--primary); }
.btn-primary:hover  { background:#cf6d17; border-color:#cf6d17; }
.btn-success        { background:#10b981; color:#fff; border-color:#10b981; }
.btn-success:hover  { background:#059669; }
.btn-ghost          { background:transparent; color:var(--text-muted); border-color:var(--card-border); }
.btn-ghost:hover    { background:var(--page-bg); color:var(--text); }
.btn-danger         { background:#ef4444; color:#fff; border-color:#ef4444; }
.btn-danger:hover   { background:#dc2626; }

.mobile-menu-btn { display:none; align-items:center; justify-content:center; width:36px; height:36px; border:1px solid var(--card-border); border-radius:8px; background:var(--card-bg); cursor:pointer; color:var(--dark); }
@media(max-width:768px) {
    .main-content    { margin-left:0; padding:16px 16px 48px; width:100%; }
    .mobile-menu-btn { display:flex; }
    .form-grid       { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="admin-layout">
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">

<div class="page-header">
    <div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
            <button class="mobile-menu-btn" onclick="openSidebar()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <h1 class="page-title">Orders</h1>
        </div>
        <div class="page-title-sub">Approve or reject orders, set delivery fees, and confirm payments</div>
    </div>
</div>

<?= flash() ?>

<!-- Stat Cards -->
<div class="stats-row">
    <div class="stat-card sc-orange">
        <div class="stat-card-accent"></div>
        <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></div>
        <div class="stat-body"><div class="stat-value"><?= number_format($totalOrders) ?></div><div class="stat-label">Total Orders</div></div>
    </div>
    <div class="stat-card sc-blue">
        <div class="stat-card-accent"></div>
        <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
        <div class="stat-body"><div class="stat-value"><?= number_format($todayCount) ?></div><div class="stat-label">Orders Today</div></div>
    </div>
    <div class="stat-card sc-amber">
        <div class="stat-card-accent"></div>
        <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
        <div class="stat-body"><div class="stat-value"><?= number_format($pendingCount) ?></div><div class="stat-label">Pending Review</div></div>
    </div>
    <div class="stat-card sc-green">
        <div class="stat-card-accent"></div>
        <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
        <div class="stat-body"><div class="stat-value" style="font-size:1.3rem;">₱<?= number_format($totalRevenue, 0) ?></div><div class="stat-label">Revenue Collected</div></div>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
<div class="alert-banner alert-warn">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><strong><?= $pendingCount ?> order<?= $pendingCount !== 1 ? 's' : '' ?></strong> waiting for your approval. Review and approve or reject below.</span>
</div>
<?php endif; ?>

<?php if ($proofPendingCount > 0): ?>
<div class="alert-banner alert-blue">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
    <span><strong><?= $proofPendingCount ?> GCash order<?= $proofPendingCount !== 1 ? 's' : '' ?></strong> approved but awaiting customer payment proof upload.</span>
</div>
<?php endif; ?>

<!-- Status Filter Tabs -->
<div class="filter-tabs">
    <?php
    $tabDefs = [
        ''                 => 'All',
        'pending'          => 'Pending',
        'approved'         => 'Approved',
        'processing'       => 'Processing',
        'out_for_delivery' => 'Out for Delivery',
        'delivered'        => 'Delivered',
        'cancelled'        => 'Cancelled/Rejected',
    ];
    foreach ($tabDefs as $val => $label):
        $qs     = http_build_query(array_merge($_GET, ['status' => $val, 'page' => 1]));
        $cnt    = ($val === '') ? $totalOrders : ($statusCounts[$val] ?? 0);
        $active = ($filterStatus === $val) ? 'active' : '';
    ?>
    <a href="?<?= $qs ?>" class="filter-tab <?= $active ?>">
        <?= $label ?> <span class="tab-count"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <form method="GET" style="display:contents;">
            <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
            <div class="search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="q" class="search-input" placeholder="Search customer, order #…" value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="payment" class="filter-select" onchange="this.form.submit()">
                <option value="">All Payments</option>
                <option value="unpaid" <?= $filterPayment==='unpaid'?'selected':'' ?>>Unpaid</option>
                <option value="paid"   <?= $filterPayment==='paid'  ?'selected':'' ?>>Paid</option>
            </select>
            <select name="method" class="filter-select" onchange="this.form.submit()">
                <option value="">All Methods</option>
                <option value="cash"  <?= $filterMethod==='cash' ?'selected':'' ?>>Cash</option>
                <option value="gcash" <?= $filterMethod==='gcash'?'selected':'' ?>>GCash</option>
                <option value="cod"   <?= $filterMethod==='cod'  ?'selected':'' ?>>COD</option>
            </select>
            <?php if ($search||$filterPayment||$filterMethod): ?>
                <a href="orders.php?status=<?= urlencode($filterStatus) ?>" style="font-size:0.8rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="toolbar-right" style="font-size:0.82rem;color:var(--text-muted);">
        <?= number_format($totalCount) ?> order<?= $totalCount!==1?'s':'' ?>
    </div>
</div>

<!-- Table -->
<div class="table-wrapper">
    <?php if ($orders && $orders->num_rows > 0): ?>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:44px;">#</th>
                <th>Order</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Total</th>
                <th>Method</th>
                <th>Payment</th>
                <th>Proof</th>
                <th>Status</th>
                <th style="text-align:center;min-width:200px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $rowNum = $offset + 1;
        while ($o = $orders->fetch_assoc()):
            $statusClass   = 's-' . $o['status'];
            $statusLabel   = ucwords(str_replace('_', ' ', $o['status']));
            $initial       = strtoupper(substr($o['full_name'], 0, 1));
            $isPending     = ($o['status'] === 'pending');
            $isApproved    = ($o['status'] === 'approved');
            $isCancelled   = ($o['status'] === 'cancelled');
            $isDelivered   = ($o['status'] === 'delivered');
            $isFinalised   = ($isCancelled || $isDelivered);
            $methodIcon    = $o['payment_method'] === 'gcash' ? '<i class="fa-solid fa-mobile-screen"></i>' : '<i class="fa-solid fa-money-bill"></i>';
            $feeIsSet      = $o['delivery_fee'] !== null;
            $itemsSubtotal = (float)$o['items_subtotal'];
            $hasProof      = !empty($o['gcash_proof']);
        ?>
        <tr>
            <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $rowNum++ ?></td>
            <td>
                <div class="order-id">#<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?></div>
                <div class="order-date"><?= date('M j, Y · g:i A', strtotime($o['created_at'])) ?></div>
            </td>
            <td>
                <div class="customer-cell">
                    <div class="customer-avatar"><?= htmlspecialchars($initial) ?></div>
                    <div>
                        <div class="customer-name"><?= htmlspecialchars($o['full_name']) ?></div>
                        <div class="customer-sub"><?= $o['phone'] ? htmlspecialchars($o['phone']) : htmlspecialchars($o['email']) ?></div>
                    </div>
                </div>
            </td>
            <td style="font-weight:600;color:var(--dark);">
                <?= (int)$o['item_count'] ?>
                <span style="font-weight:400;color:var(--text-muted);font-size:0.78rem;">item<?= (int)$o['item_count']!==1?'s':'' ?></span>
            </td>
            <td>
                <div class="total-main">₱<?= number_format((float)$o['total_amount'], 2) ?></div>
                <?php if (!$isCancelled): ?>
                    <?php if ($feeIsSet): ?>
                        <div class="total-fee-set">+₱<?= number_format((float)$o['delivery_fee'], 2) ?> fee ✓</div>
                    <?php else: ?>
                        <div class="total-fee-unset">Fee not set <i class="fa-solid fa-triangle-exclamation"></i></div>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td><span class="method-badge"><?= $methodIcon ?> <?= strtoupper($o['payment_method']) ?></span></td>
            <td>
                <span class="status-badge <?= $o['payment_status']==='paid'?'pay-paid':'pay-unpaid' ?>">
                    <?= $o['payment_status']==='paid'?'Paid':'Unpaid' ?>
                </span>
            </td>
            <td>
                <?php if ($o['payment_method'] === 'gcash'): ?>
                    <?php if ($hasProof): ?>
                        <span class="proof-badge proof-yes" onclick="viewProof('<?= htmlspecialchars(addslashes($o['gcash_proof'])) ?>', '<?= str_pad($o['id'],4,'0',STR_PAD_LEFT) ?>')">
                            <i class="fa-solid fa-paperclip"></i> View
                        </span>
                    <?php else: ?>
                        <span class="proof-badge proof-no"><i class="fa-solid fa-clock"></i> None</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="font-size:0.72rem;color:var(--text-muted);">N/A</span>
                <?php endif; ?>
            </td>
            <td><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
            <td style="text-align:center;">
                <div style="display:flex;align-items:center;justify-content:center;gap:4px;flex-wrap:wrap;">
                    <button class="btn-action btn-view" onclick="openView(<?= $o['id'] ?>)">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        View
                    </button>

                    <?php if ($isPending): ?>
                        <button class="btn-action btn-approve"
                            onclick="openApprove(<?= htmlspecialchars(json_encode([
                                'id'             => $o['id'],
                                'full_name'      => $o['full_name'],
                                'total_amount'   => $o['total_amount'],
                                'items_subtotal' => $itemsSubtotal,
                                'delivery_fee'   => $o['delivery_fee'],
                                'delivery_address'=> $o['delivery_address'],
                                'payment_method' => $o['payment_method'],
                            ]), ENT_QUOTES) ?>)">
                            ✓ Approve
                        </button>
                        <button class="btn-action btn-reject"
                            onclick="openReject(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['full_name'])) ?>')">
                            ✕ Reject
                        </button>

                    <?php elseif (!$isFinalised): ?>
                        <button class="btn-action btn-edit-s"
                            onclick="openUpdate(<?= htmlspecialchars(json_encode([
                                'id'              => $o['id'],
                                'full_name'       => $o['full_name'],
                                'status'          => $o['status'],
                                'payment_status'  => $o['payment_status'],
                                'payment_method'  => $o['payment_method'],
                                'total_amount'    => $o['total_amount'],
                                'delivery_fee'    => $o['delivery_fee'],
                                'items_subtotal'  => $itemsSubtotal,
                                'delivery_address'=> $o['delivery_address'],
                            ]), ENT_QUOTES) ?>)">
                            <i class="fa-solid fa-pen-to-square"></i> Update
                        </button>
                        <button class="btn-action btn-cancel"
                            onclick="openCancel(<?= $o['id'] ?>, '<?= htmlspecialchars(addslashes($o['full_name'])) ?>')">
                            ✕
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
        <div>Showing <?= number_format($offset+1) ?>–<?= number_format(min($offset+$perPage,$totalCount)) ?> of <?= number_format($totalCount) ?> orders</div>
        <div class="pagination-pages">
            <?php
            $qs=http_build_query(array_merge($_GET,['page'=>max(1,$page-1)]));
            echo "<a href='?{$qs}' class='pg-btn".($page<=1?' disabled':'')."'>← Prev</a>";
            $s2=max(1,$page-2);$e2=min($totalPages,$page+2);
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
    <div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-cart-shopping"></i></div><div>No orders found<?= ($search||$filterStatus||$filterPayment||$filterMethod)?' — try adjusting filters.':'.'; ?></div></div>
    <?php endif; ?>
</div>

</div><!-- /.main-content -->
</div><!-- /.admin-layout -->


<!-- ══ VIEW MODAL ══ -->
<div class="modal-backdrop" id="viewModal" onclick="backdropClose(event,'viewModal')">
    <div class="modal-card lg">
        <div class="modal-header">
            <div class="modal-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <span id="view_modal_title">Order Details</span>
            </div>
            <button class="modal-close" onclick="closeModal('viewModal')">✕</button>
        </div>
        <div class="modal-body">
            <div id="view_content" style="text-align:center;padding:32px;color:var(--text-muted);">Loading…</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>


<!-- ══ APPROVE MODAL ══ -->
<div class="modal-backdrop" id="approveModal" onclick="backdropClose(event,'approveModal')">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title" style="color:#10b981;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Approve Order
            </div>
            <button class="modal-close" onclick="closeModal('approveModal')">✕</button>
        </div>
        <form method="POST" action="orders.php">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id" id="approve_id">
            <div class="modal-body">

                <div class="order-summary-box">
                    <div style="font-size:1.5rem;"><i class="fa-solid fa-cart-shopping"></i></div>
                    <div style="flex:1;">
                        <div class="order-summary-id" id="approve_order_label">#0000</div>
                        <div class="order-summary-sub" id="approve_customer_label">Customer</div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;" id="approve_address_label"></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:1rem;font-weight:800;color:#10b981;" id="approve_total_label">₱0.00</div>
                        <div style="font-size:0.73rem;color:var(--text-muted);" id="approve_method_label"></div>
                    </div>
                </div>

                <!-- Delivery fee — REQUIRED before approving -->
                <div class="fee-section">
                    <div class="fee-section-title">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                        Set Delivery Fee
                    </div>
                    <div class="form-group">
                        <label class="form-label">Delivery Fee (₱)</label>
                        <input type="number" name="delivery_fee" id="approve_delivery_fee"
                               class="form-input" step="0.01" min="0" max="9999.99"
                               placeholder="e.g. 80.00 (leave blank for pickup / free)"
                               oninput="updateApproveFeePreview()">
                        <span class="form-hint">Based on the customer's delivery address. Leave blank if pickup or free delivery.</span>
                        <div class="fee-preview" id="approve_fee_preview"></div>
                    </div>
                </div>

                <div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:12px 14px;font-size:0.85rem;color:#065f46;line-height:1.6;">
                    <i class="fa-solid fa-circle-check"></i> <strong>Approving</strong> this order will notify the customer that their order is confirmed. If they chose GCash, they will be prompted to upload their payment proof.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Approve Order
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══ REJECT MODAL ══ -->
<div class="modal-backdrop" id="rejectModal" onclick="backdropClose(event,'rejectModal')">
    <div class="modal-card sm">
        <div class="modal-header">
            <div class="modal-title" style="color:#ef4444;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Reject Order
            </div>
            <button class="modal-close" onclick="closeModal('rejectModal')">✕</button>
        </div>
        <form method="POST" action="orders.php">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="reject_id">
            <div class="modal-body">
                <div style="text-align:center;margin-bottom:18px;">
                    <div style="font-size:3rem;margin-bottom:12px;">✕</div>
                    <div style="font-size:1rem;font-weight:700;color:var(--dark);margin-bottom:4px;">Reject Order <span id="reject_order_label">#0000</span>?</div>
                    <div style="font-size:0.85rem;color:var(--text-muted);">from <strong id="reject_customer_label"></strong></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason for Rejection</label>
                    <textarea name="reject_reason" class="form-textarea" placeholder="e.g. Out of stock, item unavailable, outside delivery area…" rows="3"></textarea>
                    <span class="form-hint">This will be saved in the order notes. Stock will be automatically restored.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Keep Order</button>
                <button type="submit" class="btn btn-danger">✕ Reject Order</button>
            </div>
        </form>
    </div>
</div>


<!-- ══ UPDATE STATUS MODAL ══ -->
<div class="modal-backdrop" id="updateModal" onclick="backdropClose(event,'updateModal')">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Update Order
            </div>
            <button class="modal-close" onclick="closeModal('updateModal')">✕</button>
        </div>
        <form method="POST" action="orders.php">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" id="upd_id">
            <div class="modal-body">

                <div class="order-summary-box">
                    <div style="font-size:1.5rem;"><i class="fa-solid fa-cart-shopping"></i></div>
                    <div style="flex:1;">
                        <div class="order-summary-id" id="upd_order_label">#0000</div>
                        <div class="order-summary-sub" id="upd_customer_label">Customer</div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;" id="upd_address_label"></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:1rem;font-weight:800;color:#10b981;" id="upd_total_label">₱0.00</div>
                        <div style="font-size:0.73rem;color:var(--text-muted);" id="upd_method_label"></div>
                    </div>
                </div>

                <div class="status-steps" id="status_steps_track"></div>

                <div class="info-note" id="gcash_note" style="display:none;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span>GCash order — verify the customer's payment proof before marking as Paid.</span>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Order Status <span class="req">*</span></label>
                        <select name="status" id="upd_status" class="form-select" required onchange="updateStatusTracker(this.value)">
                            <option value="approved"><i class="fa-solid fa-circle-check"></i> Approved</option>
                            <option value="processing"><i class="fa-solid fa-gear"></i> Processing</option>
                            <option value="out_for_delivery"><i class="fa-solid fa-truck"></i> Out for Delivery</option>
                            <option value="delivered"><i class="fa-solid fa-champagne-glasses"></i> Delivered</option>
                            <option value="cancelled"><i class="fa-solid fa-circle-xmark"></i> Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Payment Status <span class="req">*</span></label>
                        <select name="payment_status" id="upd_payment_status" class="form-select" required>
                            <option value="unpaid"><i class="fa-solid fa-circle-exclamation"></i> Unpaid</option>
                            <option value="paid">🟢 Paid</option>
                        </select>
                    </div>
                </div>

                <div class="fee-section">
                    <div class="fee-section-title">
                        <i class="fa-solid fa-truck"></i> Delivery Fee <span id="upd_fee_current_badge"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Update Delivery Fee (₱)</label>
                        <input type="number" name="delivery_fee" id="upd_delivery_fee"
                               class="form-input" step="0.01" min="0" max="9999.99"
                               placeholder="Leave blank to keep existing"
                               oninput="updateFeePreview()">
                        <span class="form-hint">Leave blank to keep the existing fee unchanged.</span>
                        <div class="fee-preview" id="fee_preview"></div>
                    </div>
                </div>

                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:0.82rem;color:#92400e;line-height:1.5;">
                    <i class="fa-solid fa-lightbulb"></i> Marking as <strong>Paid</strong> automatically creates a transaction record if none exists yet.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('updateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══ CANCEL MODAL ══ -->
<div class="modal-backdrop" id="cancelModal" onclick="backdropClose(event,'cancelModal')">
    <div class="modal-card sm">
        <div class="modal-header">
            <div class="modal-title" style="color:#ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Cancel Order</div>
            <button class="modal-close" onclick="closeModal('cancelModal')">✕</button>
        </div>
        <form method="POST" action="orders.php">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" id="cancel_id">
            <div class="modal-body" style="text-align:center;padding:28px 24px;">
                <div style="font-size:3rem;margin-bottom:14px;"><i class="fa-solid fa-ban"></i></div>
                <div style="font-size:1rem;font-weight:700;color:var(--dark);margin-bottom:8px;">Cancel this order?</div>
                <div style="font-size:0.88rem;color:var(--text-muted);line-height:1.6;">
                    Order <strong id="cancel_order_label">#0000</strong> from <strong id="cancel_customer_label"></strong> will be cancelled.<br><br>
                    <span style="color:#10b981;font-weight:600;"><i class="fa-solid fa-check"></i> Stock will be automatically restored</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('cancelModal')">Keep Order</button>
                <button type="submit" class="btn btn-danger">Yes, Cancel</button>
            </div>
        </form>
    </div>
</div>


<!-- ══ PROOF IMAGE MODAL ══ -->
<div class="modal-backdrop" id="proofModal" onclick="backdropClose(event,'proofModal')">
    <div class="modal-card sm">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-solid fa-paperclip"></i> GCash Payment Proof</div>
            <button class="modal-close" onclick="closeModal('proofModal')">✕</button>
        </div>
        <div class="modal-body">
            <div style="font-size:0.82rem;color:var(--text-muted);margin-bottom:10px;" id="proof_order_label"></div>
            <div class="proof-img-wrap">
                <img id="proof_img_el" src="" alt="GCash Payment Proof" style="max-width:100%;border-radius:10px;border:1px solid var(--card-border);">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeModal('proofModal')">Close</button>
        </div>
    </div>
</div>


<script>
// ── Modal helpers ──────────────────────────────────────────────
function openModal(id)        { document.getElementById(id).classList.add('open');    document.body.style.overflow='hidden'; }
function closeModal(id)       { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function backdropClose(e, id) { if(e.target===document.getElementById(id)) closeModal(id); }
document.addEventListener('keydown', function(e) {
    if (e.key==='Escape') {
        ['viewModal','approveModal','rejectModal','updateModal','cancelModal','proofModal']
            .forEach(id => document.getElementById(id).classList.remove('open'));
        document.body.style.overflow='';
    }
});

// ── Status tracker ─────────────────────────────────────────────
const STATUS_FLOW   = ['approved','processing','out_for_delivery','delivered'];
const STATUS_LABELS = { approved:'Approved', processing:'Processing', out_for_delivery:'Out for\nDelivery', delivered:'Delivered' };

function updateStatusTracker(cur) {
    var c = document.getElementById('status_steps_track');
    c.innerHTML = '';
    if (cur==='cancelled') {
        c.innerHTML='<div style="display:flex;align-items:center;gap:10px;"><div style="width:28px;height:28px;border-radius:50%;background:#ef4444;color:#fff;display:flex;align-items:center;justify-content:center;">✕</div><div style="font-size:0.85rem;font-weight:700;color:#ef4444;">Order Cancelled</div></div>';
        return;
    }
    var curIdx = STATUS_FLOW.indexOf(cur);
    STATUS_FLOW.forEach(function(s, i) {
        if (i>0) { var conn=document.createElement('div'); conn.className='status-step-connector'+(i<=curIdx?' done':''); c.appendChild(conn); }
        var step=document.createElement('div'); step.className='status-step';
        var dot=document.createElement('div'); var lbl=document.createElement('div');
        if      (i<curIdx) { dot.className='status-step-dot done';    dot.textContent='✓'; lbl.className='status-step-label done'; }
        else if (i===curIdx){ dot.className='status-step-dot current'; dot.textContent=i+1; lbl.className='status-step-label current'; }
        else               { dot.className='status-step-dot';          dot.textContent=i+1; lbl.className='status-step-label'; }
        lbl.style.whiteSpace='pre-line'; lbl.textContent=STATUS_LABELS[s];
        step.appendChild(dot); step.appendChild(lbl); c.appendChild(step);
    });
}

// ── Shared fee formatter ───────────────────────────────────────
function fmtPeso(n) { return '₱'+parseFloat(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }

// ── Approve modal ──────────────────────────────────────────────
var _approveSubtotal = 0;
function openApprove(data) {
    _approveSubtotal = parseFloat(data.items_subtotal)||0;
    document.getElementById('approve_id').value = data.id;
    document.getElementById('approve_order_label').textContent    = '#'+String(data.id).padStart(4,'0');
    document.getElementById('approve_customer_label').textContent = data.full_name;
    document.getElementById('approve_method_label').textContent   = data.payment_method.toUpperCase();
    document.getElementById('approve_total_label').textContent    = fmtPeso(data.total_amount);
    var addr = data.delivery_address||'';
    document.getElementById('approve_address_label').textContent  = addr ? '<i class="fa-solid fa-location-dot"></i> '+(addr.length>60?addr.substring(0,60)+'…':addr) : '';
    document.getElementById('approve_delivery_fee').value = '';
    document.getElementById('approve_fee_preview').classList.remove('show');
    openModal('approveModal');
}
function updateApproveFeePreview() {
    var input=document.getElementById('approve_delivery_fee');
    var preview=document.getElementById('approve_fee_preview');
    var val=input.value.trim();
    if(val!==''&&!isNaN(parseFloat(val))&&parseFloat(val)>=0) {
        var fee=parseFloat(val); var total=_approveSubtotal+fee;
        preview.innerHTML='✓ Items: <strong>'+fmtPeso(_approveSubtotal)+'</strong> + Fee: <strong>'+fmtPeso(fee)+'</strong> = New Total: <strong>'+fmtPeso(total)+'</strong>';
        preview.classList.add('show');
        document.getElementById('approve_total_label').textContent=fmtPeso(total);
    } else {
        preview.classList.remove('show');
        document.getElementById('approve_total_label').textContent=fmtPeso(_approveSubtotal);
    }
}

// ── Reject modal ───────────────────────────────────────────────
function openReject(id, name) {
    document.getElementById('reject_id').value=id;
    document.getElementById('reject_order_label').textContent='#'+String(id).padStart(4,'0');
    document.getElementById('reject_customer_label').textContent=name;
    openModal('rejectModal');
}

// ── Update modal ───────────────────────────────────────────────
var _itemsSubtotal = 0;
function openUpdate(data) {
    _itemsSubtotal = parseFloat(data.items_subtotal)||0;
    document.getElementById('upd_id').value=data.id;
    document.getElementById('upd_order_label').textContent    = '#'+String(data.id).padStart(4,'0');
    document.getElementById('upd_customer_label').textContent = data.full_name;
    document.getElementById('upd_method_label').textContent   = data.payment_method.toUpperCase();
    document.getElementById('upd_status').value               = data.status;
    document.getElementById('upd_payment_status').value       = data.payment_status;
    document.getElementById('gcash_note').style.display       = data.payment_method==='gcash'?'flex':'none';
    var addr=data.delivery_address||'';
    document.getElementById('upd_address_label').textContent  = addr?'<i class="fa-solid fa-location-dot"></i> '+(addr.length>60?addr.substring(0,60)+'…':addr):'';
    document.getElementById('upd_total_label').textContent    = fmtPeso(data.total_amount);
    document.getElementById('upd_delivery_fee').value         = '';
    var badgeEl=document.getElementById('upd_fee_current_badge');
    if(data.delivery_fee!==null&&data.delivery_fee!==undefined&&data.delivery_fee!=='') {
        badgeEl.className='fee-current-badge'; badgeEl.textContent=fmtPeso(data.delivery_fee)+' set';
    } else {
        badgeEl.className='fee-unset-badge'; badgeEl.textContent='<i class="fa-solid fa-triangle-exclamation"></i> Not set';
    }
    document.getElementById('fee_preview').classList.remove('show');
    updateStatusTracker(data.status);
    openModal('updateModal');
}
function updateFeePreview() {
    var input=document.getElementById('upd_delivery_fee');
    var preview=document.getElementById('fee_preview');
    var val=input.value.trim();
    if(val!==''&&!isNaN(parseFloat(val))&&parseFloat(val)>=0) {
        var fee=parseFloat(val); var total=_itemsSubtotal+fee;
        preview.innerHTML='✓ Items: <strong>'+fmtPeso(_itemsSubtotal)+'</strong> + Fee: <strong>'+fmtPeso(fee)+'</strong> = <strong>'+fmtPeso(total)+'</strong>';
        preview.classList.add('show');
        document.getElementById('upd_total_label').textContent=fmtPeso(total);
    } else {
        preview.classList.remove('show');
    }
}

// ── Cancel modal ───────────────────────────────────────────────
function openCancel(id,name) {
    document.getElementById('cancel_id').value=id;
    document.getElementById('cancel_order_label').textContent='#'+String(id).padStart(4,'0');
    document.getElementById('cancel_customer_label').textContent=name;
    openModal('cancelModal');
}

// ── Proof image modal ──────────────────────────────────────────
function viewProof(path, orderNum) {
    document.getElementById('proof_order_label').textContent='Order #'+orderNum;
    document.getElementById('proof_img_el').src='../'+path+'?v='+Date.now();
    openModal('proofModal');
}

// ── View modal (AJAX) ──────────────────────────────────────────
function openView(orderId) {
    document.getElementById('view_modal_title').textContent='Order #'+String(orderId).padStart(4,'0');
    document.getElementById('view_content').innerHTML='<div style="text-align:center;padding:32px;color:var(--text-muted);">Loading…</div>';
    openModal('viewModal');
    fetch('order_detail_ajax.php?id='+orderId)
        .then(r=>r.text())
        .then(html=>{ document.getElementById('view_content').innerHTML=html; })
        .catch(()=>{ document.getElementById('view_content').innerHTML='<div style="text-align:center;padding:32px;color:var(--text-muted);"><i class="fa-solid fa-triangle-exclamation"></i> Could not load details.</div>'; });
}

// ── Search ─────────────────────────────────────────────────────
var si=document.querySelector('.search-input');
if(si) si.addEventListener('keydown',function(e){if(e.key==='Enter')e.target.closest('form').submit();});
</script>
</body>
</html>