<?php

declare(strict_types=1);

// Validasi mandiri (tidak butuh koneksi DB) untuk assets/geojson/batas_kota_sorong.geojson; berdiri sendiri tanpa bootstrap.php agar tidak bergantung ke sesi/DB hanya untuk ini.

// Tidak require bootstrap.php, jadi tidak punya APP_BOOTSTRAPPED; PHP_SAPI satu-satunya penjaga akses web di sini.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: jalankan hanya lewat command line (php database/validate_geojson.php).');
}

const MAX_UKURAN_BYTE = 150 * 1024;

// Harus tetap sama dengan SORONG_LAT/LNG_MIN/MAX di config/config.php; perbarui manual bila itu berubah.
const SORONG_LAT_MIN = -1.05;
const SORONG_LAT_MAX = -0.75;
const SORONG_LNG_MIN = 131.15;
const SORONG_LNG_MAX = 131.45;

$errors = [];
$warnings = [];

$path = $argv[1] ?? __DIR__ . '/../assets/geojson/batas_kota_sorong.geojson';

function tampilkan_hasil_dan_keluar(array $errors, array $warnings): void
{
    echo "\n";
    foreach ($warnings as $w) {
        echo "[PERINGATAN] {$w}\n";
    }
    foreach ($errors as $e) {
        echo "[GAGAL] {$e}\n";
    }

    if ($errors === []) {
        echo "\nVALID -- lulus semua pemeriksaan wajib.\n";
        exit(0);
    }

    echo "\nTIDAK VALID -- " . count($errors) . " masalah ditemukan.\n";
    exit(1);
}

// Luas bertanda (shoelace); positif = counter-clockwise, negatif = clockwise.
function luas_bertanda(array $ring): float
{
    $sum = 0.0;
    $n = count($ring);
    for ($i = 0; $i < $n - 1; $i++) {
        $sum += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
    }

    return $sum / 2;
}

if (!is_file($path)) {
    fwrite(STDERR, "Berkas tidak ditemukan: {$path}\n");
    exit(1);
}

echo "Berkas: {$path}\n";
$ukuran = filesize($path);
printf("Ukuran: %.1f KB\n", $ukuran / 1024);
if ($ukuran > MAX_UKURAN_BYTE) {
    $errors[] = sprintf('Ukuran file %.1f KB melebihi batas 150 KB -- simplifikasi lebih lanjut diperlukan.', $ukuran / 1024);
}

$raw = file_get_contents($path);
$data = json_decode($raw, true);
if (!is_array($data)) {
    $errors[] = 'Berkas bukan JSON yang valid.';
    tampilkan_hasil_dan_keluar($errors, $warnings);
}

// RFC 7946 tidak memakai member "crs" (WGS84 selalu tersirat); gagalkan hanya bila isinya eksplisit bukan WGS84/CRS84.
if (isset($data['crs'])) {
    $nama_crs = (string) ($data['crs']['properties']['name'] ?? 'tidak diketahui');
    if (stripos($nama_crs, '4326') === false && stripos($nama_crs, 'CRS84') === false) {
        $errors[] = "Member \"crs\" eksplisit menyebut \"{$nama_crs}\", bukan WGS84/EPSG:4326. Ekspor ulang dari QGIS: Layer > Save As > CRS = EPSG:4326.";
    } else {
        $warnings[] = 'Berkas memuat member "crs" eksplisit. RFC 7946 tidak membutuhkannya (WGS84 selalu tersirat) -- aman diabaikan, tapi sebaiknya dihapus saat ekspor berikutnya.';
    }
}

if (($data['properties']['placeholder'] ?? false) === true) {
    $warnings[] = 'Berkas ditandai properties.placeholder=true -- ini BUKAN batas resmi, lihat README untuk status penggantian data asli.';
}

// Kumpulkan semua geometry Polygon/MultiPolygon dari bentuk Feature, FeatureCollection, atau geometry telanjang.
$geometries = [];
$tipe_root = $data['type'] ?? null;
if ($tipe_root === 'FeatureCollection') {
    foreach ($data['features'] ?? [] as $f) {
        if (isset($f['geometry'])) {
            $geometries[] = $f['geometry'];
        }
    }
} elseif ($tipe_root === 'Feature') {
    if (isset($data['geometry'])) {
        $geometries[] = $data['geometry'];
    }
} elseif (in_array($tipe_root, ['Polygon', 'MultiPolygon'], true)) {
    $geometries[] = $data;
} else {
    $errors[] = "Struktur GeoJSON tidak dikenali (type=\"{$tipe_root}\"); harus Feature/FeatureCollection berisi geometry Polygon/MultiPolygon.";
}

