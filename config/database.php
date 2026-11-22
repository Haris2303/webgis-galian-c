<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// Koneksi PDO tunggal + helper akses data.

// Lazy: koneksi dibuka saat pertama dipanggil, setelah date_default_timezone_set() di bootstrap.php sudah berjalan.
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

        // Pakai offset numerik, bukan nama zona: nama zona butuh tabel mysql.time_zone_name yang belum tentu di-load.
        $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
    } catch (PDOException $e) {
        $pdo = null;
        // Detail koneksi hanya masuk log lokal, tidak pernah ke pesan exception yang bisa tampil ke browser.
        app_log('error', 'Koneksi database gagal: ' . $e->getMessage());
        throw new RuntimeException('Tidak dapat terhubung ke database. Silakan coba lagi nanti.', 0, $e);
    }

    return $pdo;
}

// Bind tiap parameter dengan tipe PDO yang sesuai (int, bool, null, string); PDO::execute($array) saja mem-bind semuanya sebagai string, yang ditolak MySQL untuk posisi LIMIT/OFFSET.
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
    db_query_count(true);

    return $stmt;
}

// Hitung jumlah query yang sudah dijalankan pada request ini; alat debug saja.
function db_query_count(bool $increment = false): int
{
    static $count = 0;

    if ($increment) {
        $count++;
    }

    return $count;
}

/** Ambil semua baris hasil query terparameter. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

// Kembalikan PDOStatement mentah untuk iterasi baris satu per satu (dataset besar); pemanggil wajib ->closeCursor() setelah selesai.
function db_cursor(string $sql, array $params = []): PDOStatement
{
    return db_run($sql, $params);
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

// Aman dipanggil bersarang: panggilan terluar membuka transaksi InnoDB, panggilan dalam memakai SAVEPOINT; deadlock di level terluar diulang sekali otomatis.
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

// Fragmen "`kolom` ARAH" aman ditempel ke SQL karena $column divalidasi lewat whitelist $allowed_columns.
function db_order_by(string $column, string $direction, array $allowed_columns, string $default_column, string $default_direction = 'asc'): string
{
    $safe_column = in_array($column, $allowed_columns, true) ? $column : $default_column;

    $direction_upper = strtoupper($direction);
    if (!in_array($direction_upper, ['ASC', 'DESC'], true)) {
        $direction_upper = strtoupper($default_direction);
    }

    return '`' . $safe_column . '` ' . $direction_upper;
}

// Escape backslash duluan (sebelum % dan _) agar tidak dobel-escape; caller menambahkan wildcard '%' sendiri.
function db_like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

// $per_page dibatasi 1..200 agar ?per_page=999999 tidak memaksa MySQL men-scan seluruh tabel.
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
