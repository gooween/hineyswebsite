<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/delivery_zones.php
// Manage delivery zones (municipality / barangay / fee).
// ============================================================

session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
requireAdmin();   // <-- if your admin guard has a different name, change this line

$activePage = 'delivery_zones';

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add' || $action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $m    = trim($_POST['municipality'] ?? '');
        $b    = trim($_POST['barangay'] ?? '');
        $fee  = (float)($_POST['fee'] ?? 0);
        $act  = isset($_POST['active']) ? 1 : 0;

        if ($m === '' || $b === '') {
            $_SESSION['flash_type'] = 'error';
            $_SESSION['flash_message'] = 'Municipality and barangay are required.';
            header('Location: delivery_zones.php');
            exit;
        }

        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO delivery_zones (municipality, barangay, fee, active) VALUES (?,?,?,?)");
            $stmt->bind_param('ssdi', $m, $b, $fee, $act);
            if (!$stmt->execute()) {
                $_SESSION['flash_type'] = 'error';
                $_SESSION['flash_message'] = 'That zone already exists.';
            } else {
                $_SESSION['flash_type'] = 'success';
                $_SESSION['flash_message'] = 'Zone added.';
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("UPDATE delivery_zones SET municipality=?, barangay=?, fee=?, active=? WHERE id=?");
            $stmt->bind_param('ssdii', $m, $b, $fee, $act, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_type'] = 'success';
            $_SESSION['flash_message'] = 'Zone updated.';
        }
        header('Location: delivery_zones.php');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM delivery_zones WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_type'] = 'success';
        $_SESSION['flash_message'] = 'Zone deleted.';
        header('Location: delivery_zones.php');
        exit;
    }
}

// ── Fetch all zones grouped by municipality ───────────────────
$zones = [];
$res = $conn->query("SELECT * FROM delivery_zones ORDER BY municipality ASC, barangay ASC");
while ($r = $res->fetch_assoc()) {
    $zones[$r['municipality']][] = $r;
}
$totalZones = $conn->query("SELECT COUNT(*) c FROM delivery_zones")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <title>Delivery Zones — Hiney's Admin</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        :root {
            --primary: #e67e22;
            --primary-dark: #cf6d17;
            --primary-light: #fef3e8;
            --dark2: #2c3e50;
            --text: #374151;
            --muted: #6b7280;
            --bg: #faf9f7;
            --card: #fff;
            --border: #e5e7eb;
            --success: #10b981;
            --danger: #ef4444;
            --radius: 14px;
            --shadow: 0 2px 8px rgba(0, 0, 0, .06), 0 8px 24px rgba(0, 0, 0, .05);
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6
        }

        a {
            text-decoration: none;
            color: inherit
        }

        .wrap {
            max-width: 1000px;
            margin: 0 auto;
            padding: 32px 24px 64px
        }

        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark2);
            display: flex;
            align-items: center;
            gap: 10px
        }

        .page-sub {
            font-size: .85rem;
            color: var(--muted);
            margin-top: 2px
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 18px;
            border-radius: 9px;
            font-size: .86rem;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-family: inherit;
            transition: all .2s
        }

        .btn-primary {
            background: var(--primary);
            color: #fff
        }

        .btn-primary:hover {
            background: var(--primary-dark)
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: .78rem
        }

        .btn-ghost {
            background: #fff;
            border: 1.5px solid var(--border);
            color: var(--muted)
        }

        .btn-ghost:hover {
            border-color: var(--primary);
            color: var(--primary)
        }

        .btn-danger {
            background: #fef2f2;
            border: 1.5px solid #fecaca;
            color: #991b1b
        }

        .btn-danger:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger)
        }

        .flash {
            padding: 12px 16px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: .87rem;
            font-weight: 600
        }

        .flash.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #86efac
        }

        .flash.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px
        }

        .card-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            background: #fafafa;
            font-weight: 800;
            color: var(--dark2);
            font-size: .95rem;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .muni-tag {
            font-size: .72rem;
            font-weight: 700;
            color: var(--primary);
            background: var(--primary-light);
            padding: 2px 10px;
            border-radius: 20px;
            margin-left: auto
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            text-align: left;
            padding: 11px 20px;
            font-size: .86rem;
            border-bottom: 1px solid #f3f4f6
        }

        th {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            background: #fcfcfc
        }

        tr:last-child td {
            border-bottom: none
        }

        .fee-val {
            font-weight: 800;
            color: var(--dark2)
        }

        .fee-free {
            color: var(--success);
            font-weight: 800
        }

        .badge {
            font-size: .68rem;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 20px
        }

        .badge-on {
            background: #d1fae5;
            color: #065f46
        }

        .badge-off {
            background: #f3f4f6;
            color: #6b7280
        }

        .actions {
            display: flex;
            gap: 6px;
            justify-content: flex-end
        }

        .inline-form {
            display: inline
        }

        /* Modal */
        .modal-bg {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 20px
        }

        .modal-bg.show {
            display: flex
        }

        .modal {
            background: #fff;
            border-radius: var(--radius);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
            box-shadow: 0 24px 48px rgba(0, 0, 0, .2)
        }

        .modal-head {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            font-weight: 800;
            color: var(--dark2);
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .modal-body {
            padding: 22px
        }

        .modal-foot {
            padding: 14px 22px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px
        }

        .fg {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 14px
        }

        .fg label {
            font-size: .8rem;
            font-weight: 700;
            color: var(--dark2)
        }

        .fg input {
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: .88rem;
            font-family: inherit;
            outline: none
        }

        .fg input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, .1)
        }

        .fg-check {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .86rem;
            color: var(--text)
        }

        .fg-check input {
            width: 16px;
            height: 16px;
            accent-color: var(--primary)
        }

        .x-btn {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: var(--muted);
            line-height: 1
        }

        .empty {
            text-align: center;
            padding: 50px 20px;
            color: var(--muted)
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .84rem;
            color: var(--muted);
            margin-bottom: 18px
        }

        .back-link:hover {
            color: var(--primary)
        }
    </style>
