<?php

declare(strict_types=1);

/**
 * database/bersihkan_foto_yatim.php — skrip CLI pembersih file foto "yatim"
 * (T-18, FR-14). File yatim adalah berkas di uploads/aktivitas/**  yang TIDAK
 * dirujuk oleh foto_aktivitas.file_path manapun -- bisa terjadi bila proses
 * PHP terhenti paksa (kill -9, OOM) di antara move/tulis file dan commit
 * transaksi (jendela yang TIDAK tertutup oleh rollback try/catch di
 * aktivitas/form.php, karena itu hanya menangani Exception PHP biasa, bukan
 * proses yang mati total), atau sisa dari versi aplikasi lama sebelum
 * mekanisme rollback T-18 ada.
 *
 * MODE AMAN secara default: hanya MELAPORKAN, tidak menghapus apa pun, kecuali
 * diberi --hapus.
 *
 * Pemakaian:
 *   php database/bersihkan_foto_yatim.php            (dry-run, hanya laporan)
 *   php database/bersihkan_foto_yatim.php --hapus     (sungguh menghapus)
 *   php database/bersihkan_foto_yatim.php --hapus --lebih-tua-dari=24
 *       (hanya hapus file yatim yang mtime-nya lebih tua dari N jam --
 *       default 1 jam, supaya file yang SEDANG ditulis oleh request lain
 *       yang masih berjalan tidak ikut terhapus -- lihat Risiko tersisa.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya untuk CLI.');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/foto.php';
require_once __DIR__ . '/../includes/upload_foto.php';

$args = $argv ?? [];
$hapus_sungguhan = in_array('--hapus', $args, true);

$batas_jam = 1.0;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--lebih-tua-dari=')) {
        $batas_jam = max(0.0, (float) substr($arg, strlen('--lebih-tua-dari=')));
    }
}
$batas_detik = $batas_jam * 3600;

// Kumpulkan SEMUA path yang TERDAFTAR di DB -- file utama DAN thumbnail
// turunannya (thumbnail tidak punya baris sendiri, lihat upload_foto_path_thumbnail()).
$terdaftar = [];
foreach (db_all('SELECT file_path FROM foto_aktivitas') as $row) {
    $path = str_replace('\\', '/', (string) $row['file_path']);
    $terdaftar[$path] = true;
    $terdaftar[upload_foto_path_thumbnail($path)] = true;
}

$root_upload = rtrim(PROJECT_ROOT, '/\\') . '/uploads/aktivitas';
$ditemukan = 0;
$dihapus = 0;
$dilewati_baru = 0;
$gagal = 0;

if (!is_dir($root_upload)) {
    echo "Direktori {$root_upload} tidak ada -- tidak ada yang dibersihkan.\n";
    exit(0);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root_upload, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $nama_berkas = $file->getFilename();
    if ($nama_berkas === '.gitkeep' || str_starts_with($nama_berkas, '.')) {
        continue; // placeholder git / berkas tersembunyi -- bukan hasil unggahan, jangan disentuh
    }

    $path_absolut = str_replace('\\', '/', $file->getPathname());
    $path_relatif = 'uploads/aktivitas/' . ltrim(substr($path_absolut, strlen($root_upload)), '/');

    if (isset($terdaftar[$path_relatif])) {
        continue; // dirujuk baris DB -- bukan yatim
    }

    $ditemukan++;
    $umur_detik = time() - $file->getMTime();

    if ($umur_detik < $batas_detik) {
        // Edge case: upload YANG SEDANG BERJALAN (file sudah tertulis ke
        // disk, transaksi DB belum commit) terlihat "yatim" sesaat -- jangan
        // dihapus kalau masih sangat baru, beri jendela aman (default 1 jam).
        $dilewati_baru++;
        continue;
    }

    if (!$hapus_sungguhan) {
        echo "[DRY-RUN] akan dihapus: {$path_relatif} (umur " . round($umur_detik / 60) . " menit)\n";
        continue;
    }

    if (@unlink($path_absolut)) {
        echo "dihapus: {$path_relatif}\n";
        $dihapus++;
    } else {
        echo "GAGAL menghapus: {$path_relatif}\n";
        $gagal++;
    }
}

echo "\n=== Ringkasan ===\n";
echo "Berkas yatim ditemukan: {$ditemukan}\n";
echo "Dilewati (lebih baru dari {$batas_jam} jam): {$dilewati_baru}\n";
if ($hapus_sungguhan) {
    echo "Dihapus: {$dihapus}\n";
    echo "Gagal dihapus: {$gagal}\n";
} else {
    echo "Mode: DRY-RUN (tidak ada yang sungguh dihapus). Jalankan ulang dengan --hapus untuk menghapus sungguhan.\n";
}
