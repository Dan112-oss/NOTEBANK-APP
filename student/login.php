<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (current_student()) {
    redirect('student/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    [$success, $message] = student_attempt_login($email, $password);
if ($success) {
    $redirectTo = $_GET['next'] ?? 'student/dashboard.php';
    redirect($redirectTo);
}

// Not a matching student account — if these happen to be admin
// credentials, sign the admin in from here too, so you don't have
// to remember the separate /admin/login.php URL.
[$adminSuccess] = admin_attempt_login($email, $password);
if ($adminSuccess) {
    redirect('admin/dashboard.php');
}

$error = $message;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In — Note Bank</title>
<link rel="icon" href="<?= asset_url('images/favicon-32.png') ?>" type="image/png">
<link rel="stylesheet" href="<?= asset_url('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('css/site.css') ?>">
</head>
<body>
<div class="nb-auth">
  <div class="nb-auth__card">
    <div class="nb-auth__brand">
      <img src="<?= asset_url('images/logo-mark-48.png') ?>" alt="">
      <span>Note Bank</span>
    </div>
    <h1>Welcome back</h1>
    <p class="nb-auth__sub">Sign in to browse, preview and download your notes.</p>

    <?php if ($error): ?>
      <div class="nb-alert nb-alert--error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="nb-field">
        <label class="nb-label" for="email">Email</label>
        <input class="nb-input" type="email" id="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="password">Password</label>
        <input class="nb-input" type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block" data-loading-text="Signing in…">Sign in</button>
    </form>
    <p class="nb-auth__foot">New to Note Bank? <a href="register.php">Create an account</a></p>
    <p class="nb-auth__foot"><a href="<?= base_url('index.php') ?>">← Back to Note Bank</a></p>
  </div>
</div>
<script src="<?= asset_url('js/main.js') ?>"></script>
</body>
</html>
