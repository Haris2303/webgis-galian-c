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

    // Kolom eksplisit (T-24, docs/query-audit.md Q1), BUKAN "SELECT l.*" --
    // daftar ini adalah UNION kolom yang benar-benar dipakai keempat
    // pemanggil (lokasi/index.php, dashboard.php, aktivitas/index.php &
    // monitoring.php untuk dropdown, api/cari.php). Sengaja TIDAK termasuk
    // area_geojson (LONGTEXT, bisa besar per poligon), alamat/keterangan
    // (TEXT), nomor_izin/tanggal_berlaku_izin -- tidak ada satu pun pemanggil
    // yang membacanya dari hasil fungsi ini (repo_lokasi_find() dipakai
    // terpisah untuk form ubah yang BUTUH semua kolom itu).
    $sql = 'SELECT l.id, l.nama_lokasi, l.kelurahan, l.latitude, l.longitude,
                   l.status_izin, l.tanggal_berakhir_izin,
                   k.nama_perusahaan AS kontraktor_nama, m.nama AS material_nama
            FROM lokasi_tambang l
            JOIN kontraktor k ON k.id = l.kontraktor_id
            JOIN jenis_material m ON m.id = l.material_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $order_by;

    return paginate($sql, $params, $page, $per_page);
}

/**
 * Kartu dashboard (PRD bagian 9 #2) & api/statistik.php (T-12). $kontraktor_id
 * null = semua lokasi (Admin); diisi = ter-scope ke satu kontraktor (FR-04:
 * "Kontraktor hanya lokasinya"). Tetap satu query GROUP BY, memakai idx_status
 * (atau idx_status+idx_kontraktor sekaligus saat ter-scope).
 */
function repo_lokasi_count_by_status(?int $kontraktor_id = null): array
{
    $sql = 'SELECT status_izin, COUNT(*) AS jumlah FROM lokasi_tambang';
    $params = [];
    if ($kontraktor_id !== null) {
        $sql .= ' WHERE kontraktor_id = :kontraktor_id';
        $params['kontraktor_id'] = $kontraktor_id;
    }
    $sql .= ' GROUP BY status_izin';

    $rows = db_all($sql, $params);

    $hasil = ['berizin' => 0, 'tidak_berizin' => 0, 'proses' => 0];
    foreach ($rows as $row) {
        $hasil[$row['status_izin']] = (int) $row['jumlah'];
    }

    return $hasil;
}

/**
 * Titik + properti ringan untuk api/lokasi.php (T-12), termasuk tanggal
 * aktivitas terakhir lewat subquery (bukan JOIN langsung ke aktivitas_tambang,
 * supaya tetap SATU baris per lokasi walau aktivitasnya banyak).
 *
 * $kontraktor_id null = semua lokasi (Admin); diisi = ter-scope ke satu
 * kontraktor (peran kontraktor: peta hanya menampilkan lokasi miliknya
 * sendiri, konsisten dengan dashboard.php/repo_lokasi_count_by_status()).
 */
function repo_lokasi_list_for_map(?string $status_izin, ?int $kontraktor_id = null): array
{
    $where = [];
    $params = [];
    if ($status_izin !== null) {
        $where[] = 'l.status_izin = :status_izin';
        $params['status_izin'] = $status_izin;
    }
    if ($kontraktor_id !== null) {
        $where[] = 'l.kontraktor_id = :kontraktor_id';
        $params['kontraktor_id'] = $kontraktor_id;
    }
    $klausa_where = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    return db_all(
        "SELECT l.id, l.nama_lokasi, l.latitude, l.longitude, l.status_izin,
                k.nama_perusahaan AS kontraktor_nama, m.nama AS material_nama,
                (SELECT MAX(a.tanggal_aktivitas) FROM aktivitas_tambang a WHERE a.lokasi_id = l.id) AS aktivitas_terakhir
         FROM lokasi_tambang l
         JOIN kontraktor k ON k.id = l.kontraktor_id
         JOIN jenis_material m ON m.id = l.material_id
         {$klausa_where}
         ORDER BY l.id ASC",
        $params
    );
}

