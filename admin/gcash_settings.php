<?php
// ============================================================
// HATCH — Pickup Settings (payment is handled by PayMongo)
// File: admin/gcash_settings.php
// Purpose: Manage the store pickup address shown at checkout.
// (GCash QR / number / name removed — payments now go through
//  PayMongo hosted checkout, so no manual GCash details needed.)
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$activePage = 'gcash_settings';

// ── Local settings helpers ────────────────────────────────────
if (!function_exists('getSetting')) {
    function getSetting(mysqli $conn, string $key, string $default = ''): string
    {
        $k = $conn->real_escape_string($key);
        $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key = '{$k}' LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) return $row['setting_value'] ?? $default;
        return $default;
    }
}
if (!function_exists('saveSetting')) {
    function saveSetting(mysqli $conn, string $key, string $value): void
    {
        $k = $conn->real_escape_string($key);
        $v = $conn->real_escape_string($value);
        $conn->query("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('{$k}', '{$v}')
                      ON DUPLICATE KEY UPDATE setting_value = '{$v}', updated_at = NOW()");
    }
}

// ── Save pickup address ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pickup') {
    $pickup = trim($_POST['pickup_address'] ?? '');
    saveSetting($conn, 'pickup_address', $pickup);
    redirect('gcash_settings.php', 'success', 'Pickup address saved.');
}

