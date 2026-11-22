<?php

declare(strict_types=1);

/**
 * api/aktivitas_detail.php — detail satu aktivitas (lokasi, kontraktor,
 * material, pencatat, foto) untuk panel "Lihat" di monitoring.php. Admin
 * saja -- monitoring.php sendiri require_role('admin'), endpoint ini
 * menegakkan ulang di sisi server, tidak pernah mempercayai sisi klien.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/repo/material.php';
require_once __DIR__ . '/../includes/repo/foto.php';

$user = api_boot();
if ($user['role'] !== 'admin') {
    api_error(403, 'Hanya admin yang dapat mengakses detail ini.');
}

$id = api_parse_id(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null);
if ($id === null) {
    api_error(400, 'Parameter id wajib diisi dan berupa angka bulat positif.');
}

$aktivitas = repo_aktivitas_find($id);
if ($aktivitas === null) {
    api_error(404, 'Aktivitas tidak ditemukan.');
}

$lokasi = repo_lokasi_find((int) $aktivitas['lokasi_id']);
if ($lokasi === null) {
    // Edge case: lokasi sudah dihapus tapi baris aktivitas masih ada --
    // seharusnya tidak terjadi (FK), tapi jangan tampilkan 500 kalau terjadi.
    api_error(404, 'Lokasi aktivitas ini tidak ditemukan.');
}

$kontraktor = repo_kontraktor_find((int) $lokasi['kontraktor_id']);
$material = repo_material_find((int) $aktivitas['material_id']);
$pencatat = repo_user_find((int) $aktivitas['user_id']);
$foto = repo_foto_list_by_aktivitas($id);

api_json([
    'id' => (int) $aktivitas['id'],
    'tanggal_aktivitas' => $aktivitas['tanggal_aktivitas'],
    'jenis_kegiatan' => $aktivitas['jenis_kegiatan'],
    'status_aktivitas' => $aktivitas['status_aktivitas'],
    'jumlah_alat_berat' => (int) $aktivitas['jumlah_alat_berat'],
    'jumlah_pekerja' => (int) $aktivitas['jumlah_pekerja'],
    'deskripsi' => $aktivitas['deskripsi'],
    'user_nama' => $pencatat['nama_lengkap'] ?? null,
    'lokasi' => [
        'id' => (int) $lokasi['id'],
        'nama_lokasi' => $lokasi['nama_lokasi'],
        'status_izin' => $lokasi['status_izin'],
        'kontraktor' => $kontraktor['nama_perusahaan'] ?? null,
        'material' => $material['nama'] ?? null,
        'koordinat' => [
            'lat' => (float) $lokasi['latitude'],
            'lng' => (float) $lokasi['longitude'],
        ],
    ],
    // Sama seperti api/lokasi_detail.php: thumb untuk grid kecil, full untuk
    // lihat besar (klik thumbnail membuka tab baru ke ukuran penuh).
    'foto' => array_values(array_map(
        static fn (array $f): array => [
            'thumb' => url('media.php?id=' . $f['id'] . '&size=thumb'),
            'full' => url('media.php?id=' . $f['id'] . '&size=full'),
        ],
        $foto
    )),
]);
