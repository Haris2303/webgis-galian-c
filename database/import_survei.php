<?php

declare(strict_types=1);

/**
 * database/import_survei.php — CLI import koordinat hasil survei lapangan ke
 * lokasi_tambang (T-28; skripsi bagian 3.2.4-3.2.5; PRD bagian 7).
 *
 * BEDA dengan tests/smoke.php (T-27): skrip itu CLI-only DAN dev-only karena
 * mereset total database. Skrip INI CLI-only SAJA (tidak mensyaratkan
 * APP_ENV=dev) -- tujuannya justru memasukkan data survei NYATA, termasuk ke
 * database produksi, saat Dinas benar-benar punya titik baru dari lapangan.
 *
 * Pemakaian:
 *   php database/import_survei.php --file=survei.csv [--commit] [--create-kontraktor]
 *     [--map=target_field=KolomCSV,...] [--delimiter=;] [--batch-size=25]
 *     [--errors=path/errors.csv]
 *
 * Default TANPA --commit adalah DRY-RUN (hanya melaporkan, tidak menulis DB) --
 * spesifikasi T-28 eksplisit meminta ini sebagai default, bukan opt-in.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: jalankan hanya lewat command line (php database/import_survei.php).');
}

define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../includes/bootstrap.php';
// includes/auth.php (dimuat bootstrap.php) hanya mem-require repo/lokasi.php
// & repo/aktivitas.php secara otomatis -- repo/kontraktor.php TIDAK, jadi
// harus eksplisit di sini (dipakai repo_kontraktor_create()). repo/material.php
// TIDAK diperlukan: jenis_material hanya dibaca via query langsung (master
// data kecil/tetap, tidak ada repo_material_create() yang relevan di sini).
require_once __DIR__ . '/../includes/repo/kontraktor.php';

// -----------------------------------------------------------------------------
// 1. Argumen CLI
// -----------------------------------------------------------------------------
function cetak_bantuan(): void
{
    echo <<<TEKS
    Pemakaian: php database/import_survei.php --file=survei.csv [opsi]

      --file=PATH            Wajib. Berkas CSV hasil survei.
      --commit                Simpan sungguhan ke database (default: dry-run, hanya laporan).
      --dry-run               Eksplisit minta dry-run (perilaku default, boleh ditulis atau tidak).
      --create-kontraktor     Buat kontraktor baru otomatis bila nama di CSV tidak cocok yang sudah ada.
      --map=target=Kolom,...  Pemetaan kolom CSV -> field internal, mis.
                               --map=nama_lokasi=Nama Lokasi,latitude=Lat,longitude=Lng
                               Field yang dikenal: nama_lokasi, kontraktor, material, distrik,
                               kelurahan, alamat, latitude, longitude, luas_ha, status_izin,
                               nomor_izin, tanggal_berlaku_izin, tanggal_berakhir_izin, keterangan.
                               Tanpa --map, header CSV diasumsikan SAMA PERSIS dengan nama field di atas.
      --delimiter=;           Pemisah kolom CSV. Tanpa ini, dideteksi otomatis (, atau ;).
      --batch-size=25         Jumlah baris per transaksi DB (default 25).
      --errors=PATH           Lokasi errors.csv (default: <nama_berkas>_errors.csv di folder yang sama).
      --help                  Tampilkan bantuan ini.

    TEKS;
}

$opt = [
    'file' => null, 'commit' => false, 'create-kontraktor' => false,
    'map' => null, 'delimiter' => null, 'batch-size' => 25, 'errors' => null,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        cetak_bantuan();
        exit(0);
    }
    if ($arg === '--commit') {
        $opt['commit'] = true;
    } elseif ($arg === '--dry-run') {
        // no-op -- dry-run SUDAH default; diterima saja supaya pemanggil yang
        // menulisnya eksplisit di skrip/dokumentasi tidak dianggap error.
    } elseif ($arg === '--create-kontraktor') {
        $opt['create-kontraktor'] = true;
    } elseif (str_starts_with($arg, '--file=')) {
        $opt['file'] = substr($arg, 7);
    } elseif (str_starts_with($arg, '--map=')) {
        $opt['map'] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--delimiter=')) {
        $opt['delimiter'] = substr($arg, 12);
    } elseif (str_starts_with($arg, '--batch-size=')) {
        $opt['batch-size'] = max(1, (int) substr($arg, 13));
    } elseif (str_starts_with($arg, '--errors=')) {
        $opt['errors'] = substr($arg, 9);
    } else {
        fwrite(STDERR, "Argumen tidak dikenal: {$arg}\n\n");
        cetak_bantuan();
        exit(1);
    }
}

if ($opt['file'] === null || !is_file($opt['file'])) {
    fwrite(STDERR, "Berkas CSV tidak ditemukan: " . ($opt['file'] ?? '(--file tidak diisi)') . "\n\n");
    cetak_bantuan();
    exit(1);
}

$FIELD_DIKENAL = [
    'nama_lokasi', 'kontraktor', 'material', 'distrik', 'kelurahan', 'alamat',
    'latitude', 'longitude', 'luas_ha', 'status_izin', 'nomor_izin',
    'tanggal_berlaku_izin', 'tanggal_berakhir_izin', 'keterangan',
];
// Default: header CSV SAMA PERSIS dengan nama field (identitas).
$pemetaan = array_combine($FIELD_DIKENAL, $FIELD_DIKENAL);
if ($opt['map'] !== null) {
    foreach (explode(',', $opt['map']) as $pasangan) {
        [$field, $kolom] = array_pad(explode('=', $pasangan, 2), 2, null);
        if ($field === null || $kolom === null || !in_array($field, $FIELD_DIKENAL, true)) {
            fwrite(STDERR, "--map tidak valid di dekat '{$pasangan}'. Field yang dikenal: " . implode(', ', $FIELD_DIKENAL) . "\n");
            exit(1);
        }
        $pemetaan[$field] = $kolom;
    }
}

$errors_path = $opt['errors'] ?? (preg_replace('/\.csv$/i', '', $opt['file']) . '_errors.csv');

// -----------------------------------------------------------------------------
// 2. Baca berkas + normalisasi encoding (edge case wajib T-28: Windows-1252, BOM)
// -----------------------------------------------------------------------------
function baca_dan_normalisasi_isi(string $path): string
{
    $mentah = (string) file_get_contents($path);

    // BOM UTF-8 (umum dari "Save As CSV UTF-8" Excel) -- buang SEBELUM deteksi
    // encoding, karena 3 byte BOM sendiri bukan bagian data.
    if (str_starts_with($mentah, "\xEF\xBB\xBF")) {
        $mentah = substr($mentah, 3);
    }

    // Edge case wajib T-28: berkas survei lapangan sering dari Excel versi
    // lama/Windows yang menyimpan sebagai Windows-1252 (ANSI), BUKAN UTF-8 --
    // byte non-ASCII-nya akan merusak nama lokasi/kontraktor kalau dibaca
    // mentah sebagai UTF-8. mb_check_encoding() membedakan: UTF-8 punya
    // aturan byte-sequence yang ketat, byte Windows-1252 non-ASCII HAMPIR
    // PASTI membuatnya gagal sebagai UTF-8 valid.
    if (!mb_check_encoding($mentah, 'UTF-8')) {
        $dikonversi = @iconv('Windows-1252', 'UTF-8', $mentah);
        if ($dikonversi !== false) {
            $mentah = $dikonversi;
        }
    }

    return $mentah;
}

/** Deteksi pemisah kolom dari baris header -- ';' lebih umum dari CSV Excel lokal Indonesia (edge case wajib T-28), ',' standar RFC 4180. */
function deteksi_pemisah(string $baris_header): string
{
    $jumlah_titik_koma = substr_count($baris_header, ';');
    $jumlah_koma = substr_count($baris_header, ',');

    return $jumlah_titik_koma > $jumlah_koma ? ';' : ',';
}