$pickupAddress = getSetting($conn, 'pickup_address', "Hiney's Farm, Loreto, Cortes, Bohol");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pickup Settings — HATCH Admin</title>
    <link rel="stylesheet" href="assets/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style id="hineys-icon-colors">
        /* === Hiney's icon colors === */
        /* Icons inside dark/colored or interactive areas keep their inherited color */
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
            color: inherit !important;
        }

        /* Semantic colors for standalone content icons */
        .fa-egg {
            color: #f4a72c;
        }

        .fa-drumstick-bite {
            color: #c2703b;
        }

        .fa-circle-check,
        .fa-check,
        .fa-shield-halved,
        .fa-leaf,
        .fa-seedling,
        .fa-phone {
            color: #10b981;
        }

        .fa-circle-xmark,
        .fa-xmark,
        .fa-trash,
        .fa-ban,
        .fa-location-dot {
            color: #ef4444;
        }

        .fa-cart-shopping,
        .fa-bag-shopping,
        .fa-store,
        .fa-shop {
            color: #e67e22;
        }

        .fa-truck {
            color: #f97316;
        }

        .fa-triangle-exclamation,
        .fa-circle-exclamation,
        .fa-clock,
        .fa-star {
            color: #f59e0b;
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
            color: #3b82f6;
        }

        .fa-sack-dollar,
        .fa-money-bill,
        .fa-money-bill-transfer {
            color: #16a34a;
        }

        .fa-users,
        .fa-user,
        .fa-user-plus {
            color: #6366f1;
        }

        .fa-box,
        .fa-box-open,
        .fa-boxes-stacked,
        .fa-warehouse,
        .fa-receipt,
        .fa-clipboard-list,
        .fa-file-lines {
            color: #8b5cf6;
        }

        .fa-chart-bar,
        .fa-chart-line,
        .fa-chart-pie,
        .fa-gauge-high {
            color: #0ea5e9;
        }

        .fa-heart {
            color: #ef4444;
        }

        .fa-gear {
            color: #6b7280;
        }

        .fa-lightbulb {
            color: #f59e0b;
        }
    </style>
    <style>
        :root {
            --card-border: #e9e8e4;
        }

        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1;
            padding: 32px 32px 48px;
            min-height: 100vh;
            background: var(--page-bg);
            box-sizing: border-box;
        }

        .page-header {
            margin-bottom: 28px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title-sub {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }

        @media(max-width:960px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px 22px;
            border-bottom: 1px solid var(--card-border);
            background: #fafafa;
        }

        .card-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-body {
            padding: 22px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 16px;
        }

        .form-group:last-of-type {
            margin-bottom: 0;
        }

        .form-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--dark);
        }

        .form-label .req {
            color: #ef4444;
            margin-left: 2px;
        }

        .form-input,
        .form-textarea {
            padding: 10px 13px;
            border: 1.5px solid var(--card-border);
            border-radius: 9px;
            font-size: 0.88rem;
            font-family: inherit;
            color: var(--text);
            background: #fafafa;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            width: 100%;
        }

        .form-input:focus,
        .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.1);
            background: #fff;
        }

        .form-textarea {
            resize: vertical;
            min-height: 72px;
        }

        .form-hint {
            font-size: 0.72rem;
            color: var(--text-muted);
        }

        /* QR Upload area */
        .qr-current {
            background: #f9fafb;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 16px;
        }

        .qr-img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 10px;
            margin: 0 auto 12px;
            display: block;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--card-border);
        }

        .qr-none-icon {
            font-size: 4rem;
            margin-bottom: 8px;
            display: block;
        }

        .qr-none-text {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* Drop zone */
        .drop-zone {
            border: 2px dashed var(--card-border);
            border-radius: 12px;
            padding: 28px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #fafafa;
            position: relative;
        }

        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .drop-zone-icon {
            font-size: 2rem;
            margin-bottom: 8px;
            display: block;
        }

        .drop-zone-text {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .drop-zone-text strong {
            color: var(--primary);
        }

        .drop-zone-hint {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            border-radius: 9px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            border: 1.5px solid;
            transition: all 0.15s;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: #cf6d17;
        }

        .btn-danger {
            background: #ef4444;
            color: #fff;
            border-color: #ef4444;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border-color: var(--card-border);
        }

        .btn-ghost:hover {
            background: var(--page-bg);
        }

        .card-footer {
            padding: 14px 22px;
            border-top: 1px solid var(--card-border);
            background: #fafafa;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        /* Preview selected file */
        .preview-strip {
            display: none;
            align-items: center;
            gap: 10px;
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            border-radius: 8px;
            padding: 10px 14px;
            margin-top: 10px;
            font-size: 0.82rem;
            color: #065f46;
        }

        .preview-strip img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 6px;
        }

        /* Info badge */
        .info-badge {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.8rem;
            color: #1e40af;
            line-height: 1.5;
            margin-bottom: 16px;
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
            color: var(--dark);
        }

        @media(max-width:768px) {
            .main-content {
                margin-left: 0;
                padding: 16px 16px 48px;
            }

            .mobile-menu-btn {
                display: flex;
            }
        }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>
    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title"><i class="fa-solid fa-store"></i> Pickup Settings</h1>
            <div class="page-title-sub">Manage the pickup address shown to customers who choose Pick Up at checkout.</div>
        </div>

        <?= flash() ?>

        <div style="max-width:640px;">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-location-dot"></i> Pickup Address</div>
                </div>
                <form method="POST" action="gcash_settings.php">
                    <input type="hidden" name="action" value="save_pickup">
                    <div style="padding:22px 24px;">
                        <div class="form-group">
                            <label class="form-label">Pickup Address</label>
                            <textarea name="pickup_address" class="form-textarea" rows="3"
                                placeholder="Full address where customers can pick up their order..."><?= htmlspecialchars($pickupAddress) ?></textarea>
                            <span class="form-hint">Shown to customers who choose "Pick Up" as their delivery option.</span>
                        </div>
                        <div style="background:#eef6ff;border:1px solid #cfe3fb;border-radius:8px;padding:12px 14px;font-size:0.82rem;color:#2c5b8f;line-height:1.6;margin-top:6px;">
                            <i class="fa-solid fa-circle-info"></i> Online payments (GCash, Maya, QR Ph) are now processed automatically through PayMongo at checkout — no GCash QR or account details need to be managed here anymore.
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> Save Pickup Address</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>