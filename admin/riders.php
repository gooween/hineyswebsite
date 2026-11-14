<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: admin/riders.php
// Admin: create / edit / disable rider accounts, see availability and delivery counts.
// Rider details live in `users` (role = 'rider'); availability lives in `riders`.
// ============================================================
session_start();
require_once '../config/db.php';
requireAdmin();

$activePage = 'riders';

// The rider tables must exist (rider_system.sql)
$ready = false;
try {
    $t = $conn->query("SHOW TABLES LIKE 'riders'");
    $ready = $t && $t->num_rows > 0;
} catch (Throwable $e) {
    $ready = false;
}

// Deliverable areas — the SAME source register.php and checkout use (active delivery zones)
$BOHOL_LOCATIONS = [];
if ($ready) {
    try {
        $zr = $conn->query("SELECT municipality, barangay FROM delivery_zones WHERE active = 1 ORDER BY municipality, barangay");
        while ($zr && ($z = $zr->fetch_assoc())) $BOHOL_LOCATIONS[$z['municipality']][] = $z['barangay'];
    } catch (Throwable $e) {
        $BOHOL_LOCATIONS = [];
    }
}

/** Validates the municipality/barangay pair and builds the combined address string. Redirects on error. */
function riderAddress(array $locations, string $muni, string $brgy, string $street): string
{
    if ($muni === '' || $brgy === '') redirect('riders.php', 'error', 'Please select the municipality and barangay.');
    if (!in_array($brgy, $locations[$muni] ?? [], true)) redirect('riders.php', 'error', 'That barangay is not in the list of areas. Please choose another.');
    return implode(', ', array_filter([$street, $brgy, $muni, 'Bohol'], fn($p) => $p !== ''));
}

function riderPhoneOk(string $p): bool
{
    return $p === '' || preg_match('/^[0-9+\-\s()]{7,20}$/', $p) === 1;
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Create ──
    if ($action === 'create') {
        $name  = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        $muni  = trim($_POST['municipality'] ?? '');
        $brgy  = trim($_POST['barangay'] ?? '');
        $street = trim($_POST['street_address'] ?? '');
        $land  = trim($_POST['landmark'] ?? '');

        if ($name === '' || $email === '' || $pass === '') redirect('riders.php', 'error', 'Name, email and password are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) redirect('riders.php', 'error', 'Please enter a valid email address.');
        if (!riderPhoneOk($phone)) redirect('riders.php', 'error', 'Please enter a valid phone number.');
        if (strlen($pass) < 8) redirect('riders.php', 'error', 'Password must be at least 8 characters.');
        $addr = riderAddress($BOHOL_LOCATIONS, $muni, $brgy, $street);

        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk->bind_param('s', $email);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
        if ($exists) redirect('riders.php', 'error', 'That email is already used by another account.');

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        try {
            $conn->begin_transaction();
            $st = $conn->prepare("INSERT INTO users (full_name, email, password, role, phone, address, municipality, barangay, street_address, landmark, is_active)
                                  VALUES (?, ?, ?, 'rider', ?, ?, ?, ?, ?, ?, 1)");
            $st->bind_param('sssssssss', $name, $email, $hash, $phone, $addr, $muni, $brgy, $street, $land);
            $st->execute();
            $newId = (int)$conn->insert_id;
            $st->close();
            $conn->query("INSERT INTO riders (user_id, availability) VALUES ({$newId}, 'off_duty')");
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            redirect('riders.php', 'error', 'Could not create the rider account.');
        }
        redirect('riders.php', 'success', 'Rider account created. Share the email and password with ' . $name . ' — they sign in on the normal login page and are taken to their rider page.');
    }

    // ── Update ──
    if ($action === 'update') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $muni  = trim($_POST['municipality'] ?? '');
        $brgy  = trim($_POST['barangay'] ?? '');
        $street = trim($_POST['street_address'] ?? '');
        $land  = trim($_POST['landmark'] ?? '');
        if (!$id || $name === '' || $email === '') redirect('riders.php', 'error', 'Name and email are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) redirect('riders.php', 'error', 'Please enter a valid email address.');
        if (!riderPhoneOk($phone)) redirect('riders.php', 'error', 'Please enter a valid phone number.');
        $addr = riderAddress($BOHOL_LOCATIONS, $muni, $brgy, $street);
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $chk->bind_param('si', $email, $id);
        $chk->execute();
        $dupe = $chk->get_result()->num_rows > 0;
        $chk->close();
        if ($dupe) redirect('riders.php', 'error', 'That email is already used by another account.');
        $st = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, address=?, municipality=?, barangay=?, street_address=?, landmark=? WHERE id=? AND role='rider'");
        $st->bind_param('ssssssssi', $name, $email, $phone, $addr, $muni, $brgy, $street, $land, $id);
        $st->execute();
        $st->close();
        redirect('riders.php', 'success', 'Rider updated.');
    }

    // ── Activate / deactivate ──
    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $to  = (int)($_POST['to'] ?? 0) === 1 ? 1 : 0;
        if ($id) {
            if ($to === 0) {
                $busy = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE rider_id={$id} AND status IN ('approved','processing','out_for_delivery')")->fetch_assoc()['c'] ?? 0);
                if ($busy > 0) redirect('riders.php', 'error', 'This rider still has ' . $busy . ' active order(s). Reassign them first, then disable the account.');
                $conn->query("UPDATE riders SET availability='off_duty' WHERE user_id={$id}");
            }
            $conn->query("UPDATE users SET is_active={$to} WHERE id={$id} AND role='rider'");
            redirect('riders.php', 'success', $to ? 'Rider account activated.' : 'Rider account disabled. They can no longer sign in.');
        }
    }

    // ── Reset password ──
    if ($action === 'resetpw') {
        $id   = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['password'] ?? '');
        if (!$id || strlen($pass) < 8) redirect('riders.php', 'error', 'Password must be at least 8 characters.');
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $st = $conn->prepare("UPDATE users SET password=? WHERE id=? AND role='rider'");
        $st->bind_param('si', $hash, $id);
        $st->execute();
        $st->close();
        redirect('riders.php', 'success', 'Password reset. Give the new password to the rider.');
    }
}

