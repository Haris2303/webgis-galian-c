<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/repo/kontraktor.php — akses data tabel kontraktor.
 * Setiap fungsi berisi tepat satu query terparameter lewat helper di config/database.php.
 */

function repo_kontraktor_find(int $id): ?array
{
    return db_one('SELECT * FROM kontraktor WHERE id = :id', ['id' => $id]);
}

/** @return array{0: array<int, array<string, mixed>>, 1: int} [rows, total] */
function repo_kontraktor_list(int $page, int $per_page, ?string $search = null): array
{
    $sql = 'SELECT * FROM kontraktor';
    $params = [];

    if ($search !== null && $search !== '') {
        $sql .= ' WHERE nama_perusahaan LIKE :search';
        $params['search'] = '%' . db_like_escape($search) . '%';
    }

    $sql .= ' ORDER BY nama_perusahaan ASC';

    return paginate($sql, $params, $page, $per_page);
}

function repo_kontraktor_create(array $data): int
{
    db_exec(
        'INSERT INTO kontraktor (nama_perusahaan, penanggung_jawab, telepon, email, alamat)
         VALUES (:nama_perusahaan, :penanggung_jawab, :telepon, :email, :alamat)',
        [
            'nama_perusahaan' => $data['nama_perusahaan'],
            'penanggung_jawab' => $data['penanggung_jawab'],
            'telepon' => $data['telepon'] ?? null,
            'email' => $data['email'] ?? null,
            'alamat' => $data['alamat'] ?? null,
        ]
    );

    return (int) db()->lastInsertId();
}

function repo_kontraktor_update(int $id, array $data): bool
{
    $affected = db_exec(
        'UPDATE kontraktor SET
            nama_perusahaan = :nama_perusahaan,
            penanggung_jawab = :penanggung_jawab,
            telepon = :telepon,
            email = :email,
            alamat = :alamat
         WHERE id = :id',
        [
            'nama_perusahaan' => $data['nama_perusahaan'],
            'penanggung_jawab' => $data['penanggung_jawab'],
            'telepon' => $data['telepon'] ?? null,
            'email' => $data['email'] ?? null,
            'alamat' => $data['alamat'] ?? null,
            'id' => $id,
        ]
    );

    return $affected > 0;
}

/** Bisa melempar PDOException (SQLSTATE 23000) bila masih dirujuk lokasi_tambang (FK RESTRICT). */
function repo_kontraktor_delete(int $id): bool
{
    return db_exec('DELETE FROM kontraktor WHERE id = :id', ['id' => $id]) > 0;
}