$isi = baca_dan_normalisasi_isi($opt['file']);
$baris_pertama = strtok($isi, "\n");
$pemisah = $opt['delimiter'] ?? deteksi_pemisah((string) $baris_pertama);
echo "Pemisah kolom terdeteksi: '" . $pemisah . "'\n";

$stream = fopen('php://memory', 'r+');
fwrite($stream, $isi);
rewind($stream);
$header = fgetcsv($stream, 0, $pemisah);
if ($header === false || $header === null) {
    fwrite(STDERR, "Berkas CSV kosong atau header tidak terbaca.\n");
    exit(1);
}
$header = array_map(static fn ($h) => trim((string) $h), $header);

// -----------------------------------------------------------------------------
// 3. Normalisasi nilai per sel
// -----------------------------------------------------------------------------

/** Koma desimal -> titik ("131,25" -> "131.25"), HANYA bila format jelas koma-sebagai-desimal (satu koma, bukan pemisah ribuan). */
function normalisasi_koma_desimal(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^-?\d+,\d+$/', $raw)) {
        return str_replace(',', '.', $raw);
    }

    return $raw;
}

/**
 * Format derajat-menit-detik (DMS) -> desimal (edge case wajib T-28), mis.
 * `0°52'34.3"S`, `131°15'3.6"E`, atau varian tanpa simbol (`0 52 34.3 S`).
 * Huruf S/W membuat hasilnya NEGATIF; N/E positif (atau tanda minus di depan
 * dihormati bila sudah ada, tanpa huruf arah).
 */
