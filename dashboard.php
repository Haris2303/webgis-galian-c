<?php

declare(strict_types=1);

/**
 * dashboard.php — Dashboard per peran (FR-04 s.d. FR-06; PRD bagian 5, 9; T-20).
 * Kartu statistik + pratinjau peta + tabel 10 lokasi. Admin melihat semua
 * lokasi; Kontraktor hanya lokasi miliknya (PRD bagian 2: "Dashboard
 * statistik: Ya (hanya lokasi miliknya)") -- scoping ini diterapkan ke KEDUA
 * kartu DAN tabel lewat satu variabel $kontraktor_id_scope, bukan hanya kartu.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/repo/lokasi.php';
require_once __DIR__ . '/includes/repo/kontraktor.php';

$user = require_login();

$kontraktor_id_scope = $user['role'] === 'kontraktor' ? (int) ($user['kontraktor_id'] ?? 0) : null;

// FR-04: satu query GROUP BY status_izin (repo_lokasi_count_by_status(), T-12),
// BUKAN empat query COUNT terpisah per kartu.
$stat = repo_lokasi_count_by_status($kontraktor_id_scope);
$total_lokasi = $stat['berizin'] + $stat['tidak_berizin'] + $stat['proses'];

// FR-06: 10 lokasi: nama, kontraktor, status. Filter kontraktor_id kosong
// (null) untuk Admin berarti repo_lokasi_list() tidak menyaring sama sekali
// (lihat !empty() di includes/repo/lokasi.php) -- tetap SATU fungsi untuk
// kedua peran, bukan dua query berbeda.
[$daftar_lokasi] = repo_lokasi_list(
    $kontraktor_id_scope !== null ? ['kontraktor_id' => $kontraktor_id_scope] : [],
    1,
    10
);

$judul_dashboard = 'Dashboard — Admin Dinas Perindustrian';
if ($user['role'] === 'kontraktor') {
    $kontraktor = $kontraktor_id_scope > 0 ? repo_kontraktor_find($kontraktor_id_scope) : null;
    $judul_dashboard = 'Dashboard — ' . ($kontraktor['nama_perusahaan'] ?? $user['nama_lengkap']);
}

/**
 * Markup badge status (teks + warna, PRD bagian 9) untuk satu baris tabel.
 * default menutup edge case wajib T-20: status_izin NULL atau nilai di luar
 * tiga enum (data rusak/kolom berubah) tampil sebagai badge netral, bukan
 * baris rusak atau warning PHP "undefined array key".
 */
