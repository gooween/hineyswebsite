<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/about.php
// ============================================================

session_start();
require_once '../config/db.php';
require_once '../config/auth.php';

$activePage = 'about';
$cartItems  = 0;
if (!empty($_SESSION['user_id'])) {
    $cartItems = cartCount($conn);
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
<title>About Us — Hiney's Eggs &amp; Live Chicken</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --primary:#e67e22; --primary-dark:#cf6d17; --primary-light:#fef3e8;
    --secondary:#f39c12;
    --dark:#1a1a2e; --dark2:#2c3e50;
    --text:#374151; --muted:#6b7280; --bg:#faf9f7;
    --card:#ffffff; --border:#e5e7eb;
    --success:#10b981;
    --radius:16px;
    --shadow:0 2px 8px rgba(0,0,0,0.06),0 8px 24px rgba(0,0,0,0.05);
    --shadow-lg:0 12px 40px rgba(0,0,0,0.10);
    --navbar-h:68px; --t:0.22s ease;
}
html { scroll-behavior: smooth; }
body { font-family:'Segoe UI',system-ui,sans-serif; background:var(--bg); color:var(--text); line-height:1.7; }
a { text-decoration:none; color:inherit; }


/* ── Hero ── */
.hero {
    background:linear-gradient(135deg,#1a252f 0%,#2c3e50 60%,#1a252f 100%);
    padding:80px 0 90px; position:relative; overflow:hidden;
}
.hero::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(ellipse 800px 500px at 65% 40%,rgba(230,126,34,0.15),transparent 70%);
}
.hero-deco {
    position:absolute; right:-20px; top:50%; transform:translateY(-50%);
    font-size:14rem; opacity:0.04; user-select:none; line-height:1;
}
.hero-inner { max-width:1100px; margin:0 auto; padding:0 32px; position:relative; z-index:1; }
.breadcrumb { display:flex; align-items:center; gap:6px; font-size:0.78rem; color:#6b7a99; margin-bottom:20px; }
.breadcrumb a { color:#8fa3b3; } .breadcrumb a:hover { color:var(--secondary); }
.hero-tag {
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(230,126,34,0.15); border:1px solid rgba(230,126,34,0.3);
    color:#fba96a; font-size:0.75rem; font-weight:700;
    letter-spacing:0.1em; text-transform:uppercase;
    padding:5px 14px; border-radius:20px; margin-bottom:18px;
}
.hero-title {
    font-size:clamp(2rem,4.5vw,3.2rem); font-weight:900; color:#fff;
    letter-spacing:-0.03em; line-height:1.15; margin-bottom:18px;
}
.hero-title span { color:var(--primary); }
.hero-desc {
    font-size:1.05rem; color:#8fa3b3; max-width:560px; line-height:1.75; margin-bottom:32px;
}
.hero-cta {
    display:inline-flex; align-items:center; gap:8px;
    padding:13px 28px; background:var(--primary); color:#fff;
    border-radius:10px; font-weight:700; font-size:0.95rem;
    transition:background var(--t), transform var(--t), box-shadow var(--t);
    box-shadow:0 4px 20px rgba(230,126,34,0.4);
}
.hero-cta:hover { background:var(--primary-dark); transform:translateY(-2px); box-shadow:0 8px 28px rgba(230,126,34,0.5); }

/* ── General container ── */
.container { max-width:1100px; margin:0 auto; padding:0 32px; }
@media(max-width:600px) { .container { padding:0 16px; } }

/* ── Section spacing ── */
.section { padding:72px 0; }
.section-alt { background:var(--card); }

/* ── Section heading ── */
.section-heading { text-align:center; margin-bottom:52px; }
.section-eyebrow {
    display:inline-block; font-size:0.72rem; font-weight:800;
    letter-spacing:0.14em; text-transform:uppercase;
    color:var(--primary); margin-bottom:10px;
}
.section-title {
    font-size:clamp(1.6rem,3vw,2.2rem); font-weight:900;
    color:var(--dark2); letter-spacing:-0.025em; line-height:1.2;
    margin-bottom:12px;
}
.section-sub { font-size:0.95rem; color:var(--muted); max-width:520px; margin:0 auto; line-height:1.7; }

/* ── Story section ── */
.story-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:60px; align-items:center;
}
@media(max-width:820px) { .story-grid { grid-template-columns:1fr; gap:36px; } }
.story-image-wrap {
    position:relative;
}
.story-image-placeholder {
    background:linear-gradient(135deg,#2c3e50,#1a252f);
    border-radius:20px; aspect-ratio:4/3;
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px;
    box-shadow:var(--shadow-lg); overflow:hidden; position:relative;
}
.story-image-placeholder::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(ellipse 400px 300px at 60% 40%,rgba(230,126,34,0.2),transparent 65%);
}
.story-image-icon { font-size:5rem; position:relative; z-index:1; }
.story-image-label {
    font-size:0.82rem; font-weight:700; color:rgba(255,255,255,0.5);
    letter-spacing:0.1em; text-transform:uppercase; position:relative; z-index:1;
}
.story-badge {
    position:absolute; bottom:-14px; right:24px;
    background:var(--primary); color:#fff;
    padding:10px 18px; border-radius:12px;
    font-size:0.8rem; font-weight:800; box-shadow:0 6px 20px rgba(230,126,34,0.4);
    white-space:nowrap;
}
.story-content { }
.story-eyebrow {
    font-size:0.72rem; font-weight:800; letter-spacing:0.14em;
    text-transform:uppercase; color:var(--primary); margin-bottom:12px;
}
.story-title {
    font-size:clamp(1.5rem,2.5vw,2rem); font-weight:900; color:var(--dark2);
    letter-spacing:-0.02em; line-height:1.25; margin-bottom:16px;
}
.story-text {
    font-size:0.95rem; color:var(--muted); line-height:1.8; margin-bottom:14px;
}
.story-highlight {
    display:flex; align-items:flex-start; gap:10px;
    background:var(--primary-light); border-left:3px solid var(--primary);
    border-radius:0 10px 10px 0; padding:14px 16px; margin-top:20px;
    font-size:0.88rem; color:var(--dark2); line-height:1.6; font-style:italic;
}

/* ── Stats strip ── */
.stats-strip {
    background:linear-gradient(135deg,var(--primary),var(--secondary));
    padding:48px 0;
}
.stats-grid {
    display:grid; grid-template-columns:repeat(4,1fr); gap:0;
}
@media(max-width:700px) { .stats-grid { grid-template-columns:repeat(2,1fr); } }
.stat-item {
    text-align:center; padding:20px 16px;
    border-right:1px solid rgba(255,255,255,0.2);
}
.stat-item:last-child { border-right:none; }
.stat-num {
    font-size:2.4rem; font-weight:900; color:#fff;
    letter-spacing:-0.04em; line-height:1; margin-bottom:6px;
}
.stat-label {
    font-size:0.78rem; font-weight:700; color:rgba(255,255,255,0.75);
    text-transform:uppercase; letter-spacing:0.08em;
}

/* ── Mission / Vision ── */
.mv-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:24px;
}
@media(max-width:700px) { .mv-grid { grid-template-columns:1fr; } }
.mv-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:var(--radius); padding:32px 28px;
    box-shadow:var(--shadow); position:relative; overflow:hidden;
    transition:transform var(--t), box-shadow var(--t);
}
.mv-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-lg); }
.mv-card-accent {
    position:absolute; top:0; left:0; right:0; height:4px;
}
.mv-card-accent.orange { background:linear-gradient(90deg,var(--primary),var(--secondary)); }
.mv-card-accent.green  { background:linear-gradient(90deg,#10b981,#34d399); }
.mv-icon {
    width:52px; height:52px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.5rem; margin-bottom:18px;
}
.mv-icon.orange { background:var(--primary-light); }
.mv-icon.green  { background:#ecfdf5; }
.mv-card-title { font-size:1.2rem; font-weight:800; color:var(--dark2); margin-bottom:12px; }
.mv-card-text  { font-size:0.92rem; color:var(--muted); line-height:1.8; }

/* ── Values ── */
.values-grid {
    display:grid; grid-template-columns:repeat(3,1fr); gap:20px;
}
@media(max-width:860px) { .values-grid { grid-template-columns:repeat(2,1fr); } }
@media(max-width:520px)  { .values-grid { grid-template-columns:1fr; } }
.value-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; padding:26px 22px; box-shadow:var(--shadow);
    transition:transform var(--t), box-shadow var(--t);
}
.value-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
.value-icon {
    font-size:2rem; margin-bottom:14px; display:block;
    width:52px; height:52px; background:var(--primary-light);
    border-radius:12px; display:flex; align-items:center; justify-content:center;
}
.value-title { font-size:0.95rem; font-weight:800; color:var(--dark2); margin-bottom:8px; }
.value-text  { font-size:0.84rem; color:var(--muted); line-height:1.7; }

/* ── Team / Owner ── */
.owner-card {
    background:linear-gradient(135deg,#2c3e50,#1a252f);
    border-radius:20px; padding:48px 40px;
    display:flex; align-items:center; gap:40px; flex-wrap:wrap;
    box-shadow:var(--shadow-lg); position:relative; overflow:hidden;
}
.owner-card::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(ellipse 500px 400px at 80% 50%,rgba(230,126,34,0.12),transparent 70%);
}
.owner-avatar {
    width:100px; height:100px; border-radius:50%;
    background:linear-gradient(135deg,var(--primary),var(--secondary));
    display:flex; align-items:center; justify-content:center;
    font-size:2.6rem; font-weight:900; color:#fff;
    box-shadow:0 8px 28px rgba(230,126,34,0.45);
    flex-shrink:0; position:relative; z-index:1;
    border:4px solid rgba(255,255,255,0.15);
}
.owner-info { flex:1; position:relative; z-index:1; }
.owner-tag {
    display:inline-block; font-size:0.7rem; font-weight:800;
    letter-spacing:0.12em; text-transform:uppercase;
    color:#fba96a; margin-bottom:8px;
}
.owner-name { font-size:1.7rem; font-weight:900; color:#fff; letter-spacing:-0.02em; margin-bottom:6px; }
.owner-role { font-size:0.88rem; color:#8fa3b3; margin-bottom:14px; }
.owner-quote {
    font-size:1rem; color:rgba(255,255,255,0.8); line-height:1.7;
    font-style:italic; border-left:3px solid var(--primary); padding-left:16px;
}

/* ── Contact info ── */
.contact-grid {
    display:grid; grid-template-columns:repeat(3,1fr); gap:20px;
}
@media(max-width:700px) { .contact-grid { grid-template-columns:1fr; } }
.contact-card {
    background:var(--card); border:1px solid var(--border);
    border-radius:14px; padding:28px 24px; box-shadow:var(--shadow);
    text-align:center; transition:transform var(--t), box-shadow var(--t);
}
.contact-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-lg); }
.contact-icon {
    width:52px; height:52px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.4rem; margin:0 auto 14px;
}
.ci-orange { background:var(--primary-light); }
.ci-blue   { background:#eff6ff; }
.ci-green  { background:#ecfdf5; }
.contact-label { font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.1em; color:var(--muted); margin-bottom:6px; }
.contact-value { font-size:0.95rem; font-weight:700; color:var(--dark2); line-height:1.5; }

/* ── CTA banner ── */
.cta-banner {
    background:linear-gradient(135deg,var(--primary),var(--secondary));
    border-radius:20px; padding:52px 40px; text-align:center;
    margin:72px 0; position:relative; overflow:hidden;
    box-shadow:0 12px 40px rgba(230,126,34,0.35);
}
.cta-banner::before {
    content:''; position:absolute; inset:0;
    background:radial-gradient(ellipse 500px 300px at 50% 50%,rgba(255,255,255,0.08),transparent 65%);
}
.cta-banner-title {
    font-size:clamp(1.5rem,3vw,2.2rem); font-weight:900; color:#fff;
    letter-spacing:-0.02em; margin-bottom:12px; position:relative; z-index:1;
}
.cta-banner-sub {
    font-size:1rem; color:rgba(255,255,255,0.82); margin-bottom:28px;
    position:relative; z-index:1; line-height:1.6;
}
.cta-btn-group { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; position:relative; z-index:1; }
.btn-white {
    display:inline-flex; align-items:center; gap:8px;
    padding:13px 28px; background:#fff; color:var(--primary);
    border-radius:10px; font-weight:800; font-size:0.95rem;
    transition:transform var(--t), box-shadow var(--t);
    box-shadow:0 4px 16px rgba(0,0,0,0.12);
}
.btn-white:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,0,0,0.18); }
.btn-outline-white {
    display:inline-flex; align-items:center; gap:8px;
    padding:13px 28px; background:transparent;
    border:2px solid rgba(255,255,255,0.6); color:#fff;
    border-radius:10px; font-weight:700; font-size:0.95rem;
    transition:all var(--t);
}
.btn-outline-white:hover { background:rgba(255,255,255,0.15); border-color:#fff; }

/* ── Footer ── */
.site-footer { background:#1a1a2e; color:#6b7280; text-align:center; padding:28px 32px; font-size:0.82rem; line-height:1.8; }
.site-footer a { color:var(--primary); }
</style>
</head>
<body>
<div class="page-body">

<?php include '../includes/navbar.php'; ?>

<!-- ── Hero ── -->
<div class="hero">
    <div class="hero-deco"><i class="fa-solid fa-egg"></i></div>
    <div class="hero-inner">
        <div class="breadcrumb">
            <a href="home.php">Home</a>
            <span>›</span>
            <span>About Us</span>
        </div>
        <div class="hero-tag">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Family-Owned Since 2010
        </div>
        <h1 class="hero-title">
            Straight from the Farm,<br>
            <span>Straight to Your Table</span>
        </h1>
        <p class="hero-desc">
            Hiney's Eggs and Live Chicken Business has been serving Loreto, Cortes 
            with the freshest farm-raised eggs and live chickens for over a decade.
            We believe in quality, honesty, and the goodness of nature.
        </p>
        <a href="products.php" class="hero-cta">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Shop Our Products
        </a>
    </div>
</div>

<!-- ── Stats Strip ── -->
<div class="stats-strip">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-num">14+</div>
                <div class="stat-label">Years in Business</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">500+</div>
                <div class="stat-label">Happy Customers</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">1,000+</div>
                <div class="stat-label">Eggs Sold Weekly</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">100%</div>
                <div class="stat-label">Farm Fresh</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Our Story ── -->
<section class="section">
    <div class="container">
        <div class="story-grid">
            <div class="story-image-wrap">
                <div class="story-image-placeholder">
                    <div class="story-image-icon"><i class="fa-solid fa-drumstick-bite"></i></div>
                    <div class="story-image-label">Hiney's Farm</div>
                </div>
                <div class="story-badge"><i class="fa-solid fa-egg"></i> Est. 2010 · Loreto, Cortes</div>
            </div>
            <div class="story-content">
                <div class="story-eyebrow">Our Story</div>
                <h2 class="story-title">A Small Farm That Grew Into a Trusted Name</h2>
                <p class="story-text">
                    What started as a small backyard flock in 2010 has grown into one of Loreto, Cortes 
                    most trusted sources for fresh eggs and live chickens. Hiney's was founded with a simple
                    belief — that families deserve to know exactly where their food comes from.
                </p>
                <p class="story-text">
                    Over the years, we've expanded our flock, improved our facilities, and built lasting
                    relationships with hundreds of households, carinderias, and small businesses across Palawan.
                    Every egg we sell is collected fresh daily. Every chicken is raised with proper care,
                    feed, and space to move freely.
                </p>
                <div class="story-highlight">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:2px;"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 2v6c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 2v6c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/></svg>
                    "We don't just sell eggs — we share a piece of our farm, our values, and our family's
                    dedication to quality with every order."
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Mission & Vision ── -->
<section class="section section-alt">
    <div class="container">
        <div class="section-heading">
            <div class="section-eyebrow">What Drives Us</div>
            <h2 class="section-title">Our Mission &amp; Vision</h2>
            <p class="section-sub">Every decision we make is guided by a commitment to quality, community, and sustainable farming.</p>
        </div>
        <div class="mv-grid">
            <div class="mv-card">
                <div class="mv-card-accent orange"></div>
                <div class="mv-icon orange"><i class="fa-solid fa-bullseye"></i></div>
                <div class="mv-card-title">Our Mission</div>
                <p class="mv-card-text">
                    To provide Loreto Cortes and surrounding areas with the freshest, most
                    affordable, and responsibly raised eggs and live chickens — delivered with honesty,
                    consistency, and a personal touch that only a family farm can offer.
                </p>
            </div>
            <div class="mv-card">
                <div class="mv-card-accent green"></div>
                <div class="mv-icon green"><i class="fa-solid fa-seedling"></i></div>
                <div class="mv-card-title">Our Vision</div>
                <p class="mv-card-text">
                    To become Palawan's leading farm-to-table poultry brand — growing our flock,
                    expanding our reach, and continuing to set the standard for freshness and quality
                    while giving back to the local farming community.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ── Our Values ── -->
<section class="section">
    <div class="container">
        <div class="section-heading">
            <div class="section-eyebrow">What We Stand For</div>
            <h2 class="section-title">Our Core Values</h2>
            <p class="section-sub">These are the principles we live by — on and off the farm.</p>
        </div>
        <div class="values-grid">
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-egg"></i></div>
                <div class="value-title">Freshness First</div>
                <p class="value-text">Eggs are collected and sorted daily. We never hold stock for more than 48 hours before delivery. What you get is what was laid this morning.</p>
            </div>
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-handshake"></i></div>
                <div class="value-title">Honest Dealings</div>
                <p class="value-text">No hidden charges, no misleading labels. Our prices are straightforward, our weights are accurate, and our word is our bond.</p>
            </div>
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-leaf"></i></div>
                <div class="value-title">Responsible Farming</div>
                <p class="value-text">Our chickens are raised in clean, spacious environments with proper nutrition and veterinary care — because healthy animals mean healthy food.</p>
            </div>
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-heart"></i></div>
                <div class="value-title">Community Focus</div>
                <p class="value-text">We prioritize local buyers, support neighboring farms, and keep our pricing fair so that everyone — from households to small businesses — can afford quality.</p>
            </div>
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-box"></i></div>
                <div class="value-title">Reliable Service</div>
                <p class="value-text">When you place an order, it gets fulfilled. We communicate clearly, deliver on time, and follow up to make sure you're satisfied.</p>
            </div>
            <div class="value-card">
                <div class="value-icon"><i class="fa-solid fa-wheat-awn"></i></div>
                <div class="value-title">Sustainable Growth</div>
                <p class="value-text">We grow at a pace that lets us maintain quality. Expanding too fast at the cost of freshness or care is something we will never do.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── Owner / About ── -->
<section class="section section-alt">
    <div class="container">
        <div class="section-heading">
            <div class="section-eyebrow">The Face Behind the Farm</div>
            <h2 class="section-title">Meet the Owner</h2>
        </div>
        <div class="owner-card">
            <div class="owner-avatar">H</div>
            <div class="owner-info">
                <div class="owner-tag">Founder &amp; Owner</div>
                <div class="owner-name">Hiney</div>
                <div class="owner-role">Loreto, Cortes · Founded 2010</div>
                <div class="owner-quote">
                    "I started this farm because I wanted my neighbors to have access to eggs and
                    chicken they could trust. Fourteen years later, that same spirit still drives
                    everything we do here at Hiney's."
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Contact Info ── -->
<section class="section">
    <div class="container">
        <div class="section-heading">
            <div class="section-eyebrow">Get in Touch</div>
            <h2 class="section-title">Find Us or Reach Out</h2>
            <p class="section-sub">We'd love to hear from you — whether it's a bulk order, a question, or just saying hello.</p>
        </div>
        <div class="contact-grid">
            <div class="contact-card">
                <div class="contact-icon ci-orange"><i class="fa-solid fa-location-dot"></i></div>
                <div class="contact-label">Location</div>
                <div class="contact-value">
    Brgy. Loreto, Cortes<br>
    Bohol, Philippines
</div>
            </div>
            <div class="contact-card">
                <div class="contact-icon ci-blue"><i class="fa-solid fa-phone"></i></div>
                <div class="contact-label">Phone / GCash</div>
                <div class="contact-value">0917-XXX-XXXX<br><span style="font-size:0.78rem;color:var(--muted);">Mon–Sat, 7am–6pm</span></div>
            </div>
            <div class="contact-card">
                <div class="contact-icon ci-green"><i class="fa-solid fa-envelope"></i></div>
                <div class="contact-label">Email</div>
                <div class="contact-value">hineys@email.com<br><span style="font-size:0.78rem;color:var(--muted);">We reply within 24 hours</span></div>
            </div>
        </div>
    </div>
</section>

<!-- ── CTA Banner ── -->
<div class="container">
    <div class="cta-banner">
        <h2 class="cta-banner-title">Ready to Order Farm-Fresh Today?</h2>
        <p class="cta-banner-sub">Browse our eggs and live chickens, place your order online, and we'll take care of the rest.</p>
        <div class="cta-btn-group">
            <a href="products.php" class="btn-white">
                <i class="fa-solid fa-egg"></i> Browse Products
            </a>
            <a href="contact.php" class="btn-outline-white">
                <i class="fa-solid fa-comment"></i> Contact Us
            </a>
        </div>
    </div>
</div>

<footer class="site-footer">
    &copy; <?= date('Y') ?> Hiney's Eggs &amp; Live Chicken Business &nbsp;·&nbsp;
    Loreto, Cortes , Bohol  &nbsp;·&nbsp;
    <a href="contact.php">Contact Us</a>
</footer>

</div>
</body>
</html>