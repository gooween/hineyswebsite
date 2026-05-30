<?php
// No-cache headers — must come first
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
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

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php?msg=loggedout');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, password, role, is_active
             FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'No account found with that email address.';
        } elseif (!$user['is_active']) {
            $error = 'Your account has been deactivated. Please contact support.';
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Incorrect password. Please try again.';
        } else {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email']     = $user['email'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['is_active'] = $user['is_active'];

            if ($user['role'] === 'admin') {
                redirect('admin/dashboard.php', 'success', 'Welcome back, ' . $user['full_name'] . '!');
            } else {
                redirect('user/home.php', 'success', 'Welcome back, ' . explode(' ', $user['full_name'])[0] . '!');
            }
        }
    }
}

$infoMsg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'loggedout')   $infoMsg = "You've been logged out successfully.";
    if ($_GET['msg'] === 'registered')  $infoMsg = 'Account created! Please sign in.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style id="hineys-icon-colors">
/* === Hiney's icon colors === */
/* Icons inside dark/colored or interactive areas keep their inherited color */
.navbar .fa-solid, .mobile-drawer .fa-solid, .sidebar .fa-solid,
button .fa-solid, [class*="btn"] .fa-solid, .badge .fa-solid,
.status-badge .fa-solid, .status-tab .fa-solid, .pay-badge .fa-solid,
.page-banner .fa-solid, .page-header .fa-solid, .hero .fa-solid,
.cta-card .fa-solid, .about-strip .fa-solid, .nav-cart .fa-solid,
.user-chip .fa-solid, .info-card-top .fa-solid, .sidebar-logout .fa-solid {
    color: inherit !important;
}
/* Semantic colors for standalone content icons */
.fa-egg { color: #f4a72c; }
.fa-drumstick-bite { color: #c2703b; }
.fa-circle-check, .fa-check, .fa-shield-halved,
.fa-leaf, .fa-seedling, .fa-phone { color: #10b981; }
.fa-circle-xmark, .fa-xmark, .fa-trash, .fa-ban,
.fa-location-dot { color: #ef4444; }
.fa-cart-shopping, .fa-bag-shopping, .fa-store, .fa-shop { color: #e67e22; }
.fa-truck { color: #f97316; }
.fa-triangle-exclamation, .fa-circle-exclamation,
.fa-clock, .fa-star { color: #f59e0b; }
.fa-info-circle, .fa-credit-card, .fa-mobile-screen,
.fa-envelope, .fa-envelope-open, .fa-envelope-open-text,
.fa-inbox, .fa-comment, .fa-map, .fa-paperclip { color: #3b82f6; }
.fa-sack-dollar, .fa-money-bill, .fa-money-bill-transfer { color: #16a34a; }
.fa-users, .fa-user, .fa-user-plus { color: #6366f1; }
.fa-box, .fa-box-open, .fa-boxes-stacked, .fa-warehouse,
.fa-receipt, .fa-clipboard-list, .fa-file-lines { color: #8b5cf6; }
.fa-chart-bar, .fa-chart-line, .fa-chart-pie,
.fa-gauge-high { color: #0ea5e9; }
.fa-heart { color: #ef4444; }
.fa-gear { color: #6b7280; }
.fa-lightbulb { color: #f59e0b; }
</style>
    <title>Sign In — Hiney's Eggs &amp; Live Chicken</title>
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

        /* ── Layout ── */
        .page {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 480px;
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
                radial-gradient(ellipse 80% 60% at 20% 80%, rgba(217, 119, 6, 0.18) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 20%, rgba(245, 158, 11, 0.12) 0%, transparent 55%),
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='30' cy='30' r='1' fill='%23ffffff08'/%3E%3C/svg%3E");
        }

        .panel-top {
            position: relative;
            z-index: 1;
        }

        .panel-bottom {
            position: relative;
            z-index: 1;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 72px;
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
            letter-spacing: 0.01em;
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
            font-size: clamp(2.2rem, 3.5vw, 3.4rem);
            color: var(--white);
            line-height: 1.1;
            letter-spacing: -0.02em;
            margin-bottom: 20px;
        }

        .panel-headline em {
            font-style: italic;
            color: var(--amber-lt);
        }

        .panel-sub {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.5);
            line-height: 1.75;
            max-width: 380px;
            margin-bottom: 48px;
        }

        .panel-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pill {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 100px;
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.65);
            font-weight: 500;
            letter-spacing: 0.01em;
        }

        .pill-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--amber-lt);
            flex-shrink: 0;
        }

        .panel-footer-text {
            font-size: 0.72rem;
            color: rgba(255, 255, 255, 0.2);
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        /* ── Right Form Side ── */
        .form-side {
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 52px;
            box-shadow: -8px 0 48px rgba(0, 0, 0, 0.08);
        }

        .form-wrap {
            width: 100%;
            max-width: 340px;
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
            font-size: 2rem;
            color: var(--brown);
            letter-spacing: -0.02em;
            margin-bottom: 6px;
            line-height: 1.15;
        }

        .form-desc {
            font-size: 0.87rem;
            color: var(--text-muted);
            margin-bottom: 32px;
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

        /* Alert */
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.83rem;
            font-weight: 500;
            margin-bottom: 22px;
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

        .alert-success {
            background: #f0fdf4;
            color: var(--success);
            border: 1px solid #bbf7d0;
        }

        .alert-info {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        /* Form fields */
        .form-group {
            margin-bottom: 18px;
        }

        .label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--brown-mid);
            margin-bottom: 7px;
            letter-spacing: 0.02em;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--brown-lt);
            pointer-events: none;
            display: flex;
            align-items: center;
        }

        .input-icon svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
        }

        .input {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 0.9rem;
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
        }

        .input::placeholder {
            color: #c4b5a5;
        }

        .pw-toggle {
            position: absolute;
            right: 13px;
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
            width: 17px;
            height: 17px;
            stroke: currentColor;
            fill: none;
        }

        /* Meta row */
        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.82rem;
            color: var(--text-muted);
            cursor: pointer;
            user-select: none;
        }

        .remember input[type="checkbox"] {
            width: 15px;
            height: 15px;
            accent-color: var(--amber);
            cursor: pointer;
        }

        .forgot-link {
            font-size: 0.82rem;
            color: var(--amber);
            font-weight: 600;
            text-decoration: none;
        }

        .forgot-link:hover {
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

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 24px 0;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .divider span {
            font-size: 0.75rem;
            color: #c4b5a5;
            font-weight: 500;
            letter-spacing: 0.04em;
        }

        .register-link {
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .register-link a {
            color: var(--amber);
            font-weight: 700;
            text-decoration: none;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .footer-note {
            text-align: center;
            font-size: 0.7rem;
            color: #c4b5a5;
            margin-top: 28px;
            line-height: 1.7;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .page {
                grid-template-columns: 1fr;
            }

            .panel {
                display: none;
            }

            .form-side {
                padding: 40px 28px;
                box-shadow: none;
            }
        }

        @media (max-width: 480px) {
            .form-side {
                padding: 32px 20px;
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
                    Farm fresh,<br>
                    <em>delivered</em><br>
                    to your door.
                </h1>

                <p class="panel-sub">
                    Premium quality eggs and live chickens raised with care —
                    straight from our family farm in Loreto Cortes, Bohol.
                </p>

                <div class="panel-pills">
                    <div class="pill">
                        <div class="pill-dot"></div> Farm Fresh Daily
                    </div>
                    <div class="pill">
                        <div class="pill-dot"></div> Fast Local Delivery
                    </div>
                    <div class="pill">
                        <div class="pill-dot"></div> No Preservatives
                    </div>
                    <div class="pill">
                        <div class="pill-dot"></div> Quality Guaranteed
                    </div>
                </div>
            </div>

            <div class="panel-bottom">
                <div class="panel-footer-text">Loreto Cortes, Bohol &nbsp;</div>
            </div>
        </div>

        <!-- Right Form Side -->
        <div class="form-side">
            <div class="form-wrap">

                <div class="form-eyebrow">Welcome back</div>
                <h2 class="form-title">Sign in to<br>your account</h2>
                <p class="form-desc">
                    Don't have an account?
                    <a href="register.php">Create one free →</a>
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="8" x2="12" y2="12" />
                            <line x1="12" y1="16" x2="12.01" y2="16" />
                        </svg>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($infoMsg): ?>
                    <div class="alert alert-info">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" />
                            <line x1="12" y1="16" x2="12" y2="12" />
                            <line x1="12" y1="8" x2="12.01" y2="8" />
                        </svg>
                        <?= htmlspecialchars($infoMsg) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="index.php" id="loginForm" onsubmit="handleSubmit(event)">

                    <div class="form-group">
                        <label class="label" for="email">Email address</label>
                        <div class="input-wrap">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24" stroke-width="1.8">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                                    <polyline points="22,6 12,13 2,6" />
                                </svg>
                            </span>
                            <input type="email" id="email" name="email"
                                class="input <?= $error ? 'is-error' : '' ?>"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                required autocomplete="email">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="label" for="password">Password</label>
                        <div class="input-wrap">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24" stroke-width="1.8">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                </svg>
                            </span>
                            <input type="password" id="password" name="password"
                                class="input <?= $error ? 'is-error' : '' ?>"
                                placeholder="Your password"
                                required autocomplete="current-password">
                            <button type="button" class="pw-toggle" onclick="togglePw()" id="pwToggle" title="Show/hide password">
                                <svg id="eyeIcon" viewBox="0 0 24 24" stroke-width="1.8">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="meta-row">
                        <label class="remember">
                            <input type="checkbox" name="remember" id="remember">
                            Remember me
                        </label>
                    </div>

                    <button type="submit" class="btn-submit" id="loginBtn">
                        <span id="btnText">Sign In</span>
                        <span class="spinner" id="btnSpinner"></span>
                    </button>

                </form>

                <div class="divider"><span>or</span></div>

                <div class="register-link">
                    New customer?
                    <a href="register.php">Create a free account →</a>
                </div>

                <div class="footer-note">
                    &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business<br>
                    Loreto Cortes, Bohol
                </div>

            </div>
        </div>

    </div>

    <script>
        function togglePw() {
            const pw = document.getElementById('password');
            const btn = document.getElementById('pwToggle');
            const eyeOpen = `<svg id="eyeIcon" viewBox="0 0 24 24" stroke-width="1.8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
            const eyeClosed = `<svg id="eyeIcon" viewBox="0 0 24 24" stroke-width="1.8" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;

            if (pw.type === 'password') {
                pw.type = 'text';
                btn.innerHTML = eyeClosed;
            } else {
                pw.type = 'password';
                btn.innerHTML = eyeOpen;
            }
        }

        function handleSubmit(e) {
            const btn = document.getElementById('loginBtn');
            const text = document.getElementById('btnText');
            const spinner = document.getElementById('btnSpinner');
            btn.disabled = true;
            text.textContent = 'Signing in…';
            spinner.style.display = 'block';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const email = document.getElementById('email');
            if (email && !email.value) email.focus();
        });
    </script>
</body>

</html>