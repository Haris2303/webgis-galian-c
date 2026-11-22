<?php

declare(strict_types=1);

/**
 * laporan/unduh.php — ekspor CSV aktivitas tambang per periode (FR-22; PRD
 * bagian 3 "Unduh menghasilkan file CSV", bagian 11; T-23). Hanya Admin.
 * Periode sama persis dengan laporan/index.php (tanggal_mulai/akhir, default
 * bulan berjalan, rentang terbalik ditukar diam-diam) -- helper validasi
 * tanggal DIDUPLIKASI SENGAJA, bukan di-require dari index.php (lihat
 * Keputusan T-23: dua entry point terpisah, bukan dua mode satu berkas).
 *
 * SATU baris CSV per AKTIVITAS (bukan agregat per lokasi seperti tabel
 * laporan/index.php) -- laporan/index.php meringkas; unduhan ini memuat
 * detailnya (jenis kegiatan, deskripsi, koordinat) untuk diolah lebih lanjut
 * di Excel, dan inilah yang realistis bisa mencapai "ribuan baris" (edge
 * case wajib T-23) karena bertambah terus seiring waktu -- beda dari jumlah
 * LOKASI yang kecil/tetap.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';

require_role('admin');

/** "Y-m-d" valid (format DAN tanggal kalender nyata), atau null bila kosong/tidak valid -- sama dengan laporan/index.php & monitoring.php. */
function unduh_valid_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $value);

    return ($parsed !== false && $parsed->format('Y-m-d') === $value) ? $value : null;
}

/**
 * Proteksi CSV injection (edge case wajib T-23, OWASP CSV Injection): sel
 * yang diawali =, +, -, @, tab, atau CR diberi awalan apostrof SATU kali --
 * spreadsheet (Excel/Sheets/LibreOffice) merender apostrof di awal sebagai
 * penanda "paksa teks" dan TIDAK menampilkannya di sel, sehingga nilai
 * seperti "=1+1" atau "=HYPERLINK(...)" tampil sebagai teks apa adanya,
 * bukan dieksekusi sebagai rumus.
 *
 * HANYA dipakai untuk kolom TEKS BEBAS yang diisi pengguna (nama lokasi,
 * nama kontraktor, jenis kegiatan, deskripsi) -- SENGAJA TIDAK dipakai untuk
 * kolom numerik/tanggal/master data tertutup (lihat pemanggilan di bawah),
 * karena latitude Kota Sorong SELALU dimulai '-' (lintang selatan): membungkus
 * kolom itu juga akan memaksa Excel membaca koordinat sebagai teks, bukan
 * angka yang bisa dihitung/disortir -- padahal '-0,87...' bukan rumus
 * berbahaya, murni angka negatif biasa. Lihat Keputusan T-23.
 */
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

// Pemisah TITIK KOMA, bukan koma (spesifikasi T-23): Excel berlokal Indonesia
// memakai koma sebagai pemisah DESIMAL, sehingga CSV berpemisah koma standar
// (RFC 4180) justru salah dipecah jadi kolom-kolom yang berantakan di sana.
$pemisah = ';';

$keluaran = fopen('php://output', 'wb');

// BOM UTF-8 (spesifikasi T-23) -- tanpa ini Excel membaca berkas sebagai
// ANSI/Latin-1 dan merusak semua karakter non-ASCII.
fwrite($keluaran, "\xEF\xBB\xBF");

fputcsv($keluaran, [
    'Tanggal', 'Nama Lokasi', 'Kontraktor', 'Jenis Kegiatan', 'Jenis Material',
    'Status Aktivitas', 'Jumlah Alat Berat', 'Jumlah Pekerja', 'Deskripsi',
    'Latitude', 'Longitude',
], $pemisah);

$stmt = repo_aktivitas_cursor_for_csv($tanggal_mulai, $tanggal_akhir);

while (($row = $stmt->fetch()) !== false) {
    fputcsv($keluaran, [
        // a.tanggal_aktivitas kolom DATE MySQL -> PDO mengembalikannya sebagai
        // string "Y-m-d" (format ISO) apa adanya (spesifikasi T-23), tanpa
        // konversi tambahan.
        (string) $row['tanggal_aktivitas'],
        csv_aman($row['nama_lokasi']),
        csv_aman($row['kontraktor_nama']),
        csv_aman($row['jenis_kegiatan']),
        // material_nama dari master jenis_material (lima nilai tetap, tidak
        // ada UI pengguna untuk mengubahnya) -- tidak butuh csv_aman().
        $row['material_nama'],
        $label_status_aktivitas[$row['status_aktivitas']] ?? 'Status Tidak Diketahui',
        (string) $row['jumlah_alat_berat'],
        (string) $row['jumlah_pekerja'],
        // Edge case wajib T-23: deskripsi (textarea bebas, maks 2000 karakter,
        // includes/validator.php) bisa berisi newline DAN tanda kutip --
        // fputcsv() menanganinya otomatis lewat tanda kutip+escape RFC 4180,
        // BUKAN sesuatu yang kode di sini perlu tangani manual.
        csv_aman($row['deskripsi'] ?? ''),
        // DECIMAL(10,7) dikembalikan PDO sebagai STRING, SUDAH memakai titik
        // desimal dari MySQL (spesifikasi T-23 "koordinat memakai titik
        // desimal") -- diteruskan apa adanya, TANPA csv_aman() (lihat komentar
        // fungsi csv_aman() di atas: '-' di awal lintang bukan rumus berbahaya).
        (string) $row['latitude'],
        (string) $row['longitude'],
    ], $pemisah);

    // Streaming sungguhan (spesifikasi T-23 "dataset besar"): kirim buffer PHP
    // tiap baris alih-alih menumpuknya sampai loop selesai, supaya memori
    // tetap konstan berapa pun jumlah baris dan browser mulai menerima data
    // sejak dini.
    @flush();
}

$stmt->closeCursor();
fclose($keluaran);
