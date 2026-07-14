<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/delivery_zones.php
//
// Manage delivery zones (municipality / barangay / fee).
//   - Active zones are shown to customers at checkout.
//   - Archiving hides a zone but KEEPS the row (soft delete).
//   - Archived zones can be restored, or permanently deleted
//     via a type-to-confirm modal. A zone must be archived
//     before it can be permanently deleted.
// ============================================================

session_start();
require_once '../config/db.php';
require_once '../config/auth.php';
requireAdmin();   // <-- if your admin guard has a different name, change this line

$activePage = 'delivery_zones';

// Official Bohol municipality -> barangay reference data (powers the picker)
require_once __DIR__ . '/bohol_locations.php';   // defines $BOHOL_LOCATIONS

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // ── Add / Edit ────────────────────────────────────────────
    if ($action === 'add' || $action === 'edit') {
        $id  = (int)($_POST['id'] ?? 0);
        $m   = trim($_POST['municipality'] ?? '');
        $b   = trim($_POST['barangay'] ?? '');
        $fee = (float)($_POST['fee'] ?? 0);
        $act = isset($_POST['active']) ? 1 : 0;

        if ($m === '' || $b === '') {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Municipality and barangay are required.';
            header('Location: delivery_zones.php');
            exit;
        }

        // ── Server-side: municipality must be a real Bohol municipality ──
        if (!isset($BOHOL_LOCATIONS[$m])) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = htmlspecialchars($m) . ' is not a Bohol municipality. Please pick one from the list.';
            header('Location: delivery_zones.php');
            exit;
        }

        // ── Server-side: barangay must belong to that municipality ──
        //    (case-insensitive match, then normalize to the official spelling)
        $official = null;
        foreach ($BOHOL_LOCATIONS[$m] as $ob) {
            if (strcasecmp($ob, $b) === 0) {
                $official = $ob;
                break;
            }
        }
        if ($official === null) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = htmlspecialchars($b) . ' is not a barangay of ' . htmlspecialchars($m) . '. Please select one from the list.';
            header('Location: delivery_zones.php');
            exit;
        }
        $b = $official;   // store the canonical spelling

        // ── Server-side: block duplicates (active OR archived) ──
        //    When editing, a zone is not a duplicate of itself.
        $dup = $conn->prepare("SELECT id, active FROM delivery_zones WHERE municipality=? AND barangay=? LIMIT 1");
        $dup->bind_param('ss', $m, $b);
        $dup->execute();
        $dupRow = $dup->get_result()->fetch_assoc();
        $dup->close();

        if ($dupRow && (int)$dupRow['id'] !== $id) {
            $_SESSION['flash_type'] = 'error';
            $_SESSION['flash_message'] = (int)$dupRow['active'] === 0
                ? htmlspecialchars($b) . ', ' . htmlspecialchars($m) . ' already exists in the archive — restore it instead of adding a duplicate.'
                : htmlspecialchars($b) . ', ' . htmlspecialchars($m) . ' is already an active delivery zone.';
            header('Location: delivery_zones.php');
            exit;
        }

        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO delivery_zones (municipality, barangay, fee, active) VALUES (?,?,?,?)");
            $stmt->bind_param('ssdi', $m, $b, $fee, $act);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_type']    = 'success';
            $_SESSION['flash_message'] = 'Zone added.';
        } else {
            $stmt = $conn->prepare("UPDATE delivery_zones SET municipality=?, barangay=?, fee=?, active=? WHERE id=?");
            $stmt->bind_param('ssdii', $m, $b, $fee, $act, $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_type']    = 'success';
            $_SESSION['flash_message'] = 'Zone updated.';
        }
        header('Location: delivery_zones.php');
        exit;
    }

    // ── Archive (soft delete — hide from customers, keep the row) ──
    if ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE delivery_zones SET active=0 WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_type']    = 'success';
        $_SESSION['flash_message'] = 'Zone archived — hidden from customers but kept on file.';
        header('Location: delivery_zones.php');
        exit;
    }

    // ── Restore an archived zone ──────────────────────────────
    if ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE delivery_zones SET active=1 WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_type']    = 'success';
        $_SESSION['flash_message'] = 'Zone restored — visible to customers again.';
        header('Location: delivery_zones.php');
        exit;
    }

    // ── Permanent delete — ONLY allowed on already-archived zones ──
    if ($action === 'delete') {
        $id      = (int)($_POST['id'] ?? 0);
        $confirm = trim($_POST['confirm_text'] ?? '');

        // Server-side confirmation (never trust the browser alone)
        if ($confirm !== 'DELETE') {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Delete not confirmed. Type DELETE to confirm.';
            header('Location: delivery_zones.php');
            exit;
        }

        $chk = $conn->prepare("SELECT active FROM delivery_zones WHERE id=? LIMIT 1");
        $chk->bind_param('i', $id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$row) {
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Zone not found.';
        } elseif ((int)$row['active'] === 1) {
            // Guard: an active zone can never be hard-deleted
            $_SESSION['flash_type']    = 'error';
            $_SESSION['flash_message'] = 'Archive the zone first before permanently deleting it.';
        } else {
            $stmt = $conn->prepare("DELETE FROM delivery_zones WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash_type']    = 'success';
            $_SESSION['flash_message'] = 'Zone permanently deleted.';
        }
        header('Location: delivery_zones.php');
        exit;
    }
}

