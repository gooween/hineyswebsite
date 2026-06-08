<?php
session_start();
require_once '../config/db.php';
requireAdmin();

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
        $new_stock = (int)($_POST['stock_qty'] ?? -1);

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

            // Update stock if provided
            if ($new_stock >= 0) {
                $old = $conn->query("SELECT quantity FROM inventory WHERE product_id = {$id}")->fetch_assoc();
                $old_qty = (int)($old['quantity'] ?? 0);
                $diff = $new_stock - $old_qty;
                if ($diff != 0) {
                    $conn->query("UPDATE inventory SET quantity = {$new_stock}, last_updated = NOW() WHERE product_id = {$id}");
                    $uid = (int)$_SESSION['user_id'];
                    $type = $diff > 0 ? 'in' : 'out';
                    $abs = abs($diff);
                    $reason = $conn->real_escape_string('Manual stock adjustment via edit');
                    $conn->query("INSERT INTO inventory_logs (product_id, type, quantity, reason, created_by, created_at)
                                  VALUES ({$id}, '{$type}', {$abs}, '{$reason}', {$uid}, NOW())");
                }
            }

            redirect('products.php', 'success', 'Product updated successfully.');
        } else {
            redirect('products.php', 'error', 'Please fill in all required fields.');
        }
    }

    // Archive (soft delete with reason)
    if ($action === 'archive') {
        $id     = (int)($_POST['id'] ?? 0);
        $reason = clean($_POST['archive_reason'] ?? 'No reason provided', $conn);
        $uid    = (int)$_SESSION['user_id'];
        if ($id) {
            $conn->query("UPDATE products SET is_active = 0 WHERE id = {$id}");
            // Ensure table exists first
            $conn->query("CREATE TABLE IF NOT EXISTS product_archive_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                reason TEXT,
                archived_by INT,
                archived_at DATETIME,
                INDEX (product_id)
            )");
            $conn->query("INSERT INTO product_archive_log (product_id, reason, archived_by, archived_at)
                          VALUES ({$id}, '{$reason}', {$uid}, NOW())");
            redirect('products.php', 'success', 'Product archived successfully.');
        } else {
            redirect('products.php', 'error', 'Invalid product.');
        }
    }

    // Restore from archive
    if ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("UPDATE products SET is_active = 1 WHERE id = {$id}");
            redirect('products.php', 'success', 'Product restored successfully.');
        } else {
            redirect('products.php', 'error', 'Invalid product.');
        }
    }

    // Hard delete
    if ($action === 'delete_hard') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("DELETE FROM product_archive_log WHERE product_id = {$id}");
            $conn->query("DELETE FROM inventory_logs WHERE product_id = {$id}");
            $conn->query("DELETE FROM inventory WHERE product_id = {$id}");
            $conn->query("DELETE FROM products WHERE id = {$id}");
            redirect('products.php', 'success', 'Product permanently deleted.');
        } else {
            redirect('products.php', 'error', 'Invalid product.');
        }
    }
}

$categories = [];
$catResult  = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $catResult->fetch_assoc()) $categories[] = $row;

$catMap = [];
foreach ($categories as $c) $catMap[strtolower($c['name'])] = $c['id'];

