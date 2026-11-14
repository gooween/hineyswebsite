<?php
// ============================================================
// rider/index.php — the rider's deliveries (mobile)
//   - Available / Off duty toggle
//   - Assigned orders: Start Delivery -> Mark Delivered (with photo)
// A rider can only see and change orders that are assigned to them.
// ============================================================
require_once __DIR__ . '/rider_auth.php';
require_once __DIR__ . '/../config/cloudinary.php';   // cloudinaryUpload() — same helper as the GCash proof upload
require_once __DIR__ . '/rider_layout.php';

$rider = riderLoad($conn);
$uid   = (int)$rider['id'];

// ── Actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!riderCheckCsrf()) redirect('index.php', 'error', 'Your session expired. Please try again.');
    $action  = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    // Availability
    if ($action === 'set_availability') {
        $val = ($_POST['availability'] ?? '') === 'available' ? 'available' : 'off_duty';
        if ($val === 'off_duty') {
            $busy = $conn->query("SELECT COUNT(*) c FROM orders WHERE rider_id={$uid} AND status='out_for_delivery'")->fetch_assoc()['c'] ?? 0;
            if ((int)$busy > 0) redirect('index.php', 'warning', 'Finish your current delivery first, then go off duty.');
        }
        $st = $conn->prepare("UPDATE riders SET availability = ?, availability_updated_at = NOW() WHERE user_id = ?");
        $st->bind_param('si', $val, $uid);
        $st->execute();
        $st->close();
        redirect('index.php', 'success', $val === 'available' ? "You're now Available for deliveries." : "You're now Off duty.");
    }

    // Start delivery: Processing -> Out for Delivery
    if ($action === 'start_delivery' && $orderId) {
        $st = $conn->prepare("UPDATE orders SET status='out_for_delivery', updated_at=NOW()
                              WHERE id=? AND rider_id=? AND status='processing' AND (delivery_address IS NULL OR delivery_address NOT LIKE 'PICKUP%')");
        $st->bind_param('ii', $orderId, $uid);
        $st->execute();
        $changed = $st->affected_rows > 0;
        $st->close();
        redirect('index.php', $changed ? 'success' : 'error', $changed ? 'Delivery started. Drive safe!' : 'This order is not ready to start.');
    }

    // Delivered: photo required; COD is marked paid + a transaction is recorded
    if ($action === 'mark_delivered' && $orderId) {
        $st = $conn->prepare("SELECT id, status, payment_method, payment_status, total_amount, paymongo_method, delivery_address
                              FROM orders WHERE id=? AND rider_id=? LIMIT 1");
        $st->bind_param('ii', $orderId, $uid);
        $st->execute();
        $o = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$o) redirect('index.php', 'error', 'Order not found.');
        if ($o['status'] !== 'out_for_delivery') redirect('index.php', 'error', 'This order is not out for delivery.');

        // photo
        $pf = null;
        foreach (['delivery_proof_cam', 'delivery_proof'] as $k) {
            if (isset($_FILES[$k]) && $_FILES[$k]['error'] !== UPLOAD_ERR_NO_FILE) {
                $pf = $_FILES[$k];
                if ($pf['error'] === UPLOAD_ERR_OK) break;
            }
        }
        if ($pf === null) redirect('index.php', 'error', 'Please take a photo as proof of delivery.');
        if ($pf['error'] !== UPLOAD_ERR_OK) redirect('index.php', 'error', 'The photo could not be uploaded (it may be too large). Please try again.');
        $ext = strtolower(pathinfo($pf['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) redirect('index.php', 'error', 'Invalid photo type. Use JPG, PNG, GIF or WEBP.');
        if ($pf['size'] > 8 * 1024 * 1024) redirect('index.php', 'error', 'Photo too large. Max 8MB.');
        $url = cloudinaryUpload($pf['tmp_name'], 'delivery_proofs');
        if (!$url) redirect('index.php', 'error', 'Failed to upload the photo. The order was not changed — please try again.');

        $isCod = ($o['payment_method'] === 'cod' && $o['payment_status'] !== 'paid');
        try {
            $conn->begin_transaction();
            $up = $conn->prepare("UPDATE orders SET status='delivered', delivered_at=NOW(), delivery_proof=?, updated_at=NOW()
                                  WHERE id=? AND rider_id=? AND status='out_for_delivery'");
            $up->bind_param('sii', $url, $orderId, $uid);
            $up->execute();
            if ($up->affected_rows < 1) {
                $up->close();
                $conn->rollback();
                redirect('index.php', 'error', 'This order was already updated.');
            }
            $up->close();

            if ($isCod) {
                $conn->query("UPDATE orders SET payment_status='paid', paid_at=NOW() WHERE id={$orderId}");
                $has = $conn->query("SELECT id FROM transactions WHERE order_id={$orderId} LIMIT 1");
                if ($has && $has->num_rows === 0) {
                    $amt  = (float)$o['total_amount'];
                    $meth = 'cod';
                    $note = 'Cash collected by rider ' . $rider['full_name'] . ' on delivery';
                    $tx = $conn->prepare("INSERT INTO transactions (order_id, amount, payment_method, transaction_date, notes) VALUES (?, ?, ?, NOW(), ?)");
                    $tx->bind_param('idss', $orderId, $amt, $meth, $note);
                    $tx->execute();
                    $tx->close();
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            redirect('index.php', 'error', 'Could not save the delivery. Please try again.');
        }
        redirect('index.php', 'success', '✓ Delivered! Great job.' . ($isCod ? ' Cash of ' . peso((float)$o['total_amount']) . ' recorded.' : ''));
    }
}

// ── Data ─────────────────────────────────────────────────────
$orders = [];
$res = $conn->query("SELECT o.id, o.tracking_no, o.status, o.total_amount, o.payment_method, o.payment_status,
                            o.delivery_address, o.notes, o.created_at, u.full_name, u.phone
                     FROM orders o JOIN users u ON u.id = o.user_id
                     WHERE o.rider_id = {$uid} AND o.status IN ('approved','processing','out_for_delivery')
                     ORDER BY FIELD(o.status,'out_for_delivery','processing','approved'), o.created_at ASC");
while ($res && $r = $res->fetch_assoc()) $orders[$r['id']] = $r;

$items = [];
if ($orders) {
    $ids = implode(',', array_map('intval', array_keys($orders)));
    $ir = $conn->query("SELECT oi.order_id, oi.quantity, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id IN ({$ids}) ORDER BY oi.id");
    while ($ir && $i = $ir->fetch_assoc()) $items[$i['order_id']][] = $i;
}

$activeN   = 0;
foreach ($orders as $o) if ($o['status'] === 'out_for_delivery') $activeN++;
$todayN    = (int)($conn->query("SELECT COUNT(*) c FROM orders WHERE rider_id={$uid} AND status='delivered' AND DATE(COALESCE(delivered_at, updated_at)) = CURDATE()")->fetch_assoc()['c'] ?? 0);
$available = $rider['availability'] === 'available';
$csrf      = riderCsrf();

riderHead('Deliveries', 'deliveries', $rider);
?>

<!-- Availability -->
<div class="card">
    <div class="row between">
        <div>
            <div class="small muted" style="font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Your status</div>
            <div style="font-size:1.15rem;font-weight:800;margin-top:2px;">
                <?php if ($activeN > 0): ?><span style="color:#1e40af;"><i class="fa-solid fa-motorcycle"></i> On delivery (<?= $activeN ?>)</span>
                <?php elseif ($available): ?><span style="color:var(--ok);"><i class="fa-solid fa-circle-check"></i> Available</span>
                <?php else: ?><span style="color:#4b5563;"><i class="fa-solid fa-moon"></i> Off duty</span><?php endif; ?>
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="set_availability">
            <input type="hidden" name="availability" value="<?= $available ? 'off_duty' : 'available' ?>">
            <button class="btn sm <?= $available ? 'ghost' : 'primary' ?>" type="submit"><?= $available ? 'Go off duty' : 'Go available' ?></button>
        </form>
    </div>
</div>

<div class="grid2">
    <div class="card stat">
        <div class="n"><?= count($orders) ?></div>
        <div class="l">Active orders</div>
    </div>
    <div class="card stat">
        <div class="n"><?= $todayN ?></div>
        <div class="l">Delivered today</div>
    </div>
</div>

<h1 class="page">My Deliveries</h1>

<?php if (!$orders): ?>
    <div class="card empty">
        <i class="fa-solid fa-box-open"></i>
        <div style="font-weight:700;">No deliveries right now</div>
        <div class="small">New orders assigned to you by the admin will show up here.<?= $available ? '' : ' Set yourself to Available so you can be assigned.' ?></div>
    </div>
<?php endif; ?>

<?php foreach ($orders as $o):
    $oid     = (int)$o['id'];
    $isCod   = $o['payment_method'] === 'cod' && $o['payment_status'] !== 'paid';
    $mapUrl  = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode((string)$o['delivery_address']);
    $tel     = preg_replace('/[^0-9+]/', '', (string)$o['phone']);
    // hide admin/system log lines, show only what the customer wrote
    $noteLines = [];
    foreach (preg_split('/\R/', (string)$o['notes']) as $ln) {
        $ln = trim($ln);
        if ($ln !== '' && $ln[0] !== '[') $noteLines[] = $ln;
    }
?>
    <div class="card">
        <div class="row between" style="align-items:flex-start;">
            <div>
                <div style="font-weight:800;font-size:1.05rem;">Order #<?= str_pad((string)$oid, 4, '0', STR_PAD_LEFT) ?></div>
                <?php if (!empty($o['tracking_no'])): ?><div class="small muted" style="font-family:monospace;"><?= htmlspecialchars($o['tracking_no']) ?></div><?php endif; ?>
            </div>
            <?php if ($o['status'] === 'out_for_delivery'): ?><span class="chip blue"><i class="fa-solid fa-motorcycle"></i> On the way</span>
            <?php elseif ($o['status'] === 'processing'): ?><span class="chip ok"><i class="fa-solid fa-box"></i> Ready to go</span>
            <?php else: ?><span class="chip warn"><i class="fa-solid fa-hourglass-half"></i> Being prepared</span><?php endif; ?>
        </div>

        <div style="margin-top:12px;">
            <div style="font-weight:700;"><i class="fa-solid fa-user muted"></i> <?= htmlspecialchars($o['full_name']) ?></div>
            <?php if ($o['phone']): ?>
                <a href="tel:<?= htmlspecialchars($tel) ?>" style="text-decoration:none;color:var(--brand-dark);font-weight:700;"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($o['phone']) ?></a>
            <?php endif; ?>
        </div>

        <div style="margin-top:10px;">
            <div class="small muted" style="font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Deliver to</div>
            <div style="margin-top:2px;"><?= nl2br(htmlspecialchars((string)$o['delivery_address'])) ?></div>
        </div>

        <?php if (!empty($items[$oid])): ?>
            <div style="margin-top:10px;border-top:1px dashed var(--line);padding-top:8px;">
                <?php foreach ($items[$oid] as $it): ?>
                    <div class="row between small"><span><?= htmlspecialchars($it['name']) ?></span><strong>× <?= (int)$it['quantity'] ?></strong></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($noteLines): ?>
            <div class="small" style="margin-top:10px;background:var(--warn-soft);border-radius:10px;padding:8px 10px;"><i class="fa-solid fa-note-sticky"></i> <?= htmlspecialchars(implode(' · ', $noteLines)) ?></div>
        <?php endif; ?>

        <div style="margin-top:12px;padding:10px 12px;border-radius:12px;background:<?= $isCod ? 'var(--brand-soft)' : 'var(--ok-soft)' ?>;">
            <?php if ($isCod): ?>
                <div class="small" style="font-weight:700;color:var(--brand-dark);">COLLECT CASH</div>
                <div style="font-size:1.4rem;font-weight:800;color:var(--brand-dark);"><?= peso((float)$o['total_amount']) ?></div>
            <?php elseif ($o['payment_status'] === 'paid'): ?>
                <div style="font-weight:700;color:var(--ok);"><i class="fa-solid fa-circle-check"></i> Already paid — nothing to collect</div>
            <?php else: ?>
                <div style="font-weight:700;color:#92400e;"><i class="fa-solid fa-circle-info"></i> Online payment is being verified by the shop — don't collect cash</div>
            <?php endif; ?>
        </div>

        <div style="margin-top:12px;display:grid;gap:8px;">
            <a class="btn ghost" href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-location-dot"></i> Open in Maps</a>

            <?php if ($o['status'] === 'processing'): ?>
                <form method="POST" onsubmit="return confirm('Start this delivery now?');">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="start_delivery">
                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                    <button class="btn primary" type="submit"><i class="fa-solid fa-motorcycle"></i> Start Delivery</button>
                </form>
            <?php elseif ($o['status'] === 'out_for_delivery'): ?>
                <button class="btn ok" type="button" onclick="openDeliver(<?= $oid ?>, '#<?= str_pad((string)$oid, 4, '0', STR_PAD_LEFT) ?>', <?= $isCod ? json_encode(peso((float)$o['total_amount'])) : 'null' ?>)"><i class="fa-solid fa-camera"></i> Mark Delivered</button>
            <?php else: ?>
                <div class="small muted" style="text-align:center;">You can start once the shop marks this order as Processing.</div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<!-- Delivered sheet -->
<div class="sheet-bg" id="deliverSheet" onclick="if(event.target===this)closeDeliver()">
    <form class="sheet" method="POST" enctype="multipart/form-data" onsubmit="return checkPhoto()">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="mark_delivered">
        <input type="hidden" name="order_id" id="dv_order">
        <div class="row between">
            <div style="font-weight:800;font-size:1.1rem;">Delivered — <span id="dv_label"></span></div>
            <button type="button" class="btn ghost sm" onclick="closeDeliver()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="dv_cash" style="display:none;margin-top:10px;padding:10px 12px;border-radius:12px;background:var(--brand-soft);color:var(--brand-dark);font-weight:700;"></div>
        <p class="small muted" style="margin:12px 0 8px;">Take a clear photo of the parcel at the customer's door. The customer and the shop will see it.</p>

        <label class="btn primary" style="cursor:pointer;margin-bottom:8px;"><i class="fa-solid fa-camera"></i> Take Photo
            <input type="file" name="delivery_proof_cam" id="dv_cam" accept="image/*" capture="environment" style="display:none;" onchange="prepPhoto(this,'dv_file')">
        </label>
        <label class="btn ghost" style="cursor:pointer;"><i class="fa-solid fa-image"></i> Choose from Gallery
            <input type="file" name="delivery_proof" id="dv_file" accept="image/*" style="display:none;" onchange="prepPhoto(this,'dv_cam')">
        </label>

        <div id="dv_prev" style="display:none;margin-top:12px;text-align:center;">
            <img id="dv_img" alt="Selected photo" style="max-width:100%;max-height:260px;border-radius:12px;border:1px solid var(--line);">
        </div>

        <button type="submit" class="btn ok" id="dv_submit" style="margin-top:14px;"><i class="fa-solid fa-check"></i> Confirm Delivered</button>
    </form>
</div>

<script>
    function openDeliver(id, label, cash) {
        document.getElementById('dv_order').value = id;
        document.getElementById('dv_label').textContent = label;
        var c = document.getElementById('dv_cash');
        if (cash) {
            c.style.display = 'block';
            c.textContent = 'Collect cash: ' + cash;
        } else {
            c.style.display = 'none';
        }
        document.getElementById('dv_cam').value = '';
        document.getElementById('dv_file').value = '';
        document.getElementById('dv_prev').style.display = 'none';
        document.getElementById('dv_submit').disabled = false;
        document.getElementById('deliverSheet').classList.add('open');
    }

    function closeDeliver() {
        document.getElementById('deliverSheet').classList.remove('open');
    }

    // Shrinks phone photos (3-8 MB) to ~1600px JPEG before upload — fast on mobile data.
    function prepPhoto(input, otherId) {
        var other = document.getElementById(otherId);
        if (other) other.value = '';
        var f = input.files && input.files[0];
        var wrap = document.getElementById('dv_prev');
        if (!f) {
            wrap.style.display = 'none';
            return;
        }
        var url = URL.createObjectURL(f);
        var img = new Image();
        img.onload = function() {
            document.getElementById('dv_img').src = url;
            wrap.style.display = 'block';
            var max = 1600,
                s = Math.min(1, max / Math.max(img.width, img.height));
            if (s >= 1 && f.size < 1.5 * 1024 * 1024) return;
            var c = document.createElement('canvas');
            c.width = Math.round(img.width * s);
            c.height = Math.round(img.height * s);
            c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
            c.toBlob(function(blob) {
                if (!blob) return;
                try {
                    var dt = new DataTransfer();
                    dt.items.add(new File([blob], 'delivery.jpg', {
                        type: 'image/jpeg'
                    }));
                    input.files = dt.files;
                } catch (e) {
                    /* keep the original */
                }
            }, 'image/jpeg', 0.82);
        };
        img.onerror = function() {
            wrap.style.display = 'none';
        };
        img.src = url;
    }

    function checkPhoto() {
        var has = document.getElementById('dv_cam').files.length || document.getElementById('dv_file').files.length;
        if (!has) {
            alert('Please take a photo as proof of delivery first.');
            return false;
        }
        var b = document.getElementById('dv_submit');
        b.disabled = true;
        b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…';
        return true;
    }

    // Keep the list fresh without the rider doing anything (not while the photo sheet is open)
    setInterval(function() {
        if (!document.hidden && !document.getElementById('deliverSheet').classList.contains('open')) location.reload();
    }, 60000);
</script>

<?php riderFoot('deliveries'); ?>