<?php
// ============================================================
// rider/stats.php — the rider's delivery analytics + history
// ============================================================
require_once __DIR__ . '/rider_auth.php';
require_once __DIR__ . '/rider_layout.php';

$rider = riderLoad($conn);
$uid   = (int)$rider['id'];

$when = "COALESCE(delivered_at, updated_at)";

$t = $conn->query("SELECT COUNT(*) AS total,
                          SUM(DATE({$when}) = CURDATE()) AS today,
                          SUM(YEARWEEK({$when}, 1) = YEARWEEK(CURDATE(), 1)) AS wk,
                          SUM(DATE_FORMAT({$when}, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')) AS mo,
                          COALESCE(SUM(total_amount), 0) AS value,
                          COALESCE(SUM(CASE WHEN payment_method = 'cod' THEN total_amount ELSE 0 END), 0) AS cash
                   FROM orders WHERE rider_id = {$uid} AND status = 'delivered'")->fetch_assoc();
$total = (int)($t['total'] ?? 0);
$today = (int)($t['today'] ?? 0);
$week  = (int)($t['wk'] ?? 0);
$month = (int)($t['mo'] ?? 0);
$value = (float)($t['value'] ?? 0);
$cash  = (float)($t['cash'] ?? 0);

// Last 7 days (oldest -> today)
$days = [];
for ($i = 6; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-{$i} day"))] = 0;
$dr = $conn->query("SELECT DATE({$when}) AS d, COUNT(*) AS c FROM orders
                    WHERE rider_id = {$uid} AND status = 'delivered' AND DATE({$when}) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                    GROUP BY DATE({$when})");
while ($dr && $r = $dr->fetch_assoc()) if (isset($days[$r['d']])) $days[$r['d']] = (int)$r['c'];
$maxDay = max(1, max($days));

// History
$hist = [];
$hr = $conn->query("SELECT o.id, o.tracking_no, o.total_amount, o.payment_method, o.delivery_address, o.delivery_proof,
                           {$when} AS done_at, u.full_name
                    FROM orders o JOIN users u ON u.id = o.user_id
                    WHERE o.rider_id = {$uid} AND o.status = 'delivered'
                    ORDER BY {$when} DESC LIMIT 40");
while ($hr && $r = $hr->fetch_assoc()) $hist[] = $r;

riderHead('My Stats', 'stats', $rider);
?>

<h1 class="page">My Stats</h1>

<div class="grid2">
    <div class="card stat">
        <div class="n"><?= $today ?></div>
        <div class="l">Today</div>
    </div>
    <div class="card stat">
        <div class="n"><?= $week ?></div>
        <div class="l">This week</div>
    </div>
    <div class="card stat">
        <div class="n"><?= $month ?></div>
        <div class="l">This month</div>
    </div>
    <div class="card stat">
        <div class="n"><?= $total ?></div>
        <div class="l">All time</div>
    </div>
</div>

<div class="card">
    <div style="font-weight:800;margin-bottom:10px;">Last 7 days</div>
    <div style="display:flex;align-items:flex-end;gap:8px;height:110px;">
        <?php foreach ($days as $d => $c): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;">
                <div class="small" style="font-weight:700;"><?= $c ?: '' ?></div>
                <div style="width:100%;max-width:34px;border-radius:6px 6px 0 0;background:<?= $d === date('Y-m-d') ? 'var(--brand)' : '#f7c79c' ?>;height:<?= max(4, (int)round($c / $maxDay * 78)) ?>px;"></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;margin-top:4px;">
        <?php foreach ($days as $d => $c): ?>
            <div class="small muted" style="flex:1;text-align:center;"><?= date('D', strtotime($d)) ?></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="row between small"><span class="muted">Value of orders delivered</span><strong><?= peso($value) ?></strong></div>
    <div class="row between small" style="margin-top:6px;"><span class="muted">Cash collected (COD)</span><strong><?= peso($cash) ?></strong></div>
</div>

<h1 class="page" style="margin-top:18px;">Delivery history</h1>
<?php if (!$hist): ?>
    <div class="card empty"><i class="fa-solid fa-clock-rotate-left"></i>
        <div style="font-weight:700;">No completed deliveries yet</div>
    </div>
<?php endif; ?>
<?php foreach ($hist as $h): ?>
    <div class="card">
        <div class="row" style="align-items:flex-start;">
            <?php if (!empty($h['delivery_proof'])): ?>
                <a href="<?= htmlspecialchars(riderMedia($h['delivery_proof'])) ?>" target="_blank" rel="noopener">
                    <img src="<?= htmlspecialchars(riderMedia($h['delivery_proof'])) ?>" alt="Proof" style="width:56px;height:56px;object-fit:cover;border-radius:10px;border:1px solid var(--line);">
                </a>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div class="row between">
                    <strong>Order #<?= str_pad((string)$h['id'], 4, '0', STR_PAD_LEFT) ?></strong>
                    <span class="chip ok">Delivered</span>
                </div>
                <div class="small muted"><?= htmlspecialchars($h['full_name']) ?> · <?= peso((float)$h['total_amount']) ?></div>
                <div class="small muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars((string)$h['delivery_address']) ?></div>
                <div class="small muted"><?= date('M j, Y · g:i A', strtotime($h['done_at'])) ?></div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php riderFoot('stats'); ?>