function dashboard_badge_status(?string $status_izin): string
{
    $label = match ($status_izin) {
        'berizin' => 'Berizin',
        'tidak_berizin' => 'Tidak Berizin',
        'proses' => 'Proses',
        default => 'Status Tidak Diketahui',
    };
    $class = match ($status_izin) {
        'berizin' => 'ds-badge--berizin',
        'tidak_berizin' => 'ds-badge--tidak-berizin',
        'proses' => 'ds-badge--proses',
        default => 'ds-badge--tidak-diketahui',
    };

    return '<span class="ds-badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * Ikon SVG minimal (20x20, dekoratif/aria-hidden) per kartu statistik --
 * tangan sendiri, bukan pustaka ikon (PRD bagian 3: tanpa dependensi
 * tambahan), konsisten dengan ikon error di login.php (migrasi DESIGN.md).
 */
function dashboard_ikon(string $nama): string
{
    $path = match ($nama) {
        'total' => '<path d="M10 2.5c-3.6 0-6.5 2.8-6.5 6.3 0 4.6 6.5 8.7 6.5 8.7s6.5-4.1 6.5-8.7c0-3.5-2.9-6.3-6.5-6.3Z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="8.8" r="2.1" stroke="currentColor" stroke-width="1.5"/>',
        'berizin' => '<circle cx="10" cy="10" r="7.3" stroke="currentColor" stroke-width="1.5"/><path d="M6.8 10.2l2.1 2.1 4.3-4.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
        'tidak_berizin' => '<circle cx="10" cy="10" r="7.3" stroke="currentColor" stroke-width="1.5"/><path d="M7.3 7.3l5.4 5.4M12.7 7.3l-5.4 5.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>',
        'proses' => '<circle cx="10" cy="10" r="7.3" stroke="currentColor" stroke-width="1.5"/><path d="M10 6.2v4l2.6 1.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>',
        default => '',
    };

    return '<svg width="22" height="22" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

$page_title = 'Dashboard';
$layout = 'app';
$active_menu = 'dashboard';
$extra_head = [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
    // Migrasi UI DESIGN.md (lihat login.php) -- halaman per halaman.
    '<link rel="preconnect" href="https://fonts.googleapis.com">',
    '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>',
    '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;800;900&display=swap">',
    '<link rel="stylesheet" href="' . e(asset('assets/css/design-system.css')) . '">',
];
require __DIR__ . '/includes/header.php';
?>
<div class="ds-page">
  <div class="ds-page-header">
    <h1 class="ds-page-header__title"><?= e($judul_dashboard) ?></h1>
  </div>

  <div class="ds-stat-grid">
    <div class="ds-stat-card">
      <span class="ds-stat-card__icon"><?= dashboard_ikon('total') ?></span>
      <div>
        <p class="ds-stat-card__label">Total Lokasi Tambang</p>
        <p class="ds-stat-card__value"><?= e((string) $total_lokasi) ?></p>
      </div>
    </div>
    <div class="ds-stat-card ds-stat-card--berizin">
      <span class="ds-stat-card__icon"><?= dashboard_ikon('berizin') ?></span>
      <div>
        <p class="ds-stat-card__label">Berizin</p>
        <p class="ds-stat-card__value"><?= e((string) $stat['berizin']) ?></p>
      </div>
    </div>
    <div class="ds-stat-card ds-stat-card--tidak-berizin">
      <span class="ds-stat-card__icon"><?= dashboard_ikon('tidak_berizin') ?></span>
      <div>
        <p class="ds-stat-card__label">Tidak Berizin</p>
        <p class="ds-stat-card__value"><?= e((string) $stat['tidak_berizin']) ?></p>
      </div>
    </div>
    <div class="ds-stat-card ds-stat-card--proses">
      <span class="ds-stat-card__icon"><?= dashboard_ikon('proses') ?></span>
      <div>
        <p class="ds-stat-card__label">Proses</p>
        <p class="ds-stat-card__value"><?= e((string) $stat['proses']) ?></p>
      </div>
    </div>
  </div>

  <div class="ds-grid-2">
    <div class="ds-card">
      <h2 class="ds-card__title">Pratinjau Peta Persebaran Galian C</h2>
      <div
        id="dashboard-peta-preview"
        class="map-shell map-shell--preview"
        data-lokasi-url="<?= e(url('api/lokasi.php')) ?>"
        data-preview-url="<?= e(url('peta.php')) ?>"
      ></div>
      <p class="u-mt-4"><a class="ds-link-arrow" href="<?= e(url('peta.php')) ?>">Lihat peta lengkap &rarr;</a></p>
    </div>

    <div class="ds-card">
      <h2 class="ds-card__title">Daftar Lokasi Tambang</h2>
      <div class="ds-table-responsive">
        <table class="ds-table">
          <caption class="sr-only">Daftar lokasi tambang dan status izinnya</caption>
          <thead>
            <tr>
              <th scope="col">Nama Lokasi</th>
              <th scope="col">Kontraktor</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($daftar_lokasi === []): ?>
            <tr>
              <td colspan="3">
                Belum ada data lokasi tambang<?= $kontraktor_id_scope !== null ? ' untuk perusahaan Anda' : '' ?>.
              </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($daftar_lokasi as $row): ?>
            <tr>
              <td><?= e($row['nama_lokasi']) ?></td>
              <td><?= e($row['kontraktor_nama']) ?></td>
              <td><?= dashboard_badge_status($row['status_izin']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($user['role'] === 'admin' && $total_lokasi > 10): ?>
      <p class="u-mt-4"><a class="ds-link-arrow" href="<?= e(url('lokasi/index.php')) ?>">Lihat semua lokasi &rarr;</a></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$extra_scripts = [
    '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>',
    '<script src="' . e(asset('assets/js/map.js')) . '"></script>',
    '<script src="' . e(asset('assets/js/dashboard.js')) . '"></script>',
];
require __DIR__ . '/includes/footer.php';
