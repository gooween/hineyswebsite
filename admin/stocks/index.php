<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/stocks/index.php
// Purpose: Stock batches list — FIFO order
// ============================================================

session_start();
require_once '../../config/db.php';
requireAdmin();

$activePage = 'stocks';

// Ensure tables exist
$conn->query("CREATE TABLE IF NOT EXISTS stock_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_code VARCHAR(60) UNIQUE NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    remaining INT NOT NULL,
    unit VARCHAR(50) NOT NULL,
    notes TEXT,
    expires_at DATE NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT NOW(),
    status ENUM('active','depleted','expired') DEFAULT 'active',
    INDEX(product_id), INDEX(status), INDEX(created_at)
)");

// Auto-expire batches past their expiry date
$conn->query("UPDATE stock_batches SET status='expired'
    WHERE status='active' AND expires_at IS NOT NULL AND expires_at < CURDATE()");

// Filters
$filterStatus  = trim($_GET['status']  ?? '');
$filterProduct = (int)($_GET['product'] ?? 0);
$filterDate    = trim($_GET['date']    ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 20;
$offset        = ($page - 1) * $perPage;

$where = "WHERE 1=1";
if ($filterStatus)  $where .= " AND sb.status = '{$conn->real_escape_string($filterStatus)}'";
if ($filterProduct) $where .= " AND sb.product_id = {$filterProduct}";
if ($filterDate)    $where .= " AND DATE(sb.created_at) = '{$conn->real_escape_string($filterDate)}'";

$totalRes   = $conn->query("SELECT COUNT(*) AS cnt FROM stock_batches sb {$where}");
$totalCount = (int)($totalRes->fetch_assoc()['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$batches = $conn->query("
    SELECT sb.*, p.name AS product_name, c.name AS category_name,
           u.full_name AS created_by_name
    FROM stock_batches sb
    LEFT JOIN products p ON p.id = sb.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN users u ON u.id = sb.created_by
    {$where}
    ORDER BY sb.created_at ASC
    LIMIT {$perPage} OFFSET {$offset}
");

// Products for filter
$products = $conn->query("SELECT p.id, p.name, p.unit, c.name AS category FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active=1 ORDER BY c.name, p.name");

// Summary stats
$stats = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status='depleted' THEN 1 ELSE 0 END) AS depleted,
        SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN status='active' AND expires_at <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND expires_at >= CURDATE() THEN 1 ELSE 0 END) AS expiring_soon
    FROM stock_batches
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <title>Stock Batches — Hiney's Admin</title>
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

        .btn-add {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-add:hover {
            background: var(--primary);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(230, 126, 34, 0.35);
        }

        /* Stats */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media(max-width:900px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 16px 18px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .si-green {
            background: #ecfdf5;
        }

        .si-gray {
            background: #f3f4f6;
        }

        .si-red {
            background: #fef2f2;
        }

        .si-yellow {
            background: #fefce8;
        }

        .stat-num {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.03em;
            line-height: 1;
        }

        .stat-lbl {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        /* Toolbar */
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
            background-position: right 8px center;
        }

        .filter-select:focus {
            border-color: var(--primary);
            outline: none;
        }

        .filter-date {
            padding: 7px 10px;
            border: 1px solid var(--card-border);
            border-radius: 8px;
            font-size: 0.85rem;
            background: var(--page-bg);
            color: var(--text);
            outline: none;
            font-family: inherit;
        }

        .filter-date:focus {
            border-color: var(--primary);
            outline: none;
        }

        .btn-filter {
            padding: 7px 14px;
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.2s;
        }

        .btn-filter:hover {
            background: var(--primary);
            color: #fff;
        }

        .btn-clear {
            font-size: 0.8rem;
            color: var(--primary);
            text-decoration: none;
            white-space: nowrap;
        }

        /* Table */
        .table-wrapper {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 0 0 var(--radius) var(--radius);
            overflow-x: auto;
            box-shadow: var(--shadow);
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

        .batch-code {
            font-family: monospace;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--dark);
            background: #f3f4f6;
            padding: 3px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .sb-active {
            background: #d1fae5;
            color: #065f46;
        }

        .sb-depleted {
            background: #f3f4f6;
            color: #6b7280;
        }

        .sb-expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .sb-expiring {
            background: #fef3c7;
            color: #92400e;
        }

        /* Progress bar */
        .qty-bar-wrap {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 80px;
        }

        .qty-nums {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--dark);
        }

        .qty-bar {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
        }

        .qty-bar-fill {
            height: 100%;
            border-radius: 2px;
            transition: width 0.3s;
        }

        /* Expiry */
        .expiry-cell {
            font-size: 0.82rem;
        }

        .expiry-soon {
            color: #92400e;
            font-weight: 700;
        }

        .expiry-ok {
            color: var(--text-muted);
        }

        .expiry-gone {
            color: #991b1b;
            font-weight: 700;
        }

        /* Pagination */
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
            text-decoration: none;
            transition: background 0.15s;
        }

        .pg-btn:hover {
            background: var(--page-bg);
        }

        .pg-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            font-weight: 700;
        }

        .pg-btn.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* Empty */
        .empty-state {
            padding: 56px 20px;
            text-align: center;
            color: var(--text-muted);
        }

        .empty-icon {
            font-size: 3rem;
            margin-bottom: 12px;
        }

        /* FIFO note */
        .fifo-note {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.8rem;
            color: #1e40af;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
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
        }
    </style>
</head>

<body>
    <div class="admin-layout">
        <?php include '../../includes/sidebar.php'; ?>
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
                        <h1 class="page-title">Stock Batches</h1>
                    </div>
                    <div class="page-title-sub">All batches sorted oldest first (FIFO). System assigns oldest active batch to orders automatically.</div>
                </div>
                <a href="add.php" class="btn-add">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Add Batch
                </a>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon si-green"><i class="fa-solid fa-boxes-stacked" style="color:#10b981;"></i></div>
                    <div>
                        <div class="stat-num"><?= number_format($stats['active'] ?? 0) ?></div>
                        <div class="stat-lbl">Active Batches</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon si-yellow"><i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i></div>
                    <div>
                        <div class="stat-num"><?= number_format($stats['expiring_soon'] ?? 0) ?></div>
                        <div class="stat-lbl">Expiring Soon</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon si-gray"><i class="fa-solid fa-box" style="color:#6b7280;"></i></div>
                    <div>
                        <div class="stat-num"><?= number_format($stats['depleted'] ?? 0) ?></div>
                        <div class="stat-lbl">Depleted</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon si-red"><i class="fa-solid fa-ban" style="color:#ef4444;"></i></div>
                    <div>
                        <div class="stat-num"><?= number_format($stats['expired'] ?? 0) ?></div>
                        <div class="stat-lbl">Expired</div>
                    </div>
                </div>
            </div>

            <!-- FIFO note -->
            <div class="fifo-note">
                <i class="fa-solid fa-info-circle"></i>
                <span>Batches are listed oldest first. When an order is fulfilled, the system automatically picks from the top of this list (FIFO).</span>
            </div>

            <!-- Toolbar / Filters -->
            <div class="toolbar">
                <form method="GET" style="display:contents;">
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="active" <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                        <option value="depleted" <?= $filterStatus === 'depleted' ? 'selected' : '' ?>>Depleted</option>
                        <option value="expired" <?= $filterStatus === 'expired'  ? 'selected' : '' ?>>Expired</option>
                    </select>
                    <select name="product" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Products</option>
                        <?php while ($pr = $products->fetch_assoc()): ?>
                            <option value="<?= $pr['id'] ?>" <?= $filterProduct == $pr['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pr['name']) ?> (<?= htmlspecialchars($pr['unit']) ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <input type="date" name="date" class="filter-date" value="<?= htmlspecialchars($filterDate) ?>" onchange="this.form.submit()">
                    <?php if ($filterStatus || $filterProduct || $filterDate): ?>
                        <a href="index.php" class="btn-clear">✕ Clear</a>
                    <?php endif; ?>
                </form>
                <span style="margin-left:auto;font-size:0.82rem;color:var(--text-muted);"><?= number_format($totalCount) ?> batch<?= $totalCount !== 1 ? 'es' : '' ?></span>
            </div>

            <!-- Table -->
            <div class="table-wrapper">
                <?php if ($batches && $batches->num_rows > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Batch Code</th>
                                <th>Product</th>
                                <th>Unit</th>
                                <th>Qty Remaining</th>
                                <th>Status</th>
                                <th>Expires</th>
                                <th>Logged</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($b = $batches->fetch_assoc()):
                                $pct      = $b['quantity'] > 0 ? round(($b['remaining'] / $b['quantity']) * 100) : 0;
                                $barColor = $pct > 50 ? '#10b981' : ($pct > 20 ? '#f59e0b' : '#ef4444');

                                // Expiry display
                                $today   = new DateTime();
                                $expDate = $b['expires_at'] ? new DateTime($b['expires_at']) : null;
                                $daysLeft = $expDate ? (int)$today->diff($expDate)->format('%r%a') : null;

                                if ($b['status'] === 'expired') {
                                    $expClass = 'expiry-gone';
                                    $expText = 'Expired';
                                } elseif ($daysLeft !== null && $daysLeft <= 3) {
                                    $expClass = 'expiry-soon';
                                    $expText = $daysLeft === 0 ? 'Today!' : "In {$daysLeft}d";
                                } else {
                                    $expClass = 'expiry-ok';
                                    $expText = $expDate ? date('M j, Y', strtotime($b['expires_at'])) : '—';
                                }

                                // Status badge
                                if ($b['status'] === 'active' && $daysLeft !== null && $daysLeft <= 3 && $daysLeft >= 0) {
                                    $badgeClass = 'sb-expiring';
                                    $badgeLabel = 'Expiring';
                                } else {
                                    $badgeClass = 'sb-' . $b['status'];
                                    $badgeLabel = ucfirst($b['status']);
                                }
                            ?>
                                <tr>
                                    <td><span class="batch-code"><?= htmlspecialchars($b['batch_code']) ?></span></td>
                                    <td>
                                        <div style="font-weight:600;color:var(--dark);"><?= htmlspecialchars($b['product_name'] ?? '—') ?></div>
                                        <div style="font-size:0.72rem;color:var(--text-muted);"><?= htmlspecialchars($b['category_name'] ?? '') ?></div>
                                    </td>
                                    <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($b['unit']) ?></td>
                                    <td>
                                        <div class="qty-bar-wrap">
                                            <div class="qty-nums"><?= number_format($b['remaining']) ?> / <?= number_format($b['quantity']) ?></div>
                                            <div class="qty-bar">
                                                <div class="qty-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                                    <td class="expiry-cell <?= $expClass ?>"><?= $expText ?></td>
                                    <td style="font-size:0.82rem;color:var(--text-muted);white-space:nowrap;">
                                        <?= date('M j, Y', strtotime($b['created_at'])) ?><br>
                                        <span style="font-size:0.72rem;"><?= date('g:i A', strtotime($b['created_at'])) ?></span>
                                    </td>
                                    <td style="font-size:0.82rem;color:var(--text-muted);"><?= htmlspecialchars($b['created_by_name'] ?? 'Admin') ?></td>
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
                                echo "<a href='?{$qs}' class='pg-btn " . ($page <= 1 ? 'disabled' : '') . "'>← Prev</a>";
                                for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
                                    $qi = http_build_query(array_merge($_GET, ['page' => $i]));
                                    echo "<a href='?{$qi}' class='pg-btn " . ($i == $page ? 'active' : '') . "'>{$i}</a>";
                                }
                                $qs2 = http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)]));
                                echo "<a href='?{$qs2}' class='pg-btn " . ($page >= $totalPages ? 'disabled' : '') . "'>Next →</a>";
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                        <div style="font-size:0.9rem;font-weight:600;color:var(--dark);margin-bottom:6px;">No batches found</div>
                        <div style="font-size:0.82rem;">
                            <?= ($filterStatus || $filterProduct || $filterDate) ? 'Try adjusting your filters.' : 'Click "Add Batch" to log your first stock batch.' ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</body>

</html>