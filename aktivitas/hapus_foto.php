<?php

declare(strict_types=1);

// aktivitas/hapus_foto.php — hapus satu foto; boleh pemilik aktivitas (kontraktor) atau admin, beda dari hapus aktivitas (admin saja).

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';
require_once __DIR__ . '/../includes/repo/foto.php';
require_once __DIR__ . '/../includes/upload_foto.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort(405, 'Hapus foto hanya bisa lewat POST.');
}

csrf_require();

$id = (int) ($_POST['id'] ?? 0);
$foto = $id > 0 ? repo_foto_find($id) : null;
$kembali = validate_next_path($_POST['back'] ?? null) ?? 'aktivitas/index.php';

if ($foto === null) {
    flash('error', 'Foto tidak ditemukan (mungkin sudah dihapus sebelumnya).');
    redirect($kembali);
}

// Otorisasi lewat aktivitas pemilik foto -- kontraktor lain tidak boleh hapus foto yang bukan miliknya.
if (!can_access_aktivitas($user, (int) $foto['aktivitas_id'])) {
    abort(403);
}

upload_foto_hapus($foto);

app_log('info', sprintf('User id=%d menghapus foto id=%d (aktivitas_id=%d)', $user['id'], $id, (int) $foto['aktivitas_id']));

flash('success', 'Foto berhasil dihapus.');
redirect($kembali);
