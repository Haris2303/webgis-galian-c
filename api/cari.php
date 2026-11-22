<?php

declare(strict_types=1);

// api/cari.php — pencarian lokasi untuk peta; kontraktor ter-scope ke lokasi miliknya sendiri.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

$user = api_boot();
$kontraktor_id_scope = $user['role'] === 'kontraktor' ? (int) ($user['kontraktor_id'] ?? 0) : null;

// q bisa dikirim sebagai array (?q[]=x); ditolak di sini sebelum trim()/mb_strlen().
$q_raw = $_GET['q'] ?? null;
if (!is_string($q_raw)) {
    api_error(400, 'Parameter q wajib diisi sebagai teks.');
}

$q = trim($q_raw);
if (mb_strlen($q) < 2) {
    api_error(400, 'Parameter q minimal 2 karakter.');
}

$filter_cari = ['search' => $q];
if ($kontraktor_id_scope !== null) {
    $filter_cari['kontraktor_id'] = $kontraktor_id_scope;
}

[$rows] = repo_lokasi_list($filter_cari, 1, 10, 'nama_lokasi', 'asc');

$hasil = array_map(static function (array $row): array {
    return [
        'id' => (int) $row['id'],
        'nama_lokasi' => $row['nama_lokasi'],
        'lat' => (float) $row['latitude'],
        'lng' => (float) $row['longitude'],
        'status_izin' => $row['status_izin'],
    ];
}, $rows);

api_json($hasil);