$total_titik = 0;
$min_lon = 180.0;
$max_lon = -180.0;
$min_lat = 90.0;
$max_lat = -90.0;
$crs_rusak = false;

foreach ($geometries as $p_idx => $geom) {
    $tipe = $geom['type'] ?? null;
    if (!in_array($tipe, ['Polygon', 'MultiPolygon'], true)) {
        $errors[] = "Geometry[{$p_idx}] bertipe \"{$tipe}\" tidak didukung untuk layer batas administrasi (harus Polygon/MultiPolygon).";
        continue;
    }

    $semua_polygon = $tipe === 'Polygon' ? [$geom['coordinates']] : $geom['coordinates'];

    foreach ($semua_polygon as $poly_idx => $semua_cincin) {
        foreach ($semua_cincin as $ring_idx => $ring) {
            $n = count($ring);
            $total_titik += $n;

            if ($n < 4) {
                $errors[] = "Geometry[{$p_idx}] polygon[{$poly_idx}] cincin[{$ring_idx}] hanya punya {$n} titik (minimal 4 termasuk penutup).";
                continue;
            }
            if ($ring[0] !== $ring[$n - 1]) {
                $errors[] = "Geometry[{$p_idx}] polygon[{$poly_idx}] cincin[{$ring_idx}] tidak tertutup (titik awal berbeda dari titik akhir).";
            }

            foreach ($ring as $titik) {
                if (!is_array($titik) || count($titik) < 2 || !is_numeric($titik[0]) || !is_numeric($titik[1])) {
                    $errors[] = "Geometry[{$p_idx}] polygon[{$poly_idx}] cincin[{$ring_idx}] punya titik yang bukan [lng,lat] numerik.";
                    continue 3;
                }

                // CRS bukan WGS84: koordinat UTM (ratusan ribu/jutaan meter) alih-alih derajat.
                if (!$crs_rusak && (abs((float) $titik[0]) > 180 || abs((float) $titik[1]) > 90)) {
                    $errors[] = sprintf(
                        'Ditemukan koordinat [%s, %s] di luar rentang derajat WGS84 -- kemungkinan CRS sumber BUKAN EPSG:4326 (mis. UTM zona 53S untuk Papua). Ekspor ulang dari QGIS dengan "Reproject Layer" ke EPSG:4326 sebelum menyimpan GeoJSON.',
                        $titik[0],
                        $titik[1]
                    );
                    $crs_rusak = true; // satu pesan cukup, hindari spam ratusan baris serupa
                }

                $min_lon = min($min_lon, (float) $titik[0]);
                $max_lon = max($max_lon, (float) $titik[0]);
                $min_lat = min($min_lat, (float) $titik[1]);
                $max_lat = max($max_lat, (float) $titik[1]);
            }

            if ($crs_rusak) {
                continue;
            }

            // RFC 7946: cincin eksterior (indeks 0) wajib counter-clockwise; cincin lubang wajib clockwise.
            $luas = luas_bertanda($ring);
            if ($ring_idx === 0 && $luas < 0) {
                $errors[] = "Geometry[{$p_idx}] polygon[{$poly_idx}]: cincin eksterior arahnya CLOCKWISE -- RFC 7946 mewajibkan counter-clockwise. Balik urutan titiknya.";
            } elseif ($ring_idx > 0 && $luas > 0) {
                $errors[] = "Geometry[{$p_idx}] polygon[{$poly_idx}] cincin[{$ring_idx}] (lubang) arahnya counter-clockwise -- RFC 7946 mewajibkan cincin lubang clockwise.";
            }
        }
    }
}

echo "Total titik: {$total_titik}\n";
if ($min_lon <= $max_lon && !$crs_rusak) {
    printf("Bounding box data: lon %.4f..%.4f, lat %.4f..%.4f\n", $min_lon, $max_lon, $min_lat, $max_lat);

    $margin = 0.3; // derajat, kelonggaran wajar sebelum dianggap "mencurigakan"
    if ($min_lon < SORONG_LNG_MIN - $margin || $max_lon > SORONG_LNG_MAX + $margin
        || $min_lat < SORONG_LAT_MIN - $margin || $max_lat > SORONG_LAT_MAX + $margin) {
        $warnings[] = 'Bounding box data jauh di luar perkiraan wilayah Kota Sorong (lihat SORONG_LAT/LNG_MIN/MAX di atas, harus sama dengan config/config.php) -- periksa apakah berkas ini benar batas Kota Sorong, bukan wilayah lain.';
    }
}

tampilkan_hasil_dan_keluar($errors, $warnings);
