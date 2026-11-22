<?php

declare(strict_types=1);

/**
 * laporan/index.php — laporan monitoring per periode, khusus Admin (FR-22;
 * PRD bagian 5, 9 #7; T-22). Filter periode (default bulan berjalan), rekap
 * per lokasi (status izin, jumlah aktivitas, aktivitas terakhir) lewat SATU
 * query (repo_lokasi_list_for_laporan(), T-22). Tombol Cetak memanggil
 * window.print() (assets/js/laporan.js); layout cetak A4 di assets/css/print.css.
 * "Unduh" (CSV) menyusul T-23 -- SENGAJA belum ditambahkan di sini supaya
 * tidak ada tautan ke laporan/unduh.php yang belum ada (lihat Keputusan T-22).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

require_role('admin');

/** "Y-m-d" valid (format DAN tanggal kalender nyata), atau null bila kosong/tidak valid -- sama dengan monitoring.php (T-21): input tidak valid diperlakukan SAMA dengan kosong, bukan error keras. */
function laporan_valid_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $value);

    return ($parsed !== false && $parsed->format('Y-m-d') === $value) ? $value : null;
}

/** Format "Y-m-d" -> "22 November 2026" (bahasa Indonesia, tanpa ekstensi intl). */
function laporan_format_tanggal_id(string $ymd): string
{
    static $nama_bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    [$tahun, $bulan, $tanggal] = array_map('intval', explode('-', $ymd));

    return $tanggal . ' ' . $nama_bulan[$bulan] . ' ' . $tahun;
}

$tanggal_mulai = laporan_valid_date((string) ($_GET['tanggal_mulai'] ?? ''));
$tanggal_akhir = laporan_valid_date((string) ($_GET['tanggal_akhir'] ?? ''));

// Edge case wajib T-22 (sama dengan T-21): rentang terbalik -- ditukar + peringatan.
$rentang_tertukar = false;
if ($tanggal_mulai !== null && $tanggal_akhir !== null && $tanggal_mulai > $tanggal_akhir) {
    [$tanggal_mulai, $tanggal_akhir] = [$tanggal_akhir, $tanggal_mulai];
    $rentang_tertukar = true;
}

// Spesifikasi T-22: default BULAN BERJALAN (1 s.d. hari terakhir bulan ini),
// bukan "30 hari terakhir" seperti T-21 -- hanya saat KEDUA batas kosong/tidak
// valid. Satu batas diisi tetap dihormati sebagai rentang terbuka sebelah.
$hari_ini = date('Y-m-d');
if ($tanggal_mulai === null && $tanggal_akhir === null) {
    $tanggal_mulai = date('Y-m-01');
    $tanggal_akhir = date('Y-m-t');
}

if ($rentang_tertukar) {
    flash('warning', 'Rentang tanggal "Dari" dan "Sampai" terbalik — urutannya otomatis ditukar.');
}

$daftar_lokasi = repo_lokasi_list_for_laporan($tanggal_mulai, $tanggal_akhir);
$total_aktivitas_periode = array_sum(array_column($daftar_lokasi, 'jumlah_aktivitas'));

// Zona waktu tanggal cetak (edge case wajib T-22): date()/time() di sini SELALU
// Asia/Jayapura (WIT) karena date_default_timezone_set() global di
// includes/bootstrap.php -- bukan UTC atau timezone default OS server. Label
// "WIT" ditulis eksplisit supaya pembaca cetakan tidak perlu menebak zona waktunya.
$waktu_cetak = date('d/m/Y H:i') . ' WIT';

$page_title = 'Laporan Monitoring';
$layout = 'app';
$active_menu = 'laporan';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="app-main__title no-print">Laporan Monitoring</h1>

<div class="card no-print">
  <form method="get" action="<?= e(url('laporan/index.php')) ?>">
    <div class="grid-2">
      <div class="field">
        <label class="field__label" for="tanggal_mulai">Dari Tanggal</label>
        <input class="input" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= e($tanggal_mulai) ?>">
      </div>
      <div class="field">
        <label class="field__label" for="tanggal_akhir">Sampai Tanggal</label>
        <input class="input" type="date" id="tanggal_akhir" name="tanggal_akhir" value="<?= e($tanggal_akhir) ?>">
      </div>
    </div>
    <p class="field__hint">Kosongkan keduanya untuk kembali ke periode bulan berjalan.</p>
    <div class="form-actions">
      <button type="submit" class="btn btn--primary">Terapkan Filter</button>
      <a class="btn btn--secondary" href="<?= e(url('laporan/index.php')) ?>">Reset</a>
      <button type="button" class="btn btn--primary" id="btn-cetak">Cetak</button>
    </div>
  </form>
