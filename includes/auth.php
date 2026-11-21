<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/auth.php — fondasi keamanan (T-05) + autentikasi/otorisasi (T-06,
 * PRD bagian 2 & 5). Di-require sekali dari bootstrap.php, SETELAH error
 * handler terpasang (lihat urutan di sana).
 */

require_once __DIR__ . '/repo/login_attempts.php';
require_once __DIR__ . '/repo/users.php';
require_once __DIR__ . '/repo/lokasi.php';
require_once __DIR__ . '/repo/aktivitas.php';

define('LOGIN_THROTTLE_MAX_ATTEMPTS', 5);
define('LOGIN_THROTTLE_WINDOW_MINUTES', 15);
define('SESSION_IDLE_TIMEOUT_SECONDS', 30 * 60);
define('SESSION_ABSOLUTE_TIMEOUT_SECONDS', 8 * 60 * 60);

/**
 * Hash bcrypt VALID tapi sengaja tidak akan pernah cocok dengan password apa
 * pun yang masuk akal. Nilainya sendiri tidak penting -- yang penting formatnya
 * bcrypt cost=10 yang sama dengan hash asli (database/make_seed_hash.php),
 * supaya password_verify() terhadapnya makan waktu SETARA dengan verifikasi
 * sungguhan. Dipakai login.php saat username tidak ditemukan, agar waktu
 * respons tidak membocorkan apakah username itu ada (timing attack).
 */
define('DUMMY_PASSWORD_HASH', '$2y$10$Cp7gxfO0sJLKEPbE8lglbub0DWrBvEz0M0AnYuCbwbAvaUbjnAkZm');

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
 * - img-src ... tile.openstreetmap.org + unpkg.com : tile peta (PRD bagian 9/11,
 *                              tegas) ditambah unpkg.com (T-10) karena ikon marker
 *                              default Leaflet dimuat lewat path relatif ke CSS-nya
 *                              sendiri di CDN yang sama, bukan dari origin sendiri.
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
        . "img-src 'self' https://tile.openstreetmap.org https://unpkg.com; connect-src 'self'; "
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

/**
 * Data user yang sedang login dari session, atau null bila belum login.
 * Nama current_user() (bukan auth_user() seperti draf awal T-05) mengikuti
 * spesifikasi artefak T-06 secara literal -- lihat Keputusan T-06.
 */
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

/**
 * Path relatif (tanpa BASE_URL) dari permintaan saat ini, format sama dengan
 * yang diterima url()/redirect() -- "dashboard.php", "lokasi/index.php?id=3".
 * Dipakai require_login() untuk membangun ?next= saat mengarahkan ke login.php.
 */
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

/**
 * Validasi ?next= agar hanya menerima path internal relatif (PRD/edge case
 * T-06: cegah open redirect). Aturan, semua WAJIB lolos:
 * - tidak ada ':'            -> otomatis menolak skema apa pun (http:, https:,
 *                                javascript:, data:), termasuk "//host" (dianggap
 *                                protocol-relative oleh browser, bukan path).
 * - tidak diawali '/'        -> harus relatif terhadap BASE_URL (lewat url()),
 *                                bukan root domain -- yang juga berarti path ke
 *                                luar proyek ini tidak mungkin lolos.
 * - tidak mengandung '..'    -> cegah traversal.
 * - whitelist karakter       -> hanya huruf/angka/`_-./` untuk path dan
 *                                `?=&%` untuk query sederhana.
 * Mengembalikan null bila gagal validasi (caller jatuh ke default dashboard.php).
 */
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

/**
 * Pastikan ada user login; redirect ke login.php (dengan ?next= ke halaman
 * ini) bila belum. Mengecek ULANG is_active ke DB setiap panggilan -- bukan
 * hanya sekali saat login -- supaya akun yang dinonaktifkan admin saat sesi
 * masih berjalan langsung ter-logout di request berikutnya (edge case T-06),
 * bukan menunggu sesi kedaluwarsa sendiri. Cache-Control: no-store dipasang
 * di SINI (bukan hanya di logout.php) karena ini yang benar-benar mencegah
 * tombol Back menampilkan halaman terproteksi dari cache browser -- header
 * pada respons logout.php sendiri tidak memengaruhi cache halaman SEBELUMNYA.
 */
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

/**
 * Pastikan user login DAN berperan salah satu dari $roles. Mengembalikan 403
 * EKSPLISIT bila peran tidak cocok -- BUKAN redirect diam-diam (spesifikasi
 * T-06 secara eksplisit melarang itu, dibedakan dari require_login() yang
 * memang mengarahkan ke login.php karena belum login sama sekali).
 */
function require_role(string|array $roles): array
{
    $user = require_login();

    $allowed = is_array($roles) ? $roles : [$roles];
    if (!in_array($user['role'], $allowed, true)) {
        abort(403);
    }

    return $user;
}

/**
 * Satu-satunya titik otorisasi data untuk lokasi_tambang (PRD bagian 2 & 5).
 * Admin: boleh asal lokasinya memang ada. Kontraktor: hanya bila lokasi itu
 * milik PERUSAHAANNYA (kontraktor_id), bukan admin ATAU kontraktor lain.
 * Mengembalikan false baik untuk ID yang tidak ada maupun ID milik pihak
 * lain -- caller cukup `if (!can_access_lokasi($user, $id)) abort(404);`,
 * sehingga responsnya identik untuk kedua kasus (edge case T-06: cegah
 * kebocoran informasi lewat perbedaan respons).
 */
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

/**
 * Satu-satunya titik otorisasi data untuk aktivitas_tambang. Kepemilikan
 * ditentukan lewat kontraktor_id PERUSAHAAN (via lokasi_tambang), bukan
 * aktivitas_tambang.user_id individu -- lihat Keputusan T-06: PRD bagian 2
 * menulis "aktivitas yang ia input sendiri" tapi memberi contoh filter
 * `WHERE kontraktor_id = ?`; kontraktor_id dipilih karena itu yang akan
 * tetap benar bila satu perusahaan kontraktor punya lebih dari satu akun
 * (FR-12 tidak melarangnya), sementara user_id akan salah dalam kasus itu.
 */
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

/**
 * Password acak kuat (T-08: pembuatan akun kontraktor & reset password admin).
 * Admin TIDAK PERNAH mengetik password akun kontraktor sendiri -- sistem selalu
 * membangkitkannya, supaya "password awal lemah" (edge case T-08) tidak mungkin
 * terjadi. Alfabet menghindari karakter rupa-mirip (0/O, 1/l/I) supaya mudah
 * disalin/dibacakan manual; ~14 karakter dari ~70 simbol memberi entropi tinggi.
 */
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

/** Catat satu percobaan gagal. Dipanggil HANYA setelah verifikasi kredensial gagal, bukan saat sudah terkunci. */
function login_throttle_record_failure(string $username, string $ip_address): void
{
    repo_login_attempt_record($username, $ip_address);
    app_log('warning', sprintf('Login gagal: username="%s" ip=%s', $username, $ip_address));
}
