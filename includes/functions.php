<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// ENT_SUBSTITUTE: byte UTF-8 tidak valid diganti karakter pengganti, bukan mengosongkan seluruh string.
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');

    return rtrim(BASE_URL, '/') . ($path !== '' ? '/' . $path : '/');
}

// Gabungkan markup <link>/<script> yang sudah dibentuk pemanggil (server), bukan dari data pengguna.
function render_raw_tags(array $tags): string
{
    return implode("\n", $tags);
}

// Cache-busting berdasarkan filemtime agar browser otomatis ambil versi baru.
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $full_path = rtrim(PROJECT_ROOT, '/\\') . '/' . $path;
    $version = is_file($full_path) ? filemtime($full_path) : time();

    return url($path) . '?v=' . $version;
}

// Buffer dibersihkan dulu agar tidak memicu error "headers already sent".
function redirect(string $path, int $status_code = 302): void
{
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $location = preg_match('#^https?://#i', $path) ? $path : url($path);
    header('Location: ' . $location, true, $status_code);
    exit;
}

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

// Beradaptasi: JSON untuk permintaan AJAX, halaman HTML untuk navigasi browser biasa.
function abort(int $code, ?string $message = null): void
{
    $default_messages = [
        400 => 'Permintaan tidak valid.',
        401 => 'Anda harus masuk untuk mengakses halaman ini.',
        403 => 'Akses ditolak.',
        404 => 'Halaman tidak ditemukan.',
        405 => 'Metode permintaan tidak diizinkan.',
        419 => 'Sesi Anda telah berakhir atau token keamanan tidak valid. Muat ulang halaman dan coba lagi.',
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

// Jatuh ke error_log() PHP bawaan bila folder log tidak bisa ditulis, supaya aplikasi tidak crash hanya karena logging gagal.
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

// Satu token acak per session (bukan per form), dibuat sekali dan dipakai ulang selama session berlangsung.
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Field tersembunyi non-file tetap terbaca di $_POST pada request multipart (upload foto), tidak butuh penanganan khusus.
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

// Lewat hash_equals() agar tahan timing attack. Murni predikat -- tidak menghentikan request.
function csrf_verify(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['csrf_token'])) {
        return false;
    }

    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    return is_string($submitted) && $submitted !== '' && hash_equals($_SESSION['csrf_token'], $submitted);
}

// No-op untuk method selain POST, jadi aman dipanggil di berkas yang merangkap GET dan POST sekaligus.
function csrf_require(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_verify()) {
        abort(419);
    }
}

// Token sekali-pakai anti-double-submit, beda dari csrf_token(): baru per render form dan hangus begitu dikonsumsi.
function form_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['form_tokens'][$token] = time();

    // Batasi jumlah token tertunda supaya session tidak membengkak.
    if (count($_SESSION['form_tokens']) > 20) {
        array_shift($_SESSION['form_tokens']);
    }

    return $token;
}

// True & token langsung dihapus (sekali pakai) bila valid; false bila sudah dipakai/tidak pernah ada.
function form_token_consume(?string $token): bool
{
    if ($token === null || $token === '' || session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['form_tokens'][$token])) {
        return false;
    }

    unset($_SESSION['form_tokens'][$token]);

    return true;
}

// Flash message ditampilkan sekali di request berikutnya (umumnya setelah redirect dari aksi simpan/hapus).
function flash(string $type, string $message, array $extra = []): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['flash'][] = ['type' => $type, 'message' => $message, 'extra' => $extra];
}

// Sekali dibaca, tidak akan tampil lagi walau halaman di-refresh.
function flash_pull(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }

    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

// Styling inline sengaja, karena fungsi ini juga jaring pengaman saat bootstrap/koneksi DB gagal dan tidak boleh bergantung pada berkas lain.
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
<style nonce="<?= e(csp_nonce()) ?>">
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
