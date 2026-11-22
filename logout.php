<?php

declare(strict_types=1);

// Hanya menerima POST+CSRF -- logout lewat GET bisa dipicu lintas-situs (mis. <img src="logout.php">) tanpa sepengetahuan pengguna.

require_once __DIR__ . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    abort(405, 'Logout hanya bisa lewat POST.');
}

csrf_require();

auth_logout();

header('Cache-Control: no-store');

redirect('login.php');
