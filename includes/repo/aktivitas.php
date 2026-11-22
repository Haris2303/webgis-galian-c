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
 * Filter yang dikenali: lokasi_id, user_id, status_aktivitas, tanggal_mulai,
 * tanggal_akhir, kontraktor_id (T-17 -- scope "milik sendiri" kontraktor
 * ditentukan lewat PERUSAHAAN/lokasi, bukan user_id individu, konsisten
 * dengan can_access_aktivitas() di includes/auth.php). JOIN lokasi_tambang,
 * jenis_material & users SELALU dilakukan (bukan hanya saat kontraktor_id
 * diisi) supaya daftar bisa menampilkan nama lokasi/material/pencatat, bukan
 * ID mentah -- aman karena FK ketiganya NOT NULL (tidak ada baris anak yatim).
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

    // Kolom eksplisit (T-24, docs/query-audit.md Q8), BUKAN "SELECT a.*" --
    // satu-satunya pemanggil (aktivitas/index.php) hanya membaca id,
    // tanggal_aktivitas, status_aktivitas, nama_lokasi, jenis_kegiatan,
    // material_nama, user_nama. kontraktor_id TETAP di JOIN (lt.kontraktor_id
    // di WHERE, T-17) tapi tidak perlu ikut ke SELECT -- dan deskripsi (TEXT,
    // hingga 2000 karakter) tidak pernah dibaca dari hasil LIST ini (hanya
    // lewat repo_aktivitas_find() untuk form ubah satu baris).
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
 * Daftar aktivitas untuk monitoring.php (FR-21, T-21, khusus Admin). SATU
 * query dengan JOIN lokasi_tambang + kontraktor + jenis_material, PLUS dua
 * scalar subquery agregat per baris (jumlah foto & tanggal aktivitas terakhir
 * lokasi itu) -- keduanya bagian dari SATU statement SQL yang sama (pola yang
 * sama dengan repo_lokasi_list_for_map(), T-12), BUKAN query terpisah per
 * baris dari PHP (N+1). paginate() di bawah selalu menjalankan tepat 2 query
 * (COUNT + SELECT) apa pun jumlah total barisnya -- lihat db_query_count().
 *
 * Filter yang dikenali: lokasi_id, kontraktor_id, status_aktivitas,
 * tanggal_mulai, tanggal_akhir (inklusif, >=/<=). TIDAK ada parameter scope
 * kontraktor wajib di sini (beda dari repo_aktivitas_list()) karena
 * monitoring.php khusus Admin (require_role('admin')) -- kontraktor_id di
 * sini murni salah satu FILTER dropdown, bukan guard kepemilikan data.
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

/**
 * Statement MENTAH (bukan array) daftar aktivitas dalam periode untuk ekspor
 * CSV (T-23, laporan/unduh.php) -- dipakai dengan db_cursor() +
 * `while (($row = $stmt->fetch()) !== false)` supaya memori tetap konstan
 * untuk ribuan baris (SATU baris di memori pada satu waktu, bukan seluruh
 * resultset lewat fetchAll()/db_all()). JOIN lokasi_tambang (+ koordinat),
 * kontraktor, jenis_material -- SEMUA lokasi tanpa scope kontraktor (laporan
 * Dinas selalu lintas-kontraktor, sama seperti repo_lokasi_list_for_laporan(),
 * T-22). Pemanggil WAJIB ->closeCursor() setelah selesai.
 */
function repo_aktivitas_cursor_for_csv(string $tanggal_mulai, string $tanggal_akhir): PDOStatement
{
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

/**
 * Edge case wajib T-17: aktivitas ganda untuk lokasi & tanggal yang sama --
 * BUKAN diblokir, hanya dicek di sini supaya form.php bisa menampilkan
 * PERINGATAN sebelum/setelah simpan. $exclude_id dipakai saat mode ubah
 * supaya baris yang sedang diedit tidak menghitung dirinya sendiri.
 */
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

/** ON DELETE CASCADE ke foto_aktivitas (lihat database/schema.sql). */
function repo_aktivitas_delete(int $id): bool
{
    return db_exec('DELETE FROM aktivitas_tambang WHERE id = :id', ['id' => $id]) > 0;
}

/** Dipakai lokasi/form.php (T-09): deteksi lokasi berizin->tidak_berizin yang sudah punya riwayat aktivitas. */
function repo_aktivitas_count_by_lokasi(int $lokasi_id): int
{
    $row = db_one('SELECT COUNT(*) AS jumlah FROM aktivitas_tambang WHERE lokasi_id = :lokasi_id', ['lokasi_id' => $lokasi_id]);

    return (int) ($row['jumlah'] ?? 0);
}
