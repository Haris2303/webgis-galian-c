<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// includes/repo/aktivitas.php — akses data tabel aktivitas_tambang.

function repo_aktivitas_find(int $id): ?array
{
    return db_one('SELECT * FROM aktivitas_tambang WHERE id = :id', ['id' => $id]);
}

// Pakai idx_lokasi_tanggal (lokasi_id, tanggal_aktivitas).
function repo_aktivitas_latest_by_lokasi(int $lokasi_id, int $limit = 5): array
{
    $limit = max(1, min(50, $limit));

    return db_all(
        'SELECT * FROM aktivitas_tambang WHERE lokasi_id = :lokasi_id ORDER BY tanggal_aktivitas DESC LIMIT :batas',
        ['lokasi_id' => $lokasi_id, 'batas' => $limit]
    );
}

/**
 * kontraktor_id scope ditentukan lewat lokasi, bukan user_id individu.
 * @return array{0: array<int, array<string, mixed>>, 1: int} [rows, total]
 */
function repo_aktivitas_list(array $filters, int $page, int $per_page, string $sort_column = 'tanggal_aktivitas', string $sort_direction = 'desc'): array
{
    $allowed_columns = ['tanggal_aktivitas', 'status_aktivitas', 'created_at'];
    $order_by = db_order_by($sort_column, $sort_direction, $allowed_columns, 'tanggal_aktivitas', 'desc');

    $where = [];
    $params = [];

    if (!empty($filters['kontraktor_id'])) {
        $where[] = 'lt.kontraktor_id = :kontraktor_id';
        $params['kontraktor_id'] = (int) $filters['kontraktor_id'];
    }
    if (!empty($filters['lokasi_id'])) {
        $where[] = 'a.lokasi_id = :lokasi_id';
        $params['lokasi_id'] = (int) $filters['lokasi_id'];
    }
    if (!empty($filters['user_id'])) {
        $where[] = 'a.user_id = :user_id';
        $params['user_id'] = (int) $filters['user_id'];
    }
    if (!empty($filters['status_aktivitas'])) {
        $where[] = 'a.status_aktivitas = :status_aktivitas';
        $params['status_aktivitas'] = $filters['status_aktivitas'];
    }
    if (!empty($filters['tanggal_mulai'])) {
        $where[] = 'a.tanggal_aktivitas >= :tanggal_mulai';
        $params['tanggal_mulai'] = $filters['tanggal_mulai'];
    }
    if (!empty($filters['tanggal_akhir'])) {
        $where[] = 'a.tanggal_aktivitas <= :tanggal_akhir';
        $params['tanggal_akhir'] = $filters['tanggal_akhir'];
    }

    // Kolom eksplisit, bukan "SELECT a.*" -- hanya kolom yang dipakai aktivitas/index.php.
    $sql = 'SELECT a.id, a.tanggal_aktivitas, a.jenis_kegiatan, a.status_aktivitas,
                   lt.nama_lokasi, m.nama AS material_nama, u.nama_lengkap AS user_nama
            FROM aktivitas_tambang a
            JOIN lokasi_tambang lt ON lt.id = a.lokasi_id
            JOIN jenis_material m ON m.id = a.material_id
            JOIN users u ON u.id = a.user_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $order_by;

    return paginate($sql, $params, $page, $per_page);
}

/**
 * kontraktor_id di sini hanya filter dropdown, bukan scope keamanan (beda dari repo_aktivitas_list()).
 * @return array{0: array<int, array<string, mixed>>, 1: int} [rows, total]
 */
