<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/orders.php
// - Shows order list + tracking timeline
// - GCash proof upload shown after admin approves order
// - Detail modal with proof image
// ============================================================

session_start();
require_once '../config/db.php';
requireCustomer();

$activePage = 'orders';
$uid        = (int)$_SESSION['user_id'];
$cartItems  = cartCount($conn);

// ── Handle GCash proof upload ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_proof') {
    $orderId = (int)($_POST['order_id'] ?? 0);

    // Verify order belongs to this user and is approved + gcash + unpaid
    $chk = $conn->prepare("SELECT id, payment_method, payment_status, status FROM orders
                            WHERE id=? AND user_id=? LIMIT 1");
    $chk->bind_param('ii', $orderId, $uid);
    $chk->execute();
    $chkRow = $chk->get_result()->fetch_assoc();
    $chk->close();

    $uploadError = '';
    if (!$chkRow) {
        $uploadError = 'Order not found.';
    } elseif ($chkRow['status'] !== 'approved') {
        $uploadError = 'You can only upload proof for approved orders.';
    } elseif ($chkRow['payment_method'] !== 'gcash') {
        $uploadError = 'This order does not use GCash payment.';
    } elseif ($chkRow['payment_status'] === 'paid') {
        $uploadError = 'This order is already marked as paid.';
    } elseif (!isset($_FILES['gcash_proof']) || $_FILES['gcash_proof']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'No file uploaded or upload error. Please try again.';
    } else {
        $file    = $_FILES['gcash_proof'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];

        if (!in_array($ext, $allowed)) {
            $uploadError = 'Invalid file type. Use JPG, PNG, GIF or WEBP.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $uploadError = 'File too large. Max 5MB.';
        } else {
            $uploadDir = '../uploads/gcash_proofs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // Delete old proof if exists
            $old = $conn->query("SELECT gcash_proof FROM orders WHERE id={$orderId} LIMIT 1");
            if ($old && $oldRow = $old->fetch_assoc()) {
                if (!empty($oldRow['gcash_proof']) && file_exists('../' . $oldRow['gcash_proof'])) {
                    unlink('../' . $oldRow['gcash_proof']);
                }
            }

            $filename   = 'proof_' . $orderId . '_' . time() . '.' . $ext;
            $destPath   = $uploadDir . $filename;
            $publicPath = 'uploads/gcash_proofs/' . $filename;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $safePath = $conn->real_escape_string($publicPath);
                $conn->query("UPDATE orders SET gcash_proof='{$safePath}', updated_at=NOW() WHERE id={$orderId}");
                redirect('orders.php', 'success', '✓ Payment proof uploaded! The admin will verify it shortly.');
            } else {
                $uploadError = 'Failed to save file. Please try again.';
            }
        }
    }

    if ($uploadError) {
        redirect('orders.php', 'error', $uploadError);
    }
}

// ── Filter ────────────────────────────────────────────────────
$filterStatus = trim($_GET['status'] ?? '');
$validStatuses = ['pending','approved','processing','out_for_delivery','delivered','cancelled'];
if ($filterStatus && !in_array($filterStatus, $validStatuses)) $filterStatus = '';

$whereStatus = $filterStatus ? "AND o.status='{$filterStatus}'" : '';

