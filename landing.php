<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>EscrowPay — Secure Transactions Guaranteed</title>
<meta name="description" content="EscrowPay safely holds buyer funds until product delivery is verified and confirmed. Trusted by Somali e-commerce buyers and sellers.">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
<link rel="icon" type="image/png" href="assets/logo/image.png">
<link rel="shortcut icon" type="image/png" href="assets/logo/image.png">
<link rel="apple-touch-icon" href="assets/logo/image.png">

<style>
/* ── RESET & BASE ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --primary:       #00236f;
  --primary-dark:  #001755;
  --primary-mid:   #1e3a8a;
  --secondary:     #006c49;
  --secondary-lt:  #10b981;
  --on-primary:    #ffffff;
  --surface:       #f8f9ff;
  --surface-low:   #eff4ff;
  --surface-high:  #dce9ff;
  --on-surface:    #0b1c30;
  --on-surface-v:  #444651;
  --outline:       #c5c5d3;
  --container-max: 1280px;
  --radius:        0.5rem;
}

html { scroll-behavior: smooth; }

body {
  font-family: 'Inter', sans-serif;
  background: var(--surface);
  color: var(--on-surface);
  min-height: 100vh;
}

/* ── UTILITY ── */
.container {
  max-width: var(--container-max);
  margin: 0 auto;
  padding: 0 40px;
}
@media (max-width: 768px) { .container { padding: 0 16px; } }

/* ── NAV ── */
nav {
  position: sticky;
  top: 0;
  z-index: 100;
  background: rgba(248, 249, 255, 0.85);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid rgba(197, 197, 211, 0.3);
}
.nav-inner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  height: 68px;
  gap: 24px;
}
.logo {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
}
.logo-icon {
  width: 38px;
  height: 38px;
  background: var(--primary-mid);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 20px;
  flex-shrink: 0;
}
.logo-text {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 20px;
  font-weight: 700;
  color: var(--primary);
}
.nav-links {
  display: flex;
  align-items: center;
  gap: 28px;
  list-style: none;
}
.nav-links a {
  font-size: 14px;
  font-weight: 600;
  color: var(--on-surface-v);
  text-decoration: none;
  transition: color .2s;
  position: relative;
}
.nav-links a:hover, .nav-links a.active {
  color: var(--primary);
}
.nav-links a.active::after {
  content: '';
  position: absolute;
  bottom: -4px;
  left: 0;
  right: 0;
  height: 2px;
  background: var(--primary);
  border-radius: 2px;
}
.nav-ctas {
  display: flex;
  align-items: center;
  gap: 12px;
}
.btn-outline-nav {
  font-size: 14px;
  font-weight: 600;
  color: var(--primary);
  background: transparent;
  border: 1.5px solid var(--primary-mid);
  padding: 8px 20px;
  border-radius: 8px;
  cursor: pointer;
  text-decoration: none;
  transition: all .2s;
}
.btn-outline-nav:hover { background: var(--surface-low); }

