<?php

declare(strict_types=1);

// api/lokasi.php — FeatureCollection titik lokasi tambang; kontraktor hanya melihat marker miliknya sendiri.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

$user = api_boot();
$kontraktor_id_scope = $user['role'] === 'kontraktor' ? (int) ($user['kontraktor_id'] ?? 0) : null;

$status = null;
if (isset($_GET['status']) && $_GET['status'] !== '') {
    if (!is_string($_GET['status'])) {
        api_error(400, 'Parameter status tidak valid.');
    }
    $status = api_parse_status($_GET['status']);
    if ($status === null) {
        api_error(400, 'Parameter status harus salah satu dari: berizin, tidak_berizin, proses.');
    }
}

// ETag dari COUNT(*) + MAX(updated_at) supaya peta tidak unduh ulang data yang belum berubah.
$meta = repo_lokasi_etag_meta($status, $kontraktor_id_scope);
$etag = '"' . $meta['jumlah'] . '-' . ($meta['terakhir'] !== null ? strtotime((string) $meta['terakhir']) : 0) . '"';
header('ETag: ' . $etag);

$if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
if ($if_none_match === $etag) {
    http_response_code(304);
    exit;
}

$rows = repo_lokasi_list_for_map($status, $kontraktor_id_scope);

$features = [];
foreach ($rows as $row) {
    $features[] = [
        'type' => 'Feature',
        // GeoJSON pakai urutan [lng, lat], bukan [lat, lng].
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float) $row['longitude'], (float) $row['latitude']],
        ],
        'properties' => [
            // Properti ringan saja; detail lengkap ada di api/lokasi_detail.php.
            'id' => (int) $row['id'],
            'nama_lokasi' => $row['nama_lokasi'],
            'kontraktor' => $row['kontraktor_nama'],
            'material' => $row['material_nama'],
            'status_izin' => $row['status_izin'],
            'aktivitas_terakhir' => $row['aktivitas_terakhir'],
        ],
    ];
}

api_json(['type' => 'FeatureCollection', 'features' => $features]);
