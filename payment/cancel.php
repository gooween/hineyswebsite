<?php
// ============================================================
// HATCH — Payment cancelled landing
// File: payment/cancel.php
// ============================================================
session_start();
require_once '../config/db.php';
requireCustomer();

$activePage = 'orders';
$orderId  = (int)($_GET['order_id'] ?? 0);
$orderNum = $orderId ? str_pad($orderId, 4, '0', STR_PAD_LEFT) : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled — HATCH</title>
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
            --warn: #d98a17;
            --warn-tint: #fbf1de;
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
            background: var(--warn-tint);
            color: var(--warn);
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
            background: var(--warn-tint);
            border-radius: 10px;
            padding: 12px 16px;
            margin: 20px 0 28px;
            line-height: 1.6;
        }

        .btns {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            padding: 13px 26px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.92rem;
            transition: all 0.15s;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-ghost {
            background: #f0eee9;
            color: var(--ink-2);
        }

        .btn-ghost:hover {
            background: #e8e5e0;
        }
    </style>
</head>

<body>
    <?php include '../includes/navbar.php'; ?>
    <div class="wrap">
        <div class="card">
            <div class="icon"><i class="fa-solid fa-circle-xmark"></i></div>
            <h1>Payment Cancelled</h1>
            <p>Your payment for order <span class="order">#<?= htmlspecialchars($orderNum) ?></span> was not completed.</p>
            <div class="note"><i class="fa-solid fa-circle-info"></i> The order is saved as <strong>unpaid</strong> — nothing was charged. You can retry the payment anytime from My Orders.</div>
            <div class="btns">
                <a href="../user/orders.php" class="btn btn-primary"><i class="fa-solid fa-rotate-right"></i> Retry Payment</a>
                <a href="../user/products.php" class="btn btn-ghost"><i class="fa-solid fa-bag-shopping"></i> Keep Shopping</a>
            </div>
        </div>
    </div>
</body>

</html>