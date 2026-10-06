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
    if ($pickup === '') {
        redirect('gcash_settings.php', 'error', 'Enter a pickup address before saving — customers see it at checkout.');
    }
    saveSetting($conn, 'pickup_address', mb_substr($pickup, 0, 255));
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
        .fa-circle-info,
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
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        .page-title-sub {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 4px;
            max-width: 60ch;
        }

        /* Form on the left, live preview + notes on the right */
        .settings-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(280px, 1fr);
            gap: 24px;
            align-items: start;
            max-width: 1040px;
        }

        .settings-side {
            display: flex;
            flex-direction: column;
            gap: 24px;
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
            margin: 0;
        }

        .card-body {
            padding: 22px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--dark);
        }

        .form-label .req {
            color: #ef4444;
            margin-left: 2px;
        }

        .form-textarea {
            width: 100%;
            min-height: 110px;
            padding: 11px 13px;
            border: 1.5px solid var(--card-border);
            border-radius: 9px;
            font-size: 0.92rem;
            line-height: 1.5;
            font-family: inherit;
            color: var(--text);
            background: #fafafa;
            outline: none;
            resize: vertical;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }

        .form-textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, 0.14);
            background: #fff;
        }

        .field-meta {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            font-size: 0.76rem;
            color: var(--text-muted);
        }

        .field-meta .count.near {
            color: #b45309;
            font-weight: 700;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 10px 20px;
            border-radius: 9px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            border: 1.5px solid;
            transition: background 0.15s, opacity 0.15s;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .btn-primary:hover:not(:disabled) {
            background: #cf6d17;
        }

        .btn-ghost {
            background: transparent;
            color: var(--text-muted);
            border-color: var(--card-border);
        }

        .btn-ghost:hover:not(:disabled) {
            background: var(--page-bg);
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn:focus-visible {
            outline: 3px solid rgba(230, 126, 34, 0.45);
            outline-offset: 2px;
        }

        .card-footer {
            padding: 14px 22px;
            border-top: 1px solid var(--card-border);
            background: #fafafa;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .save-state {
            margin-right: auto;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .save-state.dirty {
            color: #b45309;
            font-weight: 600;
        }

        /* "What customers see" preview */
        .pickup-preview {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding: 14px 16px;
            border: 1.5px solid var(--primary);
            border-radius: 11px;
            background: var(--primary-light, #fff7ed);
        }

        .pickup-preview .pin {
            flex: 0 0 38px;
            height: 38px;
            border-radius: 50%;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .pickup-preview .lbl {
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        .pickup-preview .addr {
            margin-top: 2px;
            font-size: 0.92rem;
            font-weight: 600;
            color: var(--dark);
            line-height: 1.5;
            white-space: pre-line;
            overflow-wrap: anywhere;
        }

        .pickup-preview .addr.empty {
            font-weight: 500;
            font-style: italic;
            color: var(--text-muted);
        }

        .preview-note {
            margin: 12px 0 0;
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        .notice {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            padding: 14px 16px;
            border: 1px solid #cfe3fb;
            border-radius: var(--radius);
            background: #eef6ff;
            color: #2c5b8f;
            font-size: 0.84rem;
            line-height: 1.6;
        }

        .notice i {
            margin-top: 3px;
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

        @media (max-width: 960px) {
            .settings-layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 16px 16px 48px;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .card-footer {
                flex-wrap: wrap;
            }

            .card-footer .btn-primary {
                flex: 1 1 100%;
                order: -1;
            }

            .save-state {
                flex: 1 1 100%;
                margin: 0;
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

        <div class="settings-layout">
            <form class="card" method="POST" action="gcash_settings.php" id="pickupForm">
                <input type="hidden" name="action" value="save_pickup">
                <div class="card-header">
                    <h2 class="card-title"><i class="fa-solid fa-location-dot"></i> Pickup address</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" for="pickup_address">Where customers pick up their order <span class="req">*</span></label>
                        <textarea id="pickup_address" name="pickup_address" class="form-textarea" rows="4" maxlength="255" required
                            placeholder="Street, barangay, municipality, province — add a landmark if it helps."><?= htmlspecialchars($pickupAddress) ?></textarea>
                        <div class="field-meta">
                            <span>Shown to customers who choose Pick Up at checkout.</span>
                            <span class="count" id="addrCount" aria-live="polite">0 / 255</span>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <span class="save-state" id="saveState" role="status">All changes saved</span>
                    <button type="button" class="btn btn-ghost" id="resetBtn" disabled>Discard changes</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn" disabled><i class="fa-solid fa-check"></i> Save changes</button>
                </div>
            </form>

            <div class="settings-side">
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title"><i class="fa-solid fa-store"></i> What customers see</h2>
                    </div>
                    <div class="card-body">
                        <div class="pickup-preview">
                            <div class="pin"><i class="fa-solid fa-location-dot"></i></div>
                            <div>
                                <div class="lbl">Pick up at</div>
                                <div class="addr" id="addrPreview"></div>
                            </div>
                        </div>
                        <p class="preview-note">Updates as you type. It goes live when you save.</p>
                    </div>
                </div>

                <div class="notice">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>GCash, Maya and QR Ph payments are handled automatically by PayMongo at checkout, so there are no payment details to manage here.</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            var ta = document.getElementById('pickup_address'),
                prev = document.getElementById('addrPreview'),
                cnt = document.getElementById('addrCount'),
                save = document.getElementById('saveBtn'),
                reset = document.getElementById('resetBtn'),
                state = document.getElementById('saveState'),
                saved = ta.value;

            function sync() {
                var v = ta.value.trim(),
                    dirty = ta.value !== saved;
                prev.textContent = v || 'No address set yet';
                prev.classList.toggle('empty', !v);
                cnt.textContent = ta.value.length + ' / 255';
                cnt.classList.toggle('near', ta.value.length >= 230);
                save.disabled = !dirty || !v;
                reset.disabled = !dirty;
                state.textContent = dirty ? (v ? 'Unsaved changes' : 'Enter an address to save') : 'All changes saved';
                state.classList.toggle('dirty', dirty);
            }
            ta.addEventListener('input', sync);
            reset.addEventListener('click', function() {
                ta.value = saved;
                sync();
                ta.focus();
            });
            sync();
        })();
    </script>
</body>

</html>