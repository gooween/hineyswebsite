<?php
session_start();
require_once '../config/db.php';
requireAdmin();

// ── Stock helpers ─────────────────────────────────────────────
function deductStock(mysqli $conn, int $orderId, int $adminId): void
{
    $items = $conn->query("SELECT oi.product_id, oi.quantity, p.name
                           FROM order_items oi
                           JOIN products p ON p.id = oi.product_id
                           WHERE oi.order_id = {$orderId}");
    if (!$items) return;
    while ($item = $items->fetch_assoc()) {
        $pid = (int)$item['product_id'];
        $qty = (int)$item['quantity'];
        $remaining = $qty;
        $batches = $conn->query("SELECT id, remaining FROM stock_batches WHERE product_id={$pid} AND status='active' AND remaining>0 ORDER BY created_at ASC");
        while ($remaining > 0 && $batch = $batches->fetch_assoc()) {
            $bid = $batch['id'];
            $avail = (int)$batch['remaining'];
            $deduct = min($remaining, $avail);
            $newLeft = $avail - $deduct;
            $conn->query("UPDATE stock_batches SET remaining={$newLeft}, status='" . ($newLeft <= 0 ? 'depleted' : 'active') . "' WHERE id={$bid}");
            $conn->query("INSERT INTO batch_consumption (batch_id,order_id,quantity) VALUES ({$bid},{$orderId},{$deduct})");
            $remaining -= $deduct;
        }
        $conn->query("UPDATE inventory SET quantity=quantity-{$qty} WHERE product_id={$pid} AND quantity>={$qty}");
        $reason = $conn->real_escape_string("Order #" . str_pad($orderId, 4, '0', STR_PAD_LEFT) . " approved — {$item['name']} x{$qty}");
        $conn->query("INSERT INTO inventory_logs (product_id,type,quantity,reason,created_by,created_at) VALUES ({$pid},'out',{$qty},'{$reason}',{$adminId},NOW())");
    }
}

function restoreStock(mysqli $conn, int $orderId, int $adminId, string $label): void
{
    $check = $conn->query("SELECT COUNT(*) AS cnt FROM batch_consumption WHERE order_id={$orderId}");
    if (!$check || (int)$check->fetch_assoc()['cnt'] === 0) return; // never approved, nothing to restore
    $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id={$orderId}");
    if (!$items) return;
    while ($item = $items->fetch_assoc()) {
        $pid = (int)$item['product_id'];
        $qty = (int)$item['quantity'];
        $toRestore = $qty;
        $consumed = $conn->query("SELECT bc.id AS cid, bc.batch_id, bc.quantity, sb.remaining FROM batch_consumption bc JOIN stock_batches sb ON sb.id=bc.batch_id WHERE bc.order_id={$orderId} AND sb.product_id={$pid} ORDER BY bc.id DESC");
        while ($toRestore > 0 && $con = $consumed->fetch_assoc()) {
            $restore = min($toRestore, (int)$con['quantity']);
            $newRemain = (int)$con['remaining'] + $restore;
            $conn->query("UPDATE stock_batches SET remaining={$newRemain}, status='active' WHERE id={$con['batch_id']}");
            $conn->query("DELETE FROM batch_consumption WHERE id={$con['cid']}");
            $toRestore -= $restore;
        }
        $r = $conn->real_escape_string("{$label} order #" . str_pad($orderId, 4, '0', STR_PAD_LEFT));
        $conn->query("UPDATE inventory SET quantity=quantity+{$qty}, last_updated=NOW() WHERE product_id={$pid}");
        $conn->query("INSERT INTO inventory_logs (product_id,type,quantity,reason,created_by,created_at) VALUES ({$pid},'in',{$qty},'{$r}',{$adminId},NOW())");
    }
}
// ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'approve') {
        $id     = (int)($_POST['id'] ?? 0);
        $newFee = (isset($_POST['delivery_fee']) && $_POST['delivery_fee'] !== '') ? (float)$_POST['delivery_fee'] : null;
        if ($id) {
            if ($newFee !== null && $newFee >= 0) {
                $itemsResult = $conn->query("SELECT COALESCE(SUM(subtotal),0) AS t FROM order_items WHERE order_id={$id}");
                $itemsTotal  = (float)($itemsResult->fetch_assoc()['t'] ?? 0);
                $newTotal    = $itemsTotal + $newFee;
                $conn->query("UPDATE orders SET status='approved', delivery_fee={$newFee}, total_amount={$newTotal}, updated_at=NOW() WHERE id={$id}");
                $note = $conn->real_escape_string('[Admin] Order approved. Delivery fee: ₱' . number_format($newFee, 2) . '. Total: ₱' . number_format($newTotal, 2) . '.');
            } else {
                $conn->query("UPDATE orders SET status='approved', updated_at=NOW() WHERE id={$id}");
                $note = $conn->real_escape_string('[Admin] Order approved.');
            }
            $conn->query("UPDATE orders SET notes=CONCAT(IFNULL(notes,''),IF(notes IS NULL OR notes='','','\n'),'{$note}') WHERE id={$id}");
            // ── Deduct stock on approval ──
            deductStock($conn, $id, (int)$_SESSION['user_id']);
            redirect('orders.php', 'success', 'Order approved and stock deducted.');
        }
    }

    if ($action === 'reject') {
        $id     = (int)($_POST['id'] ?? 0);
        $reason = $conn->real_escape_string(trim($_POST['reject_reason'] ?? ''));
        if ($id) {
            $conn->query("UPDATE orders SET status='cancelled', updated_at=NOW() WHERE id={$id}");
            $note = $conn->real_escape_string('[Admin] Order rejected' . ($reason ? ': ' . $reason : '.'));
            $conn->query("UPDATE orders SET notes=CONCAT(IFNULL(notes,''),IF(notes IS NULL OR notes='','','\n'),'{$note}') WHERE id={$id}");
            // ── Restore stock only if it was previously approved ──
            restoreStock($conn, $id, (int)$_SESSION['user_id'], 'Restored from rejected');
            redirect('orders.php', 'success', 'Order rejected.');
        }
    }

    if ($action === 'update_status') {
        $id             = (int)($_POST['id'] ?? 0);
        $status         = $conn->real_escape_string(trim($_POST['status'] ?? ''));
        $payment_status = $conn->real_escape_string(trim($_POST['payment_status'] ?? ''));
        $valid_statuses = ['pending', 'approved', 'processing', 'out_for_delivery', 'delivered', 'cancelled'];
        $valid_payment  = ['unpaid', 'paid'];
        if ($id && in_array($status, $valid_statuses) && in_array($payment_status, $valid_payment)) {
            $conn->query("UPDATE orders SET status='{$status}', payment_status='{$payment_status}', updated_at=NOW() WHERE id={$id}");
            $newFee = (isset($_POST['delivery_fee']) && $_POST['delivery_fee'] !== '') ? (float)$_POST['delivery_fee'] : null;
            if ($newFee !== null && $newFee >= 0) {
                $itemsResult = $conn->query("SELECT COALESCE(SUM(subtotal),0) AS t FROM order_items WHERE order_id={$id}");
                $itemsTotal  = (float)($itemsResult->fetch_assoc()['t'] ?? 0);
                $newTotal    = $itemsTotal + $newFee;
                $conn->query("UPDATE orders SET delivery_fee={$newFee}, total_amount={$newTotal}, updated_at=NOW() WHERE id={$id}");
                $feeNote = $conn->real_escape_string('[Admin] Delivery fee updated to ₱' . number_format($newFee, 2) . '. New total: ₱' . number_format($newTotal, 2) . '.');
                $conn->query("UPDATE orders SET notes=CONCAT(IFNULL(notes,''),IF(notes IS NULL OR notes='','','\n'),'{$feeNote}') WHERE id={$id}");
            }
            if ($payment_status === 'paid') {
                $chk = $conn->query("SELECT id FROM transactions WHERE order_id={$id} LIMIT 1");
                if ($chk && $chk->num_rows === 0) {
                    $ord = $conn->query("SELECT total_amount, payment_method FROM orders WHERE id={$id} LIMIT 1");
                    if ($ord && $row = $ord->fetch_assoc()) {
                        $amt  = (float)$row['total_amount'];
                        $meth = $conn->real_escape_string($row['payment_method']);
                        $note = $conn->real_escape_string('Auto-recorded when order marked as paid');
                        $conn->query("INSERT INTO transactions (order_id,amount,payment_method,transaction_date,notes) VALUES ({$id},{$amt},'{$meth}',NOW(),'{$note}')");
                    }
                }
            }
            // ── If status changed to cancelled, restore stock ──
            if ($status === 'cancelled') {
                restoreStock($conn, $id, (int)$_SESSION['user_id'], 'Returned from cancelled');
            }
            redirect('orders.php', 'success', 'Order updated successfully.');
        } else {
            redirect('orders.php', 'error', 'Invalid status values provided.');
        }
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("UPDATE orders SET status='cancelled', updated_at=NOW() WHERE id={$id}");
            // ── Restore stock only if it was previously approved ──
            restoreStock($conn, $id, (int)$_SESSION['user_id'], 'Returned from cancelled');
            redirect('orders.php', 'success', 'Order cancelled and stock restored.');
        }
    }
}

