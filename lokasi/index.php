<?php

declare(strict_types=1);

/**
 * lokasi/index.php — daftar lokasi tambang (FR-07/FR-10, T-09). Khusus Admin.
 * Tanpa peta picker (menyusul T-10) -- murni tabel, filter, pagination.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/repo/material.php';

require_role('admin');

$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$kontraktor_id = (int) ($_GET['kontraktor_id'] ?? 0);
$material_id = (int) ($_GET['material_id'] ?? 0);
$sort = (string) ($_GET['sort'] ?? 'nama_lokasi');
$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 10;

/** Querystring filter saat ini (TANPA page/sort/dir), dipakai membangun link paginasi & sort yang konsisten. */
function lokasi_filter_query(string $search, string $status, int $kontraktor_id, int $material_id): array
{
    return array_filter([
        'q' => $search !== '' ? $search : null,
        'status' => $status !== '' ? $status : null,
        'kontraktor_id' => $kontraktor_id > 0 ? $kontraktor_id : null,
        'material_id' => $material_id > 0 ? $material_id : null,
    ], static fn ($v) => $v !== null);
}

function lokasi_list_url(array $filter_query, array $extra = []): string
{
    $query = $filter_query + $extra;

    return 'lokasi/index.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

$filter_query = lokasi_filter_query($search, $status, $kontraktor_id, $material_id);

[$daftar, $total] = repo_lokasi_list(
    ['search' => $search, 'status_izin' => $status, 'kontraktor_id' => $kontraktor_id, 'material_id' => $material_id],
    $page,
    $per_page,
    $sort,
    $dir
);
$total_halaman = max(1, (int) ceil($total / $per_page));

[$daftar_kontraktor] = repo_kontraktor_list(1, 200, null);
$daftar_material = repo_material_list();
$hari_ini = date('Y-m-d');

$page_title = 'Data Lokasi Tambang';
$layout = 'app';
$active_menu = 'lokasi';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="app-main__title">Data Lokasi Tambang</h1>

<div class="card">
  <form method="get" action="<?= e(url('lokasi/index.php')) ?>">
    <div class="grid-2">
      <div class="field">
        <label class="field__label" for="q">Cari (nama lokasi / kelurahan)</label>
        <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>">
      </div>
      <div class="field">
        <label class="field__label" for="status">Status Izin</label>
        <select class="select" id="status" name="status">
          <option value="">Semua status</option>
          <option value="berizin" <?php if ($status === 'berizin'): ?>selected<?php endif; ?>>Berizin</option>
          <option value="tidak_berizin" <?php if ($status === 'tidak_berizin'): ?>selected<?php endif; ?>>Tidak Berizin</option>
          <option value="proses" <?php if ($status === 'proses'): ?>selected<?php endif; ?>>Proses</option>
        </select>
      </div>
    </div>
    <div class="grid-2">
      <div class="field">
        <label class="field__label" for="kontraktor_id">Kontraktor</label>
        <select class="select" id="kontraktor_id" name="kontraktor_id">
          <option value="">Semua kontraktor</option>
          <?php foreach ($daftar_kontraktor as $k): ?>
          <option value="<?= e((string) $k['id']) ?>" <?php if ($kontraktor_id === (int) $k['id']): ?>selected<?php endif; ?>><?= e($k['nama_perusahaan']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="material_id">Jenis Material</label>
        <select class="select" id="material_id" name="material_id">
          <option value="">Semua material</option>
          <?php foreach ($daftar_material as $m): ?>
          <option value="<?= e((string) $m['id']) ?>" <?php if ($material_id === (int) $m['id']): ?>selected<?php endif; ?>><?= e($m['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn--primary">Terapkan Filter</button>
      <a class="btn btn--secondary" href="<?= e(url('lokasi/index.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="list-toolbar">
  <span><?= e((string) $total) ?> lokasi ditemukan</span>
  <a class="btn btn--primary" href="<?= e(url('lokasi/form.php')) ?>">+ Tambah Lokasi</a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <caption class="sr-only">Daftar lokasi tambang</caption>
      <thead>
        <tr>
          <?php
            $kolom_sortable = ['nama_lokasi' => 'Nama Lokasi', 'kelurahan' => 'Kelurahan', 'status_izin' => 'Status', 'tanggal_berakhir_izin' => 'Berakhir Izin'];
          ?>
          <?php foreach ($kolom_sortable as $kolom => $label): ?>
          <?php $arah_berikutnya = ($sort === $kolom && $dir === 'asc') ? 'desc' : 'asc'; ?>
          <th scope="col">
            <a href="<?= e(url(lokasi_list_url($filter_query, ['sort' => $kolom, 'dir' => $arah_berikutnya]))) ?>">
              <?= e($label) ?>
              <?php if ($sort === $kolom && $dir === 'asc'): ?> ▲<?php elseif ($sort === $kolom): ?> ▼<?php endif; ?>
            </a>
          </th>
          <?php endforeach; ?>
          <th scope="col">Kontraktor</th>
          <th scope="col">Material</th>
          <th scope="col">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($daftar === []): ?>
        <tr><td colspan="7">Belum ada data lokasi yang cocok dengan filter ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($daftar as $row): ?>
        <?php $kedaluwarsa = $row['status_izin'] === 'berizin' && !empty($row['tanggal_berakhir_izin']) && $row['tanggal_berakhir_izin'] < $hari_ini; ?>
        <tr>
          <td><?= e($row['nama_lokasi']) ?></td>
          <td><?= e($row['kelurahan'] ?? '—') ?></td>
          <td>
            <?php if ($row['status_izin'] === 'berizin'): ?>
            <span class="badge badge--berizin">Berizin</span>
            <?php elseif ($row['status_izin'] === 'tidak_berizin'): ?>
            <span class="badge badge--tidak-berizin">Tidak Berizin</span>
            <?php else: ?>
            <span class="badge badge--proses">Proses</span>
            <?php endif; ?>
            <?php if ($kedaluwarsa): ?>
            <span class="badge badge--kedaluwarsa">Izin Kedaluwarsa</span>
            <?php endif; ?>
          </td>
          <td><?= e($row['tanggal_berakhir_izin'] ?? '—') ?></td>
          <td><?= e($row['kontraktor_nama']) ?></td>
          <td><?= e($row['material_nama']) ?></td>
          <td class="data-table__actions">
            <a class="btn btn--secondary btn--sm" href="<?= e(url('lokasi/form.php?id=' . $row['id'])) ?>">Ubah</a>
            <button type="button" class="btn btn--danger btn--sm" data-dialog-open="dialog-hapus-<?= e((string) $row['id']) ?>">Hapus</button>
          </td>
        </tr>

        <dialog class="modal" id="dialog-hapus-<?= e((string) $row['id']) ?>" aria-labelledby="dialog-hapus-<?= e((string) $row['id']) ?>-title">
          <div class="modal__body">
            <h2 class="modal__title" id="dialog-hapus-<?= e((string) $row['id']) ?>-title">Hapus "<?= e($row['nama_lokasi']) ?>"?</h2>
            <p>Tindakan ini tidak dapat dibatalkan. Semua aktivitas dan foto dokumentasi milik lokasi ini akan ikut terhapus permanen.</p>
            <div class="modal__actions">
              <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Batal</button></form>
              <form method="post" action="<?= e(url('lokasi/hapus.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                <input type="hidden" name="back" value="<?= e(lokasi_list_url($filter_query, ['page' => $page])) ?>">
                <button type="submit" class="btn btn--danger">Ya, Hapus</button>
              </form>
            </div>
          </div>
        </dialog>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($total_halaman > 1): ?>
<nav class="pagination" aria-label="Navigasi halaman">
  <?php if ($page > 1): ?>
    <a class="pagination__link" href="<?= e(url(lokasi_list_url($filter_query, ['page' => $page - 1, 'sort' => $sort, 'dir' => $dir]))) ?>">&larr; Sebelumnya</a>
  <?php else: ?>
    <span class="pagination__link" aria-disabled="true">&larr; Sebelumnya</span>
  <?php endif; ?>
  <span>Halaman <?= e((string) $page) ?> dari <?= e((string) $total_halaman) ?></span>
  <?php if ($page < $total_halaman): ?>
    <a class="pagination__link" href="<?= e(url(lokasi_list_url($filter_query, ['page' => $page + 1, 'sort' => $sort, 'dir' => $dir]))) ?>">Berikutnya &rarr;</a>
  <?php else: ?>
    <span class="pagination__link" aria-disabled="true">Berikutnya &rarr;</span>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php
require __DIR__ . '/../includes/footer.php';
