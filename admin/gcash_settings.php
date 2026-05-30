<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/gcash_settings.php
// Purpose: Upload GCash QR image + manage payment settings
// ============================================================

session_start();
require_once '../config/db.php';
requireAdmin();

$activePage = 'gcash_settings';

// ── Ensure upload directory exists ───────────────────────────
$uploadDir = '../uploads/gcash/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── Fetch current settings ────────────────────────────────────
function getSetting(mysqli $conn, string $key, string $default = ''): string {
    $k = $conn->real_escape_string($key);
    $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key = '{$k}' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) return $row['setting_value'] ?? $default;
    return $default;
}

function saveSetting(mysqli $conn, string $key, string $value): void {
    $k = $conn->real_escape_string($key);
    $v = $conn->real_escape_string($value);
    $conn->query("INSERT INTO settings (setting_key, setting_value)
                  VALUES ('{$k}', '{$v}')
                  ON DUPLICATE KEY UPDATE setting_value = '{$v}', updated_at = NOW()");
}

// ── Handle POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // Save GCash info
    if ($action === 'save_gcash_info') {
        $number  = clean($_POST['gcash_number']  ?? '', $conn);
        $name    = clean($_POST['gcash_name']    ?? '', $conn);
        $pickup  = clean($_POST['pickup_address'] ?? '', $conn);
        $delfee  = (float)($_POST['delivery_fee'] ?? 50);

        saveSetting($conn, 'gcash_number',   $number);
        saveSetting($conn, 'gcash_name',     $name);
        saveSetting($conn, 'pickup_address', $pickup);
        saveSetting($conn, 'delivery_fee',   (string)$delfee);

        redirect('gcash_settings.php', 'success', 'Settings saved successfully.');
    }

    // Upload QR image
    if ($action === 'upload_qr') {
        if (!isset($_FILES['qr_image']) || $_FILES['qr_image']['error'] !== UPLOAD_ERR_OK) {
            redirect('gcash_settings.php', 'error', 'No file uploaded or upload error.');
        }

        $file     = $_FILES['qr_image'];
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($ext, $allowed)) {
            redirect('gcash_settings.php', 'error', 'Invalid file type. Use JPG, PNG, GIF or WEBP.');
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            redirect('gcash_settings.php', 'error', 'File too large. Max 5MB.');
        }

        // Delete old QR if exists
        $oldPath = getSetting($conn, 'gcash_qr_path');
        if ($oldPath && file_exists('../' . $oldPath)) {
            unlink('../' . $oldPath);
        }

        $filename   = 'gcash_qr_' . time() . '.' . $ext;
        $destPath   = $uploadDir . $filename;
        $publicPath = 'uploads/gcash/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            saveSetting($conn, 'gcash_qr_path', $publicPath);
            redirect('gcash_settings.php', 'success', 'GCash QR image uploaded successfully.');
        } else {
            redirect('gcash_settings.php', 'error', 'Failed to save file. Check folder permissions.');
        }
    }

    // Delete QR
    if ($action === 'delete_qr') {
        $oldPath = getSetting($conn, 'gcash_qr_path');
        if ($oldPath && file_exists('../' . $oldPath)) {
            unlink('../' . $oldPath);
        }
        saveSetting($conn, 'gcash_qr_path', '');
        redirect('gcash_settings.php', 'success', 'QR image removed.');
    }
}

