<?php

declare(strict_types=1);

/**
 * api/area.php — FeatureCollection poligon area_geojson (FR-09/17; PRD bagian
 * 10, T-12). Sama seperti api/lokasi.php: peran kontraktor ter-scope ke
 * area miliknya sendiri, admin tetap lihat semua area.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

$user = api_boot();
$kontraktor_id_scope = $user['role'] === 'kontraktor' ? (int) ($user['kontraktor_id'] ?? 0) : null;

$rows = repo_lokasi_list_with_area($kontraktor_id_scope);

$features = [];
foreach ($rows as $row) {
    $decoded = json_decode((string) $row['area_geojson'], true);

    // Edge case T-12: area_geojson korup di database (mis. diedit manual,
    // atau bug versi lampau). LEWATI baris ini & catat log -- JANGAN
    // menjatuhkan seluruh respons karena satu baris rusak.
    if (!is_array($decoded) || !isset($decoded['geometry']['type'], $decoded['geometry']['coordinates'])) {
        app_log('warning', "api/area.php: area_geojson korup untuk lokasi id={$row['id']}, dilewati.");
        continue;
    }

    $features[] = [
        'type' => 'Feature',
        'geometry' => $decoded['geometry'],
        'properties' => [
            'id' => (int) $row['id'],
            'nama_lokasi' => $row['nama_lokasi'],
            'status_izin' => $row['status_izin'],
        ],
    ];
}

api_json(['type' => 'FeatureCollection', 'features' => $features]);
