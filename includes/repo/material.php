<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// includes/repo/material.php — akses data tabel jenis_material (master data tetap, tanpa pagination).

function repo_material_find(int $id): ?array
{
    return db_one('SELECT * FROM jenis_material WHERE id = :id', ['id' => $id]);
}

function repo_material_list(): array
{
    return db_all('SELECT * FROM jenis_material ORDER BY nama ASC');
}