function repo_aktivitas_list_for_monitoring(array $filters, int $page, int $per_page, string $sort_column = 'tanggal_aktivitas', string $sort_direction = 'desc'): array
{
    $allowed_columns = ['tanggal_aktivitas', 'status_aktivitas', 'nama_lokasi', 'kontraktor_nama'];
    $order_by = db_order_by($sort_column, $sort_direction, $allowed_columns, 'tanggal_aktivitas', 'desc');

    $where = [];
    $params = [];

    if (!empty($filters['lokasi_id'])) {
        $where[] = 'a.lokasi_id = :lokasi_id';
        $params['lokasi_id'] = (int) $filters['lokasi_id'];
    }
    if (!empty($filters['kontraktor_id'])) {
        $where[] = 'lt.kontraktor_id = :kontraktor_id';
        $params['kontraktor_id'] = (int) $filters['kontraktor_id'];
    }
    if (!empty($filters['status_aktivitas'])) {
        $where[] = 'a.status_aktivitas = :status_aktivitas';
        $params['status_aktivitas'] = $filters['status_aktivitas'];
    }
    if (!empty($filters['tanggal_mulai'])) {
        $where[] = 'a.tanggal_aktivitas >= :tanggal_mulai';
        $params['tanggal_mulai'] = $filters['tanggal_mulai'];
    }
    if (!empty($filters['tanggal_akhir'])) {
        $where[] = 'a.tanggal_aktivitas <= :tanggal_akhir';
        $params['tanggal_akhir'] = $filters['tanggal_akhir'];
    }

    $sql = 'SELECT a.id, a.tanggal_aktivitas, a.jenis_kegiatan, a.status_aktivitas,
                   lt.id AS lokasi_id, lt.nama_lokasi,
                   k.nama_perusahaan AS kontraktor_nama,
                   (SELECT COUNT(*) FROM foto_aktivitas f WHERE f.aktivitas_id = a.id) AS jumlah_foto,
                   (SELECT MAX(a2.tanggal_aktivitas) FROM aktivitas_tambang a2 WHERE a2.lokasi_id = lt.id) AS lokasi_aktivitas_terakhir
            FROM aktivitas_tambang a
            JOIN lokasi_tambang lt ON lt.id = a.lokasi_id
            JOIN kontraktor k ON k.id = lt.kontraktor_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $order_by;

    return paginate($sql, $params, $page, $per_page);
}

// Pemanggil wajib ->closeCursor() setelah selesai; null tanggal berarti sisi itu terbuka (pakai sentinel MIN/MAX).
function repo_aktivitas_cursor_for_csv(?string $tanggal_mulai, ?string $tanggal_akhir): PDOStatement
{
    $tanggal_mulai ??= '1000-01-01';
    $tanggal_akhir ??= '9999-12-31';

    return db_cursor(
        'SELECT a.tanggal_aktivitas, a.jenis_kegiatan, a.status_aktivitas,
                a.jumlah_alat_berat, a.jumlah_pekerja, a.deskripsi,
                lt.nama_lokasi, lt.latitude, lt.longitude,
                k.nama_perusahaan AS kontraktor_nama,
                m.nama AS material_nama
         FROM aktivitas_tambang a
         JOIN lokasi_tambang lt ON lt.id = a.lokasi_id
         JOIN kontraktor k ON k.id = lt.kontraktor_id
         JOIN jenis_material m ON m.id = a.material_id
         WHERE a.tanggal_aktivitas BETWEEN :mulai AND :akhir
         ORDER BY a.tanggal_aktivitas ASC, a.id ASC',
        ['mulai' => $tanggal_mulai, 'akhir' => $tanggal_akhir]
    );
}

// Dipakai form.php untuk peringatan (bukan blokir) aktivitas ganda per lokasi+tanggal.
function repo_aktivitas_exists_same_date(int $lokasi_id, string $tanggal, ?int $exclude_id = null): bool
{
    $sql = 'SELECT COUNT(*) AS jumlah FROM aktivitas_tambang WHERE lokasi_id = :lokasi_id AND tanggal_aktivitas = :tanggal';
    $params = ['lokasi_id' => $lokasi_id, 'tanggal' => $tanggal];

    if ($exclude_id !== null) {
        $sql .= ' AND id != :exclude_id';
        $params['exclude_id'] = $exclude_id;
    }

    $row = db_one($sql, $params);

    return (int) ($row['jumlah'] ?? 0) > 0;
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

// ON DELETE CASCADE ke foto_aktivitas.
function repo_aktivitas_delete(int $id): bool
{
    return db_exec('DELETE FROM aktivitas_tambang WHERE id = :id', ['id' => $id]) > 0;
}

// Dipakai lokasi/form.php untuk deteksi lokasi berizin->tidak_berizin yang sudah punya riwayat aktivitas.
function repo_aktivitas_count_by_lokasi(int $lokasi_id): int
{
    $row = db_one('SELECT COUNT(*) AS jumlah FROM aktivitas_tambang WHERE lokasi_id = :lokasi_id', ['lokasi_id' => $lokasi_id]);

    return (int) ($row['jumlah'] ?? 0);
}
