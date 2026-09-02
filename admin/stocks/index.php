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

$conn->query("UPDATE stock_batches SET status='expired'
    WHERE status='active' AND expires_at IS NOT NULL AND expires_at < CURDATE()");

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

$products = $conn->query("SELECT p.id, p.name, p.unit, c.name AS category FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active=1 ORDER BY c.name, p.name");

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
    <title>Stock Batches — HATCH Admin</title>
    <style>
        /* Page-specific only — shared system comes from admin.css */

        /* FIFO note */
        .fifo-note {
            display: flex;
            align-items: flex-start;
            gap: var(--s2);
            background: var(--info-tint);
            border: 1px solid #bcd6f5;
            border-radius: var(--r-sm);
            padding: 10px var(--s4);
            font-size: var(--fs-sm);
            color: #2b62ad;
            line-height: 1.5;
            margin-bottom: var(--s5);
        }

        .fifo-note i {
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Toolbar / filters */
        .toolbar {
            display: flex;
            align-items: center;
            gap: var(--s2);
            flex-wrap: wrap;
            margin-bottom: var(--s4);
        }

        .filter-select,
        .filter-date {
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
        .filter-date:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .filter-date {
            color: var(--ink-2);
        }

        .btn-clear {
            font-size: var(--fs-sm);
            color: var(--brand);
            font-weight: var(--fw-med);
            white-space: nowrap;
        }

        .btn-clear:hover {
            text-decoration: underline;
        }

        /* Batch code */
        .batch-code {
            font-family: 'SF Mono', 'Consolas', monospace;
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            background: var(--surface-2);
            color: var(--ink);
            padding: 3px 9px;
            border-radius: 5px;
            white-space: nowrap;
        }

        /* Qty remaining bar */
        .qty-bar-wrap {
            min-width: 120px;
        }

        .qty-nums {
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            color: var(--ink);
            margin-bottom: 4px;
            font-variant-numeric: tabular-nums;
        }

        .qty-bar {
            height: 6px;
            background: var(--line);
            border-radius: 3px;
            overflow: hidden;
        }

        .qty-bar-fill {
            height: 100%;
            border-radius: 3px;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            white-space: nowrap;
        }

        .status-badge::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .sb-active {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        .sb-expiring {
            background: var(--warn-tint);
            color: #8a5a0c;
        }

        .sb-depleted {
            background: var(--surface-2);
            color: var(--ink-2);
        }

        .sb-expired {
            background: var(--danger-tint);
            color: #b23c34;
        }

        /* Expiry cell */
        .expiry-cell {
            font-size: var(--fs-sm);
            font-weight: var(--fw-med);
            white-space: nowrap;
        }

        .expiry-ok {
            color: var(--ink-2);
        }

        .expiry-soon {
            color: #8a5a0c;
            font-weight: var(--fw-bold);
        }

        .expiry-gone {
            color: #b23c34;
            font-weight: var(--fw-bold);
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

        @media (max-width: 768px) {
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>

<body>
    <div class="admin-layout">
        <?php include '../../includes/sidebar.php'; ?>
        <div class="main-content">

            <!-- Mobile topbar -->
            <div class="mobile-topbar">
                <div class="mobile-brand">
                    <div class="mobile-brand-icon"><i class="fa-solid fa-egg"></i></div>
                    HATCH Admin
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
                    <h1 class="page-title">Stock Batches</h1>
                    <div class="page-title-sub">All batches sorted oldest first (FIFO). The system assigns the oldest active batch to orders automatically.</div>
                </div>
                <a href="add.php" class="btn btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Add Batch
                </a>
            </div>

            <!-- Stat cards -->
            <div class="grid cols-2 mb-6" style="grid-template-columns:repeat(4,1fr);">
                <div class="stat-card tone-green">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Active Batches</span>
                        <div class="stat-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($stats['active'] ?? 0) ?></div>
                    <div class="stat-foot">Currently in stock</div>
                </div>
                <div class="stat-card tone-amber">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Expiring Soon</span>
                        <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    </div>
                    <div class="stat-value">
                        <?php if (($stats['expiring_soon'] ?? 0) > 0): ?><span class="pulse amber"><span class="pulse-dot amber"></span><?= number_format($stats['expiring_soon']) ?></span><?php else: ?><?= number_format($stats['expiring_soon'] ?? 0) ?><?php endif; ?>
                    </div>
                    <div class="stat-foot">Within 3 days</div>
                </div>
                <div class="stat-card tone-brand">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Depleted</span>
                        <div class="stat-icon"><i class="fa-solid fa-box-open"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($stats['depleted'] ?? 0) ?></div>
                    <div class="stat-foot">Fully used up</div>
                </div>
                <div class="stat-card tone-red">
                    <div class="stat-top">
                        <span class="stat-eyebrow">Expired</span>
                        <div class="stat-icon"><i class="fa-solid fa-ban"></i></div>
                    </div>
                    <div class="stat-value"><?= number_format($stats['expired'] ?? 0) ?></div>
                    <div class="stat-foot">Past expiry date</div>
                </div>
            </div>



            <!-- Toolbar / filters -->
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
                <span style="margin-left:auto;font-size:var(--fs-sm);color:var(--ink-3);"><?= number_format($totalCount) ?> batch<?= $totalCount !== 1 ? 'es' : '' ?></span>
            </div>

            <!-- Table -->
            <div class="table-card">
                <?php if ($batches && $batches->num_rows > 0): ?>
                    <div class="table-scroll">
                        <table class="data">
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
                                    $barColor = $pct > 50 ? 'var(--ok)' : ($pct > 20 ? 'var(--warn)' : 'var(--danger)');
                                    $isTrayUnit = stripos($b['unit'], 'tray') !== false;

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

                                    if ($b['status'] === 'active' && $daysLeft !== null && $daysLeft <= 3 && $daysLeft >= 0) {
                                        $badgeClass = 'sb-expiring';
                                        $badgeLabel = 'Expiring';
                                    } else {
                                        $badgeClass = 'sb-' . $b['status'];
                                        $badgeLabel = ucfirst($b['status']);
                                    }

                                    $unitLabel = $isTrayUnit ? ('tray' . ($b['remaining'] != 1 ? 's' : '')) : '';
                                ?>
                                    <tr>
                                        <td><span class="batch-code"><?= htmlspecialchars($b['batch_code']) ?></span></td>
                                        <td>
                                            <div style="font-weight:var(--fw-semi);color:var(--ink);"><?= htmlspecialchars($b['product_name'] ?? '—') ?></div>
                                            <div style="font-size:var(--fs-xs);color:var(--ink-3);"><?= htmlspecialchars($b['category_name'] ?? '') ?></div>
                                        </td>
                                        <td style="font-size:var(--fs-sm);color:var(--ink-2);"><?= htmlspecialchars($b['unit']) ?></td>
                                        <td>
                                            <div class="qty-bar-wrap">
                                                <div class="qty-nums"><?= number_format($b['remaining']) ?> / <?= number_format($b['quantity']) ?><?= $unitLabel ? ' ' . $unitLabel : '' ?></div>
                                                <div class="qty-bar">
                                                    <div class="qty-bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="status-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                                        <td class="expiry-cell <?= $expClass ?>"><?= $expText ?></td>
                                        <td style="font-size:var(--fs-xs);color:var(--ink-3);white-space:nowrap;">
                                            <?= date('M j, Y', strtotime($b['created_at'])) ?><br>
                                            <span style="font-size:0.7rem;"><?= date('g:i A', strtotime($b['created_at'])) ?></span>
                                        </td>
                                        <td style="font-size:var(--fs-sm);color:var(--ink-2);"><?= htmlspecialchars($b['created_by_name'] ?? 'Admin') ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

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
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                        <div class="empty-title">No batches found</div>
                        <div class="empty-text"><?= ($filterStatus || $filterProduct || $filterDate) ? 'Try adjusting your filters.' : 'Click "Add Batch" to log your first stock batch.' ?></div>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /.main-content -->
    </div><!-- /.admin-layout -->
</body>

</html>