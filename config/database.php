<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * config/database.php — koneksi PDO tunggal + helper akses data (T-03).
 *
 * Dikumpulkan dalam satu berkas (bukan dipisah ke includes/) karena struktur
 * folder PRD bagian 8 hanya menyebut satu berkas config database, dan semua
 * helper di sini (db_one/db_all/db_exec/db_transaction/paginate/...) selalu
 * dipakai bersama db() — memisahkannya ke berkas lain hanya menambah
 * indirection tanpa manfaat. Repository per entitas (includes/repo/) memanggil
 * fungsi-fungsi di sini, bukan memanggil PDO langsung.
 */

/**
 * Koneksi PDO singleton. Lazy: koneksi baru dibuka saat pertama kali dipanggil,
 * bukan saat berkas ini di-require — supaya date_default_timezone_set() di
 * bootstrap.php sudah pasti berjalan duluan sebelum SET time_zone di bawah.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Samakan time_zone sesi MySQL dengan timezone PHP (Asia/Jayapura, PRD
        // bagian 11) lewat OFFSET NUMERIK ('+09:00'), bukan nama zona
        // ('Asia/Jayapura'/'Asia/Jakarta') — nama zona butuh tabel
        // mysql.time_zone_name yang belum tentu di-load (umum di instalasi
        // XAMPP/Laragon default), sedangkan offset numerik selalu didukung.
        $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
    } catch (PDOException $e) {
        $pdo = null;
        // Detail koneksi (host, port, nama DB) hanya masuk log lokal, TIDAK PERNAH
        // ke pesan exception yang bisa tampil ke browser — bahkan di APP_ENV=dev,
        // supaya perilaku ini tidak bergantung pada operator lupa set APP_ENV=prod.
        app_log('error', 'Koneksi database gagal: ' . $e->getMessage());
        throw new RuntimeException('Tidak dapat terhubung ke database. Silakan coba lagi nanti.', 0, $e);
    }

    return $pdo;
}

/**
 * Siapkan statement, bind setiap parameter dengan tipe PDO yang sesuai tipe PHP-nya
 * (int -> PARAM_INT, bool -> PARAM_INT 0/1, null -> PARAM_NULL, selain itu -> PARAM_STR),
 * lalu eksekusi. Satu-satunya titik prepare+bind+execute dipakai semua helper di bawah,
 * supaya LIMIT/OFFSET yang dikirim sebagai int PHP otomatis ter-bind PARAM_INT —
 * tanpa ini, PDO::execute($array) mem-bind semua nilai sebagai string, dan MySQL native
 * prepared statement (EMULATE_PREPARES=false) menolak string untuk posisi LIMIT/OFFSET.
 */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);

    foreach ($params as $key => $value) {
        $param = is_string($key) ? ':' . ltrim($key, ':') : $key + 1;

        if (is_int($value)) {
            $stmt->bindValue($param, $value, PDO::PARAM_INT);
        } elseif (is_bool($value)) {
            $stmt->bindValue($param, (int) $value, PDO::PARAM_INT);
        } elseif ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, (string) $value, PDO::PARAM_STR);
        }
    }

    $stmt->execute();

    return $stmt;
}

/** Ambil semua baris hasil query terparameter. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Ambil satu baris, atau null bila tidak ada hasil. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();

    return $row === false ? null : $row;
}

/** Jalankan INSERT/UPDATE/DELETE terparameter, kembalikan jumlah baris terdampak. */
function db_exec(string $sql, array $params = []): int
{
    return db_run($sql, $params)->rowCount();
}

/**
 * Jalankan $callback di dalam transaksi. Aman dipanggil bersarang: panggilan
 * terluar (depth 0) membuka transaksi InnoDB sungguhan; panggilan di dalamnya
 * memakai SAVEPOINT, sehingga rollback di level dalam tidak membatalkan
 * perubahan level luar yang sudah berhasil (selama level luar menangkap
 * exception-nya sendiri). $depth adalah static function-level, bukan variabel
 * instance, karena desain fungsional ini tidak punya objek untuk menyimpannya.
 *
 * Deadlock/lock-wait-timeout (SQLSTATE 40001, errno 1205/1213) pada transaksi
 * TERLUAR diulang sekali secara otomatis sebelum menyerah — mengulang baru
 * masuk akal di level terluar karena hanya level itu yang benar-benar
 * membuka/menutup transaksi InnoDB.
 */