$orders = $conn->query("
    SELECT o.id, o.status, o.total_amount, o.delivery_fee, o.payment_method,
           o.payment_status, o.delivery_address, o.notes, o.gcash_proof,
           o.created_at, o.updated_at,
           COUNT(oi.id) AS item_count,
           SUM(oi.quantity) AS total_units
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id=o.id
    WHERE o.user_id={$uid} {$whereStatus}
    GROUP BY o.id
    ORDER BY o.created_at DESC
");

$orderList = [];
while ($row = $orders->fetch_assoc()) $orderList[] = $row;

$statusCounts = [];
$cr = $conn->query("SELECT status, COUNT(*) AS cnt FROM orders WHERE user_id={$uid} GROUP BY status");
while ($row = $cr->fetch_assoc()) $statusCounts[$row['status']] = (int)$row['cnt'];
$totalOrders = array_sum($statusCounts);

// ── AJAX: order detail ─────────────────────────────────────────
if (isset($_GET['get_order']) && is_numeric($_GET['get_order'])) {
    $oid = (int)$_GET['get_order'];
    header('Content-Type: application/json');

    $oStmt = $conn->prepare("SELECT o.*, u.full_name, u.email, u.phone
                              FROM orders o JOIN users u ON u.id=o.user_id
                              WHERE o.id=? AND o.user_id=? LIMIT 1");
    $oStmt->bind_param('ii', $oid, $uid);
    $oStmt->execute();
    $order = $oStmt->get_result()->fetch_assoc();
    $oStmt->close();

    if (!$order) { echo json_encode(['success'=>false,'message'=>'Order not found.']); exit; }

    $iStmt = $conn->prepare("SELECT oi.quantity, oi.unit_price, oi.subtotal, p.name, p.unit, c.name AS category
                              FROM order_items oi
                              JOIN products p ON p.id=oi.product_id
                              JOIN categories c ON c.id=p.category_id
                              WHERE oi.order_id=? ORDER BY oi.id ASC");
    $iStmt->bind_param('i', $oid);
    $iStmt->execute();
    $items = $iStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $iStmt->close();

    $tStmt = $conn->prepare("SELECT reference_no, payment_method, transaction_date, amount
                              FROM transactions WHERE order_id=? LIMIT 1");
    $tStmt->bind_param('i', $oid);
    $tStmt->execute();
    $txn = $tStmt->get_result()->fetch_assoc();
    $tStmt->close();

    echo json_encode(['success'=>true,'order'=>$order,'items'=>$items,'txn'=>$txn]);
    exit;
}
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
<title>My Orders — Hiney's Eggs &amp; Live Chicken</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root {
    --primary:#e67e22; --primary-dark:#cf6d17; --primary-light:#fef3e8;
    --secondary:#f39c12; --dark:#1a1a2e; --dark2:#2c3e50;
    --text:#374151; --text-muted:#6b7280; --bg:#faf9f7;
    --card-bg:#ffffff; --border:#e5e7eb; --danger:#ef4444;
    --success:#10b981; --radius:14px;
    --shadow:0 2px 8px rgba(0,0,0,0.06),0 8px 24px rgba(0,0,0,0.05);
    --shadow-lg:0 8px 24px rgba(0,0,0,0.10),0 24px 48px rgba(0,0,0,0.08);
    --navbar-h:68px; --transition:0.2s ease;
}
html{scroll-behavior:smooth;}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);line-height:1.6;}
a{text-decoration:none;color:inherit;}


.page-banner{background:linear-gradient(135deg,#2c3e50 0%,#1a252f 100%);padding:36px 0 40px;position:relative;overflow:hidden;}
.page-banner::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse 500px 300px at 80% 50%,rgba(230,126,34,0.14),transparent 70%);}
.page-banner-inner{max-width:1200px;margin:0 auto;padding:0 32px;position:relative;z-index:1;}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:0.78rem;color:#6b7a99;margin-bottom:12px;}
.breadcrumb a{color:#8fa3b3;} .breadcrumb a:hover{color:var(--secondary);} .breadcrumb-sep{opacity:0.4;}
.page-banner-title{font-size:clamp(1.5rem,3vw,2rem);font-weight:800;color:#fff;letter-spacing:-0.025em;margin-bottom:4px;}
.page-banner-sub{font-size:0.9rem;color:#8fa3b3;}

.container{max-width:1200px;margin:0 auto;padding:0 32px;}
@media(max-width:600px){.container{padding:0 16px;}}
.page-content{padding:32px 0 60px;}

/* ── Status Tabs ── */
.status-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:24px;padding:4px;background:var(--card-bg);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);}
.status-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;font-size:0.82rem;font-weight:600;cursor:pointer;color:var(--text-muted);transition:all var(--transition);text-decoration:none;white-space:nowrap;}
.status-tab:hover{background:#f3f4f6;color:var(--dark2);}
.status-tab.active{background:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(230,126,34,0.3);}
.tab-count{background:rgba(255,255,255,0.25);font-size:0.68rem;font-weight:700;padding:1px 6px;border-radius:10px;}
.status-tab:not(.active) .tab-count{background:#e5e7eb;color:var(--text-muted);}

/* ── Order Cards ── */
.orders-list{display:flex;flex-direction:column;gap:16px;}
.order-card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;transition:box-shadow var(--transition),transform var(--transition);}
.order-card:hover{box-shadow:var(--shadow-lg);transform:translateY(-1px);}
.order-card-accent{height:3px;width:100%;}
.accent-pending          {background:#f59e0b;}
.accent-approved         {background:#3b82f6;}
.accent-processing       {background:#8b5cf6;}
.accent-out_for_delivery {background:#f97316;}
.accent-delivered        {background:#10b981;}
.accent-cancelled        {background:#ef4444;}

.order-card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap;gap:10px;}
.order-id{font-size:1rem;font-weight:800;color:var(--dark2);letter-spacing:-0.01em;}
.order-date{font-size:0.78rem;color:var(--text-muted);}

.status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;white-space:nowrap;}
.sb-pending          {background:#fef3c7;color:#92400e;}
.sb-approved         {background:#dbeafe;color:#1e40af;}
.sb-processing       {background:#ede9fe;color:#5b21b6;}
.sb-out_for_delivery {background:#ffedd5;color:#9a3412;}
.sb-delivered        {background:#d1fae5;color:#065f46;}
.sb-cancelled        {background:#fee2e2;color:#991b1b;}
.status-dot{width:7px;height:7px;border-radius:50%;}
.sd-pending          {background:#f59e0b;animation:pulse 1.5s infinite;}
.sd-approved         {background:#3b82f6;animation:pulse2 1.5s infinite;}
.sd-processing       {background:#8b5cf6;animation:pulse3 1.5s infinite;}
.sd-out_for_delivery {background:#f97316;animation:pulse 1.5s infinite;}
.sd-delivered        {background:#10b981;}
.sd-cancelled        {background:#ef4444;}
@keyframes pulse  {0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,0.4);}50%{box-shadow:0 0 0 4px rgba(245,158,11,0);}}
@keyframes pulse2 {0%,100%{box-shadow:0 0 0 0 rgba(59,130,246,0.4);}50%{box-shadow:0 0 0 4px rgba(59,130,246,0);}}
@keyframes pulse3 {0%,100%{box-shadow:0 0 0 0 rgba(139,92,246,0.4);}50%{box-shadow:0 0 0 4px rgba(139,92,246,0);}}

.order-card-body{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;}
.order-meta{display:flex;gap:28px;flex-wrap:wrap;}
.order-meta-item{display:flex;flex-direction:column;gap:2px;}
.order-meta-label{font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);}
.order-meta-value{font-size:0.9rem;font-weight:700;color:var(--dark2);}
.order-meta-value.amount{color:var(--primary);font-size:1rem;}
.pay-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;font-size:0.7rem;font-weight:700;}
.pay-paid  {background:#d1fae5;color:#065f46;}
.pay-unpaid{background:#fee2e2;color:#991b1b;}

/* Tracking strip */
.tracking-strip{padding:14px 20px 12px;border-top:1px solid #f3f4f6;}
.tracking-timeline{display:flex;align-items:center;}
.track-step{display:flex;flex-direction:column;align-items:center;flex:1;position:relative;}
.track-step::after{content:'';position:absolute;top:12px;left:50%;width:100%;height:2px;background:#e5e7eb;z-index:0;}
.track-step:last-child::after{display:none;}
.track-step.done::after{background:var(--success);}
.track-dot{width:24px;height:24px;border-radius:50%;border:2px solid #e5e7eb;background:#fff;display:flex;align-items:center;justify-content:center;font-size:0.65rem;position:relative;z-index:1;transition:all var(--transition);flex-shrink:0;}
.track-step.done   .track-dot{background:var(--success);border-color:var(--success);color:#fff;}
.track-step.active .track-dot{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 0 0 4px rgba(230,126,34,0.2);}
.track-label{font-size:0.6rem;font-weight:600;color:var(--text-muted);margin-top:5px;text-align:center;line-height:1.3;white-space:nowrap;}
.track-step.done  .track-label{color:var(--success);}
.track-step.active .track-label{color:var(--primary);font-weight:700;}

/* ── GCash Proof Upload Card ── */
.proof-upload-card{border-top:2px solid #3b82f6;background:linear-gradient(to right,#f0f7ff,#fff);}
.proof-upload-section{padding:16px 20px;}
.proof-upload-title{font-size:0.88rem;font-weight:800;color:#1e40af;display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.proof-upload-desc{font-size:0.8rem;color:#3b82f6;margin-bottom:14px;line-height:1.5;}
.proof-upload-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.proof-file-input-wrap{position:relative;overflow:hidden;flex:1;min-width:200px;}
.proof-file-input-wrap input[type="file"]{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;}
.proof-file-label{display:flex;align-items:center;gap:8px;padding:9px 14px;border:2px dashed #93c5fd;border-radius:9px;background:#eff6ff;font-size:0.84rem;font-weight:600;color:#1e40af;cursor:pointer;transition:all var(--transition);}
.proof-file-label:hover{border-color:#3b82f6;background:#dbeafe;}
.proof-file-label.has-file{border-color:#10b981;background:#ecfdf5;color:#065f46;}
.btn-upload-proof{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#3b82f6;color:#fff;border:none;border-radius:9px;font-size:0.85rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all var(--transition);white-space:nowrap;}
.btn-upload-proof:hover{background:#2563eb;}

/* Already uploaded proof */
.proof-uploaded-section{padding:14px 20px;border-top:2px solid #10b981;background:linear-gradient(to right,#f0fdf4,#fff);display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.proof-thumb{width:52px;height:52px;border-radius:8px;object-fit:cover;border:2px solid #6ee7b7;cursor:pointer;transition:transform var(--transition);}
.proof-thumb:hover{transform:scale(1.05);}
.proof-uploaded-info{flex:1;}
.proof-uploaded-title{font-size:0.85rem;font-weight:800;color:#065f46;display:flex;align-items:center;gap:6px;margin-bottom:3px;}
.proof-uploaded-desc{font-size:0.75rem;color:#10b981;}
.btn-reupload{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:transparent;border:1.5px solid #10b981;color:#065f46;border-radius:7px;font-size:0.77rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all var(--transition);}
.btn-reupload:hover{background:#d1fae5;}

.order-card-footer{padding:12px 20px;border-top:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#fafafa;}
.order-address{font-size:0.78rem;color:var(--text-muted);display:flex;align-items:flex-start;gap:5px;flex:1;min-width:0;}
.order-address-text{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:400px;}
.btn-view-order{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:var(--primary);color:#fff;border:none;border-radius:8px;font-size:0.8rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all var(--transition);white-space:nowrap;}
.btn-view-order:hover{background:var(--primary-dark);}

/* Empty state */
.empty-state{text-align:center;padding:80px 20px;background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);}
.empty-icon{font-size:3.5rem;display:block;margin-bottom:16px;}
.empty-title{font-size:1.1rem;font-weight:800;color:var(--dark2);margin-bottom:8px;}
.empty-sub{font-size:0.88rem;color:var(--text-muted);margin-bottom:24px;}
.btn-shop{display:inline-flex;align-items:center;gap:6px;padding:11px 22px;background:var(--primary);color:#fff;border-radius:9px;font-size:0.9rem;font-weight:700;transition:background var(--transition);}
.btn-shop:hover{background:var(--primary-dark);}

/* ── Order detail modal ── */
.modal-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1000;backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:20px;}
.modal-backdrop.show{display:flex;}
.modal{background:var(--card-bg);border-radius:var(--radius);width:100%;max-width:640px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);animation:modalIn 0.25s ease;scrollbar-width:thin;}
@keyframes modalIn{from{opacity:0;transform:translateY(20px) scale(0.97);}to{opacity:1;transform:translateY(0) scale(1);}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--card-bg);z-index:1;}
.modal-title{font-size:1rem;font-weight:800;color:var(--dark2);}
.modal-close{width:32px;height:32px;background:#f3f4f6;border:none;border-radius:8px;cursor:pointer;font-size:1rem;color:var(--text-muted);display:flex;align-items:center;justify-content:center;transition:all var(--transition);}
.modal-close:hover{background:#fee2e2;color:#ef4444;}
.modal-body{padding:22px;}
.modal-status-banner{display:flex;align-items:center;gap:12px;padding:14px 16px;border-radius:10px;margin-bottom:20px;}
.modal-section{margin-bottom:20px;}
.modal-section-title{font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.12em;color:var(--text-muted);margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.modal-section-title::after{content:'';flex:1;height:1px;background:var(--border);}
.modal-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:500px){.modal-info-grid{grid-template-columns:1fr;}}
.modal-info-label{font-size:0.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:2px;}
.modal-info-value{font-size:0.88rem;font-weight:600;color:var(--dark2);}
.modal-items-table{width:100%;border-collapse:collapse;font-size:0.84rem;}
.modal-items-table thead th{padding:8px 10px;text-align:left;font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);background:#f9fafb;border-bottom:1px solid var(--border);}
.modal-items-table tbody tr{border-bottom:1px solid #f3f4f6;}
.modal-items-table tbody tr:last-child{border-bottom:none;}
.modal-items-table tbody td{padding:10px;vertical-align:middle;}
.modal-total-row{display:flex;justify-content:space-between;align-items:center;padding:12px 10px;background:var(--primary-light);border-radius:8px;margin-top:10px;}
.modal-total-label{font-size:0.9rem;font-weight:700;color:var(--dark2);}
.modal-total-value{font-size:1.1rem;font-weight:900;color:var(--primary);}
.modal-proof-wrap{margin-top:8px;}
.modal-proof-wrap img{max-width:100%;border-radius:10px;border:1px solid var(--border);box-shadow:0 4px 16px rgba(0,0,0,0.08);}
.modal-loading{text-align:center;padding:48px 20px;color:var(--text-muted);font-size:0.9rem;}
.spinner-lg{width:36px;height:36px;border:3px solid #f3f4f6;border-top-color:var(--primary);border-radius:50%;animation:spin 0.7s linear infinite;margin:0 auto 12px;}
@keyframes spin{to{transform:rotate(360deg);}}

.site-footer{background:#1a1a2e;color:#6b7280;text-align:center;padding:24px 32px;font-size:0.82rem;line-height:1.7;}
.site-footer a{color:var(--primary);}
</style>
</head>
<body>
<div class="page-body">

<?php include '../includes/navbar.php'; ?>

<div class="page-banner">
    <div class="page-banner-inner">
        <div class="breadcrumb"><a href="home.php">Home</a><span class="breadcrumb-sep">›</span><span>My Orders</span></div>
        <div class="page-banner-title"><i class="fa-solid fa-box"></i> My Orders</div>
        <div class="page-banner-sub"><?= $totalOrders ?> order<?= $totalOrders!==1?'s':'' ?> total</div>
    </div>
</div>

<?= flash() ?>

<div class="container">
<div class="page-content">

    <!-- Status Tabs -->
    <div class="status-tabs">
        <?php
        $tabs = [
            ''                => ['All','<i class="fa-solid fa-cart-shopping"></i>'],
            'pending'         => ['Pending','<i class="fa-solid fa-clock"></i>'],
            'approved'        => ['Approved','✓'],
            'processing'      => ['Processing','<i class="fa-solid fa-gear"></i>'],
            'out_for_delivery'=> ['On the Way','<i class="fa-solid fa-truck"></i>'],
            'delivered'       => ['Delivered','✓'],
            'cancelled'       => ['Cancelled','✕'],
        ];
        foreach ($tabs as $key => $info):
            $isActive = $filterStatus===$key?'active':'';
            $cnt = $key===''?$totalOrders:($statusCounts[$key]??0);
            $url = $key===''?'orders.php':'orders.php?status='.$key;
        ?>
        <a href="<?= $url ?>" class="status-tab <?= $isActive ?>">
            <?= $info[1] ?> <?= $info[0] ?> <span class="tab-count"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (count($orderList) > 0): ?>
    <div class="orders-list">
    <?php foreach ($orderList as $o):
        $sid    = $o['status'];
        $steps  = ['pending','approved','processing','out_for_delivery','delivered'];
        $curIdx = array_search($sid, $steps);

        $canUploadProof = (
            $sid === 'approved' &&
            $o['payment_method'] === 'gcash' &&
            $o['payment_status'] === 'unpaid'
        );
        $hasProof = !empty($o['gcash_proof']);
        $proofUploaded = $sid === 'approved' && $o['payment_method'] === 'gcash' && $hasProof;
    ?>
    <div class="order-card">
        <div class="order-card-accent accent-<?= $sid ?>"></div>

        <!-- Header -->
        <div class="order-card-header">
            <div>
                <div class="order-id">Order #<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?></div>
                <div class="order-date"><?= date('M j, Y · g:i A', strtotime($o['created_at'])) ?></div>
            </div>
            <span class="status-badge sb-<?= $sid ?>">
                <span class="status-dot sd-<?= $sid ?>"></span>
                <?= $sid==='out_for_delivery'?'On the Way':ucwords(str_replace('_',' ',$sid)) ?>
            </span>
        </div>

        <!-- Tracking timeline (skip cancelled) -->
        <?php if ($sid !== 'cancelled'): ?>
        <div class="tracking-strip">
            <div class="tracking-timeline">
                <?php foreach ($steps as $idx => $step):
                    if ($curIdx === false) { $cls=''; }
                    elseif ($idx < $curIdx) { $cls='done'; }
                    elseif ($idx === $curIdx) { $cls='active'; }
                    else { $cls=''; }
                    $stepLabels = ['pending'=>'Pending','approved'=>'Approved','processing'=>'Processing','out_for_delivery'=>'On the Way','delivered'=>'Delivered'];
                    $icon = $cls==='done'?'✓':($idx+1);
                ?>
                <div class="track-step <?= $cls ?>">
                    <div class="track-dot"><?= $icon ?></div>
                    <div class="track-label"><?= $stepLabels[$step] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Body -->
        <div class="order-card-body">
            <div class="order-meta">
                <div class="order-meta-item">
                    <span class="order-meta-label">Total</span>
                    <span class="order-meta-value amount">
                        ₱<?= number_format((float)$o['total_amount'], 2) ?>
                        <?php if ($o['delivery_fee'] === null && $sid !== 'cancelled'): ?>
                            <div style="font-size:0.68rem;color:#f59e0b;font-weight:600;">+ delivery fee TBD</div>
                        <?php elseif ($o['delivery_fee'] !== null): ?>
                            <div style="font-size:0.68rem;color:#10b981;font-weight:600;">(incl. ₱<?= number_format((float)$o['delivery_fee'],2) ?> fee)</div>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="order-meta-item">
                    <span class="order-meta-label">Items</span>
                    <span class="order-meta-value"><?= (int)$o['item_count'] ?> type<?= (int)$o['item_count']!==1?'s':'' ?> · <?= (int)$o['total_units'] ?> units</span>
                </div>
                <div class="order-meta-item">
                    <span class="order-meta-label">Payment</span>
                    <span class="order-meta-value">
                        <?= strtoupper($o['payment_method']) ?>
                        <span class="pay-badge <?= $o['payment_status']==='paid'?'pay-paid':'pay-unpaid' ?>">
                            <?= ucfirst($o['payment_status']) ?>
                        </span>
                    </span>
                </div>
                <div class="order-meta-item">
                    <span class="order-meta-label">Updated</span>
                    <span class="order-meta-value"><?= date('M j, g:i A', strtotime($o['updated_at'])) ?></span>
                </div>
            </div>
        </div>

        <!-- GCash Proof Upload — shown only when approved + gcash + unpaid -->
        <?php if ($canUploadProof && !$hasProof): ?>
        <div class="proof-upload-card">
            <div class="proof-upload-section">
                <div class="proof-upload-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    Action Required: Upload GCash Payment Proof
                </div>
                <div class="proof-upload-desc">
                    Your order has been approved! Please send your GCash payment and upload a screenshot of your payment receipt below.
                </div>
                <form method="POST" action="orders.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload_proof">
                    <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                    <div class="proof-upload-form">
                        <div class="proof-file-input-wrap">
                            <label class="proof-file-label" id="proof_label_<?= $o['id'] ?>">
                                <i class="fa-solid fa-paperclip"></i> Choose screenshot (JPG, PNG)
                            </label>
                            <input type="file" name="gcash_proof" accept="image/*"
                                   onchange="updateProofLabel(this, <?= $o['id'] ?>)" required>
                        </div>
                        <button type="submit" class="btn-upload-proof">
                            <i class="fa-solid fa-arrow-up"></i> Upload Proof
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php elseif ($proofUploaded): ?>
        <!-- Proof already uploaded — show thumbnail + re-upload option -->
        <div class="proof-uploaded-section">
            <img src="../<?= htmlspecialchars($o['gcash_proof']) ?>?v=<?= time() ?>"
                 class="proof-thumb"
                 alt="Payment Proof"
                 onclick="viewProofImg('../<?= htmlspecialchars($o['gcash_proof']) ?>')">
            <div class="proof-uploaded-info">
                <div class="proof-uploaded-title">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#065f46" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Payment proof uploaded
                </div>
                <div class="proof-uploaded-desc">Admin will verify and mark your payment as confirmed.</div>
            </div>
            <form method="POST" action="orders.php" enctype="multipart/form-data" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <input type="hidden" name="action" value="upload_proof">
                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                <div style="position:relative;overflow:hidden;">
                    <button type="button" class="btn-reupload"><i class="fa-solid fa-arrows-rotate"></i> Replace</button>
                    <input type="file" name="gcash_proof" accept="image/*"
                           style="position:absolute;inset:0;opacity:0;width:100%;cursor:pointer;"
                           onchange="this.closest('form').submit()" required>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="order-card-footer">
            <div class="order-address">
                <span><i class="fa-solid fa-location-dot"></i></span>
                <span class="order-address-text"><?= htmlspecialchars($o['delivery_address']) ?></span>
            </div>
            <button class="btn-view-order" onclick="viewOrder(<?= $o['id'] ?>)">View Details →</button>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="empty-state">
        <span class="empty-icon"><i class="fa-solid fa-box"></i></span>
        <div class="empty-title"><?= $filterStatus?'No '.ucwords(str_replace('_',' ',$filterStatus)).' orders':'No orders yet' ?></div>
        <div class="empty-sub">
            <?= $filterStatus
                ?'You have no orders with this status. <a href="orders.php" style="color:var(--primary);font-weight:600;">View all orders</a>'
                :"You haven't placed any orders yet." ?>
        </div>
        <?php if (!$filterStatus): ?><a href="products.php" class="btn-shop"><i class="fa-solid fa-egg"></i> Browse Products</a><?php endif; ?>
    </div>
    <?php endif; ?>

</div>
</div>

<footer class="site-footer">
    &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business &nbsp;·&nbsp;
    Loreto Cortes, Bohol  &nbsp;·&nbsp;
    <a href="contact.php">Contact Us</a>
</footer>
</div>


<!-- ══ ORDER DETAIL MODAL ══ -->
<div class="modal-backdrop" id="orderModal" onclick="if(event.target===this)closeModal()">
    <div class="modal" id="modalBox">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">Order Details</div>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="modal-loading"><div class="spinner-lg"></div>Loading…</div>
        </div>
    </div>
</div>

<!-- Proof image lightbox -->
<div class="modal-backdrop" id="proofLightbox" onclick="if(event.target===this)closeLightbox()" style="z-index:1100;">
    <div style="max-width:600px;width:100%;padding:10px;">
        <div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
            <button onclick="closeLightbox()" style="background:#fff;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:1.2rem;display:flex;align-items:center;justify-content:center;">✕</button>
        </div>
        <img id="lightbox_img" src="" alt="Proof" style="max-width:100%;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,0.3);">
    </div>
</div>


<script>
const STATUS_CONFIG = {
    pending:          {label:'Pending',          bg:'#fef3c7',color:'#92400e',icon:'<i class="fa-solid fa-clock"></i>'},
    approved:         {label:'Approved',         bg:'#dbeafe',color:'#1e40af',icon:'✓'},
    processing:       {label:'Processing',       bg:'#ede9fe',color:'#5b21b6',icon:'<i class="fa-solid fa-gear"></i>'},
    out_for_delivery: {label:'Out for Delivery', bg:'#ffedd5',color:'#9a3412',icon:'<i class="fa-solid fa-truck"></i>'},
    delivered:        {label:'Delivered',        bg:'#d1fae5',color:'#065f46',icon:'✓'},
    cancelled:        {label:'Cancelled',        bg:'#fee2e2',color:'#991b1b',icon:'✕'},
};

// ── Order detail modal ─────────────────────────────────────────
function viewOrder(orderId) {
    document.getElementById('modalTitle').textContent = 'Order #'+String(orderId).padStart(4,'0');
    document.getElementById('modalBody').innerHTML = '<div class="modal-loading"><div class="spinner-lg"></div>Loading…</div>';
    document.getElementById('orderModal').classList.add('show');
    document.body.style.overflow = 'hidden';

    fetch('orders.php?get_order='+orderId)
        .then(r=>r.json())
        .then(data=>{
            if(!data.success){ document.getElementById('modalBody').innerHTML='<div class="modal-loading">✕ Could not load.</div>'; return; }
            renderModal(data);
        })
        .catch(()=>{ document.getElementById('modalBody').innerHTML='<div class="modal-loading">✕ Network error.</div>'; });
}

function renderModal(data) {
    const o   = data.order;
    const cfg = STATUS_CONFIG[o.status]||STATUS_CONFIG.pending;
    const fmtDate = d=>{ if(!d)return'—'; const dt=new Date(d.replace(' ','T')); return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})+' · '+dt.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'}); };
    const fmtPeso = v=>'₱'+parseFloat(v).toLocaleString('en-PH',{minimumFractionDigits:2});
    const esc = s=>{ if(!s)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };

    let itemRows='';
    data.items.forEach(item=>{
        const isChick=item.category.toLowerCase().includes('chicken');
        itemRows+=`<tr>
            <td><span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;font-size:1rem;margin-right:6px;${isChick?'background:linear-gradient(135deg,#f0fdf4,#bbf7d0)':'background:linear-gradient(135deg,#fef9ee,#fdeec8)'}">${isChick?'<i class="fa-solid fa-drumstick-bite"></i>':'<i class="fa-solid fa-egg"></i>'}</span>${esc(item.name)}<div style="font-size:0.72rem;color:var(--text-muted);">${esc(item.unit)}</div></td>
            <td style="text-align:center;font-weight:600;">${item.quantity}</td>
            <td style="text-align:right;">${fmtPeso(item.unit_price)}</td>
            <td style="text-align:right;font-weight:700;color:var(--primary);">${fmtPeso(item.subtotal)}</td>
        </tr>`;
    });

    // Delivery fee row
    const feeRow = (o.delivery_fee!==null&&o.delivery_fee!==undefined&&o.delivery_fee!=='')
        ? `<div style="display:flex;justify-content:space-between;font-size:0.82rem;color:var(--text-muted);padding:6px 10px;"><span>Delivery fee</span><span>${fmtPeso(o.delivery_fee)}</span></div>`
        : `<div style="display:flex;justify-content:space-between;font-size:0.78rem;color:#f59e0b;padding:6px 10px;"><span>Delivery fee</span><span>To be confirmed</span></div>`;

    // GCash proof section
    let proofHtml='';
    if(o.payment_method==='gcash'&&o.gcash_proof) {
        proofHtml=`<div class="modal-section"><div class="modal-section-title"><i class="fa-solid fa-paperclip"></i> Payment Proof</div>
            <div class="modal-proof-wrap"><img src="../${esc(o.gcash_proof)}?v=${Date.now()}" alt="GCash Payment Proof" style="max-width:100%;border-radius:10px;border:1px solid var(--border);cursor:pointer;" onclick="viewProofImg('../${esc(o.gcash_proof)}')"></div></div>`;
    } else if(o.payment_method==='gcash'&&o.status==='approved') {
        proofHtml=`<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;font-size:0.82rem;color:#1e40af;margin-top:10px;"><i class="fa-solid fa-paperclip"></i> No payment proof uploaded yet. Please upload it from the order list.</div>`;
    }

    // Transaction section
    let txnHtml='';
    if(data.txn) {
        txnHtml=`<div class="modal-section"><div class="modal-section-title"><i class="fa-solid fa-credit-card"></i> Transaction</div>
            <div class="modal-info-grid">
                <div><div class="modal-info-label">Method</div><div class="modal-info-value">${data.txn.payment_method.toUpperCase()}</div></div>
                <div><div class="modal-info-label">Reference</div><div class="modal-info-value">${esc(data.txn.reference_no||'—')}</div></div>
                <div><div class="modal-info-label">Amount</div><div class="modal-info-value" style="color:var(--success);">${fmtPeso(data.txn.amount)}</div></div>
                <div><div class="modal-info-label">Date</div><div class="modal-info-value">${fmtDate(data.txn.transaction_date)}</div></div>
            </div></div>`;
    }

    const notesHtml = o.notes
        ? `<div style="margin-top:8px;font-size:0.8rem;color:var(--text-muted);background:#f9fafb;border:1px solid var(--border);border-radius:8px;padding:10px 12px;white-space:pre-line;">${esc(o.notes)}</div>`
        : '';

    document.getElementById('modalBody').innerHTML=`
        <div class="modal-status-banner" style="background:${cfg.bg};color:${cfg.color};">
            <span style="font-size:1.5rem;">${cfg.icon}</span>
            <div><div style="font-size:0.95rem;font-weight:800;">${cfg.label}</div><div style="font-size:0.75rem;opacity:0.8;">Updated: ${fmtDate(o.updated_at)}</div></div>
        </div>
        <div class="modal-section"><div class="modal-section-title"><i class="fa-solid fa-clipboard-list"></i> Order Info</div>
            <div class="modal-info-grid">
                <div><div class="modal-info-label">Order ID</div><div class="modal-info-value">#${String(o.id).padStart(4,'0')}</div></div>
                <div><div class="modal-info-label">Date Placed</div><div class="modal-info-value">${fmtDate(o.created_at)}</div></div>
                <div><div class="modal-info-label">Payment</div><div class="modal-info-value">${o.payment_method.toUpperCase()}</div></div>
                <div><div class="modal-info-label">Payment Status</div><div class="modal-info-value" style="color:${o.payment_status==='paid'?'var(--success)':'var(--danger)'};">${o.payment_status.charAt(0).toUpperCase()+o.payment_status.slice(1)}</div></div>
            </div>
        </div>
        <div class="modal-section"><div class="modal-section-title"><i class="fa-solid fa-location-dot"></i> Delivery</div>
            <div style="font-size:0.88rem;background:#f9fafb;border:1px solid var(--border);border-radius:8px;padding:10px 14px;">${esc(o.delivery_address)}</div>
            ${notesHtml}
        </div>
        <div class="modal-section"><div class="modal-section-title"><i class="fa-solid fa-cart-shopping"></i> Items</div>
            <table class="modal-items-table"><thead><tr><th>Product</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Price</th><th style="text-align:right;">Subtotal</th></tr></thead>
            <tbody>${itemRows}</tbody></table>
            ${feeRow}
            <div class="modal-total-row"><span class="modal-total-label">Grand Total</span><span class="modal-total-value">${fmtPeso(o.total_amount)}</span></div>
        </div>
        ${proofHtml}
        ${txnHtml}
    `;
}

function closeModal() {
    document.getElementById('orderModal').classList.remove('show');
    document.body.style.overflow='';
}

// ── Proof lightbox ─────────────────────────────────────────────
function viewProofImg(src) {
    document.getElementById('lightbox_img').src=src+'?v='+Date.now();
    document.getElementById('proofLightbox').classList.add('show');
    document.body.style.overflow='hidden';
}
function closeLightbox() {
    document.getElementById('proofLightbox').classList.remove('show');
    document.body.style.overflow='';
}

// ── File label update ──────────────────────────────────────────
function updateProofLabel(input, orderId) {
    var label=document.getElementById('proof_label_'+orderId);
    if(input.files&&input.files[0]) {
        label.textContent='✓ '+input.files[0].name;
        label.classList.add('has-file');
    }
}

document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){closeModal();closeLightbox();}
});
</script>
</body>
</html>