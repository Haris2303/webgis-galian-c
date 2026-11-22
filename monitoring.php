<?php

declare(strict_types=1);

/**
 * monitoring.php — daftar aktivitas terbaru semua lokasi, khusus Admin
 * (FR-21; PRD bagian 5, 9; T-21). Filter lokasi/kontraktor/status/rentang
 * tanggal (default 30 hari), SATU query dengan JOIN + subquery agregat
 * (repo_aktivitas_list_for_monitoring(), includes/repo/aktivitas.php) supaya
 * tidak ada pola N+1 -- lihat debug jumlah query di bagian bawah halaman
 * (APP_ENV=dev saja).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/repo/aktivitas.php';
require_once __DIR__ . '/includes/repo/lokasi.php';
require_once __DIR__ . '/includes/repo/kontraktor.php';

require_role('admin');

/** "Y-m-d" valid (format DAN tanggal kalender nyata), atau null bila kosong/tidak valid -- input tidak valid diperlakukan SAMA dengan kosong (edge case wajib T-21), bukan error keras. */
function monitoring_valid_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $value);

    return ($parsed !== false && $parsed->format('Y-m-d') === $value) ? $value : null;
}

/** Querystring filter saat ini (TANPA page/sort/dir), dipakai membangun link paginasi & sort konsisten -- menyimpan tanggal EFEKTIF (bukan input mentah), supaya rentang yang sedang berlaku tetap jelas saat pindah halaman/urutan. */
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

// Edge case wajib T-21: rentang kosong ATAU terbalik. Input tidak valid (format
// salah, 31 Februari, dst.) diperlakukan sama dengan kosong -- tidak pernah
// melempar error ke pengguna.
$tanggal_mulai = monitoring_valid_date((string) ($_GET['tanggal_mulai'] ?? ''));
$tanggal_akhir = monitoring_valid_date((string) ($_GET['tanggal_akhir'] ?? ''));

$rentang_tertukar = false;
if ($tanggal_mulai !== null && $tanggal_akhir !== null && $tanggal_mulai > $tanggal_akhir) {
    [$tanggal_mulai, $tanggal_akhir] = [$tanggal_akhir, $tanggal_mulai];
    $rentang_tertukar = true;
}

$hari_ini = date('Y-m-d');
if ($tanggal_mulai === null && $tanggal_akhir === null) {
    // Spesifikasi T-21: default 30 hari -- HANYA saat KEDUA batas kosong.
    // Satu batas diisi (mis. hanya "Dari Tanggal") tetap dihormati sebagai
    // rentang terbuka sebelah, bukan dipaksa jadi 30 hari (Keputusan T-21).
    $tanggal_akhir = $hari_ini;
    $tanggal_mulai = date('Y-m-d', strtotime($hari_ini . ' -30 days'));
}