// ── Load settings ─────────────────────────────────────────────
$gcashNumber    = getSetting($conn, 'gcash_number',   '0917-XXX-XXXX');
$gcashName      = getSetting($conn, 'gcash_name',     "Hiney's Eggs & Live Chicken");
$gcashQrPath    = getSetting($conn, 'gcash_qr_path',  '');
$pickupAddress  = getSetting($conn, 'pickup_address', 'Hiney\'s Farm, Puerto Princesa City, Palawan');
$deliveryFee    = getSetting($conn, 'delivery_fee',   '50.00');
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
<title>Payment Settings — Hiney's Admin</title>
<style>
:root { --card-border: #e9e8e4; }
.main-content {
    margin-left: var(--sidebar-w); flex: 1;
    padding: 32px 32px 48px; min-height: 100vh;
    background: var(--page-bg); box-sizing: border-box;
}
.page-header { margin-bottom: 28px; }
.page-title { font-size: 1.5rem; font-weight: 800; color: var(--dark); letter-spacing: -0.02em; display:flex; align-items:center; gap:10px; }
.page-title-sub { font-size: 0.82rem; color: var(--text-muted); margin-top: 2px; }

.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    align-items: start;
}
@media(max-width:960px) { .settings-grid { grid-template-columns: 1fr; } }

