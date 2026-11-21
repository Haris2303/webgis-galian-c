<?php

declare(strict_types=1);

/**
 * aktivitas/hapus.php — hapus aktivitas (FR-16; T-17). Admin SAJA -- spesifikasi
 * T-17 ("hapus hanya admin") tidak mengenal nuansa kepemilikan di sini sama
 * sekali, beda dengan ubah (FR-15) yang masih mengizinkan pemilik lewat
 * can_access_aktivitas(). require_role('admin') di bawah SEKALIGUS menjadi
 * jawaban untuk skenario IDOR #3 (edge case wajib T-17, "hapus ID orang
 * lain"): kontraktor yang memaksa POST ke sini -- memakai ID miliknya sendiri
 * ATAUPUN milik kontraktor lain -- SELALU 403 di baris pertama, sebelum ID
 * apa pun sempat diperiksa.
 *
 * Urutan hapus file fisik SETELAH commit (bukan sebelum) -- pola yang sama
 * dengan lokasi/hapus.php (T-09): kalau file dihapus duluan lalu transaksi
 * batal, baris DB tetap ada tapi file sudah hilang (lebih buruk).
 */

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
    // ON DELETE CASCADE ke foto_aktivitas (database/schema.sql) -- baris foto
    // ikut terhapus otomatis, $foto_lama di atas sudah dikumpulkan SEBELUM ini
    // supaya path filenya tetap bisa dibaca untuk hapus fisik di bawah.
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
