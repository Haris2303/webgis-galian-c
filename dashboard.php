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

$judul_dashboard = 'Dashboard';

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

$page_title = 'Dashboard';
$layout = 'app';
$active_menu = 'dashboard';
$extra_head = [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
];
require __DIR__ . '/includes/header.php';
?>
<div class="ds-page">
  <div class="ds-page-header ds-page-header--row">
    <div>
      <span class="ds-page-header__eyebrow">Selamat datang, <?= e($user['nama_lengkap']) ?></span>
      <h1 class="ds-page-header__title"><?= e($judul_dashboard) ?></h1>
    </div>
    <?php if ($user['role'] === 'kontraktor'): ?>
    <a class="ds-btn ds-btn--primary" href="<?= e(url('aktivitas/form.php')) ?>">+ Input Aktivitas</a>
    <?php endif; ?>
  </div>

  <div class="ds-stat-cols">
    <div class="ds-stat-col">
      <p class="ds-stat-col__label">Total Lokasi Tambang</p>
      <p class="ds-stat-col__value"><?= e((string) $total_lokasi) ?></p>
    </div>
    <div class="ds-stat-col ds-stat-col--berizin">
      <p class="ds-stat-col__label">Berizin</p>
      <p class="ds-stat-col__value"><?= e((string) $stat['berizin']) ?></p>
    </div>
    <div class="ds-stat-col ds-stat-col--tidak-berizin">
      <p class="ds-stat-col__label">Tidak Berizin</p>
      <p class="ds-stat-col__value"><?= e((string) $stat['tidak_berizin']) ?></p>
    </div>
    <div class="ds-stat-col ds-stat-col--proses">
      <p class="ds-stat-col__label">Proses</p>
      <p class="ds-stat-col__value"><?= e((string) $stat['proses']) ?></p>
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