.btn-primary-nav {
  font-size: 14px;
  font-weight: 600;
  color: #fff;
  background: var(--secondary);
  border: none;
  padding: 8px 20px;
  border-radius: 8px;
  cursor: pointer;
  text-decoration: none;
  box-shadow: 0 4px 12px rgba(0,108,73,.2);
  transition: all .2s;
}
.btn-primary-nav:hover { background: #005236; box-shadow: 0 6px 16px rgba(0,108,73,.3); transform: translateY(-1px); }

.hamburger {
  display: none;
  background: none;
  border: none;
  color: var(--primary);
  font-size: 26px;
  cursor: pointer;
}

/* Mobile nav */
.mobile-nav {
  display: none;
  flex-direction: column;
  gap: 8px;
  padding: 16px;
  border-top: 1px solid var(--outline);
  background: var(--surface);
}
.mobile-nav a {
  font-size: 15px;
  font-weight: 500;
  color: var(--on-surface-v);
  text-decoration: none;
  padding: 10px 0;
  border-bottom: 1px solid var(--surface-low);
  display: block;
}
.mobile-nav .mobile-btns { display: flex; gap: 10px; margin-top: 8px; }
.mobile-nav .mobile-btns a { border: none; padding: 0; flex: 1; text-align: center; }

@media (max-width: 768px) {
  .nav-links, .nav-ctas { display: none; }
  .hamburger { display: block; }
}

/* ── HERO ── */
.hero {
  padding: 96px 0 80px;
}
.hero-inner {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 64px;
  align-items: center;
}
.hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(0,35,111,.07);
  color: var(--primary);
  font-size: 12px;
  font-weight: 600;
  padding: 5px 12px;
  border-radius: 20px;
  letter-spacing: .05em;
  text-transform: uppercase;
  margin-bottom: 20px;
}
.hero h1 {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 52px;
  font-weight: 700;
  line-height: 1.1;
  letter-spacing: -.02em;
  color: var(--primary);
  margin-bottom: 20px;
}
.hero p {
  font-size: 18px;
  color: var(--on-surface-v);
  line-height: 1.7;
  max-width: 480px;
  margin-bottom: 36px;
}
.hero-btns {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
}
.btn-hero-primary {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--secondary);
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  padding: 14px 26px;
  border-radius: 10px;
  text-decoration: none;
  box-shadow: 0 4px 14px rgba(0,108,73,.25);
  transition: all .25s;
}
.btn-hero-primary:hover { background: #005236; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,108,73,.3); }

.btn-hero-secondary {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: transparent;
  color: var(--primary);
  font-size: 14px;
  font-weight: 600;
  padding: 14px 26px;
  border-radius: 10px;
  text-decoration: none;
  border: 1.5px solid var(--outline);
  transition: all .25s;
}
.btn-hero-secondary:hover { background: var(--surface-low); border-color: var(--primary); transform: translateY(-2px); }

.hero-visual {
  position: relative;
  border-radius: 20px;
  overflow: hidden;
  box-shadow: 0 24px 64px rgba(30,58,138,.15);
  height: 460px;
}
.hero-visual img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
/* Overlay card on hero */
.hero-stat-card {
  position: absolute;
  background: #fff;
  border-radius: 14px;
  padding: 14px 20px;
  box-shadow: 0 8px 24px rgba(30,58,138,.12);
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13px;
  color: var(--on-surface);
  animation: floatCard 4s ease-in-out infinite;
}
.hero-stat-card:nth-child(2) { bottom: 28px; left: 24px; animation-delay: 0s; }
.hero-stat-card:nth-child(3) { top: 28px; right: 24px; animation-delay: 2s; }
.stat-icon-circle {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
}
.stat-icon-green { background: #d1fae5; color: var(--secondary); }
.stat-icon-blue  { background: #dce9ff; color: var(--primary); }
.stat-value { font-weight: 700; font-size: 16px; color: var(--primary); }
.stat-sub   { font-size: 11px; color: var(--on-surface-v); }

@keyframes floatCard {
  0%,100% { transform: translateY(0); }
  50%      { transform: translateY(-8px); }
}

@media (max-width: 900px) {
  .hero { padding: 56px 0; }
  .hero-inner { grid-template-columns: 1fr; gap: 40px; }
  .hero h1 { font-size: 36px; }
  .hero-visual { height: 280px; }
}

/* ── TRUST STRIP ── */
.trust-strip {
  background: var(--surface-low);
  padding: 36px 0;
  border-top: 1px solid var(--outline);
  border-bottom: 1px solid var(--outline);
}
.trust-inner {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 48px;
  flex-wrap: wrap;
}
.trust-label {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--on-surface-v);
  width: 100%;
  text-align: center;
  margin-bottom: 16px;
}
.trust-logo {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 22px;
  font-weight: 700;
  color: var(--primary);
  opacity: .65;
  transition: opacity .2s;
}
.trust-logo:hover { opacity: 1; }
.trust-endorsed {
  font-size: 13px;
  color: var(--on-surface-v);
  width: 100%;
  text-align: center;
  margin-top: 20px;
  padding-top: 20px;
  border-top: 1px solid var(--outline);
  max-width: 440px;
  margin-left: auto;
  margin-right: auto;
}

/* ── FEATURES ── */
.section { padding: 96px 0; }
.section-header {
  text-align: center;
  max-width: 560px;
  margin: 0 auto 56px;
}
.section-overline {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--secondary);
  margin-bottom: 12px;
}
.section-title {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 36px;
  font-weight: 700;
  color: var(--primary);
  line-height: 1.2;
  margin-bottom: 14px;
}
.section-sub {
  font-size: 16px;
  color: var(--on-surface-v);
  line-height: 1.65;
}

/* Features Bento Grid */
.features-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  grid-template-rows: auto auto;
  gap: 20px;
}
.feat-card {
  background: #fff;
  border-radius: 16px;
  padding: 36px;
  border: 1px solid var(--outline);
  box-shadow: 0 4px 14px rgba(30,58,138,.04);
  transition: box-shadow .25s, transform .25s;
}
.feat-card:hover { box-shadow: 0 12px 32px rgba(30,58,138,.1); transform: translateY(-4px); }

