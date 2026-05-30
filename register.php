<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: register.php
// Purpose: Customer self-registration page
// ============================================================
session_start();
require_once 'config/db.php';

if (!empty($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin/dashboard.php');
        exit;
    } else {
        header('Location: user/home.php');
        exit;
    }
}

$errors   = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['full_name'] = trim($_POST['full_name'] ?? '');
    $formData['email']     = trim($_POST['email']     ?? '');
    $formData['phone']     = trim($_POST['phone']     ?? '');
    $formData['address']   = trim($_POST['address']   ?? '');
    $password              = $_POST['password']         ?? '';
    $confirmPw             = $_POST['confirm_password'] ?? '';

    if (!$formData['full_name']) {
        $errors['full_name'] = 'Full name is required.';
    } elseif (strlen($formData['full_name']) < 3) {
        $errors['full_name'] = 'Full name must be at least 3 characters.';
    }

    if (!$formData['email']) {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $formData['email']);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors['email'] = 'This email is already registered. Please log in.';
        }
        $stmt->close();
    }

    if ($formData['phone'] && !preg_match('/^[0-9+\-\s()]{7,20}$/', $formData['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }

    if (!$password) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors['password'] = 'Password must be at least 6 characters.';
    }

    if (!$confirmPw) {
        $errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirmPw) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $hashedPw = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare(
            "INSERT INTO users (full_name, email, password, role, phone, address, is_active)
             VALUES (?, ?, ?, 'customer', ?, ?, 1)"
        );
        $stmt->bind_param('sssss', $formData['full_name'], $formData['email'], $hashedPw, $formData['phone'], $formData['address']);
        if ($stmt->execute()) {
            $stmt->close();
            header('Location: index.php?msg=registered');
            exit;
        } else {
            $errors['general'] = 'Registration failed. Please try again.';
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <title>Create Account — Hiney's Eggs &amp; Live Chicken</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap');

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --amber: #d97706;
            --amber-lt: #f59e0b;
            --amber-dark: #b45309;
            --cream: #fdf8f0;
            --cream-dark: #f5ede0;
            --brown: #292015;
            --brown-mid: #5c4a32;
            --brown-lt: #8b6f4e;
            --text: #1c1410;
            --text-muted: #7a6653;
            --border: #e8ddd0;
            --danger: #c0392b;
            --success: #2d7a4f;
            --white: #ffffff;
        }

        html,
        body {
            min-height: 100vh;
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--cream);
            color: var(--text);
        }

        .page {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 540px;
        }

        /* ── Left Panel ── */
        .panel {
            background: var(--brown);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 52px 56px;
        }

        .panel-texture {
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(ellipse 70% 55% at 10% 20%, rgba(245, 158, 11, 0.14) 0%, transparent 60%),
                radial-gradient(ellipse 80% 60% at 90% 80%, rgba(217, 119, 6, 0.18) 0%, transparent 60%),
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='1' fill='%23ffffff08'/%3E%3C/svg%3E");
        }

        .panel-top,
        .panel-bottom {
            position: relative;
            z-index: 1;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 64px;
        }

        .brand-egg {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--amber-lt), var(--amber));
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            box-shadow: 0 4px 16px rgba(217, 119, 6, 0.4);
        }

        .brand-name {
            font-family: 'DM Serif Display', serif;
            font-size: 1.25rem;
            color: var(--white);
        }

        .brand-tagline {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            margin-top: 1px;
        }

        .panel-headline {
            font-family: 'DM Serif Display', serif;
            font-size: clamp(2rem, 3vw, 3rem);
            color: var(--white);
            line-height: 1.12;
            letter-spacing: -0.02em;
            margin-bottom: 20px;
        }

        .panel-headline em {
            font-style: italic;
            color: var(--amber-lt);
        }

        .panel-sub {
            font-size: 0.93rem;
            color: rgba(255, 255, 255, 0.45);
            line-height: 1.75;
            max-width: 360px;
            margin-bottom: 48px;
        }

        /* Steps */
        .steps {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .step {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .step-num {
            width: 30px;
            height: 30px;
            background: rgba(217, 119, 6, 0.2);
            border: 1px solid rgba(217, 119, 6, 0.35);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--amber-lt);
            flex-shrink: 0;
            margin-top: 1px;
        }

        .step-body strong {
            display: block;
            font-size: 0.87rem;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 600;
            margin-bottom: 2px;
        }

        .step-body span {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.35);
            line-height: 1.5;
        }

        .panel-footer-text {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.18);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        /* ── Form Side ── */
        .form-side {
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 44px 52px;
            box-shadow: -8px 0 48px rgba(0, 0, 0, 0.08);
            overflow-y: auto;
        }

        .form-wrap {
            width: 100%;
            max-width: 400px;
        }

        .form-eyebrow {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--amber);
            margin-bottom: 10px;
        }

        .form-title {
            font-family: 'DM Serif Display', serif;
            font-size: 1.85rem;
            color: var(--brown);
            letter-spacing: -0.02em;
            margin-bottom: 6px;
            line-height: 1.15;
        }

        .form-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 28px;
            line-height: 1.6;
        }

        .form-desc a {
            color: var(--amber);
            font-weight: 600;
            text-decoration: none;
        }

        .form-desc a:hover {
            text-decoration: underline;
        }

        /* Section label */
        .section-label {
            font-size: 0.67rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--brown-lt);
            margin: 22px 0 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--cream-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-label:first-of-type {
            margin-top: 0;
        }

        /* Alerts */
        .alert {
            padding: 11px 14px;
            border-radius: 9px;
            font-size: 0.82rem;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 9px;
            line-height: 1.5;
        }

        .alert-error {
            background: #fef2f2;
            color: var(--danger);
            border: 1px solid #fecaca;
        }

        /* Form groups */
        .form-group {
            margin-bottom: 14px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .label {
            display: block;
            font-size: 0.77rem;
            font-weight: 600;
            color: var(--brown-mid);
            margin-bottom: 6px;
            letter-spacing: 0.02em;
        }

        .label .req {
            color: var(--danger);
            margin-left: 2px;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--brown-lt);
            pointer-events: none;
            display: flex;
            align-items: center;
        }

        .input-icon svg {
            width: 15px;
            height: 15px;
            stroke: currentColor;
            fill: none;
        }

        .input-wrap.textarea-wrap .input-icon {
            top: 13px;
            transform: none;
        }

        .input {
            width: 100%;
            padding: 11px 13px 11px 40px;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-size: 0.875rem;
            font-family: 'DM Sans', sans-serif;
            color: var(--text);
            background: var(--cream);
            outline: none;
            transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
        }

        .input:focus {
            border-color: var(--amber);
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
            background: var(--white);
        }

        .input.is-error {
            border-color: var(--danger);
            background: #fff8f8;
        }

        .input.is-valid {
            border-color: var(--success);
        }

        .input::placeholder {
            color: #c4b5a5;
        }

        textarea.input {
            resize: vertical;
            min-height: 76px;
            padding-top: 10px;
        }

        .field-err {
            font-size: 0.74rem;
            color: var(--danger);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Password strength */
        .strength-bar {
            height: 3px;
            background: #f0ebe4;
            border-radius: 3px;
            margin-top: 7px;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.25s, background 0.25s;
            width: 0%;
        }

        .strength-text {
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 3px;
            color: var(--text-muted);
        }

        .pw-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--brown-lt);
            display: flex;
            align-items: center;
            padding: 3px;
            transition: color 0.15s;
        }

        .pw-toggle:hover {
            color: var(--amber);
        }

        .pw-toggle svg {
            width: 15px;
            height: 15px;
            stroke: currentColor;
            fill: none;
        }

        /* Terms */
        .terms {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin: 18px 0;
            font-size: 0.81rem;
            color: var(--text-muted);
            line-height: 1.55;
        }

        .terms input[type="checkbox"] {
            width: 15px;
            height: 15px;
            margin-top: 2px;
            accent-color: var(--amber);
            cursor: pointer;
            flex-shrink: 0;
        }

        .terms a {
            color: var(--amber);
            font-weight: 600;
            text-decoration: none;
        }

        .terms a:hover {
            text-decoration: underline;
        }

        /* Button */
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: var(--brown);
            color: var(--white);
            border: none;
            border-radius: 10px;
            font-size: 0.92rem;
            font-weight: 700;
            font-family: 'DM Sans', sans-serif;
            cursor: pointer;
            letter-spacing: 0.02em;
            transition: background 0.18s, transform 0.15s, box-shadow 0.18s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .btn-submit::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.07), transparent);
        }

        .btn-submit:hover {
            background: var(--amber-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(180, 83, 9, 0.35);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .spinner {
            display: none;
            width: 15px;
            height: 15px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.65s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 22px 0;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .divider span {
            font-size: 0.74rem;
            color: #c4b5a5;
            font-weight: 500;
        }

        .login-link {
            text-align: center;
            font-size: 0.84rem;
            color: var(--text-muted);
        }

        .login-link a {
            color: var(--amber);
            font-weight: 700;
            text-decoration: none;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .footer-note {
            text-align: center;
            font-size: 0.7rem;
            color: #c4b5a5;
            margin-top: 24px;
            line-height: 1.7;
        }

        /* Responsive */
        @media (max-width: 960px) {
            .page {
                grid-template-columns: 1fr;
            }

            .panel {
                display: none;
            }

            .form-side {
                padding: 36px 24px;
                box-shadow: none;
            }
        }

        @media (max-width: 480px) {
            .form-side {
                padding: 28px 16px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="page">

        <!-- Left Panel -->
        <div class="panel">
            <div class="panel-texture"></div>

            <div class="panel-top">
                <div class="brand">
                    <img src="assets/images/hineys_logo.png" alt="Hiney's"
                        style="width:44px;height:44px;border-radius:50% 50% 50% 50%/60% 60% 40% 40%;object-fit:cover;">
                    <div>
                        <div class="brand-name">Hiney's</div>
                        <div class="brand-tagline">Eggs &amp; Live Chicken</div>
                    </div>
                </div>

                <h1 class="panel-headline">
                    Join our<br>
                    <em>farm family</em><br>
                    today.
                </h1>

                <p class="panel-sub">
                    Get access to fresh eggs and healthy live chickens
                    delivered straight from our farm to your doorstep.
                </p>

                <div class="steps">
                    <div class="step">
                        <div class="step-num">1</div>
                        <div class="step-body">
                            <strong>Create your account</strong>
                            <span>Quick and free — takes under a minute</span>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">2</div>
                        <div class="step-body">
                            <strong>Browse our products</strong>
                            <span>Fresh eggs &amp; live chickens, farm-direct</span>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">3</div>
                        <div class="step-body">
                            <strong>Place your order</strong>
                            <span>Cash on delivery or GCash accepted</span>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-num">4</div>
                        <div class="step-body">
                            <strong>Enjoy farm freshness</strong>
                            <span>Delivered right to your door</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel-bottom">
                <div class="panel-footer-text">Loerto Cortes, Bohol &nbsp;🌴</div>
            </div>
        </div>

        <!-- Right Form Side -->
        <div class="form-side">
            <div class="form-wrap">

                <div class="form-eyebrow">New account</div>
                <h2 class="form-title">Create your<br>account</h2>
                <p class="form-desc">
                    Already have one?
                    <a href="index.php">Sign in here →</a>
                </p>

                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-error">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <?= htmlspecialchars($errors['general']) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register.php" id="regForm" onsubmit="handleSubmit(event)">

                    <div class="section-label">Personal Information</div>

                    <div class="form-group">
                        <label class="label" for="full_name">Full Name <span class="req">*</span></label>
                        <div class="input-wrap">
                            <span class="input-icon"><svg viewBox="0 0 24 24" stroke-width="1.8">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                    <circle cx="12" cy="7" r="4" />
                                </svg></span>
                            <input type="text" id="full_name" name="full_name"
                                class="input <?= isset($errors['full_name']) ? 'is-error' : '' ?>"
                                placeholder="e.g. Maria Santos"
                                value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>"
                                required autocomplete="name">
                        </div>
                        <?php if (isset($errors['full_name'])): ?>
                            <div class="field-err">⚠ <?= htmlspecialchars($errors['full_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="label" for="email">Email <span class="req">*</span></label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg viewBox="0 0 24 24" stroke-width="1.8">
                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                                        <polyline points="22,6 12,13 2,6" />
                                    </svg></span>
                                <input type="email" id="email" name="email"
                                    class="input <?= isset($errors['email']) ? 'is-error' : '' ?>"
                                    placeholder="you@email.com"
                                    value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                                    required autocomplete="email">
                            </div>
                            <?php if (isset($errors['email'])): ?>
                                <div class="field-err">⚠ <?= htmlspecialchars($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="label" for="phone">Phone</label>
                            <div class="input-wrap">
                                <span class="input-icon"><svg viewBox="0 0 24 24" stroke-width="1.8">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13 19.79 19.79 0 0 1 1.61 4.37 2 2 0 0 1 3.6 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.09 6.09l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z" />
                                    </svg></span>
                                <input type="tel" id="phone" name="phone"
                                    class="input <?= isset($errors['phone']) ? 'is-error' : '' ?>"
                                    placeholder="09XXXXXXXXX"
                                    value="<?= htmlspecialchars($formData['phone'] ?? '') ?>"
                                    autocomplete="tel">
                            </div>
                            <?php if (isset($errors['phone'])): ?>
                                <div class="field-err">⚠ <?= htmlspecialchars($errors['phone']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="label" for="address">Delivery Address</label>
                        <div class="input-wrap textarea-wrap">
                            <span class="input-icon"><svg viewBox="0 0 24 24" stroke-width="1.8">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                                    <circle cx="12" cy="10" r="3" />
                                </svg></span>
                            <textarea id="address" name="address"
                                class="input"
                                placeholder="House No., Street, Barangay, City"
                                style="padding-left:40px;"><?= htmlspecialchars($formData['address'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div class="section-label">Account Security</div>

                    <div class="form-group">
                        <label class="label" for="password">Password <span class="req">*</span></label>
                        <div class="input-wrap">
                            <span class="input-icon"><svg viewBox="0 0 24 24" stroke-width="1.8">
                                    <rect x="3" y="11" width="18" height="11" rx="2" />
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                </svg></span>
                            <input type="password" id="password" name="password"
                                class="input <?= isset($errors['password']) ? 'is-error' : '' ?>"
                                placeholder="Minimum 6 characters"
                                required autocomplete="new-password"
                                oninput="checkStrength(this.value)">
                            <button type="button" class="pw-toggle" id="pwToggle1" onclick="togglePw('password','pwToggle1')">
                                <svg viewBox="0 0 24 24" stroke-width="1.8">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                        <div class="strength-bar">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                        <div class="strength-text" id="strengthText"></div>
                        <?php if (isset($errors['password'])): ?>
                            <div class="field-err">⚠ <?= htmlspecialchars($errors['password']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="label" for="confirm_password">Confirm Password <span class="req">*</span></label>
                        <div class="input-wrap">
                            <span class="input-icon"><svg viewBox="0 0 24 24" stroke-width="1.8">
                                    <rect x="3" y="11" width="18" height="11" rx="2" />
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                </svg></span>
                            <input type="password" id="confirm_password" name="confirm_password"
                                class="input <?= isset($errors['confirm_password']) ? 'is-error' : '' ?>"
                                placeholder="Re-enter your password"
                                required autocomplete="new-password"
                                oninput="checkMatch()">
                            <button type="button" class="pw-toggle" id="pwToggle2" onclick="togglePw('confirm_password','pwToggle2')">
                                <svg viewBox="0 0 24 24" stroke-width="1.8">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                        <div class="field-err" id="matchErr" style="display:none;">⚠ Passwords do not match.</div>
                        <?php if (isset($errors['confirm_password'])): ?>
                            <div class="field-err">⚠ <?= htmlspecialchars($errors['confirm_password']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="terms">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms" style="cursor:pointer;">
                            I agree to the <a href="user/about.php">Terms of Service</a> and confirm that my information is accurate.
                        </label>
                    </div>

                    <button type="submit" class="btn-submit" id="regBtn">
                        <span id="btnText">Create My Account</span>
                        <span class="spinner" id="btnSpinner"></span>
                    </button>

                </form>

                <div class="divider"><span>or</span></div>

                <div class="login-link">
                    Already have an account? <a href="index.php">Sign in →</a>
                </div>

                <div class="footer-note">
                    &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business<br>
                    Loreto Cortes, Bohol
                </div>

            </div>
        </div>

    </div>

    <script>
        const eyeOpen = `<svg viewBox="0 0 24 24" stroke-width="1.8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
        const eyeClosed = `<svg viewBox="0 0 24 24" stroke-width="1.8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;

        function togglePw(inputId, btnId) {
            const input = document.getElementById(inputId);
            const btn = document.getElementById(btnId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.innerHTML = eyeClosed;
            } else {
                input.type = 'password';
                btn.innerHTML = eyeOpen;
            }
        }

        function checkStrength(val) {
            const fill = document.getElementById('strengthFill');
            const text = document.getElementById('strengthText');
            if (!val) {
                fill.style.width = '0%';
                text.textContent = '';
                return;
            }

            let score = 0;
            if (val.length >= 6) score++;
            if (val.length >= 10) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            const levels = [{
                    pct: '20%',
                    bg: '#dc2626',
                    label: 'Very weak',
                    color: '#dc2626'
                },
                {
                    pct: '40%',
                    bg: '#d97706',
                    label: 'Weak',
                    color: '#d97706'
                },
                {
                    pct: '60%',
                    bg: '#f59e0b',
                    label: 'Fair',
                    color: '#b45309'
                },
                {
                    pct: '80%',
                    bg: '#16a34a',
                    label: 'Strong',
                    color: '#15803d'
                },
                {
                    pct: '100%',
                    bg: '#047857',
                    label: 'Very strong',
                    color: '#065f46'
                },
            ];
            const lvl = levels[Math.min(score - 1, 4)] || levels[0];
            fill.style.width = lvl.pct;
            fill.style.background = lvl.bg;
            text.textContent = lvl.label;
            text.style.color = lvl.color;
        }

        function checkMatch() {
            const pw1 = document.getElementById('password').value;
            const pw2 = document.getElementById('confirm_password').value;
            const err = document.getElementById('matchErr');
            const inp = document.getElementById('confirm_password');
            if (pw2 && pw1 !== pw2) {
                err.style.display = 'flex';
                inp.classList.add('is-error');
                inp.classList.remove('is-valid');
            } else {
                err.style.display = 'none';
                inp.classList.remove('is-error');
                if (pw2 && pw1 === pw2) inp.classList.add('is-valid');
            }
        }

        function handleSubmit(e) {
            const pw1 = document.getElementById('password').value;
            const pw2 = document.getElementById('confirm_password').value;
            if (pw1 !== pw2) {
                e.preventDefault();
                checkMatch();
                return;
            }

            const btn = document.getElementById('regBtn');
            const text = document.getElementById('btnText');
            const spinner = document.getElementById('btnSpinner');
            btn.disabled = true;
            text.textContent = 'Creating account…';
            spinner.style.display = 'block';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const fn = document.getElementById('full_name');
            if (fn && !fn.value) fn.focus();
        });

        document.getElementById('email').addEventListener('blur', function() {
            const val = this.value.trim();
            if (val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                this.classList.add('is-error');
            } else if (val) {
                this.classList.remove('is-error');
                this.classList.add('is-valid');
            }
        });
    </script>
</body>

</html>