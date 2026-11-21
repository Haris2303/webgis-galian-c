<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// Salin berkas ini menjadi "config.local.php" lalu sesuaikan nilainya.
// config.local.php TIDAK ikut masuk Git (lihat .gitignore) karena berisi kredensial.

// 'dev'  -> tampilkan detail error, cocok untuk pengembangan di XAMPP/Laragon.
// 'prod' -> sembunyikan detail error dari pengguna, cocok untuk server produksi.
define('APP_ENV', 'dev');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'db_galian_c');
define('DB_USER', 'root');
define('DB_PASS', '');

// Opsional: isi hanya jika auto-deteksi BASE_URL di config.php keliru
// (misalnya di belakang reverse proxy atau alias domain khusus).
// define('BASE_URL', 'http://localhost/webgis-galian-c');
