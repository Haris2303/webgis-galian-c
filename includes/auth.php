<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/auth.php — fondasi keamanan (T-05, PRD bagian 11): header
 * keamanan, session hardening, dan throttling login. Di-require sekali dari
 * bootstrap.php, SETELAH error handler terpasang (lihat urutan di sana).
 *
 * CATATAN: require_login()/require_role() (guard RBAC per halaman) SENGAJA
 * TIDAK ada di sini — itu artefak T-06 ("Login/logout, RBAC dua peran"),
 * yang butuh tabel menu/rute per peran yang belum didesain T-05. auth.php
 * hanya menyediakan mekanisme sesi (login/logout/baca user aktif), bukan
 * keputusan otorisasi per halaman.
 */

require_once __DIR__ . '/repo/login_attempts.php';

define('LOGIN_THROTTLE_MAX_ATTEMPTS', 5);
define('LOGIN_THROTTLE_WINDOW_MINUTES', 15);
define('SESSION_IDLE_TIMEOUT_SECONDS', 30 * 60);
define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', 8 * 60 * 60);

/**
 * Nonce CSP per request, dipakai header Content-Security-Policy (di bawah)
 * DAN satu-satunya <style> inline proyek ini (includes/functions.php,
 * render_error_page()). static di dalam fungsi, bukan session, karena nonce
 * harus beda setiap request -- memakainya ulang lintas request meniadakan
 * gunanya (penyerang yang tahu satu nonce bisa reuse selamanya).
 */
function csp_nonce(): string
{
    static $nonce = null;

    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * Header keamanan dasar (PRD bagian 11), dipanggil sekali di bootstrap.php
 * sebelum byte apa pun lain dicetak. Tiap direktif CSP:
 * - default-src 'self'      : baseline, tolak semua asal selain origin sendiri
 *                              kecuali dilonggarkan direktif lebih spesifik di bawah.
 * - script-src 'self' https://unpkg.com : skrip hanya dari sendiri + unpkg.com
 *                              (Leaflet via CDN, PRD bagian 3). Tanpa 'unsafe-inline'
 *                              -- semua skrip proyek sudah di assets/js/*.js (T-04),
 *                              bukan <script> inline, jadi tidak perlu nonce di sini.
 * - style-src ... 'nonce-%s' : gaya hanya dari sendiri + unpkg.com (CSS Leaflet)
 *                              + SATU <style> inline yang masih ada (halaman error
 *                              darurat) lewat nonce per request, bukan 'unsafe-inline'
 *                              blanket.
 * - img-src ... tile.openstreetmap.org : tegas sesuai PRD, hanya tile peta.
 * - connect-src 'self'      : fetch() (PRD bagian 10) hanya ke endpoint sendiri.
 * - object-src 'none'       : blokir <object>/<embed>, tidak ada kebutuhan di app ini.
 * - base-uri 'self'         : cegah <base> disuntik untuk membajak URL relatif.
 * - form-action 'self'      : form hanya boleh submit ke origin sendiri.
 * - frame-ancestors 'none'  : versi CSP dari X-Frame-Options, menolak di-iframe sama sekali.
 */
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $csp = sprintf(
        "default-src 'self'; script-src 'self' https://unpkg.com; "
        . "style-src 'self' https://unpkg.com 'nonce-%s'; "
        . "img-src 'self' https://tile.openstreetmap.org; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        csp_nonce()
    );
    header('Content-Security-Policy: ' . $csp);
}

/**
 * Siapkan & mulai session dengan parameter aman, lalu tegakkan timeout idle
 * (30 menit) dan absolut (8 jam, PRD bagian 11). ini_set di sini WAJIB
 * sebelum session_start() -- sesudahnya tidak berefek. Tidak dipanggil dua
 * kali per request (dicek di baris pertama) supaya aman dipanggil ulang oleh
 * skrip dev/CLI yang butuh session sendiri (lihat tests/*.php).
 */
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
        'lifetime' => 0, // cookie sesi (hilang saat browser ditutup); batas absolut ditegakkan manual, bukan lewat cookie
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // gc_maxlifetime PHP hanya probabilistik (garbage collection, bukan jaminan
    // per-session), jadi timeout idle/absolut ditegakkan manual di sini lewat
    // timestamp sendiri -- satu-satunya cara memastikan berlaku untuk SETIAP sesi.
    $now = time();
    $kedaluwarsa_absolut = isset($_SESSION['_created_at']) && ($now - $_SESSION['_created_at']) > SESSION_ABSOLUTE_TIMEOUT_SECONDS;
    $kedaluwarsa_idle = isset($_SESSION['_last_activity']) && ($now - $_SESSION['_last_activity']) > SESSION_IDLE_TIMEOUT_SECONDS;

    if ($kedaluwarsa_absolut || $kedaluwarsa_idle) {
        // Sesi kedaluwarsa: kosongkan + ganti ID. Bila ada form lama masih
        // terbuka memakai csrf_token sesi ini, submit-nya akan gagal csrf_verify()
        // (token sudah hilang bersama $_SESSION) dan berakhir 419 -- disengaja,
        // lebih aman daripada memproses POST dari sesi yang sudah kedaluwarsa.
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_created_at'] = $now;
    } elseif (!isset($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = $now;
    }

    $_SESSION['_last_activity'] = $now;
}

/**
 * Tandai session sebagai sudah login. session_regenerate_id(true) + reset
 * total $_SESSION SEBELUM mengisi data user mencegah session fixation (ID
 * session pra-login, termasuk yang disuntik penyerang, tidak pernah dipakai
 * untuk sesi terautentikasi) dan memastikan csrf_token lama tidak terbawa.
 */
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

/** Hapus seluruh data session + cookie-nya. Dipanggil dari logout.php (T-06). */
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

/** Data user yang sedang login dari session, atau null bila belum login. */
function auth_user(): ?array
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

/**
 * Alamat IP klien untuk throttling. SENGAJA hanya REMOTE_ADDR, tidak membaca
 * X-Forwarded-For/X-Real-IP -- header itu dikirim klien sendiri dan mudah
 * dipalsukan untuk melewati throttle selama proyek ini belum punya reverse
 * proxy tepercaya yang menimpanya. Lihat Risiko tersisa T-05 bila nanti
 * dideploy di belakang proxy/load balancer.
 */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** True bila kombinasi username+IP ini sudah mencapai batas gagal (PRD bagian 11). */
function login_throttle_is_blocked(string $username, string $ip_address): bool
{
    return repo_login_attempt_count_recent($username, $ip_address, LOGIN_THROTTLE_WINDOW_MINUTES) >= LOGIN_THROTTLE_MAX_ATTEMPTS;
}

/** Catat satu percobaan gagal. Dipanggil HANYA setelah verifikasi kredensial gagal, bukan saat sudah terkunci. */
function login_throttle_record_failure(string $username, string $ip_address): void
{
    repo_login_attempt_record($username, $ip_address);
    app_log('warning', sprintf('Login gagal: username="%s" ip=%s', $username, $ip_address));
}
