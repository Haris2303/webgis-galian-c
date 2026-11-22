<?php

declare(strict_types=1);

// Ekspor CSV aktivitas tambang per periode, khusus Admin: satu baris per aktivitas (bukan agregat per lokasi seperti laporan/index.php).

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';

require_role('admin');

// Tanggal tidak valid diperlakukan sama dengan kosong.
function unduh_valid_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $value);

    return ($parsed !== false && $parsed->format('Y-m-d') === $value) ? $value : null;
}

// Proteksi CSV injection: sel yang diawali =, +, -, @, tab, atau CR diberi awalan apostrof agar spreadsheet merendernya sebagai teks, bukan rumus.
function csv_aman(?string $nilai): string
{
    $nilai = (string) $nilai;
    if ($nilai !== '' && in_array($nilai[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $nilai;
    }

    return $nilai;
}

$tanggal_mulai = unduh_valid_date((string) ($_GET['tanggal_mulai'] ?? ''));
$tanggal_akhir = unduh_valid_date((string) ($_GET['tanggal_akhir'] ?? ''));

if ($tanggal_mulai !== null && $tanggal_akhir !== null && $tanggal_mulai > $tanggal_akhir) {
    [$tanggal_mulai, $tanggal_akhir] = [$tanggal_akhir, $tanggal_mulai];
}

if ($tanggal_mulai === null && $tanggal_akhir === null) {
    $tanggal_mulai = date('Y-m-01');
    $tanggal_akhir = date('Y-m-t');
}

$label_status_aktivitas = [
    'aktif' => 'Aktif',
    'berhenti_sementara' => 'Berhenti Sementara',
    'selesai' => 'Selesai',
];

$nama_file = 'laporan-galian-c-' . date('Ymd') . '.csv';

if (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nama_file . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Titik koma, bukan koma: Excel berlokal Indonesia memakai koma sebagai pemisah desimal.
$pemisah = ';';

$keluaran = fopen('php://output', 'wb');

// BOM UTF-8: tanpa ini Excel membaca berkas sebagai ANSI/Latin-1.
fwrite($keluaran, "\xEF\xBB\xBF");

fputcsv($keluaran, [
    'Tanggal', 'Nama Lokasi', 'Kontraktor', 'Jenis Kegiatan', 'Jenis Material',
    'Status Aktivitas', 'Jumlah Alat Berat', 'Jumlah Pekerja', 'Deskripsi',
    'Latitude', 'Longitude',
], $pemisah);

$stmt = repo_aktivitas_cursor_for_csv($tanggal_mulai, $tanggal_akhir);

while (($row = $stmt->fetch()) !== false) {
    fputcsv($keluaran, [
        (string) $row['tanggal_aktivitas'],
        csv_aman($row['nama_lokasi']),
        csv_aman($row['kontraktor_nama']),
        csv_aman($row['jenis_kegiatan']),
        // Dari master jenis_material (nilai tetap), tidak butuh csv_aman().
        $row['material_nama'],
        $label_status_aktivitas[$row['status_aktivitas']] ?? 'Status Tidak Diketahui',
        (string) $row['jumlah_alat_berat'],
        (string) $row['jumlah_pekerja'],
        csv_aman($row['deskripsi'] ?? ''),
        // Latitude/longitude tidak dibungkus csv_aman(): '-' di awal lintang bukan rumus.
        (string) $row['latitude'],
        (string) $row['longitude'],
    ], $pemisah);

    // Flush per baris agar memori tetap konstan pada dataset besar.
    @flush();
}

$stmt->closeCursor();
fclose($keluaran);
