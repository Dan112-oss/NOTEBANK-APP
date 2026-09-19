<?php
/**
 * Admin layout shell — opens <html>, sidebar and topbar.
 * Requires: $pageTitle (string), $admin (from require_admin()).
 * Pair with includes/partials/admin_footer.php.
 */
require_once __DIR__ . '/../csrf.php';
$__current = basename($_SERVER['SCRIPT_NAME']);

$__nav = [
    'Overview' => [
        ['dashboard.php', 'Dashboard'],
    ],
    'Academic Structure' => [
        ['universities.php', 'Universities'],
        ['faculties.php', 'Faculties'],
        ['departments.php', 'Departments'],
        ['levels.php', 'Levels'],
        ['semesters.php', 'Semesters'],
        ['courses.php', 'Courses'],
    ],
    'Catalogue' => [
        ['documents.php', 'Documents'],
        ['uploads.php', 'Upload Document'],
    ],
    'Payments' => [
        ['payments.php', 'Payment Queue'],
        ['orders.php', 'Orders'],
    ],
    'People' => [
        ['students.php', 'Students'],
    ],
    'Insights' => [
        ['reports.php', 'Reports'],
        ['settings.php', 'Settings'],
    ],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Admin') ?> — Note Bank Admin</title>
<link rel="icon" href="<?= asset_url('images/favicon-32.png') ?>" type="image/png">
<link rel="apple-touch-icon" href="<?= asset_url('images/apple-touch-icon.png') ?>">
<link rel="stylesheet" href="<?= asset_url('css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('css/admin.css') ?>">
</head>
<body>
<div class="nb-admin">
  <aside class="nb-admin__sidebar" id="nbSidebar">
    <div class="nb-admin__brand">
      <img src="<?= asset_url('images/logo-mark-48.png') ?>" alt="">
      <span>Note Bank</span>
    </div>
    <nav class="nb-admin__nav">
      <?php foreach ($__nav as $__group => $__links): ?>
        <div class="nb-admin__nav-label"><?= e($__group) ?></div>
        <?php foreach ($__links as [$__href, $__label]): ?>
          <a href="<?= e($__href) ?>" class="<?= $__current === $__href ? 'is-active' : '' ?>"><?= e($__label) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="nb-admin__footer">
      Signed in as <strong><?= e($admin['full_name'] ?? '') ?></strong><br>
      <a href="logout.php">Sign out</a>
    </div>
  </aside>
  <div class="nb-admin__main">
    <header class="nb-admin__topbar">
      <button type="button" class="nb-btn nb-btn--ghost nb-btn--sm" id="nbSidebarToggle" style="display:none;">Menu</button>
      <h1><?= e($pageTitle ?? '') ?></h1>
      <div class="nb-admin__who">
        <span class="nb-stamp nb-stamp--unlocked"><?= e($admin['role'] ?? '') ?></span>
      </div>
    </header>
    <main class="nb-admin__content">
      <?php require __DIR__ . '/flash.php'; ?>
