<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// Lokasi fisik proyek, dipakai untuk membaca berkas (cache-busting asset, dsb).
define('PROJECT_ROOT', dirname(__DIR__));
define('STORAGE_PATH', PROJECT_ROOT . '/storage');

// Kredensial DB dan APP_ENV didefinisikan di config.local.php (di luar Git).
require __DIR__ . '/config.local.php';

if (!defined('APP_ENV')) {
    define('APP_ENV', 'prod'); // default paling aman bila config.local.php lupa mengisinya
}

// Deteksi BASE_URL otomatis; bisa ditimpa manual lewat define('BASE_URL', ...) di config.local.php.
if (!defined('BASE_URL')) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $is_https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $doc_root = isset($_SERVER['DOCUMENT_ROOT'])
        ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/')
        : '';
    $project_root_norm = rtrim(str_replace('\\', '/', PROJECT_ROOT), '/');

    $base_path = '';
    if ($doc_root !== '' && strpos($project_root_norm, $doc_root) === 0) {
        $base_path = substr($project_root_norm, strlen($doc_root));
    }

    define('BASE_URL', $scheme . '://' . $host . $base_path);

    unset($is_https, $scheme, $host, $doc_root, $project_root_norm, $base_path);
}

// Batas unggah foto dokumentasi aktivitas.
define('UPLOAD_MAX_SIZE', 2 * 1024 * 1024); // 2 MB per foto
define('UPLOAD_MAX_FILES', 5);              // maksimal 5 foto per aktivitas
define('UPLOAD_ALLOWED_MIME', ['image/jpeg', 'image/png']);

// Dimensi diperiksa via getimagesize() (baca header saja) sebelum imagecreatefrom*(), yang mendekode penuh ke memori.
define('UPLOAD_MAX_DIMENSION', 6000);  // px, sisi mana pun, sebelum decode
define('UPLOAD_RESIZE_MAX', 1600);     // px, sisi terpanjang hasil re-encode
define('UPLOAD_THUMBNAIL_MAX', 320);   // px, sisi terpanjang thumbnail

// Bounding box Kota Sorong untuk validasi koordinat.
define('SORONG_LAT_MIN', -1.05);
define('SORONG_LAT_MAX', -0.75);
define('SORONG_LNG_MIN', 131.15);
define('SORONG_LNG_MAX', 131.45);

// Pejabat penandatangan laporan monitoring -- ganti nilai di bawah sebelum laporan dipakai sungguhan.
define('LAPORAN_PEJABAT_JABATAN', 'Kepala Bidang Pertambangan dan Energi');
define('LAPORAN_PEJABAT_NAMA', 'Hendra Sandra, S.M., M.M');
define('LAPORAN_PEJABAT_NIP', ''); // opsional -- kosongkan bila tidak perlu dicetak