function urai_dms(string $raw): ?float
{
    $raw = trim($raw);
    $pola = '/^(-?)(\d+)[°\s]+(\d+)[\'\s]+([\d.]+)["\s]*([NSEWnsew]?)$/';
    if (!preg_match($pola, $raw, $m)) {
        return null;
    }
    [, $minus, $derajat, $menit, $detik, $arah] = $m;
    $nilai = (float) $derajat + ((float) $menit / 60) + ((float) $detik / 3600);
    if ($minus === '-' || in_array(strtoupper($arah), ['S', 'W'], true)) {
        $nilai *= -1;
    }

    return $nilai;
}

/** Satu pintu normalisasi koordinat: DMS atau desimal (koma/titik), null bila sama sekali tidak terbaca. */
function normalisasi_koordinat(string $raw): ?float
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $dms = urai_dms($raw);
    if ($dms !== null) {
        return $dms;
    }
    $desimal = normalisasi_koma_desimal($raw);

    return is_numeric($desimal) ? (float) $desimal : null;
}

function dalam_bbox_sorong(float $lat, float $lng): bool
{
    return $lat >= SORONG_LAT_MIN && $lat <= SORONG_LAT_MAX && $lng >= SORONG_LNG_MIN && $lng <= SORONG_LNG_MAX;
}