/** MAX(updated_at) + COUNT(*) untuk ETag api/lokasi.php (T-12) -- ter-scope ke filter DAN kontraktor yang sama dengan query utamanya. */
function repo_lokasi_etag_meta(?string $status_izin, ?int $kontraktor_id = null): array
{
    $where = [];
    $params = [];
    if ($status_izin !== null) {
        $where[] = 'status_izin = :status_izin';
        $params['status_izin'] = $status_izin;
    }
    if ($kontraktor_id !== null) {
        $where[] = 'kontraktor_id = :kontraktor_id';
        $params['kontraktor_id'] = $kontraktor_id;
    }
    $sql = 'SELECT COUNT(*) AS jumlah, MAX(updated_at) AS terakhir FROM lokasi_tambang';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $row = db_one($sql, $params);

    return ['jumlah' => (int) ($row['jumlah'] ?? 0), 'terakhir' => $row['terakhir'] ?? null];
}

/**
 * Lokasi yang punya area_geojson (poligon), untuk api/area.php (T-12).
 * $kontraktor_id null = semua lokasi (Admin); diisi = ter-scope ke satu
 * kontraktor (peran kontraktor: peta hanya menampilkan area miliknya sendiri).
 */
function repo_lokasi_list_with_area(?int $kontraktor_id = null): array
{
    $where = 'WHERE area_geojson IS NOT NULL';
    $params = [];
    if ($kontraktor_id !== null) {
        $where .= ' AND kontraktor_id = :kontraktor_id';
        $params['kontraktor_id'] = $kontraktor_id;
    }

    return db_all(
        "SELECT id, nama_lokasi, status_izin, area_geojson
         FROM lokasi_tambang
         {$where}
         ORDER BY id ASC",
        $params
    );
}

/**
 * Rekap laporan monitoring per periode (FR-22, T-22, Admin saja -- selalu
 * SEMUA lokasi lintas kontraktor, tidak ada scope). SATU statement SQL dengan
 * JOIN kontraktor + TIGA scalar subquery agregat per baris (pola yang sama
 * dengan T-12/T-20/T-21): jumlah aktivitas DALAM periode, tanggal aktivitas
 * TERAKHIR dalam periode, dan status aktivitas pada tanggal itu. Lokasi tanpa
 * aktivitas pada periode ini TETAP tampil dengan jumlah=0 dan tanggal/status
 * NULL (edge case wajib T-22 "periode tanpa data"), bukan hilang dari laporan.
 *
 * Nama parameter diberi akhiran 1/2/3 (bukan dipakai ulang) karena MySQL
 * native prepared statement (EMULATE_PREPARES=false, config/database.php)
 * tidak mengizinkan satu placeholder bernama dipakai lebih dari sekali dalam
 * satu statement.
 * $tanggal_mulai/$tanggal_akhir null berarti sisi itu TERBUKA (tidak dibatasi)
 * -- diganti sentinel tanggal MIN/MAX MySQL (bukan dibiarkan null) karena
 * `BETWEEN x AND NULL` selalu bernilai NULL/false, bukan "tanpa batas".
 * @return array<int, array<string, mixed>>
 */
function repo_lokasi_list_for_laporan(?string $tanggal_mulai, ?string $tanggal_akhir): array
{
    $tanggal_mulai ??= '1000-01-01';
    $tanggal_akhir ??= '9999-12-31';

    return db_all(
        'SELECT lt.id, lt.nama_lokasi, lt.status_izin, k.nama_perusahaan AS kontraktor_nama,
                (SELECT COUNT(*) FROM aktivitas_tambang a
                  WHERE a.lokasi_id = lt.id AND a.tanggal_aktivitas BETWEEN :mulai1 AND :akhir1) AS jumlah_aktivitas,
                (SELECT MAX(a.tanggal_aktivitas) FROM aktivitas_tambang a
                  WHERE a.lokasi_id = lt.id AND a.tanggal_aktivitas BETWEEN :mulai2 AND :akhir2) AS aktivitas_terakhir_tanggal,
                (SELECT a.status_aktivitas FROM aktivitas_tambang a
                  WHERE a.lokasi_id = lt.id AND a.tanggal_aktivitas BETWEEN :mulai3 AND :akhir3
                  ORDER BY a.tanggal_aktivitas DESC, a.id DESC LIMIT 1) AS aktivitas_terakhir_status
         FROM lokasi_tambang lt
         JOIN kontraktor k ON k.id = lt.kontraktor_id
         ORDER BY lt.nama_lokasi ASC',
        [
            'mulai1' => $tanggal_mulai, 'akhir1' => $tanggal_akhir,
            'mulai2' => $tanggal_mulai, 'akhir2' => $tanggal_akhir,
            'mulai3' => $tanggal_mulai, 'akhir3' => $tanggal_akhir,
        ]
    );
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
