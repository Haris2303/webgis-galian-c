<?php

declare(strict_types=1);

/**
 * tests/ui_preview.php — pratinjau komponen UI T-04, BUKAN bagian dari PRD.
 *
 * dashboard.php belum dibangun (menyusul T-08/T-20) sehingga kriteria selesai
 * T-04 ("Jalankan Lighthouse pada ... dashboard") tidak punya target nyata.
 * Berkas ini mensimulasikan tata letak dashboard dari PRD memakai
 * includes/header.php + sidebar.php + footer.php yang sama, supaya CSS/markup
 * T-04 bisa diuji manual dan lewat Lighthouse SEKARANG, sebelum halaman
 * aslinya ada. login.php SUDAH nyata sejak T-06 -- gunakan itu langsung,
 * bukan mode view=login di sini (sudah dihapus).
 *
 * Harus 404 di luar APP_ENV=dev (lihat cek di bawah) dan WAJIB dihapus atau
 * diganti pengujian ke halaman asli begitu T-08/T-20 selesai — lihat
 * "Risiko tersisa" pada laporan T-04/T-06.
 *
 * Akses: /tests/ui_preview.php?view=dashboard|components&role=admin|kontraktor
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require __DIR__ . '/../includes/bootstrap.php';
}

if (APP_ENV !== 'dev') {
    http_response_code(404);
    exit('Not Found');
}

$view = $_GET['view'] ?? 'components';
if (!in_array($view, ['dashboard', 'components'], true)) {
    $view = 'components';
}

// user_id palsu (bukan ID nyata mana pun) HANYA supaya current_user() (T-06)
// mengembalikan array, bukan null, saat preview ini dipakai tanpa login asli.
$simulated_role = ($_GET['role'] ?? 'admin') === 'kontraktor' ? 'kontraktor' : 'admin';
$_SESSION['user_id'] = 999999;
$_SESSION['role'] = $simulated_role;
$_SESSION['nama_lengkap'] = $simulated_role === 'admin' ? 'Admin Dinas Perindustrian' : 'Yohanes Kambu';
$_SESSION['username'] = 'preview';
$_SESSION['kontraktor_id'] = null;

// Demo flash message baca-sekali: klik link -> set + redirect -> tampil SEKALI.
if (isset($_GET['set_flash'])) {
    $jenis_flash = in_array($_GET['set_flash'], ['success', 'error', 'warning'], true) ? $_GET['set_flash'] : 'success';
    $pesan_flash = [
        'success' => 'Data lokasi berhasil disimpan.',
        'error' => 'Gagal menghapus data: masih dirujuk aktivitas lain.',
        'warning' => 'Koordinat di luar perkiraan wilayah Kota Sorong — periksa kembali.',
    ][$jenis_flash];
    flash($jenis_flash, $pesan_flash);
    redirect('tests/ui_preview.php?view=' . $view . '&role=' . $simulated_role);
}

if ($view === 'dashboard') {
    $page_title = 'Dashboard';
    $layout = 'app';
    $active_menu = 'dashboard';
    require __DIR__ . '/../includes/header.php';
    $judul_dashboard = $simulated_role === 'admin' ? 'Dashboard — Admin Dinas Perindustrian' : 'Dashboard — CV Sorong Jaya Tambang';
    ?>
    <h1 class="app-main__title"><?= e($judul_dashboard) ?></h1>

    <div class="stat-grid">
      <div class="card stat-card">
        <p class="stat-card__label">Total Lokasi Tambang</p>
        <p class="stat-card__value">12</p>
      </div>
      <div class="card stat-card stat-card--berizin">
        <p class="stat-card__label">Berizin</p>
        <p class="stat-card__value">7</p>
      </div>
      <div class="card stat-card stat-card--tidak-berizin">
        <p class="stat-card__label">Tidak Berizin</p>
        <p class="stat-card__value">3</p>
      </div>
      <div class="card stat-card stat-card--proses">
        <p class="stat-card__label">Proses</p>
        <p class="stat-card__value">2</p>
      </div>
    </div>

    <div class="grid-2">
      <div class="card">
        <h2 class="card__title">Pratinjau Peta Persebaran Galian C</h2>
        <div class="map-shell" role="img" aria-label="Pratinjau peta persebaran lokasi tambang (placeholder, Leaflet dipasang di T-13)"></div>
      </div>
      <div class="card">
        <h2 class="card__title">Daftar Lokasi Tambang</h2>
        <div class="table-responsive">
          <table class="data-table">
            <caption class="sr-only">Daftar lokasi tambang dan status izinnya</caption>
            <thead>
              <tr><th scope="col">Nama Lokasi</th><th scope="col">Kontraktor</th><th scope="col">Status</th></tr>
            </thead>
            <tbody>
              <tr><td>Lokasi Klasaman</td><td>CV Sorong Jaya Tambang</td><td><span class="badge badge--berizin">Berizin</span></td></tr>
              <tr><td>Lokasi Matalamagi</td><td>PT Papua Mineral Sejahtera</td><td><span class="badge badge--tidak-berizin">Tidak Berizin</span></td></tr>
              <tr><td>Lokasi Mariat Pantai</td><td>CV Mitra Galian Sorong</td><td><span class="badge badge--proses">Proses</span></td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

// --- $view === 'components': katalog semua komponen T-04 untuk uji manual ---
$page_title = 'Katalog Komponen (T-04)';
$layout = 'app';
$active_menu = '';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="app-main__title">Katalog Komponen UI — T-04</h1>

<p>
  Pratinjau peran saat ini: <strong><?= e($simulated_role) ?></strong>.
  Ganti via <code>?role=admin</code> atau <code>?role=kontraktor</code>.
  Lihat juga <a href="<?= e(url('login.php')) ?>">halaman login</a> (asli, T-06) dan
  <a href="<?= e(url('tests/ui_preview.php?view=dashboard&role=' . $simulated_role)) ?>">tampilan dashboard</a> (masih pratinjau, dashboard.php asli menyusul T-08/T-20).
</p>

<section class="card">
  <h2 class="card__title">Flash message (baca sekali)</h2>
  <p>Klik salah satu, lalu <strong>muat ulang halaman secara manual</strong> — pesan harus hilang setelah tampil sekali.</p>
  <p>
    <a class="btn btn--secondary btn--sm" href="?view=components&role=<?= e($simulated_role) ?>&set_flash=success">Trigger sukses</a>
    <a class="btn btn--secondary btn--sm" href="?view=components&role=<?= e($simulated_role) ?>&set_flash=error">Trigger error</a>
    <a class="btn btn--secondary btn--sm" href="?view=components&role=<?= e($simulated_role) ?>&set_flash=warning">Trigger peringatan</a>
  </p>
</section>

<section class="card">
  <h2 class="card__title">Kartu statistik</h2>
  <div class="stat-grid">
    <div class="card stat-card"><p class="stat-card__label">Total Lokasi Tambang</p><p class="stat-card__value">12</p></div>
    <div class="card stat-card stat-card--berizin"><p class="stat-card__label">Berizin</p><p class="stat-card__value">7</p></div>
    <div class="card stat-card stat-card--tidak-berizin"><p class="stat-card__label">Tidak Berizin</p><p class="stat-card__value">3</p></div>
    <div class="card stat-card stat-card--proses"><p class="stat-card__label">Proses</p><p class="stat-card__value">2</p></div>
  </div>
</section>

<section class="card">
  <h2 class="card__title">Badge status</h2>
  <p>
    <span class="badge badge--berizin">Berizin</span>
    <span class="badge badge--tidak-berizin">Tidak Berizin</span>
    <span class="badge badge--proses">Proses</span>
  </p>
</section>

<section class="card">
  <h2 class="card__title">Tabel (coba persempit jendela &lt; 560px)</h2>
  <div class="table-responsive">
    <table class="data-table">
      <caption class="sr-only">Contoh tabel lokasi tambang</caption>
      <thead>
        <tr><th scope="col">Nama Lokasi</th><th scope="col">Distrik</th><th scope="col">Kontraktor</th><th scope="col">Material</th><th scope="col">Status</th><th scope="col">Aksi</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Lokasi Klasaman</td><td>Sorong Timur</td><td>CV Sorong Jaya Tambang</td><td>Pasir</td>
          <td><span class="badge badge--berizin">Berizin</span></td>
          <td class="data-table__actions">
            <a class="btn btn--secondary btn--sm" href="#">Ubah</a>
            <button type="button" class="btn btn--danger btn--sm" data-dialog-open="dialog-hapus-contoh">Hapus</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <h2 class="card__title">Tombol</h2>
  <p>
    <button type="button" class="btn btn--primary">Simpan</button>
    <button type="button" class="btn btn--secondary">Batal</button>
    <button type="button" class="btn btn--danger">Hapus</button>
    <button type="button" class="btn btn--primary" disabled>Nonaktif</button>
  </p>
</section>

<section class="card">
  <h2 class="card__title">Form dengan error inline</h2>
  <form method="post" action="#" novalidate>
    <?= csrf_field() ?>
    <div class="field field--invalid">
      <label class="field__label" for="contoh-nama">Nama Lokasi <span class="field__required">*</span></label>
      <input class="input" type="text" id="contoh-nama" name="nama" aria-describedby="contoh-nama-error" aria-invalid="true">
      <p class="field__error" id="contoh-nama-error">Nama lokasi wajib diisi.</p>
    </div>
    <div class="field">
      <label class="field__label" for="contoh-material">Jenis Material</label>
      <select class="select" id="contoh-material" name="material">
        <option>Pasir</option>
        <option>Batu</option>
      </select>
      <p class="field__hint">Pilih salah satu material utama lokasi ini.</p>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn--primary">Simpan Data</button>
      <button type="button" class="btn btn--secondary">Batal</button>
    </div>
  </form>
</section>

<section class="card">
  <h2 class="card__title">Modal konfirmasi hapus (&lt;dialog&gt;)</h2>
  <button type="button" class="btn btn--danger" data-dialog-open="dialog-hapus-contoh">Buka modal hapus</button>
  <dialog class="modal" id="dialog-hapus-contoh" aria-labelledby="dialog-hapus-contoh-title">
    <form method="dialog">
      <div class="modal__body">
        <h2 class="modal__title" id="dialog-hapus-contoh-title">Hapus lokasi ini?</h2>
        <p>Tindakan ini tidak dapat dibatalkan. Aktivitas dan foto terkait lokasi ini juga akan terhapus.</p>
        <div class="modal__actions">
          <button type="submit" value="batal" class="btn btn--secondary" formnovalidate>Batal</button>
          <button type="submit" value="hapus" class="btn btn--danger" formnovalidate>Ya, Hapus</button>
        </div>
      </div>
    </form>
  </dialog>
</section>

<section class="card">
  <h2 class="card__title">Skeleton loader</h2>
  <div class="skeleton skeleton-card"></div>
  <div class="skeleton skeleton-text"></div>
  <div class="skeleton skeleton-text"></div>
</section>

<?php
require __DIR__ . '/../includes/footer.php';
