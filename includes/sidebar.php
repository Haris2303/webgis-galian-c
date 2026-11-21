<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/sidebar.php — menu navigasi per peran (T-04, PRD bagian 9).
 * Hanya di-require dari includes/header.php saat $layout === 'app'.
 *
 * $_SESSION['role'] belum tentu terisi sebelum T-06 (login) berjalan; default
 * ke 'admin' di sini murni agar halaman bisa dirender/diuji sebelum T-06 ada,
 * BUKAN keputusan otorisasi -- guard akses sesungguhnya adalah tugas T-06.
 */

$role = $_SESSION['role'] ?? 'admin';
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
      <a class="app-sidebar__link" href="<?= e(url('logout.php')) ?>">Logout</a>
    </li>
  </ul>
</nav>
