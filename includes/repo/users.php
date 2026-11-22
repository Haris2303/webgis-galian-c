<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// includes/repo/users.php — akses data tabel users; password_hash tidak pernah lewat repo_user_update(), hanya lewat repo_user_update_password().

function repo_user_find(int $id): ?array
{
    return db_one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
}

// Mengandalkan UNIQUE KEY username.
function repo_user_find_by_username(string $username): ?array
{
    return db_one('SELECT * FROM users WHERE username = :username', ['username' => $username]);
}

/** @return array{0: array<int, array<string, mixed>>, 1: int} [rows, total] */
function repo_user_list(int $page, int $per_page, ?string $role = null): array
{
    $sql = 'SELECT id, username, nama_lengkap, role, kontraktor_id, is_active, last_login, created_at FROM users';
    $params = [];

    if ($role !== null && $role !== '') {
        $sql .= ' WHERE role = :role';
        $params['role'] = $role;
    }

    $sql .= ' ORDER BY nama_lengkap ASC';

    return paginate($sql, $params, $page, $per_page);
}

function repo_user_create(array $data): int
{
    db_exec(
        'INSERT INTO users (username, password_hash, nama_lengkap, role, kontraktor_id, is_active)
         VALUES (:username, :password_hash, :nama_lengkap, :role, :kontraktor_id, :is_active)',
        [
            'username' => $data['username'],
            'password_hash' => $data['password_hash'],
            'nama_lengkap' => $data['nama_lengkap'],
            'role' => $data['role'],
            'kontraktor_id' => $data['kontraktor_id'] ?? null,
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]
    );

    return (int) db()->lastInsertId();
}

function repo_user_update(int $id, array $data): bool
{
    $affected = db_exec(
        'UPDATE users SET
            nama_lengkap = :nama_lengkap,
            role = :role,
            kontraktor_id = :kontraktor_id,
            is_active = :is_active
         WHERE id = :id',
        [
            'nama_lengkap' => $data['nama_lengkap'],
            'role' => $data['role'],
            'kontraktor_id' => $data['kontraktor_id'] ?? null,
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'id' => $id,
        ]
    );

    return $affected > 0;
}

function repo_user_update_password(int $id, string $password_hash): bool
{
    return db_exec(
        'UPDATE users SET password_hash = :password_hash WHERE id = :id',
        ['password_hash' => $password_hash, 'id' => $id]
    ) > 0;
}

function repo_user_touch_last_login(int $id): bool
{
    return db_exec('UPDATE users SET last_login = NOW() WHERE id = :id', ['id' => $id]) > 0;
}

// Nonaktifkan/aktifkan akun tanpa mengirim ulang semua kolom seperti repo_user_update().
function repo_user_set_active(int $id, bool $active): bool
{
    return db_exec('UPDATE users SET is_active = :is_active WHERE id = :id', ['is_active' => $active ? 1 : 0, 'id' => $id]) > 0;
}

// Akun (biasanya satu) yang terhubung ke satu kontraktor_id.
function repo_user_list_by_kontraktor(int $kontraktor_id): array
{
    return db_all('SELECT * FROM users WHERE kontraktor_id = :kontraktor_id ORDER BY id ASC', ['kontraktor_id' => $kontraktor_id]);
}

function repo_user_delete(int $id): bool
{
    return db_exec('DELETE FROM users WHERE id = :id', ['id' => $id]) > 0;
}
