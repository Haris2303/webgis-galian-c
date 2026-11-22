<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/header.php — <head> + pembuka shell halaman (T-04, PRD bagian 9).
 *
 * Variabel yang bisa diset pemanggil SEBELUM require:
 * - $page_title   (string)  judul tab browser, default "WebGIS Galian C".
 * - $layout       ('app'|'auth') 'app' = topbar+sidebar (default), 'auth' =
 *                  shell login tanpa sidebar (PRD #1: "Tanpa sidebar").
 * - $active_menu  (string)  kunci menu aktif, dibaca includes/sidebar.php untuk aria-current.
 *
 * Wajib ditutup dengan require includes/footer.php di akhir halaman.
 */

$page_title = $page_title ?? 'WebGIS Galian C';
$layout = $layout ?? 'app';
// Dibaca HANYA saat $layout === 'auth' -- pemanggil (mis. login.php, saat
// migrasi ke tampilan DESIGN.md) bisa menimpanya SEBELUM require ini untuk
// ganti class pembungkus tanpa mengubah berkas ini lagi per halaman.
$auth_shell_class = $auth_shell_class ?? 'auth-shell';

$flash_messages = flash_pull();
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> — WebGIS Galian C Kota Sorong</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/print.css')) ?>" media="print">
<?= render_raw_tags($extra_head ?? []) ?>
</head>
<body>
<?php if ($layout === 'auth'): ?>
<div class="<?= e($auth_shell_class) ?>">
  <main id="konten-utama">
<?php else: ?>
<a class="skip-link" href="#konten-utama">Lewati ke konten utama</a>
<div class="app-shell">
  <header class="app-topbar">
    <button type="button" class="hamburger-btn" data-sidebar-toggle aria-expanded="false" aria-controls="sidebar-utama">
      <span class="hamburger-btn__icon"></span>
      <span class="sr-only">Buka menu navigasi</span>
    </button>
    <a class="app-topbar__brand" href="<?= e(url('dashboard.php')) ?>">WebGIS Galian C — Kota Sorong</a>
    <span class="app-topbar__spacer"></span>
    <?php $topbar_user = current_user(); ?>
    <div class="app-topbar__user">
      <?php if ($topbar_user !== null): ?>
        <span class="app-topbar__user-name"><?= e($topbar_user['nama_lengkap']) ?></span>
      <?php endif; ?>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn-link">Keluar</button>
      </form>
    </div>
  </header>
  <div class="app-sidebar-backdrop" hidden></div>
  <div class="app-body">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main id="konten-utama" class="app-main">
<?php endif; ?>
<?php if ($flash_messages !== []): ?>
<ul class="flash-list" aria-live="polite">
  <?php foreach ($flash_messages as $flash_item): ?>
  <?php $flash_type = in_array($flash_item['type'], ['success', 'error', 'warning', 'credential'], true) ? $flash_item['type'] : 'success'; ?>
  <?php $flash_password = $flash_item['extra']['password'] ?? null; ?>
  <li class="flash flash--<?= e($flash_type) ?>">
    <span class="flash__message"><?= e($flash_item['message']) ?></span>
    <?php if ($flash_password !== null): ?>
    <span class="flash__password"><?= e($flash_password) ?></span>
    <?php endif; ?>
    <button type="button" class="flash__close" aria-label="Tutup pesan">×</button>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
