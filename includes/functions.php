<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Escape output untuk mencegah XSS. Dipakai di semua tempat yang mencetak data ke HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Bangun URL absolut berbasis BASE_URL, aman dipakai baik di subfolder XAMPP maupun root hosting.
 */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');

    return rtrim(BASE_URL, '/') . ($path !== '' ? '/' . $path : '/');
}

/**
 * URL aset statis dengan cache-busting berdasarkan filemtime, supaya browser
 * otomatis ambil versi baru setiap kali berkas CSS/JS diubah.
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $full_path = rtrim(PROJECT_ROOT, '/\\') . '/' . $path;
    $version = is_file($full_path) ? filemtime($full_path) : time();

    return url($path) . '?v=' . $version;
}

/**
 * Redirect lalu hentikan eksekusi. Buffer output dibersihkan dulu agar tidak
 * memicu error "headers already sent" bila ada output tersisa sebelum redirect.
 */
function redirect(string $path, int $status_code = 302): void
{
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $location = preg_match('#^https?://#i', $path) ? $path : url($path);
    header('Location: ' . $location, true, $status_code);
    exit;
}

/**
 * Kirim respons JSON lalu hentikan eksekusi. Dipakai endpoint api/*.php.
 */
function json_response($data, int $status_code = 200): void
{
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Deteksi apakah permintaan ini AJAX/JSON (bukan navigasi browser biasa),
 * lewat header X-Requested-With atau Accept. Dipakai untuk memilih bentuk respons error.
 */
function is_ajax_request(): bool
{
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if (strcasecmp($xhr, 'XMLHttpRequest') === 0) {
        return true;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if ($accept !== '' && stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false) {
        return true;
    }

    return false;
}

/**
 * Hentikan permintaan dengan kode HTTP tertentu dan pesan ramah berbahasa Indonesia.
 * Beradaptasi: JSON untuk permintaan AJAX, halaman HTML untuk navigasi browser biasa.
 */
function abort(int $code, ?string $message = null): void
{
    $default_messages = [
        400 => 'Permintaan tidak valid.',
        401 => 'Anda harus masuk untuk mengakses halaman ini.',
        403 => 'Akses ditolak.',
        404 => 'Halaman tidak ditemukan.',
        405 => 'Metode permintaan tidak diizinkan.',
        500 => 'Terjadi kesalahan pada sistem.',
    ];

    $final_message = $message ?? ($default_messages[$code] ?? 'Terjadi kesalahan.');

    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (is_ajax_request()) {
        json_response(['error' => $final_message], $code);
    }

    render_error_page($code, $final_message);
    exit;
}

/**
 * Tulis satu baris log ke storage/logs/app-YYYYMMDD.log (rotasi harian berdasarkan tanggal).
 * Bila folder log tidak bisa ditulis, jatuh ke error_log() PHP bawaan supaya aplikasi
 * tidak crash hanya karena logging gagal.
 */
function app_log(string $level, string $message): void
{
    $log_dir = STORAGE_PATH . '/logs';
    $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;

    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    if (is_dir($log_dir) && is_writable($log_dir)) {
        $log_file = $log_dir . '/app-' . date('Ymd') . '.log';
        $written = @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
        if ($written !== false) {
            return;
        }
    }

    @error_log('[webgis-galian-c] ' . $line);
}

/**
 * Halaman error HTML sederhana (header.php/style.css global belum ada, jadi di-styling inline).
 * $debug diisi hanya saat APP_ENV=dev.
 */
function render_error_page(int $code, string $message, ?array $debug = null): void
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kesalahan <?= e((string) $code) ?> — WebGIS Galian C</title>
<style>
  body{font-family:system-ui,"Segoe UI",Roboto,sans-serif;background:#F4F6F9;color:#1E3A5F;margin:0;padding:48px 16px;display:flex;justify-content:center;}
  .box{max-width:640px;width:100%;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.12);padding:32px;}
  h1{margin:0 0 8px;font-size:22px;color:#D64545;}
  p{line-height:1.5;margin:0 0 8px;}
  pre{background:#F4F6F9;padding:12px;border-radius:6px;overflow:auto;font-size:12px;white-space:pre-wrap;}
</style>
</head>
<body>
<div class="box">
  <h1>Kesalahan <?= e((string) $code) ?></h1>
  <p><?= e($message) ?></p>
  <?php if ($debug !== null): ?>
  <pre><?= e(print_r($debug, true)) ?></pre>
  <?php endif; ?>
</div>
</body>
</html>
<?php
}
