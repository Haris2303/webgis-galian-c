<?php

declare(strict_types=1);

/**
 * api/statistik.php — kartu statistik dashboard (FR-04; PRD bagian 10, T-12).
 * Admin: semua lokasi. Kontraktor: ter-scope ke kontraktor_id sendiri saja
 * ("Kontraktor hanya lokasinya", FR-04) -- satu query GROUP BY di
 * repo_lokasi_count_by_status(), bukan dua query beda untuk dua peran.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

$user = api_boot();

$kontraktor_id = $user['role'] === 'kontraktor' ? (int) ($user['kontraktor_id'] ?? 0) : null;
$hasil = repo_lokasi_count_by_status($kontraktor_id);

api_json([
    'total' => $hasil['berizin'] + $hasil['tidak_berizin'] + $hasil['proses'],
    'berizin' => $hasil['berizin'],
    'tidak_berizin' => $hasil['tidak_berizin'],
    'proses' => $hasil['proses'],
]);
