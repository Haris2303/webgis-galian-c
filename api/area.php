<?php

declare(strict_types=1);

// api/area.php — FeatureCollection poligon area_geojson; kontraktor ter-scope ke area miliknya sendiri.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

$user = api_boot();
$kontraktor_id_scope = $user['role'] === 'kontraktor' ? (int) ($user['kontraktor_id'] ?? 0) : null;

$rows = repo_lokasi_list_with_area($kontraktor_id_scope);

$features = [];
foreach ($rows as $row) {
    $decoded = json_decode((string) $row['area_geojson'], true);

    // Lewati baris dengan area_geojson korup, jangan gagalkan seluruh respons.
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
