<?php

declare(strict_types=1);

/**
 * tests/validator_test.php — uji unit includes/validator.php (T-07).
 * Jalankan: php tests/validator_test.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$total = 0;
$failures = 0;

function cek(string $label, bool $kondisi): void
{
    global $total, $failures;
    $total++;
    if ($kondisi) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "=== 1. Aturan dasar: required, min, max, numeric, integer ===\n";

cek('required: string kosong -> error', validate(['nama' => ''], ['nama' => 'required'])['errors'] !== []);
cek('required: terisi -> lolos', validate(['nama' => 'Lokasi Klasaman'], ['nama' => 'required'])['valid']);
cek('required: hanya spasi -> error (trim dulu)', !validate(['nama' => '   '], ['nama' => 'required'])['valid']);

cek('min (panjang teks): "ab" min:3 -> error', !validate(['x' => 'ab'], ['x' => 'min:3'])['valid']);
cek('min (panjang teks): "abc" min:3 -> lolos', validate(['x' => 'abc'], ['x' => 'min:3'])['valid']);
cek('max (panjang teks): "abcdef" max:5 -> error', !validate(['x' => 'abcdef'], ['x' => 'max:5'])['valid']);

cek('numeric: "abc" -> error', !validate(['x' => 'abc'], ['x' => 'numeric'])['valid']);
cek('numeric: "123.5" -> lolos', validate(['x' => '123.5'], ['x' => 'numeric'])['valid']);

cek('integer: "2.5" (pecahan) -> error [edge case T-07]', !validate(['jumlah_pekerja' => '2.5'], ['jumlah_pekerja' => 'integer'])['valid']);
cek('integer: "5" -> lolos', validate(['jumlah_pekerja' => '5'], ['jumlah_pekerja' => 'integer'])['valid']);
cek('integer+min:0: "-3" (negatif) -> error [edge case T-07]', !validate(['jumlah_alat_berat' => '-3'], ['jumlah_alat_berat' => 'integer|min:0'])['valid']);
cek('integer+min:0: "0" -> lolos', validate(['jumlah_alat_berat' => '0'], ['jumlah_alat_berat' => 'integer|min:0'])['valid']);

echo "\n=== 2. Normalisasi koma desimal (PRD bagian 7) ===\n";

$hasil_koma = validate(['lat' => '-0,8762'], ['lat' => 'numeric']);
cek('normalisasi "-0,8762" -> "-0.8762" dan tetap numeric', $hasil_koma['valid'] && $hasil_koma['data']['lat'] === '-0.8762');

$hasil_teks_koma = validate(['ket' => 'Lokasi dekat jalan, dekat pasar'], ['ket' => 'required']);
cek('koma di TEKS BEBAS tidak ikut diubah jadi titik', $hasil_teks_koma['data']['ket'] === 'Lokasi dekat jalan, dekat pasar');

echo "\n=== 3. between, in, date, after_or_equal ===\n";

cek('between: "95" between:-90,90 -> error', !validate(['lat' => '95'], ['lat' => 'between:-90,90'])['valid']);
cek('between: "45" between:-90,90 -> lolos', validate(['lat' => '45'], ['lat' => 'between:-90,90'])['valid']);

cek('in: "proses" in:berizin,tidak_berizin,proses -> lolos', validate(['s' => 'proses'], ['s' => 'in:berizin,tidak_berizin,proses'])['valid']);
cek('in: "selesai" (bukan opsi) -> error', !validate(['s' => 'selesai'], ['s' => 'in:berizin,tidak_berizin,proses'])['valid']);

cek('date: "2026-02-30" (tanggal kalender tidak ada) -> error', !validate(['t' => '2026-02-30'], ['t' => 'date'])['valid']);
cek('date: "2026-02-28" -> lolos', validate(['t' => '2026-02-28'], ['t' => 'date'])['valid']);

$after_gagal = validate(
    ['tanggal_berlaku_izin' => '2025-01-01', 'tanggal_berakhir_izin' => '2024-01-01'],
    ['tanggal_berakhir_izin' => 'date|after_or_equal:tanggal_berlaku_izin']
);
cek('after_or_equal: berakhir SEBELUM berlaku -> error', !$after_gagal['valid']);

$after_lolos = validate(
    ['tanggal_berlaku_izin' => '2025-01-01', 'tanggal_berakhir_izin' => '2030-01-01'],
    ['tanggal_berakhir_izin' => 'date|after_or_equal:tanggal_berlaku_izin']
);
cek('after_or_equal: berakhir SETELAH berlaku -> lolos', $after_lolos['valid']);

echo "\n=== 4. email, phone, regex ===\n";

cek('email: "bukan-email" -> error', !validate(['e' => 'bukan-email'], ['e' => 'email'])['valid']);
cek('email: "admin@sorong.go.id" -> lolos', validate(['e' => 'admin@sorong.go.id'], ['e' => 'email'])['valid']);

cek('phone: "123" (terlalu pendek) -> error', !validate(['p' => '123'], ['p' => 'phone'])['valid']);
cek('phone: "0813-4001-1122" (dengan strip) -> lolos', validate(['p' => '0813-4001-1122'], ['p' => 'phone'])['valid']);

cek(
    'regex (bentuk array, aman dari "|" di pattern): "AB12" vs /^[A-Z]{2}\d+$/ -> lolos',
    validate(['kode' => 'AB12'], ['kode' => ['required', 'regex:/^[A-Z]{2}\d+$/']])['valid']
);
cek(
    'regex: "ab12" (huruf kecil) -> error',
    !validate(['kode' => 'ab12'], ['kode' => ['required', 'regex:/^[A-Z]{2}\d+$/']])['valid']
);

echo "\n=== 5. Edge case: input array tak terduga pada field skalar ===\n";

cek(
    'field[]=x&field[]=y pada field yang didefinisikan skalar -> error, tidak fatal',
    !validate(['nama' => ['x', 'y']], ['nama' => 'required'])['valid']
);
cek('old() mengembalikan default (bukan array) saat $_POST berisi array tak terduga', (function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['nama_aneh'] = ['x', 'y'];
    $hasil = old('nama_aneh', 'default-aman');
    unset($_POST['nama_aneh']);

    return $hasil === 'default-aman';
})());

echo "\n=== 6. Edge case: nama berisi Unicode vs karakter kontrol ===\n";

cek(
    'nama Unicode (aksara non-Latin) DIPERTAHANKAN, bukan ditolak',
    validate(['nama' => 'José Müller Søren 郑'], ['nama' => 'required'])['data']['nama'] === 'José Müller Søren 郑'
);
cek(
    'nama dengan karakter kontrol (NUL, 0x01) DIBERSIHKAN, bukan meloloskannya mentah',
    validate(["nama" => "Lokasi\x00Uji\x01Rusak"], ['nama' => 'required'])['data']['nama'] === 'LokasiUjiRusak'
);

echo "\n=== 7. old() untuk isi ulang form ===\n";

cek('old(): GET request -> selalu default, tidak baca $_POST', (function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST['nama'] = 'harus-diabaikan';
    $hasil = old('nama', 'nilai-default');
    unset($_POST['nama']);

    return $hasil === 'nilai-default';
})());
cek('old(): POST request dengan field ada -> kembalikan nilai submit', (function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['nama'] = 'Lokasi Diisi Ulang';
    $hasil = old('nama', 'nilai-default');
    unset($_POST['nama']);

    return $hasil === 'Lokasi Diisi Ulang';
})());

echo "\n=== 8. validate_koordinat() ===\n";

$k1 = validate_koordinat('-0.8762', '131.2551');
cek('koordinat valid DALAM bounding box Kota Sorong -> tanpa error/warning', $k1['errors'] === [] && $k1['warnings'] === []);

$k2 = validate_koordinat('-10.5', '131.2551');
cek('koordinat valid secara WGS84 tapi LUAR bounding box Sorong -> warning, BUKAN error [spesifikasi T-07]', $k2['errors'] === [] && $k2['warnings'] !== []);

$k3 = validate_koordinat('200', '131.2551');
cek('latitude di luar -90..90 -> error keras', $k3['errors'] !== [] && isset($k3['errors']['latitude']));

$k4 = validate_koordinat('-0,8762', '131,2551'); // notasi koma Indonesia
cek('validate_koordinat menormalisasi koma desimal juga', $k4['errors'] === [] && $k4['latitude'] === -0.8762);

$k5 = validate_koordinat('bukan-angka', '131.2551');
cek('latitude bukan angka -> error, bukan fatal/TypeError', $k5['errors'] !== []);

echo "\n=== 9. validate_geojson_polygon() ===\n";

$poligon_valid = json_encode([
    'type' => 'Feature',
    'geometry' => [
        'type' => 'Polygon',
        'coordinates' => [[[131.25, -0.87], [131.26, -0.87], [131.26, -0.88], [131.25, -0.88], [131.25, -0.87]]],
    ],
]);
$g1 = validate_geojson_polygon($poligon_valid);
cek('poligon persegi tertutup valid (Feature->Polygon) -> lolos', $g1['errors'] === [] && $g1['geojson'] !== null);

$poligon_bare = json_encode([
    'type' => 'Polygon',
    'coordinates' => [[[131.25, -0.87], [131.26, -0.87], [131.26, -0.88], [131.25, -0.87]]],
]);
cek('geometry Polygon TELANJANG (tanpa pembungkus Feature) juga diterima', validate_geojson_polygon($poligon_bare)['errors'] === []);

cek('poligon kosong ("") -> TIDAK error (opsional, FR-09)', validate_geojson_polygon('')['errors'] === []);
cek('poligon null -> TIDAK error (opsional)', validate_geojson_polygon(null)['errors'] === []);

cek('JSON rusak -> error, bukan fatal', validate_geojson_polygon('{bukan json valid')['errors'] !== []);

cek(
    'bukan Polygon (mis. Point) -> error',
    validate_geojson_polygon(json_encode(['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [131.25, -0.87]]]))['errors'] !== []
);

$poligon_kurang_titik = json_encode(['type' => 'Polygon', 'coordinates' => [[[131.25, -0.87], [131.26, -0.87], [131.25, -0.87]]]]);
cek('cincin kurang dari 4 titik -> error [spesifikasi T-07]', validate_geojson_polygon($poligon_kurang_titik)['errors'] !== []);

$poligon_tidak_tertutup = json_encode(['type' => 'Polygon', 'coordinates' => [[[131.25, -0.87], [131.26, -0.87], [131.26, -0.88], [131.20, -0.90]]]]);
cek('cincin TIDAK tertutup (titik awal != akhir) -> error', validate_geojson_polygon($poligon_tidak_tertutup)['errors'] !== []);

$poligon_lubang = json_encode(['type' => 'Polygon', 'coordinates' => [
    [[131.20, -0.80], [131.30, -0.80], [131.30, -0.90], [131.20, -0.90], [131.20, -0.80]],
    [[131.22, -0.82], [131.24, -0.82], [131.24, -0.84], [131.22, -0.84], [131.22, -0.82]],
]]);
cek('poligon dengan LUBANG (2 cincin) -> error, tidak didukung [spesifikasi T-07]', validate_geojson_polygon($poligon_lubang)['errors'] !== []);

// Poligon "dasi kupu-kupu" (bowtie): sisi saling berpotongan di tengah.
$poligon_potong = json_encode(['type' => 'Polygon', 'coordinates' => [[
    [131.20, -0.80], [131.30, -0.90], [131.30, -0.80], [131.20, -0.90], [131.20, -0.80],
]]]);
cek('poligon self-intersection (bowtie) -> error [edge case T-07]', validate_geojson_polygon($poligon_potong)['errors'] !== []);

$poligon_lng_lat_terbalik = json_encode(['type' => 'Polygon', 'coordinates' => [[
    [-0.87, 131.25], [-0.87, 131.26], [-0.88, 131.26], [-0.88, 131.25], [-0.87, 131.25],
]]]);
cek(
    'urutan [lat,lng] terbalik (lat=-0.87 ditaruh di posisi lng) -> tertangkap sebagai di luar rentang [edge case T-07]',
    validate_geojson_polygon($poligon_lng_lat_terbalik)['errors'] !== []
);

$poligon_besar = json_encode(['type' => 'Polygon', 'coordinates' => [array_fill(0, 1, [131.25, -0.87])]]);
$raw_besar = str_repeat('a', 201 * 1024); // > 200 KB, bukan JSON valid tapi ukurannya yang diuji dulu
cek('data poligon > 200 KB -> ditolak karena ukuran, SEBELUM proses JSON [spesifikasi T-07]', validate_geojson_polygon($raw_besar)['errors'] !== []);

echo "\n=== Ringkasan ===\n";
echo "Total kasus uji: {$total}, gagal: {$failures}\n";

exit($failures > 0 ? 1 : 0);