.card {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden;
}
.card-header {
    display: flex; align-items: center; gap: 10px;
    padding: 16px 22px; border-bottom: 1px solid var(--card-border);
    background: #fafafa;
}
.card-title { font-size: 0.95rem; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.card-body  { padding: 22px; }

.form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 16px; }
.form-group:last-of-type { margin-bottom: 0; }
.form-label { font-size: 0.8rem; font-weight: 700; color: var(--dark); }
.form-label .req { color: #ef4444; margin-left: 2px; }
.form-input, .form-textarea {
    padding: 10px 13px; border: 1.5px solid var(--card-border);
    border-radius: 9px; font-size: 0.88rem; font-family: inherit;
    color: var(--text); background: #fafafa; outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
    width: 100%;
}
.form-input:focus, .form-textarea:focus {
    border-color: var(--primary); box-shadow: 0 0 0 3px rgba(230,126,34,0.1); background: #fff;
}
.form-textarea { resize: vertical; min-height: 72px; }
.form-hint { font-size: 0.72rem; color: var(--text-muted); }

/* QR Upload area */
.qr-current {
    background: #f9fafb; border: 1px solid var(--card-border);
    border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 16px;
}
.qr-img {
    max-width: 200px; max-height: 200px;
    border-radius: 10px; margin: 0 auto 12px;
    display: block; box-shadow: 0 4px 16px rgba(0,0,0,0.1);
    border: 1px solid var(--card-border);
}
.qr-none-icon { font-size: 4rem; margin-bottom: 8px; display: block; }
.qr-none-text { font-size: 0.85rem; color: var(--text-muted); }

/* Drop zone */
.drop-zone {
    border: 2px dashed var(--card-border);
    border-radius: 12px; padding: 28px 20px;
    text-align: center; cursor: pointer;
    transition: all 0.2s; background: #fafafa;
    position: relative;
}
.drop-zone:hover, .drop-zone.dragover {
    border-color: var(--primary); background: var(--primary-light);
}
.drop-zone input[type="file"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.drop-zone-icon { font-size: 2rem; margin-bottom: 8px; display: block; }
.drop-zone-text { font-size: 0.85rem; color: var(--text-muted); }
.drop-zone-text strong { color: var(--primary); }
.drop-zone-hint { font-size: 0.72rem; color: var(--text-muted); margin-top: 4px; }

/* Buttons */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 20px; border-radius: 9px; font-size: 0.88rem;
    font-weight: 700; cursor: pointer; border: 1.5px solid;
    transition: all 0.15s; font-family: inherit;
}
.btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
.btn-primary:hover { background: #cf6d17; }
.btn-danger  { background: #ef4444; color: #fff; border-color: #ef4444; }
.btn-danger:hover { background: #dc2626; }
.btn-ghost   { background: transparent; color: var(--text-muted); border-color: var(--card-border); }
.btn-ghost:hover { background: var(--page-bg); }

.card-footer {
    padding: 14px 22px; border-top: 1px solid var(--card-border);
    background: #fafafa; display: flex; gap: 10px; justify-content: flex-end;
}

/* Preview selected file */
.preview-strip {
    display: none; align-items: center; gap: 10px;
    background: #ecfdf5; border: 1px solid #6ee7b7;
    border-radius: 8px; padding: 10px 14px; margin-top: 10px;
    font-size: 0.82rem; color: #065f46;
}
.preview-strip img { width: 40px; height: 40px; object-fit: cover; border-radius: 6px; }

/* Info badge */
.info-badge {
    display: flex; align-items: flex-start; gap: 8px;
    background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: 8px; padding: 10px 14px;
    font-size: 0.8rem; color: #1e40af; line-height: 1.5;
    margin-bottom: 16px;
}

.mobile-menu-btn {
    display: none; align-items: center; justify-content: center;
    width: 36px; height: 36px; border: 1px solid var(--card-border);
    border-radius: 8px; background: var(--card-bg); cursor: pointer; color: var(--dark);
}
@media(max-width:768px) {
    .main-content { margin-left: 0; padding: 16px 16px 48px; }
    .mobile-menu-btn { display: flex; }
}
</style>
</head>
<body>
<div class="admin-layout">
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">

    <div class="page-header">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
            <button class="mobile-menu-btn" onclick="openSidebar()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <h1 class="page-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Payment Settings
            </h1>
        </div>
        <div class="page-title-sub">Manage GCash QR code, pickup address, and delivery fee</div>
    </div>

    <?= flash() ?>

    <div class="settings-grid">

        <!-- ── LEFT: GCash QR Upload ── -->
        <div>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-mobile-screen"></i> GCash QR Code</div>
                </div>
                <div class="card-body">

                    <div class="info-badge">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <span>This QR code will be shown to customers who select <strong>GCash</strong> as their payment method during checkout.</span>
                    </div>

                    <!-- Current QR -->
                    <div class="qr-current">
                        <?php if ($gcashQrPath && file_exists('../' . $gcashQrPath)): ?>
                            <img src="../<?= htmlspecialchars($gcashQrPath) ?>?v=<?= time() ?>"
                                 alt="GCash QR Code" class="qr-img" id="currentQrImg">
                            <div style="font-size:0.82rem;color:#065f46;font-weight:600;">✓ QR Code is active</div>
                        <?php else: ?>
                            <span class="qr-none-icon"><i class="fa-solid fa-camera"></i></span>
                            <div class="qr-none-text">No QR code uploaded yet</div>
                        <?php endif; ?>
                    </div>

                    <!-- Upload form -->
                    <form method="POST" action="gcash_settings.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_qr">

                        <div class="drop-zone" id="dropZone">
                            <input type="file" name="qr_image" id="qrFileInput"
                                   accept="image/jpeg,image/png,image/gif,image/webp"
                                   onchange="previewFile(this)">
                            <span class="drop-zone-icon"><i class="fa-solid fa-upload"></i></span>
                            <div class="drop-zone-text">
                                <strong>Click to upload</strong> or drag & drop
                            </div>
                            <div class="drop-zone-hint">JPG, PNG, GIF, WEBP — Max 5MB</div>
                        </div>

                        <div class="preview-strip" id="previewStrip">
                            <img id="previewImg" src="" alt="Preview">
                            <div>
                                <div style="font-weight:600;" id="previewName">filename.png</div>
                                <div style="font-size:0.72rem;color:#065f46;">Ready to upload</div>
                            </div>
                        </div>

                        <div class="card-footer" style="margin: 16px -22px -22px; border-radius:0 0 var(--radius) var(--radius);">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa-solid fa-upload"></i> Upload QR Image
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete QR -->
            <?php if ($gcashQrPath && file_exists('../' . $gcashQrPath)): ?>
            <div class="card">
                <div class="card-body" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                    <div>
                        <div style="font-size:0.88rem;font-weight:600;color:var(--dark);">Remove QR Code</div>
                        <div style="font-size:0.78rem;color:var(--text-muted);">This will remove the QR image from the checkout page.</div>
                    </div>
                    <form method="POST" action="gcash_settings.php"
                          onsubmit="return confirm('Remove the GCash QR image?')">
                        <input type="hidden" name="action" value="delete_qr">
                        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Remove</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── RIGHT: Business Settings ── -->
        <div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-gear"></i> Payment & Delivery Settings</div>
                </div>
                <form method="POST" action="gcash_settings.php">
                    <input type="hidden" name="action" value="save_gcash_info">
                    <div class="card-body">

                        <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:var(--primary);margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid #fde9d0;">
                            GCash Account Info
                        </div>

                        <div class="form-group">
                            <label class="form-label">GCash Number <span class="req">*</span></label>
                            <input type="text" name="gcash_number" class="form-input"
                                   value="<?= htmlspecialchars($gcashNumber) ?>"
                                   placeholder="e.g. 0917-123-4567" required>
                            <span class="form-hint">Displayed to customers on the checkout page</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">GCash Account Name <span class="req">*</span></label>
                            <input type="text" name="gcash_name" class="form-input"
                                   value="<?= htmlspecialchars($gcashName) ?>"
                                   placeholder="e.g. Hiney's Eggs & Live Chicken" required>
                        </div>

                        <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:var(--primary);margin:18px 0 12px;padding-bottom:6px;border-bottom:1px solid #fde9d0;">
                            Delivery & Pickup
                        </div>

                        <div class="form-group">
                            <label class="form-label">Delivery Fee (₱)</label>
                            <input type="number" name="delivery_fee" class="form-input"
                                   value="<?= htmlspecialchars($deliveryFee) ?>"
                                   step="0.01" min="0" placeholder="50.00">
                            <span class="form-hint">Flat delivery fee added to every delivery order</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Pickup Address</label>
                            <textarea name="pickup_address" class="form-textarea"
                                      placeholder="Enter full address where customers can pick up..."><?= htmlspecialchars($pickupAddress) ?></textarea>
                            <span class="form-hint">Shown to customers who choose "Pick Up" as delivery option</span>
                        </div>

                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- Preview box -->
            <div class="card" style="margin-top:20px;">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-eye"></i> Customer Preview</div>
                </div>
                <div class="card-body">
                    <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;">
                        This is what customers will see when they select GCash:
                    </div>
                    <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:16px;">
                        <div style="font-size:0.82rem;font-weight:700;color:#1e40af;margin-bottom:10px;">
                            <i class="fa-solid fa-mobile-screen"></i> Send GCash Payment To:
                        </div>
                        <?php if ($gcashQrPath && file_exists('../' . $gcashQrPath)): ?>
                        <div style="text-align:center;margin-bottom:10px;">
                            <img src="../<?= htmlspecialchars($gcashQrPath) ?>?v=<?= time() ?>"
                                 style="max-width:120px;border-radius:8px;border:1px solid #bfdbfe;"
                                 alt="QR Preview">
                        </div>
                        <?php endif; ?>
                        <div style="background:#fff;border:1.5px solid #bfdbfe;border-radius:8px;padding:10px 14px;font-size:1rem;font-weight:800;color:#1e40af;letter-spacing:0.06em;text-align:center;margin-bottom:8px;">
                            <?= htmlspecialchars($gcashNumber) ?>
                        </div>
                        <div style="font-size:0.78rem;color:#1e40af;text-align:center;">
                            Account Name: <strong><?= htmlspecialchars($gcashName) ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.settings-grid -->
</div><!-- /.main-content -->
</div><!-- /.admin-layout -->

<script>
// ── File preview ───────────────────────────────────────────────
function previewFile(input) {
    const strip = document.getElementById('previewStrip');
    const img   = document.getElementById('previewImg');
    const name  = document.getElementById('previewName');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            img.src = e.target.result;
            name.textContent = input.files[0].name;
            strip.style.display = 'flex';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ── Drag & drop highlight ──────────────────────────────────────
const dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop',      e => { e.preventDefault(); dz.classList.remove('dragover'); });
}
</script>
</body>
</html>