function db_transaction(callable $callback)
{
    static $depth = 0;
    $pdo = db();

    if ($depth === 0) {
        $attempts = 0;

        while (true) {
            $attempts++;
            $pdo->beginTransaction();
            $depth = 1;

            try {
                $result = $callback($pdo);
                $pdo->commit();
                $depth = 0;

                return $result;
            } catch (Throwable $e) {
                $depth = 0;
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($attempts < 2 && db_is_deadlock($e)) {
                    continue;
                }

                throw $e;
            }
        }
    }

    $savepoint = 'db_sp_' . $depth;
    $pdo->exec('SAVEPOINT ' . $savepoint);
    $depth++;

    try {
        $result = $callback($pdo);
        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        $depth--;

        return $result;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $depth--;

        throw $e;
    }
}

/** Deteksi deadlock (40001) atau lock wait timeout (1205/1213) MySQL/InnoDB. */
function db_is_deadlock(Throwable $e): bool
{
    if (!($e instanceof PDOException)) {
        return false;
    }

    $sqlstate = $e->errorInfo[0] ?? $e->getCode();
    $driver_code = $e->errorInfo[1] ?? null;

    return $sqlstate === '40001' || in_array($driver_code, [1205, 1213], true);
}

/**
 * Bangun fragmen "`kolom` ARAH" yang aman untuk ditempel langsung ke SQL (PDO
 * tidak bisa bind nama kolom/arah sort sebagai parameter). Aman karena $column
 * dan $direction HARUS lolos whitelist $allowed_columns di sini dulu — tidak
 * pernah ada jalur di mana nilai mentah dari input pengguna langsung masuk SQL.
 */
function db_order_by(string $column, string $direction, array $allowed_columns, string $default_column, string $default_direction = 'asc'): string
{
    $safe_column = in_array($column, $allowed_columns, true) ? $column : $default_column;

    $direction_upper = strtoupper($direction);
    if (!in_array($direction_upper, ['ASC', 'DESC'], true)) {
        $direction_upper = strtoupper($default_direction);
    }

    return '`' . $safe_column . '` ' . $direction_upper;
}

/**
 * Escape literal untuk karakter spesial LIKE (% dan _) serta backslash itu
 * sendiri (harus di-escape duluan, sebelum % dan _, supaya tidak dobel-escape).
 * MySQL memakai backslash sebagai escape char default untuk LIKE, jadi tidak
 * perlu klausa ESCAPE tambahan di query. Caller tetap menambahkan wildcard
 * '%' di luar hasil fungsi ini, mis. '%' . db_like_escape($q) . '%'.
 */
function db_like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

/**
 * Jalankan $sql (SELECT tanpa LIMIT/OFFSET/titik-koma) dengan pagination.
 * Mengembalikan [rows, total]. $page/$per_page dari input pengguna tidak pernah
 * dipercaya mentah: $page dipaksa >= 1, $per_page dibatasi 1..200 di sini supaya
 * permintaan ?per_page=999999 tidak memaksa MySQL men-scan seluruh tabel.
 */
function paginate(string $sql, array $params, int $page, int $per_page): array
{
    $page = max(1, $page);
    $per_page = max(1, min(200, $per_page));
    $offset = ($page - 1) * $per_page;

    $total_row = db_one('SELECT COUNT(*) AS total FROM (' . $sql . ') AS paginate_subquery', $params);
    $total = (int) ($total_row['total'] ?? 0);

    $rows = db_all($sql . ' LIMIT :pg_limit OFFSET :pg_offset', $params + [
        'pg_limit' => $per_page,
        'pg_offset' => $offset,
    ]);

    return [$rows, $total];
}
