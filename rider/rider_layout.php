<?php
// ============================================================
// rider/rider_layout.php — mobile "app" shell shared by the rider pages
// ============================================================
function riderHead(string $title, string $active, array $rider): void
{
    $initial = strtoupper(mb_substr($rider['full_name'], 0, 1));
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title><?= htmlspecialchars($title) ?> — HATCH Rider</title>
        <meta name="theme-color" content="#e8761e">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="HATCH Rider">
        <link rel="manifest" href="manifest.php">
        <link rel="icon" type="image/png" href="../assets/images/hineys_logo.png">
        <link rel="apple-touch-icon" href="../assets/images/hineys_logo.png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            :root {
                --brand: #e8761e;
                --brand-dark: #c85f0f;
                --brand-soft: #fff3e6;
                --ink: #1f2937;
                --muted: #6b7280;
                --line: #e5e7eb;
                --bg: #f7f5f2;
                --ok: #15803d;
                --ok-soft: #dcfce7;
                --warn-soft: #fef3c7;
                --danger: #b91c1c;
                --danger-soft: #fee2e2;
                --blue-soft: #dbeafe;
            }

            * {
                box-sizing: border-box;
                -webkit-tap-highlight-color: transparent;
            }

            html {
                -webkit-text-size-adjust: 100%;
            }

            body {
                margin: 0;
                background: var(--bg);
                color: var(--ink);
                font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
                font-size: 15px;
                line-height: 1.4;
                padding-bottom: calc(76px + env(safe-area-inset-bottom));
            }

            a {
                color: inherit;
            }

            .app {
                max-width: 560px;
                margin: 0 auto;
                padding: 14px 14px 20px;
            }

            .topbar {
                position: sticky;
                top: 0;
                z-index: 20;
                background: #fff;
                border-bottom: 1px solid var(--line);
                padding: calc(10px + env(safe-area-inset-top)) 14px 10px;
            }

            .topbar-in {
                max-width: 560px;
                margin: 0 auto;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .topbar img {
                width: 34px;
                height: 34px;
                object-fit: contain;
            }

            .topbar .t1 {
                font-weight: 800;
                font-size: 1rem;
                line-height: 1.1;
            }

            .topbar .t2 {
                font-size: 0.72rem;
                color: var(--muted);
            }

            .topbar .avatar {
                margin-left: auto;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: var(--brand-soft);
                color: var(--brand-dark);
                font-weight: 800;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            h1.page {
                font-size: 1.25rem;
                margin: 4px 0 12px;
            }

            .card {
                background: #fff;
                border: 1px solid var(--line);
                border-radius: 16px;
                padding: 14px;
                margin-bottom: 12px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
            }

            .row {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .between {
                justify-content: space-between;
            }

            .muted {
                color: var(--muted);
            }

            .small {
                font-size: 0.8rem;
            }

            .chip {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 3px 10px;
                border-radius: 999px;
                font-size: 0.75rem;
                font-weight: 700;
                white-space: nowrap;
            }

            .chip.ok {
                background: var(--ok-soft);
                color: var(--ok);
            }

            .chip.warn {
                background: var(--warn-soft);
                color: #92400e;
            }

            .chip.blue {
                background: var(--blue-soft);
                color: #1e40af;
            }

            .chip.off {
                background: #e5e7eb;
                color: #374151;
            }

            .chip.red {
                background: var(--danger-soft);
                color: var(--danger);
            }

            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                min-height: 48px;
                padding: 0 18px;
                border-radius: 12px;
                border: 1.5px solid transparent;
                font-size: 1rem;
                font-weight: 700;
                font-family: inherit;
                cursor: pointer;
                text-decoration: none;
                width: 100%;
            }

            .btn.primary {
                background: var(--brand);
                color: #fff;
            }

            .btn.primary:active {
                background: var(--brand-dark);
            }

            .btn.ok {
                background: var(--ok);
                color: #fff;
            }

            .btn.ghost {
                background: #fff;
                border-color: var(--line);
                color: var(--ink);
            }

            .btn.sm {
                min-height: 40px;
                font-size: 0.9rem;
                width: auto;
                padding: 0 14px;
            }

            .btn:disabled {
                opacity: .55;
                cursor: not-allowed;
            }

            .grid2 {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat {
                text-align: center;
                padding: 12px 6px;
            }

            .stat .n {
                font-size: 1.6rem;
                font-weight: 800;
                line-height: 1.1;
            }

            .stat .l {
                font-size: 0.72rem;
                color: var(--muted);
                text-transform: uppercase;
                letter-spacing: .04em;
                font-weight: 700;
            }

            label.f {
                display: block;
                font-size: 0.8rem;
                font-weight: 700;
                margin: 10px 0 4px;
            }

            input.in,
            select.in,
            textarea.in {
                width: 100%;
                padding: 12px;
                border: 1.5px solid var(--line);
                border-radius: 10px;
                font-size: 1rem;
                font-family: inherit;
                background: #fff;
            }

            input.in:focus,
            select.in:focus,
            textarea.in:focus {
                outline: none;
                border-color: var(--brand);
                box-shadow: 0 0 0 3px rgba(232, 118, 30, .15);
            }

            .flash-wrap>div {
                margin: 0 0 12px !important;
            }

            nav.bottom {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 30;
                background: #fff;
                border-top: 1px solid var(--line);
                padding-bottom: env(safe-area-inset-bottom);
            }

            nav.bottom .in {
                max-width: 560px;
                margin: 0 auto;
                display: grid;
                grid-template-columns: repeat(3, 1fr);
            }

            nav.bottom a {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 3px;
                padding: 9px 0 8px;
                font-size: 0.72rem;
                font-weight: 700;
                color: var(--muted);
                text-decoration: none;
            }

            nav.bottom a i {
                font-size: 1.2rem;
            }

            nav.bottom a.active {
                color: var(--brand);
            }

            .empty {
                text-align: center;
                color: var(--muted);
                padding: 36px 16px;
            }

            .empty i {
                font-size: 2.2rem;
                color: #d1d5db;
                margin-bottom: 8px;
            }

            .sheet-bg {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, .45);
                z-index: 50;
                display: none;
                align-items: flex-end;
                justify-content: center;
            }

            .sheet-bg.open {
                display: flex;
            }

            .sheet {
                background: #fff;
                width: 100%;
                max-width: 560px;
                border-radius: 20px 20px 0 0;
                padding: 16px 16px calc(18px + env(safe-area-inset-bottom));
                max-height: 92vh;
                overflow-y: auto;
            }
        </style>
    </head>

    <body>
        <header class="topbar">
            <div class="topbar-in">
                <img src="../assets/images/hineys_logo.png" alt="" onerror="this.style.display='none'">
                <div>
                    <div class="t1">HATCH Rider</div>
                    <div class="t2"><?= htmlspecialchars($rider['full_name']) ?></div>
                </div>
                <div class="avatar"><?= htmlspecialchars($initial) ?></div>
            </div>
        </header>
        <main class="app">
            <div class="flash-wrap"><?= flash() ?></div>
        <?php
    }

    function riderFoot(string $active): void
    {
        $tabs = [
            'deliveries' => ['index.php', 'fa-motorcycle', 'Deliveries'],
            'stats'      => ['stats.php', 'fa-chart-simple', 'My Stats'],
            'profile'    => ['profile.php', 'fa-user', 'Profile'],
        ];
        ?>
        </main>
        <nav class="bottom">
            <div class="in">
                <?php foreach ($tabs as $key => [$href, $icon, $label]): ?>
                    <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><i class="fa-solid <?= $icon ?>"></i><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </nav>
    </body>

    </html>
<?php
    }
