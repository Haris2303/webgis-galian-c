<?php

declare(strict_types=1);

/**
 * logout.php — FR-02 (PRD bagian 5). Hanya menerima POST + CSRF (spesifikasi
 * T-06) -- logout lewat GET bisa dipicu lintas-situs (mis. <img src="logout.php">
 * dari halaman lain) tanpa sepengetahuan pengguna, jadi method lain ditolak
 * tegas, bukan "berhasil secara diam-diam".
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort(405, 'Logout hanya bisa lewat POST.');
}

csrf_require();

auth_logout();

// Redundan dengan Cache-Control yang sudah dipasang require_login() di
// halaman-halaman sebelumnya, tapi tetap dipasang di sini juga supaya respons
// logout.php sendiri juga tidak pernah di-cache apa pun.
header('Cache-Control: no-store');

redirect('login.php');
