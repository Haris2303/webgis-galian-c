<?php

declare(strict_types=1);

/**
 * kontraktor/index.php — daftar kontraktor + aksi baris (FR-11, FR-12, T-08).
 * Khusus Admin. Menangani juga dua aksi POST ringan di halaman yang sama
 * (reset_password, toggle_active) -- "Tambah"/"Ubah" tetap di form.php,
 * "Hapus" tetap di hapus.php, sesuai daftar artefak T-08.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/repo/users.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

require_role('admin');

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 10;

/** Bangun querystring halaman daftar ini (dipakai redirect balik & link paginasi), SELALU path relatif aman untuk url(). */
function kontraktor_list_url(string $search, int $page): string
{
    $query = array_filter(['q' => $search, 'page' => $page > 1 ? $page : null], static fn ($v) => $v !== null && $v !== '');

    return 'kontraktor/index.php' . ($query !== [] ? '?' . http_build_query($query) : '');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();

    $action = (string) ($_POST['action'] ?? '');
    $kontraktor_id = (int) ($_POST['kontraktor_id'] ?? 0);
    $kontraktor = $kontraktor_id > 0 ? repo_kontraktor_find($kontraktor_id) : null;

    if ($kontraktor === null) {
        flash('error', 'Kontraktor tidak ditemukan (mungkin sudah dihapus admin lain).');
        redirect(kontraktor_list_url($search, $page));
    }

    if ($action === 'reset_password') {
        $akun_list = repo_user_list_by_kontraktor($kontraktor_id);
        $akun = $akun_list[0] ?? null;

        if ($akun === null) {
            flash('error', 'Kontraktor ini tidak memiliki akun login untuk di-reset.');
        } else {
            // Password BARU selalu dibangkitkan sistem (tidak pernah diketik admin)
            // dan HANYA hash-nya disimpan -- lihat generate_strong_password() di
            // includes/auth.php (Keputusan T-08: cegah "password awal lemah").
            $password_baru = generate_strong_password();
            repo_user_update_password((int) $akun['id'], password_hash($password_baru, PASSWORD_BCRYPT));

            app_log('info', sprintf('Admin mereset password akun id=%d username="%s"', $akun['id'], $akun['username']));

            flash(
                'credential',
                sprintf('Password baru untuk "%s" (username: %s) — salin sekarang, tidak akan ditampilkan lagi:', $kontraktor['nama_perusahaan'], $akun['username']),
                ['password' => $password_baru]
            );
        }

        redirect(kontraktor_list_url($search, $page));
    }

    if ($action === 'toggle_active') {
        $akun_list = repo_user_list_by_kontraktor($kontraktor_id);
        $akun = $akun_list[0] ?? null;

        if ($akun === null) {
            flash('error', 'Kontraktor ini tidak memiliki akun login.');
        } else {
            $aktifkan = !((int) $akun['is_active'] === 1);
            repo_user_set_active((int) $akun['id'], $aktifkan);

            // Tidak perlu memutus session kontraktor yang sedang login secara manual
            // di sini -- require_login() (T-06) sudah mengecek ulang is_active dari
            // DB di SETIAP request, jadi request berikutnya dari akun itu otomatis
            // ter-logout sendiri (edge case T-08, memakai mekanisme T-06 yang sudah ada).
            flash('success', $aktifkan
                ? "Akun \"{$akun['username']}\" diaktifkan kembali."
                : "Akun \"{$akun['username']}\" dinonaktifkan. Sesi yang sedang berjalan akan otomatis ter-logout.");
        }

        redirect(kontraktor_list_url($search, $page));
    }

    abort(400, 'Aksi tidak dikenal.');
}

[$daftar, $total] = repo_kontraktor_list($page, $per_page, $search !== '' ? $search : null);
$total_halaman = max(1, (int) ceil($total / $per_page));

$page_title = 'Data Kontraktor';
$layout = 'app';
$active_menu = 'kontraktor';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="app-main__title">Data Kontraktor</h1>