// ── Fetch zones, split into active / archived ─────────────────
$activeZones   = [];
$archivedZones = [];
$res = $conn->query("SELECT * FROM delivery_zones ORDER BY municipality ASC, barangay ASC");
while ($r = $res->fetch_assoc()) {
    if ((int)$r['active'] === 1) {
        $activeZones[$r['municipality']][] = $r;
    } else {
        $archivedZones[] = $r;
    }
}
$activeCount = 0;
foreach ($activeZones as $g) $activeCount += count($g);
$archivedCount = count($archivedZones);

// ── Existing zone lookup for the client-side duplicate check ──
// key = "Municipality||Barangay"  ->  ['id' => int, 'active' => 0|1]
$existingZones = [];
$er = $conn->query("SELECT id, municipality, barangay, active FROM delivery_zones");
while ($e = $er->fetch_assoc()) {
    $existingZones[$e['municipality'] . '||' . $e['barangay']] = [
        'id'     => (int)$e['id'],
        'active' => (int)$e['active'],
    ];
}
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

        .btn-warn {
            background: #fff7ed;
            border: 1.5px solid #fed7aa;
            color: #9a3412
        }

        .btn-warn:hover {
            background: #f97316;
            color: #fff;
            border-color: #f97316
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

        .btn-success {
            background: #ecfdf5;
            border: 1.5px solid #a7f3d0;
            color: #065f46
        }

        .btn-success:hover {
            background: var(--success);
            color: #fff;
            border-color: var(--success)
        }

        .btn-danger-solid {
            background: var(--danger);
            color: #fff;
            border: none
        }

        .btn-danger-solid:hover {
            background: #dc2626
        }

        .btn-danger-solid:disabled {
            background: #fca5a5;
            cursor: not-allowed
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
            justify-content: flex-end;
            flex-wrap: wrap
        }

        .inline-form {
            display: inline
        }

        .section-label {
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 30px 0 12px;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border)
        }

        .archived-card .card-head {
            background: #f9fafb;
            color: var(--muted)
        }

        .archive-note {
            font-size: .78rem;
            color: var(--muted);
            padding: 10px 20px;
            background: #fafafa;
            border-bottom: 1px solid var(--border)
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
            max-width: 460px;
            overflow: visible;
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
            margin-bottom: 14px;
            position: relative
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
            outline: none;
            width: 100%
        }

        .fg input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(230, 126, 34, .1)
        }

        .fg input:disabled {
            background: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed
        }

        .fg-hint {
            font-size: .72rem;
            color: var(--muted)
        }

        /* Validation states */
        .fg input.is-error {
            border-color: var(--danger);
            background: #fef2f2
        }

        .fg input.is-error:focus {
            border-color: var(--danger);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, .12)
        }

        .fg input.is-ok {
            border-color: #a7f3d0;
            background: #f0fdf4
        }

        .fg-error {
            display: none;
            font-size: .75rem;
            color: #b91c1c;
            font-weight: 600;
            align-items: flex-start;
            gap: 5px;
            line-height: 1.45;
            margin-top: 1px
        }

        .fg-error.show {
            display: flex
        }

        .fg-error a {
            color: #991b1b;
            text-decoration: underline;
            font-weight: 700
        }

        .fg-ok {
            display: none;
            font-size: .75rem;
            color: #047857;
            font-weight: 600;
            align-items: center;
            gap: 5px;
            margin-top: 1px
        }

        .fg-ok.show {
            display: flex
        }

        .btn-primary:disabled {
            background: #e5c9ad;
            cursor: not-allowed
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

        /* Autocomplete dropdowns */
        .suggest-box {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: 0 0 9px 9px;
            max-height: 210px;
            overflow-y: auto;
            z-index: 30;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .12);
            display: none
        }

        .suggest-box.show {
            display: block
        }

        .suggest-item {
            padding: 9px 13px;
            font-size: .85rem;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6
        }

        .suggest-item:last-child {
            border-bottom: none
        }

        .suggest-item:hover,
        .suggest-item.active {
            background: var(--primary-light);
            color: var(--primary-dark)
        }

        .suggest-count {
            padding: 7px 13px;
            font-size: .7rem;
            color: var(--muted);
            background: #fafafa;
            border-bottom: 1px solid #f3f4f6;
            font-weight: 600
        }

        .suggest-empty {
            padding: 10px 13px;
            font-size: .8rem;
            color: var(--muted)
        }

        /* Permanent-delete confirm modal */
        .confirm-bg {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .55);
            z-index: 120;
            align-items: center;
            justify-content: center;
            padding: 20px
        }

        .confirm-bg.show {
            display: flex
        }

        .confirm-card {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 410px;
            text-align: center;
            padding: 30px 26px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .25)
        }

        .confirm-icon {
            width: 58px;
            height: 58px;
            background: #fee2e2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 1.4rem
        }

        .confirm-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #111827;
            margin-bottom: 8px
        }

        .confirm-text {
            font-size: .85rem;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 16px
        }

        .confirm-zone {
            font-weight: 700;
            color: var(--dark2)
        }

        .confirm-input {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: .88rem;
            font-family: inherit;
            outline: none;
            margin-bottom: 18px;
            text-align: center;
            letter-spacing: .05em
        }

        .confirm-input:focus {
            border-color: var(--danger);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, .12)
        }

        .confirm-actions {
            display: flex;
            gap: 10px
        }

        .confirm-actions .btn,
        .confirm-actions form {
            flex: 1
        }

        .confirm-actions .btn {
            justify-content: center;
            width: 100%
        }
    </style>