</div>

<article class="laporan-dokumen">
  <div class="laporan-kop">
    <h2 class="laporan-kop__judul">Laporan Monitoring Galian C</h2>
    <p class="laporan-kop__instansi">Dinas Perindustrian Kota Sorong</p>
    <p class="laporan-kop__periode">
      Periode: <?= e(laporan_format_tanggal_id($tanggal_mulai)) ?> s.d. <?= e(laporan_format_tanggal_id($tanggal_akhir)) ?>
    </p>
    <p class="laporan-kop__cetak">Dicetak pada: <?= e($waktu_cetak) ?></p>
  </div>

  <p class="no-print"><?= e((string) count($daftar_lokasi)) ?> lokasi, total <?= e((string) $total_aktivitas_periode) ?> aktivitas pada periode ini.</p>

  <div class="table-responsive">
    <table class="data-table laporan-tabel">
      <caption class="sr-only">Rekap lokasi tambang periode <?= e($tanggal_mulai) ?> sampai <?= e($tanggal_akhir) ?></caption>
      <thead>
        <tr>
          <th scope="col">Nama Lokasi</th>
          <th scope="col">Kontraktor</th>
          <th scope="col">Status Izin</th>
          <th scope="col">Jumlah Aktivitas</th>
          <th scope="col">Aktivitas Terakhir</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($daftar_lokasi === []): ?>
        <tr><td colspan="5">Belum ada data lokasi tambang.</td></tr>
        <?php endif; ?>
        <?php foreach ($daftar_lokasi as $row): ?>
        <tr>
          <td><?= e($row['nama_lokasi']) ?></td>
          <td><?= e($row['kontraktor_nama']) ?></td>
          <td>
            <?php if ($row['status_izin'] === 'berizin'): ?>
            <span class="badge badge--berizin">Berizin</span>
            <?php elseif ($row['status_izin'] === 'tidak_berizin'): ?>
            <span class="badge badge--tidak-berizin">Tidak Berizin</span>
            <?php elseif ($row['status_izin'] === 'proses'): ?>
            <span class="badge badge--proses">Proses</span>
            <?php else: ?>
            <span class="badge badge--tidak-diketahui">Status Tidak Diketahui</span>
            <?php endif; ?>
          </td>
          <td><?= e((string) $row['jumlah_aktivitas']) ?></td>
          <td>
            <?php if ($row['aktivitas_terakhir_tanggal'] !== null): ?>
              <?= e((string) $row['aktivitas_terakhir_tanggal']) ?> —
              <?php
                $label_status_terakhir = match ($row['aktivitas_terakhir_status']) {
                    'aktif' => 'Aktif',
                    'berhenti_sementara' => 'Berhenti Sementara',
                    'selesai' => 'Selesai',
                    default => 'Status Tidak Diketahui',
                };
              ?>
              <?= e($label_status_terakhir) ?>
            <?php else: ?>
              Tidak ada aktivitas pada periode ini
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="laporan-tandatangan">
    <p>Kota Sorong, <?= e(laporan_format_tanggal_id($hari_ini)) ?></p>
    <p><?= e(LAPORAN_PEJABAT_JABATAN) ?></p>
    <div class="laporan-tandatangan__spasi" aria-hidden="true"></div>
    <p class="laporan-tandatangan__nama"><?= e(LAPORAN_PEJABAT_NAMA) ?></p>
    <?php if (LAPORAN_PEJABAT_NIP !== ''): ?>
    <p>NIP. <?= e(LAPORAN_PEJABAT_NIP) ?></p>
    <?php endif; ?>
  </div>
</article>

<?php
$extra_scripts = [
    '<script src="' . e(asset('assets/js/laporan.js')) . '"></script>',
];
require __DIR__ . '/../includes/footer.php';