.feat-card.full-row { grid-column: 1 / -1; }
.feat-card.dark-card {
  background: var(--primary);
  border-color: transparent;
  box-shadow: 0 4px 14px rgba(30,58,138,.15);
  grid-column: 1 / 3;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 32px;
  overflow: hidden;
  position: relative;
}
.feat-card.dark-card:hover { box-shadow: 0 12px 32px rgba(30,58,138,.3); }
.feat-card.dark-card .feat-icon-wrap { background: rgba(255,255,255,.1); color: #6ffbbe; }
.feat-card.dark-card h3 { color: #fff; }
.feat-card.dark-card p  { color: rgba(255,255,255,.75); }
.feat-card.dark-card .dark-deco {
  position: absolute;
  right: -30px;
  bottom: -30px;
  font-size: 160px;
  color: rgba(255,255,255,.05);
}

.feat-icon-wrap {
  width: 52px; height: 52px;
  border-radius: 14px;
  background: var(--surface-high);
  color: var(--primary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  margin-bottom: 20px;
  flex-shrink: 0;
}
.feat-card h3 {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 22px;
  font-weight: 600;
  color: var(--primary);
  margin-bottom: 10px;
}
.feat-card p {
  font-size: 15px;
  color: var(--on-surface-v);
  line-height: 1.65;
}

@media (max-width: 900px) {
  .features-grid { grid-template-columns: 1fr; }
  .feat-card.dark-card { grid-column: 1; flex-direction: column; align-items: flex-start; }
  .section-title { font-size: 28px; }
}

/* ── HOW IT WORKS ── */
.hiw-section {
  background: var(--surface-low);
  padding: 96px 0;
}
.hiw-steps {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 32px;
  position: relative;
}
.hiw-line {
  position: absolute;
  top: 48px;
  left: 17%;
  right: 17%;
  height: 2px;
  background: var(--outline);
}
.step-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 20px;
  position: relative;
  z-index: 1;
}
.step-circle {
  width: 96px; height: 96px;
  border-radius: 50%;
  background: #fff;
  border: 4px solid var(--surface-low);
  box-shadow: 0 4px 14px rgba(30,58,138,.08);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 36px;
  color: var(--primary);
  flex-shrink: 0;
}
.step-circle.done {
  background: var(--secondary);
  color: #fff;
  border-color: var(--secondary);
}
.step-overline {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: var(--secondary);
}
.step-title {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 22px;
  font-weight: 600;
  color: var(--primary);
  margin-bottom: 8px;
}
.step-desc {
  font-size: 15px;
  color: var(--on-surface-v);
  line-height: 1.65;
  max-width: 280px;
}
@media (max-width: 768px) {
  .hiw-steps { grid-template-columns: 1fr; }
  .hiw-line  { display: none; }
}

/* ── CTA BANNER ── */
.cta-section { padding: 96px 0; }
.cta-banner {
  background: var(--primary);
  border-radius: 24px;
  padding: 80px 60px;
  text-align: center;
  box-shadow: 0 20px 60px rgba(30,58,138,.2);
  position: relative;
  overflow: hidden;
}
.cta-banner::before {
  content: '';
  position: absolute;
  top: -80px; right: -80px;
  width: 360px; height: 360px;
  background: rgba(255,255,255,.04);
  border-radius: 50%;
}
.cta-banner::after {
  content: '';
  position: absolute;
  bottom: -100px; left: -60px;
  width: 280px; height: 280px;
  background: rgba(16,185,129,.06);
  border-radius: 50%;
}
.cta-banner h2 {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 42px;
  font-weight: 700;
  color: #fff;
  line-height: 1.15;
  margin-bottom: 16px;
  position: relative;
  z-index: 1;
}
.cta-banner p {
  font-size: 18px;
  color: rgba(255,255,255,.75);
  max-width: 540px;
  margin: 0 auto 36px;
  line-height: 1.65;
  position: relative;
  z-index: 1;
}
.btn-cta {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--secondary);
  color: #fff;
  font-size: 15px;
  font-weight: 700;
  padding: 16px 36px;
  border-radius: 12px;
  text-decoration: none;
  box-shadow: 0 4px 14px rgba(0,0,0,.1);
  position: relative;
  z-index: 1;
  transition: all .25s;
}
.btn-cta:hover { background: #005236; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.2); }

@media (max-width: 768px) {
  .cta-banner { padding: 48px 24px; }
  .cta-banner h2 { font-size: 28px; }
}

/* ── FOOTER ── */
footer {
  background: var(--on-surface);
  padding: 56px 0 32px;
  color: #fff;
}
.footer-inner {
  display: grid;
  grid-template-columns: 1.5fr 1fr 1fr 1fr;
  gap: 40px;
  margin-bottom: 48px;
}
.footer-brand .logo-text { color: #fff; font-size: 22px; }
.footer-brand p { font-size: 14px; color: rgba(255,255,255,.6); line-height: 1.65; margin-top: 12px; max-width: 240px; }
.footer-col h4 {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: rgba(255,255,255,.4);
  margin-bottom: 16px;
}
.footer-col a {
  display: block;
  font-size: 14px;
  color: rgba(255,255,255,.7);
  text-decoration: none;
  margin-bottom: 10px;
  transition: color .2s;
}
.footer-col a:hover { color: #fff; }
.footer-bottom {
  border-top: 1px solid rgba(255,255,255,.08);
  padding-top: 28px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.footer-bottom p { font-size: 13px; color: rgba(255,255,255,.4); }

@media (max-width: 900px) {
  .footer-inner { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
  .footer-inner { grid-template-columns: 1fr; }
  .footer-bottom { flex-direction: column; text-align: center; }
}

/* ── ANIMATIONS ── */
.fade-up {
  opacity: 0;
  transform: translateY(28px);
  transition: opacity .6s ease, transform .6s ease;
}
.fade-up.visible {
  opacity: 1;
  transform: translateY(0);
}
.fade-up:nth-child(2) { transition-delay: .1s; }
.fade-up:nth-child(3) { transition-delay: .2s; }
.fade-up:nth-child(4) { transition-delay: .3s; }
.product-preview { height:100%; padding:18px; background:linear-gradient(145deg,#102b68,#071b48); color:#fff; display:flex; flex-direction:column; gap:15px; }
.preview-window { background:#f8faff; border-radius:13px; padding:14px; color:var(--on-surface); flex:1; }
.preview-bar { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
.preview-brand { display:flex; align-items:center; gap:7px; font:700 12px 'Plus Jakarta Sans',sans-serif; color:var(--primary); }
.preview-brand i { width:24px; height:24px; border-radius:7px; background:var(--primary); color:#fff; display:grid; place-items:center; }
.preview-user { width:24px; height:24px; display:grid; place-items:center; border-radius:50%; color:#fff; background:#10a878; font-size:12px; }
.preview-balance { background:linear-gradient(135deg,#0b327c,#1d57b8); border-radius:11px; padding:14px; color:#fff; }
.preview-label { font-size:10px; opacity:.76; letter-spacing:.04em; text-transform:uppercase; }
.preview-amount { font:700 25px 'Plus Jakarta Sans',sans-serif; margin:5px 0 9px; }
.preview-status { display:inline-flex; align-items:center; gap:5px; padding:4px 7px; border-radius:20px; background:rgba(255,255,255,.14); font-size:9px; }
.preview-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:11px; }
.preview-card { border:1px solid #e4eaf5; border-radius:10px; padding:10px; background:#fff; }
.preview-card strong { display:block; font-size:11px; margin-top:5px; color:#16243d; }.preview-card span { font-size:9px; color:#718096; }.preview-card i { color:var(--secondary); font-size:16px; }
.preview-order { display:flex; align-items:center; gap:8px; border-top:1px solid #e7edf7; margin-top:12px; padding-top:11px; }.preview-order-icon { width:31px; height:31px; display:grid; place-items:center; border-radius:8px; color:#0d5c44; background:#d9f6e9; }.preview-order strong { font-size:10px; display:block; }.preview-order span { color:#718096; font-size:9px; }
.preview-caption { display:flex; justify-content:space-between; align-items:center; font-size:10px; color:#dbe7ff; padding:0 3px; }.preview-caption span { display:inline-flex; gap:5px; align-items:center; }
</style>
</head>

<body>

<!-- ── NAVIGATION ── -->
<nav id="navbar">
  <div class="container">
    <div class="nav-inner">
      <a href="index.php" class="logo">
        <img src="assets/logo/image.png" alt="EscrowPay Logo" style="width:48px;height:48px;border-radius:9px;object-fit:contain;background:#fff;padding:2px;">
        <span class="logo-text">EscrowPay</span>
      </a>

      <ul class="nav-links">
        <li><a href="#" class="active">Home</a></li>
        <li><a href="#how-it-works">How It Works</a></li>
        <li><a href="#features">Features</a></li>
        <li><a href="#payment-methods">Payment Methods</a></li>
        <li><a href="#disputes">Dispute System</a></li>
      </ul>

      <div class="nav-ctas">
        <a href="login.php" class="btn-outline-nav">Sign In</a>
        <a href="register.php" class="btn-primary-nav"><i class="ri-download-line"></i> Get Started</a>
      </div>

      <button class="hamburger" id="hamburger" aria-label="Toggle menu">
        <i class="ri-menu-line"></i>
      </button>
    </div>
  </div>

  <!-- Mobile Menu -->
  <div class="mobile-nav" id="mobileNav">
    <a href="#">Home</a>
    <a href="#how-it-works">How It Works</a>
    <a href="#features">Features</a>
    <a href="#payment-methods">Payment Methods</a>
    <a href="#disputes">Dispute System</a>
    <div class="mobile-btns">
      <a href="login.php" class="btn-outline-nav" style="flex:1;text-align:center;display:block;padding:11px 0">Sign In</a>
      <a href="register.php" class="btn-primary-nav" style="flex:1;text-align:center;display:block;padding:11px 0">Get Started</a>
    </div>
  </div>
</nav>

<!-- ── HERO ── -->
<section class="hero">
  <div class="container">
    <div class="hero-inner">
      <!-- Left: Copy -->
      <div>
        <div class="hero-badge">
          <i class="ri-shield-check-fill"></i> Soomaaliya · Secure commerce infrastructure
        </div>
        <h1>Every online deal deserves a trusted middle ground.</h1>
        <p>EscrowPay protects buyers, sellers, and delivery partners by holding payment securely until the order is verified and complete.</p>
        <div class="hero-btns">
          <a href="register.php" class="btn-hero-primary">
            <i class="ri-user-add-line"></i> Create your account
          </a>
          <a href="login.php" class="btn-hero-secondary">
            Sign in <i class="ri-arrow-right-line"></i>
          </a>
        </div>
      </div>

      <!-- Right: Visual -->
      <div class="hero-visual">
        <div class="product-preview" aria-label="EscrowPay dashboard preview">
          <div class="preview-window">
            <div class="preview-bar"><div class="preview-brand"><i class="ri-shield-check-line"></i> ESCROWPAY</div><div class="preview-user"><i class="ri-user-3-line"></i></div></div>
            <div class="preview-balance"><div class="preview-label">Protected order value</div><div class="preview-amount">$ 240.00</div><div class="preview-status"><i class="ri-shield-check-fill"></i> Funds safely held in escrow</div></div>
            <div class="preview-grid"><div class="preview-card"><i class="ri-store-2-line"></i><strong>Seller verified</strong><span>Identity checked</span></div><div class="preview-card"><i class="ri-e-bike-2-line"></i><strong>Delivery tracked</strong><span>Proof at every step</span></div></div>
            <div class="preview-order"><div class="preview-order-icon"><i class="ri-checkbox-circle-line"></i></div><div><strong>Order ESC-1042 is protected</strong><span>Release payment after confirmation</span></div></div>
          </div>
          <div class="preview-caption"><span><i class="ri-lock-2-line"></i> Secure by design</span><span>Buyer · Seller · Delivery</span></div>
        </div>
        <!-- Floating stat cards -->
        <div class="hero-stat-card">
          <div class="stat-icon-circle stat-icon-green"><i class="ri-shield-check-fill"></i></div>
          <div>
            <div class="stat-value">100% Secured</div>
            <div class="stat-sub">Every transaction protected</div>
          </div>
        </div>
        <div class="hero-stat-card">
          <div class="stat-icon-circle stat-icon-blue"><i class="ri-money-dollar-circle-line"></i></div>
          <div>
            <div class="stat-value">Funds in Escrow</div>
            <div class="stat-sub">Released on confirmation</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── TRUST STRIP ── -->
<section class="trust-strip" id="payment-methods">
  <div class="container">
    <div class="trust-inner">
      <p class="trust-label">Trusted by local payment providers</p>
      <div class="trust-logo">EVC Plus</div>
      <div class="trust-logo">Sahal</div>
      <div class="trust-logo">Zaad</div>
      <p class="trust-endorsed">
        <i class="ri-award-line"></i>
        Endorsed by Hormuud University Faculty of Computer Science &amp; IT
      </p>
    </div>
  </div>
</section>

<!-- ── FEATURES ── -->
<section class="section" id="features">
  <div class="container">
    <div class="section-header fade-up">
      <p class="section-overline">Platform Features</p>
      <h2 class="section-title">Institutional-Grade Security</h2>
      <p class="section-sub">Designed to eliminate fraud and build absolute trust in digital transactions across Somalia.</p>
    </div>

    <div class="features-grid">
      <!-- Big card -->
      <div class="feat-card fade-up">
        <div class="feat-icon-wrap"><i class="ri-safe-2-line"></i></div>
        <h3>Escrow Wallet Protection</h3>
        <p>Funds are securely frozen in our vault until the buyer confirms fulfillment, protecting both parties from non-delivery or non-payment. No risk, no worry.</p>
      </div>
      <!-- Small card -->
      <div class="feat-card fade-up">
        <div class="feat-icon-wrap"><i class="ri-smartphone-line"></i></div>
        <h3>Local Mobile Money</h3>
        <p>Seamless integration with EVC Plus, Zaad, and Sahal for instant, zero-friction deposits.</p>
      </div>
      <!-- Small card -->
      <div class="feat-card fade-up">
        <div class="feat-icon-wrap"><i class="ri-verified-badge-line"></i></div>
        <h3>Verified Sellers (KYC)</h3>
        <p>Rigorous identity checks ensure you only transact with legitimate, verified businesses and individuals.</p>
      </div>
      <!-- Dark full-width card -->
      <div class="feat-card dark-card fade-up" id="disputes">
        <div>
          <div class="feat-icon-wrap"><i class="ri-scales-3-line"></i></div>
          <h3>Smart Dispute Resolution</h3>
          <p>Swift conflict resolution workflow with unbiased mediation tools to handle any issues fairly and efficiently. Our team reviews and resolves disputes within 24 hours.</p>
        </div>
        <div class="dark-deco"><i class="ri-scales-3-line"></i></div>
      </div>
    </div>
  </div>
</section>

<!-- ── HOW IT WORKS ── -->
<section class="hiw-section" id="how-it-works">
  <div class="container">
    <div class="section-header fade-up">
      <p class="section-overline">The Process</p>
      <h2 class="section-title">Simple, Transparent Process</h2>
      <p class="section-sub">How EscrowPay protects your transaction in three easy steps.</p>
    </div>

    <div class="hiw-steps">
      <div class="hiw-line"></div>

      <div class="step-item fade-up">
        <div class="step-circle">
          <i class="ri-secure-payment-line"></i>
        </div>
        <div>
          <div class="step-overline">Step 1</div>
          <div class="step-title">Place &amp; Pay</div>
          <p class="step-desc">Buyer pays into the secure EscrowPay vault via their preferred mobile money provider (EVC Plus, Zaad, Sahal).</p>
        </div>
      </div>

      <div class="step-item fade-up">
        <div class="step-circle">
          <i class="ri-truck-line"></i>
        </div>
        <div>
          <div class="step-overline">Step 2</div>
          <div class="step-title">Ship &amp; Track</div>
          <p class="step-desc">Seller is notified of secured funds and ships the item. A verified delivery agent picks up and transports to the buyer.</p>
        </div>
      </div>

      <div class="step-item fade-up">
        <div class="step-circle done">
          <i class="ri-checkbox-circle-line"></i>
        </div>
        <div>
          <div class="step-overline">Step 3</div>
          <div class="step-title">Confirm &amp; Release</div>
          <p class="step-desc">Funds are automatically released to the seller upon the buyer's confirmation of delivery. Simple and instant.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── CTA BANNER ── -->
<section class="cta-section">
  <div class="container">
    <div class="cta-banner fade-up">
      <h2>Ready to secure your online transactions?</h2>
      <p>Join thousands of buyers and sellers who trust EscrowPay for safe, reliable e-commerce across Somalia.</p>
      <a href="register.php" class="btn-cta">
        <i class="ri-rocket-line"></i> Get Started Now
      </a>
    </div>
  </div>
</section>

<!-- ── FOOTER ── -->
<footer>
  <div class="container">
    <div class="footer-inner">
      <!-- Brand -->
      <div class="footer-brand">
        <a href="index.php" class="logo">
          <div class="logo-icon" style="background:rgba(255,255,255,.1)"><i class="ri-shield-check-fill"></i></div>
          <span class="logo-text">EscrowPay</span>
        </a>
        <p>Building trust in Somali e-commerce through secure, transparent escrow technology.</p>
      </div>
      <!-- Platform -->
      <div class="footer-col">
        <h4>Platform</h4>
        <a href="login.php">Sign In</a>
        <a href="#how-it-works">How It Works</a>
        <a href="#features">Features</a>
        <a href="#disputes">Dispute System</a>
      </div>
      <!-- Payment -->
      <div class="footer-col">
        <h4>Payments</h4>
        <a href="#payment-methods">EVC Plus</a>
        <a href="#payment-methods">Zaad</a>
        <a href="#payment-methods">Sahal</a>
      </div>
      <!-- Legal -->
      <div class="footer-col">
        <h4>Legal</h4>
        <a href="#">Privacy Policy</a>
        <a href="#">Terms of Service</a>
        <a href="#">Security Standards</a>
        <a href="#">Contact Support</a>
      </div>
    </div>

    <div class="footer-bottom">
      <p>&copy; <?= date('Y') ?> EscrowPay. All rights reserved. Secure Transactions Guaranteed.</p>
      <p style="font-size:13px;color:rgba(255,255,255,.35)">Endorsed by Hormuud University — CS &amp; IT Faculty</p>
    </div>
  </div>
</footer>

<script>
// Mobile nav toggle
const hamburger = document.getElementById('hamburger');
const mobileNav = document.getElementById('mobileNav');
hamburger.addEventListener('click', () => {
  const open = mobileNav.style.display === 'flex';
  mobileNav.style.display = open ? 'none' : 'flex';
  hamburger.innerHTML = open ? '<i class="ri-menu-line"></i>' : '<i class="ri-close-line"></i>';
});

// Scroll reveal
const observer = new IntersectionObserver((entries) => {
  entries.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      observer.unobserve(e.target);
    }
  });
}, { threshold: 0.1 });

document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));

// Navbar shadow on scroll
window.addEventListener('scroll', () => {
  document.getElementById('navbar').style.boxShadow =
    window.scrollY > 10 ? '0 2px 20px rgba(30,58,138,.08)' : 'none';
});
</script>

</body>
</html>
