<?php

declare(strict_types=1);

// Urutan wajib: kumpulkan path foto + DELETE lokasi (CASCADE) di dalam transaksi -> commit -> baru hapus file fisik. Menghapus file sebelum commit berisiko baris DB rollback tapi file sudah hilang.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';
require_once __DIR__ . '/../includes/repo/foto.php';

require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort(405, 'Hapus lokasi hanya bisa lewat POST.');
}

csrf_require();

$id = (int) ($_POST['id'] ?? 0);
$lokasi = $id > 0 ? repo_lokasi_find($id) : null;
$kembali = validate_next_path($_POST['back'] ?? null) ?? 'lokasi/index.php';

if ($lokasi === null) {
    flash('error', 'Lokasi tidak ditemukan (mungkin sudah dihapus admin lain).');
    redirect($kembali);
}

$foto_paths = db_transaction(function () use ($id) {
    $paths = repo_foto_list_paths_by_lokasi($id);
    repo_lokasi_delete($id);

    return $paths;
});

$gagal_hapus_file = 0;
foreach ($foto_paths as $path) {
    $path = str_replace('\\', '/', (string) $path);
    $full_path = rtrim(PROJECT_ROOT, '/\\') . '/' . ltrim($path, '/');

    // File fisik bisa sudah hilang dari disk sebelum lokasi dihapus -- is_file() membuatnya skip dengan tenang, bukan kegagalan.
    if (is_file($full_path)) {
        if (!@unlink($full_path)) {
            $gagal_hapus_file++;
            app_log('warning', "Gagal menghapus file foto saat hapus lokasi id={$id}: {$full_path}");
        }
    }
}

app_log('info', sprintf(
    'Admin menghapus lokasi id=%d "%s" (%d foto dihapus dari disk%s)',
    $id,
    $lokasi['nama_lokasi'],
    count($foto_paths) - $gagal_hapus_file,
    $gagal_hapus_file > 0 ? ", {$gagal_hapus_file} gagal" : ''
));

flash('success', "Lokasi \"{$lokasi['nama_lokasi']}\" beserta aktivitas dan foto terkait berhasil dihapus.");
redirect($kembali);
