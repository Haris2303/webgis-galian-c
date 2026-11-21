<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/repo/lokasi.php — akses data tabel lokasi_tambang.
 */

function repo_lokasi_find(int $id): ?array
{
    return db_one('SELECT * FROM lokasi_tambang WHERE id = :id', ['id' => $id]);
}

/**
 * Filter yang dikenali: status_izin, kontraktor_id, material_id, search
 * (cocok di nama_lokasi ATAU kelurahan, T-09). $sort_column divalidasi lewat
 * whitelist di db_order_by() — tidak pernah dari input mentah. JOIN kontraktor
 * & jenis_material supaya daftar admin menampilkan nama, bukan ID mentah.
 * @return array{0: array<int, array<string, mixed>>, 1: int} [rows, total]
 */
function repo_lokasi_list(array $filters, int $page, int $per_page, string $sort_column = 'nama_lokasi', string $sort_direction = 'asc'): array
{
    $allowed_columns = ['nama_lokasi', 'status_izin', 'distrik', 'kelurahan', 'tanggal_berakhir_izin', 'created_at', 'updated_at'];
    $order_by = db_order_by($sort_column, $sort_direction, $allowed_columns, 'nama_lokasi');

    $where = [];
    $params = [];

    if (!empty($filters['status_izin'])) {
        $where[] = 'l.status_izin = :status_izin';
        $params['status_izin'] = $filters['status_izin'];
    }
    if (!empty($filters['kontraktor_id'])) {
        $where[] = 'l.kontraktor_id = :kontraktor_id';
        $params['kontraktor_id'] = (int) $filters['kontraktor_id'];
    }
    if (!empty($filters['material_id'])) {
        $where[] = 'l.material_id = :material_id';
        $params['material_id'] = (int) $filters['material_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(l.nama_lokasi LIKE :search OR l.kelurahan LIKE :search_kel)';
        $kata = '%' . db_like_escape((string) $filters['search']) . '%';
        $params['search'] = $kata;
        $params['search_kel'] = $kata;
    }

    $sql = 'SELECT l.*, k.nama_perusahaan AS kontraktor_nama, m.nama AS material_nama
            FROM lokasi_tambang l
            JOIN kontraktor k ON k.id = l.kontraktor_id
            JOIN jenis_material m ON m.id = l.material_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $order_by;

    return paginate($sql, $params, $page, $per_page);
}

/** Kartu dashboard (PRD bagian 9 #2). Satu query GROUP BY, memakai idx_status. */
function repo_lokasi_count_by_status(): array
{
    $rows = db_all('SELECT status_izin, COUNT(*) AS jumlah FROM lokasi_tambang GROUP BY status_izin');

    $hasil = ['berizin' => 0, 'tidak_berizin' => 0, 'proses' => 0];
    foreach ($rows as $row) {
        $hasil[$row['status_izin']] = (int) $row['jumlah'];
    }

    return $hasil;
}

function repo_lokasi_create(array $data): int
{
    db_exec(
        'INSERT INTO lokasi_tambang
            (nama_lokasi, kontraktor_id, material_id, distrik, kelurahan, alamat, latitude, longitude, area_geojson, luas_ha, status_izin, nomor_izin, tanggal_berlaku_izin, tanggal_berakhir_izin, keterangan, created_by)
         VALUES
            (:nama_lokasi, :kontraktor_id, :material_id, :distrik, :kelurahan, :alamat, :latitude, :longitude, :area_geojson, :luas_ha, :status_izin, :nomor_izin, :tanggal_berlaku_izin, :tanggal_berakhir_izin, :keterangan, :created_by)',
        [
            'nama_lokasi' => $data['nama_lokasi'],
            'kontraktor_id' => (int) $data['kontraktor_id'],
            'material_id' => (int) $data['material_id'],
            'distrik' => $data['distrik'] ?? null,
            'kelurahan' => $data['kelurahan'] ?? null,
            'alamat' => $data['alamat'] ?? null,
            // latitude/longitude dibind sebagai string (PARAM_STR), bukan float,
            // supaya presisi DECIMAL(10,7) di schema.sql tidak digeser rounding
            // float PHP — konsisten dengan catatan "DECIMAL dikembalikan sebagai
            // string" di database/README.md.
            'latitude' => (string) $data['latitude'],
            'longitude' => (string) $data['longitude'],
            'area_geojson' => $data['area_geojson'] ?? null,
            'luas_ha' => $data['luas_ha'] ?? null,
            'status_izin' => $data['status_izin'] ?? 'proses',
            'nomor_izin' => $data['nomor_izin'] ?? null,
            'tanggal_berlaku_izin' => $data['tanggal_berlaku_izin'] ?? null,
            'tanggal_berakhir_izin' => $data['tanggal_berakhir_izin'] ?? null,
            'keterangan' => $data['keterangan'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]
    );

    return (int) db()->lastInsertId();
}

function repo_lokasi_update(int $id, array $data): bool
{
    $affected = db_exec(
        'UPDATE lokasi_tambang SET
            nama_lokasi = :nama_lokasi,
            kontraktor_id = :kontraktor_id,
            material_id = :material_id,
            distrik = :distrik,
            kelurahan = :kelurahan,
            alamat = :alamat,
            latitude = :latitude,
            longitude = :longitude,
            area_geojson = :area_geojson,
            luas_ha = :luas_ha,
            status_izin = :status_izin,
            nomor_izin = :nomor_izin,
            tanggal_berlaku_izin = :tanggal_berlaku_izin,
            tanggal_berakhir_izin = :tanggal_berakhir_izin,
            keterangan = :keterangan
         WHERE id = :id',
        [
            'nama_lokasi' => $data['nama_lokasi'],
            'kontraktor_id' => (int) $data['kontraktor_id'],
            'material_id' => (int) $data['material_id'],
            'distrik' => $data['distrik'] ?? null,
            'kelurahan' => $data['kelurahan'] ?? null,
            'alamat' => $data['alamat'] ?? null,
            'latitude' => (string) $data['latitude'],
            'longitude' => (string) $data['longitude'],
            'area_geojson' => $data['area_geojson'] ?? null,
            'luas_ha' => $data['luas_ha'] ?? null,
            'status_izin' => $data['status_izin'] ?? 'proses',
            'nomor_izin' => $data['nomor_izin'] ?? null,
            'tanggal_berlaku_izin' => $data['tanggal_berlaku_izin'] ?? null,
            'tanggal_berakhir_izin' => $data['tanggal_berakhir_izin'] ?? null,
            'keterangan' => $data['keterangan'] ?? null,
            'id' => $id,
        ]
    );

    return $affected > 0;
}

/** ON DELETE CASCADE ke aktivitas_tambang & foto_aktivitas (lihat database/schema.sql). */
function repo_lokasi_delete(int $id): bool
{
    return db_exec('DELETE FROM lokasi_tambang WHERE id = :id', ['id' => $id]) > 0;
}

/** Dipakai kontraktor/hapus.php (T-08): kontraktor hanya boleh dihapus bila ini 0 (FK RESTRICT). */
function repo_lokasi_count_by_kontraktor(int $kontraktor_id): int
{
    $row = db_one('SELECT COUNT(*) AS jumlah FROM lokasi_tambang WHERE kontraktor_id = :kontraktor_id', ['kontraktor_id' => $kontraktor_id]);

    return (int) ($row['jumlah'] ?? 0);
}
