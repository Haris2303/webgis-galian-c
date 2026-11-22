<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// includes/repo/foto.php — akses data tabel foto_aktivitas; hanya menyimpan path, validasi dilakukan di layer upload.

function repo_foto_find(int $id): ?array
{
    return db_one('SELECT * FROM foto_aktivitas WHERE id = :id', ['id' => $id]);
}

function repo_foto_list_by_aktivitas(int $aktivitas_id): array
{
    return db_all(
        'SELECT * FROM foto_aktivitas WHERE aktivitas_id = :aktivitas_id ORDER BY uploaded_at ASC',
        ['aktivitas_id' => $aktivitas_id]
    );
}

// Dipakai menegakkan batas UPLOAD_MAX_FILES sebelum insert baru.
function repo_foto_count_by_aktivitas(int $aktivitas_id): int
{
    $row = db_one(
        'SELECT COUNT(*) AS jumlah FROM foto_aktivitas WHERE aktivitas_id = :aktivitas_id',
        ['aktivitas_id' => $aktivitas_id]
    );

    return (int) ($row['jumlah'] ?? 0);
}

function repo_foto_create(array $data): int
{
    db_exec(
        'INSERT INTO foto_aktivitas (aktivitas_id, file_path) VALUES (:aktivitas_id, :file_path)',
        [
            'aktivitas_id' => (int) $data['aktivitas_id'],
            'file_path' => $data['file_path'],
        ]
    );

    return (int) db()->lastInsertId();
}

function repo_foto_delete(int $id): bool
{
    return db_exec('DELETE FROM foto_aktivitas WHERE id = :id', ['id' => $id]) > 0;
}

/**
 * Dikumpulkan sebelum lokasi dihapus -- CASCADE membuat baris ini tak lagi bisa di-query setelahnya.
 * @return string[]
 */
function repo_foto_list_paths_by_lokasi(int $lokasi_id): array
{
    $rows = db_all(
        'SELECT fa.file_path
         FROM foto_aktivitas fa
         JOIN aktivitas_tambang a ON a.id = fa.aktivitas_id
         WHERE a.lokasi_id = :lokasi_id',
        ['lokasi_id' => $lokasi_id]
    );

    return array_column($rows, 'file_path');
}
