<?php
session_start();
require_once '../config/db.php';
require_once '../config/paymongo.php';
requireCustomer();

$activePage = 'orders';
$orderId  = (int)($_GET['order_id'] ?? 0);
$orderNum = $orderId ? str_pad($orderId, 4, '0', STR_PAD_LEFT) : '';

$uid     = (int)$_SESSION['user_id'];
$isPaid  = false;
$paidVia = '';

if ($orderId) {
    $ps = $conn->prepare("SELECT payment_status, paymongo_method, paymongo_checkout_id FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $ps->bind_param('ii', $orderId, $uid);
    $ps->execute();
    $po = $ps->get_result()->fetch_assoc();
    $ps->close();

    if ($po && $po['payment_status'] !== 'paid' && !empty($po['paymongo_checkout_id'])) {
        [$code, $data] = paymongoRequest('GET', '/checkout_sessions/' . rawurlencode($po['paymongo_checkout_id']));
        $payments = $data['data']['attributes']['payments'] ?? [];
        if ($code === 200 && is_array($payments)) {
            foreach ($payments as $p) {
                if (($p['attributes']['status'] ?? '') === 'paid') {
                    $method = (string)($p['attributes']['source']['type'] ?? '');
                    $up = $conn->prepare("UPDATE orders SET payment_status='paid', paymongo_method=?, updated_at=NOW()
                                          WHERE id=? AND user_id=? AND payment_status <> 'paid'");
                    $up->bind_param('sii', $method, $orderId, $uid);
                    $up->execute();
                    $up->close();
                    $po['payment_status']  = 'paid';
                    $po['paymongo_method'] = $method;
                    break;
                }
            }
        }
    }

    if ($po && $po['payment_status'] === 'paid') {
        $isPaid  = true;
        $paidVia = (string)($po['paymongo_method'] ?? '');
    }
}
$tries   = (int)($_GET['n'] ?? 0);
$recheck = !$isPaid && $orderId && $tries < 10;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Received — HATCH</title>
    <?php if ($recheck): ?>
        <meta http-equiv="refresh" content="3;url=success.php?order_id=<?= $orderId ?>&n=<?= $tries + 1 ?>">
    <?php endif; ?>
    <style>
        :root {
            --primary: #e67e22;
            --primary-dark: #d16b12;
            --primary-tint: #fef4ea;
            --bg: #f7f6f3;
            --surface: #fff;
            --ink: #23201c;
            --ink-2: #6f6a62;
            --ink-3: #9c968c;
            --ok: #2f9e60;
            --ok-tint: #e6f4ec;
            --line: #ebe8e3;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        .wrap {
            min-height: calc(100vh - 64px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 18px;
            max-width: 480px;
            width: 100%;
            padding: 44px 36px;
            text-align: center;
            box-shadow: 0 16px 40px -12px rgba(35, 32, 28, 0.14);
        }

        .icon {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: var(--ok-tint);
            color: var(--ok);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.1rem;
            margin: 0 auto 22px;
        }

        h1 {
            font-size: 1.55rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 12px;
        }

        p {
            font-size: 0.96rem;
            color: var(--ink-2);
            line-height: 1.7;
            margin-bottom: 8px;
        }

        .order {
            font-weight: 700;
            color: var(--primary);
        }

        .note {
            font-size: 0.84rem;
            color: var(--ink-3);
            background: var(--primary-tint);
            border-radius: 10px;
            padding: 12px 16px;
            margin: 20px 0 28px;
            line-height: 1.6;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: #fff;
            text-decoration: none;
            padding: 13px 30px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: background 0.15s;
        }

        .btn:hover {
            background: var(--primary-dark);
        }
    </style>
</head>

<body>
    <?php include '../includes/navbar.php'; ?>
    <div class="wrap">
        <div class="card">
            <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
            <?php if ($isPaid): ?>
                <h1>Payment Confirmed!</h1>
                <p>Thank you! Your payment<?= $paidVia !== '' ? ' via <strong>' . htmlspecialchars($paidVia) . '</strong>' : '' ?> for order <span class="order">#<?= htmlspecialchars($orderNum) ?></span> is confirmed.</p>
                <div class="note"><i class="fa-solid fa-circle-check"></i> Your order is marked <strong>Paid</strong>. We'll review and confirm it shortly — track it anytime in My Orders.</div>
            <?php else: ?>
                <h1>Payment Received!</h1>
                <p>Thank you! Your payment for order <span class="order">#<?= htmlspecialchars($orderNum) ?></span> was received.</p>
                <div class="note"><i class="fa-solid fa-clock"></i> We're confirming it now — this page updates automatically, and your order will show as <strong>Paid</strong> in My Orders within a few moments.</div>
            <?php endif; ?>
            <a href="../user/orders.php" class="btn"><i class="fa-solid fa-box"></i> View My Orders</a>
        </div>
    </div>
</body>

</html>