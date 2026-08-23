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
    <title>Delivery Zones — Hiney's Admin</title>
    <style>
        /* Page-specific only — shared system comes from admin.css */

        /* Section label between active/archived */
        .section-label {
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: var(--s2);
            margin: var(--s7) 0 var(--s4);
            letter-spacing: -0.01em;
        }

        .section-label i {
            color: var(--ink-3);
        }

        /* Municipality card head */
        .muni-head {
            display: flex;
            align-items: center;
            gap: var(--s2);
            padding: var(--s4) var(--s5);
            border-bottom: 1px solid var(--line);
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--ink);
        }

        .muni-head .pin {
            color: var(--brand);
        }

        .muni-tag {
            margin-left: auto;
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            background: var(--brand-tint);
            color: var(--brand-strong);
            padding: 3px 10px;
            border-radius: var(--r-pill);
        }

        .archived-card .muni-head {
            color: var(--ink-2);
        }

        .archived-card .muni-head i {
            color: var(--ink-3);
        }

        .archive-note {
            display: flex;
            align-items: flex-start;
            gap: var(--s2);
            padding: 10px var(--s5);
            font-size: var(--fs-sm);
            color: var(--ink-2);
            background: var(--surface-2);
            border-bottom: 1px solid var(--line);
        }

        .archive-note i {
            color: var(--info);
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* Fee display */
        .fee-free {
            display: inline-flex;
            align-items: center;
            font-size: var(--fs-xs);
            font-weight: var(--fw-bold);
            background: var(--ok-tint);
            color: #1f7a48;
            padding: 2px 10px;
            border-radius: var(--r-pill);
        }

        .fee-val {
            font-weight: var(--fw-bold);
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        /* Status badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: var(--r-pill);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
        }

        .badge::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
        }

        .badge-on {
            background: var(--ok-tint);
            color: #1f7a48;
        }

        /* Row actions (labeled small buttons — few per row, differ by section) */
        .actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-wrap: wrap;
        }

        .inline-form {
            display: inline-flex;
            margin: 0;
        }

        .btn-sm {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 11px;
            border-radius: var(--r-sm);
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            cursor: pointer;
            border: 1px solid;
            background: transparent;
            font-family: inherit;
            white-space: nowrap;
            transition: background 0.14s, color 0.14s, border-color 0.14s;
        }

        .btn-sm svg,
        .btn-sm i {
            flex-shrink: 0;
        }

        .btn-edit {
            color: var(--brand);
            border-color: var(--brand);
        }

        .btn-edit:hover {
            background: var(--brand);
            color: #fff;
        }

        .btn-arch {
            color: var(--warn);
            border-color: #f2ddb0;
        }

        .btn-arch:hover {
            background: var(--warn);
            color: #fff;
            border-color: var(--warn);
        }

        .btn-rest {
            color: var(--ok);
            border-color: #a7dcbc;
        }

        .btn-rest:hover {
            background: var(--ok);
            color: #fff;
            border-color: var(--ok);
        }

        .btn-del {
            color: var(--danger);
            border-color: #f0c4c0;
        }

        .btn-del:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
        }

        /* ── Add/Edit Modal ── */
        .modal-bg {
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

        .modal-bg.show {
            display: flex;
        }

        .modal {
            background: var(--surface);
            border-radius: var(--r-lg);
            width: 100%;
            max-width: 480px;
            max-height: 92vh;
            overflow: visible;
            box-shadow: var(--shadow-md);
            animation: modalIn 0.22s cubic-bezier(0.34, 1.4, 0.64, 1) both;
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
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--ink);
        }

        .x-btn {
            border: none;
            background: var(--surface-2);
            width: 30px;
            height: 30px;
            border-radius: var(--r-sm);
            cursor: pointer;
            color: var(--ink-3);
            font-size: 1.2rem;
            line-height: 1;
            transition: background 0.14s, color 0.14s;
        }

        .x-btn:hover {
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
        }

        /* Form fields */
        .fg {
            position: relative;
            margin-bottom: var(--s4);
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .fg label {
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            color: var(--ink);
        }

        .fg input[type="text"],
        .fg input[type="number"] {
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

        .fg input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring);
        }

        .fg input:disabled {
            background: var(--surface-2);
            color: var(--ink-3);
            cursor: not-allowed;
        }

        .fg-hint {
            font-size: var(--fs-xs);
            color: var(--ink-3);
        }

        .fg-error {
            display: none;
            font-size: var(--fs-xs);
            color: var(--danger);
            align-items: flex-start;
            gap: 5px;
        }

        .fg-error i {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .fg-error.show {
            display: flex;
        }

        .fg-ok {
            display: none;
            font-size: var(--fs-xs);
            color: var(--ok);
            align-items: center;
            gap: 5px;
        }

        .fg-ok.show {
            display: flex;
        }

        /* JS toggles is-error / is-ok directly on the input */
        input.is-error {
            border-color: var(--danger) !important;
        }

        input.is-error:focus {
            box-shadow: 0 0 0 3px var(--danger-tint) !important;
        }

        input.is-ok {
            border-color: var(--ok) !important;
        }

        input.is-ok:focus {
            box-shadow: 0 0 0 3px var(--ok-tint) !important;
        }

        .fg-check {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .fg-check input {
            width: 16px;
            height: 16px;
            accent-color: var(--brand);
            cursor: pointer;
        }

        .fg-check label {
            font-size: var(--fs-sm);
            color: var(--ink);
        }

        /* Autocomplete suggestion box */
        .suggest-box {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 20;
            background: var(--surface);
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            margin-top: 4px;
            max-height: 220px;
            overflow-y: auto;
            box-shadow: var(--shadow-md);
        }

        .suggest-box.show {
            display: block;
        }

        .suggest-item {
            padding: 8px 12px;
            font-size: var(--fs-sm);
            color: var(--ink);
            cursor: pointer;
            transition: background 0.1s;
        }

        .suggest-item:hover,
        .suggest-item.active {
            background: var(--brand-tint);
            color: var(--brand-strong);
        }

        .suggest-count {
            padding: 6px 12px;
            font-size: var(--fs-xs);
            font-weight: var(--fw-semi);
            color: var(--ink-3);
            background: var(--surface-2);
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
        }

        .suggest-empty {
            padding: 10px 12px;
            font-size: var(--fs-sm);
            color: var(--ink-3);
        }

        /* ── Delete confirm modal ── */
        .confirm-bg {
            position: fixed;
            inset: 0;
            background: rgba(35, 32, 28, 0.5);
            backdrop-filter: blur(3px);
            z-index: 1100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .confirm-bg.show {
            display: flex;
        }

        .confirm-card {
            background: var(--surface);
            border-radius: var(--r-lg);
            width: 100%;
            max-width: 420px;
            padding: var(--s6);
            text-align: center;
            box-shadow: var(--shadow-md);
            animation: modalIn 0.22s cubic-bezier(0.34, 1.4, 0.64, 1) both;
        }

        .confirm-icon {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            background: var(--danger-tint);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin: 0 auto var(--s3);
        }

        .confirm-title {
            font-size: 1.05rem;
            font-weight: var(--fw-bold);
            color: var(--ink);
            margin-bottom: var(--s2);
        }

        .confirm-text {
            font-size: var(--fs-sm);
            color: var(--ink-2);
            line-height: 1.6;
            margin-bottom: var(--s4);
        }

        .confirm-zone {
            font-weight: var(--fw-bold);
            color: var(--ink);
        }

        .confirm-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            text-align: center;
            letter-spacing: 0.1em;
            font-weight: var(--fw-semi);
            outline: none;
            font-family: inherit;
            margin-bottom: var(--s4);
        }

        .confirm-input:focus {
            border-color: var(--danger);
            box-shadow: 0 0 0 3px var(--danger-tint);
        }

        .confirm-actions {
            display: flex;
            gap: var(--s3);
            justify-content: center;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--s2);
            padding: 9px var(--s4);
            border-radius: var(--r-sm);
            font-size: var(--fs-sm);
            font-weight: var(--fw-semi);
            font-family: inherit;
            cursor: pointer;
            border: 1px solid transparent;
            transition: background 0.14s, border-color 0.14s;
            white-space: nowrap;
        }

        .btn svg,
        .btn i {
            flex-shrink: 0;
        }

        .btn-ghost {
            background: transparent;
            color: var(--ink-2);
            border-color: var(--line-strong);
        }

        .btn-ghost:hover {
            background: var(--surface-2);
            color: var(--ink);
        }

        .btn-primary {
            background: var(--brand);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--brand-strong);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-danger-solid {
            background: var(--danger);
            color: #fff;
        }

        .btn-danger-solid:hover {
            background: #c0433b;
        }

        .btn-danger-solid:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        @media (max-width: 768px) {
            .actions {
                justify-content: flex-start;
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

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Delivery Zones</h1>
                    <div class="page-title-sub"><?= $activeCount ?> active · <?= $archivedCount ?> archived · fees shown to customers at checkout</div>
                </div>
                <button class="btn btn-primary" onclick="openAdd()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Add Zone
                </button>
            </div>

            <?php if (!empty($_SESSION['flash_message'])): ?>
                <div class="flash <?= $_SESSION['flash_type'] ?? 'success' ?>"><?= $_SESSION['flash_message'] ?></div>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
            <?php endif; ?>

            <?php if (empty($activeZones) && empty($archivedZones)): ?>
                <div class="table-card">
                    <div class="empty">
                        <div class="empty-icon"><i class="fa-solid fa-map-location-dot"></i></div>
                        <div class="empty-title">No delivery zones yet</div>
                        <div class="empty-text">Click <strong>Add Zone</strong> to create your first delivery area.</div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ── ACTIVE ZONES (grouped by municipality) ── -->
            <?php foreach ($activeZones as $muni => $rows): ?>
                <div class="table-card mb-4">
                    <div class="muni-head">
                        <i class="fa-solid fa-location-dot pin"></i> <?= htmlspecialchars($muni) ?>
                        <span class="muni-tag"><?= count($rows) ?> barangay<?= count($rows) !== 1 ? 's' : '' ?></span>
                    </div>
                    <div class="table-scroll">
                        <table class="data">
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
                                        <td style="font-weight:var(--fw-semi);color:var(--ink);"><?= htmlspecialchars($z['barangay']) ?></td>
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
                                                <button class="btn-sm btn-edit" onclick='openEdit(<?= json_encode($z, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="fa-solid fa-pen"></i> Edit
                                                </button>
                                                <form method="POST" class="inline-form"
                                                    onsubmit="return confirm('Archive this zone?\n\nIt will be hidden from customers at checkout, but the record is kept and can be restored anytime.')">
                                                    <input type="hidden" name="action" value="archive">
                                                    <input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
                                                    <button type="submit" class="btn-sm btn-arch"><i class="fa-solid fa-box-archive"></i> Archive</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- ── ARCHIVED ZONES ── -->
            <?php if (!empty($archivedZones)): ?>
                <div class="section-label"><i class="fa-solid fa-box-archive"></i> Archived Zones</div>
                <div class="table-card archived-card">
                    <div class="archive-note">
                        <i class="fa-solid fa-circle-info"></i>
                        These zones no longer appear at checkout. Restore one to bring it back, or permanently delete it (this cannot be undone).
                    </div>
                    <div class="table-scroll">
                        <table class="data">
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
                                        <td style="color:var(--ink-2);"><?= htmlspecialchars($z['municipality']) ?></td>
                                        <td style="font-weight:var(--fw-semi);color:var(--ink);"><?= htmlspecialchars($z['barangay']) ?></td>
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
                                                    <button type="submit" class="btn-sm btn-rest"><i class="fa-solid fa-rotate-left"></i> Restore</button>
                                                </form>
                                                <button class="btn-sm btn-del"
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
                </div>
            <?php endif; ?>

        </div><!-- /.main-content -->
    </div><!-- /.admin-layout -->

    <!-- ── Add / Edit Modal ── -->
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
                        <span class="fg-error" id="muniError"><i class="fa-solid fa-triangle-exclamation"></i><span id="muniErrorText"></span></span>
                    </div>

                    <div class="fg">
                        <label>Barangay</label>
                        <input type="text" name="barangay" id="fBrgy"
                            placeholder="Choose a municipality first"
                            autocomplete="off" oninput="brgyInput()" onfocus="brgyInput()" disabled>
                        <div class="suggest-box" id="brgySuggest"></div>
                        <span class="fg-hint" id="brgyHint">Barangays are filtered to the chosen municipality.</span>
                        <span class="fg-error" id="brgyError"><i class="fa-solid fa-triangle-exclamation"></i><span id="brgyErrorText"></span></span>
                        <span class="fg-ok" id="brgyOk"><i class="fa-solid fa-circle-check"></i> Valid barangay.</span>
                    </div>

                    <div class="fg">
                        <label>Delivery Fee (₱) — enter 0 for free</label>
                        <input type="number" name="fee" id="fFee" step="0.01" min="0" value="0.00" required>
                    </div>

                    <div class="fg-check">
                        <input type="checkbox" name="active" id="fActive" checked>
                        <label for="fActive">Active (show to customers)</label>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save Zone</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Permanent Delete Confirm Modal ── -->
    <div class="confirm-bg" id="confirmBg">
        <div class="confirm-card">
            <div class="confirm-icon"><i class="fa-solid fa-trash" style="color:var(--danger)"></i></div>
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