if ($rentang_tertukar) {
    flash('warning', 'Rentang tanggal "Dari" dan "Sampai" terbalik — urutannya otomatis ditukar.');
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

// Dropdown filter: semua lokasi/kontraktor (Admin, tanpa scope) -- paginate()
// di sini TETAP 2 query (COUNT+SELECT) terlepas dari jumlah lokasi/kontraktor,
// tidak terkait jumlah BARIS AKTIVITAS yang jadi fokus verifikasi T-21.
[$daftar_lokasi] = repo_lokasi_list([], 1, 500, 'nama_lokasi', 'asc');
[$daftar_kontraktor] = repo_kontraktor_list(1, 200, null);

$ambang_tanpa_aktivitas = date('Y-m-d', strtotime($hari_ini . ' -30 days'));

$page_title = 'Monitoring Aktivitas';
$layout = 'app';
$active_menu = 'monitoring';
require __DIR__ . '/includes/header.php';
?>
<h1 class="app-main__title">Monitoring Aktivitas Tambang</h1>

<div class="card">
  <form method="get" action="<?= e(url('monitoring.php')) ?>">
    <div class="grid-2">
      <div class="field">
        <label class="field__label" for="lokasi_id">Lokasi</label>
        <select class="select" id="lokasi_id" name="lokasi_id">
          <option value="">Semua lokasi</option>
          <?php foreach ($daftar_lokasi as $l): ?>
          <option value="<?= e((string) $l['id']) ?>" <?php if ($lokasi_id === (int) $l['id']): ?>selected<?php endif; ?>><?= e($l['nama_lokasi']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="kontraktor_id">Kontraktor</label>
        <select class="select" id="kontraktor_id" name="kontraktor_id">
          <option value="">Semua kontraktor</option>
          <?php foreach ($daftar_kontraktor as $k): ?>
          <option value="<?= e((string) $k['id']) ?>" <?php if ($kontraktor_id === (int) $k['id']): ?>selected<?php endif; ?>><?= e($k['nama_perusahaan']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label class="field__label" for="status_aktivitas">Status Aktivitas</label>
        <select class="select" id="status_aktivitas" name="status_aktivitas">
          <option value="">Semua status</option>
          <option value="aktif" <?php if ($status_aktivitas === 'aktif'): ?>selected<?php endif; ?>>Aktif</option>
          <option value="berhenti_sementara" <?php if ($status_aktivitas === 'berhenti_sementara'): ?>selected<?php endif; ?>>Berhenti Sementara</option>
          <option value="selesai" <?php if ($status_aktivitas === 'selesai'): ?>selected<?php endif; ?>>Selesai</option>
        </select>
      </div>
      <div class="field">
        <span class="field__label">Rentang Tanggal</span>
        <div class="grid-2">
          <div class="field">
            <label class="field__label" for="tanggal_mulai">Dari</label>
            <input class="input" type="date" id="tanggal_mulai" name="tanggal_mulai" value="<?= e((string) $tanggal_mulai) ?>">
          </div>
          <div class="field">
            <label class="field__label" for="tanggal_akhir">Sampai</label>
            <input class="input" type="date" id="tanggal_akhir" name="tanggal_akhir" value="<?= e((string) $tanggal_akhir) ?>">
          </div>
        </div>
        <p class="field__hint">Kosongkan keduanya untuk kembali ke rentang 30 hari terakhir.</p>
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn--primary">Terapkan Filter</button>
      <a class="btn btn--secondary" href="<?= e(url('monitoring.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="list-toolbar">
  <span><?= e((string) $total) ?> aktivitas ditemukan (<?= e($tanggal_mulai) ?> s.d. <?= e($tanggal_akhir) ?>)</span>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="data-table">
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
            <br><span class="badge badge--perlu-pengawasan">Tanpa Aktivitas &gt;30 Hari</span>
            <?php endif; ?>
          </td>
          <td><?= e($row['kontraktor_nama']) ?></td>
          <td><?= e($row['jenis_kegiatan']) ?></td>
          <td>
            <?php if ($row['status_aktivitas'] === 'aktif'): ?>
            <span class="badge badge--berizin">Aktif</span>
            <?php elseif ($row['status_aktivitas'] === 'selesai'): ?>
            <span class="badge badge--proses">Selesai</span>
            <?php else: ?>
            <span class="badge badge--tidak-berizin">Berhenti Sementara</span>
            <?php endif; ?>
          </td>
          <td><?= (int) $row['jumlah_foto'] > 0 ? e((string) $row['jumlah_foto']) . ' foto' : '—' ?></td>
          <td class="data-table__actions">
            <a class="btn btn--secondary btn--sm" href="<?= e(url('peta.php?lokasi=' . $row['lokasi_id'])) ?>">Lihat di Peta</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($total_halaman > 1): ?>
<nav class="pagination" aria-label="Navigasi halaman">
  <?php if ($page > 1): ?>
    <a class="pagination__link" href="<?= e(url(monitoring_list_url($filter_query, ['page' => $page - 1, 'sort' => $sort, 'dir' => $dir]))) ?>">&larr; Sebelumnya</a>
  <?php else: ?>
    <span class="pagination__link" aria-disabled="true">&larr; Sebelumnya</span>
  <?php endif; ?>
  <span>Halaman <?= e((string) $page) ?> dari <?= e((string) $total_halaman) ?></span>
  <?php if ($page < $total_halaman): ?>
    <a class="pagination__link" href="<?= e(url(monitoring_list_url($filter_query, ['page' => $page + 1, 'sort' => $sort, 'dir' => $dir]))) ?>">Berikutnya &rarr;</a>
  <?php else: ?>
    <span class="pagination__link" aria-disabled="true">Berikutnya &rarr;</span>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php if (APP_ENV === 'dev'): ?>
<!-- [debug] T-21: jumlah query database pada halaman ini (harus tetap konstan berapa pun $total aktivitas) -->
<p class="u-mt-4" style="color:var(--color-text-muted);font-size:var(--font-size-sm)">
  [debug] Jumlah query database pada halaman ini: <?= e((string) db_query_count()) ?>
</p>
<?php endif; ?>

<?php
require __DIR__ . '/includes/footer.php';
