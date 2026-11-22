<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// includes/repo/login_attempts.php — akses data tabel login_attempts.

function repo_login_attempt_record(string $username, string $ip_address): void
{
    db_exec(
        'INSERT INTO login_attempts (username, ip_address) VALUES (:username, :ip_address)',
        ['username' => $username, 'ip_address' => $ip_address]
    );
}

// Hitung percobaan gagal dalam $window_minutes terakhir.
function repo_login_attempt_count_recent(string $username, string $ip_address, int $window_minutes): int
{
    $row = db_one(
        'SELECT COUNT(*) AS jumlah FROM login_attempts
         WHERE username = :username AND ip_address = :ip_address
           AND attempted_at > DATE_SUB(NOW(), INTERVAL :menit MINUTE)',
        ['username' => $username, 'ip_address' => $ip_address, 'menit' => $window_minutes]
    );

    return (int) ($row['jumlah'] ?? 0);
}
