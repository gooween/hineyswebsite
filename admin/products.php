<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/products.php
// Purpose: Products CRUD — one file (list + add + edit + delete)
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

// ── POST Handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $cat_id      = (int)($_POST['category_id'] ?? 0);
        $name        = clean($_POST['name'] ?? '', $conn);
        $desc        = clean($_POST['description'] ?? '', $conn);
        $price       = (float)($_POST['price'] ?? 0);
        $unit        = clean($_POST['unit'] ?? 'per piece', $conn);
        $image_url   = clean($_POST['image_url'] ?? '', $conn);
        $is_active   = (int)($_POST['is_active'] ?? 1);
        $init_qty    = (int)($_POST['init_qty'] ?? 0);
        $reorder_lvl = (int)($_POST['reorder_level'] ?? 10);

        if ($name && $cat_id && $price > 0) {
            $conn->query("INSERT INTO products (category_id, name, description, price, unit, image_url, is_active, created_at)
                          VALUES ({$cat_id}, '{$name}', '{$desc}', {$price}, '{$unit}', '{$image_url}', {$is_active}, NOW())");
            $new_pid = (int)$conn->insert_id;
            if ($new_pid) {
                $conn->query("INSERT INTO inventory (product_id, quantity, reorder_level, last_updated, notes)
                              VALUES ({$new_pid}, {$init_qty}, {$reorder_lvl}, NOW(), 'Initial stock on product creation')
                              ON DUPLICATE KEY UPDATE quantity=quantity");
                if ($init_qty > 0) {
                    $uid = (int)$_SESSION['user_id'];
                    $conn->query("INSERT INTO inventory_logs (product_id, type, quantity, reason, created_by, created_at)
                                  VALUES ({$new_pid}, 'in', {$init_qty}, 'Initial stock entry', {$uid}, NOW())");
                }
            }
            redirect('products.php', 'success', 'Product added successfully.');
        } else {
            redirect('products.php', 'error', 'Please fill in all required fields.');
        }
    }

    if ($action === 'edit') {
        $id        = (int)($_POST['id'] ?? 0);
        $cat_id    = (int)($_POST['category_id'] ?? 0);
        $name      = clean($_POST['name'] ?? '', $conn);
        $desc      = clean($_POST['description'] ?? '', $conn);
        $price     = (float)($_POST['price'] ?? 0);
        $unit      = clean($_POST['unit'] ?? 'per piece', $conn);
        $image_url = clean($_POST['image_url'] ?? '', $conn);
        $is_active = (int)($_POST['is_active'] ?? 1);
        $reorder   = (int)($_POST['reorder_level'] ?? 10);

        if ($id && $name && $cat_id && $price > 0) {
            $conn->query("UPDATE products SET
                category_id = {$cat_id},
                name        = '{$name}',
                description = '{$desc}',
                price       = {$price},
                unit        = '{$unit}',
                image_url   = '{$image_url}',
                is_active   = {$is_active}
                WHERE id = {$id}");
            $conn->query("UPDATE inventory SET reorder_level = {$reorder}, last_updated = NOW() WHERE product_id = {$id}");
            redirect('products.php', 'success', 'Product updated successfully.');
        } else {
            redirect('products.php', 'error', 'Please fill in all required fields.');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("UPDATE products SET is_active = 0 WHERE id = {$id}");
            redirect('products.php', 'success', 'Product deactivated successfully.');
        } else {
            redirect('products.php', 'error', 'Invalid product.');
        }
    }

    if ($action === 'delete_hard') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("DELETE FROM inventory_logs WHERE product_id = {$id}");
            $conn->query("DELETE FROM inventory WHERE product_id = {$id}");
            $conn->query("DELETE FROM products WHERE id = {$id}");
            redirect('products.php', 'success', 'Product permanently deleted.');
        } else {
            redirect('products.php', 'error', 'Invalid product.');
        }
    }
}

// ── Fetch categories ─────────────────────────────────────────
$categories = [];
$catResult  = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $catResult->fetch_assoc()) $categories[] = $row;

