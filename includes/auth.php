<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/repo/login_attempts.php';
require_once __DIR__ . '/repo/users.php';
require_once __DIR__ . '/repo/lokasi.php';
require_once __DIR__ . '/repo/aktivitas.php';

define('LOGIN_THROTTLE_MAX_ATTEMPTS', 5);
define('LOGIN_THROTTLE_WINDOW_MINUTES', 15);
define('SESSION_IDLE_TIMEOUT_SECONDS', 30 * 60);
define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', 8 * 60 * 60);

// Hash bcrypt valid tapi tak pernah cocok; dipakai saat username tidak ditemukan agar waktu respons tidak membocorkan keberadaannya (timing attack).
define('DUMMY_PASSWORD_HASH', '$2y$10$Cp7gxfO0sJLKEPbE8lglbub0DWrBvEz0M0AnYuCbwbAvaUbjnAkZm');

// Nonce CSP per request; static (bukan session) karena harus beda setiap request agar tidak bisa dipakai ulang.
function csp_nonce(): string
{
    static $nonce = null;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

// Header keamanan dasar, dipanggil sekali di bootstrap.php sebelum byte apa pun dicetak.
// img-src mengizinkan domain tile Google tanpa API key resmi -- bisa berhenti bekerja atau diblokir tanpa pemberitahuan.
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $csp = sprintf(
        "default-src 'self'; script-src 'self' https://unpkg.com; "
        . "style-src 'self' https://unpkg.com https://fonts.googleapis.com 'nonce-%s'; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' blob: https://tile.openstreetmap.org https://unpkg.com https://*.google.com https://*.googleapis.com https://*.gstatic.com; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        csp_nonce()
    );
    header('Content-Security-Policy: ' . $csp);
}

// Siapkan & mulai session, lalu tegakkan timeout idle dan absolut. ini_set harus sebelum session_start() -- sesudahnya tidak berefek.
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('webgis_sid');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Timeout ditegakkan manual lewat timestamp karena gc_maxlifetime PHP hanya probabilistik.
    $now = time();
    $kedaluwarsa_absolut = isset($_SESSION['_created_at']) && ($now - $_SESSION['_created_at']) > SESSION_ABSOLUTE_TIMEOUT_SECONDS;
    $kedaluwarsa_idle = isset($_SESSION['_last_activity']) && ($now - $_SESSION['_last_activity']) > SESSION_IDLE_TIMEOUT_SECONDS;

    if ($kedaluwarsa_absolut || $kedaluwarsa_idle) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_created_at'] = $now;
    } elseif (!isset($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = $now;
    }

    $_SESSION['_last_activity'] = $now;
}

// Regenerate ID + reset $_SESSION sebelum mengisi data user mencegah session fixation.
function auth_login(array $user): void
{
    session_regenerate_id(true);

    $now = time();
    $_SESSION = [
        '_created_at' => $now,
        '_last_activity' => $now,
        'user_id' => (int) $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'kontraktor_id' => $user['kontraktor_id'] ?? null,
        'nama_lengkap' => $user['nama_lengkap'],
    ];
}

// Hapus seluruh data session + cookie-nya.
function auth_logout(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

// Data user yang sedang login dari session, atau null bila belum login.
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'kontraktor_id' => $_SESSION['kontraktor_id'] ?? null,
        'nama_lengkap' => $_SESSION['nama_lengkap'] ?? '',
    ];
}

// Path relatif (tanpa BASE_URL) dari permintaan saat ini, format sama dengan yang diterima url()/redirect().
function current_request_path(): string
{
    $base_path = rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');

    $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
    $request_path = (string) (parse_url($request_uri, PHP_URL_PATH) ?? '/');
    $query = parse_url($request_uri, PHP_URL_QUERY);

    $relative = ($base_path !== '' && str_starts_with($request_path, $base_path))
        ? substr($request_path, strlen($base_path))
        : $request_path;
    $relative = ltrim($relative, '/');

    return $relative . ($query ? '?' . $query : '');
}

// Validasi ?next= agar hanya menerima path internal relatif, mencegah open redirect.
function validate_next_path(?string $next): ?string
{
    if ($next === null || $next === '') {
        return null;
    }

    if (strpos($next, ':') !== false || str_starts_with($next, '/') || strpos($next, '..') !== false) {
        return null;
    }

    if (!preg_match('#^[A-Za-z0-9_\-./]+(?:\?[A-Za-z0-9_\-.=&%]*)?$#', $next)) {
        return null;
    }

    return $next;
}

// Mengecek ulang is_active ke DB setiap panggilan agar akun yang baru dinonaktifkan langsung ter-logout, bukan menunggu sesi kedaluwarsa.
function require_login(): array
{
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }

    $user = current_user();

    if ($user !== null) {
        $fresh = repo_user_find($user['id']);
        if ($fresh === null || (int) $fresh['is_active'] !== 1) {
            auth_logout();
            flash('error', 'Akun Anda tidak lagi aktif. Silakan masuk kembali atau hubungi admin.');
            redirect('login.php');
        }

        return $user;
    }

    $next = current_request_path();
    redirect('login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
}

// Mengembalikan 403 eksplisit bila peran tidak cocok, bukan redirect diam-diam.
function require_role(string|array $roles): array
{
    $user = require_login();

    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($user['role'], $allowed, true)) {
        abort(403);
    }

    return $user;
}

// Mengembalikan false baik untuk ID yang tidak ada maupun milik pihak lain, agar respons identik dan tidak membocorkan informasi.
function can_access_lokasi(array $user, int $lokasi_id): bool
{
    $lokasi = repo_lokasi_find($lokasi_id);
    if ($lokasi === null) {
        return false;
    }

    if ($user['role'] === 'admin') {
        return true;
    }

    return (int) $lokasi['kontraktor_id'] === (int) ($user['kontraktor_id'] ?? 0);
}

// Kepemilikan ditentukan lewat kontraktor_id perusahaan (via lokasi_tambang), bukan user_id individu, agar tetap benar bila satu kontraktor punya beberapa akun.
function can_access_aktivitas(array $user, int $aktivitas_id): bool
{
    $aktivitas = repo_aktivitas_find($aktivitas_id);
    if ($aktivitas === null) {
        return false;
    }

    if ($user['role'] === 'admin') {
        return true;
    }

    return can_access_lokasi($user, (int) $aktivitas['lokasi_id']);
}

// Sengaja hanya REMOTE_ADDR, bukan X-Forwarded-For, karena header itu mudah dipalsukan klien untuk melewati throttle.
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// True bila kombinasi username+IP ini sudah mencapai batas gagal.
function login_throttle_is_blocked(string $username, string $ip_address): bool
{
    return repo_login_attempt_count_recent($username, $ip_address, LOGIN_THROTTLE_WINDOW_MINUTES) >= LOGIN_THROTTLE_MAX_ATTEMPTS;
}

// Alfabet menghindari karakter rupa-mirip (0/O, 1/l/I) supaya mudah disalin/dibacakan manual.
function generate_strong_password(int $length = 14): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%&*';
    $max_index = strlen($alphabet) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max_index)];
    }

    return $password;
}

// Dipanggil hanya setelah verifikasi kredensial gagal, bukan saat sudah terkunci.
function login_throttle_record_failure(string $username, string $ip_address): void
{
    repo_login_attempt_record($username, $ip_address);
    app_log('warning', sprintf('Login gagal: username="%s" ip=%s', $username, $ip_address));
}
