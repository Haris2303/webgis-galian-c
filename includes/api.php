<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/repo/users.php';

/**
 * includes/api.php — helper bersama endpoint api/*.php (T-12, PRD bagian 10).
 *
 * api_boot() SENGAJA TIDAK memanggil require_login()/require_role() (T-06)
 * apa adanya -- kedua fungsi itu me-redirect (302 ke login.php / 403 HTML)
 * saat gagal, benar untuk halaman HTML tapi SALAH untuk API: klien fetch()
 * dari peta mengharapkan balasan JSON {"error": "..."} dengan kode HTTP yang
 * tepat, bukan redirect atau halaman HTML. Logika cek login+is_active di
 * bawah meniru require_login(), hanya beda cara melapor gagalnya.
 */

/** Balas {"error": "..."} + kode HTTP, lalu hentikan eksekusi. */
function api_error(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":' . json_encode($message, JSON_UNESCAPED_UNICODE) . '}';
    exit;
}

/**
 * Balas $data sebagai JSON (spesifikasi T-12: JSON_UNESCAPED_UNICODE +
 * JSON_THROW_ON_ERROR), lalu hentikan eksekusi. JsonException SENGAJA
 * ditangkap LOKAL di sini, tidak dibiarkan lolos ke exception handler global
 * (includes/bootstrap.php) -- handler itu memilih HTML/JSON lewat
 * is_ajax_request() yang menyimak header klien, padahal endpoint api/*.php
 * WAJIB selalu JSON apa pun headernya.
 */
function api_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        app_log('error', 'api_json gagal encode: ' . $e->getMessage());
        http_response_code(500);
        echo '{"error":"Terjadi kesalahan saat membentuk respons."}';
        exit;
    }

    echo $body;
    exit;
}

/**
 * Wajib dipanggil baris pertama setiap api/*.php. Menegakkan: hanya GET,
 * header JSON UTF-8, wajib login (401 JSON bila tidak -- BUKAN redirect),
 * dan cek ulang is_active ke DB (konsisten dengan require_login() T-06,
 * tanpa redirect). Mengembalikan data user yang sedang login.
 */
function api_boot(): array
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        api_error(405, 'Endpoint ini hanya menerima GET.');
    }

    $user = current_user();
    if ($user === null) {
        api_error(401, 'Anda harus masuk untuk mengakses endpoint ini.');
    }

    $fresh = repo_user_find($user['id']);
    if ($fresh === null || (int) $fresh['is_active'] !== 1) {
        auth_logout();
        api_error(401, 'Sesi tidak valid. Silakan masuk kembali.');
    }

    return $user;
}

/**
 * Validasi parameter ?id= sebagai integer positif wajar (edge case T-12: id
 * non-numerik atau sangat besar) -- null bila tidak valid, caller yang
 * memutuskan kode HTTP (400 untuk malformed, bukan 404).
 */
function api_parse_id(?string $raw): ?int
{
    if ($raw === null || !preg_match('/^[1-9][0-9]*$/', $raw)) {
        return null;
    }

    $value = (int) $raw;

    return ($value > 0 && $value <= 2147483647) ? $value : null; // 2147483647 = batas kolom INT MySQL
}

/** Validasi ?status= lewat whitelist -- tidak pernah nilai mentah masuk ke query (konsisten T-03/T-09). */
function api_parse_status(string $raw): ?string
{
    return in_array($raw, ['berizin', 'tidak_berizin', 'proses'], true) ? $raw : null;
}
