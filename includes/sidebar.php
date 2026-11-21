<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/sidebar.php — menu navigasi per peran (T-04/T-06, PRD bagian 9).
 * Hanya di-require dari includes/header.php saat $layout === 'app'.
 *
 * Membaca peran lewat current_user() (T-06), bukan $_SESSION langsung --
 * default 'admin' kalau null tetap dipertahankan untuk tests/ui_preview.php
 * (lihat berkas itu), BUKAN keputusan otorisasi; guard akses sesungguhnya
 * ada di require_login()/require_role(), dijalankan sebelum sidebar ini di-require.
 */

$sidebar_user = current_user();
$role = $sidebar_user['role'] ?? 'admin';
$active_menu = $active_menu ?? '';

$menu_admin = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
    'lokasi' => ['label' => 'Data Lokasi', 'href' => 'lokasi/index.php'],
    'kontraktor' => ['label' => 'Data Kontraktor', 'href' => 'kontraktor/index.php'],
    'aktivitas' => ['label' => 'Aktivitas', 'href' => 'aktivitas/index.php'],
    'peta' => ['label' => 'Peta Interaktif', 'href' => 'peta.php'],
    'monitoring' => ['label' => 'Monitoring', 'href' => 'monitoring.php'],
    'laporan' => ['label' => 'Laporan', 'href' => 'laporan/index.php'],
];

$menu_kontraktor = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
    'aktivitas_saya' => ['label' => 'Aktivitas Saya', 'href' => 'aktivitas/index.php'],
    'input_aktivitas' => ['label' => 'Input Aktivitas', 'href' => 'aktivitas/form.php'],
    'peta' => ['label' => 'Peta Lokasi', 'href' => 'peta.php'],
];

$menu_items = $role === 'kontraktor' ? $menu_kontraktor : $menu_admin;
?>
<nav class="app-sidebar" id="sidebar-utama" aria-label="Menu utama">
  <ul class="app-sidebar__nav">
    <?php foreach ($menu_items as $key => $item): ?>
    <li>
      <a
        class="app-sidebar__link"
        href="<?= e(url($item['href'])) ?>"
        <?php if ($active_menu === $key): ?>aria-current="page"<?php endif; ?>
      ><?= e($item['label']) ?></a>
    </li>
    <?php endforeach; ?>
    <li>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="app-sidebar__link app-sidebar__link--button">Logout</button>
      </form>
    </li>
  </ul>
</nav>