</head>

<body>
    <div class="wrap">

        <a href="dashboard.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>

        <div class="page-head">
            <div>
                <div class="page-title"><i class="fa-solid fa-truck" style="color:#f97316"></i> Delivery Zones</div>
                <div class="page-sub"><?= $activeCount ?> active · <?= $archivedCount ?> archived · fees shown to customers at checkout</div>
            </div>
            <button class="btn btn-primary" onclick="openAdd()"><i class="fa-solid fa-plus"></i> Add Zone</button>
        </div>

        <?php if (!empty($_SESSION['flash_message'])): ?>
            <div class="flash <?= $_SESSION['flash_type'] ?? 'success' ?>"><?= $_SESSION['flash_message'] ?></div>
            <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <?php if (empty($activeZones) && empty($archivedZones)): ?>
            <div class="card">
                <div class="empty">
                    <div style="font-size:2.5rem;margin-bottom:10px;"><i class="fa-solid fa-map-location-dot" style="color:#3b82f6"></i></div>
                    No delivery zones yet. Click <strong>Add Zone</strong> to create one.
                </div>
            </div>
        <?php endif; ?>

        <!-- ── ACTIVE ZONES ────────────────────────────────────── -->
        <?php foreach ($activeZones as $muni => $rows): ?>
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
                                <td><span class="badge badge-on">Active</span></td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-ghost btn-sm"
                                            onclick='openEdit(<?= json_encode($z, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </button>
                                        <form method="POST" class="inline-form"
                                            onsubmit="return confirm('Archive this zone?\n\nIt will be hidden from customers at checkout, but the record is kept and can be restored anytime.')">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
                                            <button type="submit" class="btn btn-warn btn-sm"><i class="fa-solid fa-box-archive"></i> Archive</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- ── ARCHIVED ZONES ──────────────────────────────────── -->
        <?php if (!empty($archivedZones)): ?>
            <div class="section-label"><i class="fa-solid fa-box-archive"></i> Archived Zones</div>
            <div class="card archived-card">
                <div class="card-head"><i class="fa-solid fa-box-archive" style="color:#9ca3af"></i> Hidden from customers</div>
                <div class="archive-note">
                    <i class="fa-solid fa-circle-info"></i>
                    These zones no longer appear at checkout. Restore one to bring it back, or permanently delete it (this cannot be undone).
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Municipality</th>
                            <th>Barangay</th>
                            <th>Fee</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archivedZones as $z): ?>
                            <tr>
                                <td><?= htmlspecialchars($z['municipality']) ?></td>
                                <td><?= htmlspecialchars($z['barangay']) ?></td>
                                <td>
                                    <?php if ((float)$z['fee'] == 0): ?>
                                        <span class="fee-free">FREE</span>
                                    <?php else: ?>
                                        <span class="fee-val">₱<?= number_format((float)$z['fee'], 2) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                                        </form>
                                        <button class="btn btn-danger btn-sm"
                                            onclick="openDelete(<?= (int)$z['id'] ?>, <?= htmlspecialchars(json_encode($z['municipality'] . ' — ' . $z['barangay']), ENT_QUOTES) ?>)">
                                            <i class="fa-solid fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Add / Edit Modal ───────────────────────────────────── -->
    <div class="modal-bg" id="modalBg">
        <div class="modal">
            <form method="POST" id="zoneForm">
                <div class="modal-head">
                    <span id="modalTitle">Add Zone</span>
                    <button type="button" class="x-btn" onclick="closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="fAction" value="add">
                    <input type="hidden" name="id" id="fId" value="0">

                    <div class="fg">
                        <label>Municipality / City</label>
                        <input type="text" name="municipality" id="fMuni"
                            placeholder="Type to search… e.g. Cortes"
                            autocomplete="off" oninput="muniInput()" onfocus="muniInput()">
                        <div class="suggest-box" id="muniSuggest"></div>
                        <span class="fg-hint">All 48 Bohol municipalities available.</span>
                        <span class="fg-error" id="muniError"><i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;"></i><span id="muniErrorText"></span></span>
                    </div>

                    <div class="fg">
                        <label>Barangay</label>
                        <input type="text" name="barangay" id="fBrgy"
                            placeholder="Choose a municipality first"
                            autocomplete="off" oninput="brgyInput()" onfocus="brgyInput()" disabled>
                        <div class="suggest-box" id="brgySuggest"></div>
                        <span class="fg-hint" id="brgyHint">Barangays are filtered to the chosen municipality.</span>
                        <span class="fg-error" id="brgyError"><i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;"></i><span id="brgyErrorText"></span></span>
                        <span class="fg-ok" id="brgyOk"><i class="fa-solid fa-circle-check"></i> Valid barangay.</span>
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
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save Zone</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Permanent Delete Confirm Modal ─────────────────────── -->
    <div class="confirm-bg" id="confirmBg">
        <div class="confirm-card">
            <div class="confirm-icon"><i class="fa-solid fa-trash" style="color:#ef4444"></i></div>
            <div class="confirm-title">Permanently delete this zone?</div>
            <div class="confirm-text">
                <span class="confirm-zone" id="confirmZoneName"></span> will be removed from the database for good.
                This cannot be undone.<br><br>
                Type <strong>DELETE</strong> below to confirm.
            </div>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId" value="0">
                <input type="text" name="confirm_text" class="confirm-input" id="confirmInput"
                    placeholder="Type DELETE" autocomplete="off" oninput="confirmTyped()">
                <div class="confirm-actions">
                    <button type="button" class="btn btn-ghost" onclick="closeDelete()">Cancel</button>
                    <button type="submit" class="btn btn-danger-solid" id="deleteBtn" disabled>
                        <i class="fa-solid fa-trash"></i> Delete Forever
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ── Official Bohol data: { "Cortes": ["Loreto", "Poblacion", ...], ... }
        const BOHOL = <?= json_encode($BOHOL_LOCATIONS, JSON_UNESCAPED_UNICODE) ?>;
        const MUNIS = Object.keys(BOHOL).sort();

        // Zones already in the database: { "Cortes||Loreto": {id: 3, active: 1}, ... }
        const EXISTING = <?= json_encode($existingZones, JSON_UNESCAPED_UNICODE) ?>;

        // The zone currently being edited (0 when adding) — a zone is never a duplicate of itself
        let EDITING_ID = 0;

        // ── Add / Edit modal ───────────────────────────────────────────
        function openAdd() {
            EDITING_ID = 0;
            document.getElementById('modalTitle').textContent = 'Add Zone';
            document.getElementById('fAction').value = 'add';
            document.getElementById('fId').value = 0;
            document.getElementById('fMuni').value = '';
            const b = document.getElementById('fBrgy');
            b.value = '';
            b.disabled = true;
            b.placeholder = 'Choose a municipality first';
            document.getElementById('fFee').value = '0.00';
            document.getElementById('fActive').checked = true;
            hideSuggest('muniSuggest');
            hideSuggest('brgySuggest');
            clearValidation();
            document.getElementById('modalBg').classList.add('show');
            setTimeout(() => document.getElementById('fMuni').focus(), 50);
        }

        function openEdit(z) {
            EDITING_ID = parseInt(z.id, 10);
            document.getElementById('modalTitle').textContent = 'Edit Zone';
            document.getElementById('fAction').value = 'edit';
            document.getElementById('fId').value = z.id;
            document.getElementById('fMuni').value = z.municipality;
            const b = document.getElementById('fBrgy');
            b.value = z.barangay;
            b.disabled = false;
            b.placeholder = 'Type to search…';
            document.getElementById('fFee').value = parseFloat(z.fee).toFixed(2);
            document.getElementById('fActive').checked = z.active == 1;
            hideSuggest('muniSuggest');
            hideSuggest('brgySuggest');
            clearValidation();
            validateAll(); // show state for the row being edited
            document.getElementById('modalBg').classList.add('show');
        }

        function closeModal() {
            document.getElementById('modalBg').classList.remove('show');
            hideSuggest('muniSuggest');
            hideSuggest('brgySuggest');
        }
        document.getElementById('modalBg').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // ── Suggestion box helpers ─────────────────────────────────────
        function hideSuggest(id) {
            document.getElementById(id).classList.remove('show');
        }

        function renderSuggest(boxId, items, onPick) {
            const box = document.getElementById(boxId);
            box.innerHTML = '';
            if (items.length === 0) {
                box.innerHTML = '<div class="suggest-empty">No matches. You can still type a custom name.</div>';
                box.classList.add('show');
                return;
            }
            if (items.length > 30) {
                const c = document.createElement('div');
                c.className = 'suggest-count';
                c.textContent = items.length + ' matches — keep typing to narrow';
                box.appendChild(c);
            }
            items.slice(0, 60).forEach(it => {
                const div = document.createElement('div');
                div.className = 'suggest-item';
                div.textContent = it;
                // mousedown fires before blur, so the pick lands correctly
                div.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    onPick(it);
                });
                box.appendChild(div);
            });
            box.classList.add('show');
        }

        // ── Municipality autocomplete ──────────────────────────────────
        function muniInput() {
            const val = document.getElementById('fMuni').value.trim().toLowerCase();
            const matches = MUNIS.filter(m => m.toLowerCase().includes(val));
            renderSuggest('muniSuggest', matches, pickMuni);
        }

        function pickMuni(m) {
            document.getElementById('fMuni').value = m;
            hideSuggest('muniSuggest');
            const b = document.getElementById('fBrgy');
            b.disabled = false;
            b.placeholder = 'Type to search…';
            b.value = '';
            const n = (BOHOL[m] || []).length;
            document.getElementById('brgyHint').textContent = n + ' barangay' + (n !== 1 ? 's' : '') + ' in ' + m + '.';
            b.focus();
            brgyInput();
            validateAll();
        }

        // ── Barangay autocomplete (scoped to the chosen municipality) ──
        function brgyInput() {
            const muni = document.getElementById('fMuni').value.trim();
            const val = document.getElementById('fBrgy').value.trim().toLowerCase();
            if (BOHOL[muni]) {
                const matches = BOHOL[muni].filter(b => b.toLowerCase().includes(val));
                renderSuggest('brgySuggest', matches, pickBrgy);
            } else {
                hideSuggest('brgySuggest');
            }
            validateAll();
        }

        function pickBrgy(b) {
            document.getElementById('fBrgy').value = b;
            hideSuggest('brgySuggest');
            validateAll();
            document.getElementById('fFee').focus();
        }

        // Close suggestion boxes on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#fMuni') && !e.target.closest('#muniSuggest')) hideSuggest('muniSuggest');
            if (!e.target.closest('#fBrgy') && !e.target.closest('#brgySuggest')) hideSuggest('brgySuggest');
        });

        // ══ VALIDATION ═════════════════════════════════════════════════
        function clearValidation() {
            ['fMuni', 'fBrgy'].forEach(id => {
                const el = document.getElementById(id);
                el.classList.remove('is-error', 'is-ok');
            });
            document.getElementById('muniError').classList.remove('show');
            document.getElementById('brgyError').classList.remove('show');
            document.getElementById('brgyOk').classList.remove('show');
            document.getElementById('saveBtn').disabled = false;
        }

        function showMuniError(msg) {
            document.getElementById('fMuni').classList.add('is-error');
            document.getElementById('muniErrorText').innerHTML = msg;
            document.getElementById('muniError').classList.add('show');
        }

        function showBrgyError(msg) {
            document.getElementById('fBrgy').classList.remove('is-ok');
            document.getElementById('fBrgy').classList.add('is-error');
            document.getElementById('brgyOk').classList.remove('show');
            document.getElementById('brgyErrorText').innerHTML = msg;
            document.getElementById('brgyError').classList.add('show');
        }

        function showBrgyOk() {
            document.getElementById('fBrgy').classList.remove('is-error');
            document.getElementById('fBrgy').classList.add('is-ok');
            document.getElementById('brgyError').classList.remove('show');
            document.getElementById('brgyOk').classList.add('show');
        }

        // Returns true when the form is safe to submit
        function validateAll() {
            // reset visuals first
            document.getElementById('fMuni').classList.remove('is-error', 'is-ok');
            document.getElementById('muniError').classList.remove('show');
            document.getElementById('fBrgy').classList.remove('is-error', 'is-ok');
            document.getElementById('brgyError').classList.remove('show');
            document.getElementById('brgyOk').classList.remove('show');

            const muni = document.getElementById('fMuni').value.trim();
            const brgy = document.getElementById('fBrgy').value.trim();
            let ok = true;

            // ── Municipality must be one of the 48 ──
            if (muni === '') {
                ok = false; // empty: no red yet, just can't save
            } else if (!BOHOL[muni]) {
                showMuniError('&ldquo;' + escapeHtml(muni) + '&rdquo; is not a Bohol municipality. Pick one from the list.');
                ok = false;
            }

            // ── Barangay ──
            if (brgy === '') {
                ok = false; // empty: no red yet
            } else if (!BOHOL[muni]) {
                ok = false; // can't validate a barangay without a valid municipality
            } else {
                // Must EXACTLY match an official barangay of that municipality
                const official = BOHOL[muni];
                const exact = official.find(b => b.toLowerCase() === brgy.toLowerCase());

                if (!exact) {
                    // Partial / misspelled — offer the closest matches
                    const partial = official.filter(b => b.toLowerCase().includes(brgy.toLowerCase()));
                    if (partial.length > 0) {
                        const preview = partial.slice(0, 3).map(escapeHtml).join(', ');
                        const more = partial.length > 3 ? ' …and ' + (partial.length - 3) + ' more' : '';
                        showBrgyError('Incomplete barangay name. Did you mean: <strong>' + preview + '</strong>' + more + '? Please select one from the list.');
                    } else {
                        showBrgyError('&ldquo;' + escapeHtml(brgy) + '&rdquo; is not a barangay of ' + escapeHtml(muni) + '. Please select one from the list.');
                    }
                    ok = false;
                } else {
                    // Normalize casing to the official spelling
                    if (document.getElementById('fBrgy').value !== exact) {
                        document.getElementById('fBrgy').value = exact;
                    }

                    // ── Duplicate check ──
                    const key = muni + '||' + exact;
                    const hit = EXISTING[key];

                    if (hit && hit.id !== EDITING_ID) {
                        if (hit.active === 1) {
                            showBrgyError('<strong>' + escapeHtml(exact) + ', ' + escapeHtml(muni) + '</strong> is already an active delivery zone. Edit the existing one instead of adding a duplicate.');
                        } else {
                            showBrgyError('<strong>' + escapeHtml(exact) + ', ' + escapeHtml(muni) + '</strong> already exists in the <strong>archive</strong>. Restore it from the Archived Zones section instead of adding a duplicate.');
                        }
                        ok = false;
                    } else {
                        showBrgyOk();
                    }
                }
            }

            document.getElementById('saveBtn').disabled = !ok;
            return ok;
        }

        function escapeHtml(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Re-validate when the municipality box is typed in
        document.getElementById('fMuni').addEventListener('input', validateAll);

        // Block submit if anything is invalid
        document.getElementById('zoneForm').addEventListener('submit', function(e) {
            if (!validateAll()) {
                e.preventDefault();
                const muni = document.getElementById('fMuni').value.trim();
                const brgy = document.getElementById('fBrgy').value.trim();
                if (!muni || !brgy) {
                    alert('Please select both a municipality and a barangay from the list.');
                }
                // otherwise the inline red warning already explains why
            }
        });

        // ── Permanent-delete confirm modal ─────────────────────────────
        function openDelete(id, name) {
            document.getElementById('deleteId').value = id;
            document.getElementById('confirmZoneName').textContent = name;
            document.getElementById('confirmInput').value = '';
            document.getElementById('deleteBtn').disabled = true;
            document.getElementById('confirmBg').classList.add('show');
            setTimeout(() => document.getElementById('confirmInput').focus(), 50);
        }

        function closeDelete() {
            document.getElementById('confirmBg').classList.remove('show');
        }

        function confirmTyped() {
            const v = document.getElementById('confirmInput').value.trim();
            document.getElementById('deleteBtn').disabled = (v !== 'DELETE');
        }
        document.getElementById('confirmBg').addEventListener('click', function(e) {
            if (e.target === this) closeDelete();
        });
    </script>
</body>

</html>