// ── Data ──
$riders = [];
$sum = ['total' => 0, 'available' => 0, 'busy' => 0, 'today' => 0];
if ($ready) {
    $res = $conn->query("SELECT u.id, u.full_name, u.email, u.phone, u.address, u.municipality, u.barangay, u.street_address, u.landmark,
                                u.is_active, u.created_at, rd.availability,
        (SELECT COUNT(*) FROM orders o WHERE o.rider_id=u.id AND o.status IN ('approved','processing')) AS assigned_n,
        (SELECT COUNT(*) FROM orders o WHERE o.rider_id=u.id AND o.status='out_for_delivery') AS active_n,
        (SELECT COUNT(*) FROM orders o WHERE o.rider_id=u.id AND o.status='delivered') AS total_n,
        (SELECT COUNT(*) FROM orders o WHERE o.rider_id=u.id AND o.status='delivered' AND DATE(COALESCE(o.delivered_at,o.updated_at))=CURDATE()) AS today_n,
        (SELECT COUNT(*) FROM orders o WHERE o.rider_id=u.id AND o.status='delivered' AND DATE_FORMAT(COALESCE(o.delivered_at,o.updated_at),'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')) AS month_n
        FROM users u JOIN riders rd ON rd.user_id=u.id
        WHERE u.role='rider' ORDER BY u.is_active DESC, u.full_name");
    while ($res && $r = $res->fetch_assoc()) {
        $riders[] = $r;
        $sum['total']++;
        $sum['today'] += (int)$r['today_n'];
        if ($r['is_active']) {
            if ((int)$r['active_n'] > 0) $sum['busy']++;
            elseif ($r['availability'] === 'available') $sum['available']++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riders — Hiney's Admin</title>
    <style>
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .pill.ok {
            background: #dcfce7;
            color: #15803d;
        }

        .pill.busy {
            background: #dbeafe;
            color: #1e40af;
        }

        .pill.off {
            background: #e5e7eb;
            color: #374151;
        }

        .pill.dis {
            background: #fee2e2;
            color: #b91c1c;
        }

        .rider-name {
            font-weight: 700;
            color: var(--ink);
        }

        .rider-sub {
            font-size: 0.78rem;
            color: var(--ink-3);
        }

        .num-strong {
            font-weight: 700;
        }

        .ract {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            color: var(--ink-2);
        }

        .ract:hover {
            background: var(--surface-2);
        }

        .ract.danger {
            color: #b91c1c;
            border-color: #fecaca;
        }

        .ract.good {
            color: #15803d;
            border-color: #bbf7d0;
        }

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
            background: var(--surface, #fff);
            border-radius: var(--r-lg, 14px);
            width: 100%;
            max-width: 560px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: var(--shadow-md);
        }

        .modal-card.sm {
            max-width: 420px;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
        }

        .modal-head h3 {
            margin: 0;
            font-size: 1.05rem;
        }

        .modal-x {
            border: 0;
            background: transparent;
            font-size: 1.1rem;
            cursor: pointer;
            color: var(--ink-3);
        }

        .modal-bodyp {
            padding: 18px 20px;
        }

        .modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 14px 20px;
            border-top: 1px solid var(--line);
        }

        .fgrid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .fgrp {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .fgrp.full {
            grid-column: 1 / -1;
        }

        .flabel {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--ink);
        }

        .flabel .req {
            color: #dc2626;
        }

        .finput {
            padding: 9px 12px;
            border: 1px solid var(--line-strong, #d1d5db);
            border-radius: 8px;
            font-size: 0.88rem;
            font-family: inherit;
            width: 100%;
            background: #fff;
        }

        .finput:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px var(--brand-ring, rgba(232, 118, 30, .15));
        }

        .fhint {
            font-size: 0.75rem;
            color: var(--ink-3);
        }

        .mbtn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 9px 16px;
            border-radius: 8px;
            border: 1px solid transparent;
            font-size: 0.88rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }

        .mbtn.primary {
            background: var(--brand);
            color: #fff;
        }

        .mbtn.primary:hover {
            background: var(--brand-strong, var(--brand));
        }

        .mbtn.ghost {
            background: transparent;
            color: var(--ink-2);
        }

        .mbtn.ghost:hover {
            background: var(--surface-2);
        }

        .setup-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 18px 20px;
            color: #92400e;
        }

        @media (max-width: 640px) {
            .fgrid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="admin-layout">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
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

            <div class="page-header">
                <div>
                    <h1 class="page-title">Riders</h1>
                    <div class="page-title-sub">Create rider accounts, see who is available, and track deliveries</div>
                </div>
                <?php if ($ready): ?>
                    <button class="mbtn primary" onclick="openCreate()"><i class="fa-solid fa-plus"></i> Add Rider</button>
                <?php endif; ?>
            </div>

            <?= flash() ?>

            <?php if (!$ready): ?>
                <div class="setup-box"><strong>Rider tables are not set up yet.</strong><br>Open <a href="setup_riders.php"><strong>setup_riders.php</strong></a> once and click <em>Run setup</em>, then reload this page.</div>
            <?php else: ?>

                <div class="grid cols-2 mb-6" style="grid-template-columns:repeat(4,1fr);">
                    <div class="stat-card tone-brand">
                        <div class="stat-top"><span class="stat-eyebrow">Riders</span>
                            <div class="stat-icon"><i class="fa-solid fa-motorcycle"></i></div>
                        </div>
                        <div class="stat-value"><?= $sum['total'] ?></div>
                        <div class="stat-foot">Total accounts</div>
                    </div>
                    <div class="stat-card tone-green">
                        <div class="stat-top"><span class="stat-eyebrow">Available</span>
                            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                        </div>
                        <div class="stat-value"><?= $sum['available'] ?></div>
                        <div class="stat-foot">Ready for orders</div>
                    </div>
                    <div class="stat-card tone-blue">
                        <div class="stat-top"><span class="stat-eyebrow">On Delivery</span>
                            <div class="stat-icon"><i class="fa-solid fa-route"></i></div>
                        </div>
                        <div class="stat-value"><?= $sum['busy'] ?></div>
                        <div class="stat-foot">Out right now</div>
                    </div>
                    <div class="stat-card tone-amber">
                        <div class="stat-top"><span class="stat-eyebrow">Delivered Today</span>
                            <div class="stat-icon"><i class="fa-solid fa-box-open"></i></div>
                        </div>
                        <div class="stat-value"><?= $sum['today'] ?></div>
                        <div class="stat-foot"><?= date('F j') ?></div>
                    </div>
                </div>

                <div class="table-card">
                    <?php if ($riders): ?>
                        <div class="table-scroll">
                            <table class="data">
                                <thead>
                                    <tr>
                                        <th>Rider</th>
                                        <th>Contact</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">To do</th>
                                        <th style="text-align:center;">Today</th>
                                        <th style="text-align:center;">This month</th>
                                        <th style="text-align:center;">All time</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($riders as $r):
                                        $busy = (int)$r['active_n'];
                                        if (!$r['is_active']) {
                                            $cls = 'dis';
                                            $lbl = 'Disabled';
                                        } elseif ($busy > 0) {
                                            $cls = 'busy';
                                            $lbl = 'On delivery (' . $busy . ')';
                                        } elseif ($r['availability'] === 'available') {
                                            $cls = 'ok';
                                            $lbl = 'Available';
                                        } else {
                                            $cls = 'off';
                                            $lbl = 'Off duty';
                                        }
                                        $addrLine = trim(implode(', ', array_filter([$r['street_address'], $r['barangay'], $r['municipality']])));
                                        if ($addrLine === '') $addrLine = (string)$r['address'];
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="rider-name"><?= htmlspecialchars($r['full_name']) ?></div>
                                                <div class="rider-sub">Since <?= date('M j, Y', strtotime($r['created_at'])) ?></div>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars($r['email']) ?></div>
                                                <div class="rider-sub"><?= htmlspecialchars((string)$r['phone']) ?><?= $addrLine ? ' · ' . htmlspecialchars($addrLine) : '' ?></div>
                                            </td>
                                            <td><span class="pill <?= $cls ?>"><?= $lbl ?></span></td>
                                            <td style="text-align:center;" class="num-strong"><?= (int)$r['assigned_n'] + $busy ?></td>
                                            <td style="text-align:center;" class="num-strong"><?= (int)$r['today_n'] ?></td>
                                            <td style="text-align:center;" class="num-strong"><?= (int)$r['month_n'] ?></td>
                                            <td style="text-align:center;" class="num-strong"><?= (int)$r['total_n'] ?></td>
                                            <td style="text-align:center;white-space:nowrap;">
                                                <button class="ract" onclick='openEdit(<?= htmlspecialchars(json_encode([
                                                                                            "id" => (int)$r["id"],
                                                                                            "full_name" => $r["full_name"],
                                                                                            "email" => $r["email"],
                                                                                            "phone" => $r["phone"],
                                                                                            "address" => $r["address"],
                                                                                            "municipality" => $r["municipality"],
                                                                                            "barangay" => $r["barangay"],
                                                                                            "street_address" => $r["street_address"],
                                                                                            "landmark" => $r["landmark"]
                                                                                        ]), ENT_QUOTES) ?>)'><i class="fa-solid fa-pen"></i> Edit</button>
                                                <button class="ract" onclick="openPw(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['full_name']), ENT_QUOTES) ?>)"><i class="fa-solid fa-key"></i> Password</button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $r['is_active'] ? 'Disable this rider? They will not be able to sign in.' : 'Activate this rider account?' ?>');">
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                    <input type="hidden" name="to" value="<?= $r['is_active'] ? 0 : 1 ?>">
                                                    <button class="ract <?= $r['is_active'] ? 'danger' : 'good' ?>" type="submit"><?= $r['is_active'] ? '<i class="fa-solid fa-ban"></i> Disable' : '<i class="fa-solid fa-check"></i> Activate' ?></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="padding:40px;text-align:center;color:var(--ink-3);">No riders yet. Click <strong>Add Rider</strong> to create the first account.</div>
                    <?php endif; ?>
                </div>
                <p class="rider-sub" style="margin-top:10px;">Riders sign in on the normal login page (same as everyone) and are taken to <code>rider/index.php</code>. Assign a rider to a delivery order from <strong>Orders → Update</strong>.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create / Edit modal -->
    <div class="modal-backdrop" id="riderModal" onclick="if(event.target===this)closeM('riderModal')">
        <div class="modal-card">
            <div class="modal-head">
                <h3 id="rm_title">Add Rider</h3><button class="modal-x" onclick="closeM('riderModal')">✕</button>
            </div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" id="rm_action" value="create">
                <input type="hidden" name="id" id="rm_id" value="">
                <div class="modal-bodyp">
                    <div class="fgrid">
                        <div class="fgrp full"><label class="flabel">Full name <span class="req">*</span></label><input class="finput" name="full_name" id="rm_name" required></div>
                        <div class="fgrp"><label class="flabel">Email <span class="req">*</span></label><input class="finput" type="email" name="email" id="rm_email" required></div>
                        <div class="fgrp"><label class="flabel">Phone</label><input class="finput" type="tel" name="phone" id="rm_phone" placeholder="09XXXXXXXXX"></div>
                        <div class="fgrp full" id="rm_pass_wrap"><label class="flabel">Password <span class="req">*</span></label><input class="finput" type="text" name="password" id="rm_pass" minlength="8" autocomplete="new-password"><span class="fhint">At least 8 characters. Give it to the rider; they can change it in their profile.</span></div>
                        <div class="fgrp"><label class="flabel">Municipality / City <span class="req">*</span></label>
                            <select class="finput" name="municipality" id="rm_muni" onchange="loadBarangays(this.value)">
                                <option value="">— Select municipality —</option>
                                <?php foreach (array_keys($BOHOL_LOCATIONS) as $m): ?>
                                    <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fgrp"><label class="flabel">Barangay <span class="req">*</span></label>
                            <select class="finput" name="barangay" id="rm_brgy">
                                <option value="">— Select municipality first —</option>
                            </select>
                            <span class="fhint">Only areas we currently deliver to are listed.</span>
                        </div>
                        <div class="fgrp"><label class="flabel">Street / Purok / House No. <span class="fhint">(optional)</span></label><input class="finput" name="street_address" id="rm_street"></div>
                        <div class="fgrp"><label class="flabel">Landmark <span class="fhint">(optional)</span></label><input class="finput" name="landmark" id="rm_land"></div>
                    </div>
                </div>
                <div class="modal-foot"><button type="button" class="mbtn ghost" onclick="closeM('riderModal')">Cancel</button><button type="submit" class="mbtn primary" id="rm_submit">Create Rider</button></div>
            </form>
        </div>
    </div>

    <!-- Reset password modal -->
    <div class="modal-backdrop" id="pwModal" onclick="if(event.target===this)closeM('pwModal')">
        <div class="modal-card sm">
            <div class="modal-head">
                <h3>Reset password</h3><button class="modal-x" onclick="closeM('pwModal')">✕</button>
            </div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="action" value="resetpw">
                <input type="hidden" name="id" id="pw_id">
                <div class="modal-bodyp">
                    <p style="margin:0 0 12px;">New password for <strong id="pw_name"></strong></p>
                    <input class="finput" type="text" name="password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
                </div>
                <div class="modal-foot"><button type="button" class="mbtn ghost" onclick="closeM('pwModal')">Cancel</button><button type="submit" class="mbtn primary">Save password</button></div>
            </form>
        </div>
    </div>

    <script>
        // ── Deliverable areas (from delivery_zones) for the cascading dropdowns ──
        const BOHOL_LOCATIONS = <?= json_encode($BOHOL_LOCATIONS) ?>;

        function loadBarangays(muni, preselect) {
            var sel = document.getElementById('rm_brgy');
            sel.innerHTML = '';
            if (!muni || !BOHOL_LOCATIONS[muni]) {
                sel.innerHTML = '<option value="">— Select municipality first —</option>';
                return;
            }
            sel.innerHTML = '<option value="">— Select barangay —</option>';
            BOHOL_LOCATIONS[muni].forEach(function(b) {
                var opt = document.createElement('option');
                opt.value = b;
                opt.textContent = b;
                if (preselect && preselect === b) opt.selected = true;
                sel.appendChild(opt);
            });
        }

        function openM(id) {
            document.getElementById(id).classList.add('open');
        }

        function closeM(id) {
            document.getElementById(id).classList.remove('open');
        }

        function openCreate() {
            document.getElementById('rm_title').textContent = 'Add Rider';
            document.getElementById('rm_action').value = 'create';
            document.getElementById('rm_id').value = '';
            document.getElementById('rm_submit').textContent = 'Create Rider';
            ['rm_name', 'rm_email', 'rm_phone', 'rm_pass', 'rm_muni', 'rm_street', 'rm_land'].forEach(function(i) {
                document.getElementById(i).value = '';
            });
            loadBarangays('');
            document.getElementById('rm_pass_wrap').style.display = '';
            document.getElementById('rm_pass').required = true;
            openM('riderModal');
        }

        function openEdit(r) {
            document.getElementById('rm_title').textContent = 'Edit Rider';
            document.getElementById('rm_action').value = 'update';
            document.getElementById('rm_id').value = r.id;
            document.getElementById('rm_submit').textContent = 'Save Changes';
            document.getElementById('rm_name').value = r.full_name || '';
            document.getElementById('rm_email').value = r.email || '';
            document.getElementById('rm_phone').value = r.phone || '';
            document.getElementById('rm_muni').value = r.municipality || '';
            loadBarangays(r.municipality || '', r.barangay || '');
            document.getElementById('rm_street').value = r.street_address || '';
            document.getElementById('rm_land').value = r.landmark || '';
            document.getElementById('rm_pass_wrap').style.display = 'none';
            document.getElementById('rm_pass').required = false;
            document.getElementById('rm_pass').value = '';
            openM('riderModal');
        }

        function openPw(id, name) {
            document.getElementById('pw_id').value = id;
            document.getElementById('pw_name').textContent = name;
            openM('pwModal');
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeM('riderModal');
                closeM('pwModal');
            }
        });
    </script>
</body>

</html>