<?php

declare(strict_types=1);

// api/lokasi_detail.php — detail satu lokasi + aktivitas terakhir + foto. aktivitas_terakhir hanya diisi untuk admin atau pemilik lokasi; lainnya tetap dapat data lokasi dasar.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/repo/material.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';
require_once __DIR__ . '/../includes/repo/foto.php';

$user = api_boot();

$id = api_parse_id(isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null);
if ($id === null) {
    api_error(400, 'Parameter id wajib diisi dan berupa angka bulat positif.');
}

$lokasi = repo_lokasi_find($id);
if ($lokasi === null) {
    api_error(404, 'Lokasi tidak ditemukan.');
}

$kontraktor = repo_kontraktor_find((int) $lokasi['kontraktor_id']);
$material = repo_material_find((int) $lokasi['material_id']);

$boleh_lihat_aktivitas = $user['role'] === 'admin' || can_access_lokasi($user, $id);

$respons = [
    'id' => (int) $lokasi['id'],
    'nama_lokasi' => $lokasi['nama_lokasi'],
    'distrik' => $lokasi['distrik'],
    'kelurahan' => $lokasi['kelurahan'],
    'alamat' => $lokasi['alamat'],
    'koordinat' => [
        'lat' => (float) $lokasi['latitude'],
        'lng' => (float) $lokasi['longitude'],
    ],
    'status_izin' => $lokasi['status_izin'],
    'luas_ha' => $lokasi['luas_ha'] !== null ? (float) $lokasi['luas_ha'] : null,
    'kontraktor' => $kontraktor['nama_perusahaan'] ?? null,
    'material' => $material['nama'] ?? null,
    'aktivitas_terakhir' => [],
    // Membedakan "dibatasi otorisasi" dari "memang belum ada riwayat".
    'aktivitas_dibatasi' => !$boleh_lihat_aktivitas,
];

if ($boleh_lihat_aktivitas) {
    foreach (repo_aktivitas_latest_by_lokasi($id, 5) as $aktivitas) {
        $foto = repo_foto_list_by_aktivitas((int) $aktivitas['id']);
        $respons['aktivitas_terakhir'][] = [
            'id' => (int) $aktivitas['id'],
            'tanggal' => $aktivitas['tanggal_aktivitas'],
            'jenis_kegiatan' => $aktivitas['jenis_kegiatan'],
            'status_aktivitas' => $aktivitas['status_aktivitas'],
            // Lewat media.php, bukan URL uploads/ langsung (folder itu tertutup).
            'foto' => array_values(array_map(
                static fn (array $f): array => [
                    'thumb' => url('media.php?id=' . $f['id'] . '&size=thumb'),
                    'full' => url('media.php?id=' . $f['id'] . '&size=full'),
                ],
                $foto
            )),
        ];
    }
}

api_json($respons);
