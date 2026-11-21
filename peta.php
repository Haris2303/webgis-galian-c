<?php

declare(strict_types=1);

/**
 * peta.php — peta interaktif penuh (FR-17, FR-18; PRD bagian 9, T-13).
 * Semua peran yang login boleh membuka halaman ini (lihat includes/sidebar.php);
 * data yang ditampilkan sendiri sudah ter-scope dengan benar di sisi API
 * (api/lokasi.php & api/area.php, T-12) -- marker/area tetap sama untuk semua
 * peran per PRD bagian 10, jadi di sini cukup require_login() biasa.
 */

require_once __DIR__ . '/includes/bootstrap.php';

require_login();

$page_title = 'Peta Interaktif';
$layout = 'app';
$active_menu = 'peta';
$extra_head = [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
];
require __DIR__ . '/includes/header.php';
?>
<h1 class="app-main__title"><?= e($page_title) ?></h1>

<div
  id="peta-mapa"
  class="map-shell"
  data-lokasi-url="<?= e(url('api/lokasi.php')) ?>"
  data-area-url="<?= e(url('api/area.php')) ?>"
  data-boundary-url="<?= e(asset('assets/geojson/batas_kota_sorong.geojson')) ?>"
></div>

<?php
$extra_scripts = [
    '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>',
    '<script src="' . e(asset('assets/js/map.js')) . '"></script>',
];
require __DIR__ . '/includes/footer.php';
