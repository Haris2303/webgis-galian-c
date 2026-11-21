<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/repo/aktivitas.php — akses data tabel aktivitas_tambang.
 */

function repo_aktivitas_find(int $id): ?array
{
    return db_one('SELECT * FROM aktivitas_tambang WHERE id = :id', ['id' => $id]);
}

/**
 * "N aktivitas terakhir per lokasi" (popup peta, api/lokasi_detail.php). Memakai
 * idx_lokasi_tanggal (lokasi_id, tanggal_aktivitas) — lihat database/schema.sql.
 */
function repo_aktivitas_latest_by_lokasi(int $lokasi_id, int $limit = 5): array
{
    $limit = max(1, min(50, $limit));

    return db_all(
        'SELECT * FROM aktivitas_tambang WHERE lokasi_id = :lokasi_id ORDER BY tanggal_aktivitas DESC LIMIT :batas',
        ['lokasi_id' => $lokasi_id, 'batas' => $limit]
    );
}

/**
 * Filter yang dikenali: lokasi_id, user_id, status_aktivitas, tanggal_mulai, tanggal_akhir.
 * @return array{0: array<int, array<string, mixed>>, 1: int} [rows, total]
 */
function repo_aktivitas_list(array $filters, int $page, int $per_page, string $sort_column = 'tanggal_aktivitas', string $sort_direction = 'desc'): array
{
    $allowed_columns = ['tanggal_aktivitas', 'status_aktivitas', 'created_at'];
    $order_by = db_order_by($sort_column, $sort_direction, $allowed_columns, 'tanggal_aktivitas', 'desc');

    $where = [];
    $params = [];

    if (!empty($filters['lokasi_id'])) {
        $where[] = 'lokasi_id = :lokasi_id';
        $params['lokasi_id'] = (int) $filters['lokasi_id'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'user_id = :user_id';
        $params['user_id'] = (int) $filters['user_id'];
    }
    if (!empty($filters['status_aktivitas'])) {
        $where[] = 'status_aktivitas = :status_aktivitas';
        $params['status_aktivitas'] = $filters['status_aktivitas'];
    }
    if (!empty($filters['tanggal_mulai'])) {
        $where[] = 'tanggal_aktivitas >= :tanggal_mulai';
        $params['tanggal_mulai'] = $filters['tanggal_mulai'];
    }
    if (!empty($filters['tanggal_akhir'])) {
        $where[] = 'tanggal_aktivitas <= :tanggal_akhir';
        $params['tanggal_akhir'] = $filters['tanggal_akhir'];
    }

    $sql = 'SELECT * FROM aktivitas_tambang';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $order_by;

    return paginate($sql, $params, $page, $per_page);
}

function repo_aktivitas_create(array $data): int
{
    db_exec(
        'INSERT INTO aktivitas_tambang
            (lokasi_id, user_id, tanggal_aktivitas, jenis_kegiatan, material_id, status_aktivitas, jumlah_alat_berat, jumlah_pekerja, deskripsi)
         VALUES
            (:lokasi_id, :user_id, :tanggal_aktivitas, :jenis_kegiatan, :material_id, :status_aktivitas, :jumlah_alat_berat, :jumlah_pekerja, :deskripsi)',
        [
            'lokasi_id' => (int) $data['lokasi_id'],
            'user_id' => (int) $data['user_id'],
            'tanggal_aktivitas' => $data['tanggal_aktivitas'],
            'jenis_kegiatan' => $data['jenis_kegiatan'],
            'material_id' => (int) $data['material_id'],
            'status_aktivitas' => $data['status_aktivitas'] ?? 'aktif',
            'jumlah_alat_berat' => (int) ($data['jumlah_alat_berat'] ?? 0),
            'jumlah_pekerja' => (int) ($data['jumlah_pekerja'] ?? 0),
            'deskripsi' => $data['deskripsi'] ?? null,
        ]
    );

    return (int) db()->lastInsertId();
}

function repo_aktivitas_update(int $id, array $data): bool
{
    $affected = db_exec(
        'UPDATE aktivitas_tambang SET
            tanggal_aktivitas = :tanggal_aktivitas,
            jenis_kegiatan = :jenis_kegiatan,
            material_id = :material_id,
            status_aktivitas = :status_aktivitas,
            jumlah_alat_berat = :jumlah_alat_berat,
            jumlah_pekerja = :jumlah_pekerja,
            deskripsi = :deskripsi
         WHERE id = :id',
        [
            'tanggal_aktivitas' => $data['tanggal_aktivitas'],
            'jenis_kegiatan' => $data['jenis_kegiatan'],
            'material_id' => (int) $data['material_id'],
            'status_aktivitas' => $data['status_aktivitas'] ?? 'aktif',
            'jumlah_alat_berat' => (int) ($data['jumlah_alat_berat'] ?? 0),
            'jumlah_pekerja' => (int) ($data['jumlah_pekerja'] ?? 0),
            'deskripsi' => $data['deskripsi'] ?? null,
            'id' => $id,
        ]
    );

    return $affected > 0;
}

/** ON DELETE CASCADE ke foto_aktivitas (lihat database/schema.sql). */
function repo_aktivitas_delete(int $id): bool
{
    return db_exec('DELETE FROM aktivitas_tambang WHERE id = :id', ['id' => $id]) > 0;
}
