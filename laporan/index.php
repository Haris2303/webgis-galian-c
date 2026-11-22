<?php

declare(strict_types=1);

/**
 * laporan/index.php — laporan monitoring per periode, khusus Admin (FR-22;
 * PRD bagian 5, 9 #7; T-22). Filter periode (default bulan berjalan), rekap
 * per lokasi (status izin, jumlah aktivitas, aktivitas terakhir) lewat SATU
 * query (repo_lokasi_list_for_laporan(), T-22). Tombol Cetak memanggil
 * window.print() (assets/js/laporan.js); layout cetak A4 di assets/css/print.css.
 * "Unduh CSV" mengarah ke laporan/unduh.php (T-23).
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

/**
 * Teks "Periode: ..." di kop laporan. Satu sisi null berarti rentang TERBUKA
 * sebelah (pengguna hanya mengisi satu dari dua kolom "Dari"/"Sampai" di
 * dialog filter) -- harus tetap tampil dengan jelas, bukan ikut dipaksa jadi
 * tanggal konkret seperti query (lihat repo_lokasi_list_for_laporan()).
 */
function laporan_format_periode(?string $mulai, ?string $akhir): string
{
    if ($mulai !== null && $akhir !== null) {
        return laporan_format_tanggal_id($mulai) . ' s.d. ' . laporan_format_tanggal_id($akhir);
    }
    if ($mulai !== null) {
        return 'Sejak ' . laporan_format_tanggal_id($mulai);
    }

    return 's.d. ' . laporan_format_tanggal_id((string) $akhir);
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
    flash('warning', 'Rentang tanggal "Dari" dan "Sampai" terbalik, urutannya otomatis ditukar.');
}

$daftar_lokasi = repo_lokasi_list_for_laporan($tanggal_mulai, $tanggal_akhir);
$total_aktivitas_periode = array_sum(array_column($daftar_lokasi, 'jumlah_aktivitas'));

// Ringkasan status izin (standar laporan dinas: rekapitulasi singkat sebelum
// rincian per lokasi) -- dihitung dari $daftar_lokasi yang SAMA, bukan query
// terpisah, supaya angkanya selalu konsisten dengan tabel rincian di bawahnya.
$jumlah_berizin = 0;
$jumlah_tidak_berizin = 0;
$jumlah_proses = 0;
foreach ($daftar_lokasi as $baris_ringkasan) {
    if ($baris_ringkasan['status_izin'] === 'berizin') {
        $jumlah_berizin++;
    } elseif ($baris_ringkasan['status_izin'] === 'tidak_berizin') {
        $jumlah_tidak_berizin++;
    } elseif ($baris_ringkasan['status_izin'] === 'proses') {
        $jumlah_proses++;
    }
}

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
<div class="ds-page no-print">
  <div class="ds-page-header">
    <h1 class="ds-page-header__title">Laporan Monitoring</h1>
  </div>

  <div class="ds-list-toolbar">
    <span><?= e((string) count($daftar_lokasi)) ?> lokasi, total <?= e((string) $total_aktivitas_periode) ?> aktivitas pada periode ini.</span>
    <div class="ds-inline-form">
      <button type="button" class="ds-btn ds-btn--primary" id="btn-cetak">Cetak</button>
      <a class="ds-btn ds-btn--secondary" href="<?= e(url('laporan/unduh.php?' . http_build_query(['tanggal_mulai' => $tanggal_mulai, 'tanggal_akhir' => $tanggal_akhir]))) ?>">Unduh CSV</a>
    </div>
  </div>
</div>

<article class="laporan-dokumen">
  <div class="laporan-kop">
    <p class="laporan-kop__pemerintah">Pemerintah Kota Sorong</p>
    <p class="laporan-kop__instansi">Dinas Perindustrian Kota Sorong</p>
  </div>

  <div class="laporan-judul">
    <h2 class="laporan-judul__teks">Laporan Monitoring Kegiatan Pertambangan Galian C</h2>
    <p class="laporan-judul__periode">
      Periode: <?= e(laporan_format_periode($tanggal_mulai, $tanggal_akhir)) ?>
    </p>
  </div>

  <div class="laporan-ringkasan">
    <p class="laporan-ringkasan__judul">Ringkasan</p>
    <table class="laporan-ringkasan__tabel">
      <tbody>
        <tr>
          <th scope="row">Jumlah Lokasi Terpantau</th>
          <td><?= e((string) count($daftar_lokasi)) ?></td>
        </tr>
        <tr>
          <th scope="row">Total Aktivitas Tercatat</th>
          <td><?= e((string) $total_aktivitas_periode) ?></td>
        </tr>
        <tr>
          <th scope="row">Lokasi Berizin</th>
          <td><?= e((string) $jumlah_berizin) ?></td>
        </tr>
        <tr>
          <th scope="row">Lokasi Tidak Berizin</th>
          <td><?= e((string) $jumlah_tidak_berizin) ?></td>
        </tr>
        <tr>
          <th scope="row">Lokasi Dalam Proses Izin</th>
          <td><?= e((string) $jumlah_proses) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="table-responsive">
    <table class="data-table laporan-tabel">
      <caption class="sr-only">Rekap lokasi tambang periode <?= e(laporan_format_periode($tanggal_mulai, $tanggal_akhir)) ?></caption>
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
              <?= e((string) $row['aktivitas_terakhir_tanggal']) ?>:
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

  <p class="laporan-kop__cetak">Dicetak pada: <?= e($waktu_cetak) ?></p>

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

<button type="button" class="ds-fab<?= ($tanggal_mulai !== date('Y-m-01') || $tanggal_akhir !== date('Y-m-t')) ? ' ds-fab--active' : '' ?> no-print" data-dialog-open="dialog-filter-laporan" aria-haspopup="dialog">
  <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
    <path d="M3 4h14l-5.2 6.5v5L8.2 17v-6.5L3 4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
  </svg>
  Periode
</button>

<dialog class="modal no-print" id="dialog-filter-laporan" aria-labelledby="dialog-filter-laporan-title">
  <div class="modal__body">
    <h2 class="modal__title" id="dialog-filter-laporan-title">Ubah Periode Laporan</h2>
    <form method="get" action="<?= e(url('laporan/index.php')) ?>" id="form-filter-laporan">
      <div class="ds-grid-2">
        <div class="ds-field">
          <label class="ds-field__label" for="tanggal_mulai">Dari Tanggal</label>
          <input class="ds-input" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= e($tanggal_mulai) ?>">
        </div>
        <div class="ds-field">
          <label class="ds-field__label" for="tanggal_akhir">Sampai Tanggal</label>
          <input class="ds-input" type="date" id="tanggal_akhir" name="tanggal_akhir" value="<?= e($tanggal_akhir) ?>">
        </div>
      </div>
      <p class="ds-field__hint">Kosongkan keduanya untuk kembali ke periode bulan berjalan.</p>
    </form>
    <div class="ds-form-actions">
      <button type="submit" form="form-filter-laporan" class="ds-btn ds-btn--primary">Terapkan</button>
      <a class="ds-btn ds-btn--secondary" href="<?= e(url('laporan/index.php')) ?>">Reset</a>
      <form method="dialog"><button type="submit" class="ds-btn ds-btn--secondary" formnovalidate>Tutup</button></form>
    </div>
  </div>
</dialog>

<?php
$extra_scripts = [
    '<script src="' . e(asset('assets/js/laporan.js')) . '"></script>',
];
require __DIR__ . '/../includes/footer.php';
