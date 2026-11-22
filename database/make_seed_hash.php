<?php

declare(strict_types=1);

/**
 * database/make_seed_hash.php
 *
 * Membangkitkan hash password (bcrypt via password_hash()) untuk akun dev di
 * database/seed.sql. MySQL/MariaDB tidak punya fungsi yang kompatibel dengan
 * password_hash()/password_verify() PHP, jadi hash tidak bisa dibuat langsung
 * dari SQL — skrip ini dijalankan di CLI, hasilnya ditempel sebagai literal
 * ke statement INSERT INTO users di seed.sql.
 *
 * Setiap kali dijalankan, password_hash() memakai salt acak sehingga string
 * hash yang dicetak akan berbeda dari run sebelumnya walau password sama. Itu
 * normal: password_verify($password, $hash) tetap valid untuk hash manapun
 * yang dihasilkan dari password yang sama. Password di bawah ini HANYA untuk
 * data dev/demo — ganti sebelum deploy produksi (lihat database/README.md).
 *
 * Pemakaian: php database/make_seed_hash.php
 */

// T-26: tolak dieksekusi lewat web SAMA SEKALI, apa pun konfigurasi server
// (Apache/.htaccess ATAU nginx -- keduanya TIDAK bisa diandalkan secara
// portabel, lihat Keputusan/temuan T-26). Skrip ini mencetak password
// PLAINTEXT akun dev; satu-satunya cara jalan yang benar adalah CLI
// (`php database/make_seed_hash.php`), PHP_SAPI selalu 'cli' untuk itu.
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
