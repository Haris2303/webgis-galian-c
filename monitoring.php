<?php

declare(strict_types=1);

// Daftar aktivitas terbaru semua lokasi, khusus Admin; filter lokasi/kontraktor/status/rentang tanggal (default 30 hari).

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/repo/aktivitas.php';
require_once __DIR__ . '/includes/repo/lokasi.php';
require_once __DIR__ . '/includes/repo/kontraktor.php';

require_role('admin');

// Tanggal tidak valid diperlakukan sama dengan kosong, bukan error keras.
function monitoring_valid_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $value);

    return ($parsed !== false && $parsed->format('Y-m-d') === $value) ? $value : null;
}

// Menyimpan tanggal efektif (bukan input mentah) agar rentang tetap jelas saat pindah halaman/urutan.
function monitoring_filter_query(int $lokasi_id, int $kontraktor_id, string $status, ?string $tanggal_mulai, ?string $tanggal_akhir): array
{
    return array_filter([
        'lokasi_id' => $lokasi_id > 0 ? $lokasi_id : null,
        'kontraktor_id' => $kontraktor_id > 0 ? $kontraktor_id : null,
        'status_aktivitas' => $status !== '' ? $status : null,
        'tanggal_mulai' => $tanggal_mulai,
        'tanggal_akhir' => $tanggal_akhir,
    ], static fn ($v) => $v !== null);
}

