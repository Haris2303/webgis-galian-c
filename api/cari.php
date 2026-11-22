<?php

declare(strict_types=1);

/**
 * api/cari.php — pencarian lokasi untuk peta (FR-20; PRD bagian 10, T-12).
 * q minimal 2 karakter, maksimal 10 hasil, LIKE ter-escape (reuse
 * db_like_escape() via repo_lokasi_list(), sudah ada sejak T-09). Peran
 * kontraktor ter-scope ke lokasi miliknya sendiri, konsisten dengan
 * api/lokasi.php & api/area.php -- pencarian tidak boleh jadi jalan belakang
 * untuk menemukan lokasi kontraktor lain yang sudah disembunyikan dari peta.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';

$user = api_boot();
$kontraktor_id_scope = $user['role'] === 'kontraktor' ? (int) ($user['kontraktor_id'] ?? 0) : null;

// Edge case T-12: q berisi % atau _ ditangani db_like_escape() di dalam
// repo_lokasi_list(); edge case lain -- q dikirim sebagai array (?q[]=x) --
// ditangkap eksplisit di sini SEBELUM trim()/mb_strlen() dipanggil padanya.
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
