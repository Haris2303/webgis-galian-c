<?php

declare(strict_types=1);

/**
 * tests/rbac_preview.php — harness verifikasi T-06 (require_login,
 * require_role, can_access_lokasi, can_access_aktivitas), BUKAN halaman
 * asli. kontraktor/index.php (T-08) dan halaman detail aktivitas (T-09/T-17)
 * yang disebut langsung di kriteria verifikasi T-06 belum ada, jadi rute
 * sintetis ini dipakai sebagai gantinya untuk sekarang.
 *
 * Harus 404 di luar APP_ENV=dev. WAJIB dihapus begitu halaman asli (T-08,
 * T-09, T-17) berdiri dan verifikasinya bisa dipindah ke sana.
 *
 * Akses: /tests/rbac_preview.php?route=admin-only|lokasi|aktivitas&id=...
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (APP_ENV !== 'dev') {
    http_response_code(404);
    exit('Not Found');
}

header('Content-Type: text/plain; charset=utf-8');

$route = $_GET['route'] ?? '';

if ($route === 'admin-only') {
    $user = require_role('admin'); // 403 eksplisit bila bukan admin (T-06)
    echo "OK admin-only. Login sebagai: {$user['username']} (peran: {$user['role']})\n";
    exit;
}

if ($route === 'lokasi') {
    $user = require_login();
    $id = (int) ($_GET['id'] ?? 0);

    if (!can_access_lokasi($user, $id)) {
        abort(404); // sama untuk ID tak ada maupun ID milik pihak lain (anti kebocoran info)
    }

    echo "OK akses lokasi id={$id} untuk {$user['username']} (peran: {$user['role']})\n";
    exit;
}

if ($route === 'aktivitas') {
    $user = require_login();
    $id = (int) ($_GET['id'] ?? 0);

    if (!can_access_aktivitas($user, $id)) {
        abort(404);
    }

    echo "OK akses aktivitas id={$id} untuk {$user['username']} (peran: {$user['role']})\n";
    exit;
}

http_response_code(400);
echo "Route tidak dikenal. Gunakan ?route=admin-only|lokasi|aktivitas&id=...\n";
