<?php
// ============================================================
// rider/profile.php — rider information, contact/address edit, password
// (name and email are managed by the admin)
// ============================================================
require_once __DIR__ . '/rider_auth.php';
require_once __DIR__ . '/rider_layout.php';

$rider = riderLoad($conn);
$uid   = (int)$rider['id'];

// Deliverable areas — the SAME source register.php and checkout use (active delivery zones)
$BOHOL_LOCATIONS = [];
$zr = $conn->query("SELECT municipality, barangay FROM delivery_zones WHERE active = 1 ORDER BY municipality, barangay");
while ($zr && ($z = $zr->fetch_assoc())) $BOHOL_LOCATIONS[$z['municipality']][] = $z['barangay'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!riderCheckCsrf()) redirect('profile.php', 'error', 'Your session expired. Please try again.');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_info') {
        $phone = trim($_POST['phone'] ?? '');
        if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) redirect('profile.php', 'error', 'Please enter a valid phone number.');
        $muni    = trim($_POST['municipality'] ?? '');
        $brgy    = trim($_POST['barangay'] ?? '');
        $street  = trim($_POST['street_address'] ?? '');
        $land    = trim($_POST['landmark'] ?? '');
        if ($muni === '' || $brgy === '') redirect('profile.php', 'error', 'Please select your municipality and barangay.');
        if (!in_array($brgy, $BOHOL_LOCATIONS[$muni] ?? [], true)) redirect('profile.php', 'error', 'That barangay is not in the list of areas. Please choose another.');
        $address = implode(', ', array_filter([$street, $brgy, $muni, 'Bohol'], fn($x) => $x !== ''));
        $st = $conn->prepare("UPDATE users SET phone=?, address=?, municipality=?, barangay=?, street_address=?, landmark=? WHERE id=? AND role='rider'");
        $st->bind_param('ssssssi', $phone, $address, $muni, $brgy, $street, $land, $uid);
        $st->execute();
        $st->close();
        redirect('profile.php', 'success', 'Your information was updated.');
    }

    if ($action === 'change_password') {
        $cur = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $cf  = (string)($_POST['confirm_password'] ?? '');
        $row = $conn->query("SELECT password FROM users WHERE id={$uid} LIMIT 1")->fetch_assoc();
        if (!$row || !password_verify($cur, $row['password'])) redirect('profile.php', 'error', 'Your current password is incorrect.');
        if (strlen($new) < 8) redirect('profile.php', 'error', 'New password must be at least 8 characters.');
        if ($new !== $cf) redirect('profile.php', 'error', 'New passwords do not match.');
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $st = $conn->prepare("UPDATE users SET password=? WHERE id=? AND role='rider'");
        $st->bind_param('si', $hash, $uid);
        $st->execute();
        $st->close();
        redirect('profile.php', 'success', 'Password changed.');
    }
}

$csrf = riderCsrf();
riderHead('Profile', 'profile', $rider);
?>

<h1 class="page">My Profile</h1>

<div class="card">
    <div class="row" style="gap:14px;">
        <div style="width:56px;height:56px;border-radius:50%;background:var(--brand-soft);color:var(--brand-dark);font-weight:800;font-size:1.4rem;display:flex;align-items:center;justify-content:center;"><?= htmlspecialchars(strtoupper(mb_substr($rider['full_name'], 0, 1))) ?></div>
        <div>
            <div style="font-weight:800;font-size:1.1rem;"><?= htmlspecialchars($rider['full_name']) ?></div>
            <div class="small muted"><?= htmlspecialchars($rider['email']) ?></div>
            <div class="small muted">Rider since <?= date('M j, Y', strtotime($rider['created_at'])) ?></div>
        </div>
    </div>
    <div class="small muted" style="margin-top:10px;">Your name and email are managed by the shop admin.</div>
</div>

<form class="card" method="POST">
    <div style="font-weight:800;">Contact &amp; address</div>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save_info">
    <label class="f" for="phone">Phone</label>
    <input class="in" id="phone" name="phone" type="tel" value="<?= htmlspecialchars((string)$rider['phone']) ?>">
    <label class="f" for="muni">Municipality / City</label>
    <select class="in" id="muni" name="municipality" onchange="loadBarangays(this.value)" required>
        <option value="">— Select municipality —</option>
        <?php foreach (array_keys($BOHOL_LOCATIONS) as $m): ?>
            <option value="<?= htmlspecialchars($m) ?>" <?= $rider['municipality'] === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="f" for="brgy">Barangay</label>
    <select class="in" id="brgy" name="barangay" required>
        <option value="">— Select municipality first —</option>
    </select>
    <label class="f" for="street">Street / Purok / House No. <span class="muted" style="font-weight:400;">(optional)</span></label>
    <input class="in" id="street" name="street_address" value="<?= htmlspecialchars((string)$rider['street_address']) ?>">
    <label class="f" for="land">Landmark <span class="muted" style="font-weight:400;">(optional)</span></label>
    <input class="in" id="land" name="landmark" value="<?= htmlspecialchars((string)$rider['landmark']) ?>">
    <button class="btn primary" type="submit" style="margin-top:14px;">Save changes</button>
</form>

<form class="card" method="POST" autocomplete="off">
    <div style="font-weight:800;">Change password</div>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="change_password">
    <label class="f" for="cp">Current password</label>
    <input class="in" id="cp" name="current_password" type="password" autocomplete="current-password" required>
    <label class="f" for="np">New password (min. 8 characters)</label>
    <input class="in" id="np" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
    <label class="f" for="cf">Confirm new password</label>
    <input class="in" id="cf" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
    <button class="btn ghost" type="submit" style="margin-top:14px;">Update password</button>
</form>

<a href="../logout.php" class="btn ghost" style="color:var(--danger);border-color:#fecaca;"><i class="fa-solid fa-right-from-bracket"></i> Sign out</a>

<script>
    // Deliverable areas (from delivery_zones) for the cascading dropdowns
    const BOHOL_LOCATIONS = <?= json_encode($BOHOL_LOCATIONS) ?>;
    const SAVED_BARANGAY = <?= json_encode((string)$rider['barangay']) ?>;

    function loadBarangays(muni, preselect) {
        var sel = document.getElementById('brgy');
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
    (function() {
        var m = document.getElementById('muni');
        if (m && m.value) loadBarangays(m.value, SAVED_BARANGAY);
    })();
</script>

<?php riderFoot('profile'); ?>