<div class="list-toolbar">
  <form class="search-form" method="get" action="<?= e(url('kontraktor/index.php')) ?>">
    <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama perusahaan…" aria-label="Cari kontraktor">
    <button type="submit" class="btn btn--secondary">Cari</button>
  </form>
  <a class="btn btn--primary" href="<?= e(url('kontraktor/form.php')) ?>">+ Tambah Kontraktor</a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="data-table">
      <caption class="sr-only">Daftar kontraktor beserta akun login dan jumlah lokasi</caption>
      <thead>
        <tr>
          <th scope="col">Nama Perusahaan</th>
          <th scope="col">Penanggung Jawab</th>
          <th scope="col">Telepon</th>
          <th scope="col">Akun Login</th>
          <th scope="col">Lokasi</th>
          <th scope="col">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($daftar === [] && $search !== ''): ?>
        <tr><td colspan="6">Belum ada data kontraktor yang cocok dengan pencarian "<?= e($search) ?>".</td></tr>
        <?php elseif ($daftar === []): ?>
        <tr><td colspan="6">Belum ada data kontraktor.</td></tr>
        <?php endif; ?>
        <?php foreach ($daftar as $row): ?>
        <?php
            $akun_list = repo_user_list_by_kontraktor((int) $row['id']);
            $akun = $akun_list[0] ?? null;
            $jumlah_lokasi = repo_lokasi_count_by_kontraktor((int) $row['id']);
        ?>
        <tr>
          <td><?= e($row['nama_perusahaan']) ?></td>
          <td><?= e($row['penanggung_jawab']) ?></td>
          <td><?= e($row['telepon'] ?? '—') ?></td>
          <td>
            <?php if ($akun === null): ?>
              <span class="badge badge--tidak-berizin">Tanpa akun</span>
            <?php elseif ((int) $akun['is_active'] === 1): ?>
              <span class="badge badge--berizin">Aktif</span> <?= e($akun['username']) ?>
            <?php else: ?>
              <span class="badge badge--proses">Nonaktif</span> <?= e($akun['username']) ?>
            <?php endif; ?>
          </td>
          <td><?= e((string) $jumlah_lokasi) ?></td>
          <td class="data-table__actions">
            <a class="btn btn--secondary btn--sm" href="<?= e(url('kontraktor/form.php?id=' . $row['id'])) ?>">Ubah</a>

            <?php if ($akun !== null): ?>
            <button type="button" class="btn btn--secondary btn--sm" data-dialog-open="dialog-reset-<?= e((string) $row['id']) ?>">Reset Password</button>

            <form method="post" action="<?= e(url(kontraktor_list_url($search, $page))) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="kontraktor_id" value="<?= e((string) $row['id']) ?>">
              <button type="submit" class="btn btn--secondary btn--sm">
                <?php if ((int) $akun['is_active'] === 1): ?>Nonaktifkan<?php else: ?>Aktifkan<?php endif; ?>
              </button>
            </form>
            <?php endif; ?>

            <button type="button" class="btn btn--danger btn--sm" data-dialog-open="dialog-hapus-<?= e((string) $row['id']) ?>">Hapus</button>
          </td>
        </tr>

        <?php if ($akun !== null): ?>
        <dialog class="modal" id="dialog-reset-<?= e((string) $row['id']) ?>" aria-labelledby="dialog-reset-<?= e((string) $row['id']) ?>-title">
          <div class="modal__body">
            <h2 class="modal__title" id="dialog-reset-<?= e((string) $row['id']) ?>-title">Reset password <?= e($akun['username']) ?>?</h2>
            <p>Password lama akan langsung tidak berlaku. Password baru hanya ditampilkan SATU KALI setelah ini.</p>
            <div class="modal__actions">
              <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Batal</button></form>
              <form method="post" action="<?= e(url(kontraktor_list_url($search, $page))) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="kontraktor_id" value="<?= e((string) $row['id']) ?>">
                <button type="submit" class="btn btn--primary">Ya, Reset Password</button>
              </form>
            </div>
          </div>
        </dialog>
        <?php endif; ?>

        <dialog class="modal" id="dialog-hapus-<?= e((string) $row['id']) ?>" aria-labelledby="dialog-hapus-<?= e((string) $row['id']) ?>-title">
          <div class="modal__body">
            <h2 class="modal__title" id="dialog-hapus-<?= e((string) $row['id']) ?>-title">Hapus "<?= e($row['nama_perusahaan']) ?>"?</h2>
            <?php if ($jumlah_lokasi > 0): ?>
            <p>Kontraktor ini masih memiliki <strong><?= e((string) $jumlah_lokasi) ?> lokasi</strong> terdaftar. Hapus atau pindahkan lokasinya dulu sebelum menghapus kontraktor ini.</p>
            <div class="modal__actions">
              <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Tutup</button></form>
            </div>
            <?php else: ?>
            <p>Tindakan ini tidak dapat dibatalkan. Akun login kontraktor ini (jika ada) juga akan ikut terhapus.</p>
            <div class="modal__actions">
              <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Batal</button></form>
              <form method="post" action="<?= e(url('kontraktor/hapus.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
                <input type="hidden" name="back" value="<?= e(kontraktor_list_url($search, $page)) ?>">
                <button type="submit" class="btn btn--danger">Ya, Hapus</button>
              </form>
            </div>
            <?php endif; ?>
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
    <a class="pagination__link" href="<?= e(url(kontraktor_list_url($search, $page - 1))) ?>">&larr; Sebelumnya</a>
  <?php else: ?>
    <span class="pagination__link" aria-disabled="true">&larr; Sebelumnya</span>
  <?php endif; ?>
  <span>Halaman <?= e((string) $page) ?> dari <?= e((string) $total_halaman) ?> (<?= e((string) $total) ?> kontraktor)</span>
  <?php if ($page < $total_halaman): ?>
    <a class="pagination__link" href="<?= e(url(kontraktor_list_url($search, $page + 1))) ?>">Berikutnya &rarr;</a>
  <?php else: ?>
    <span class="pagination__link" aria-disabled="true">Berikutnya &rarr;</span>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php
require __DIR__ . '/../includes/footer.php';