/** Jarak permukaan bumi (meter) antara dua titik lat/lng -- formula haversine, radius bumi 6.371.000 m. */
function jarak_haversine_meter(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $rad = M_PI / 180;
    $dlat = ($lat2 - $lat1) * $rad;
    $dlng = ($lng2 - $lng1) * $rad;
    $a = sin($dlat / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin($dlng / 2) ** 2;

    return 2 * $r * atan2(sqrt($a), sqrt(1 - $a));
}

function kunci_idempoten(string $nama, float $lat, float $lng): string
{
    return mb_strtolower(trim($nama)) . '@' . sprintf('%.5f,%.5f', round($lat, 5), round($lng, 5));
}

// -----------------------------------------------------------------------------
// 4. Pencocokan kontraktor & material
// -----------------------------------------------------------------------------
function cari_atau_buat_kontraktor(string $nama, bool $boleh_buat, array &$kontraktor_baru_dibuat): ?int
{
    static $kontraktor_dibuat_sesi = []; // nama(lower) -> id -- CSV yang menyebut kontraktor baru yang SAMA di banyak baris hanya dibuat SEKALI per sesi skrip ini.
    $nama = trim($nama);
    if ($nama === '') {
        return null;
    }
    $kunci = mb_strtolower($nama);
    if (isset($kontraktor_dibuat_sesi[$kunci])) {
        return $kontraktor_dibuat_sesi[$kunci];
    }

    $row = db_one('SELECT id FROM kontraktor WHERE LOWER(nama_perusahaan) = :n', ['n' => $kunci]);
    if ($row !== null) {
        return (int) $row['id'];
    }

    if (!$boleh_buat) {
        return null;
    }

    $id = repo_kontraktor_create([
        'nama_perusahaan' => $nama,
        // Field wajib di schema (NOT NULL) tapi memang tidak ada di data
        // survei koordinat -- placeholder EKSPLISIT, bukan string kosong,
        // supaya jelas di UI admin bahwa ini perlu dilengkapi manual.
        'penanggung_jawab' => '(lengkapi manual -- dibuat otomatis oleh import_survei.php)',
        'telepon' => null,
        'email' => null,
        'alamat' => null,
    ]);
    $kontraktor_dibuat_sesi[$kunci] = $id;
    $kontraktor_baru_dibuat[] = $nama;

    return $id;
}

function cari_material(string $nama): ?int
{
    $nama = trim($nama);
    if ($nama === '') {
        return null;
    }
    $row = db_one('SELECT id FROM jenis_material WHERE LOWER(nama) = :n', ['n' => mb_strtolower($nama)]);

    return $row !== null ? (int) $row['id'] : null;
}

// -----------------------------------------------------------------------------
// 5. Muat lokasi yang SUDAH ADA sekali di memori (bukan query per baris) --
//    dipakai untuk kunci idempoten DAN pemeriksaan jarak haversine.
// -----------------------------------------------------------------------------
$lokasi_ada = db_all('SELECT nama_lokasi, latitude, longitude FROM lokasi_tambang');
$kunci_ada = [];
foreach ($lokasi_ada as $l) {
    $kunci_ada[kunci_idempoten($l['nama_lokasi'], (float) $l['latitude'], (float) $l['longitude'])] = true;
}
// Dipakai KHUSUS untuk pengecekan haversine+kemiripan nama (fuzzy) --
// terpisah dari $kunci_ada (exact) karena dua jenis pemeriksaan berbeda
// (lihat Keputusan T-28).
$titik_ada = array_map(static fn ($l) => ['nama' => $l['nama_lokasi'], 'lat' => (float) $l['latitude'], 'lng' => (float) $l['longitude']], $lokasi_ada);

$admin_id_row = db_one("SELECT id FROM users WHERE username = 'admin'");
$admin_id = $admin_id_row !== null ? (int) $admin_id_row['id'] : null;

// -----------------------------------------------------------------------------
// 6. Proses setiap baris: normalisasi -> validasi -> dedup -> siap/tolak.
// -----------------------------------------------------------------------------
$siap = [];       // baris yang lolos semua pemeriksaan, siap di-INSERT
$ditolak = [];     // ['baris' => int, 'nama' => string, 'alasan' => string]
$dilewati_idempoten = 0;
$koreksi_tukar = 0;
$kontraktor_baru_dibuat = [];
$nomor_baris = 1; // header = baris 1

while (($kolom = fgetcsv($stream, 0, $pemisah)) !== false) {
    $nomor_baris++;
    if ($kolom === [null] || $kolom === false) {
        continue; // baris kosong, dilewati diam-diam
    }
    $kolom = array_map(static fn ($v) => trim((string) $v), $kolom);
    $baris = [];
    foreach ($header as $i => $nama_kolom) {
        $baris[$nama_kolom] = $kolom[$i] ?? '';
    }

    $ambil = static fn (string $field): string => $baris[$pemetaan[$field]] ?? '';

    $nama_lokasi = $ambil('nama_lokasi');
    if ($nama_lokasi === '') {
        $ditolak[] = ['baris' => $nomor_baris, 'nama' => '(kosong)', 'alasan' => 'Nama lokasi wajib diisi.'];
        continue;
    }

    $lat = normalisasi_koordinat($ambil('latitude'));
    $lng = normalisasi_koordinat($ambil('longitude'));
    if ($lat === null || $lng === null) {
        $ditolak[] = ['baris' => $nomor_baris, 'nama' => $nama_lokasi, 'alasan' => 'Latitude/longitude tidak terbaca sebagai angka (periksa format desimal/DMS).'];
        continue;
    }

    // Edge case wajib T-28: lat & lng TERTUKAR. Lat Kota Sorong selalu sekitar
    // -0.8..-1.0 (SORONG_LAT_MIN/MAX), lng selalu sekitar 131.x (SORONG_LNG_MIN/MAX)
    // -- kombinasi yang amat khas (lat kecil negatif, lng besar positif) tidak
    // mungkin tertukar dengan kota lain secara kebetulan. Bila kombinasi ASLI
    // gagal bbox TAPI versi TERTUKAR lolos, itu koreksi otomatis, bukan
    // penolakan -- spesifikasi: "deteksi dan TAWARKAN koreksi", bukan sekadar menolak.
    if (!dalam_bbox_sorong($lat, $lng) && dalam_bbox_sorong($lng, $lat)) {
        [$lat, $lng] = [$lng, $lat];
        $koreksi_tukar++;
    }

    if (!dalam_bbox_sorong($lat, $lng)) {
        $ditolak[] = [
            'baris' => $nomor_baris, 'nama' => $nama_lokasi,
            'alasan' => sprintf('Koordinat (%.6f, %.6f) di luar bounding box Kota Sorong, termasuk setelah dicoba ditukar.', $lat, $lng),
        ];
        continue;
    }

    $kontraktor_id = cari_atau_buat_kontraktor($ambil('kontraktor'), $opt['create-kontraktor'], $kontraktor_baru_dibuat);
    if ($kontraktor_id === null) {
        $ditolak[] = ['baris' => $nomor_baris, 'nama' => $nama_lokasi, 'alasan' => "Kontraktor '" . $ambil('kontraktor') . "' tidak ditemukan (pakai --create-kontraktor untuk membuat otomatis)."];
        continue;
    }

    $material_id = cari_material($ambil('material'));
    if ($material_id === null) {
        $ditolak[] = ['baris' => $nomor_baris, 'nama' => $nama_lokasi, 'alasan' => "Jenis material '" . $ambil('material') . "' tidak dikenal (master: Pasir, Batu, Kerikil, Tanah Urug, Sirtu)."];
        continue;
    }

    $status_izin = $ambil('status_izin') !== '' ? strtolower($ambil('status_izin')) : 'proses';
    if (!in_array($status_izin, ['berizin', 'tidak_berizin', 'proses'], true)) {
        $ditolak[] = ['baris' => $nomor_baris, 'nama' => $nama_lokasi, 'alasan' => "status_izin '{$status_izin}' tidak dikenal (berizin/tidak_berizin/proses)."];
        continue;
    }
    if ($status_izin === 'berizin' && $ambil('nomor_izin') === '') {
        $ditolak[] = ['baris' => $nomor_baris, 'nama' => $nama_lokasi, 'alasan' => 'status_izin "berizin" wajib menyertakan nomor_izin (aturan bisnis yang sama dengan lokasi/form.php).'];
        continue;
    }

    // --- Deteksi duplikat: DUA pemeriksaan berbeda (lihat Keputusan T-28) ---
    $kunci = kunci_idempoten($nama_lokasi, $lat, $lng);
    if (isset($kunci_ada[$kunci])) {
        $dilewati_idempoten++;
        continue; // BUKAN error -- idempoten by design, lihat verifikasi "impor kedua tidak menambah baris"
    }

    $duplikat_fuzzy = null;
    foreach ($titik_ada as $titik) {
        if (jarak_haversine_meter($lat, $lng, $titik['lat'], $titik['lng']) >= 20.0) {
            continue;
        }
        $persen = 0.0;
        similar_text(mb_strtolower($nama_lokasi), mb_strtolower($titik['nama']), $persen);
        if ($persen >= 70.0) {
            $duplikat_fuzzy = $titik['nama'];
            break;
        }
    }
    if ($duplikat_fuzzy !== null) {
        $ditolak[] = [
            'baris' => $nomor_baris, 'nama' => $nama_lokasi,
            'alasan' => "Kemungkinan duplikat: <20m dan nama mirip dengan lokasi yang sudah ada ('{$duplikat_fuzzy}'). Periksa manual, bukan ditolak otomatis sepenuhnya -- baris ini TIDAK diimpor, perbaiki/hapus dari CSV bila memang sama.",
        ];
        continue;
    }

    // Lolos semua pemeriksaan -- masuk antrean siap-simpan. Dimasukkan juga
    // ke $titik_ada & $kunci_ada SEKARANG (bukan setelah commit) supaya baris
    // BERIKUTNYA di file yang sama ikut terdeteksi sebagai duplikat/idempoten
    // terhadap baris INI, bukan hanya terhadap yang sudah ada di DB sebelumnya.
    $kunci_ada[$kunci] = true;
    $titik_ada[] = ['nama' => $nama_lokasi, 'lat' => $lat, 'lng' => $lng];

    $siap[] = [
        'baris' => $nomor_baris,
        'data' => [
            'nama_lokasi' => $nama_lokasi,
            'kontraktor_id' => $kontraktor_id,
            'material_id' => $material_id,
            'distrik' => $ambil('distrik') !== '' ? $ambil('distrik') : null,
            'kelurahan' => $ambil('kelurahan') !== '' ? $ambil('kelurahan') : null,
            'alamat' => $ambil('alamat') !== '' ? $ambil('alamat') : null,
            'latitude' => $lat,
            'longitude' => $lng,
            'luas_ha' => $ambil('luas_ha') !== '' ? normalisasi_koma_desimal($ambil('luas_ha')) : null,
            'status_izin' => $status_izin,
            'nomor_izin' => $ambil('nomor_izin') !== '' ? $ambil('nomor_izin') : null,
            'tanggal_berlaku_izin' => $ambil('tanggal_berlaku_izin') !== '' ? $ambil('tanggal_berlaku_izin') : null,
            'tanggal_berakhir_izin' => $ambil('tanggal_berakhir_izin') !== '' ? $ambil('tanggal_berakhir_izin') : null,
            'keterangan' => $ambil('keterangan') !== '' ? $ambil('keterangan') : null,
            'created_by' => $admin_id,
        ],
    ];
}
fclose($stream);

// -----------------------------------------------------------------------------
// 7. Simpan per batch (edge case wajib T-28: "impor sebagian gagal di tengah
//    file" -- satu batch gagal TIDAK menggagalkan batch lain yang sudah/akan
//    commit; baris dalam batch yang gagal dicatat ke errors.csv, bukan
//    menghentikan seluruh proses).
// -----------------------------------------------------------------------------
$berhasil_disimpan = 0;
if ($opt['commit'] && $siap !== []) {
    foreach (array_chunk($siap, (int) $opt['batch-size']) as $batch) {
        try {
            db_transaction(function () use ($batch, &$berhasil_disimpan) {
                foreach ($batch as $item) {
                    repo_lokasi_create($item['data']);
                    $berhasil_disimpan++;
                }
            });
        } catch (Throwable $e) {
            foreach ($batch as $item) {
                $ditolak[] = ['baris' => $item['baris'], 'nama' => $item['data']['nama_lokasi'], 'alasan' => 'Gagal disimpan (batch dibatalkan): ' . $e->getMessage()];
            }
            app_log('error', 'import_survei.php: satu batch gagal (' . count($batch) . ' baris) -- ' . $e->getMessage());
        }
    }
}

// -----------------------------------------------------------------------------
// 8. errors.csv -- BOM UTF-8 + pemisah titik koma (konsisten dengan laporan/
//    unduh.php, T-23); kolom teks bebas (nama) dilewatkan lewat csv_aman()
//    yang SAMA (proteksi CSV injection, nama bisa berisi payload berbahaya
//    seperti data pengguna lain manapun).
// -----------------------------------------------------------------------------
function csv_aman(?string $nilai): string
{
    $nilai = (string) $nilai;
    if ($nilai !== '' && in_array($nilai[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $nilai;
    }

    return $nilai;
}

$f = fopen($errors_path, 'wb');
fwrite($f, "\xEF\xBB\xBF");
fputcsv($f, ['Baris CSV', 'Nama Lokasi', 'Alasan Ditolak'], ';');
foreach ($ditolak as $d) {
    fputcsv($f, [(string) $d['baris'], csv_aman($d['nama']), csv_aman($d['alasan'])], ';');
}
fclose($f);

// -----------------------------------------------------------------------------
// 9. Ringkasan
// -----------------------------------------------------------------------------
$mode = $opt['commit'] ? 'COMMIT (disimpan sungguhan)' : 'DRY-RUN (tidak ada yang disimpan)';
echo "\n=== Ringkasan impor survei — mode {$mode} ===\n";
echo 'Total baris data dibaca   : ' . ($nomor_baris - 1) . "\n";
echo 'Siap/berhasil disimpan    : ' . ($opt['commit'] ? $berhasil_disimpan : count($siap)) . "\n";
echo 'Dilewati (sudah ada)      : ' . $dilewati_idempoten . "\n";
echo 'Ditolak -> errors.csv     : ' . count($ditolak) . "\n";
echo 'Koordinat dikoreksi (tukar lat/lng): ' . $koreksi_tukar . "\n";
if ($kontraktor_baru_dibuat !== []) {
    echo 'Kontraktor baru dibuat    : ' . implode(', ', $kontraktor_baru_dibuat) . "\n";
}
echo "errors.csv ditulis ke     : {$errors_path}\n";
if (!$opt['commit'] && $siap !== []) {
    echo "\n(Dry-run -- jalankan ulang dengan --commit untuk benar-benar menyimpan " . count($siap) . " baris di atas.)\n";
}

// Baris ditolak BUKAN kegagalan skrip (itu kerja normalnya -- lihat
// errors.csv) -- exit 0 selama proses CLI-nya sendiri selesai tanpa error.
exit(0);
