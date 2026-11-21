<?php

declare(strict_types=1);

/**
 * api/lokasi.php — FeatureCollection titik lokasi tambang (FR-17/18/20; PRD
 * bagian 10, T-12). Semua peran login boleh melihat SEMUA marker ("Kontraktor
 * tetap boleh melihat semua marker", PRD bagian 10) -- tidak difilter per
 * kontraktor di sini; yang dibatasi per kepemilikan hanya DETAIL aktivitas
 * (api/lokasi_detail.php).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

api_boot();

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

// ETag dari COUNT(*) + MAX(updated_at) PADA FILTER YANG SAMA (spesifikasi
// T-12) -- supaya peta tidak mengunduh ulang FeatureCollection yang isinya
// belum berubah sejak permintaan sebelumnya (304 Not Modified).
$meta = repo_lokasi_etag_meta($status);
$etag = '"' . $meta['jumlah'] . '-' . ($meta['terakhir'] !== null ? strtotime((string) $meta['terakhir']) : 0) . '"';
header('ETag: ' . $etag);

$if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
if ($if_none_match === $etag) {
    http_response_code(304);
    exit;
}

$rows = repo_lokasi_list_for_map($status);

$features = [];
foreach ($rows as $row) {
    $features[] = [
        'type' => 'Feature',
        // GeoJSON WAJIB [lng, lat] (RFC 7946) -- lihat juga catatan serupa di
        // includes/validator.php & assets/js/map-picker.js (T-07/T-10).
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float) $row['longitude'], (float) $row['latitude']],
        ],
        'properties' => [
            // Properti RINGAN saja (spesifikasi T-12) -- detail lengkap +
            // aktivitas/foto ada di api/lokasi_detail.php, bukan di sini.
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
