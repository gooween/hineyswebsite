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

    // Manual adjust disabled — stock is managed via Stock Batches
    // if ($action === 'adjust') { ... }

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

$stockExpr = "COALESCE((
    SELECT SUM(sb.remaining)
    FROM stock_batches sb
    WHERE sb.product_id = p.id AND sb.status = 'active'
), 0)";

$where = "WHERE p.is_active = 1";
if ($search)    $where .= " AND p.name LIKE '%{$conn->real_escape_string($search)}%'";
if ($filterCat) $where .= " AND p.category_id = {$filterCat}";

$inventory = $conn->query("
    SELECT p.id AS product_id, p.name, p.unit, p.image_url, c.name AS category,
           i.id AS inv_id, i.reorder_level, i.last_updated, i.notes,
           {$stockExpr} AS stock
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    JOIN categories c ON c.id = p.category_id
    {$where}
    ORDER BY ({$stockExpr}) ASC, p.name ASC
");

$r = $conn->query("SELECT COUNT(*) AS cnt FROM inventory i JOIN products p ON p.id = i.product_id WHERE p.is_active = 1");
$totalItems = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("
    SELECT COUNT(*) AS cnt FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
    AND (
        SELECT SUM(sb.remaining)
        FROM stock_batches sb WHERE sb.product_id = i.product_id AND sb.status = 'active'
    ) <= i.reorder_level
    AND (
        SELECT SUM(sb.remaining)
        FROM stock_batches sb WHERE sb.product_id = i.product_id AND sb.status = 'active'
    ) > 0
");
$lowStockCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("
    SELECT COUNT(*) AS cnt FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
    AND COALESCE((
        SELECT SUM(sb.remaining)
        FROM stock_batches sb WHERE sb.product_id = i.product_id AND sb.status = 'active'
    ), 0) = 0
");
$outOfStockCount = (int)($r->fetch_assoc()['cnt'] ?? 0);

$r = $conn->query("
    SELECT COALESCE(SUM(
        (SELECT COALESCE(SUM(remaining),0) FROM stock_batches sb WHERE sb.product_id = p.id AND sb.status = 'active')
    ), 0) AS total
    FROM inventory i
    JOIN products p ON p.id = i.product_id
    WHERE p.is_active = 1
");
$totalUnits = (int)($r->fetch_assoc()['total'] ?? 0);

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
    <title>Inventory — Hiney's Admin</title>
    <style>
        :root {
            --card-border: #e9e8e4
        }

        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1;
            padding: 32px 32px 48px;
            min-height: 100vh;
            background: var(--page-bg)
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.02em
        }

        .page-title-sub {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-top: 2px
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px
        }

        @media(max-width:1100px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr)
            }
        }

        @media(max-width:600px) {
            .stats-row {
                grid-template-columns: 1fr
            }
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            transition: transform 0.18s, box-shadow 0.18s
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md)
        }

        .stat-card-accent {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px
        }

        .stat-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .stat-body {
            flex: 1
        }

        .stat-value {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--dark);
            line-height: 1;
            letter-spacing: -0.03em
        }

        .stat-label {
            font-size: 0.73rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            font-weight: 700;
            margin-top: 4px
        }

        .sc-blue .stat-card-accent {
            background: #3b82f6
        }

        .sc-blue .stat-icon-wrap {
            background: #eff6ff;
            color: #3b82f6
        }

        .sc-green .stat-card-accent {
            background: #10b981
        }

        .sc-green .stat-icon-wrap {
            background: #ecfdf5;
            color: #10b981
        }

        .sc-amber .stat-card-accent {
            background: #f59e0b
        }

        .sc-amber .stat-icon-wrap {
            background: #fffbeb;
            color: #f59e0b
        }

        .sc-red .stat-card-accent {
            background: #ef4444
        }

        .sc-red .stat-icon-wrap {
            background: #fef2f2;
            color: #ef4444
        }

        .info-notice {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-left: 4px solid #3b82f6;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 0.88rem;
            color: #1e40af;
            line-height: 1.5
        }

        .info-notice a {
            color: #1d4ed8;
            font-weight: 700;
            text-decoration: none
        }

        .info-notice a:hover {
            text-decoration: underline
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
            border-bottom: none
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            flex-wrap: wrap
        }

        .toolbar-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark)
        }

        .search-wrap {
            position: relative;
            display: flex;
            align-items: center
        }

        .search-wrap svg {
            position: absolute;
            left: 10px;
            color: var(--text-muted);
            pointer-events: none
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
            transition: border-color 0.15s
        }

        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.12)
        }

        .filter-select {
            padding: 7px 28px 7px 10px;
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
            background-position: right 9px center
        }

        .filter-select:focus {
            border-color: var(--primary);
            outline: none
        }

        .table-wrapper {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 0 0 var(--radius) var(--radius);
            overflow-x: auto;
            box-shadow: var(--shadow);
            margin-bottom: 24px
        }

        table.data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.87rem
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
            text-align: left
        }

        table.data-table tbody tr:nth-child(even) {
            background: #faf9f7
        }

        table.data-table tbody tr:hover {
            background: #fef9f4;
            transition: background 0.12s
        }

        table.data-table tbody td {
            padding: 12px 14px;
            color: var(--text);
            border-bottom: 1px solid #f3f2f0;
            vertical-align: middle
        }

        table.data-table tbody tr:last-child td {
            border-bottom: none
        }

        .product-cell {
            display: flex;
            align-items: center;
            gap: 10px
        }

        .product-thumb {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, #fef3e8, #fde9d0);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
            border: 1px solid #fddcb5
        }

        .product-name {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.88rem
        }

        .product-unit {
            font-size: 0.74rem;
            color: var(--text-muted);
            margin-top: 1px
        }

        .stock-wrap {
            display: flex;
            flex-direction: column;
            gap: 4px
        }

        .stock-number {
            font-size: 1rem;
            font-weight: 700
        }

        .stock-unit-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 500
        }

        .stock-bar {
            width: 80px;
            height: 5px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden
        }

        .stock-fill {
            height: 100%;
            border-radius: 3px
        }

        .stock-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            white-space: nowrap
        }

        .stock-badge::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor
        }

        .stock-badge.ok {
            background: #d1fae5;
            color: #065f46
        }

        .stock-badge.low {
            background: #fef3c7;
            color: #92400e
        }

        .stock-badge.out {
            background: #fee2e2;
            color: #991b1b
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
            text-decoration: none
        }

        .btn-add-stock {
            color: #10b981;
            border-color: #10b981
        }

        .btn-add-stock:hover {
            background: #10b981;
            color: #fff
        }

        .btn-logs {
            color: #6b7280;
            border-color: #d1d5db
        }

        .btn-logs:hover {
            background: #f3f4f6
        }

        .empty-state {
            padding: 48px 20px;
            text-align: center;
            color: var(--text-muted)
        }

        .empty-icon {
            font-size: 2.8rem;
            margin-bottom: 10px
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 14px 18px;
            border-bottom: none
        }

        .section-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px
        }

        .log-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600
        }

        .log-in {
            background: #d1fae5;
            color: #065f46
        }

        .log-out {
            background: #fee2e2;
            color: #991b1b
        }

        .log-adjustment {
            background: #dbeafe;
            color: #1e40af
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(3px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px
        }

        .modal-backdrop.open {
            display: flex
        }

        .modal-card {
            background: var(--card-bg);
            border-radius: 14px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            animation: modalIn 0.22s cubic-bezier(0.34, 1.56, 0.64, 1) both
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: translateY(16px) scale(0.97)
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
            padding: 18px 22px 14px;
            border-bottom: 1px solid var(--card-border);
            position: sticky;
            top: 0;
            background: var(--card-bg);
            z-index: 1;
            border-radius: 14px 14px 0 0
        }

        .modal-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px
        }

        .modal-close {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: var(--page-bg);
            border-radius: 6px;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 0.95rem;
            transition: background 0.15s, color 0.15s
        }

        .modal-close:hover {
            background: #fee2e2;
            color: #ef4444
        }

        .modal-body {
            padding: 0
        }

        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            background: var(--card-bg);
            cursor: pointer;
            color: var(--dark)
        }

        @media(max-width:768px) {
            .main-content {
                margin-left: 0;
                padding: 16px 16px 48px
            }

            .mobile-menu-btn {
                display: flex
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
                        <button class="mobile-menu-btn" onclick="openSidebar()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="3" y1="6" x2="21" y2="6" />
                                <line x1="3" y1="12" x2="21" y2="12" />
                                <line x1="3" y1="18" x2="21" y2="18" />
                            </svg></button>
                        <h1 class="page-title">Inventory</h1>
                    </div>
                    <div class="page-title-sub">Monitor stock levels and track changes</div>
                </div>
            </div>

            <?= flash() ?>

            <div class="info-notice">
                <i class="fa-solid fa-circle-info" style="font-size:1.1rem;flex-shrink:0;"></i>
                <span>Stock is managed through <strong>Stock Batches</strong>. To add stock, go to <a href="stocks/add.php">Add Stock Batch</a>. Stock deductions happen automatically when orders are <strong>approved</strong>.</span>
            </div>

            <div class="stats-row">
                <div class="stat-card sc-blue">
                    <div class="stat-card-accent"></div>
                    <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z" />
                        </svg></div>
                    <div class="stat-body">
                        <div class="stat-value"><?= number_format($totalItems) ?></div>
                        <div class="stat-label">Total Products</div>
                    </div>
                </div>
                <div class="stat-card sc-green">
                    <div class="stat-card-accent"></div>
                    <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12" />
                        </svg></div>
                    <div class="stat-body">
                        <div class="stat-value"><?= number_format($totalUnits) ?></div>
                        <div class="stat-label">Total Units in Stock</div>
                    </div>
                </div>
                <div class="stat-card sc-amber">
                    <div class="stat-card-accent"></div>
                    <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                            <line x1="12" y1="9" x2="12" y2="13" />
                            <line x1="12" y1="17" x2="12.01" y2="17" />
                        </svg></div>
                    <div class="stat-body">
                        <div class="stat-value"><?= number_format($lowStockCount) ?></div>
                        <div class="stat-label">Low Stock Items</div>
                    </div>
                </div>
                <div class="stat-card sc-red">
                    <div class="stat-card-accent"></div>
                    <div class="stat-icon-wrap"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="15" y1="9" x2="9" y2="15" />
                            <line x1="9" y1="9" x2="15" y2="15" />
                        </svg></div>
                    <div class="stat-body">
                        <div class="stat-value"><?= number_format($outOfStockCount) ?></div>
                        <div class="stat-label">Out of Stock</div>
                    </div>
                </div>
            </div>

            <div class="toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-title">Stock Levels</span>
                    <form method="GET" style="display:contents;">
                        <div class="search-wrap"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg><input type="text" name="q" class="search-input" placeholder="Search products…" value="<?= htmlspecialchars($search) ?>"></div>
                        <select name="cat" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Categories</option><?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>" <?= $filterCat == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?>
                        </select>
                        <select name="stock" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Stock</option>
                            <option value="ok" <?= $filterStock === 'ok' ? 'selected' : '' ?>>OK</option>
                            <option value="low" <?= $filterStock === 'low' ? 'selected' : '' ?>>Low Stock</option>
                            <option value="out" <?= $filterStock === 'out' ? 'selected' : '' ?>>Out of Stock</option>
                        </select>
                        <?php if ($search || $filterCat || $filterStock): ?><a href="inventory.php" style="font-size:0.8rem;color:var(--primary);text-decoration:none;white-space:nowrap;">✕ Clear</a><?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="table-wrapper">
                <?php
                $rows = [];
                if ($inventory) {
                    while ($row = $inventory->fetch_assoc()) {
                        $qty   = (int)$row['stock'];
                        $reord = (int)$row['reorder_level'];
                        if ($filterStock === 'low' && !($qty <= $reord && $qty > 0)) continue;
                        if ($filterStock === 'out' && $qty !== 0) continue;
                        if ($filterStock === 'ok'  && !($qty > $reord)) continue;
                        $rows[] = $row;
                    }
                }
                ?>
                <?php if (count($rows) > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Current Stock</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th style="text-align:center;width:160px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $n = 1;
                            foreach ($rows as $inv):
                                $qty   = (int)$inv['stock'];
                                $reord = (int)$inv['reorder_level'];
                                $isTray = strtolower($inv['unit']) === 'per tray';
                                $emoji  = stripos($inv['category'], 'chicken') !== false ? '<i class="fa-solid fa-drumstick-bite"></i>' : '<i class="fa-solid fa-egg"></i>';
                                $pct    = $reord > 0 ? min(100, round(($qty / max($reord * 2, 1)) * 100)) : 100;
                                $color  = $qty <= 0 ? '#ef4444' : ($qty <= $reord ? '#f59e0b' : '#10b981');
                                $bCls   = $qty <= 0 ? 'out' : ($qty <= $reord ? 'low' : 'ok');
                                $bLbl   = $qty <= 0 ? 'Out of Stock' : ($qty <= $reord ? 'Low Stock' : 'OK');
                                $unitLbl = $isTray ? 'tray' . ($qty != 1 ? 's' : '') : htmlspecialchars($inv['unit']);
                            ?>
                                <tr>
                                    <td style="color:var(--text-muted);font-size:0.78rem;font-weight:600;"><?= $n++ ?></td>
                                    <td>
                                        <div class="product-cell">
                                            <div class="product-thumb" style="<?= !empty($inv['image_url']) ? 'padding:0;overflow:hidden;' : '' ?>"><?php if (!empty($inv['image_url'])): ?><img src="<?= htmlspecialchars($inv['image_url']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:8px;"><?php else: ?><?= $emoji ?><?php endif; ?></div>
                                            <div>
                                                <div class="product-name"><?= htmlspecialchars($inv['name']) ?></div>
                                                <div class="product-unit"><?= htmlspecialchars($inv['unit']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span style="background:#f3f4f6;color:var(--text-muted);padding:2px 8px;border-radius:6px;font-size:0.78rem;font-weight:500;"><?= htmlspecialchars($inv['category']) ?></span></td>
                                    <td>
                                        <div class="stock-wrap"><span class="stock-number" style="color:<?= $color ?>;"><?= number_format($qty) ?></span><span class="stock-unit-label"><?= $unitLbl ?></span>
                                            <div class="stock-bar">
                                                <div class="stock-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="color:var(--text-muted);"><?= number_format($reord) ?></td>
                                    <td><span class="stock-badge <?= $bCls ?>"><?= $bLbl ?></span></td>
                                    <td style="color:var(--text-muted);font-size:0.8rem;"><?= date('M j, Y', strtotime($inv['last_updated'])) ?></td>
                                    <td style="text-align:center;">
                                        <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                                            <a href="stocks/add.php?product_id=<?= $inv['product_id'] ?>" class="btn-action btn-add-stock">
                                                <i class="fa-solid fa-plus"></i> Add Stock
                                            </a>
                                            <button class="btn-action btn-logs" onclick="openLogs(<?= $inv['product_id'] ?>,'<?= htmlspecialchars(addslashes($inv['name'])) ?>')">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z" />
                                                </svg>
                                                Logs
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-box"></i></div>
                        <div>No inventory records found.</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Logs -->
            <div class="section-header">
                <div class="section-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z" />
                    </svg> Recent Stock Activity</div><span style="font-size:0.78rem;color:var(--text-muted);">Last 20 entries</span>
            </div>
            <div class="table-wrapper">
                <?php if ($logs && $logs->num_rows > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Product</th>
                                <th>Type</th>
                                <th>Quantity</th>
                                <th>Reason</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($log = $logs->fetch_assoc()):
                                $tc = match ($log['type']) {
                                    'in' => 'log-in',
                                    'out' => 'log-out',
                                    'adjustment' => 'log-adjustment',
                                    default => 'log-in'
                                };
                                $tl = match ($log['type']) {
                                    'in' => '<i class="fa-solid fa-arrow-up"></i> Stock In',
                                    'out' => '<i class="fa-solid fa-arrow-down"></i> Stock Out',
                                    'adjustment' => '⇄ Adjusted',
                                    default => $log['type']
                                };
                            ?>
                                <tr>
                                    <td style="font-size:0.82rem;color:var(--text-muted);white-space:nowrap;"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
                                    <td style="font-weight:600;"><?= htmlspecialchars($log['product_name']) ?></td>
                                    <td><span class="log-type-badge <?= $tc ?>"><?= $tl ?></span></td>
                                    <td style="font-weight:700;"><?= number_format($log['quantity']) ?></td>
                                    <td style="color:var(--text-muted);font-size:0.84rem;"><?= htmlspecialchars($log['reason'] ?? '—') ?></td>
                                    <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($log['by_name'] ?? '—') ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                        <div>No stock activity yet.</div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- LOGS MODAL -->
    <div class="modal-backdrop" id="logsModal" onclick="backdropClose(event,'logsModal')">
        <div class="modal-card" style="max-width:680px;">
            <div class="modal-header">
                <div class="modal-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 14l6 0M9 10l6 0M16 2H8a4 4 0 0 0-4 4v14l4-2 4 2 4-2 4 2V6a4 4 0 0 0-4-4z" />
                    </svg><span id="logs_title">Stock History</span></div><button class="modal-close" onclick="closeModal('logsModal')">✕</button>
            </div>
            <div class="modal-body">
                <div id="logs_content" style="padding:32px;text-align:center;color:var(--text-muted);">Loading…</div>
            </div>
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
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(m => closeModal(m.id));
        });

        function openLogs(productId, productName) {
            document.getElementById('logs_title').textContent = productName + ' — Stock History';
            document.getElementById('logs_content').innerHTML = '<div style="padding:32px;text-align:center;color:var(--text-muted);">Loading…</div>';
            openModal('logsModal');
            fetch('inventory_logs_ajax.php?product_id=' + productId)
                .then(r => r.text())
                .then(html => {
                    document.getElementById('logs_content').innerHTML = html;
                })
                .catch(() => {
                    document.getElementById('logs_content').innerHTML = '<div style="padding:24px;text-align:center;color:#ef4444;">Failed to load logs.</div>';
                });
        }

        const si = document.querySelector('.search-input');
        if (si) si.addEventListener('keydown', e => {
            if (e.key === 'Enter') e.target.closest('form').submit();
        });

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[style*="background:#d1fae5"],[style*="background:#fee2e2"]').forEach(el => {
                setTimeout(() => {
                    el.style.transition = 'opacity 0.5s';
                    el.style.opacity = '0';
                    setTimeout(() => el.remove(), 520);
                }, 4000);
            });
        });
    </script>
</body>

</html>