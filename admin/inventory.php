<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/inventory.php
// ============================================================
session_start();
require_once '../config/db.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'adjust') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $type       = $_POST['type'] ?? 'in';
        $qty        = (int)($_POST['quantity'] ?? 0);
        $reason     = clean($_POST['reason'] ?? '', $conn);
        $uid        = (int)$_SESSION['user_id'];
        if (!in_array($type, ['in','out','adjustment'])) $type = 'in';
        if ($product_id && $qty > 0) {
            if ($type === 'in')
                $conn->query("UPDATE inventory SET quantity = quantity + {$qty}, last_updated = NOW() WHERE product_id = {$product_id}");
            elseif ($type === 'out')
                $conn->query("UPDATE inventory SET quantity = GREATEST(0, quantity - {$qty}), last_updated = NOW() WHERE product_id = {$product_id}");
            else
                $conn->query("UPDATE inventory SET quantity = {$qty}, last_updated = NOW() WHERE product_id = {$product_id}");
            $conn->query("INSERT INTO inventory_logs (product_id, type, quantity, reason, created_by, created_at) VALUES ({$product_id}, '{$type}', {$qty}, '{$reason}', {$uid}, NOW())");
            redirect('inventory.php', 'success', 'Stock updated successfully.');
        } else {
            redirect('inventory.php', 'error', 'Please fill in all required fields.');
        }
    }
    if ($action === 'reorder') {
        $product_id    = (int)($_POST['product_id'] ?? 0);
        $reorder_level = (int)($_POST['reorder_level'] ?? 0);
        if ($product_id) {
            $conn->query("UPDATE inventory SET reorder_level = {$reorder_level}, last_updated = NOW() WHERE product_id = {$product_id}");
            redirect('inventory.php', 'success', 'Reorder level updated.');
        }
    }
}

$search      = trim($_GET['q'] ?? '');
$filterStock = trim($_GET['stock'] ?? '');
$filterCat   = (int)($_GET['cat'] ?? 0);

$where = "WHERE p.is_active = 1";
if ($search)    $where .= " AND p.name LIKE '%{$conn->real_escape_string($search)}%'";
if ($filterCat) $where .= " AND p.category_id = {$filterCat}";
if ($filterStock === 'low') $where .= " AND i.quantity <= i.reorder_level AND i.quantity > 0";
if ($filterStock === 'out') $where .= " AND i.quantity = 0";
if ($filterStock === 'ok')  $where .= " AND i.quantity > i.reorder_level";

$r = $conn->query("SELECT COUNT(*) AS cnt FROM inventory i JOIN products p ON p.id = i.product_id WHERE p.is_active = 1");
$totalItems = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM inventory i JOIN products p ON p.id = i.product_id WHERE p.is_active = 1 AND i.quantity <= i.reorder_level AND i.quantity > 0");
$lowStockCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COUNT(*) AS cnt FROM inventory i JOIN products p ON p.id = i.product_id WHERE p.is_active = 1 AND i.quantity = 0");
$outOfStockCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("SELECT COALESCE(SUM(i.quantity),0) AS total FROM inventory i JOIN products p ON p.id = i.product_id WHERE p.is_active = 1");
$totalUnits = (int)($r->fetch_assoc()['total'] ?? 0);

$inventory = $conn->query("
    SELECT p.id AS product_id, p.name, p.unit, c.name AS category,
           i.id AS inv_id, i.quantity, i.reorder_level, i.last_updated, i.notes
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    {$where}
    ORDER BY i.quantity ASC, p.name ASC
");

$categories = [];
$cr = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $cr->fetch_assoc()) $categories[] = $row;

