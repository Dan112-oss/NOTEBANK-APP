<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$db = get_db();
$courseCount = (int) $db->query("SELECT COUNT(*) FROM courses WHERE status = 'active'")->fetchColumn();
$documentCount = (int) $db->query("SELECT COUNT(*) FROM documents WHERE status = 'ACTIVE'")->fetchColumn();
$universityCount = (int) $db->query("SELECT COUNT(*) FROM universities WHERE status = 'active'")->fetchColumn();
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Note Bank — Verified Notes &amp; Past Questions</title>
<meta name="description" content="Note Bank is where Landmark University students deposit and withdraw verified lecture notes and past questions, unlocked the moment payment is confirmed.">
<link rel="icon" href="<?= asset_url('images/favicon-32.png') ?>" type="image/png">
<link rel="apple-touch-icon" href="<?= asset_url('images/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="<?= asset_url('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('css/site.css') ?>">
</head>
<body>

<header class="nb-site-header">
  <div class="nb-site-header__inner">
    <div class="nb-site-header__brand">
      <img src="<?= asset_url('images/logo-mark-48.png') ?>" alt="">
      <span>Note Bank</span>
    </div>
    <div class="nb-site-header__actions">
      <a href="student/login.php" class="nb-link-light">Student Sign In</a>
      <a href="student/register.php" class="nb-btn nb-btn--primary nb-btn--sm">Get Started</a>
    </div>
  </div>
</header>

<section class="nb-hero">
  <div class="nb-hero__inner">
    <div>
      <span class="nb-hero__eyebrow"><strong><?= number_format($documentCount) ?></strong> documents verified &amp; ready</span>
      <h1>Deposit your notes.<br>Withdraw an <em>edge.</em></h1>
      <p class="nb-lede">Note Bank is the verified marketplace for lecture notes and past questions — pay by MTN Mobile Money or Orange Money, and your library unlocks the moment payment is confirmed.</p>
      <div class="nb-hero__ctas">
        <a href="student/register.php" class="nb-btn nb-btn--primary">Create your account</a>
        <a href="student/login.php" class="nb-btn nb-btn--glass">I already have one</a>
      </div>
    </div>
    <div class="nb-hero__visual">
      <div class="nb-stat-chip">
        <div class="nb-stat-chip__icon">✓</div>
        <div>
          <div class="nb-stat-chip__value"><?= number_format($courseCount) ?> courses</div>
          <div class="nb-stat-chip__label">covered across <?= number_format($universityCount) ?> universit<?= $universityCount === 1 ? 'y' : 'ies' ?></div>
        </div>
      </div>
      <div class="nb-slip">
        <div class="nb-slip__title">Withdrawal Slip</div>
        <div class="nb-slip__row"><span>Course</span><strong>CSC301</strong></div>
        <div class="nb-slip__row"><span>Document</span><strong>Data Structures — Full Note</strong></div>
        <div class="nb-slip__row"><span>Method</span><strong>MTN MoMo</strong></div>
        <div class="nb-slip__row"><span>Reference</span><strong>NB-2026-XXXXXX</strong></div>
        <div class="nb-slip__row" style="border-bottom:none;"><span>Status</span><strong>Verified</strong></div>
        <div class="nb-slip__stamp">UNLOCKED</div>
      </div>
    </div>
  </div>
</section>

<section class="nb-section nb-section--muted">
  <div class="nb-section__head">
    <h2>Three steps. No guesswork.</h2>
    <p>Every order follows the same transparent path from cart to unlocked library.</p>
  </div>
  <div class="nb-steps">
    <div class="nb-step">
      <div class="nb-step__num">01 — Choose</div>
      <h3>Browse your catalogue</h3>
      <p>Filter by faculty, department, level and semester to find exactly the notes and past questions your course needs.</p>
    </div>
    <div class="nb-step">
      <div class="nb-step__num">02 — Pay</div>
      <h3>Pay by Mobile Money</h3>
      <p>Send payment via MTN Mobile Money or Orange Money, then upload your evidence — no card required.</p>
    </div>
    <div class="nb-step">
      <div class="nb-step__num">03 — Unlock</div>
      <h3>Download instantly</h3>
      <p>Once an admin verifies your payment, your documents land in your library — personalised, watermarked, ready to study.</p>
    </div>
  </div>
</section>

<section class="nb-section">
  <div class="nb-section__head">
    <h2>Built for how you actually study</h2>
    <p><?= number_format($universityCount) ?> university onboarded · <?= number_format($courseCount) ?> courses · <?= number_format($documentCount) ?> documents in the catalogue and growing.</p>
  </div>
  <div class="nb-features">
    <div class="nb-feature nb-feature--wide">
      <h3>Preview before you pay</h3>
      <p>Every document offers a watermarked, page-limited preview so you know exactly what you're getting before you spend a single FCFA.</p>
    </div>
    <div class="nb-feature">
      <h3>Personalised protection</h3>
      <p>Downloads are stamped with your name and a timestamp — a real deterrent against sharing.</p>
    </div>
    <div class="nb-feature">
      <h3>One library, every purchase</h3>
      <p>Everything you've unlocked lives in one place, with receipts and order history a click away.</p>
    </div>
    <div class="nb-feature">
      <h3>Course-matched search</h3>
      <p>Filter by faculty, department, level and semester to find exactly what your course needs.</p>
    </div>
    <div class="nb-feature">
      <h3>Mobile Money native</h3>
      <p>Pay by MTN Mobile Money or Orange Money — no card, no bank account required.</p>
    </div>
    <div class="nb-feature">
      <h3>Mobile-first, always</h3>
      <p>Built for the phone in your hand between classes — browsing, previewing and downloading all work on the go.</p>
    </div>
  </div>
</section>

<div class="nb-cta-band">
  <h2>Ready to stop hunting for notes?</h2>
  <p style="max-width:46ch; margin:0 auto 22px; opacity:0.92;">Create your free account and start browsing your department's catalogue in minutes.</p>
  <a href="student/register.php" class="nb-btn nb-btn--primary">Create your account</a>
</div>

<footer class="nb-site-footer">
  <div class="nb-site-footer__inner">
    <div>
      <div class="nb-student__brand" style="margin-bottom:8px;"><img src="<?= asset_url('images/logo-mark-48.png') ?>" alt="" style="width:26px;height:26px;"> <strong style="color:#fff;">Note Bank</strong></div>
      <p style="max-width:36ch;">Learn. Access. Succeed.</p>
    </div>
    <div>
      <p><a href="student/login.php">Student Sign In</a></p>
    </div>
    <div>
      <p>Support: <?= e(setting('support_email', 'support@notebank.test')) ?></p>
      <p>&copy; <?= date('Y') ?> Note Bank.</p>
    </div>
  </div>
</footer>

</body>
</html>
