<?php

declare(strict_types=1);

// CLI import koordinat hasil survei lapangan ke lokasi_tambang; CLI-only (bukan dev-only, bisa menulis ke produksi). Default tanpa --commit adalah dry-run.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: jalankan hanya lewat command line (php database/import_survei.php).');
}

define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../includes/bootstrap.php';
// bootstrap.php tidak otomatis memuat repo/kontraktor.php.
require_once __DIR__ . '/../includes/repo/kontraktor.php';

// 1. Argumen CLI
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
        // no-op -- dry-run sudah default, diterima saja agar tidak dianggap argumen error.
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

// 2. Baca berkas + normalisasi encoding
function baca_dan_normalisasi_isi(string $path): string
{
    $mentah = (string) file_get_contents($path);

    // Buang BOM UTF-8 sebelum deteksi encoding; 3 byte BOM bukan bagian data.
    if (str_starts_with($mentah, "\xEF\xBB\xBF")) {
        $mentah = substr($mentah, 3);
    }

    // Berkas survei lama/Windows sering Windows-1252, bukan UTF-8; mb_check_encoding() membedakannya.
    if (!mb_check_encoding($mentah, 'UTF-8')) {
        $dikonversi = @iconv('Windows-1252', 'UTF-8', $mentah);
        if ($dikonversi !== false) {
            $mentah = $dikonversi;
        }
    }

    return $mentah;
}

// Deteksi pemisah kolom dari baris header: ';' umum dari Excel lokal Indonesia, ',' standar RFC 4180.
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

// 3. Normalisasi nilai per sel

// Koma desimal -> titik, hanya bila formatnya jelas satu koma (bukan pemisah ribuan).
function normalisasi_koma_desimal(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/^-?\d+,\d+$/', $raw)) {
        return str_replace(',', '.', $raw);
    }

    return $raw;
}

// Format derajat-menit-detik (DMS) -> desimal, mis. `0°52'34.3"S`; huruf S/W membuat hasilnya negatif.
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

// Satu pintu normalisasi koordinat: DMS atau desimal (koma/titik).
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

// Jarak permukaan bumi (meter) antara dua titik lat/lng, formula haversine.
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

// 4. Pencocokan kontraktor & material
function cari_atau_buat_kontraktor(string $nama, bool $boleh_buat, array &$kontraktor_baru_dibuat): ?int
{
    static $kontraktor_dibuat_sesi = []; // nama(lower) -> id; kontraktor baru yang sama dibuat sekali per sesi.
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
        // Kolom NOT NULL tanpa data di CSV survei; placeholder eksplisit agar jelas perlu dilengkapi manual.
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

// 5. Muat lokasi yang sudah ada sekali di memori (bukan query per baris).
$lokasi_ada = db_all('SELECT nama_lokasi, latitude, longitude FROM lokasi_tambang');
$kunci_ada = [];
foreach ($lokasi_ada as $l) {
    $kunci_ada[kunci_idempoten($l['nama_lokasi'], (float) $l['latitude'], (float) $l['longitude'])] = true;
}
// Terpisah dari $kunci_ada (exact): dipakai untuk pengecekan haversine+kemiripan nama (fuzzy).
$titik_ada = array_map(static fn ($l) => ['nama' => $l['nama_lokasi'], 'lat' => (float) $l['latitude'], 'lng' => (float) $l['longitude']], $lokasi_ada);

$admin_id_row = db_one("SELECT id FROM users WHERE username = 'admin'");
$admin_id = $admin_id_row !== null ? (int) $admin_id_row['id'] : null;

// 6. Proses setiap baris: normalisasi -> validasi -> dedup -> siap/tolak.
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

    // Bila kombinasi asli gagal bbox tapi versi tertukar lolos, koreksi otomatis lat/lng tertukar.
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
        $ditolak[] = ['baris' => $nomor_baris, 'nama' => $nama_lokasi, 'alasan' => 'status_izin "berizin" wajib menyertakan nomor_izin.'];
        continue;
    }

    $kunci = kunci_idempoten($nama_lokasi, $lat, $lng);
    if (isset($kunci_ada[$kunci])) {
        $dilewati_idempoten++;
        continue; // Bukan error -- idempoten by design.
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
            'alasan' => "Kemungkinan duplikat: <20m dan nama mirip dengan lokasi yang sudah ada ('{$duplikat_fuzzy}'). Periksa manual; baris ini tidak diimpor.",
        ];
        continue;
    }

    // Masuk ke $titik_ada/$kunci_ada sekarang agar baris berikutnya di file yang sama ikut terdeteksi sebagai duplikat.
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

// 7. Simpan per batch; satu batch gagal tidak menggagalkan batch lain, dicatat ke errors.csv.
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

// 8. errors.csv: BOM UTF-8 + pemisah titik koma; csv_aman() proteksi CSV injection pada kolom teks bebas.
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

// 9. Ringkasan
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

// Baris ditolak bukan kegagalan skrip; exit 0 selama proses CLI-nya selesai tanpa error.
exit(0);
