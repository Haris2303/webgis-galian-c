<?php

declare(strict_types=1);

// aktivitas/hapus.php — hapus aktivitas, admin saja (beda dari ubah, yang juga mengizinkan pemilik).

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';
require_once __DIR__ . '/../includes/repo/foto.php';

require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort(405, 'Hapus aktivitas hanya bisa lewat POST.');
}

csrf_require();

$id = (int) ($_POST['id'] ?? 0);
$aktivitas = $id > 0 ? repo_aktivitas_find($id) : null;
$kembali = validate_next_path($_POST['back'] ?? null) ?? 'aktivitas/index.php';

if ($aktivitas === null) {
    flash('error', 'Aktivitas tidak ditemukan (mungkin sudah dihapus admin lain).');
    redirect($kembali);
}

$foto_lama = repo_foto_list_by_aktivitas($id);

db_transaction(function () use ($id) {
    // $foto_lama dikumpulkan sebelum delete karena ON DELETE CASCADE ikut menghapus baris foto.
    repo_aktivitas_delete($id);
});

$gagal_hapus_file = 0;
foreach ($foto_lama as $foto) {
    $path = str_replace('\\', '/', (string) $foto['file_path']);
    $full_path = rtrim(PROJECT_ROOT, '/\\') . '/' . ltrim($path, '/');

    if (is_file($full_path)) {
        if (!@unlink($full_path)) {
            $gagal_hapus_file++;
            app_log('warning', "Gagal menghapus file foto saat hapus aktivitas id={$id}: {$full_path}");
        }
    }
}

app_log('info', sprintf(
    'Admin menghapus aktivitas id=%d (lokasi_id=%d, %d foto dihapus dari disk%s)',
    $id,
    (int) $aktivitas['lokasi_id'],
    count($foto_lama) - $gagal_hapus_file,
    $gagal_hapus_file > 0 ? ", {$gagal_hapus_file} gagal" : ''
));

flash('success', 'Aktivitas berhasil dihapus.');
redirect($kembali);