</head>

<body>
    <div class="wrap">

        <a href="dashboard.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>

        <div class="page-head">
            <div>
                <div class="page-title"><i class="fa-solid fa-truck" style="color:#f97316"></i> Delivery Zones</div>
                <div class="page-sub"><?= (int)$totalZones ?> zone<?= (int)$totalZones !== 1 ? 's' : '' ?> configured · fees shown to customers at checkout</div>
            </div>
            <button class="btn btn-primary" onclick="openAdd()"><i class="fa-solid fa-plus"></i> Add Zone</button>
        </div>

        <?php if (!empty($_SESSION['flash_message'])): ?>
            <div class="flash <?= $_SESSION['flash_type'] ?? 'success' ?>"><?= $_SESSION['flash_message'] ?></div>
            <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <?php if (empty($zones)): ?>
            <div class="card">
                <div class="empty">
                    <div style="font-size:2.5rem;margin-bottom:10px;"><i class="fa-solid fa-map-location-dot" style="color:#3b82f6"></i></div>
                    No delivery zones yet. Click <strong>Add Zone</strong> to create one.
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($zones as $muni => $rows): ?>
                <div class="card">
                    <div class="card-head">
                        <i class="fa-solid fa-location-dot" style="color:#ef4444"></i> <?= htmlspecialchars($muni) ?>
                        <span class="muni-tag"><?= count($rows) ?> barangay<?= count($rows) !== 1 ? 's' : '' ?></span>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Barangay</th>
                                <th>Fee</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $z): ?>
                                <tr>
                                    <td><?= htmlspecialchars($z['barangay']) ?></td>
                                    <td>
                                        <?php if ((float)$z['fee'] == 0): ?>
                                            <span class="fee-free">FREE</span>
                                        <?php else: ?>
                                            <span class="fee-val">₱<?= number_format((float)$z['fee'], 2) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($z['active']): ?><span class="badge badge-on">Active</span>
                                        <?php else: ?><span class="badge badge-off">Hidden</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <button class="btn btn-ghost btn-sm"
                                                onclick='openEdit(<?= json_encode($z, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                <i class="fa-solid fa-pen"></i> Edit
                                            </button>
                                            <form method="POST" class="inline-form" onsubmit="return confirm('Delete this zone?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal-bg" id="modalBg">
        <div class="modal">
            <form method="POST">
                <div class="modal-head">
                    <span id="modalTitle">Add Zone</span>
                    <button type="button" class="x-btn" onclick="closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="fAction" value="add">
                    <input type="hidden" name="id" id="fId" value="0">
                    <div class="fg">
                        <label>Municipality / City</label>
                        <input type="text" name="municipality" id="fMuni" placeholder="e.g. Cortes" required>
                    </div>
                    <div class="fg">
                        <label>Barangay</label>
                        <input type="text" name="barangay" id="fBrgy" placeholder="e.g. Poblacion" required>
                    </div>
                    <div class="fg">
                        <label>Delivery Fee (₱) — enter 0 for free</label>
                        <input type="number" name="fee" id="fFee" step="0.01" min="0" value="0.00" required>
                    </div>
                    <div class="fg-check">
                        <input type="checkbox" name="active" id="fActive" checked>
                        <label for="fActive" style="margin:0;cursor:pointer;">Active (show to customers)</label>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Zone</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAdd() {
            document.getElementById('modalTitle').textContent = 'Add Zone';
            document.getElementById('fAction').value = 'add';
            document.getElementById('fId').value = 0;
            document.getElementById('fMuni').value = '';
            document.getElementById('fBrgy').value = '';
            document.getElementById('fFee').value = '0.00';
            document.getElementById('fActive').checked = true;
            document.getElementById('modalBg').classList.add('show');
        }

        function openEdit(z) {
            document.getElementById('modalTitle').textContent = 'Edit Zone';
            document.getElementById('fAction').value = 'edit';
            document.getElementById('fId').value = z.id;
            document.getElementById('fMuni').value = z.municipality;
            document.getElementById('fBrgy').value = z.barangay;
            document.getElementById('fFee').value = parseFloat(z.fee).toFixed(2);
            document.getElementById('fActive').checked = z.active == 1;
            document.getElementById('modalBg').classList.add('show');
        }

        function closeModal() {
            document.getElementById('modalBg').classList.remove('show');
        }
        document.getElementById('modalBg').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>

</html>