$perPage      = 15;
$page         = max(1, (int)($_GET['page'] ?? 1));
$search       = trim($_GET['q'] ?? '');
$filterCat    = (int)($_GET['cat'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');
$filterUnit   = trim($_GET['unit'] ?? '');
$showArchive  = $_GET['view'] === 'archive';
$offset       = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($showArchive) {
    $where .= " AND p.is_active = 0";
} else {
    if ($filterStatus === 'active')   $where .= " AND p.is_active = 1";
    elseif ($filterStatus === 'inactive') $where .= " AND p.is_active = 0";
    else $where .= " AND p.is_active = 1"; // default: only active
}
if ($search)    $where .= " AND (p.name LIKE '%{$conn->real_escape_string($search)}%' OR p.description LIKE '%{$conn->real_escape_string($search)}%')";
if ($filterCat) $where .= " AND p.category_id = {$filterCat}";
if ($filterUnit) $where .= " AND p.unit = '{$conn->real_escape_string($filterUnit)}'";


$totalResult = $conn->query("SELECT COUNT(*) AS cnt FROM products p LEFT JOIN categories c ON c.id = p.category_id {$where}");
$totalCount  = (int)($totalResult->fetch_assoc()['cnt'] ?? 0);
$totalPages  = max(1, (int)ceil($totalCount / $perPage));

$products = $conn->query("
    SELECT p.*, c.name AS category_name,
           COALESCE((
               SELECT CASE
                   WHEN p.unit = 'per tray'
                   THEN COUNT(sb.id)
                   ELSE SUM(sb.remaining)
               END
               FROM stock_batches sb
               WHERE sb.product_id = p.id AND sb.status = 'active'
           ), 0) AS stock,
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style id="hineys-icon-colors">
        .navbar .fa-solid,
        .mobile-drawer .fa-solid,
        .sidebar .fa-solid,
        button .fa-solid,
        [class*="btn"] .fa-solid,
        .badge .fa-solid,
        .status-badge .fa-solid,
        .status-tab .fa-solid,
        .pay-badge .fa-solid,
        .page-banner .fa-solid,
        .page-header .fa-solid,
        .hero .fa-solid,
        .cta-card .fa-solid,
        .about-strip .fa-solid,
        .nav-cart .fa-solid,
        .user-chip .fa-solid,
        .info-card-top .fa-solid,
        .sidebar-logout .fa-solid {
            color: inherit !important
        }

        .fa-egg {
            color: #f4a72c
        }

        .fa-drumstick-bite {
            color: #c2703b
        }

        .fa-circle-check,
        .fa-check,
        .fa-shield-halved,
        .fa-leaf,
        .fa-seedling,
        .fa-phone {
            color: #10b981
        }

        .fa-circle-xmark,
        .fa-xmark,
        .fa-trash,
        .fa-ban,
        .fa-location-dot {
            color: #ef4444
        }

        .fa-cart-shopping,
        .fa-bag-shopping,
        .fa-store,
        .fa-shop {
            color: #e67e22
        }

        .fa-truck {
            color: #f97316
        }

        .fa-triangle-exclamation,
        .fa-circle-exclamation,
        .fa-clock,
        .fa-star {
            color: #f59e0b
        }

        .fa-info-circle,
        .fa-credit-card,
        .fa-mobile-screen,
        .fa-envelope,
        .fa-envelope-open,
        .fa-envelope-open-text,
        .fa-inbox,
        .fa-comment,
        .fa-map,
        .fa-paperclip {
            color: #3b82f6
        }

        .fa-sack-dollar,
        .fa-money-bill,
        .fa-money-bill-transfer {
            color: #16a34a
        }

        .fa-users,
        .fa-user,
        .fa-user-plus {
            color: #6366f1
        }

        .fa-box,
        .fa-box-open,
        .fa-boxes-stacked,
        .fa-warehouse,
        .fa-receipt,
        .fa-clipboard-list,
        .fa-file-lines {
            color: #8b5cf6
        }

        .fa-chart-bar,
        .fa-chart-line,
        .fa-chart-pie,
        .fa-gauge-high {
            color: #0ea5e9
        }

        .fa-heart {
            color: #ef4444
        }

        .fa-gear {
            color: #6b7280
        }

        .fa-lightbulb {
            color: #f59e0b
        }
    </style>
    <title>Products — Hiney's Admin</title>
    <style>
        :root {
            --card-border: #e5e7eb;
        }

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

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.02em;
        }

        .page-title-sub {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

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

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            flex-wrap: wrap;
        }

        .toolbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .toolbar-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark);
        }

        .count-pill {
            background: var(--primary);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 20px;
            letter-spacing: 0.03em;
        }

        .count-pill.archive {
            background: #6b7280;
        }

        .search-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-wrap svg {
            position: absolute;
            left: 10px;
            color: var(--text-muted);
            pointer-events: none;
        }

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

        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.12);
        }

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
        }

        .filter-select:focus {
            border-color: var(--primary);
            outline: none;
        }

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

        .btn-add:hover {
            background: #cf6d17;
            transform: translateY(-1px);
        }

        .btn-archive-view {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s;
        }

        .btn-archive-view:hover,
        .btn-archive-view.active {
            background: #f3f4f6;
            color: var(--dark);
            border-color: #d1d5db;
        }

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

        table.data-table tbody tr:nth-child(even) {
            background: #fef9f0;
        }

        table.data-table tbody tr:hover {
            background: #fdebd0;
            transition: background 0.15s;
        }

        table.data-table tbody td {
            padding: 11px 14px;
            color: var(--text);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }

        table.data-table tbody tr:last-child td {
            border-bottom: none;
        }

        table.data-table tbody tr.archived-row {
            opacity: 0.7;
        }

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

        .btn-edit {
            color: var(--primary);
            border-color: var(--primary);
        }

        .btn-edit:hover {
            background: var(--primary);
            color: #fff;
        }

        .btn-delete {
            color: #ef4444;
            border-color: #ef4444;
        }

        .btn-delete:hover {
            background: #ef4444;
            color: #fff;
        }

        .btn-restore {
            color: #10b981;
            border-color: #10b981;
        }

        .btn-restore:hover {
            background: #10b981;
            color: #fff;
        }

        .product-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .product-thumb {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            border: 1px solid #fddcb5;
            overflow: hidden;
            background: linear-gradient(135deg, #fef3e8, #fde9d0);
        }

        .product-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-name {
            font-weight: 600;
            color: var(--dark);
        }

        .product-desc {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 1px;
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-dot {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        .status-dot.active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-dot.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-dot.archived {
            background: #f3f4f6;
            color: #6b7280;
        }

        .status-dot::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .price-cell {
            font-weight: 700;
            color: var(--dark);
        }

        .unit-label {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        .stock-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .stock-num {
            font-weight: 700;
        }

        .stock-bar {
            width: 60px;
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
        }

        .stock-bar-fill {
            height: 100%;
            border-radius: 2px;
        }

        .empty-state {
            padding: 56px 20px;
            text-align: center;
            color: var(--text-muted);
        }

        .empty-icon {
            font-size: 3rem;
            margin-bottom: 12px;
        }

        .empty-text {
            font-size: 0.9rem;
        }

        .empty-sub {
            font-size: 0.8rem;
            margin-top: 4px;
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-top: 1px solid var(--card-border);
            font-size: 0.82rem;
            color: var(--text-muted);
            flex-wrap: wrap;
            gap: 8px;
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
            border-radius: 6px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text);
            font-size: 0.82rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s, border-color 0.15s;
        }

        .pg-btn:hover {
            background: var(--page-bg);
            border-color: #d1d5db;
        }

        .pg-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            font-weight: 700;
        }

        .pg-btn:disabled,
        .pg-btn.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* Flash auto-dismiss */
        .flash-msg {
            transition: opacity 0.5s ease;
        }

        /* Modals */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
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
            background: var(--card-bg);
            border-radius: 14px;
            width: 100%;
            max-width: 640px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2), 0 4px 16px rgba(0, 0, 0, 0.1);
            animation: modalSlide 0.22s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }

        .modal-card.sm {
            max-width: 460px;
        }

        @keyframes modalSlide {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.97)
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1)
            }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px 16px;
            border-bottom: 1px solid var(--card-border);
            position: sticky;
            top: 0;
            background: var(--card-bg);
            z-index: 1;
            border-radius: 14px 14px 0 0;
        }

        .modal-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: var(--page-bg);
            border-radius: 7px;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 1rem;
            transition: background 0.15s, color 0.15s;
        }

        .modal-close:hover {
            background: #fee2e2;
            color: #ef4444;
        }

        .modal-body {
            padding: 20px 24px;
        }

        .modal-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 24px;
            border-top: 1px solid var(--card-border);
            background: var(--page-bg);
            border-radius: 0 0 14px 14px;
            position: sticky;
            bottom: 0;
        }

        .form-section-label {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--primary);
            margin: 18px 0 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #fde9d0;
        }

        .form-section-label:first-child {
            margin-top: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group.span-2 {
            grid-column: span 2;
        }

        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-label .req {
            color: #ef4444;
            margin-left: 2px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            padding: 8px 12px;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            font-size: 0.87rem;
            color: var(--text);
            background: #fff;
            outline: none;
            font-family: inherit;
            width: 100%;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.12);
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-hint {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid;
            transition: background 0.15s, transform 0.1s;
            font-family: inherit;
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: #cf6d17;
            border-color: #cf6d17;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border-color: var(--card-border);
        }

        .btn-ghost:hover {
            background: var(--page-bg);
            color: var(--text);
        }

        .btn-danger {
            background: #ef4444;
            color: #fff;
            border-color: #ef4444;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        /* Image upload */
        .img-upload-wrap {
            border: 2px dashed var(--card-border);
            border-radius: 10px;
            padding: 16px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            position: relative;
            background: var(--page-bg);
            overflow: hidden;
        }

        .img-upload-wrap:hover,
        .img-upload-wrap.dragover {
            border-color: var(--primary);
            background: #fef3e8;
        }

        .img-upload-wrap input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
            z-index: 2;
        }

        .img-upload-wrap.has-image {
            padding: 0;
            border-color: var(--primary);
            border-style: solid;
        }

        .img-upload-wrap.has-image .upload-placeholder {
            display: none;
        }

        .upload-placeholder {
            pointer-events: none;
        }

        .img-preview-box {
            display: none;
            position: relative;
        }

        .img-preview-box.show {
            display: block;
        }

        .img-preview-box img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
            border-radius: 8px;
        }

        .img-preview-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.65), transparent);
            padding: 10px 12px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-radius: 0 0 8px 8px;
        }

        .img-preview-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .img-remove-btn {
            background: rgba(239, 68, 68, 0.9);
            border: none;
            color: #fff;
            width: 26px;
            height: 26px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .img-remove-btn:hover {
            background: #ef4444;
        }

        .upload-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid #e5e7eb;
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg)
            }
        }

        .upload-status {
            font-size: 0.75rem;
            margin-top: 6px;
        }

        .upload-status.ok {
            color: #10b981;
        }

        .upload-status.err {
            color: #ef4444;
        }

        /* Crop modal */
        .crop-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .crop-backdrop.open {
            display: flex;
        }

        .crop-modal {
            background: #1e1e2e;
            border-radius: 14px;
            width: 100%;
            max-width: 560px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.5);
            overflow: hidden;
        }

        .crop-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .crop-header-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #fff;
        }

        .crop-close {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .crop-close:hover {
            background: #ef4444;
        }

        .crop-canvas-wrap {
            position: relative;
            overflow: hidden;
            background: #111;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 340px;
            user-select: none;
        }

        #cropCanvas {
            max-width: 100%;
            max-height: 340px;
            display: block;
        }

        .crop-box {
            position: absolute;
            border: 2px solid var(--primary);
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
            cursor: move;
            box-sizing: border-box;
        }

        .crop-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: var(--primary);
            border-radius: 2px;
        }

        .crop-handle.tl {
            top: -5px;
            left: -5px;
            cursor: nw-resize
        }

        .crop-handle.tr {
            top: -5px;
            right: -5px;
            cursor: ne-resize
        }

        .crop-handle.bl {
            bottom: -5px;
            left: -5px;
            cursor: sw-resize
        }

        .crop-handle.br {
            bottom: -5px;
            right: -5px;
            cursor: se-resize
        }

        .crop-controls {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            flex-wrap: wrap;
        }

        .crop-ratio-btns {
            display: flex;
            gap: 6px;
        }

        .crop-ratio-btn {
            padding: 5px 12px;
            border-radius: 6px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: transparent;
            color: #ccc;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }

        .crop-ratio-btn.active,
        .crop-ratio-btn:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .crop-actions {
            display: flex;
            gap: 8px;
        }

        .btn-crop-cancel {
            padding: 7px 16px;
            border-radius: 7px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: transparent;
            color: #ccc;
            font-size: 0.83rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-crop-cancel:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-crop-apply {
            padding: 7px 16px;
            border-radius: 7px;
            border: none;
            background: var(--primary);
            color: #fff;
            font-size: 0.83rem;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
        }

        .btn-crop-apply:hover {
            background: #cf6d17;
        }

        .delete-icon-wrap {
            width: 60px;
            height: 60px;
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.8rem;
        }

        .archive-icon-wrap {
            width: 60px;
            height: 60px;
            background: #f3f4f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.8rem;
        }

        .delete-title {
            text-align: center;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
        }

        .delete-text {
            text-align: center;
            font-size: 0.88rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .delete-name {
            font-weight: 700;
            color: #ef4444;
        }

        .archive-name {
            font-weight: 700;
            color: #374151;
        }

        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            background: var(--card-bg);
            cursor: pointer;
            color: var(--dark);
            flex-shrink: 0;
        }

        @media(max-width:768px) {
            .main-content {
                margin-left: 0;
                padding: 16px 16px 48px;
                width: 100%;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.span-2 {
                grid-column: span 1;
            }

            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .toolbar-right {
                justify-content: flex-end;
            }
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
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="3" y1="6" x2="21" y2="6" />
                                <line x1="3" y1="12" x2="21" y2="12" />
                                <line x1="3" y1="18" x2="21" y2="18" />
                            </svg>
                        </button>
                        <h1 class="page-title"><?= $showArchive ? 'Archived Products' : 'Products' ?></h1>
                    </div>
                    <div class="page-title-sub"><?= $showArchive ? 'Products that have been archived — restore or permanently delete.' : 'Manage your product catalog — eggs and live chicken inventory' ?></div>
                </div>
            </div>

            <?= flash() ?>

            <div class="toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-title"><?= $showArchive ? 'Archive' : 'All Products' ?></span>
                    <span class="count-pill <?= $showArchive ? 'archive' : '' ?>"><?= number_format($totalCount) ?></span>
                    <?php if (!$showArchive): ?>
                        <form method="GET" style="display:contents;">
                            <div class="search-wrap">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8" />
                                    <line x1="21" y1="21" x2="16.65" y2="16.65" />
                                </svg>
                                <input type="text" name="q" class="search-input" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>">
                            </div>
                            <select name="cat" class="filter-select" onchange="this.form.submit()" id="catFilterSel">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="unit" class="filter-select" id="unitFilterSel" onchange="this.form.submit()" style="display:<?= $filterCat ? 'block' : 'none' ?>">
                                <option value="">All Units</option>
                                <?php
                                // Show units based on selected category
                                $unitOptions = [];
                                if ($filterCat) {
                                    $catName = '';
                                    foreach ($categories as $c) {
                                        if ($c['id'] == $filterCat) {
                                            $catName = strtolower($c['name']);
                                            break;
                                        }
                                    }
                                    if (str_contains($catName, 'egg'))     $unitOptions = ['per tray' => 'Per Tray', 'per piece' => 'Per Piece'];
                                    if (str_contains($catName, 'chicken')) $unitOptions = ['alive' => 'Alive Chicken', 'processed' => 'Processed Chicken'];
                                }
                                foreach ($unitOptions as $val => $label):
                                ?>
                                    <option value="<?= $val ?>" <?= $filterUnit === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($search || $filterCat || $filterUnit): ?>
                                <a href="products.php" style="font-size:0.8rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear</a>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="toolbar-right">
                    <?php if ($showArchive): ?>
                        <a href="products.php" class="btn-archive-view">← Back to Products</a>
                    <?php else: ?>
                        <a href="products.php?view=archive" class="btn-archive-view">
                            <i class="fa-solid fa-box-archive"></i> Archive
                        </a>
                        <button class="btn-add" onclick="openModal('addModal')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19" />
                                <line x1="5" y1="12" x2="19" y2="12" />
                            </svg>
                            Add Product
                        </button>
                    <?php endif; ?>
                </div>
            </div>

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
                                <th>Status</th>
                                <th style="text-align:center;width:<?= $showArchive ? '160px' : '130px' ?>;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rowNum = $offset + 1;
                            while ($p = $products->fetch_assoc()):
                                $isChicken  = stripos($p['category_name'] ?? '', 'chicken') !== false;
                                $emoji      = $isChicken ? '<i class="fa-solid fa-drumstick-bite"></i>' : '<i class="fa-solid fa-egg"></i>';
                                $stockPct   = $p['reorder_level'] > 0 ? min(100, round(($p['stock'] / max($p['reorder_level'] * 2, 1)) * 100)) : 100;
                                $stockColor = $p['stock'] <= 0 ? '#ef4444' : ($p['stock'] <= $p['reorder_level'] ? '#f59e0b' : '#10b981');
                            ?>
                                <tr <?= $showArchive ? 'class="archived-row"' : '' ?>>
                                    <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $rowNum++ ?></td>
                                    <td>
                                        <div class="product-cell">
                                            <div class="product-thumb">
                                                <?php if (!empty($p['image_url'])): ?>
                                                    <img src="<?= htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
                                                <?php else: ?>
                                                    <?= $emoji ?>
                                                <?php endif; ?>
                                            </div>
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
                                            <div class="stock-bar">
                                                <div class="stock-bar-fill" style="width:<?= $stockPct ?>%;background:<?= $stockColor ?>;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-dot <?= $showArchive ? 'archived' : ($p['is_active'] ? 'active' : 'inactive') ?>">
                                            <?= $showArchive ? 'Archived' : ($p['is_active'] ? 'Active' : 'Inactive') ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                            <?php if ($showArchive): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn-action btn-restore">
                                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="1 4 1 10 7 10" />
                                                            <path d="M3.51 15a9 9 0 1 0 .49-3.5" />
                                                        </svg>
                                                        Restore
                                                    </button>
                                                </form>
                                                <button class="btn-action btn-delete"
                                                    onclick="openHardDelete(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="3 6 5 6 21 6" />
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                                    </svg>
                                                    Delete
                                                </button>
                                            <?php else: ?>
                                                <button class="btn-action btn-edit"
                                                    onclick="openEdit(<?= htmlspecialchars(json_encode([
                                                                            'id'            => $p['id'],
                                                                            'category_id'   => $p['category_id'],
                                                                            'category_name' => strtolower($p['category_name'] ?? ''),
                                                                            'name'          => $p['name'],
                                                                            'description'   => $p['description'],
                                                                            'price'         => $p['price'],
                                                                            'unit'          => $p['unit'],
                                                                            'image_url'     => $p['image_url'],
                                                                            'is_active'     => $p['is_active'],
                                                                            'reorder_level' => $p['reorder_level'],
                                                                            'stock'         => $p['stock'],
                                                                        ]), ENT_QUOTES) ?>)">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                                    </svg>
                                                    Edit
                                                </button>
                                                <button class="btn-action btn-delete"
                                                    onclick="openArchive(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>')">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="21 8 21 21 3 21 3 8" />
                                                        <rect x="1" y="3" width="22" height="5" />
                                                        <line x1="10" y1="12" x2="14" y2="12" />
                                                    </svg>
                                                    Archive
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
                            <div>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?></div>
                            <div class="pagination-pages">
                                <?php
                                $qs = http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]));
                                echo "<a href='?{$qs}' class='pg-btn" . ($page <= 1 ? ' disabled' : '') . "'>← Prev</a>";
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $page + 2);
                                if ($start > 1) {
                                    $q1 = http_build_query(array_merge($_GET, ['page' => 1]));
                                    echo "<a href='?{$q1}' class='pg-btn'>1</a>";
                                    if ($start > 2) echo "<span class='pg-btn disabled'>…</span>";
                                }
                                for ($i = $start; $i <= $end; $i++) {
                                    $qi = http_build_query(array_merge($_GET, ['page' => $i]));
                                    echo "<a href='?{$qi}' class='pg-btn" . ($i == $page ? ' active' : '') . "'>{$i}</a>";
                                }
                                if ($end < $totalPages) {
                                    if ($end < $totalPages - 1) echo "<span class='pg-btn disabled'>…</span>";
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
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-<?= $showArchive ? 'box-archive' : 'box' ?>"></i></div>
                        <div class="empty-text"><?= $showArchive ? 'No archived products' : 'No products found' ?></div>
                        <div class="empty-sub"><?= $showArchive ? 'Archived products will appear here.' : 'Click "Add Product" to add your first product.' ?></div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- ADD MODAL -->
    <div class="modal-backdrop" id="addModal" onclick="backdropClose(event,'addModal')">
        <div class="modal-card" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Add New Product
                </div>
                <button class="modal-close" onclick="closeModal('addModal')">✕</button>
            </div>
            <form method="POST" action="products.php" id="addForm">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="image_url" id="add_image_url">
                <div class="modal-body">
                    <div class="form-section-label">Basic Information</div>
                    <div class="form-grid">
                        <div class="form-group span-2">
                            <label class="form-label">Product Name <span class="req">*</span></label>
                            <input type="text" name="name" class="form-input" placeholder="e.g. Egg Large, Chicken" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category <span class="req">*</span></label>
                            <select name="category_id" id="add_category_id" class="form-select" required onchange="updateUnits('add', this.value)">
                                <option value="">— Select Category —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" data-name="<?= strtolower(htmlspecialchars($cat['name'])) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unit <span class="req">*</span></label>
                            <select name="unit" id="add_unit" class="form-select" required>
                                <option value="">— Select Category first —</option>
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
                    <div class="form-section-label">Product Image</div>
                    <div class="form-group span-2">
                        <div class="img-upload-wrap" id="add_upload_wrap" ondragover="this.classList.add('dragover');event.preventDefault();" ondragleave="this.classList.remove('dragover');" ondrop="handleDrop(event,'add')">
                            <input type="file" accept="image/*" onchange="uploadToCloudinary(this,'add')">
                            <div class="upload-placeholder">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);margin:0 auto 6px;display:block;">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="17 8 12 3 7 8" />
                                    <line x1="12" y1="3" x2="12" y2="15" />
                                </svg>
                                <div style="font-size:0.82rem;font-weight:600;color:var(--text-muted);">Click or drag image here</div>
                                <div style="font-size:0.72rem;color:#b0b7c0;margin-top:2px;">JPG, PNG, WEBP — max 5MB</div>
                                <div class="upload-spinner" id="add_spinner" style="margin-top:8px;"></div>
                            </div>
                            <div class="img-preview-box" id="add_preview">
                                <img src="" id="add_preview_img" alt="Preview">
                                <div class="img-preview-overlay">
                                    <span class="img-preview-label">✓ Image uploaded</span>
                                    <button type="button" class="img-remove-btn" onclick="removeImage('add')">✕</button>
                                </div>
                            </div>
                        </div>
                        <div class="upload-status" id="add_upload_status"></div>
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
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        Save Product
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal-backdrop" id="editModal" onclick="backdropClose(event,'editModal')">
        <div class="modal-card" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                    </svg>
                    Edit Product
                </div>
                <button class="modal-close" onclick="closeModal('editModal')">✕</button>
            </div>
            <form method="POST" action="products.php" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="image_url" id="edit_image_url">
                <div class="modal-body">
                    <div class="form-section-label">Basic Information</div>
                    <div class="form-grid">
                        <div class="form-group span-2">
                            <label class="form-label">Product Name <span class="req">*</span></label>
                            <input type="text" name="name" id="edit_name" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category <span class="req">*</span></label>
                            <select name="category_id" id="edit_category_id" class="form-select" required onchange="updateUnits('edit', this.value)">
                                <option value="">— Select Category —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" data-name="<?= strtolower(htmlspecialchars($cat['name'])) ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Unit <span class="req">*</span></label>
                            <select name="unit" id="edit_unit" class="form-select" required></select>
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
                    <div class="form-section-label">Product Image</div>
                    <div class="form-group span-2">
                        <div class="img-upload-wrap" id="edit_upload_wrap" ondragover="this.classList.add('dragover');event.preventDefault();" ondragleave="this.classList.remove('dragover');" ondrop="handleDrop(event,'edit')">
                            <input type="file" accept="image/*" onchange="uploadToCloudinary(this,'edit')">
                            <div class="upload-placeholder">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);margin:0 auto 6px;display:block;">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="17 8 12 3 7 8" />
                                    <line x1="12" y1="3" x2="12" y2="15" />
                                </svg>
                                <div style="font-size:0.82rem;font-weight:600;color:var(--text-muted);">Click or drag to replace image</div>
                                <div style="font-size:0.72rem;color:#b0b7c0;margin-top:2px;">JPG, PNG, WEBP — max 5MB</div>
                                <div class="upload-spinner" id="edit_spinner" style="margin-top:8px;"></div>
                            </div>
                            <div class="img-preview-box" id="edit_preview">
                                <img src="" id="edit_preview_img" alt="Preview">
                                <div class="img-preview-overlay">
                                    <span class="img-preview-label">✓ Image ready</span>
                                    <button type="button" class="img-remove-btn" onclick="removeImage('edit')">✕</button>
                                </div>
                            </div>
                        </div>
                        <div class="upload-status" id="edit_upload_status"></div>
                    </div>
                    <div class="form-section-label">Inventory</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Current Stock</label>
                            <input type="number" name="stock_qty" id="edit_stock_qty" class="form-input" min="0">
                            <span class="form-hint">Changing this logs a stock adjustment automatically</span>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" id="edit_reorder_level" class="form-input" min="0">
                            <span class="form-hint">Alert when stock falls below this</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg>
                        Update Product
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ARCHIVE MODAL -->
    <div class="modal-backdrop" id="archiveModal" onclick="backdropClose(event,'archiveModal')">
        <div class="modal-card sm" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="21 8 21 21 3 21 3 8" />
                        <rect x="1" y="3" width="22" height="5" />
                        <line x1="10" y1="12" x2="14" y2="12" />
                    </svg>
                    Archive Product
                </div>
                <button class="modal-close" onclick="closeModal('archiveModal')">✕</button>
            </div>
            <form method="POST" action="products.php">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="id" id="archive_id">
                <div class="modal-body">
                    <div class="archive-icon-wrap"><i class="fa-solid fa-box-archive" style="color:#6b7280;"></i></div>
                    <div class="delete-title">Archive Product?</div>
                    <div class="delete-text">
                        <span class="archive-name" id="archive_name"></span> will be hidden from customers and moved to the archive.<br><br>
                        You can restore it anytime from the Archive section.
                    </div>
                    <div style="margin-top:16px;">
                        <label style="font-size:0.8rem;font-weight:600;color:var(--dark);display:block;margin-bottom:5px;">Reason (optional)</label>
                        <input type="text" name="archive_reason" class="form-input" placeholder="e.g. Out of season, discontinued…" style="width:100%;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('archiveModal')">Cancel</button>
                    <button type="submit" class="btn" style="background:#6b7280;color:#fff;border-color:#6b7280;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="21 8 21 21 3 21 3 8" />
                            <rect x="1" y="3" width="22" height="5" />
                        </svg>
                        Archive
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- HARD DELETE MODAL -->
    <div class="modal-backdrop" id="hardDeleteModal" onclick="backdropClose(event,'hardDeleteModal')">
        <div class="modal-card sm" role="dialog" aria-modal="true">
            <div class="modal-header">
                <div class="modal-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <line x1="12" y1="9" x2="12" y2="13" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg>
                    <span style="color:#ef4444;">Permanently Delete</span>
                </div>
                <button class="modal-close" onclick="closeModal('hardDeleteModal')">✕</button>
            </div>
            <form method="POST" action="products.php">
                <input type="hidden" name="action" value="delete_hard">
                <input type="hidden" name="id" id="hard_delete_id">
                <div class="modal-body">
                    <div class="delete-icon-wrap"><i class="fa-solid fa-trash"></i></div>
                    <div class="delete-title">Permanently Delete?</div>
                    <div class="delete-text">
                        <span class="delete-name" id="hard_delete_name"></span> will be permanently removed along with all its inventory and log records.<br><br>
                        <strong style="color:#ef4444;">This cannot be undone.</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('hardDeleteModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6" />
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                        </svg>
                        Delete Forever
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php require_once '../config/cloudinary.php'; ?>

    <!-- CROP MODAL -->
    <div class="crop-backdrop" id="cropBackdrop">
        <div class="crop-modal">
            <div class="crop-header">
                <div class="crop-header-title">✂️ Crop Image</div>
                <button class="crop-close" onclick="cancelCrop()">✕</button>
            </div>
            <div class="crop-canvas-wrap" id="cropCanvasWrap">
                <canvas id="cropCanvas"></canvas>
                <div class="crop-box" id="cropBox">
                    <div class="crop-handle tl" data-handle="tl"></div>
                    <div class="crop-handle tr" data-handle="tr"></div>
                    <div class="crop-handle bl" data-handle="bl"></div>
                    <div class="crop-handle br" data-handle="br"></div>
                </div>
            </div>
            <div class="crop-controls">
                <div class="crop-ratio-btns">
                    <button class="crop-ratio-btn active" onclick="setRatio(1,1,this)">1:1</button>
                    <button class="crop-ratio-btn" onclick="setRatio(4,3,this)">4:3</button>
                    <button class="crop-ratio-btn" onclick="setRatio(16,9,this)">16:9</button>
                    <button class="crop-ratio-btn" onclick="setRatio(0,0,this)">Free</button>
                </div>
                <div class="crop-actions">
                    <button class="btn-crop-cancel" onclick="cancelCrop()">Cancel</button>
                    <button class="btn-crop-apply" onclick="applyCrop()">✓ Apply & Upload</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const CLOUDINARY_CLOUD_NAME = '<?= CLOUDINARY_CLOUD_NAME ?>';
        const CLOUDINARY_UPLOAD_PRESET = '<?= CLOUDINARY_UPLOAD_PRESET ?>';

        // ── Category filter → show/hide unit filter ──────────────────
        const catFilterSel = document.getElementById('catFilterSel');
        const unitFilterSel = document.getElementById('unitFilterSel');
        if (catFilterSel && unitFilterSel) {
            catFilterSel.addEventListener('change', function() {
                // Just submit — PHP will re-render with correct unit options
                // Unit filter visibility is handled server-side
            });
        }

        // ── Auto-dismiss flash messages (1.5s) ───────────────────────
        function autoDismiss(el) {
            if (!el) return;
            setTimeout(() => {
                el.style.transition = 'opacity 0.4s ease';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 420);
            }, 1500);
        }
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.flash-msg, .flash-success, .flash-error, .alert, .alert-success, .alert-error').forEach(autoDismiss);
            document.querySelectorAll('div[style]').forEach(el => {
                const s = el.getAttribute('style') || '';
                if (s.includes('#d1fae5') || s.includes('#fee2e2') || s.includes('#fef3c7')) autoDismiss(el);
            });
        });

        // ── Units per category ───────────────────────────────────────
        const UNIT_MAP = {
            egg: [{
                val: 'per tray',
                label: 'Per Tray'
            }, {
                val: 'per piece',
                label: 'Per Piece'
            }],
            chicken: [{
                val: 'alive',
                label: 'Alive Chicken'
            }, {
                val: 'processed',
                label: 'Processed Chicken'
            }],
        };

        function getCatType(catSelectId) {
            const sel = document.getElementById(catSelectId);
            if (!sel) return null;
            const opt = sel.options[sel.selectedIndex];
            if (!opt) return null;
            const name = (opt.dataset.name || '').toLowerCase();
            if (name.includes('egg')) return 'egg';
            if (name.includes('chicken')) return 'chicken';
            return null;
        }

        function updateUnits(prefix, catId) {
            const unitSel = document.getElementById(prefix + '_unit');
            if (!unitSel) return;
            const catType = getCatType(prefix + '_category_id');
            const units = catType ? UNIT_MAP[catType] : [];
            unitSel.innerHTML = units.length ?
                units.map(u => `<option value="${u.val}">${u.label}</option>`).join('') :
                '<option value="">— Select Category first —</option>';
        }

        // ── Modal helpers ────────────────────────────────────────────
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
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(m => closeModal(m.id));
        });

        function openArchive(id, name) {
            document.getElementById('archive_id').value = id;
            document.getElementById('archive_name').textContent = name;
            openModal('archiveModal');
        }

        function openHardDelete(id, name) {
            document.getElementById('hard_delete_id').value = id;
            document.getElementById('hard_delete_name').textContent = name;
            openModal('hardDeleteModal');
        }

        function openEdit(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_name').value = data.name;
            document.getElementById('edit_description').value = data.description || '';
            document.getElementById('edit_price').value = data.price;
            document.getElementById('edit_reorder_level').value = data.reorder_level;
            document.getElementById('edit_stock_qty').value = data.stock;
            document.getElementById('edit_image_url').value = data.image_url || '';
            document.getElementById('edit_is_active').value = data.is_active;
            const catSel = document.getElementById('edit_category_id');
            catSel.value = data.category_id;
            updateUnits('edit', data.category_id);
            const unitSel = document.getElementById('edit_unit');
            for (let opt of unitSel.options) {
                if (opt.value === data.unit) {
                    opt.selected = true;
                    break;
                }
            }
            const prev = document.getElementById('edit_preview');
            const prevImg = document.getElementById('edit_preview_img');
            if (data.image_url) {
                prevImg.src = data.image_url;
                prev.classList.add('show');
                document.getElementById('edit_upload_wrap').classList.add('has-image');
            } else {
                prevImg.src = '';
                prev.classList.remove('show');
                document.getElementById('edit_upload_wrap').classList.remove('has-image');
            }
            document.getElementById('edit_upload_status').textContent = '';
            openModal('editModal');
        }

        // ── Image upload ─────────────────────────────────────────────
        async function uploadToCloudinary(input, prefix) {
            const file = input.files[0];
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                setUploadStatus(prefix, 'File too large. Max 5MB.', 'err');
                return;
            }
            openCrop(file, prefix);
        }

        function handleDrop(e, prefix) {
            e.preventDefault();
            document.getElementById(prefix + '_upload_wrap').classList.remove('dragover');
            const file = e.dataTransfer.files[0];
            if (file) openCrop(file, prefix);
        }

        function removeImage(prefix) {
            document.getElementById(prefix + '_image_url').value = '';
            document.getElementById(prefix + '_preview_img').src = '';
            document.getElementById(prefix + '_preview').classList.remove('show');
            document.getElementById(prefix + '_upload_wrap').classList.remove('has-image');
            setUploadStatus(prefix, '', '');
        }

        function showSpinner(prefix, show) {
            const s = document.getElementById(prefix + '_spinner');
            if (s) s.style.display = show ? 'block' : 'none';
        }

        function setUploadStatus(prefix, msg, type) {
            const el = document.getElementById(prefix + '_upload_status');
            if (!el) return;
            el.textContent = msg;
            el.className = 'upload-status' + (type ? ' ' + type : '');
        }

        // ── Crop logic ───────────────────────────────────────────────
        let _cropPrefix = null,
            _cropImg = new Image(),
            _cropRatioW = 1,
            _cropRatioH = 1;
        let _cropBox = {
            x: 0,
            y: 0,
            w: 0,
            h: 0
        };
        let _dragging = false,
            _resizing = false,
            _handle = null;
        let _dragStart = {
            x: 0,
            y: 0,
            bx: 0,
            by: 0
        };

        function openCrop(file, prefix) {
            _cropPrefix = prefix;
            const reader = new FileReader();
            reader.onload = e => {
                _cropImg.onload = () => {
                    const canvas = document.getElementById('cropCanvas');
                    const wrap = document.getElementById('cropCanvasWrap');
                    const maxW = wrap.clientWidth || 520,
                        maxH = 340;
                    const scale = Math.min(maxW / _cropImg.naturalWidth, maxH / _cropImg.naturalHeight, 1);
                    canvas.width = Math.round(_cropImg.naturalWidth * scale);
                    canvas.height = Math.round(_cropImg.naturalHeight * scale);
                    canvas.getContext('2d').drawImage(_cropImg, 0, 0, canvas.width, canvas.height);
                    const side = Math.min(canvas.width, canvas.height) * 0.8;
                    _cropBox = {
                        x: (canvas.width - side) / 2,
                        y: (canvas.height - side) / 2,
                        w: side,
                        h: side
                    };
                    document.querySelectorAll('.crop-ratio-btn').forEach(b => b.classList.remove('active'));
                    document.querySelector('.crop-ratio-btn').classList.add('active');
                    _cropRatioW = 1;
                    _cropRatioH = 1;
                    updateCropBox();
                    document.getElementById('cropBackdrop').classList.add('open');
                };
                _cropImg.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        function updateCropBox() {
            const canvas = document.getElementById('cropCanvas');
            const box = document.getElementById('cropBox');
            const wrap = document.getElementById('cropCanvasWrap');
            const cr = canvas.getBoundingClientRect(),
                wr = wrap.getBoundingClientRect();
            const offX = cr.left - wr.left,
                offY = cr.top - wr.top;
            _cropBox.x = Math.max(0, Math.min(_cropBox.x, canvas.width - _cropBox.w));
            _cropBox.y = Math.max(0, Math.min(_cropBox.y, canvas.height - _cropBox.h));
            _cropBox.w = Math.max(30, Math.min(_cropBox.w, canvas.width - _cropBox.x));
            _cropBox.h = Math.max(30, Math.min(_cropBox.h, canvas.height - _cropBox.y));
            const scaleX = cr.width / canvas.width,
                scaleY = cr.height / canvas.height;
            box.style.left = (offX + _cropBox.x * scaleX) + 'px';
            box.style.top = (offY + _cropBox.y * scaleY) + 'px';
            box.style.width = (_cropBox.w * scaleX) + 'px';
            box.style.height = (_cropBox.h * scaleY) + 'px';
        }

        function setRatio(w, h, btn) {
            _cropRatioW = w;
            _cropRatioH = h;
            document.querySelectorAll('.crop-ratio-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            if (w && h) {
                const canvas = document.getElementById('cropCanvas');
                const maxW = Math.min(_cropBox.w, canvas.width - _cropBox.x);
                const newH = maxW * (h / w);
                if (_cropBox.y + newH <= canvas.height) {
                    _cropBox.w = maxW;
                    _cropBox.h = newH;
                } else {
                    _cropBox.h = canvas.height - _cropBox.y;
                    _cropBox.w = _cropBox.h * (w / h);
                }
                updateCropBox();
            }
        }

        const cropWrap = document.getElementById('cropCanvasWrap');
        cropWrap.addEventListener('mousedown', e => {
            const box = document.getElementById('cropBox');
            const canvas = document.getElementById('cropCanvas');
            const cr = canvas.getBoundingClientRect();
            const scaleX = canvas.width / cr.width,
                scaleY = canvas.height / cr.height;
            if (e.target.dataset.handle) {
                _resizing = true;
                _handle = e.target.dataset.handle;
            } else if (e.target === box || box.contains(e.target)) {
                _dragging = true;
            } else return;
            _dragStart = {
                x: e.clientX,
                y: e.clientY,
                bx: _cropBox.x,
                by: _cropBox.y,
                bw: _cropBox.w,
                bh: _cropBox.h
            };
            e.preventDefault();
        });
        window.addEventListener('mousemove', e => {
            if (!_dragging && !_resizing) return;
            const canvas = document.getElementById('cropCanvas');
            const cr = canvas.getBoundingClientRect();
            const scaleX = canvas.width / cr.width,
                scaleY = canvas.height / cr.height;
            const dx = (e.clientX - _dragStart.x) * scaleX,
                dy = (e.clientY - _dragStart.y) * scaleY;
            if (_dragging) {
                _cropBox.x = _dragStart.bx + dx;
                _cropBox.y = _dragStart.by + dy;
            } else if (_resizing) {
                let {
                    bx,
                    by,
                    bw,
                    bh
                } = _dragStart;
                if (_handle.includes('r')) bw = Math.max(30, bw + dx);
                if (_handle.includes('l')) {
                    bw = Math.max(30, bw - dx);
                    bx = _dragStart.bx + (_dragStart.bw - bw);
                }
                if (_handle.includes('b')) bh = Math.max(30, bh + dy);
                if (_handle.includes('t')) {
                    bh = Math.max(30, bh - dy);
                    by = _dragStart.by + (_dragStart.bh - bh);
                }
                if (_cropRatioW && _cropRatioH) {
                    if (_handle.includes('r') || _handle.includes('l')) bh = bw * (_cropRatioH / _cropRatioW);
                    else bw = bh * (_cropRatioW / _cropRatioH);
                }
                _cropBox = {
                    x: bx,
                    y: by,
                    w: bw,
                    h: bh
                };
            }
            updateCropBox();
        });
        window.addEventListener('mouseup', () => {
            _dragging = false;
            _resizing = false;
        });

        function cancelCrop() {
            document.getElementById('cropBackdrop').classList.remove('open');
            _cropPrefix = null;
        }

        async function applyCrop() {
            const canvas = document.getElementById('cropCanvas');
            const out = document.createElement('canvas');
            const size = 800;
            out.width = size;
            out.height = _cropRatioW && _cropRatioH ? size * (_cropRatioH / _cropRatioW) : Math.round(_cropBox.h * (size / _cropBox.w));
            const ctx = out.getContext('2d');
            const sx = _cropImg.naturalWidth / canvas.width,
                sy = _cropImg.naturalHeight / canvas.height;
            ctx.drawImage(_cropImg, _cropBox.x * sx, _cropBox.y * sy, _cropBox.w * sx, _cropBox.h * sy, 0, 0, out.width, out.height);
            document.getElementById('cropBackdrop').classList.remove('open');
            const prefix = _cropPrefix;
            showSpinner(prefix, true);
            setUploadStatus(prefix, 'Uploading cropped image…', '');
            out.toBlob(async blob => {
                const fd = new FormData();
                fd.append('file', blob, 'product.jpg');
                fd.append('upload_preset', CLOUDINARY_UPLOAD_PRESET);
                try {
                    const res = await fetch(`https://api.cloudinary.com/v1_1/${CLOUDINARY_CLOUD_NAME}/image/upload`, {
                        method: 'POST',
                        body: fd
                    });
                    const data = await res.json();
                    if (data.secure_url) {
                        document.getElementById(prefix + '_image_url').value = data.secure_url;
                        document.getElementById(prefix + '_preview_img').src = data.secure_url;
                        document.getElementById(prefix + '_preview').classList.add('show');
                        document.getElementById(prefix + '_upload_wrap').classList.add('has-image');
                        setUploadStatus(prefix, '✓ Uploaded successfully', 'ok');
                        setTimeout(() => setUploadStatus(prefix, '', ''), 3000);
                    } else {
                        setUploadStatus(prefix, data.error?.message || 'Upload failed.', 'err');
                    }
                } catch {
                    setUploadStatus(prefix, 'Network error. Try again.', 'err');
                } finally {
                    showSpinner(prefix, false);
                }
            }, 'image/jpeg', 0.92);
        }
        window.addEventListener('resize', () => {
            if (document.getElementById('cropBackdrop').classList.contains('open')) updateCropBox();
        });

        // Reset add modal
        document.querySelector('[onclick="openModal(\'addModal\')"]')?.addEventListener('click', () => {
            document.getElementById('addForm').reset();
            document.getElementById('add_image_url').value = '';
            document.getElementById('add_preview').classList.remove('show');
            document.getElementById('add_preview_img').src = '';
            document.getElementById('add_upload_status').textContent = '';
            document.getElementById('add_unit').innerHTML = '<option value="">— Select Category first —</option>';
            document.getElementById('add_upload_wrap').classList.remove('has-image');
        });
    </script>
</body>

</html>