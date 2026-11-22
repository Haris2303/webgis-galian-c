<?php

declare(strict_types=1);

/**
 * aktivitas/index.php — daftar aktivitas tambang (FR-13, FR-15, FR-16; T-17,
 * PRD bagian 2, 5, 7). Kontraktor: HANYA aktivitas di lokasi milik
 * perusahaannya sendiri (lewat kontraktor_id, bukan user_id individu --
 * lihat can_access_aktivitas() di includes/auth.php). Admin: semua aktivitas.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

$user = require_login();

$lokasi_id = (int) ($_GET['lokasi_id'] ?? 0);
$status_aktivitas = (string) ($_GET['status_aktivitas'] ?? '');
$tanggal_mulai = (string) ($_GET['tanggal_mulai'] ?? '');
$tanggal_akhir = (string) ($_GET['tanggal_akhir'] ?? '');
$sort = (string) ($_GET['sort'] ?? 'tanggal_aktivitas');
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 10;

/** Querystring filter saat ini (TANPA page/sort/dir), dipakai membangun link paginasi & sort konsisten. */
function aktivitas_filter_query(int $lokasi_id, string $status, string $tanggal_mulai, string $tanggal_akhir): array
{
    return array_filter([
        'lokasi_id' => $lokasi_id > 0 ? $lokasi_id : null,
        'status_aktivitas' => $status !== '' ? $status : null,
        'tanggal_mulai' => $tanggal_mulai !== '' ? $tanggal_mulai : null,
        'tanggal_akhir' => $tanggal_akhir !== '' ? $tanggal_akhir : null,
    ], static fn ($v) => $v !== null);
}

