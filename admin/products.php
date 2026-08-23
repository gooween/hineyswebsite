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
        $reorder_lvl = (int)($_POST['reorder_level'] ?? 10);

        if ($name && $cat_id && $price > 0) {
            $conn->query("INSERT INTO products (category_id, name, description, price, unit, image_url, is_active, created_at)
                          VALUES ({$cat_id}, '{$name}', '{$desc}', {$price}, '{$unit}', '{$image_url}', {$is_active}, NOW())");
            $new_pid = (int)$conn->insert_id;
            if ($new_pid) {
                $conn->query("INSERT INTO inventory (product_id, quantity, reorder_level, last_updated, notes)
                              VALUES ({$new_pid}, 0, {$reorder_lvl}, NOW(), 'Initial stock on product creation')
                              ON DUPLICATE KEY UPDATE quantity=quantity");

                // ── Price Maintenance: seed the price history with the starting price ──
                $uid = (int)($_SESSION['user_id'] ?? 0);
                $seedStmt = $conn->prepare("INSERT INTO price_history (product_id, old_price, new_price, changed_by, reason) VALUES (?, ?, ?, ?, 'Initial price on product creation')");
                $seedStmt->bind_param('iddi', $new_pid, $price, $price, $uid);
                $seedStmt->execute();
                $seedStmt->close();
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
        $priceReason = trim($_POST['price_reason'] ?? '');

        if ($id && $name && $cat_id && $price > 0) {

            // ── Price Maintenance: capture the price BEFORE it changes ──
            $oldPrice = null;
            $oldRes = $conn->query("SELECT price FROM products WHERE id = {$id} LIMIT 1");
            if ($oldRes && $oldRow = $oldRes->fetch_assoc()) {
                $oldPrice = (float)$oldRow['price'];
            }

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
            // Stock is managed via Stock Batches — not editable here

            // ── Price Maintenance: log the change if the price actually moved ──
            if ($oldPrice !== null && abs($oldPrice - $price) > 0.0001) {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                $reasonToStore = $priceReason !== '' ? $priceReason : null;
                $logStmt = $conn->prepare("INSERT INTO price_history (product_id, old_price, new_price, changed_by, reason) VALUES (?, ?, ?, ?, ?)");
                $logStmt->bind_param('iddis', $id, $oldPrice, $price, $uid, $reasonToStore);
                $logStmt->execute();
                $logStmt->close();
            }

            redirect('products.php', 'success', 'Product updated successfully.');
        } else {
            redirect('products.php', 'error', 'Please fill in all required fields.');
        }
    }

    if ($action === 'archive') {
        $id     = (int)($_POST['id'] ?? 0);
        $reason = clean($_POST['archive_reason'] ?? 'No reason provided', $conn);
        $uid    = (int)$_SESSION['user_id'];
        if ($id) {
            $conn->query("UPDATE products SET is_active = 0 WHERE id = {$id}");
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

    if ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("UPDATE products SET is_active = 1 WHERE id = {$id}");
            redirect('products.php', 'success', 'Product restored successfully.');
        } else {
            redirect('products.php', 'error', 'Invalid product.');
        }
    }

    if ($action === 'delete_hard') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->query("DELETE FROM product_archive_log WHERE product_id = {$id}");
            $conn->query("DELETE FROM inventory_logs WHERE product_id = {$id}");
            $conn->query("DELETE FROM inventory WHERE product_id = {$id}");
            // price_history rows cascade-delete automatically (FK ON DELETE CASCADE)
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
$showArchive  = isset($_GET['view']) && $_GET['view'] === 'archive';
$offset       = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($showArchive) {
    $where .= " AND p.is_active = 0";
} else {
    if ($filterStatus === 'inactive') $where .= " AND p.is_active = 0";
    else $where .= " AND p.is_active = 1";
}
if ($search)     $where .= " AND (p.name LIKE '%{$conn->real_escape_string($search)}%' OR p.description LIKE '%{$conn->real_escape_string($search)}%')";
if ($filterCat)  $where .= " AND p.category_id = {$filterCat}";
if ($filterUnit) $where .= " AND p.unit = '{$conn->real_escape_string($filterUnit)}'";

$totalResult = $conn->query("SELECT COUNT(*) AS cnt FROM products p LEFT JOIN categories c ON c.id = p.category_id {$where}");
$totalCount  = (int)($totalResult->fetch_assoc()['cnt'] ?? 0);
$totalPages  = max(1, (int)ceil($totalCount / $perPage));

$products = $conn->query("
    SELECT p.*, c.name AS category_name,
           COALESCE((
               SELECT SUM(sb.remaining)
               FROM stock_batches sb
               WHERE sb.product_id = p.id AND sb.status = 'active'
           ), 0) AS stock,
           COALESCE(i.reorder_level, 10) AS reorder_level,
           (
               SELECT ph.old_price
               FROM price_history ph
               WHERE ph.product_id = p.id
               ORDER BY ph.changed_at DESC, ph.id DESC
               LIMIT 1
           ) AS last_old_price,
           (
               SELECT ph.changed_at
               FROM price_history ph
               WHERE ph.product_id = p.id
               ORDER BY ph.changed_at DESC, ph.id DESC
               LIMIT 1
           ) AS last_price_change_at,
           (
               SELECT COUNT(*) FROM price_history ph WHERE ph.product_id = p.id
           ) AS price_change_count
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
        /* Page-specific styles only — shared system comes from admin.css */

        /* Toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            gap: var(--s3);
            flex-wrap: wrap;
            margin-bottom: var(--s4);
        }

        .toolbar-count {
            display: inline-flex;
            align-items: center;
            gap: var(--s2);
            font-size: var(--fs-h3);
            font-weight: var(--fw-semi);
            color: var(--ink);
        }

        .count-pill {
            background: var(--brand-tint-2);
            color: var(--brand-strong);
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            padding: 2px 10px;
            border-radius: var(--r-pill);
        }

        .count-pill.archive {
            background: #f0eee9;
            color: var(--ink-2);
        }

        .toolbar-spacer {
            flex: 1;
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
            width: 210px;
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

        .select-field {
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

        .select-field:focus {
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

        /* Product cell */
        .prod-thumb {
            width: 44px;
            height: 44px;
            border-radius: var(--r-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
            overflow: hidden;
            border: 1px solid var(--brand-tint-2);
            background: linear-gradient(135deg, var(--brand-tint), var(--brand-tint-2));
        }

        .prod-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .prod-desc {
            font-size: var(--fs-xs);
            color: var(--ink-3);
            margin-top: 1px;
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cat-tag {
            background: #f0eee9;
            color: var(--ink-2);
            padding: 2px 9px;
            border-radius: var(--r-sm);
            font-size: var(--fs-xs);
            font-weight: var(--fw-med);
        }

        .price-strong {
            font-weight: var(--fw-bold);
            color: var(--ink);
        }

        .unit-label {
            font-size: var(--fs-xs);
            color: var(--ink-3);
            font-weight: var(--fw-normal);
        }

        .price-move {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            font-size: 0.66rem;
            font-weight: var(--fw-bold);
            padding: 1px 6px;
            border-radius: var(--r-pill);
            margin-left: 5px;
            vertical-align: middle;
        }

        .price-move.up {
            background: var(--danger-tint);
            color: #b23c34;
        }

        .price-move.down {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .price-count {
            display: block;
            font-size: 0.66rem;
            color: var(--ink-3);
            margin-top: 2px;
        }

        /* Stock cell */
        .stock-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .stock-num {
            font-weight: var(--fw-bold);
            font-variant-numeric: tabular-nums;
        }

        .stock-bar {
            width: 62px;
            height: 4px;
            background: var(--line);
            border-radius: 2px;
            overflow: hidden;
        }

        .stock-bar-fill {
            height: 100%;
            border-radius: 2px;
        }

        /* Row action buttons — icon by default, expand to show label on hover */
        .row-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .act {
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

        .act svg {
            flex-shrink: 0;
        }

        /* Label sits next to the icon but is collapsed (zero width) until hover */
        .act .act-label {
            max-width: 0;
            opacity: 0;
            margin-left: 0;
            transition: max-width 0.22s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.18s, margin-left 0.22s;
        }

        .act:hover {
            width: auto;
            padding: 0 11px;
        }

        .act:hover .act-label {
            max-width: 90px;
            opacity: 1;
            margin-left: 5px;
        }

        .act-edit {
            color: var(--brand);
            border-color: var(--brand);
        }

        .act-edit:hover {
            background: var(--brand);
            color: #fff;
        }

        .act-history {
            color: #7c5cd0;
            border-color: #c9bbee;
        }

        .act-history:hover {
            background: #7c5cd0;
            color: #fff;
            border-color: #7c5cd0;
        }

        .act-archive {
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .act-archive:hover {
            background: var(--ink-2);
            color: #fff;
            border-color: var(--ink-2);
        }

        .act-restore {
            color: var(--ok);
            border-color: #a7dcbc;
        }

        .act-restore:hover {
            background: var(--ok);
            color: #fff;
            border-color: var(--ok);
        }

        .act-delete {
            color: var(--danger);
            border-color: #f0c4c0;
        }

        .act-delete:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
        }

        .archived-row {
            opacity: 0.72;
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

        .pg-pages {
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

        /* Modals (shared shell, page-tuned) */
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
            max-width: 640px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: var(--shadow-md);
            animation: modalIn 0.22s cubic-bezier(0.34, 1.4, 0.64, 1) both;
        }

        .modal-card.sm {
            max-width: 440px;
        }

        .modal-card.md {
            max-width: 560px;
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

        .modal-head {
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

        .modal-heading {
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: var(--s2);
        }

        .modal-x {
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

        .modal-x:hover {
            background: var(--danger-tint);
            color: var(--danger);
        }

        .modal-body {
            padding: var(--s5) var(--s6);
        }

        .modal-foot {
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

        .form-section {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.09em;
            color: var(--brand);
            margin: var(--s5) 0 var(--s3);
            padding-bottom: var(--s2);
            border-bottom: 1px solid var(--brand-tint-2);
        }

        .form-section:first-child {
            margin-top: 0;
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
            grid-column: span 2;
        }

        .form-label {
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            color: var(--ink);
        }

        .form-label .req {
            color: var(--danger);
            margin-left: 2px;
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
            min-height: 80px;
        }

        .form-hint {
            font-size: var(--fs-xs);
            color: var(--ink-3);
            margin-top: 2px;
        }

        .price-reason-box {
            display: none;
            background: var(--brand-tint);
            border: 1px solid var(--brand-tint-2);
            border-radius: var(--r-sm);
            padding: var(--s3) var(--s4);
            margin-top: 4px;
        }

        .price-reason-box.show {
            display: block;
        }

        .price-reason-note {
            font-size: var(--fs-sm);
            color: var(--brand-strong);
            margin-bottom: var(--s2);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stock-info {
            background: var(--ok-tint);
            border: 1px solid #a7dcbc;
            border-radius: var(--r-sm);
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: var(--s2);
            font-size: var(--fs-sm);
            color: #1f7a48;
        }

        .stock-info a {
            color: var(--ok);
            font-weight: var(--fw-bold);
        }

        .stock-info a:hover {
            text-decoration: underline;
        }

        /* Image upload */
        .img-upload {
            border: 2px dashed var(--line-strong);
            border-radius: var(--r);
            padding: var(--s4);
            text-align: center;
            cursor: pointer;
            position: relative;
            background: var(--surface-2);
            overflow: hidden;
            transition: border-color 0.2s, background 0.2s;
        }

        .img-upload:hover,
        .img-upload.dragover {
            border-color: var(--brand);
            background: var(--brand-tint);
        }

        .img-upload input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
            z-index: 2;
        }

        .img-upload.has-image {
            padding: 0;
            border-color: var(--brand);
            border-style: solid;
        }

        .img-upload.has-image .upload-ph {
            display: none;
        }

        .upload-ph {
            pointer-events: none;
        }

        .img-preview {
            display: none;
            position: relative;
        }

        .img-preview.show {
            display: block;
        }

        .img-preview img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            display: block;
            border-radius: var(--r-sm);
        }

        .img-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(35, 32, 28, 0.65), transparent);
            padding: 10px 12px 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-radius: 0 0 var(--r-sm) var(--r-sm);
        }

        .img-overlay-label {
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            color: #fff;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .img-remove {
            background: rgba(217, 79, 70, 0.9);
            border: none;
            color: #fff;
            width: 26px;
            height: 26px;
            border-radius: var(--r-sm);
            cursor: pointer;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .img-remove:hover {
            background: var(--danger);
        }

        .upload-spinner {
            display: none;
            width: 20px;
            height: 20px;
            margin: 8px auto 0;
            border: 2px solid var(--line);
            border-top-color: var(--brand);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .upload-status {
            font-size: var(--fs-xs);
            margin-top: 6px;
        }

        .upload-status.ok {
            color: var(--ok);
        }

        .upload-status.err {
            color: var(--danger);
        }

        /* Crop modal */
        .crop-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(35, 32, 28, 0.75);
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
            background: #201d1a;
            border-radius: var(--r-lg);
            width: 100%;
            max-width: 560px;
            box-shadow: var(--shadow-md);
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
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: #fff;
        }

        .crop-close {
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: var(--r-sm);
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .crop-close:hover {
            background: var(--danger);
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
            border: 2px solid var(--brand);
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
            cursor: move;
            box-sizing: border-box;
        }

        .crop-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: var(--brand);
            border-radius: 2px;
        }

        .crop-handle.tl {
            top: -5px;
            left: -5px;
            cursor: nw-resize;
        }

        .crop-handle.tr {
            top: -5px;
            right: -5px;
            cursor: ne-resize;
        }

        .crop-handle.bl {
            bottom: -5px;
            left: -5px;
            cursor: sw-resize;
        }

        .crop-handle.br {
            bottom: -5px;
            right: -5px;
            cursor: se-resize;
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
            border-radius: var(--r-sm);
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: transparent;
            color: #ccc;
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            cursor: pointer;
            transition: all 0.15s;
        }

        .crop-ratio-btn.active,
        .crop-ratio-btn:hover {
            background: var(--brand);
            border-color: var(--brand);
            color: #fff;
        }

        .crop-actions {
            display: flex;
            gap: 8px;
        }

        .btn-crop-cancel {
            padding: 7px 16px;
            border-radius: var(--r-sm);
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: transparent;
            color: #ccc;
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            cursor: pointer;
            font-family: inherit;
        }

        .btn-crop-cancel:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-crop-apply {
            padding: 7px 16px;
            border-radius: var(--r-sm);
            border: none;
            background: var(--brand);
            color: #fff;
            font-size: var(--fs-sm);
            font-weight: var(--fw-bold);
            cursor: pointer;
            font-family: inherit;
        }

        .btn-crop-apply:hover {
            background: var(--brand-strong);
        }

        /* Confirm modal icons */
        .confirm-icon {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto var(--s4);
            font-size: 1.6rem;
        }

        .confirm-icon.danger {
            background: var(--danger-tint);
            color: var(--danger);
        }

        .confirm-icon.archive {
            background: #f0eee9;
            color: var(--ink-2);
        }

        .confirm-title {
            text-align: center;
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--ink);
            margin-bottom: var(--s2);
        }

        .confirm-text {
            text-align: center;
            font-size: var(--fs-sm);
            color: var(--ink-2);
            line-height: 1.55;
        }

        .confirm-name {
            font-weight: var(--fw-bold);
        }

        /* Price history modal */
        .ph-loading {
            text-align: center;
            padding: var(--s8) var(--s5);
            color: var(--ink-3);
        }

        .ph-spinner {
            width: 28px;
            height: 28px;
            margin: 0 auto 10px;
            border: 3px solid var(--line);
            border-top-color: var(--brand);
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        .ph-current {
            background: var(--brand-tint);
            border: 1px solid var(--brand-tint-2);
            border-radius: var(--r);
            padding: var(--s4) var(--s5);
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: var(--s4);
        }

        .ph-current-label {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--brand-strong);
        }

        .ph-current-value {
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--brand);
            font-variant-numeric: tabular-nums;
        }

        .ph-table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--fs-sm);
        }

        .ph-table th {
            text-align: left;
            font-size: var(--fs-xs);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--ink-3);
            padding: 8px 10px;
            border-bottom: 1.5px solid var(--line);
        }

        .ph-table td {
            padding: 10px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        .ph-table tr:last-child td {
            border-bottom: none;
        }

        .ph-move {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: var(--fw-bold);
            white-space: nowrap;
        }

        .ph-up {
            color: var(--danger);
        }

        .ph-down {
            color: var(--ok);
        }

        .ph-diff {
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            padding: 1px 7px;
            border-radius: var(--r-pill);
        }

        .ph-diff.up {
            background: var(--danger-tint);
            color: #b23c34;
        }

        .ph-diff.down {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .ph-date {
            color: var(--ink-3);
            font-size: var(--fs-xs);
            white-space: nowrap;
        }

        .ph-reason {
            color: var(--ink);
            font-size: var(--fs-sm);
        }

        .ph-reason.empty {
            color: var(--ink-3);
            font-style: italic;
        }

        .ph-empty {
            text-align: center;
            padding: var(--s8) var(--s5);
            color: var(--ink-3);
        }

        @media (max-width: 768px) {
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

            .search-input {
                width: 100%;
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
                    <h1 class="page-title"><?= $showArchive ? 'Archived Products' : 'Products' ?></h1>
                    <div class="page-title-sub"><?= $showArchive ? 'Restore or permanently delete archived products.' : 'Manage your egg and live chicken catalog' ?></div>
                </div>
                <div style="display:flex;gap:var(--s2);align-items:center;">
                    <?php if ($showArchive): ?>
                        <a href="products.php" class="btn btn-secondary">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="19" y1="12" x2="5" y2="12" />
                                <polyline points="12 19 5 12 12 5" />
                            </svg>
                            Back to Products
                        </a>
                    <?php else: ?>
                        <a href="products.php?view=archive" class="btn btn-secondary">
                            <i class="fa-solid fa-box-archive"></i> Archive
                        </a>
                        <button class="btn btn-primary" onclick="openModal('addModal')">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="5" x2="12" y2="19" />
                                <line x1="5" y1="12" x2="19" y2="12" />
                            </svg>
                            Add Product
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?= flash() ?>

            <!-- Toolbar -->
            <div class="toolbar">
                <span class="toolbar-count">
                    <?= $showArchive ? 'Archive' : 'All Products' ?>
                    <span class="count-pill <?= $showArchive ? 'archive' : '' ?>"><?= number_format($totalCount) ?></span>
                </span>
                <?php if (!$showArchive): ?>
                    <form method="GET" style="display:flex;gap:var(--s3);align-items:center;flex-wrap:wrap;">
                        <div class="search-wrap">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                            <input type="text" name="q" class="search-input" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <select name="cat" class="select-field" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="unit" class="select-field" id="unitFilterSel" onchange="this.form.submit()" style="display:<?= $filterCat ? 'block' : 'none' ?>">
                            <option value="">All Units</option>
                            <?php
                            $unitOptions = [];
                            if ($filterCat) {
                                $catName = '';
                                foreach ($categories as $c) {
                                    if ($c['id'] == $filterCat) {
                                        $catName = strtolower($c['name']);
                                        break;
                                    }
                                }
                                if (str_contains($catName, 'egg')) $unitOptions = ['per tray' => 'Per Tray', 'per piece' => 'Per Piece'];
                                if (str_contains($catName, 'chicken')) $unitOptions = ['alive' => 'Alive Chicken', 'processed' => 'Processed Chicken'];
                            }
                            foreach ($unitOptions as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $filterUnit === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($search || $filterCat || $filterUnit): ?>
                            <a href="products.php" class="clear-link">✕ Clear</a>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Table -->
            <div class="table-card">
                <?php if ($products && $products->num_rows > 0): ?>
                    <div class="table-scroll">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th style="width:44px;">#</th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th style="text-align:center;width:<?= $showArchive ? '160px' : '200px' ?>;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNum = $offset + 1;
                                while ($p = $products->fetch_assoc()):
                                    $isChicken = stripos($p['category_name'] ?? '', 'chicken') !== false;
                                    $emoji = $isChicken ? '<i class="fa-solid fa-drumstick-bite"></i>' : '<i class="fa-solid fa-egg"></i>';
                                    $stockPct = $p['reorder_level'] > 0 ? min(100, round(($p['stock'] / max($p['reorder_level'] * 2, 1)) * 100)) : 100;
                                    $stockColor = $p['stock'] <= 0 ? 'var(--danger)' : ($p['stock'] <= $p['reorder_level'] ? 'var(--warn)' : 'var(--ok)');

                                    $priceBadge = '';
                                    if (!empty($p['last_price_change_at']) && $p['last_old_price'] !== null) {
                                        $changedAt = strtotime($p['last_price_change_at']);
                                        $isRecent  = $changedAt && (time() - $changedAt) <= (7 * 86400);
                                        $curPrice  = (float)$p['price'];
                                        $oldP      = (float)$p['last_old_price'];
                                        if ($isRecent && abs($curPrice - $oldP) > 0.0001) {
                                            $went = $curPrice > $oldP ? 'up' : 'down';
                                            $arrow = $went === 'up' ? '▲' : '▼';
                                            $priceBadge = "<span class=\"price-move {$went}\">{$arrow} " . date('M j', $changedAt) . "</span>";
                                        }
                                    }
                                    $historyCount = (int)($p['price_change_count'] ?? 0);
                                    [$stPillCls, $stPillLbl] = $showArchive
                                        ? ['pill-neutral', 'Archived']
                                        : ($p['is_active'] ? ['pill-ok', 'Active'] : ['pill-danger', 'Inactive']);
                                ?>
                                    <tr <?= $showArchive ? 'class="archived-row"' : '' ?>>
                                        <td style="color:var(--ink-3);font-size:var(--fs-xs);font-weight:var(--fw-semi);"><?= $rowNum++ ?></td>
                                        <td>
                                            <div class="cell-lead">
                                                <div class="prod-thumb"><?php if (!empty($p['image_url'])): ?><img src="<?= htmlspecialchars($p['image_url']) ?>" alt="<?= htmlspecialchars($p['name']) ?>"><?php else: ?><?= $emoji ?><?php endif; ?></div>
                                                <div>
                                                    <div class="cell-title"><?= htmlspecialchars($p['name']) ?></div>
                                                    <?php if ($p['description']): ?><div class="prod-desc"><?= htmlspecialchars($p['description']) ?></div><?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="cat-tag"><?= htmlspecialchars($p['category_name'] ?? '—') ?></span></td>
                                        <td>
                                            <span class="price-strong"><?= peso((float)$p['price']) ?></span><?= $priceBadge ?><br>
                                            <span class="unit-label"><?= htmlspecialchars($p['unit']) ?></span>
                                            <?php if ($historyCount > 0): ?><span class="price-count"><?= $historyCount ?> price change<?= $historyCount !== 1 ? 's' : '' ?></span><?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="stock-cell">
                                                <span class="stock-num" style="color:<?= $stockColor ?>;"><?= number_format((int)$p['stock']) ?></span>
                                                <div class="stock-bar">
                                                    <div class="stock-bar-fill" style="width:<?= $stockPct ?>%;background:<?= $stockColor ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="pill <?= $stPillCls ?> pill-dot"><?= $stPillLbl ?></span></td>
                                        <td style="text-align:center;">
                                            <div class="row-actions">
                                                <?php if ($showArchive): ?>
                                                    <form method="POST" style="display:inline;"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                        <button type="submit" class="act act-restore"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                                <polyline points="1 4 1 10 7 10" />
                                                                <path d="M3.51 15a9 9 0 1 0 .49-3.5" />
                                                            </svg><span class="act-label">Restore</span></button>
                                                    </form>
                                                    <button class="act act-delete" onclick="openHardDelete(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['name'])) ?>')"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="3 6 5 6 21 6" />
                                                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                                        </svg><span class="act-label">Delete</span></button>
                                                <?php else: ?>
                                                    <button class="act act-edit" onclick="openEdit(<?= htmlspecialchars(json_encode(['id' => $p['id'], 'category_id' => $p['category_id'], 'category_name' => strtolower($p['category_name'] ?? ''), 'name' => $p['name'], 'description' => $p['description'], 'price' => $p['price'], 'unit' => $p['unit'], 'image_url' => $p['image_url'], 'is_active' => $p['is_active'], 'reorder_level' => $p['reorder_level'], 'stock' => $p['stock']]), ENT_QUOTES) ?>)"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                                        </svg><span class="act-label">Edit</span></button>
                                                    <button class="act act-history" onclick="openHistory(<?= (int)$p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name']), ENT_QUOTES) ?>')"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <circle cx="12" cy="12" r="10" />
                                                            <polyline points="12 6 12 12 16 14" />
                                                        </svg><span class="act-label">History</span></button>
                                                    <button class="act act-archive" onclick="openArchive(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['name'])) ?>')"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                            <polyline points="21 8 21 21 3 21 3 8" />
                                                            <rect x="1" y="3" width="22" height="5" />
                                                            <line x1="10" y1="12" x2="14" y2="12" />
                                                        </svg><span class="act-label">Archive</span></button>
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
                            <div>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?></div>
                            <div class="pg-pages">
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
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-<?= $showArchive ? 'box-archive' : 'box-open' ?>"></i></div>
                        <div class="empty-title"><?= $showArchive ? 'No archived products' : 'No products found' ?></div>
                        <div class="empty-text"><?= $showArchive ? 'Archived products will appear here.' : 'Click "Add Product" to add your first product.' ?></div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- ADD MODAL -->
    <div class="modal-backdrop" id="addModal" onclick="backdropClose(event,'addModal')">
        <div class="modal-card">
            <div class="modal-head">
                <div class="modal-heading"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg> Add New Product</div>
                <button class="modal-x" onclick="closeModal('addModal')">✕</button>
            </div>
            <form method="POST" action="products.php" id="addForm">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="image_url" id="add_image_url">
                <div class="modal-body">
                    <div class="form-section">Basic Information</div>
                    <div class="form-grid">
                        <div class="form-group span-2"><label class="form-label">Product Name <span class="req">*</span></label><input type="text" name="name" class="form-input" placeholder="e.g. Egg Large, Native Chicken" required></div>
                        <div class="form-group"><label class="form-label">Category <span class="req">*</span></label>
                            <select name="category_id" id="add_category_id" class="form-select" required onchange="updateUnits('add',this.value)">
                                <option value="">— Select Category —</option>
                                <?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>" data-name="<?= strtolower(htmlspecialchars($cat['name'])) ?>"><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label class="form-label">Unit <span class="req">*</span></label><select name="unit" id="add_unit" class="form-select" required>
                                <option value="">— Select Category first —</option>
                            </select></div>
                        <div class="form-group span-2"><label class="form-label">Description</label><textarea name="description" class="form-textarea" placeholder="Short product description…"></textarea></div>
                    </div>
                    <div class="form-section">Pricing</div>
                    <div class="form-grid">
                        <div class="form-group"><label class="form-label">Price (₱) <span class="req">*</span></label><input type="number" name="price" class="form-input" placeholder="0.00" step="0.01" min="0.01" required></div>
                        <div class="form-group"><label class="form-label">Status</label><select name="is_active" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select></div>
                    </div>
                    <div class="form-section">Product Image</div>
                    <div class="form-group span-2">
                        <div class="img-upload" id="add_upload_wrap" ondragover="this.classList.add('dragover');event.preventDefault();" ondragleave="this.classList.remove('dragover');" ondrop="handleDrop(event,'add')">
                            <input type="file" accept="image/*" onchange="uploadToCloudinary(this,'add')">
                            <div class="upload-ph">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--ink-3);margin:0 auto 6px;display:block;">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="17 8 12 3 7 8" />
                                    <line x1="12" y1="3" x2="12" y2="15" />
                                </svg>
                                <div style="font-size:var(--fs-sm);font-weight:var(--fw-semi);color:var(--ink-2);">Click or drag image here</div>
                                <div style="font-size:var(--fs-xs);color:var(--ink-3);margin-top:2px;">JPG, PNG, WEBP — max 5MB</div>
                                <div class="upload-spinner" id="add_spinner"></div>
                            </div>
                            <div class="img-preview" id="add_preview"><img src="" id="add_preview_img" alt="Preview">
                                <div class="img-overlay"><span class="img-overlay-label">✓ Image uploaded</span><button type="button" class="img-remove" onclick="removeImage('add')">✕</button></div>
                            </div>
                        </div>
                        <div class="upload-status" id="add_upload_status"></div>
                    </div>
                    <div class="form-section">Inventory Setup</div>
                    <div class="form-grid">
                        <div class="form-group"><label class="form-label">Reorder Level</label><input type="number" name="reorder_level" class="form-input" value="10" min="0"><span class="form-hint">Alert when stock falls below this</span></div>
                    </div>
                </div>
                <div class="modal-foot"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg> Save Product</button></div>
            </form>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal-backdrop" id="editModal" onclick="backdropClose(event,'editModal')">
        <div class="modal-card">
            <div class="modal-head">
                <div class="modal-heading"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--brand)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                    </svg> Edit Product</div>
                <button class="modal-x" onclick="closeModal('editModal')">✕</button>
            </div>
            <form method="POST" action="products.php" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="image_url" id="edit_image_url">
                <div class="modal-body">
                    <div class="form-section">Basic Information</div>
                    <div class="form-grid">
                        <div class="form-group span-2"><label class="form-label">Product Name <span class="req">*</span></label><input type="text" name="name" id="edit_name" class="form-input" required></div>
                        <div class="form-group"><label class="form-label">Category <span class="req">*</span></label><select name="category_id" id="edit_category_id" class="form-select" required onchange="updateUnits('edit',this.value)"><?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>" data-name="<?= strtolower(htmlspecialchars($cat['name'])) ?>"><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label class="form-label">Unit <span class="req">*</span></label><select name="unit" id="edit_unit" class="form-select" required></select></div>
                        <div class="form-group span-2"><label class="form-label">Description</label><textarea name="description" id="edit_description" class="form-textarea"></textarea></div>
                    </div>
                    <div class="form-section">Pricing &amp; Status</div>
                    <div class="form-grid">
                        <div class="form-group"><label class="form-label">Price (₱) <span class="req">*</span></label><input type="number" name="price" id="edit_price" class="form-input" step="0.01" min="0.01" required oninput="checkPriceChanged()"><span class="form-hint" id="edit_price_current_hint"></span></div>
                        <div class="form-group"><label class="form-label">Status</label><select name="is_active" id="edit_is_active" class="form-select">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select></div>
                        <div class="form-group span-2">
                            <div class="price-reason-box" id="price_reason_wrap">
                                <div class="price-reason-note"><i class="fa-solid fa-circle-info"></i> You're changing the price — this will be logged to Price History.</div>
                                <label class="form-label">Reason for change (optional)</label>
                                <input type="text" name="price_reason" id="edit_price_reason" class="form-input" placeholder="e.g. Market markup, supplier price increase…">
                            </div>
                        </div>
                    </div>
                    <div class="form-section">Product Image</div>
                    <div class="form-group span-2">
                        <div class="img-upload" id="edit_upload_wrap" ondragover="this.classList.add('dragover');event.preventDefault();" ondragleave="this.classList.remove('dragover');" ondrop="handleDrop(event,'edit')">
                            <input type="file" accept="image/*" onchange="uploadToCloudinary(this,'edit')">
                            <div class="upload-ph">
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--ink-3);margin:0 auto 6px;display:block;">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <polyline points="17 8 12 3 7 8" />
                                    <line x1="12" y1="3" x2="12" y2="15" />
                                </svg>
                                <div style="font-size:var(--fs-sm);font-weight:var(--fw-semi);color:var(--ink-2);">Click or drag to replace image</div>
                                <div style="font-size:var(--fs-xs);color:var(--ink-3);margin-top:2px;">JPG, PNG, WEBP — max 5MB</div>
                                <div class="upload-spinner" id="edit_spinner"></div>
                            </div>
                            <div class="img-preview" id="edit_preview"><img src="" id="edit_preview_img" alt="Preview">
                                <div class="img-overlay"><span class="img-overlay-label">✓ Image ready</span><button type="button" class="img-remove" onclick="removeImage('edit')">✕</button></div>
                            </div>
                        </div>
                        <div class="upload-status" id="edit_upload_status"></div>
                    </div>
                    <div class="form-section">Inventory</div>
                    <div class="form-grid">
                        <div class="form-group"><label class="form-label">Reorder Level</label><input type="number" name="reorder_level" id="edit_reorder_level" class="form-input" min="0"><span class="form-hint">Alert when stock falls below this</span></div>
                        <div class="form-group" style="justify-content:flex-end;">
                            <label class="form-label">Current Stock</label>
                            <div class="stock-info"><i class="fa-solid fa-circle-info"></i><span>Managed via <a href="stocks/add.php" target="_blank">Stock Batches</a>. Current: <strong id="edit_stock_display">—</strong></span></div>
                        </div>
                    </div>
                </div>
                <div class="modal-foot"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn btn-primary"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg> Update Product</button></div>
            </form>
        </div>
    </div>

    <!-- ARCHIVE MODAL -->
    <div class="modal-backdrop" id="archiveModal" onclick="backdropClose(event,'archiveModal')">
        <div class="modal-card sm">
            <div class="modal-head">
                <div class="modal-heading"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--ink-2)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="21 8 21 21 3 21 3 8" />
                        <rect x="1" y="3" width="22" height="5" />
                        <line x1="10" y1="12" x2="14" y2="12" />
                    </svg> Archive Product</div>
                <button class="modal-x" onclick="closeModal('archiveModal')">✕</button>
            </div>
            <form method="POST" action="products.php"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" id="archive_id">
                <div class="modal-body">
                    <div class="confirm-icon archive"><i class="fa-solid fa-box-archive" style="color:var(--ink-2);"></i></div>
                    <div class="confirm-title">Archive Product?</div>
                    <div class="confirm-text"><span class="confirm-name" id="archive_name" style="color:var(--ink);"></span> will be hidden from customers.<br><br>You can restore it anytime from the Archive section.</div>
                    <div style="margin-top:var(--s4);"><label class="form-label" style="display:block;margin-bottom:5px;">Reason (optional)</label><input type="text" name="archive_reason" class="form-input" placeholder="e.g. Out of season, discontinued…"></div>
                </div>
                <div class="modal-foot"><button type="button" class="btn btn-secondary" onclick="closeModal('archiveModal')">Cancel</button><button type="submit" class="btn" style="background:var(--ink-2);color:#fff;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="21 8 21 21 3 21 3 8" />
                            <rect x="1" y="3" width="22" height="5" />
                        </svg> Archive</button></div>
            </form>
        </div>
    </div>

    <!-- HARD DELETE MODAL -->
    <div class="modal-backdrop" id="hardDeleteModal" onclick="backdropClose(event,'hardDeleteModal')">
        <div class="modal-card sm">
            <div class="modal-head">
                <div class="modal-heading"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                        <line x1="12" y1="9" x2="12" y2="13" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg> <span style="color:var(--danger);">Permanently Delete</span></div>
                <button class="modal-x" onclick="closeModal('hardDeleteModal')">✕</button>
            </div>
            <form method="POST" action="products.php"><input type="hidden" name="action" value="delete_hard"><input type="hidden" name="id" id="hard_delete_id">
                <div class="modal-body">
                    <div class="confirm-icon danger"><i class="fa-solid fa-trash"></i></div>
                    <div class="confirm-title">Permanently Delete?</div>
                    <div class="confirm-text"><span class="confirm-name" id="hard_delete_name" style="color:var(--danger);"></span> will be permanently removed along with all its inventory, price history, and log records.<br><br><strong style="color:var(--danger);">This cannot be undone.</strong></div>
                </div>
                <div class="modal-foot"><button type="button" class="btn btn-secondary" onclick="closeModal('hardDeleteModal')">Cancel</button><button type="submit" class="btn btn-danger"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6" />
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                        </svg> Delete Forever</button></div>
            </form>
        </div>
    </div>

    <!-- PRICE HISTORY MODAL -->
    <div class="modal-backdrop" id="historyModal" onclick="backdropClose(event,'historyModal')">
        <div class="modal-card md">
            <div class="modal-head">
                <div class="modal-heading"><i class="fa-solid fa-clock-rotate-left" style="color:#7c5cd0;"></i> Price History — <span id="history_product_name" style="color:#7c5cd0;">—</span></div>
                <button class="modal-x" onclick="closeModal('historyModal')">✕</button>
            </div>
            <div class="modal-body" id="history_body">
                <div class="ph-loading">
                    <div class="ph-spinner"></div>Loading price history…
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-secondary" onclick="closeModal('historyModal')">Close</button></div>
        </div>
    </div>

    <?php require_once '../config/cloudinary.php'; ?>

    <!-- CROP MODAL -->
    <div class="crop-backdrop" id="cropBackdrop">
        <div class="crop-modal">
            <div class="crop-header">
                <div class="crop-header-title">✂️ Crop Image</div><button class="crop-close" onclick="cancelCrop()">✕</button>
            </div>
            <div class="crop-canvas-wrap" id="cropCanvasWrap"><canvas id="cropCanvas"></canvas>
                <div class="crop-box" id="cropBox">
                    <div class="crop-handle tl" data-handle="tl"></div>
                    <div class="crop-handle tr" data-handle="tr"></div>
                    <div class="crop-handle bl" data-handle="bl"></div>
                    <div class="crop-handle br" data-handle="br"></div>
                </div>
            </div>
            <div class="crop-controls">
                <div class="crop-ratio-btns"><button class="crop-ratio-btn active" onclick="setRatio(1,1,this)">1:1</button><button class="crop-ratio-btn" onclick="setRatio(4,3,this)">4:3</button><button class="crop-ratio-btn" onclick="setRatio(16,9,this)">16:9</button><button class="crop-ratio-btn" onclick="setRatio(0,0,this)">Free</button></div>
                <div class="crop-actions"><button class="btn-crop-cancel" onclick="cancelCrop()">Cancel</button><button class="btn-crop-apply" onclick="applyCrop()">✓ Apply &amp; Upload</button></div>
            </div>
        </div>
    </div>

    <script>
        const CLOUDINARY_CLOUD_NAME = '<?= CLOUDINARY_CLOUD_NAME ?>';
        const CLOUDINARY_UPLOAD_PRESET = '<?= CLOUDINARY_UPLOAD_PRESET ?>';

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
            }]
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
            unitSel.innerHTML = units.length ? units.map(u => `<option value="${u.val}">${u.label}</option>`).join('') : '<option value="">— Select Category first —</option>';
        }

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

        // ── Price Maintenance: track the price the product had when Edit opened ──
        let _editOriginalPrice = null;

        function checkPriceChanged() {
            const wrap = document.getElementById('price_reason_wrap');
            const cur = parseFloat(document.getElementById('edit_price').value);
            if (_editOriginalPrice === null || isNaN(cur)) {
                wrap.classList.remove('show');
                return;
            }
            if (Math.abs(cur - _editOriginalPrice) > 0.001) {
                wrap.classList.add('show');
            } else {
                wrap.classList.remove('show');
            }
        }

        function openEdit(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_name').value = data.name;
            document.getElementById('edit_description').value = data.description || '';
            document.getElementById('edit_price').value = data.price;
            document.getElementById('edit_reorder_level').value = data.reorder_level;
            document.getElementById('edit_stock_display').textContent = data.stock + ' ' + data.unit;
            document.getElementById('edit_image_url').value = data.image_url || '';
            document.getElementById('edit_is_active').value = data.is_active;

            // Price Maintenance: remember starting price + reset the reason field
            _editOriginalPrice = parseFloat(data.price);
            document.getElementById('edit_price_current_hint').textContent = 'Current price: ₱' + parseFloat(data.price).toFixed(2);
            document.getElementById('edit_price_reason').value = '';
            document.getElementById('price_reason_wrap').classList.remove('show');

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

        // ── Price Maintenance: History modal ──────────────────────
        function openHistory(productId, productName) {
            document.getElementById('history_product_name').textContent = productName;
            const body = document.getElementById('history_body');
            body.innerHTML = '<div class="ph-loading"><div class="ph-spinner"></div>Loading price history…</div>';
            openModal('historyModal');

            fetch('price_history_get.php?product_id=' + encodeURIComponent(productId))
                .then(r => r.json())
                .then(data => renderHistory(data))
                .catch(() => {
                    body.innerHTML = '<div class="ph-empty"><i class="fa-solid fa-triangle-exclamation" style="font-size:1.8rem;color:#f59e0b;"></i><div style="margin-top:8px;">Could not load price history.</div></div>';
                });
        }

        function fmtPeso(v) {
            return '₱' + parseFloat(v).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function renderHistory(data) {
            const body = document.getElementById('history_body');
            if (!data || !data.ok) {
                body.innerHTML = '<div class="ph-empty">Could not load price history.</div>';
                return;
            }

            let html = '';
            html += '<div class="ph-current"><span class="ph-current-label">Current Price</span><span class="ph-current-value">' + fmtPeso(data.current_price) + '</span></div>';

            if (!data.history || data.history.length === 0) {
                html += '<div class="ph-empty"><i class="fa-solid fa-clock-rotate-left" style="font-size:1.8rem;color:#c4b5fd;"></i><div style="margin-top:8px;">No price changes recorded yet.</div></div>';
                body.innerHTML = html;
                return;
            }

            html += '<table class="ph-table"><thead><tr><th>Change</th><th>Difference</th><th>Date</th><th>Changed By</th><th>Reason</th></tr></thead><tbody>';
            data.history.forEach(row => {
                const up = row.new_price > row.old_price;
                const diff = Math.abs(row.new_price - row.old_price);
                const pct = row.old_price > 0 ? (diff / row.old_price * 100).toFixed(1) : '0.0';
                const arrowCls = up ? 'ph-arrow-up' : 'ph-arrow-down';
                const arrow = up ? '▲' : '▼';
                const diffCls = up ? 'up' : 'down';
                const reasonHtml = row.reason ? escapeHtml(row.reason) : '<span class="ph-reason empty">No reason given</span>';
                html += '<tr>' +
                    '<td><div class="ph-price-move"><span style="color:#9ca3af;">' + fmtPeso(row.old_price) + '</span> → <span class="' + arrowCls + '">' + arrow + ' ' + fmtPeso(row.new_price) + '</span></div></td>' +
                    '<td><span class="ph-diff ' + diffCls + '">' + (up ? '+' : '-') + fmtPeso(diff) + ' (' + pct + '%)</span></td>' +
                    '<td class="ph-date">' + row.changed_at_display + '</td>' +
                    '<td>' + (row.changed_by_name ? escapeHtml(row.changed_by_name) : '<span style="color:#b0b7c0;">—</span>') + '</td>' +
                    '<td class="ph-reason' + (row.reason ? '' : ' empty') + '">' + reasonHtml + '</td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            body.innerHTML = html;
        }

        function escapeHtml(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

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

        document.querySelector('[onclick="openModal(\'addModal\')"]')?.addEventListener('click', () => {
            document.getElementById('addForm').reset();
            document.getElementById('add_image_url').value = '';
            document.getElementById('add_preview').classList.remove('show');
            document.getElementById('add_preview_img').src = '';
            document.getElementById('add_upload_status').textContent = '';
            document.getElementById('add_unit').innerHTML = '<option value="">— Select Category first —</option>';
            document.getElementById('add_upload_wrap').classList.remove('has-image');
        });

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.flash-msg,.flash-success,.flash-error,.alert,.alert-success,.alert-error').forEach(el => {
                setTimeout(() => {
                    el.style.transition = 'opacity 0.4s ease';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 420);
                }, 1500);
            });
        });
    </script>
</body>

</html>