$search        = trim($_GET['q'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');
$filterPayment = trim($_GET['payment'] ?? '');
$filterMethod  = trim($_GET['method'] ?? '');
$perPage       = 15;
$page          = max(1, (int)($_GET['page'] ?? 1));
$offset        = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (u.full_name LIKE '%{$s}%' OR o.id LIKE '%{$s}%' OR o.delivery_address LIKE '%{$s}%')";
}
if ($filterStatus) $where .= " AND o.status='{$conn->real_escape_string($filterStatus)}'";
if ($filterPayment) $where .= " AND o.payment_status='{$conn->real_escape_string($filterPayment)}'";
if ($filterMethod) $where .= " AND o.payment_method='{$conn->real_escape_string($filterMethod)}'";

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
    FROM orders o JOIN users u ON u.id=o.user_id
    LEFT JOIN order_items oi ON oi.order_id=o.id
    {$where}
    GROUP BY o.id,o.status,o.total_amount,o.delivery_fee,o.payment_method,
             o.payment_status,o.delivery_address,o.notes,o.gcash_proof,
             o.created_at,o.updated_at,u.full_name,u.email,u.phone
    ORDER BY o.created_at DESC LIMIT {$perPage} OFFSET {$offset}
");

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
    <title>Orders — Hiney's Admin</title>
    <style>
        /* Page-specific only — shared system comes from admin.css */

        /* Alert banners */
        .alert-banner {
            display: flex;
            align-items: center;
            gap: var(--s3);
            border-radius: var(--r);
            padding: var(--s3) var(--s4);
            font-size: var(--fs-sm);
            margin-bottom: var(--s3);
        }

        .alert-banner svg {
            flex-shrink: 0;
        }

        .alert-warn {
            background: var(--warn-tint);
            border: 1px solid #f2ddb0;
            color: #8a5a0c;
        }

        .alert-warn svg {
            stroke: var(--warn);
        }

        .alert-blue {
            background: var(--info-tint);
            border: 1px solid #bcd6f5;
            color: #2b62ad;
        }

        .alert-blue svg {
            stroke: var(--info);
        }

        /* Filter tabs */
        .filter-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: var(--s4);
            border-bottom: 1px solid var(--line);
            overflow-x: auto;
            padding-bottom: 0;
        }

        .filter-tab {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px var(--s4);
            font-size: var(--fs-sm);
            font-weight: var(--fw-med);
            color: var(--ink-2);
            white-space: nowrap;
            position: relative;
            border-bottom: 2px solid transparent;
            transition: color 0.14s;
        }

        .filter-tab:hover {
            color: var(--ink);
        }

        .filter-tab.active {
            color: var(--brand);
            border-bottom-color: var(--brand);
            font-weight: var(--fw-semi);
        }

        .tab-count {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            background: var(--surface-2);
            color: var(--ink-2);
            padding: 1px 7px;
            border-radius: var(--r-pill);
            min-width: 18px;
            text-align: center;
        }

        .filter-tab.active .tab-count {
            background: var(--brand-tint);
            color: var(--brand-strong);
        }

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
            width: 230px;
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

        .toolbar-right {
            font-size: var(--fs-sm);
            color: var(--ink-3);
        }

        /* Order & customer cells */
        .order-id {
            font-weight: var(--fw-bold);
            color: var(--brand);
            font-size: 0.9rem;
        }

        .order-date {
            font-size: var(--fs-xs);
            color: var(--ink-3);
            margin-top: 1px;
        }

        .cust-name {
            font-weight: var(--fw-semi);
            color: var(--ink);
            font-size: var(--fs-sm);
        }

        .cust-sub {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        .total-main {
            font-weight: var(--fw-bold);
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        .total-fee-set {
            font-size: var(--fs-xs);
            color: var(--ok);
            margin-top: 1px;
            font-weight: var(--fw-med);
        }

        .total-fee-unset {
            font-size: var(--fs-xs);
            color: var(--warn);
            margin-top: 1px;
            font-weight: var(--fw-semi);
        }

        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            background: var(--surface-2);
            color: var(--ink-2);
        }

        .proof-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            cursor: pointer;
        }

        .proof-yes {
            background: var(--brand-tint);
            color: var(--brand-strong);
        }

        .proof-yes:hover {
            background: var(--brand-tint-2);
        }

        .proof-no {
            background: var(--surface-2);
            color: var(--ink-3);
            cursor: default;
        }

        /* Status + payment pills use shared .pill; map colors here */
        .st-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            white-space: nowrap;
        }

        .st-pill::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .s-pending {
            background: var(--warn-tint);
            color: #8a5a0c;
        }

        .s-approved {
            background: var(--info-tint);
            color: #2b62ad;
        }

        .s-processing {
            background: #f0ecfa;
            color: #6a4bc0;
        }

        .s-out_for_delivery {
            background: var(--brand-tint);
            color: var(--brand-strong);
        }

        .s-delivered {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .s-cancelled {
            background: var(--danger-tint);
            color: #b23c34;
        }

        .pay-paid {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .pay-unpaid {
            background: var(--danger-tint);
            color: #b23c34;
        }

        /* Row actions — icon by default, expand to show label on hover (matches Products) */
        .row-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .oact {
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

        .oact svg,
        .oact i {
            flex-shrink: 0;
        }

        .oact .act-label {
            max-width: 0;
            opacity: 0;
            margin-left: 0;
            transition: max-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.18s, margin-left 0.22s;
        }

        .oact:hover {
            width: auto;
            padding: 0 11px;
        }

        .oact:hover .act-label {
            max-width: 90px;
            opacity: 1;
            margin-left: 5px;
        }

        .oact-view {
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .oact-view:hover {
            background: var(--ink-2);
            color: #fff;
            border-color: var(--ink-2);
        }

        .oact-approve {
            color: var(--ok);
            border-color: #a7dcbc;
        }

        .oact-approve:hover {
            background: var(--ok);
            color: #fff;
            border-color: var(--ok);
        }

        .oact-reject {
            color: var(--danger);
            border-color: #f0c4c0;
        }

        .oact-reject:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
        }

        .oact-update {
            color: var(--brand);
            border-color: var(--brand);
        }

        .oact-update:hover {
            background: var(--brand);
            color: #fff;
        }

        .oact-cancel {
            color: var(--danger);
            border-color: #f0c4c0;
        }

        .oact-cancel:hover {
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

        .modal-body-pad {
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

        /* Order summary box inside modals */
        .order-summary-box {
            display: flex;
            align-items: center;
            gap: var(--s3);
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--r);
            padding: var(--s4);
            margin-bottom: var(--s4);
        }

        .order-summary-id {
            font-weight: var(--fw-bold);
            color: var(--ink);
            font-size: 0.95rem;
        }

        .order-summary-sub {
            font-size: var(--fs-sm);
            color: var(--ink-2);
        }

        /* Fee section */
        .fee-section {
            background: var(--brand-tint);
            border: 1px solid var(--brand-tint-2);
            border-radius: var(--r);
            padding: var(--s4);
            margin-bottom: var(--s4);
        }

        .fee-section-title {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--brand-strong);
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: var(--s3);
        }

        .fee-preview {
            font-size: var(--fs-sm);
            color: var(--ink);
            margin-top: var(--s2);
            font-weight: var(--fw-semi);
        }

        /* Status steps tracker */
        .status-steps {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: var(--s4);
        }

        .status-step {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            position: relative;
        }

        .status-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 13px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: var(--line);
            z-index: 0;
        }

        .status-step.done:not(:last-child)::after {
            background: var(--brand);
        }

        .step-dot {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface-2);
            border: 2px solid var(--line);
            font-size: 0.7rem;
            font-weight: var(--fw-bold);
            color: var(--ink-3);
        }

        .status-step.done .step-dot {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }

        .status-step.current .step-dot {
            border-color: var(--brand);
            color: var(--brand);
            background: var(--brand-tint);
        }

        .step-label {
            font-size: 0.66rem;
            color: var(--ink-3);
            text-align: center;
            font-weight: var(--fw-med);
        }

        .status-step.done .step-label,
        .status-step.current .step-label {
            color: var(--ink);
            font-weight: var(--fw-semi);
        }

        /* Info notes */
        .info-note {
            display: flex;
            align-items: center;
            gap: var(--s2);
            background: var(--warn-tint);
            border: 1px solid #f2ddb0;
            border-radius: var(--r-sm);
            padding: 10px var(--s3);
            font-size: var(--fs-sm);
            color: #8a5a0c;
            margin-bottom: var(--s4);
        }

        .info-note svg {
            flex-shrink: 0;
            stroke: var(--warn);
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--s3);
            margin-bottom: var(--s4);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
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

        .form-hint {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        /* Buttons inside modals (align to shared .btn look) */
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

        .proof-img-wrap {
            text-align: center;
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

            <!-- Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Orders</h1>
                    <div class="page-title-sub">Approve or reject orders, set delivery fees, and confirm payments</div>
                </div>
            </div>

            <?= flash() ?>

            <!-- Stat cards -->
            <div class="grid cols-2 mb-6" style="grid-template-columns:repeat(4,1fr);">
                <div class="stat-card tone-brand">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Total Orders</span>
                        <div class="stat-icon"><i class="fa-solid fa-cart-shopping"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($totalOrders) ?></div>
                    <div class="stat-foot">All time</div>
                </div>
                <div class="stat-card tone-blue">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Orders Today</span>
                        <div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($todayCount) ?></div>
                    <div class="stat-foot"><?= date('F j') ?></div>
                </div>
                <div class="stat-card tone-amber">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Pending Review</span>
                        <div class="stat-icon"><i class="fa-solid fa-clock"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php if ($pendingCount > 0): ?><span class="pulse"><span class="pulse-dot amber"></span><?= number_format($pendingCount) ?></span><?php else: ?><?= number_format($pendingCount) ?><?php endif; ?>
                    </div>
                    <div class="stat-foot">Awaiting approval</div>
                </div>
                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Revenue Collected</span>
                        <div class="stat-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                    </div>
                    <div class="stat-value money">₱<?= number_format($totalRevenue, 0) ?></div>
                    <div class="stat-foot">Paid orders</div>
                </div>
            </div>

            <?php if ($pendingCount > 0): ?>
                <div class="alert-banner alert-warn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" y1="8" x2="12" y2="12" />
                        <line x1="12" y1="16" x2="12.01" y2="16" />
                    </svg>
                    <span><strong><?= $pendingCount ?> order<?= $pendingCount !== 1 ? 's' : '' ?></strong> waiting for your approval.</span>
                </div>
            <?php endif; ?>
            <?php if ($proofPendingCount > 0): ?>
                <div class="alert-banner alert-blue">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="17 8 12 3 7 8" />
                        <line x1="12" y1="3" x2="12" y2="15" />
                    </svg>
                    <span><strong><?= $proofPendingCount ?> GCash order<?= $proofPendingCount !== 1 ? 's' : '' ?></strong> approved but awaiting customer payment proof upload.</span>
                </div>
            <?php endif; ?>

            <!-- Status tabs -->
            <div class="filter-tabs">
                <?php
                $tabDefs = ['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'processing' => 'Processing', 'out_for_delivery' => 'Out for Delivery', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled/Rejected'];
                foreach ($tabDefs as $val => $label):
                    $qs = http_build_query(array_merge($_GET, ['status' => $val, 'page' => 1]));
                    $cnt = ($val === '') ? $totalOrders : ($statusCounts[$val] ?? 0);
                    $active = ($filterStatus === $val) ? 'active' : '';
                ?><a href="?<?= $qs ?>" class="filter-tab <?= $active ?>"><?= $label ?> <span class="tab-count"><?= $cnt ?></span></a><?php endforeach; ?>
            </div>

            <!-- Toolbar -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <form method="GET" style="display:flex;gap:var(--s3);align-items:center;flex-wrap:wrap;">
                        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>"><?php endif; ?>
                        <div class="search-wrap">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                            <input type="text" name="q" class="search-input" placeholder="Search customer, order #…" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <select name="payment" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Payments</option>
                            <option value="unpaid" <?= $filterPayment === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                            <option value="paid" <?= $filterPayment === 'paid' ? 'selected' : '' ?>>Paid</option>
                        </select>
                        <select name="method" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Methods</option>
                            <option value="cash" <?= $filterMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="gcash" <?= $filterMethod === 'gcash' ? 'selected' : '' ?>>GCash</option>
                            <option value="cod" <?= $filterMethod === 'cod' ? 'selected' : '' ?>>COD</option>
                        </select>
                        <?php if ($search || $filterPayment || $filterMethod): ?><a href="orders.php?status=<?= urlencode($filterStatus) ?>" class="clear-link">✕ Clear</a><?php endif; ?>
                    </form>
                </div>
                <div class="toolbar-right"><?= number_format($totalCount) ?> order<?= $totalCount !== 1 ? 's' : '' ?></div>
            </div>

            <!-- Orders table -->
            <div class="table-card">
                <?php if ($orders && $orders->num_rows > 0): ?>
                    <div class="table-scroll">
                        <table class="data">
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
                                    <th style="text-align:center;min-width:190px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNum = $offset + 1;
                                while ($o = $orders->fetch_assoc()):
                                    $statusClass = 's-' . $o['status'];
                                    $statusLabel = ucwords(str_replace('_', ' ', $o['status']));
                                    $initial = strtoupper(substr($o['full_name'], 0, 1));
                                    $isPending = ($o['status'] === 'pending');
                                    $isCancelled = ($o['status'] === 'cancelled');
                                    $isDelivered = ($o['status'] === 'delivered');
                                    $isFinalised = ($isCancelled || $isDelivered);
                                    $methodIcon = $o['payment_method'] === 'gcash' ? '<i class="fa-solid fa-mobile-screen"></i>' : '<i class="fa-solid fa-money-bill"></i>';
                                    $feeIsSet = $o['delivery_fee'] !== null;
                                    $itemsSubtotal = (float)$o['items_subtotal'];
                                    $hasProof = !empty($o['gcash_proof']);
                                ?>
                                    <tr>
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);font-weight:var(--fw-semi);"><?= $rowNum++ ?></td>
                                        <td>
                                            <div class="order-id">#<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?></div>
                                            <div class="order-date"><?= date('M j, Y · g:i A', strtotime($o['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="cell-lead">
                                                <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                                                <div>
                                                    <div class="cust-name"><?= htmlspecialchars($o['full_name']) ?></div>
                                                    <div class="cust-sub"><?= $o['phone'] ? htmlspecialchars($o['phone']) : htmlspecialchars($o['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-weight:var(--fw-semi);color:var(--ink);"><?= (int)$o['item_count'] ?> <span style="font-weight:400;color:var(--ink-3);font-size:var(--fs-xs);">item<?= (int)$o['item_count'] !== 1 ? 's' : '' ?></span></td>
                                        <td>
                                            <div class="total-main">₱<?= number_format((float)$o['total_amount'], 2) ?></div><?php if (!$isCancelled): ?><?php if ($feeIsSet): ?><div class="total-fee-set"><?= $o['delivery_fee'] > 0 ? 'incl. ₱' . number_format((float)$o['delivery_fee'], 2) . ' delivery' : 'free delivery' ?></div><?php else: ?><div class="total-fee-unset">Fee not set <i class="fa-solid fa-triangle-exclamation"></i></div><?php endif; ?><?php endif; ?>
                                        </td>
                                        <td><span class="method-badge"><?= $methodIcon ?> <?= strtoupper($o['payment_method']) ?></span></td>
                                        <td><span class="st-pill <?= $o['payment_status'] === 'paid' ? 'pay-paid' : 'pay-unpaid' ?>"><?= $o['payment_status'] === 'paid' ? 'Paid' : 'Unpaid' ?></span></td>
                                        <td><?php if ($o['payment_method'] === 'gcash'): ?><?php if ($hasProof): ?><span class="proof-badge proof-yes" onclick="viewProof('<?= htmlspecialchars(addslashes($o['gcash_proof'])) ?>','<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?>')"><i class="fa-solid fa-paperclip"></i> View</span><?php else: ?><span class="proof-badge proof-no"><i class="fa-solid fa-clock"></i> None</span><?php endif; ?><?php else: ?><span style="font-size:var(--fs-xs);color:var(--ink-3);">N/A</span><?php endif; ?></td>
                                        <td><span class="st-pill <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                        <td style="text-align:center;">
                                            <div class="row-actions">
                                                <button class="oact oact-view" onclick="openView(<?= $o['id'] ?>)"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                        <circle cx="12" cy="12" r="3" />
                                                    </svg><span class="act-label">View</span></button>
                                                <?php if ($isPending): ?>
                                                    <button class="oact oact-approve" onclick="openApprove(<?= htmlspecialchars(json_encode(['id' => $o['id'], 'full_name' => $o['full_name'], 'total_amount' => $o['total_amount'], 'items_subtotal' => $itemsSubtotal, 'delivery_fee' => $o['delivery_fee'], 'delivery_address' => $o['delivery_address'], 'payment_method' => $o['payment_method']]), ENT_QUOTES) ?>)"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="20 6 9 17 4 12" />
                                                        </svg><span class="act-label">Approve</span></button>
                                                    <button class="oact oact-reject" onclick="openReject(<?= $o['id'] ?>,'<?= htmlspecialchars(addslashes($o['full_name'])) ?>')"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                                            <line x1="18" y1="6" x2="6" y2="18" />
                                                            <line x1="6" y1="6" x2="18" y2="18" />
                                                        </svg><span class="act-label">Reject</span></button>
                                                <?php elseif (!$isFinalised): ?>
                                                    <button class="oact oact-update" onclick="openUpdate(<?= htmlspecialchars(json_encode(['id' => $o['id'], 'full_name' => $o['full_name'], 'status' => $o['status'], 'payment_status' => $o['payment_status'], 'payment_method' => $o['payment_method'], 'total_amount' => $o['total_amount'], 'delivery_fee' => $o['delivery_fee'], 'items_subtotal' => $itemsSubtotal, 'delivery_address' => $o['delivery_address']]), ENT_QUOTES) ?>)"><i class="fa-solid fa-pen-to-square"></i><span class="act-label">Update</span></button>
                                                    <button class="oact oact-cancel" onclick="openCancel(<?= $o['id'] ?>,'<?= htmlspecialchars(addslashes($o['full_name'])) ?>')" title="Cancel order"><i class="fa-solid fa-xmark"></i></button>
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
                            <div>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?> orders</div>
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
                        <div class="empty-icon"><i class="fa-solid fa-cart-shopping"></i></div>
                        <div class="empty-title">No orders found</div>
                        <div class="empty-text"><?= ($search || $filterStatus || $filterPayment || $filterMethod) ? 'Try adjusting your filters.' : 'New orders will appear here.'; ?></div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- VIEW MODAL -->
    <div class="modal-backdrop" id="viewModal" onclick="backdropClose(event,'viewModal')">
        <div class="modal-card lg">
            <div class="modal-header">
                <div class="modal-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                        <circle cx="12" cy="12" r="3" />
                    </svg><span id="view_modal_title">Order Details</span></div><button class="modal-close" onclick="closeModal('viewModal')">✕</button>
            </div>
            <div class="modal-body">
                <div id="view_content">Loading…</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('viewModal')">Close</button></div>
        </div>
    </div>

    <!-- APPROVE MODAL -->
    <div class="modal-backdrop" id="approveModal" onclick="backdropClose(event,'approveModal')">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-title" style="color:#10b981;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12" />
                    </svg> Approve Order</div><button class="modal-close" onclick="closeModal('approveModal')">✕</button>
            </div>
            <form method="POST" action="orders.php"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" id="approve_id">
                <div class="modal-body modal-body-pad">
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
                    <div class="fee-section">
                        <div class="fee-section-title"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 3h15v13H1z" />
                                <path d="M16 8h4l3 3v5h-7V8z" />
                                <circle cx="5.5" cy="18.5" r="2.5" />
                                <circle cx="18.5" cy="18.5" r="2.5" />
                            </svg> Set Delivery Fee</div>
                        <div class="form-group"><label class="form-label">Delivery Fee (₱)</label><input type="number" name="delivery_fee" id="approve_delivery_fee" class="form-input" step="0.01" min="0" max="9999.99" placeholder="e.g. 80.00 (leave blank for pickup / free)" oninput="updateApproveFeePreview()"><span class="form-hint">Leave blank if pickup or free delivery.</span>
                            <div class="fee-preview" id="approve_fee_preview"></div>
                        </div>
                    </div>
                    <div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:12px 14px;font-size:0.85rem;color:#065f46;line-height:1.6;"><i class="fa-solid fa-circle-check"></i> <strong>Approving</strong> will deduct stock and notify the customer.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('approveModal')">Cancel</button><button type="submit" class="btn btn-success"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg> Approve Order</button></div>
            </form>
        </div>
    </div>

    <!-- REJECT MODAL -->
    <div class="modal-backdrop" id="rejectModal" onclick="backdropClose(event,'rejectModal')">
        <div class="modal-card sm">
            <div class="modal-header">
                <div class="modal-title" style="color:#ef4444;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg> Reject Order</div><button class="modal-close" onclick="closeModal('rejectModal')">✕</button>
            </div>
            <form method="POST" action="orders.php"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" id="reject_id">
                <div class="modal-body modal-body-pad">
                    <div style="text-align:center;margin-bottom:18px;">
                        <div style="font-size:3rem;margin-bottom:12px;">✕</div>
                        <div style="font-size:1rem;font-weight:700;color:var(--dark);margin-bottom:4px;">Reject Order <span id="reject_order_label">#0000</span>?</div>
                        <div style="font-size:0.85rem;color:var(--text-muted);">from <strong id="reject_customer_label"></strong></div>
                    </div>
                    <div class="form-group"><label class="form-label">Reason for Rejection</label><textarea name="reject_reason" class="form-textarea" placeholder="e.g. Out of stock, outside delivery area…" rows="3"></textarea><span class="form-hint">Saved in order notes.</span></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Keep Order</button><button type="submit" class="btn btn-danger">✕ Reject Order</button></div>
            </form>
        </div>
    </div>

    <!-- UPDATE MODAL -->
    <div class="modal-backdrop" id="updateModal" onclick="backdropClose(event,'updateModal')">
        <div class="modal-card">
            <div class="modal-header">
                <div class="modal-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12" />
                    </svg> Update Order</div><button class="modal-close" onclick="closeModal('updateModal')">✕</button>
            </div>
            <form method="POST" action="orders.php"><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" id="upd_id">
                <div class="modal-body modal-body-pad">
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
                    <div class="info-note" id="gcash_note" style="display:none;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg><span>GCash order — verify the customer's payment proof before marking as Paid.</span></div>
                    <div class="form-grid">
                        <div class="form-group"><label class="form-label">Order Status <span class="req">*</span></label><select name="status" id="upd_status" class="form-select" required onchange="updateStatusTracker(this.value)">
                                <option value="approved">Approved</option>
                                <option value="processing">Processing</option>
                                <option value="out_for_delivery">Out for Delivery</option>
                                <option value="delivered">Delivered</option>
                                <option value="cancelled">Cancelled</option>
                            </select></div>
                        <div class="form-group"><label class="form-label">Payment Status <span class="req">*</span></label><select name="payment_status" id="upd_payment_status" class="form-select" required>
                                <option value="unpaid">Unpaid</option>
                                <option value="paid">🟢 Paid</option>
                            </select></div>
                    </div>
                    <div class="fee-section">
                        <div class="fee-section-title"><i class="fa-solid fa-truck"></i> Delivery Fee <span id="upd_fee_current_badge"></span></div>
                        <div class="form-group"><label class="form-label">Update Delivery Fee (₱)</label><input type="number" name="delivery_fee" id="upd_delivery_fee" class="form-input" step="0.01" min="0" max="9999.99" placeholder="Leave blank to keep existing" oninput="updateFeePreview()"><span class="form-hint">Leave blank to keep unchanged.</span>
                            <div class="fee-preview" id="fee_preview"></div>
                        </div>
                    </div>
                    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:0.82rem;color:#92400e;line-height:1.5;"><i class="fa-solid fa-lightbulb"></i> Marking as <strong>Paid</strong> automatically creates a transaction record if none exists yet.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('updateModal')">Cancel</button><button type="submit" class="btn btn-primary"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg> Save Changes</button></div>
            </form>
        </div>
    </div>

    <!-- CANCEL MODAL -->
    <div class="modal-backdrop" id="cancelModal" onclick="backdropClose(event,'cancelModal')">
        <div class="modal-card sm">
            <div class="modal-header">
                <div class="modal-title" style="color:#ef4444;"><i class="fa-solid fa-triangle-exclamation"></i> Cancel Order</div><button class="modal-close" onclick="closeModal('cancelModal')">✕</button>
            </div>
            <form method="POST" action="orders.php"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" id="cancel_id">
                <div class="modal-body modal-body-pad" style="text-align:center;padding:28px 24px;">
                    <div style="font-size:3rem;margin-bottom:14px;"><i class="fa-solid fa-ban"></i></div>
                    <div style="font-size:1rem;font-weight:700;color:var(--dark);margin-bottom:8px;">Cancel this order?</div>
                    <div style="font-size:0.88rem;color:var(--text-muted);line-height:1.6;">Order <strong id="cancel_order_label">#0000</strong> from <strong id="cancel_customer_label"></strong> will be cancelled.<br><br><span style="color:#10b981;font-weight:600;"><i class="fa-solid fa-check"></i> Stock restored only if previously approved</span></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('cancelModal')">Keep Order</button><button type="submit" class="btn btn-danger">Yes, Cancel</button></div>
            </form>
        </div>
    </div>

    <!-- PROOF MODAL -->
    <div class="modal-backdrop" id="proofModal" onclick="backdropClose(event,'proofModal')">
        <div class="modal-card sm">
            <div class="modal-header">
                <div class="modal-title"><i class="fa-solid fa-paperclip"></i> GCash Payment Proof</div><button class="modal-close" onclick="closeModal('proofModal')">✕</button>
            </div>
            <div class="modal-body modal-body-pad">
                <div style="font-size:0.82rem;color:var(--text-muted);margin-bottom:10px;" id="proof_order_label"></div>
                <div class="proof-img-wrap"><img id="proof_img_el" src="" alt="GCash Payment Proof" style="max-width:100%;border-radius:10px;border:1px solid var(--card-border);"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" onclick="closeModal('proofModal')">Close</button></div>
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
                ['viewModal', 'approveModal', 'rejectModal', 'updateModal', 'cancelModal', 'proofModal'].forEach(id => document.getElementById(id).classList.remove('open'));
                document.body.style.overflow = '';
            }
        });
        const STATUS_FLOW = ['approved', 'processing', 'out_for_delivery', 'delivered'];
        const STATUS_LABELS = {
            approved: 'Approved',
            processing: 'Processing',
            out_for_delivery: 'Out for\nDelivery',
            delivered: 'Delivered'
        };

        function updateStatusTracker(cur) {
            var c = document.getElementById('status_steps_track');
            c.innerHTML = '';
            if (cur === 'cancelled') {
                c.innerHTML = '<div style="display:flex;align-items:center;gap:10px;"><div style="width:28px;height:28px;border-radius:50%;background:#ef4444;color:#fff;display:flex;align-items:center;justify-content:center;">✕</div><div style="font-size:0.85rem;font-weight:700;color:#ef4444;">Order Cancelled</div></div>';
                return;
            }
            var curIdx = STATUS_FLOW.indexOf(cur);
            STATUS_FLOW.forEach(function(s, i) {
                if (i > 0) {
                    var conn = document.createElement('div');
                    conn.className = 'status-step-connector' + (i <= curIdx ? ' done' : '');
                    c.appendChild(conn);
                }
                var step = document.createElement('div');
                step.className = 'status-step';
                var dot = document.createElement('div');
                var lbl = document.createElement('div');
                if (i < curIdx) {
                    dot.className = 'status-step-dot done';
                    dot.textContent = '✓';
                    lbl.className = 'status-step-label done';
                } else if (i === curIdx) {
                    dot.className = 'status-step-dot current';
                    dot.textContent = i + 1;
                    lbl.className = 'status-step-label current';
                } else {
                    dot.className = 'status-step-dot';
                    dot.textContent = i + 1;
                    lbl.className = 'status-step-label';
                }
                lbl.style.whiteSpace = 'pre-line';
                lbl.textContent = STATUS_LABELS[s];
                step.appendChild(dot);
                step.appendChild(lbl);
                c.appendChild(step);
            });
        }

        function fmtPeso(n) {
            return '₱' + parseFloat(n).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        var _approveSubtotal = 0;

        function openApprove(data) {
            _approveSubtotal = parseFloat(data.items_subtotal) || 0;
            document.getElementById('approve_id').value = data.id;
            document.getElementById('approve_order_label').textContent = '#' + String(data.id).padStart(4, '0');
            document.getElementById('approve_customer_label').textContent = data.full_name;
            document.getElementById('approve_method_label').textContent = data.payment_method.toUpperCase();
            document.getElementById('approve_total_label').textContent = fmtPeso(data.total_amount);
            var addr = data.delivery_address || '';
            document.getElementById('approve_address_label').textContent = addr ? (addr.length > 60 ? addr.substring(0, 60) + '…' : addr) : '';
            document.getElementById('approve_delivery_fee').value = '';
            document.getElementById('approve_fee_preview').classList.remove('show');
            openModal('approveModal');
        }

        function updateApproveFeePreview() {
            var input = document.getElementById('approve_delivery_fee');
            var preview = document.getElementById('approve_fee_preview');
            var val = input.value.trim();
            if (val !== '' && !isNaN(parseFloat(val)) && parseFloat(val) >= 0) {
                var fee = parseFloat(val);
                var total = _approveSubtotal + fee;
                preview.innerHTML = '✓ Items: <strong>' + fmtPeso(_approveSubtotal) + '</strong> + Fee: <strong>' + fmtPeso(fee) + '</strong> = New Total: <strong>' + fmtPeso(total) + '</strong>';
                preview.classList.add('show');
                document.getElementById('approve_total_label').textContent = fmtPeso(total);
            } else {
                preview.classList.remove('show');
                document.getElementById('approve_total_label').textContent = fmtPeso(_approveSubtotal);
            }
        }

        function openReject(id, name) {
            document.getElementById('reject_id').value = id;
            document.getElementById('reject_order_label').textContent = '#' + String(id).padStart(4, '0');
            document.getElementById('reject_customer_label').textContent = name;
            openModal('rejectModal');
        }
        var _itemsSubtotal = 0;

        function openUpdate(data) {
            _itemsSubtotal = parseFloat(data.items_subtotal) || 0;
            document.getElementById('upd_id').value = data.id;
            document.getElementById('upd_order_label').textContent = '#' + String(data.id).padStart(4, '0');
            document.getElementById('upd_customer_label').textContent = data.full_name;
            document.getElementById('upd_method_label').textContent = data.payment_method.toUpperCase();
            document.getElementById('upd_status').value = data.status;
            document.getElementById('upd_payment_status').value = data.payment_status;
            document.getElementById('gcash_note').style.display = data.payment_method === 'gcash' ? 'flex' : 'none';
            var addr = data.delivery_address || '';
            document.getElementById('upd_address_label').textContent = addr ? (addr.length > 60 ? addr.substring(0, 60) + '…' : addr) : '';
            document.getElementById('upd_total_label').textContent = fmtPeso(data.total_amount);
            document.getElementById('upd_delivery_fee').value = '';
            var badgeEl = document.getElementById('upd_fee_current_badge');
            if (data.delivery_fee !== null && data.delivery_fee !== undefined && data.delivery_fee !== '') {
                badgeEl.className = 'fee-current-badge';
                badgeEl.textContent = fmtPeso(data.delivery_fee) + ' set';
            } else {
                badgeEl.className = 'fee-unset-badge';
                badgeEl.textContent = 'Not set';
            }
            document.getElementById('fee_preview').classList.remove('show');
            updateStatusTracker(data.status);
            openModal('updateModal');
        }

        function updateFeePreview() {
            var input = document.getElementById('upd_delivery_fee');
            var preview = document.getElementById('fee_preview');
            var val = input.value.trim();
            if (val !== '' && !isNaN(parseFloat(val)) && parseFloat(val) >= 0) {
                var fee = parseFloat(val);
                var total = _itemsSubtotal + fee;
                preview.innerHTML = '✓ Items: <strong>' + fmtPeso(_itemsSubtotal) + '</strong> + Fee: <strong>' + fmtPeso(fee) + '</strong> = <strong>' + fmtPeso(total) + '</strong>';
                preview.classList.add('show');
                document.getElementById('upd_total_label').textContent = fmtPeso(total);
            } else {
                preview.classList.remove('show');
                document.getElementById('upd_total_label').textContent = fmtPeso(_itemsSubtotal);
            }
        }

        function openCancel(id, name) {
            document.getElementById('cancel_id').value = id;
            document.getElementById('cancel_order_label').textContent = '#' + String(id).padStart(4, '0');
            document.getElementById('cancel_customer_label').textContent = name;
            openModal('cancelModal');
        }

        function openView(orderId) {
            document.getElementById('view_modal_title').textContent = 'Order #' + String(orderId).padStart(4, '0');
            document.getElementById('view_content').innerHTML = '<div style="text-align:center;padding:48px 20px;color:#9ca3af;"><div style="width:32px;height:32px;border:3px solid #f3f4f6;border-top-color:#e67e22;border-radius:50%;animation:viewSpin 0.7s linear infinite;margin:0 auto 12px;"></div>Loading…</div>';
            openModal('viewModal');
            fetch('order_detail_ajax.php?id=' + orderId).then(r => r.text()).then(html => {
                document.getElementById('view_content').innerHTML = html;
            }).catch(() => {
                document.getElementById('view_content').innerHTML = '<div style="padding:32px;text-align:center;color:#ef4444;">Failed to load order details.</div>';
            });
        }

        function viewProof(path, orderNum) {
            document.getElementById('proof_order_label').textContent = 'Order #' + orderNum + ' — GCash Payment Screenshot';
            document.getElementById('proof_img_el').src = '../' + path + '?v=' + Date.now();
            openModal('proofModal');
        }
    </script>
</body>

</html>