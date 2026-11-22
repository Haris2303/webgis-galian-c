<?php

declare(strict_types=1);

/**
 * api/lokasi_detail.php — detail satu lokasi + aktivitas terakhir + foto
 * (FR-19; PRD bagian 10, T-12).
 *
 * Keputusan T-12 (lihat laporan): FR-19 (PRD bagian 5) bilang popup "Semua"
 * peran dapat lihat aktivitas terakhir TANPA kualifikasi kepemilikan, tapi
 * paragraf pembuka PRD bagian 10 bilang "Kontraktor tetap boleh melihat semua
 * marker ..., tetapi detail aktivitas hanya miliknya" -- LEBIH KETAT. Sesuai
 * instruksi task ("ambil yang paling ketat"), endpoint ini SELALU mengembalikan
 * data lokasi dasar untuk siapa pun yang login, tapi field aktivitas_terakhir
 * HANYA diisi bila admin ATAU lokasi ini milik kontraktor yang login.
 * KEPUTUSAN INI PERLU DIKONFIRMASI pemilik produk -- lihat laporan T-12.
 */

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
    // Penanda EKSPLISIT ke klien: array di atas kosong karena memang dibatasi
    // kepemilikan, bukan karena lokasi ini tidak punya riwayat aktivitas sama
    // sekali -- UI (T-14) bisa menampilkan pesan yang tepat, bukan "belum ada
    // aktivitas" yang menyesatkan.
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
            // media.php (T-19), bukan URL uploads/ langsung -- folder itu
            // sekarang tertutup total. "thumb" untuk pratinjau kecil di popup
            // peta (PRD bagian 9: "thumbnail foto"), "full" untuk lightbox
            // (UI: "lihat detail serta di slide") -- dua ukuran sekaligus
            // supaya klien tidak perlu menyusun ulang URL dari string thumb.
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
