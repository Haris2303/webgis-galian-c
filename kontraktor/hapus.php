<?php

declare(strict_types=1);

// Hapus hanya diizinkan bila kontraktor tidak punya lokasi (FK RESTRICT); akun login terkait ikut dihapus dalam transaksi yang sama.

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

// Pengecekan dulu demi pesan yang jelas; FK RESTRICT tetap jadi penjaga akhir terhadap race condition.
$jumlah_lokasi = repo_lokasi_count_by_kontraktor($id);
if ($jumlah_lokasi > 0) {
    flash('error', "Kontraktor \"{$kontraktor['nama_perusahaan']}\" masih memiliki {$jumlah_lokasi} lokasi terdaftar. Hapus atau pindahkan lokasinya dulu sebelum menghapus kontraktor ini.");
    redirect($kembali);
}

try {
    db_transaction(function () use ($id) {
        // Hapus akun login dulu -- tanpa ini FK ON DELETE SET NULL akan membuat akun role=kontraktor tanpa kontraktor_id, data yang ditolak login.php.
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
