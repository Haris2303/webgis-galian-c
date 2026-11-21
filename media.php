<?php

declare(strict_types=1);

/**
 * media.php — gerbang penyajian foto dokumentasi aktivitas (FR-14, FR-19;
 * T-19, PRD bagian 5/11). Pengganti akses langsung ke uploads/ -- folder itu
 * sekarang DITUTUP TOTAL ("Require all denied", lihat uploads/.htaccess).
 * Satu-satunya cara membaca foto adalah lewat berkas ini, dengan otorisasi
 * yang diperiksa ULANG pada SETIAP permintaan (bukan hanya sekali saat
 * halaman yang menampilkannya dirender).
 *
 * Keputusan T-19 (lihat laporan): INI MENGUBAH PRD bagian 8 -- diagram folder
 * di sana menulis "uploads/aktivitas/ # foto (dengan .htaccess: larang
 * eksekusi PHP)" dan TIDAK menyebut media.php sama sekali, mengasumsikan foto
 * tetap bisa diakses langsung via URL asal bukan dieksekusi sebagai skrip.
 * T-19 secara eksplisit meminta arsitektur yang lebih ketat (folder tertutup
 * total + gerbang PHP) -- pertukarannya: setiap foto kini melewati PHP/MySQL
 * per permintaan (sedikit lebih lambat dari static file serving Apache),
 * demi otorisasi yang konsisten dan audit-able.
 *
 * Otorisasi: SELARAS dengan kebijakan T-12 (api/lokasi_detail.php,
 * aktivitas_dibatasi) -- can_access_aktivitas() yang sama dipakai di sini,
 * di aktivitas/form.php, dan aktivitas/hapus_foto.php. Kontraktor TIDAK
 * BISA melihat thumbnail lokasi/aktivitas milik kontraktor lain, walau boleh
 * melihat MARKER lokasi itu di peta (keputusan T-12: "semua lihat semua
 * marker" hanya utk data lokasi dasar, bukan foto dokumentasi aktivitas).
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/repo/foto.php';
require_once __DIR__ . '/includes/upload_foto.php';

$user = require_login();

function media_tolak(int $kode): never
{
    // Edge case wajib T-19: "id tidak ada ATAU milik orang lain: respons
    // SERAGAM" -- satu fungsi, satu bentuk respons, dipakai untuk KETIGA
    // kegagalan (id tak valid/tak ada, bukan pemilik, berkas hilang dari
    // disk) supaya ketiganya tidak bisa dibedakan dari luar.
    http_response_code($kode);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Berkas tidak ditemukan.';
    exit;
}

// Edge case wajib T-19: path traversal pada parameter. id HARUS bilangan
// bulat positif murni lewat regex -- TIDAK PERNAH dipakai membangun path
// berkas secara langsung. Path sesungguhnya SELALU berasal dari kolom
// file_path di DB, yang sendiri hanya pernah ditulis oleh
// upload_foto_proses_dan_simpan() (T-18) dengan nama acak -- bukan dari
// input pengguna mana pun, jadi tidak ada "parameter path" yang bisa
// dimanipulasi sejak awal.
$id_raw = $_GET['id'] ?? null;
$id = is_string($id_raw) && preg_match('/^[1-9][0-9]*$/', $id_raw) === 1 ? (int) $id_raw : null;

$size = ($_GET['size'] ?? 'full') === 'thumb' ? 'thumb' : 'full';

$foto = $id !== null ? repo_foto_find($id) : null;

if ($foto === null) {
    media_tolak(404);
}

// Edge case wajib T-19 (IDOR foto): sama persis dengan aktivitas/hapus_foto.php
// -- kontraktor hanya boleh melihat foto aktivitas PERUSAHAANNYA sendiri;
// admin boleh semua.
if (!can_access_aktivitas($user, (int) $foto['aktivitas_id'])) {
    media_tolak(404);
}

$relative_path = $size === 'thumb'
    ? upload_foto_path_thumbnail((string) $foto['file_path'])
    : (string) $foto['file_path'];
$absolute_path = upload_foto_path_absolut($relative_path);

// Lapis pertahanan TAMBAHAN (defense in depth): pastikan path yang SUNGGUH
// dibuka benar-benar di dalam uploads/aktivitas/, lewat realpath() -- bukan
// karena $relative_path datang dari input pengguna (TIDAK, ia dari DB), tapi
// supaya bug di tempat lain pada masa depan (migrasi data, refactor) tidak
// pernah bisa membuat berkas DI LUAR folder itu ikut terkirim.
$root_upload_real = realpath(rtrim(PROJECT_ROOT, '/\\') . '/uploads/aktivitas');
$absolute_real = realpath($absolute_path);

if ($root_upload_real === false
    || $absolute_real === false
    || !str_starts_with($absolute_real, $root_upload_real)
) {
    // Edge case wajib T-19: "file ada di DB tetapi hilang di disk" (atau,
    // seharusnya mustahil, keluar dari folder upload) -- dicatat ke log
    // (anomali operasional yang layak diketahui), tapi klien tetap menerima
    // respons SERAGAM yang sama seperti id tidak ada/bukan milik pengguna.
    app_log('warning', "media.php: berkas tidak ditemukan di disk utk foto id={$id} ({$size}): {$absolute_path}");
    media_tolak(404);
}

// Lapis terakhir: verifikasi ulang MIME dari KONTEN berkas (bukan hanya
// percaya ekstensi nama file) -- berkas ini sendiri sudah melalui re-encode
// GD (T-18) jadi seharusnya selalu bersih, tapi memverifikasi ulang di titik
// penyajian tidak pernah salah dan biayanya kecil (satu berkas, bukan daftar).
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($absolute_real);
if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
    app_log('error', "media.php: MIME berkas tidak sesuai whitelist utk foto id={$id}: {$mime} ({$absolute_real})");
    media_tolak(404);
}

$mtime = filemtime($absolute_real);
$filesize = filesize($absolute_real);
$etag = '"' . dechex((int) $mtime) . '-' . dechex((int) $filesize) . '-' . $size . '"';

header('ETag: ' . $etag);
// private: boleh disimpan cache BROWSER pengguna sendiri (mempercepat
// pembukaan ulang popup/galeri yang sama), tapi TIDAK PERNAH oleh cache
// bersama/CDN/proxy -- foto ini terotorisasi per pengguna, bukan publik.
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
