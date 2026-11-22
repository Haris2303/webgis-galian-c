<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/repo/users.php';

// Helper bersama endpoint api/*.php: tidak memakai require_login()/require_role() karena keduanya me-redirect, bukan membalas JSON.

/** Balas {"error": "..."} + kode HTTP, lalu hentikan eksekusi. */
function api_error(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":' . json_encode($message, JSON_UNESCAPED_UNICODE) . '}';
    exit;
}

// JsonException ditangkap lokal agar respons tetap JSON, tidak lewat exception handler global yang bisa memilih HTML.
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

// Wajib dipanggil di baris pertama setiap api/*.php: menegakkan GET, JSON, login, dan status aktif user.
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

// Null bila tidak valid; caller yang memutuskan kode HTTP (400 vs 404).
function api_parse_id(?string $raw): ?int
{
    if ($raw === null || !preg_match('/^[1-9][0-9]*$/', $raw)) {
        return null;
    }

    $value = (int) $raw;

    return ($value > 0 && $value <= 2147483647) ? $value : null; // 2147483647 = batas kolom INT MySQL
}

// Whitelist agar nilai mentah tidak pernah masuk ke query.
function api_parse_status(string $raw): ?string
{
    return in_array($raw, ['berizin', 'tidak_berizin', 'proses'], true) ? $raw : null;
}