function aktivitas_list_url(array $filter_query, array $extra = []): string
{
    $query = $filter_query + $extra;

    return 'aktivitas/index.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

$filter_query = aktivitas_filter_query($lokasi_id, $status_aktivitas, $tanggal_mulai, $tanggal_akhir);

$filters = [
    'lokasi_id' => $lokasi_id,
    'status_aktivitas' => $status_aktivitas,
    'tanggal_mulai' => $tanggal_mulai,
    'tanggal_akhir' => $tanggal_akhir,
];
if ($user['role'] !== 'admin') {
    // Scope WAJIB dari sesi, tidak pernah dari GET -- kombinasi dengan
    // lokasi_id dari GET (bila diisi) otomatis menghasilkan nol baris untuk
    // lokasi_id milik kontraktor lain, bukan membocorkan datanya (lihat
    // repo_aktivitas_list(): kedua kondisi di-AND, bukan dipilih salah satu).
    $filters['kontraktor_id'] = (int) ($user['kontraktor_id'] ?? 0);
}

[$daftar, $total] = repo_aktivitas_list($filters, $page, $per_page, $sort, $dir);
$total_halaman = max(1, (int) ceil($total / $per_page));

// Dropdown lokasi filter: scope sama seperti form.php -- kontraktor hanya
// lokasi miliknya, admin semua.
if ($user['role'] === 'admin') {
    [$daftar_lokasi] = repo_lokasi_list([], 1, 500, 'nama_lokasi', 'asc');
} else {
    [$daftar_lokasi] = repo_lokasi_list(['kontraktor_id' => (int) ($user['kontraktor_id'] ?? 0)], 1, 500, 'nama_lokasi', 'asc');
}

$page_title = $user['role'] === 'admin' ? 'Data Aktivitas' : 'Aktivitas Saya';
$layout = 'app';
$active_menu = $user['role'] === 'admin' ? 'aktivitas' : 'aktivitas_saya';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="app-main__title"><?= e($page_title) ?></h1>

<div class="card">
  <form method="get" action="<?= e(url('aktivitas/index.php')) ?>">
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
        <label class="field__label" for="status_aktivitas">Status Aktivitas</label>
        <select class="select" id="status_aktivitas" name="status_aktivitas">
          <option value="">Semua status</option>
          <option value="aktif" <?php if ($status_aktivitas === 'aktif'): ?>selected<?php endif; ?>>Aktif</option>
          <option value="berhenti_sementara" <?php if ($status_aktivitas === 'berhenti_sementara'): ?>selected<?php endif; ?>>Berhenti Sementara</option>
          <option value="selesai" <?php if ($status_aktivitas === 'selesai'): ?>selected<?php endif; ?>>Selesai</option>
        </select>
      </div>
    </div>
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
    <div class="form-actions">
      <button type="submit" class="btn btn--primary">Terapkan Filter</button>
      <a class="btn btn--secondary" href="<?= e(url('aktivitas/index.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="list-toolbar">
  <span><?= e((string) $total) ?> aktivitas ditemukan</span>
  <?php if ($user['role'] === 'kontraktor'): ?>
  <a class="btn btn--primary" href="<?= e(url('aktivitas/form.php')) ?>">+ Input Aktivitas</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <caption class="sr-only">Daftar aktivitas tambang</caption>
      <thead>
        <tr>
          <?php
            $kolom_sortable = ['tanggal_aktivitas' => 'Tanggal', 'status_aktivitas' => 'Status'];
          ?>
          <?php foreach ($kolom_sortable as $kolom => $label): ?>
          <?php $arah_berikutnya = ($sort === $kolom && $dir === 'asc') ? 'desc' : 'asc'; ?>
          <th scope="col">
            <a href="<?= e(url(aktivitas_list_url($filter_query, ['sort' => $kolom, 'dir' => $arah_berikutnya]))) ?>">
              <?= e($label) ?>
              <?php if ($sort === $kolom && $dir === 'asc'): ?> ▲<?php elseif ($sort === $kolom): ?> ▼<?php endif; ?>
            </a>
          </th>
          <?php endforeach; ?>
          <th scope="col">Lokasi</th>
          <th scope="col">Jenis Kegiatan</th>
          <th scope="col">Material</th>
          <th scope="col">Dicatat Oleh</th>
          <th scope="col">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($daftar === []): ?>
        <tr><td colspan="7">Belum ada aktivitas yang cocok dengan filter ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($daftar as $row): ?>
        <tr>
          <td><?= e((string) $row['tanggal_aktivitas']) ?></td>
          <td>
            <?php if ($row['status_aktivitas'] === 'aktif'): ?>
            <span class="badge badge--berizin">Aktif</span>
            <?php elseif ($row['status_aktivitas'] === 'selesai'): ?>
            <span class="badge badge--proses">Selesai</span>
            <?php else: ?>
            <span class="badge badge--tidak-berizin">Berhenti Sementara</span>
            <?php endif; ?>
          </td>
          <td><?= e($row['nama_lokasi']) ?></td>
          <td><?= e($row['jenis_kegiatan']) ?></td>
          <td><?= e($row['material_nama']) ?></td>
          <td><?= e($row['user_nama']) ?></td>
          <td class="data-table__actions">
            <a class="btn btn--secondary btn--sm" href="<?= e(url('aktivitas/form.php?id=' . $row['id'])) ?>">Ubah</a>
            <?php if ($user['role'] === 'admin'): ?>
            <button type="button" class="btn btn--danger btn--sm" data-dialog-open="dialog-hapus-<?= e((string) $row['id']) ?>">Hapus</button>
            <?php endif; ?>
          </td>
        </tr>

        <?php if ($user['role'] === 'admin'): ?>
        <dialog class="modal" id="dialog-hapus-<?= e((string) $row['id']) ?>" aria-labelledby="dialog-hapus-<?= e((string) $row['id']) ?>-title">
          <div class="modal__body">
            <h2 class="modal__title" id="dialog-hapus-<?= e((string) $row['id']) ?>-title">Hapus aktivitas "<?= e($row['jenis_kegiatan']) ?>"?</h2>
            <p>Tindakan ini tidak dapat dibatalkan. Semua foto dokumentasi aktivitas ini akan ikut terhapus permanen.</p>
            <div class="modal__actions">
              <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Batal</button></form>
              <form method="post" action="<?= e(url('aktivitas/hapus.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                <input type="hidden" name="back" value="<?= e(aktivitas_list_url($filter_query, ['page' => $page])) ?>">
                <button type="submit" class="btn btn--danger">Ya, Hapus</button>
              </form>
            </div>
          </div>
        </dialog>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($total_halaman > 1): ?>
<nav class="pagination" aria-label="Navigasi halaman">
  <?php if ($page > 1): ?>
    <a class="pagination__link" href="<?= e(url(aktivitas_list_url($filter_query, ['page' => $page - 1, 'sort' => $sort, 'dir' => $dir]))) ?>">&larr; Sebelumnya</a>
  <?php else: ?>
    <span class="pagination__link" aria-disabled="true">&larr; Sebelumnya</span>
  <?php endif; ?>
  <span>Halaman <?= e((string) $page) ?> dari <?= e((string) $total_halaman) ?></span>
  <?php if ($page < $total_halaman): ?>
    <a class="pagination__link" href="<?= e(url(aktivitas_list_url($filter_query, ['page' => $page + 1, 'sort' => $sort, 'dir' => $dir]))) ?>">Berikutnya &rarr;</a>
  <?php else: ?>
    <span class="pagination__link" aria-disabled="true">Berikutnya &rarr;</span>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php
require __DIR__ . '/../includes/footer.php';