// ── Pagination + search ──────────────────────────────────────
$perPage      = 15;
$page         = max(1, (int)($_GET['page'] ?? 1));
$search       = trim($_GET['q'] ?? '');
$filterCat    = (int)($_GET['cat'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');
$offset       = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($search)       $where .= " AND (p.name LIKE '%{$conn->real_escape_string($search)}%' OR p.description LIKE '%{$conn->real_escape_string($search)}%')";
if ($filterCat)    $where .= " AND p.category_id = {$filterCat}";
if ($filterStatus === 'active')   $where .= " AND p.is_active = 1";
if ($filterStatus === 'inactive') $where .= " AND p.is_active = 0";

$totalResult = $conn->query("SELECT COUNT(*) AS cnt FROM products p {$where}");
$totalCount  = (int)($totalResult->fetch_assoc()['cnt'] ?? 0);
$totalPages  = max(1, (int)ceil($totalCount / $perPage));

$products = $conn->query("
    SELECT p.*, c.name AS category_name,
           COALESCE(i.quantity, 0) AS stock,
           COALESCE(i.reorder_level, 10) AS reorder_level
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN inventory i ON i.product_id = p.id
    {$where}
    ORDER BY p.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");

$activePage = 'products';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Products — Hiney's Admin</title>
<style>
/* ── Fix missing variable (sidebar may not define this) ── */
:root { --card-border: #e5e7eb; }

/* ── Main layout — matches dashboard exactly ── */
.main-content {
    margin-left: var(--sidebar-w);
    flex: 1;
    padding: 32px 32px 48px;
    min-height: 100vh;
    background: var(--page-bg);
    transition: margin-left 0.3s ease;
    box-sizing: border-box;
    width: calc(100% - var(--sidebar-w));
}

/* Page header */
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}
.page-title     { font-size: 1.5rem; font-weight: 800; color: var(--dark); letter-spacing: -0.02em; }
.page-title-sub { font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }

/* ── Toolbar ── */
.toolbar {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: var(--radius) var(--radius) 0 0;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    border-bottom: none;
    width: 100%;
    box-sizing: border-box;
}
.toolbar-left  { display: flex; align-items: center; gap: 10px; flex: 1; flex-wrap: wrap; }
.toolbar-right { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.toolbar-title { font-size: 0.95rem; font-weight: 700; color: var(--dark); }

.count-pill {
    background: var(--primary);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 20px;
    letter-spacing: 0.03em;
}

/* Search */
.search-wrap { position: relative; display: flex; align-items: center; }
.search-wrap svg { position: absolute; left: 10px; color: var(--text-muted); pointer-events: none; }
.search-input {
    padding: 7px 12px 7px 34px;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    font-size: 0.85rem;
    width: 200px;
    background: var(--page-bg);
    color: var(--text);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.12); }

.filter-select {
    padding: 7px 30px 7px 10px;
    border: 1px solid var(--card-border);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--page-bg);
    color: var(--text);
    outline: none;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 9px center;
    transition: border-color 0.15s;
}
.filter-select:focus { border-color: var(--primary); outline: none; }

/* Add button */
.btn-add {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: var(--primary);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s, transform 0.1s;
    white-space: nowrap;
    text-decoration: none;
}
.btn-add:hover  { background: #cf6d17; transform: translateY(-1px); }
.btn-add:active { transform: translateY(0); }

/* ── Table wrapper ── */
.table-wrapper {
    background: var(--card-bg);
    border: 1px solid var(--card-border);
    border-radius: 0 0 var(--radius) var(--radius);
    overflow-x: auto;
    box-shadow: var(--shadow);
    width: 100%;
    box-sizing: border-box;
}

table.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.87rem;
}
table.data-table thead th {
    background: var(--dark);
    color: #e5e7eb;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    padding: 12px 14px;
    white-space: nowrap;
    text-align: left;
}
table.data-table tbody tr:nth-child(even) { background: #fef9f0; }
table.data-table tbody tr:hover { background: #fdebd0; transition: background 0.15s; }
table.data-table tbody td {
    padding: 11px 14px;
    color: var(--text);
    border-bottom: 1px solid #f3f4f6;
    vertical-align: middle;
}
table.data-table tbody tr:last-child td { border-bottom: none; }

/* Action buttons */
.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid;
    background: transparent;
    transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}
.btn-edit            { color: var(--primary); border-color: var(--primary); }
.btn-edit:hover      { background: var(--primary); color: #fff; }
.btn-delete          { color: #ef4444; border-color: #ef4444; }
.btn-delete:hover    { background: #ef4444; color: #fff; }

/* Product cell */
.product-cell  { display: flex; align-items: center; gap: 10px; }
.product-thumb {
    width: 38px; height: 38px;
    border-radius: 8px;
    background: linear-gradient(135deg, #fef3e8, #fde9d0);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
    border: 1px solid #fddcb5;
}
.product-name { font-weight: 600; color: var(--dark); }
.product-desc { font-size: 0.75rem; color: var(--text-muted); margin-top: 1px; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Status badge */
.status-dot {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.78rem; font-weight: 600;
    padding: 3px 10px; border-radius: 20px;
}
.status-dot.active   { background: #d1fae5; color: #065f46; }
.status-dot.inactive { background: #fee2e2; color: #991b1b; }
.status-dot::before  { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

/* Price */
.price-cell { font-weight: 700; color: var(--dark); }
.unit-label { font-size: 0.72rem; color: var(--text-muted); font-weight: 400; }

/* Stock bar */
.stock-cell { display: flex; flex-direction: column; gap: 3px; }
.stock-num  { font-weight: 700; }
.stock-bar  { width: 60px; height: 4px; background: #e5e7eb; border-radius: 2px; overflow: hidden; }
.stock-bar-fill { height: 100%; border-radius: 2px; }

/* Empty state */
.empty-state { padding: 56px 20px; text-align: center; color: var(--text-muted); }
.empty-icon  { font-size: 3rem; margin-bottom: 12px; }
.empty-text  { font-size: 0.9rem; }
.empty-sub   { font-size: 0.8rem; margin-top: 4px; }

/* Pagination */
.pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-top: 1px solid var(--card-border);
    font-size: 0.82rem; color: var(--text-muted); flex-wrap: wrap; gap: 8px;
}
.pagination-pages { display: flex; align-items: center; gap: 4px; }
.pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 8px;
    border-radius: 6px; border: 1px solid var(--card-border);
    background: var(--card-bg); color: var(--text);
    font-size: 0.82rem; font-weight: 500; cursor: pointer;
    text-decoration: none; transition: background 0.15s, border-color 0.15s;
}
.pg-btn:hover              { background: var(--page-bg); border-color: #d1d5db; }
.pg-btn.active             { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 700; }
.pg-btn:disabled,
.pg-btn.disabled           { opacity: 0.4; pointer-events: none; }

/* ── Modals ── */
.modal-backdrop {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    backdrop-filter: blur(3px);
    z-index: 1000; display: none;
    align-items: center; justify-content: center; padding: 20px;
}
.modal-backdrop.open { display: flex; }

.modal-card {
    background: var(--card-bg);
    border-radius: 14px; width: 100%; max-width: 640px;
    max-height: 92vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2), 0 4px 16px rgba(0,0,0,0.1);
    animation: modalSlide 0.22s cubic-bezier(0.34,1.56,0.64,1) both;
}
.modal-card.sm { max-width: 460px; }

@keyframes modalSlide {
    from { opacity: 0; transform: translateY(20px) scale(0.97); }
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
    border: none; background: var(--page-bg); border-radius: 7px;
    cursor: pointer; color: var(--text-muted); font-size: 1rem;
    transition: background 0.15s, color 0.15s;
}
.modal-close:hover { background: #fee2e2; color: #ef4444; }

.modal-body { padding: 20px 24px; }
.modal-footer {
    display: flex; align-items: center; justify-content: flex-end; gap: 10px;
    padding: 16px 24px; border-top: 1px solid var(--card-border);
    background: var(--page-bg); border-radius: 0 0 14px 14px;
    position: sticky; bottom: 0;
}

/* Form elements */
.form-section-label {
    font-size: 0.68rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.1em; color: var(--primary);
    margin: 18px 0 10px; padding-bottom: 6px;
    border-bottom: 1px solid #fde9d0;
}
.form-section-label:first-child { margin-top: 0; }

.form-grid         { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-group        { display: flex; flex-direction: column; gap: 5px; }
.form-group.span-2 { grid-column: span 2; }
.form-label        { font-size: 0.8rem; font-weight: 600; color: var(--dark); }
.form-label .req   { color: #ef4444; margin-left: 2px; }

.form-input, .form-select, .form-textarea {
    padding: 8px 12px; border: 1px solid var(--card-border);
    border-radius: 8px; font-size: 0.87rem; color: var(--text);
    background: #fff; outline: none; font-family: inherit; width: 100%;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.12);
}
.form-input[readonly] { background: var(--page-bg); color: var(--text-muted); cursor: not-allowed; }
.form-textarea { resize: vertical; min-height: 80px; }
.form-hint     { font-size: 0.72rem; color: var(--text-muted); margin-top: 2px; }

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

/* Delete modal */
.delete-icon-wrap { width: 60px; height: 60px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 1.8rem; }
.delete-title     { text-align: center; font-size: 1.05rem; font-weight: 700; color: var(--dark); margin-bottom: 8px; }
.delete-text      { text-align: center; font-size: 0.88rem; color: var(--text-muted); line-height: 1.5; }
.delete-name      { font-weight: 700; color: #ef4444; }

/* Mobile */
.mobile-menu-btn {
    display: none; align-items: center; justify-content: center;
    width: 38px; height: 38px; border: 1px solid var(--card-border);
    border-radius: 8px; background: var(--card-bg); cursor: pointer;
    color: var(--dark); flex-shrink: 0;
}

@media(max-width:768px) {
    .main-content          { margin-left: 0; padding: 16px 16px 48px; width: 100%; }
    .mobile-menu-btn       { display: flex; }
    .form-grid             { grid-template-columns: 1fr; }
    .form-group.span-2     { grid-column: span 1; }
    .toolbar               { flex-direction: column; align-items: stretch; }
    .toolbar-right         { justify-content: flex-end; }
}
</style>
</head>
<body>
<div class="admin-layout">
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
                <button class="mobile-menu-btn" onclick="openSidebar()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <h1 class="page-title">Products</h1>
            </div>
            <div class="page-title-sub">Manage your product catalog — eggs and live chicken inventory</div>
        </div>
    </div>

    <?= flash() ?>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="toolbar-left">
            <span class="toolbar-title">All Products</span>
            <span class="count-pill"><?= number_format($totalCount) ?></span>
            <form method="GET" style="display:contents;">
                <div class="search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="q" class="search-input" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="cat" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <?php if ($search || $filterCat || $filterStatus): ?>
                    <a href="products.php" style="font-size:0.8rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear filters</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="toolbar-right">
            <button class="btn-add" onclick="openModal('addModal')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Product
            </button>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <?php if ($products && $products->num_rows > 0): ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:44px;">#</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Reorder</th>
                    <th>Status</th>
                    <th style="text-align:center;width:130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $rowNum = $offset + 1;
            while ($p = $products->fetch_assoc()):
                $emoji      = (stripos($p['name'], 'chicken') !== false || stripos($p['category_name'], 'chicken') !== false) ? '🐔' : '🥚';
                $stockPct   = $p['reorder_level'] > 0 ? min(100, round(($p['stock'] / max($p['reorder_level'] * 2, 1)) * 100)) : 100;
                $stockColor = $p['stock'] <= 0 ? '#ef4444' : ($p['stock'] <= $p['reorder_level'] ? '#f59e0b' : '#10b981');
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $rowNum++ ?></td>
                <td>
                    <div class="product-cell">
                        <div class="product-thumb"><?= $emoji ?></div>
                        <div>
                            <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                            <?php if ($p['description']): ?>
                            <div class="product-desc"><?= htmlspecialchars($p['description']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <span style="background:#f3f4f6;color:var(--text-muted);padding:2px 8px;border-radius:6px;font-size:0.78rem;font-weight:500;">
                        <?= htmlspecialchars($p['category_name'] ?? '—') ?>
                    </span>
                </td>
                <td class="price-cell">
                    <?= peso((float)$p['price']) ?>
                    <br><span class="unit-label"><?= htmlspecialchars($p['unit']) ?></span>
                </td>
                <td>
                    <div class="stock-cell">
                        <span class="stock-num" style="color:<?= $stockColor ?>;"><?= number_format((int)$p['stock']) ?></span>
                        <div class="stock-bar"><div class="stock-bar-fill" style="width:<?= $stockPct ?>%;background:<?= $stockColor ?>;"></div></div>
                    </div>
                </td>
                <td style="color:var(--text-muted);font-size:0.85rem;"><?= number_format((int)$p['reorder_level']) ?></td>
                <td>
                    <span class="status-dot <?= $p['is_active'] ? 'active' : 'inactive' ?>">
                        <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td style="text-align:center;">
                    <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                        <button class="btn-action btn-edit"
                            onclick="openEdit(<?= htmlspecialchars(json_encode([
                                'id'            => $p['id'],
                                'category_id'   => $p['category_id'],
                                'name'          => $p['name'],
                                'description'   => $p['description'],
                                'price'         => $p['price'],
                                'unit'          => $p['unit'],
                                'image_url'     => $p['image_url'],
                                'is_active'     => $p['is_active'],
                                'reorder_level' => $p['reorder_level'],
                            ]), ENT_QUOTES) ?>)">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Edit
                        </button>
                        <button class="btn-action btn-delete"
                            onclick="openDelete(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                            Delete
                        </button>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?> products</div>
            <div class="pagination-pages">
                <?php
                $qs = http_build_query(array_merge($_GET, ['page' => max(1,$page-1)]));
                echo "<a href='?{$qs}' class='pg-btn" . ($page <= 1 ? ' disabled' : '') . "'>← Prev</a>";
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                if ($start > 1) { $q1 = http_build_query(array_merge($_GET, ['page'=>1])); echo "<a href='?{$q1}' class='pg-btn'>1</a>"; if ($start > 2) echo "<span class='pg-btn disabled'>…</span>"; }
                for ($i = $start; $i <= $end; $i++) { $qi = http_build_query(array_merge($_GET, ['page'=>$i])); echo "<a href='?{$qi}' class='pg-btn" . ($i == $page ? ' active' : '') . "'>{$i}</a>"; }
                if ($end < $totalPages) { if ($end < $totalPages-1) echo "<span class='pg-btn disabled'>…</span>"; $qL = http_build_query(array_merge($_GET, ['page'=>$totalPages])); echo "<a href='?{$qL}' class='pg-btn'>{$totalPages}</a>"; }
                $qs2 = http_build_query(array_merge($_GET, ['page' => min($totalPages, $page+1)]));
                echo "<a href='?{$qs2}' class='pg-btn" . ($page >= $totalPages ? ' disabled' : '') . "'>Next →</a>";
                ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">📦</div>
            <div class="empty-text">No products found</div>
            <div class="empty-sub"><?= ($search || $filterCat || $filterStatus) ? 'Try adjusting your search or filters.' : 'Click "Add Product" to add your first product.' ?></div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /.main-content -->
</div><!-- /.admin-layout -->

<!-- ══ ADD MODAL ══ -->
<div class="modal-backdrop" id="addModal" onclick="backdropClose(event,'addModal')">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="addModalTitle">
        <div class="modal-header">
            <div class="modal-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span id="addModalTitle">Add New Product</span>
            </div>
            <button class="modal-close" onclick="closeModal('addModal')" aria-label="Close">✕</button>
        </div>
        <form method="POST" action="products.php">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-section-label">Basic Information</div>
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label class="form-label">Product Name <span class="req">*</span></label>
                        <input type="text" name="name" class="form-input" placeholder="e.g. Egg Large, Live Chicken" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category <span class="req">*</span></label>
                        <select name="category_id" class="form-select" required>
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit <span class="req">*</span></label>
                        <select name="unit" class="form-select" required>
                            <option value="per piece">Per Piece</option>
                            <option value="per dozen">Per Dozen</option>
                            <option value="per kilo">Per Kilo</option>
                            <option value="per head">Per Head</option>
                            <option value="per tray">Per Tray</option>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-textarea" placeholder="Short product description…"></textarea>
                    </div>
                </div>

                <div class="form-section-label">Pricing</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Price (₱) <span class="req">*</span></label>
                        <input type="number" name="price" class="form-input" placeholder="0.00" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="is_active" class="form-select">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-section-label">Inventory Setup</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Initial Stock Quantity</label>
                        <input type="number" name="init_qty" class="form-input" value="0" min="0">
                        <span class="form-hint">Starting quantity in inventory</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Reorder Level</label>
                        <input type="number" name="reorder_level" class="form-input" value="10" min="0">
                        <span class="form-hint">Alert when stock falls below this</span>
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label">Image URL</label>
                        <input type="text" name="image_url" class="form-input" placeholder="https://… (optional)">
                        <span class="form-hint">Leave blank to use default emoji icon</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Save Product
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ EDIT MODAL ══ -->
<div class="modal-backdrop" id="editModal" onclick="backdropClose(event,'editModal')">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <div class="modal-header">
            <div class="modal-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <span id="editModalTitle">Edit Product</span>
            </div>
            <button class="modal-close" onclick="closeModal('editModal')" aria-label="Close">✕</button>
        </div>
        <form method="POST" action="products.php">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-body">
                <div class="form-section-label">Basic Information</div>
                <div class="form-grid">
                    <div class="form-group span-2">
                        <label class="form-label">Product Name <span class="req">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category <span class="req">*</span></label>
                        <select name="category_id" id="edit_category_id" class="form-select" required>
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Unit <span class="req">*</span></label>
                        <select name="unit" id="edit_unit" class="form-select" required>
                            <option value="per piece">Per Piece</option>
                            <option value="per dozen">Per Dozen</option>
                            <option value="per kilo">Per Kilo</option>
                            <option value="per head">Per Head</option>
                            <option value="per tray">Per Tray</option>
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_description" class="form-textarea"></textarea>
                    </div>
                </div>

                <div class="form-section-label">Pricing & Status</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Price (₱) <span class="req">*</span></label>
                        <input type="number" name="price" id="edit_price" class="form-input" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="is_active" id="edit_is_active" class="form-select">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-section-label">Inventory Settings</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Reorder Level</label>
                        <input type="number" name="reorder_level" id="edit_reorder_level" class="form-input" min="0">
                        <span class="form-hint">Alert threshold for low stock</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Image URL</label>
                        <input type="text" name="image_url" id="edit_image_url" class="form-input">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Update Product
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ DELETE MODAL ══ -->
<div class="modal-backdrop" id="deleteModal" onclick="backdropClose(event,'deleteModal')">
    <div class="modal-card sm" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="modal-header">
            <div class="modal-title" id="deleteModalTitle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span style="color:#ef4444;">Delete Product</span>
            </div>
            <button class="modal-close" onclick="closeModal('deleteModal')" aria-label="Close">✕</button>
        </div>
        <form method="POST" action="products.php">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_id">
            <div class="modal-body">
                <div class="delete-icon-wrap">🗑️</div>
                <div class="delete-title">Deactivate Product?</div>
                <div class="delete-text">
                    Are you sure you want to deactivate<br>
                    <span class="delete-name" id="delete_name"></span>?<br><br>
                    The product will be hidden from customers but order history will be preserved.
                    You can reactivate it later from the edit dialog.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Deactivate
                </button>
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
        ['addModal','editModal','deleteModal'].forEach(id => document.getElementById(id).classList.remove('open'));
        document.body.style.overflow = '';
    }
});

function openEdit(data) {
    document.getElementById('edit_id').value            = data.id;
    document.getElementById('edit_name').value          = data.name;
    document.getElementById('edit_description').value   = data.description || '';
    document.getElementById('edit_price').value         = data.price;
    document.getElementById('edit_image_url').value     = data.image_url || '';
    document.getElementById('edit_is_active').value     = data.is_active;
    document.getElementById('edit_reorder_level').value = data.reorder_level;
    for (let opt of document.getElementById('edit_category_id').options) opt.selected = (parseInt(opt.value) === parseInt(data.category_id));
    for (let opt of document.getElementById('edit_unit').options)        opt.selected = (opt.value === data.unit);
    openModal('editModal');
}

function openDelete(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}

document.querySelector('.search-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') this.closest('form').submit();
});
</script>
</body>
</html>