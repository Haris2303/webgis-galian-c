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

// Deteksi BASE_URL otomatis agar benar baik di subfolder XAMPP (htdocs/webgis-galian-c)
// maupun saat proyek jadi document root di hosting. Bisa ditimpa manual lewat
// define('BASE_URL', '...') di config.local.php untuk kasus reverse proxy.
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

// Batas unggah foto dokumentasi aktivitas (PRD FR-14).
define('UPLOAD_MAX_SIZE', 2 * 1024 * 1024); // 2 MB per foto
define('UPLOAD_MAX_FILES', 5);              // maksimal 5 foto per aktivitas
define('UPLOAD_ALLOWED_MIME', ['image/jpeg', 'image/png']);

// T-18: dimensi piksel MAKSIMAL diperiksa lewat getimagesize() SEBELUM
// imagecreatefrom*() dipanggil -- getimagesize() hanya membaca header, bukan
// mendekode seluruh bitmap, jadi aman dipanggil pada berkas apa pun (termasuk
// upaya decompression bomb); imagecreatefrom*() TIDAK aman dipanggil langsung
// pada dimensi tak terbatas karena ia mendekode penuh ke memori.
define('UPLOAD_MAX_DIMENSION', 6000);  // px, sisi mana pun, sebelum decode
define('UPLOAD_RESIZE_MAX', 1600);     // px, sisi terpanjang hasil re-encode
define('UPLOAD_THUMBNAIL_MAX', 320);   // px, sisi terpanjang thumbnail

// Bounding box Kota Sorong untuk validasi koordinat (PRD bagian 11).
define('SORONG_LAT_MIN', -1.05);
define('SORONG_LAT_MAX', -0.75);
define('SORONG_LNG_MIN', 131.15);
define('SORONG_LNG_MAX', 131.45);

// Pejabat penandatangan laporan monitoring (FR-22, PRD bagian 9 #7, T-22).
// Dibaca dari SINI (bukan di-hardcode di laporan/index.php) supaya pergantian
// pejabat tidak perlu sentuh kode. GANTI nilai di bawah sebelum laporan ini
// dipakai sungguhan -- placeholder sengaja terlihat jelas sebagai placeholder,
// bukan nama kosong yang bisa lolos tercetak tanpa disadari.
define('LAPORAN_PEJABAT_JABATAN', 'Kepala Bidang Pertambangan dan Energi');
define('LAPORAN_PEJABAT_NAMA', '(Isi nama pejabat di config/config.php)');
define('LAPORAN_PEJABAT_NIP', ''); // opsional -- kosongkan bila tidak perlu dicetak
