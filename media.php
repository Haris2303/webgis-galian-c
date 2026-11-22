<?php

declare(strict_types=1);

// Gerbang penyajian foto dokumentasi aktivitas. Folder uploads/ ditutup total ("Require all denied"); satu-satunya cara membaca foto adalah lewat berkas ini, dengan otorisasi diperiksa ulang pada setiap permintaan.

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/repo/foto.php';
require_once __DIR__ . '/includes/upload_foto.php';

$user = require_login();

function media_tolak(int $kode): never
{
    // Satu bentuk respons dipakai untuk semua kegagalan (id tak ada, bukan pemilik, berkas hilang) agar tidak bisa dibedakan dari luar.
    http_response_code($kode);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Berkas tidak ditemukan.';
    exit;
}

// id harus bilangan bulat positif murni dan tidak pernah dipakai membangun path berkas langsung; path sesungguhnya selalu berasal dari kolom file_path di DB.
$id_raw = $_GET['id'] ?? null;
$id = is_string($id_raw) && preg_match('/^[1-9][0-9]*$/', $id_raw) === 1 ? (int) $id_raw : null;

$size = ($_GET['size'] ?? 'full') === 'thumb' ? 'thumb' : 'full';

$foto = $id !== null ? repo_foto_find($id) : null;

if ($foto === null) {
    media_tolak(404);
}

// Kontraktor hanya boleh melihat foto aktivitas perusahaannya sendiri; admin boleh semua.
if (!can_access_aktivitas($user, (int) $foto['aktivitas_id'])) {
    media_tolak(404);
}

$relative_path = $size === 'thumb'
    ? upload_foto_path_thumbnail((string) $foto['file_path'])
    : (string) $foto['file_path'];
$absolute_path = upload_foto_path_absolut($relative_path);

// Defense in depth: pastikan path yang dibuka benar-benar di dalam uploads/aktivitas/, agar bug di tempat lain tidak bisa mengirim berkas di luar folder itu.
$root_upload_real = realpath(rtrim(PROJECT_ROOT, '/\\') . '/uploads/aktivitas');
$absolute_real = realpath($absolute_path);

if ($root_upload_real === false
    || $absolute_real === false
    || !str_starts_with($absolute_real, $root_upload_real)
) {
    // File ada di DB tapi hilang di disk (atau keluar dari folder upload): dicatat ke log, tapi klien tetap menerima respons seragam.
    app_log('warning', "media.php: berkas tidak ditemukan di disk utk foto id={$id} ({$size}): {$absolute_path}");
    media_tolak(404);
}

// Verifikasi ulang MIME dari konten berkas di titik penyajian, bukan hanya percaya ekstensi nama file.
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolute_real);
if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
    app_log('error', "media.php: MIME berkas tidak sesuai whitelist utk foto id={$id}: {$mime} ({$absolute_real})");
    media_tolak(404);
}

$mtime = filemtime($absolute_real);
$filesize = filesize($absolute_real);
$etag = '"' . dechex((int) $mtime) . '-' . dechex((int) $filesize) . '-' . $size . '"';

header('ETag: ' . $etag);
// private: boleh di-cache browser pengguna sendiri, tapi tidak pernah oleh cache bersama/CDN/proxy -- foto ini terotorisasi per pengguna.
header('Cache-Control: private, max-age=3600');

$if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
if ($if_none_match === $etag) {
    http_response_code(304);
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) $filesize);
header('Content-Disposition: inline; filename="foto-' . $id . '-' . $size . '.' . ($mime === 'image/png' ? 'png' : 'jpg') . '"');

readfile($absolute_real);
exit;