function monitoring_list_url(array $filter_query, array $extra = []): string
{
    $query = $filter_query + $extra;

    return 'monitoring.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

$lokasi_id = (int) ($_GET['lokasi_id'] ?? 0);
$kontraktor_id = (int) ($_GET['kontraktor_id'] ?? 0);
$status_aktivitas = (string) ($_GET['status_aktivitas'] ?? '');
$sort = (string) ($_GET['sort'] ?? 'tanggal_aktivitas');
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;

$tanggal_mulai = monitoring_valid_date((string) ($_GET['tanggal_mulai'] ?? ''));
$tanggal_akhir = monitoring_valid_date((string) ($_GET['tanggal_akhir'] ?? ''));

$rentang_tertukar = false;
if ($tanggal_mulai !== null && $tanggal_akhir !== null && $tanggal_mulai > $tanggal_akhir) {
    [$tanggal_mulai, $tanggal_akhir] = [$tanggal_akhir, $tanggal_mulai];
    $rentang_tertukar = true;
}

$hari_ini = date('Y-m-d');
if ($tanggal_mulai === null && $tanggal_akhir === null) {
    // Default 30 hari hanya saat kedua batas kosong; satu batas diisi tetap dihormati sebagai rentang terbuka sebelah.
    $tanggal_akhir = $hari_ini;
    $tanggal_mulai = date('Y-m-d', strtotime($hari_ini . ' -30 days'));
}

if ($rentang_tertukar) {
    flash('warning', 'Rentang tanggal "Dari" dan "Sampai" terbalik, urutannya otomatis ditukar.');
}

$filter_query = monitoring_filter_query($lokasi_id, $kontraktor_id, $status_aktivitas, $tanggal_mulai, $tanggal_akhir);

[$daftar, $total] = repo_aktivitas_list_for_monitoring(
    [
        'lokasi_id' => $lokasi_id,
        'kontraktor_id' => $kontraktor_id,
        'status_aktivitas' => $status_aktivitas,
        'tanggal_mulai' => $tanggal_mulai,
        'tanggal_akhir' => $tanggal_akhir,
    ],
    $page,
    $per_page,
    $sort,
    $dir
);
$total_halaman = max(1, (int) ceil($total / $per_page));

[$daftar_lokasi] = repo_lokasi_list([], 1, 500, 'nama_lokasi', 'asc');
[$daftar_kontraktor] = repo_kontraktor_list(1, 200, null);

$ambang_tanpa_aktivitas = date('Y-m-d', strtotime($hari_ini . ' -30 days'));

$page_title = 'Monitoring Aktivitas';
$layout = 'app';
$active_menu = 'monitoring';
// Leaflet dimuat di sini karena panel detail aktivitas menampilkan peta kecil lokasinya.
$extra_head = [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
];
require __DIR__ . '/includes/header.php';
?>
<div class="ds-page">
  <div class="ds-page-header">
    <h1 class="ds-page-header__title">Monitoring Aktivitas Tambang</h1>
  </div>

  <div class="ds-list-toolbar">
    <span><?= e((string) $total) ?> aktivitas ditemukan (<?= e($tanggal_mulai) ?> s.d. <?= e($tanggal_akhir) ?>)</span>
  </div>

  <div class="ds-card">
    <div class="ds-table-responsive">
      <table class="ds-table">
        <caption class="sr-only">Daftar aktivitas tambang terbaru semua lokasi</caption>
        <thead>
          <tr>
            <?php
              $kolom_sortable = ['tanggal_aktivitas' => 'Tanggal', 'nama_lokasi' => 'Lokasi', 'kontraktor_nama' => 'Kontraktor', 'status_aktivitas' => 'Status'];
            ?>
            <?php foreach ($kolom_sortable as $kolom => $label): ?>
            <?php $arah_berikutnya = ($sort === $kolom && $dir === 'asc') ? 'desc' : 'asc'; ?>
            <th scope="col">
              <a href="<?= e(url(monitoring_list_url($filter_query, ['sort' => $kolom, 'dir' => $arah_berikutnya]))) ?>">
                <?= e($label) ?>
                <?php if ($sort === $kolom && $dir === 'asc'): ?> ▲<?php elseif ($sort === $kolom): ?> ▼<?php endif; ?>
              </a>
            </th>
            <?php endforeach; ?>
            <th scope="col">Kegiatan</th>
            <th scope="col">Foto</th>
            <th scope="col">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($daftar === []): ?>
          <tr><td colspan="7">Tidak ada aktivitas yang cocok dengan filter ini.</td></tr>
          <?php endif; ?>
          <?php foreach ($daftar as $row): ?>
          <?php $tanpa_aktivitas_lama = ($row['lokasi_aktivitas_terakhir'] ?? $hari_ini) < $ambang_tanpa_aktivitas; ?>
          <tr>
            <td><?= e((string) $row['tanggal_aktivitas']) ?></td>
            <td>
              <?= e($row['nama_lokasi']) ?>
              <?php if ($tanpa_aktivitas_lama): ?>
              <br><span class="ds-badge ds-badge--perlu-pengawasan">Tanpa Aktivitas &gt;30 Hari</span>
              <?php endif; ?>
            </td>
            <td><?= e($row['kontraktor_nama']) ?></td>
            <td><?= e($row['jenis_kegiatan']) ?></td>
            <td>
              <?php if ($row['status_aktivitas'] === 'aktif'): ?>
              <span class="ds-badge ds-badge--berizin">Aktif</span>
              <?php elseif ($row['status_aktivitas'] === 'selesai'): ?>
              <span class="ds-badge ds-badge--proses">Selesai</span>
              <?php else: ?>
              <span class="ds-badge ds-badge--tidak-berizin">Berhenti Sementara</span>
              <?php endif; ?>
            </td>
            <td><?= (int) $row['jumlah_foto'] > 0 ? e((string) $row['jumlah_foto']) . ' foto' : 'Tidak ada' ?></td>
            <td class="data-table__actions">
              <button type="button" class="ds-btn ds-btn--secondary ds-btn--sm" data-aktivitas-detail="<?= e((string) $row['id']) ?>">Lihat</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($total_halaman > 1): ?>
  <nav class="ds-pagination" aria-label="Navigasi halaman">
    <?php if ($page > 1): ?>
      <a class="ds-pagination__link" href="<?= e(url(monitoring_list_url($filter_query, ['page' => $page - 1, 'sort' => $sort, 'dir' => $dir]))) ?>">&larr; Sebelumnya</a>
    <?php else: ?>
      <span class="ds-pagination__link" aria-disabled="true">&larr; Sebelumnya</span>
    <?php endif; ?>
    <span>Halaman <?= e((string) $page) ?> dari <?= e((string) $total_halaman) ?></span>
    <?php if ($page < $total_halaman): ?>
      <a class="ds-pagination__link" href="<?= e(url(monitoring_list_url($filter_query, ['page' => $page + 1, 'sort' => $sort, 'dir' => $dir]))) ?>">Berikutnya &rarr;</a>
    <?php else: ?>
      <span class="ds-pagination__link" aria-disabled="true">Berikutnya &rarr;</span>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php if (APP_ENV === 'dev'): ?>
  <p class="ds-field__hint">[debug] Jumlah query database pada halaman ini: <?= e((string) db_query_count()) ?></p>
  <?php endif; ?>
</div>

<button type="button" class="ds-fab<?= $filter_query !== [] ? ' ds-fab--active' : '' ?>" data-dialog-open="dialog-filter-monitoring" aria-haspopup="dialog">
  <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
    <path d="M3 4h14l-5.2 6.5v5L8.2 17v-6.5L3 4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
  </svg>
  Filter
  <?php if ($filter_query !== []): ?>
  <span class="ds-fab__count"><?= e((string) count($filter_query)) ?></span>
  <?php endif; ?>
</button>

<dialog class="modal modal--wide" id="dialog-filter-monitoring" aria-labelledby="dialog-filter-monitoring-title">
  <div class="modal__body">
    <h2 class="modal__title" id="dialog-filter-monitoring-title">Filter Monitoring</h2>
    <form method="get" action="<?= e(url('monitoring.php')) ?>" id="form-filter-monitoring">
      <div class="ds-grid-2">
        <div class="ds-field">
          <label class="ds-field__label" for="lokasi_id">Lokasi</label>
          <select class="ds-select" id="lokasi_id" name="lokasi_id">
            <option value="">Semua lokasi</option>
            <?php foreach ($daftar_lokasi as $l): ?>
            <option value="<?= e((string) $l['id']) ?>" <?php if ($lokasi_id === (int) $l['id']): ?>selected<?php endif; ?>><?= e($l['nama_lokasi']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ds-field">
          <label class="ds-field__label" for="kontraktor_id">Kontraktor</label>
          <select class="ds-select" id="kontraktor_id" name="kontraktor_id">
            <option value="">Semua kontraktor</option>
            <?php foreach ($daftar_kontraktor as $k): ?>
            <option value="<?= e((string) $k['id']) ?>" <?php if ($kontraktor_id === (int) $k['id']): ?>selected<?php endif; ?>><?= e($k['nama_perusahaan']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="ds-grid-2">
        <div class="ds-field">
          <label class="ds-field__label" for="status_aktivitas">Status Aktivitas</label>
          <select class="ds-select" id="status_aktivitas" name="status_aktivitas">
            <option value="">Semua status</option>
            <option value="aktif" <?php if ($status_aktivitas === 'aktif'): ?>selected<?php endif; ?>>Aktif</option>
            <option value="berhenti_sementara" <?php if ($status_aktivitas === 'berhenti_sementara'): ?>selected<?php endif; ?>>Berhenti Sementara</option>
            <option value="selesai" <?php if ($status_aktivitas === 'selesai'): ?>selected<?php endif; ?>>Selesai</option>
          </select>
        </div>
        <div class="ds-field">
          <label class="ds-field__label" for="tanggal_mulai">Dari Tanggal</label>
          <input class="ds-input" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= e((string) $tanggal_mulai) ?>">
        </div>
      </div>
      <div class="ds-grid-2">
        <div class="ds-field">
          <label class="ds-field__label" for="tanggal_akhir">Sampai Tanggal</label>
          <input class="ds-input" type="date" id="tanggal_akhir" name="tanggal_akhir" value="<?= e((string) $tanggal_akhir) ?>">
          <p class="ds-field__hint">Kosongkan keduanya untuk kembali ke rentang 30 hari terakhir.</p>
        </div>
      </div>
    </form>
    <div class="ds-form-actions">
      <button type="submit" form="form-filter-monitoring" class="ds-btn ds-btn--primary">Terapkan Filter</button>
      <a class="ds-btn ds-btn--secondary" href="<?= e(url('monitoring.php')) ?>">Reset</a>
      <form method="dialog"><button type="submit" class="ds-btn ds-btn--secondary" formnovalidate>Tutup</button></form>
    </div>
  </div>
</dialog>

<dialog class="modal modal--wide" id="dialog-detail-aktivitas" aria-labelledby="dialog-detail-aktivitas-title">
  <div class="modal__body">
    <h2 class="modal__title" id="dialog-detail-aktivitas-title">Detail Aktivitas</h2>
    <div id="detail-aktivitas-content">
      <p class="ds-field__hint">Memuat...</p>
    </div>
    <div id="detail-aktivitas-map" class="detail-aktivitas-map" hidden></div>
    <div class="modal__actions">
      <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Tutup</button></form>
      <a id="detail-aktivitas-ubah" class="ds-btn ds-btn--secondary" href="#">Ubah</a>
      <button type="button" id="detail-aktivitas-hapus-btn" class="ds-btn ds-btn--danger" data-dialog-open="dialog-hapus-aktivitas-detail">Hapus</button>
    </div>
  </div>
</dialog>

<dialog class="modal" id="dialog-hapus-aktivitas-detail" aria-labelledby="dialog-hapus-aktivitas-detail-title">
  <div class="modal__body">
    <h2 class="modal__title" id="dialog-hapus-aktivitas-detail-title">Hapus aktivitas ini?</h2>
    <p>Tindakan ini tidak dapat dibatalkan. Semua foto dokumentasi aktivitas ini akan ikut terhapus permanen.</p>
    <div class="modal__actions">
      <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Batal</button></form>
      <form method="post" action="<?= e(url('aktivitas/hapus.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="detail-aktivitas-hapus-id" value="">
        <input type="hidden" name="back" value="<?= e(url('monitoring.php')) ?>">
        <button type="submit" class="btn btn--danger">Ya, Hapus</button>
      </form>
    </div>
  </div>
</dialog>

<?php
$extra_scripts = [
    '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>',
    '<script src="' . e(asset('assets/js/monitoring-detail.js')) . '"></script>',
];
require __DIR__ . '/includes/footer.php';
