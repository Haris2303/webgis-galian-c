<?php

declare(strict_types=1);

/**
 * api/lokasi.php — FeatureCollection titik lokasi tambang (FR-17/18/20; PRD
 * bagian 10, T-12). Keputusan produk (menggantikan catatan T-12 sebelumnya):
 * peran kontraktor di peta HANYA melihat marker lokasi miliknya sendiri --
 * ter-scope lewat kontraktor_id, konsisten dengan dashboard.php
 * ($kontraktor_id_scope) & api/lokasi_detail.php (DETAIL aktivitas, yang
 * tetap dibatasi kepemilikan). Admin tetap melihat semua marker.
 */

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

// ETag dari COUNT(*) + MAX(updated_at) PADA FILTER YANG SAMA (spesifikasi
// T-12) -- supaya peta tidak mengunduh ulang FeatureCollection yang isinya
// belum berubah sejak permintaan sebelumnya (304 Not Modified).
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
