<?php

declare(strict_types=1);

// api/statistik.php — kartu statistik dashboard; kontraktor ter-scope ke kontraktor_id sendiri.

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
