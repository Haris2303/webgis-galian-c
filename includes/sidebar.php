<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// Menu navigasi per peran. Default 'admin' kalau current_user() null hanya untuk tests/ui_preview.php, bukan keputusan otorisasi.

$sidebar_user = current_user();
$role = $sidebar_user['role'] ?? 'admin';
$active_menu = $active_menu ?? '';

// Ikon SVG minimal (20x20, dekoratif/aria-hidden) per kunci menu.
function sidebar_ikon(string $kunci): string
{
    $path = match ($kunci) {
        'dashboard' => '<rect x="3" y="3" width="6.5" height="6.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="10.5" y="3" width="6.5" height="6.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="10.5" width="6.5" height="6.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/><rect x="10.5" y="10.5" width="6.5" height="6.5" rx="1.5" stroke="currentColor" stroke-width="1.5"/>',
        'aktivitas', 'aktivitas_saya' => '<path d="M6 3.5h8a1 1 0 0 1 1 1V16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5"/><path d="M7.5 7.5h5M7.5 10.5h5M7.5 13.5h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
        'input_aktivitas' => '<path d="M6 3.5h8a1 1 0 0 1 1 1V16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4.5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.5"/><path d="M10 8v5M7.5 10.5h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'peta' => '<path d="M10 2.5c-3.6 0-6.5 2.8-6.5 6.3 0 4.6 6.5 8.7 6.5 8.7s6.5-4.1 6.5-8.7c0-3.5-2.9-6.3-6.5-6.3Z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="8.8" r="2.1" stroke="currentColor" stroke-width="1.5"/>',
        'monitoring' => '<path d="M3 10.5h2.8l1.8-4.8 2.6 8.6 1.9-5.8 1.4 2h3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
        'laporan' => '<path d="M6.5 2.5h5l3 3V17a.5.5 0 0 1-.5.5h-7.5a.5.5 0 0 1-.5-.5V3a.5.5 0 0 1 .5-.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M11.5 2.5V5a.5.5 0 0 0 .5.5h2.5" stroke="currentColor" stroke-width="1.5"/><path d="M7.5 10h5M7.5 12.5h5M7.5 15h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>',
        'lokasi' => '<path d="M10 2.5c-3.6 0-6.5 2.8-6.5 6.3 0 4.6 6.5 8.7 6.5 8.7s6.5-4.1 6.5-8.7c0-3.5-2.9-6.3-6.5-6.3Z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="8.8" r="2.1" stroke="currentColor" stroke-width="1.5"/>',
        'kontraktor' => '<path d="M4 16.5V5.5a1 1 0 0 1 1-1h4.5v12M10.5 16.5V9h4.5a1 1 0 0 1 1 1v6.5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M6 7.5h1.5M6 10h1.5M6 12.5h1.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>',
        default => '<circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.5"/>',
    };

    return '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

// Admin tidak punya menu "Aktivitas" sendiri -- Monitoring sudah menampilkan semua aktivitas lintas lokasi, dengan tautan Ubah/Hapus ke aktivitas/form.php & aktivitas/hapus.php.
$menu_admin_utama = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
    'peta' => ['label' => 'Peta Interaktif', 'href' => 'peta.php'],
    'monitoring' => ['label' => 'Monitoring', 'href' => 'monitoring.php'],
    'laporan' => ['label' => 'Laporan', 'href' => 'laporan/index.php'],
];
$menu_admin_master = [
    'lokasi' => ['label' => 'Data Lokasi', 'href' => 'lokasi/index.php'],
    'kontraktor' => ['label' => 'Data Kontraktor', 'href' => 'kontraktor/index.php'],
];

// Kontraktor tidak berwenang kelola lokasi/kontraktor, jadi tidak punya grup "Data Master".
$menu_kontraktor_utama = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
    'aktivitas_saya' => ['label' => 'Aktivitas Saya', 'href' => 'aktivitas/index.php'],
    'input_aktivitas' => ['label' => 'Input Aktivitas', 'href' => 'aktivitas/form.php'],
    'peta' => ['label' => 'Peta Lokasi', 'href' => 'peta.php'],
];

$menu_utama = $role === 'kontraktor' ? $menu_kontraktor_utama : $menu_admin_utama;
$menu_master = $role === 'kontraktor' ? [] : $menu_admin_master;
?>
<nav class="ds-sidebar" id="sidebar-utama" aria-label="Menu utama">
  <ul class="ds-sidebar__nav">
    <?php foreach ($menu_utama as $key => $item): ?>
    <li>
      <a
        class="ds-sidebar__link"
        href="<?= e(url($item['href'])) ?>"
        <?php if ($active_menu === $key): ?>aria-current="page"<?php endif; ?>
      ><?= sidebar_ikon($key) ?><?= e($item['label']) ?></a>
    </li>
    <?php endforeach; ?>

    <?php if ($menu_master !== []): ?>
    <li class="ds-sidebar__group-label" role="presentation">Data Master</li>
    <?php foreach ($menu_master as $key => $item): ?>
    <li>
      <a
        class="ds-sidebar__link"
        href="<?= e(url($item['href'])) ?>"
        <?php if ($active_menu === $key): ?>aria-current="page"<?php endif; ?>
      ><?= sidebar_ikon($key) ?><?= e($item['label']) ?></a>
    </li>
    <?php endforeach; ?>
    <?php endif; ?>

    <li class="ds-sidebar__logout-item">
      <button type="button" class="ds-sidebar__link" data-dialog-open="dialog-logout">Logout</button>
    </li>
  </ul>
</nav>