$logs = $conn->query("
    SELECT il.*, p.name AS product_name, u.full_name AS by_name
    FROM inventory_logs il
    JOIN products p ON p.id = il.product_id
    LEFT JOIN users u ON u.id = il.created_by
    ORDER BY il.created_at DESC
    LIMIT 20
");

$activePage = 'inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory — Hiney's Admin</title>
<style>
:root { --card-border: #e9e8e4; }
.main-content { margin-left: var(--sidebar-w); flex: 1; padding: 32px 32px 48px; min-height: 100vh; background: var(--page-bg); }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.page-title { font-size: 1.5rem; font-weight: 800; color: var(--dark); letter-spacing: -0.02em; }
.page-title-sub { font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }
.stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 24px; }
@media(max-width:1100px){.stats-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.stats-row{grid-template-columns:1fr;}}
.stat-card { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius); padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow); position: relative; overflow: hidden; transition: transform 0.18s, box-shadow 0.18s; }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-card-accent { position: absolute; top: 0; left: 0; width: 100%; height: 3px; }
.stat-icon-wrap { width: 44px; height: 44px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-body { flex: 1; }
.stat-value { font-size: 1.7rem; font-weight: 800; color: var(--dark); line-height: 1; letter-spacing: -0.03em; }
.stat-label { font-size: 0.73rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.07em; font-weight: 700; margin-top: 4px; }
.sc-blue  .stat-card-accent{background:#3b82f6;} .sc-blue  .stat-icon-wrap{background:#eff6ff;color:#3b82f6;}
.sc-green .stat-card-accent{background:#10b981;} .sc-green .stat-icon-wrap{background:#ecfdf5;color:#10b981;}
.sc-amber .stat-card-accent{background:#f59e0b;} .sc-amber .stat-icon-wrap{background:#fffbeb;color:#f59e0b;}
.sc-red   .stat-card-accent{background:#ef4444;} .sc-red   .stat-icon-wrap{background:#fef2f2;color:#ef4444;}
.toolbar { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius) var(--radius) 0 0; padding: 14px 18px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; border-bottom: none; }
.toolbar-left { display: flex; align-items: center; gap: 10px; flex: 1; flex-wrap: wrap; }
.toolbar-title { font-size: 0.95rem; font-weight: 700; color: var(--dark); }
.search-wrap { position: relative; display: flex; align-items: center; }
.search-wrap svg { position: absolute; left: 10px; color: var(--text-muted); pointer-events: none; }
.search-input { padding: 7px 12px 7px 34px; border: 1px solid var(--card-border); border-radius: 8px; font-size: 0.85rem; width: 200px; background: var(--page-bg); color: var(--text); outline: none; transition: border-color 0.15s; }
.search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.12); }
.filter-select { padding: 7px 28px 7px 10px; border: 1px solid var(--card-border); border-radius: 8px; font-size: 0.85rem; background: var(--page-bg); color: var(--text); outline: none; cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 9px center; }
.filter-select:focus { border-color: var(--primary); outline: none; }
.table-wrapper { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 0 0 var(--radius) var(--radius); overflow-x: auto; box-shadow: var(--shadow); margin-bottom: 24px; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
table.data-table thead th { background: var(--dark); color: #e5e7eb; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; padding: 12px 14px; white-space: nowrap; text-align: left; }
table.data-table tbody tr:nth-child(even) { background: #faf9f7; }
table.data-table tbody tr:hover { background: #fef9f4; transition: background 0.12s; }
table.data-table tbody td { padding: 12px 14px; color: var(--text); border-bottom: 1px solid #f3f2f0; vertical-align: middle; }
table.data-table tbody tr:last-child td { border-bottom: none; }
.product-cell { display: flex; align-items: center; gap: 10px; }
.product-thumb { width: 36px; height: 36px; border-radius: 8px; background: linear-gradient(135deg,#fef3e8,#fde9d0); display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; border: 1px solid #fddcb5; }
.product-name { font-weight: 600; color: var(--dark); font-size: 0.88rem; }
.product-unit { font-size: 0.74rem; color: var(--text-muted); margin-top: 1px; }
.stock-wrap   { display: flex; flex-direction: column; gap: 4px; }
.stock-number { font-size: 1rem; font-weight: 700; }
.stock-bar    { width: 80px; height: 5px; background: #e5e7eb; border-radius: 3px; overflow: hidden; }
.stock-fill   { height: 100%; border-radius: 3px; }
.stock-badge  { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; }
.stock-badge::before { content:''; width:5px; height:5px; border-radius:50%; background:currentColor; }
.stock-badge.ok  { background:#d1fae5; color:#065f46; }
.stock-badge.low { background:#fef3c7; color:#92400e; }
.stock-badge.out { background:#fee2e2; color:#991b1b; }
.btn-action { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; border: 1px solid; background: transparent; transition: background 0.15s, color 0.15s; white-space: nowrap; }
.btn-adjust { color: var(--primary); border-color: var(--primary); }
.btn-adjust:hover { background: var(--primary); color: #fff; }
.btn-logs { color: #6b7280; border-color: #d1d5db; }
.btn-logs:hover { background: #f3f4f6; }
.empty-state { padding: 48px 20px; text-align: center; color: var(--text-muted); }
.empty-icon { font-size: 2.8rem; margin-bottom: 10px; }
.section-header { display: flex; align-items: center; justify-content: space-between; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: var(--radius) var(--radius) 0 0; padding: 14px 18px; border-bottom: none; }
.section-title { font-size: 0.9rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.log-type-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }
.log-in         { background: #d1fae5; color: #065f46; }
.log-out        { background: #fee2e2; color: #991b1b; }
.log-adjustment { background: #dbeafe; color: #1e40af; }
/* Modals */
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(3px); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px; }
.modal-backdrop.open { display: flex; }
.modal-card { background: var(--card-bg); border-radius: 14px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: modalIn 0.22s cubic-bezier(0.34,1.56,0.64,1) both; }
@keyframes modalIn { from{opacity:0;transform:translateY(16px) scale(0.97);}to{opacity:1;transform:translateY(0) scale(1);} }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px 14px; border-bottom: 1px solid var(--card-border); position: sticky; top: 0; background: var(--card-bg); z-index: 1; border-radius: 14px 14px 0 0; }
.modal-title { font-size: 0.95rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.modal-close { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border: none; background: var(--page-bg); border-radius: 6px; cursor: pointer; color: var(--text-muted); font-size: 0.95rem; transition: background 0.15s, color 0.15s; }
.modal-close:hover { background: #fee2e2; color: #ef4444; }
.modal-body { padding: 18px 22px; }
.modal-footer { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--card-border); background: var(--page-bg); border-radius: 0 0 14px 14px; position: sticky; bottom: 0; }
.form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.form-label { font-size: 0.8rem; font-weight: 600; color: var(--dark); }
.form-label .req { color: #ef4444; margin-left: 2px; }
.form-input, .form-select, .form-textarea { padding: 9px 12px; border: 1px solid var(--card-border); border-radius: 8px; font-size: 0.87rem; color: var(--text); background: #fff; outline: none; font-family: inherit; width: 100%; transition: border-color 0.15s, box-shadow 0.15s; }
.form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.12); }
.form-textarea { resize: vertical; min-height: 72px; }
.form-hint { font-size: 0.72rem; color: var(--text-muted); }
.form-row  { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.product-info-box { background: var(--page-bg); border: 1px solid var(--card-border); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
.product-info-name  { font-weight: 600; color: var(--dark); font-size: 0.88rem; }
.product-info-stock { font-size: 0.8rem; color: var(--text-muted); margin-top: 2px; }
.type-tabs { display: flex; gap: 6px; margin-bottom: 14px; }
.type-tab  { flex: 1; padding: 8px; text-align: center; border: 1px solid var(--card-border); border-radius: 8px; cursor: pointer; font-size: 0.82rem; font-weight: 600; color: var(--text-muted); transition: all 0.15s; background: var(--page-bg); }
.type-tab:hover { border-color: var(--primary); color: var(--primary); }
.type-tab.active         { background: var(--primary); color: #fff; border-color: var(--primary); }
.type-tab.out.active     { background: #ef4444; border-color: #ef4444; }
.type-tab.adjust.active  { background: #3b82f6; border-color: #3b82f6; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 8px; font-size: 0.88rem; font-weight: 600; cursor: pointer; border: 1px solid; transition: background 0.15s, transform 0.1s; font-family: inherit; }
.btn:active { transform: translateY(1px); }
.btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
.btn-primary:hover { background: #cf6d17; border-color: #cf6d17; }
.btn-ghost { background: transparent; color: var(--text-muted); border-color: var(--card-border); }
.btn-ghost:hover { background: var(--page-bg); color: var(--text); }
.mobile-menu-btn { display: none; align-items: center; justify-content: center; width: 36px; height: 36px; border: 1px solid var(--card-border); border-radius: 8px; background: var(--card-bg); cursor: pointer; color: var(--dark); }
@media(max-width:768px) { .main-content{margin-left:0;padding:16px 16px 48px;} .mobile-menu-btn{display:flex;} .form-row{grid-template-columns:1fr;} }
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
                <h1 class="page-title">Inventory</h1>
            </div>
            <div class="page-title-sub">Monitor stock levels, adjust quantities, and track changes</div>
        </div>
    </div>

    <?= flash() ?>

    <!-- Stat cards -->
    <div class="stats-row">
        <div class="stat-card sc-blue"><div class="stat-card-accent"></div>
            <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
            <div class="stat-body"><div class="stat-value"><?= number_format($totalItems) ?></div><div class="stat-label">Total Products</div></div>
        </div>
        <div class="stat-card sc-green"><div class="stat-card-accent"></div>
            <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
            <div class="stat-body"><div class="stat-value"><?= number_format($totalUnits) ?></div><div class="stat-label">Total Units in Stock</div></div>
        </div>
        <div class="stat-card sc-amber"><div class="stat-card-accent"></div>
            <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
            <div class="stat-body"><div class="stat-value"><?= number_format($lowStockCount) ?></div><div class="stat-label">Low Stock Items</div></div>
        </div>
        <div class="stat-card sc-red"><div class="stat-card-accent"></div>
            <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
            <div class="stat-body"><div class="stat-value"><?= number_format($outOfStockCount) ?></div><div class="stat-label">Out of Stock</div></div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <span class="toolbar-title">Stock Levels</span>
            <form method="GET" style="display:contents;">
                <div class="search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" class="search-input" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="cat" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCat==$cat['id']?'selected':'' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="stock" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Stock</option>
                    <option value="ok"  <?= $filterStock==='ok' ?'selected':'' ?>>OK</option>
                    <option value="low" <?= $filterStock==='low'?'selected':'' ?>>Low Stock</option>
                    <option value="out" <?= $filterStock==='out'?'selected':'' ?>>Out of Stock</option>
                </select>
                <?php if ($search || $filterCat || $filterStock): ?>
                    <a href="inventory.php" style="font-size:0.8rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <?php if ($inventory && $inventory->num_rows > 0): ?>
        <table class="data-table">
            <thead>
                <tr><th>#</th><th>Product</th><th>Category</th><th>Current Stock</th><th>Reorder Level</th><th>Status</th><th>Last Updated</th><th style="text-align:center;width:150px;">Actions</th></tr>
            </thead>
            <tbody>
            <?php
            $n = 1;
            while ($inv = $inventory->fetch_assoc()):
                $qty   = (int)$inv['quantity'];
                $reord = (int)$inv['reorder_level'];
                $emoji = (stripos($inv['name'],'chicken')!==false||stripos($inv['category'],'chicken')!==false)?'🐔':'🥚';
                $pct   = $reord>0?min(100,round(($qty/max($reord*2,1))*100)):100;
                $color = $qty<=0?'#ef4444':($qty<=$reord?'#f59e0b':'#10b981');
                $bCls  = $qty<=0?'out':($qty<=$reord?'low':'ok');
                $bLbl  = $qty<=0?'Out of Stock':($qty<=$reord?'Low Stock':'OK');
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $n++ ?></td>
                <td>
                    <div class="product-cell">
                        <div class="product-thumb"><?= $emoji ?></div>
                        <div><div class="product-name"><?= htmlspecialchars($inv['name']) ?></div><div class="product-unit"><?= htmlspecialchars($inv['unit']) ?></div></div>
                    </div>
                </td>
                <td><span style="background:#f3f4f6;color:var(--text-muted);padding:2px 8px;border-radius:6px;font-size:0.78rem;font-weight:500;"><?= htmlspecialchars($inv['category']) ?></span></td>
                <td>
                    <div class="stock-wrap">
                        <span class="stock-number" style="color:<?= $color ?>;"><?= number_format($qty) ?></span>
                        <div class="stock-bar"><div class="stock-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div></div>
                    </div>
                </td>
                <td style="color:var(--text-muted);"><?= number_format($reord) ?></td>
                <td><span class="stock-badge <?= $bCls ?>"><?= $bLbl ?></span></td>
                <td style="color:var(--text-muted);font-size:0.8rem;"><?= date('M j, Y',strtotime($inv['last_updated'])) ?></td>
                <td style="text-align:center;">
                    <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                        <button class="btn-action btn-adjust"
                            onclick="openAdjust(<?= htmlspecialchars(json_encode(['product_id'=>$inv['product_id'],'name'=>$inv['name'],'qty'=>$qty,'unit'=>$inv['unit'],'reorder'=>$reord]),ENT_QUOTES) ?>)">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Adjust
                        </button>
                        <button class="btn-action btn-logs"
                            onclick="openLogs(<?= $inv['product_id'] ?>,'<?= htmlspecialchars(addslashes($inv['name'])) ?>')">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/></svg>
                            Logs
                        </button>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><div class="empty-icon">📦</div><div>No inventory records found.</div></div>
        <?php endif; ?>
    </div>

    <!-- Recent Logs -->
    <div class="section-header">
        <div class="section-title">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/></svg>
            Recent Stock Activity
        </div>
        <span style="font-size:0.78rem;color:var(--text-muted);">Last 20 entries</span>
    </div>
    <div class="table-wrapper">
        <?php if ($logs && $logs->num_rows > 0): ?>
        <table class="data-table">
            <thead><tr><th>Date & Time</th><th>Product</th><th>Type</th><th>Quantity</th><th>Reason</th><th>By</th></tr></thead>
            <tbody>
            <?php while ($log = $logs->fetch_assoc()):
                $tc = match($log['type']){'in'=>'log-in','out'=>'log-out','adjustment'=>'log-adjustment',default=>'log-in'};
                $tl = match($log['type']){'in'=>'↑ Stock In','out'=>'↓ Stock Out','adjustment'=>'⇄ Adjusted',default=>$log['type']};
            ?>
            <tr>
                <td style="font-size:0.82rem;color:var(--text-muted);white-space:nowrap;"><?= date('M j, Y g:i A',strtotime($log['created_at'])) ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($log['product_name']) ?></td>
                <td><span class="log-type-badge <?= $tc ?>"><?= $tl ?></span></td>
                <td style="font-weight:700;"><?= number_format($log['quantity']) ?></td>
                <td style="color:var(--text-muted);font-size:0.84rem;"><?= htmlspecialchars($log['reason']??'—') ?></td>
                <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($log['by_name']??'—') ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><div class="empty-icon">📋</div><div>No stock activity yet.</div></div>
        <?php endif; ?>
    </div>

</div>
</div>

<!-- ADJUST MODAL -->
<div class="modal-backdrop" id="adjustModal" onclick="backdropClose(event,'adjustModal')">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Adjust Stock</div>
            <button class="modal-close" onclick="closeModal('adjustModal')">✕</button>
        </div>
        <form method="POST" action="inventory.php">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="product_id" id="adj_product_id">
            <input type="hidden" name="type" id="adj_type" value="in">
            <div class="modal-body">
                <div class="product-info-box">
                    <div style="font-size:1.4rem;" id="adj_emoji">🥚</div>
                    <div>
                        <div class="product-info-name" id="adj_name">—</div>
                        <div class="product-info-stock">Current stock: <strong id="adj_current">0</strong> units</div>
                    </div>
                </div>
                <div class="type-tabs">
                    <div class="type-tab active" data-type="in" onclick="setType('in')">↑ Stock In</div>
                    <div class="type-tab out" data-type="out" onclick="setType('out')">↓ Stock Out</div>
                    <div class="type-tab adjust" data-type="adjustment" onclick="setType('adjustment')">⇄ Set Qty</div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Quantity <span class="req">*</span></label>
                        <input type="number" name="quantity" id="adj_qty" class="form-input" min="1" placeholder="0" required>
                        <span class="form-hint" id="adj_hint">Units to add to current stock</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reorder Level</label>
                        <input type="number" name="reorder_level" id="adj_reorder" class="form-input" min="0">
                        <span class="form-hint">Leave as-is or update</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason / Notes</label>
                    <textarea name="reason" class="form-textarea" placeholder="e.g. Restock delivery, Walk-in sale…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('adjustModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="adj_submit_btn">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- LOGS MODAL -->
<div class="modal-backdrop" id="logsModal" onclick="backdropClose(event,'logsModal')">
    <div class="modal-card" style="max-width:680px;">
        <div class="modal-header">
            <div class="modal-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z"/></svg><span id="logs_title">Stock History</span></div>
            <button class="modal-close" onclick="closeModal('logsModal')">✕</button>
        </div>
        <div class="modal-body" style="padding:0;">
            <div id="logs_content" style="padding:32px;text-align:center;color:var(--text-muted);">Loading…</div>
        </div>
    </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open');    document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function backdropClose(e,id) { if(e.target===document.getElementById(id)) closeModal(id); }
document.addEventListener('keydown',function(e){ if(e.key==='Escape'){ ['adjustModal','logsModal'].forEach(id=>document.getElementById(id).classList.remove('open')); document.body.style.overflow=''; } });

function openAdjust(data) {
    document.getElementById('adj_product_id').value = data.product_id;
    document.getElementById('adj_name').textContent = data.name;
    document.getElementById('adj_current').textContent = data.qty.toLocaleString();
    document.getElementById('adj_reorder').value = data.reorder;
    document.getElementById('adj_qty').value = '';
    document.getElementById('adj_emoji').textContent = data.name.toLowerCase().includes('chicken') ? '🐔' : '🥚';
    setType('in');
    openModal('adjustModal');
}

function setType(type) {
    document.getElementById('adj_type').value = type;
    document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('.type-tab[data-type="'+type+'"]').classList.add('active');
    const hints = { 'in':'Units to add to current stock', 'out':'Units to deduct from current stock', 'adjustment':'Set stock to this exact quantity' };
    document.getElementById('adj_hint').textContent = hints[type];
}

function openLogs(productId, productName) {
    document.getElementById('logs_title').textContent = productName + ' — Stock History';
    document.getElementById('logs_content').innerHTML = '<div style="padding:32px;text-align:center;color:var(--text-muted);">Loading…</div>';
    openModal('logsModal');
    fetch('inventory_logs_ajax.php?product_id=' + productId)
        .then(r => r.text())
        .then(html => { document.getElementById('logs_content').innerHTML = html; })
        .catch(() => { document.getElementById('logs_content').innerHTML = '<div style="padding:32px;text-align:center;color:#ef4444;">Failed to load logs.</div>'; });
}

const si = document.querySelector('.search-input');
if (si) si.addEventListener('keydown', e => { if(e.key==='Enter') e.target.closest('form').submit(); });
</script>
</body>
</html>
