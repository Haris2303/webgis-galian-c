<?php

// Berkas tunggal yang wajib dimuat semua halaman. Urutan di sini penting.

declare(strict_types=1);

// Output buffering agar halaman HTML setengah jadi bisa dibuang (ob_end_clean) saat terjadi exception.
if (ob_get_level() === 0) {
    ob_start();
}

// Guard: define() ulang memicu E_WARNING walau nilainya sama.
if (!defined('APP_BOOTSTRAPPED')) {
    define('APP_BOOTSTRAPPED', true);
}

// Ditangani terpisah dari exception handler umum karena APP_ENV belum tentu terdefinisi pada titik ini.
$local_config_path = __DIR__ . '/../config/config.local.php';
if (!is_file($local_config_path)) {
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    $setup_message = 'Konfigurasi belum lengkap. Salin config/config.local.example.php menjadi config/config.local.php, lalu isi kredensial database.';
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $wants_json = strcasecmp($xhr, 'XMLHttpRequest') === 0
        || (stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false);
    if ($wants_json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $setup_message]);
    } else {
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<title>Konfigurasi belum lengkap</title></head><body style="font-family:system-ui,sans-serif;padding:48px">'
            . '<h1>Konfigurasi belum lengkap</h1><p>' . htmlspecialchars($setup_message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</body></html>';
    }
    exit;
}
unset($local_config_path);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

date_default_timezone_set('Asia/Jayapura');

// db() lazy-connect, aman di-require sebelum error handler/timezone selesai disetel.
require_once __DIR__ . '/../config/database.php';

error_reporting(E_ALL);
ini_set('log_errors', '0'); // logging ditangani manual oleh app_log()
if (APP_ENV === 'prod') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// Error setingkat fatal diubah jadi ErrorException agar ditangani lewat exception handler di bawah.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $fatal_levels = E_USER_ERROR | E_RECOVERABLE_ERROR;
    if ($severity & $fatal_levels) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    app_log('warning', sprintf('%s in %s:%d', $message, $file, $line));

    return true;
});

// Satu-satunya tempat yang menentukan bentuk respons akhir: HTML untuk browser, JSON untuk AJAX, detail lengkap hanya di APP_ENV=dev.
set_exception_handler(static function (Throwable $e): void {
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    app_log('error', sprintf(
        '%s: %s in %s:%d%sStack trace:%s%s',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        PHP_EOL,
        $e->getTraceAsString()
    ));

    if (!headers_sent()) {
        http_response_code(500);
    }

    $is_dev = APP_ENV === 'dev';
    $generic_message = 'Terjadi kesalahan pada sistem. Silakan coba lagi nanti.';
    $public_message = $is_dev ? $e->getMessage() : $generic_message;
    $debug = $is_dev ? [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ] : null;

    if (is_ajax_request()) {
        json_response(['error' => $public_message] + ($debug !== null ? ['debug' => $debug] : []), 500);
    }

    render_error_page(500, $public_message, $debug);
    exit;
});

// Jaring pengaman terakhir untuk fatal error yang tidak lewat set_error_handler.
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatal_levels = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($error['type'], $fatal_levels, true)) {
        return;
    }

    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    app_log('error', sprintf('Fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));

    if (!headers_sent()) {
        http_response_code(500);
    }

    $is_dev = APP_ENV === 'dev';
    $generic_message = 'Terjadi kesalahan pada sistem. Silakan coba lagi nanti.';
    $public_message = $is_dev ? $error['message'] : $generic_message;

    if (is_ajax_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $public_message]);

        return;
    }

    render_error_page(500, $public_message, $is_dev ? $error : null);
});

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/validator.php';
require_once __DIR__ . '/upload_foto.php';

// Log saja (tidak menghentikan aplikasi) karena hanya fitur unggah foto yang butuh GD.
if (!upload_gd_tersedia()) {
    app_log('warning', 'Ekstensi PHP GD tidak aktif/lengkap -- unggah foto dokumentasi aktivitas (FR-14) tidak akan berfungsi. Pasang/aktifkan ekstensi gd di php.ini.');
}

// Diletakkan di akhir, setelah error handler terpasang, supaya exception di dalamnya tertangkap rapi.
security_headers();
auth_start_session();
