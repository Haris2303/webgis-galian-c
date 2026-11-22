<?php

declare(strict_types=1);

// Membangkitkan hash bcrypt untuk akun dev di seed.sql; hash berbeda tiap run (salt acak) tapi tetap valid untuk password yang sama -- hanya untuk data dev/demo, ganti sebelum deploy produksi.

// Skrip ini mencetak password plaintext akun dev, jadi tolak dieksekusi lewat web sama sekali.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: jalankan hanya lewat command line (php database/make_seed_hash.php).');
}

$akun = [
    'admin'       => 'admin123',
    'kontraktor1' => 'kontraktor123',
    'kontraktor2' => 'kontraktor123',
    'kontraktor3' => 'kontraktor123',
];

foreach ($akun as $username => $password) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    printf("%-14s password: %-16s hash: %s\n", $username, $password, $hash);
}
