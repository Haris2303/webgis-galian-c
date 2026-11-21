<?php

declare(strict_types=1);

/**
 * kontraktor/hapus.php — hapus kontraktor (FR-11). Hanya POST+CSRF (pola
 * sama dengan logout.php, T-06) -- aksi hapus tidak boleh bisa dipicu lewat
 * link GET biasa. Hapus HANYA diizinkan bila kontraktor tidak punya lokasi
 * (FK RESTRICT, database/schema.sql); akun login terkait (jika ada) ikut
 * dihapus dalam transaksi yang sama -- lihat Keputusan T-08.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/repo/users.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

require_role('admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort(405, 'Hapus kontraktor hanya bisa lewat POST.');
}

csrf_require();

$id = (int) ($_POST['id'] ?? 0);
$kontraktor = $id > 0 ? repo_kontraktor_find($id) : null;
$kembali = validate_next_path($_POST['back'] ?? null) ?? 'kontraktor/index.php';

if ($kontraktor === null) {
    flash('error', 'Kontraktor tidak ditemukan (mungkin sudah dihapus admin lain).');
    redirect($kembali);
}

// Pengecekan dulu demi pesan yang jelas (jumlah pasti); FK RESTRICT di bawah
// tetap jadi penjaga akhir yang otoritatif terhadap race condition (kontraktor
// ini diberi lokasi baru oleh admin lain tepat di antara cek ini dan DELETE).
$jumlah_lokasi = repo_lokasi_count_by_kontraktor($id);
if ($jumlah_lokasi > 0) {
    flash('error', "Kontraktor \"{$kontraktor['nama_perusahaan']}\" masih memiliki {$jumlah_lokasi} lokasi terdaftar. Hapus atau pindahkan lokasinya dulu sebelum menghapus kontraktor ini.");
    redirect($kembali);
}

try {
    db_transaction(function () use ($id) {
        // Hapus akun login dulu -- tanpa ini, FK users.kontraktor_id ON DELETE
        // SET NULL (database/schema.sql) akan membuat akun itu jadi role=kontraktor
        // TANPA kontraktor_id: persis kondisi "data rusak" yang ditolak login.php
        // (T-06). Lebih baik akun ikut terhapus bersih daripada dibiarkan rusak.
        foreach (repo_user_list_by_kontraktor($id) as $akun) {
            repo_user_delete((int) $akun['id']);
        }

        repo_kontraktor_delete($id);
    });

    app_log('info', sprintf('Admin menghapus kontraktor id=%d "%s"', $id, $kontraktor['nama_perusahaan']));
    flash('success', "Kontraktor \"{$kontraktor['nama_perusahaan']}\" berhasil dihapus.");
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? null) === 1451) {
        flash('error', "Kontraktor \"{$kontraktor['nama_perusahaan']}\" masih dirujuk data lain dan tidak bisa dihapus.");
    } else {
        throw $e;
    }
}

redirect($kembali);
