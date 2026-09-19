<?php
/**
 * Student layout shell. Requires: $pageTitle, $student (from require_student()).
 * Pair with includes/partials/student_footer.php.
 */
require_once __DIR__ . '/../csrf.php';
$__current = basename($_SERVER['SCRIPT_NAME']);
$__cartCount = isset($student) ? cart_item_count($student['id']) : 0;
$__tabs = [
    ['dashboard.php', 'Home'],
    ['courses.php', 'Browse'],
    ['cart.php', 'Cart'],
    ['library.php', 'Library'],
    ['profile.php', 'Profile'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Note Bank') ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="<?= asset_url('images/favicon-32.png') ?>" type="image/png">
<link rel="apple-touch-icon" href="<?= asset_url('images/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="<?= asset_url('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('css/student.css') ?>">
</head>
<body>
<div class="nb-student">
  <aside class="nb-student__sidebar" id="nbStudentSidebar">
    <a href="../index.php" class="nb-student__brand" title="Back to Note Bank home">
      <img src="<?= asset_url('images/logo-mark-48.png') ?>" alt="">
      <span>Note Bank</span>
    </a>
    <nav class="nb-student__sidenav">
      <?php foreach ($__tabs as [$__href, $__label]): ?>
        <a href="<?= e($__href) ?>" class="<?= $__current === $__href ? 'is-active' : '' ?>"><?= e($__label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="nb-student__sidefoot">
      <a href="logout.php">Log out</a>
    </div>
  </aside>
  <div class="nb-student__col">
    <header class="nb-student__topbar">
      <span class="nb-student__topbar-title"><?= e($pageTitle ?? 'Note Bank') ?></span>
      <div class="nb-student__header-actions">
        <a href="cart.php" class="nb-cart-pill">Cart <span class="nb-cart-pill__count"><?= (int) $__cartCount ?></span></a>
      </div>
    </header>
    <main class="nb-student__main">
      <?php require __DIR__ . '/flash.php'; ?>
