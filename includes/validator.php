<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/validator.php — satu-satunya mekanisme validasi input server-side
 * (T-07, PRD bagian 5, 7, 11). assets/js/validation.js HANYA memperbaiki UX
 * (pesan inline instan); yang dijalankan di sini tetap otoritas akhir --
 * setiap form (T-08/T-09/T-17) wajib memanggil validate() sebelum menulis DB,
 * terlepas dari apa pun yang sudah "lolos" di klien.
 */

define('GEOJSON_POLYGON_MAX_BYTES', 200 * 1024);

/**
 * Hilangkan spasi di tepi + karakter kontrol (edge case T-07: nama berisi
 * karakter kontrol). Unicode BIASA (huruf aksara apa pun, termasuk di luar
 * Latin) SENGAJA dipertahankan apa adanya -- nama orang/lokasi sah memuat itu.
 */
function normalize_string(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);

    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
}

/**
 * Koma desimal -> titik (PRD bagian 7: "-0,8762" jadi "-0.8762"), HANYA bila
 * seluruh string berbentuk persis "[-]digit+,digit+" -- supaya tidak salah
 * menyentuh teks bebas yang memang memuat koma (mis. field keterangan), atau
 * angka yang sudah memakai titik/pemisah ribuan ambigu.
 */
function normalize_decimal(string $value): string
{
    return preg_match('/^-?\d+,\d+$/', $value) === 1 ? str_replace(',', '.', $value) : $value;
}

/** Label Indonesia default dari nama field ("nama_lokasi" -> "nama lokasi") bila tidak diberi $labels eksplisit. */
function field_label(string $field, array $labels): string
{
    return $labels[$field] ?? str_replace('_', ' ', $field);
}

function is_valid_date_string(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }

    [$y, $m, $d] = array_map('intval', explode('-', $value));

    return checkdate($m, $d, $y);
}

/**
 * Terapkan satu aturan ("nama" + parameter opsional) pada satu nilai yang
 * SUDAH dinormalisasi. Mengembalikan pesan error (string) atau null bila lolos.
 * $is_numeric_field menentukan apakah min/max membandingkan ANGKA (field itu
 * juga punya aturan 'numeric'/'between') atau PANJANG STRING (field teks biasa).
 */
function apply_validation_rule(string $name, ?string $param, string $value, string $field, array $input, array $labels, bool $is_numeric_field): ?string
{
    $label = field_label($field, $labels);

    switch ($name) {
        case 'required':
            return $value === '' ? "{$label} wajib diisi." : null;

        case 'numeric':
            return is_numeric($value) ? null : "{$label} harus berupa angka.";

        case 'integer':
            // Edge case T-07: jumlah pekerja/alat berat tidak boleh pecahan.
            // "5.0" tetap lolos (nilainya bulat); "2.5" ditolak.
            if (!is_numeric($value)) {
                return "{$label} harus berupa bilangan bulat.";
            }

            return (float) $value == floor((float) $value) ? null : "{$label} harus berupa bilangan bulat (tidak boleh pecahan).";

        case 'min':
            if ($is_numeric_field) {
                return is_numeric($value) && (float) $value < (float) $param
                    ? "{$label} tidak boleh kurang dari {$param}." : null;
            }

            return mb_strlen($value) < (int) $param ? "{$label} minimal {$param} karakter." : null;

        case 'max':
            if ($is_numeric_field) {
                return is_numeric($value) && (float) $value > (float) $param
                    ? "{$label} tidak boleh lebih dari {$param}." : null;
            }

            return mb_strlen($value) > (int) $param ? "{$label} maksimal {$param} karakter." : null;

        case 'between':
            [$min, $max] = array_map('trim', explode(',', (string) $param, 2) + [1 => '0']);
            if (!is_numeric($value)) {
                return "{$label} harus berupa angka.";
            }

            return ((float) $value < (float) $min || (float) $value > (float) $max)
                ? "{$label} harus antara {$min} dan {$max}." : null;

        case 'in':
            $options = array_map('trim', explode(',', (string) $param));

            return in_array($value, $options, true) ? null : "{$label} harus salah satu dari: " . implode(', ', $options) . '.';

        case 'date':
            return is_valid_date_string($value) ? null : "{$label} harus tanggal yang valid (format YYYY-MM-DD).";

        case 'after_or_equal':
            $other_raw = $input[$param] ?? null;
            if (is_array($other_raw) || $other_raw === null) {
                return null; // field pembanding tidak ada/tidak sah -- biarkan aturan field itu sendiri yang menangani
            }
            $other = normalize_string((string) $other_raw) ?? '';
            if ($other === '' || !is_valid_date_string($value) || !is_valid_date_string($other)) {
                return null; // format tanggal tidak valid sudah ditangkap aturan 'date' masing-masing
            }

            return $value >= $other ? null : "{$label} harus sama atau setelah " . field_label((string) $param, $labels) . '.';

        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : "{$label} harus alamat email yang valid.";

        case 'phone':
            // Pola longgar nomor Indonesia: 08xx / +628xx / 628xx, 9-14 digit
            // setelah prefiks. PRD tidak memberi format baku -- lihat Keputusan T-07.
            $bersih = preg_replace('/[\s\-()]/', '', $value);

            return preg_match('/^(\+62|62|0)8\d{8,12}$/', (string) $bersih) === 1 ? null : "{$label} harus nomor telepon Indonesia yang valid.";

        case 'regex':
            return preg_match((string) $param, $value) === 1 ? null : "Format {$label} tidak sesuai.";

        default:
            return null;
    }
}

/**
 * Validasi $input terhadap $rules. $rules: ['field' => 'required|max:150'] ATAU
 * ['field' => ['required', 'regex:/^[0-9]+$/']] -- bentuk array dipakai saat
 * parameter aturan (terutama regex) mengandung karakter '|' yang kalau tidak
 * akan salah terpisah oleh explode('|', ...).
 *
 * @return array{valid: bool, errors: array<string, string[]>, data: array<string, mixed>}
 */
function validate(array $input, array $rules, array $labels = []): array
{
    $errors = [];
    $data = [];

    foreach ($rules as $field => $rule_spec) {
        $rule_list = is_array($rule_spec) ? $rule_spec : explode('|', $rule_spec);
        $raw = $input[$field] ?? null;

        // Edge case T-07: field[]=... tak terduga pada field yang didefinisikan skalar.
        if (is_array($raw)) {
            $errors[$field][] = field_label($field, $labels) . ' memiliki format data yang tidak valid.';
            continue;
        }

        $is_numeric_field = array_intersect(['numeric', 'between', 'integer'], array_map(
            static fn (string $r) => explode(':', $r, 2)[0],
            $rule_list
        )) !== [];

        $value = normalize_string($raw === null ? null : (string) $raw) ?? '';
        if ($is_numeric_field) {
            $value = normalize_decimal($value);
        }
        $data[$field] = $value;

        $is_required = in_array('required', $rule_list, true);
        if ($value === '' && !$is_required) {
            continue; // kosong & opsional -> lewati aturan lain untuk field ini
        }

        foreach ($rule_list as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            $message = apply_validation_rule($name, $param, $value, $field, $input, $labels, $is_numeric_field);

            if ($message !== null) {
                $errors[$field][] = $message;
                break; // satu pesan per field cukup untuk form sederhana proyek ini
            }
        }
    }

    return ['valid' => $errors === [], 'errors' => $errors, 'data' => $data];
}

/**
 * Helper template: class CSS "field--invalid" bila $errors memuat pesan untuk
 * $field, string kosong jika tidak. Dipakai lewat e(field_error_class(...))
 * di form -- bukan aturan validasi, sekadar menghindari ternary inline
 * `<?= $var ? ... : ... ?>` yang lolos pola "echo tanpa e()" (PRD bagian 11)
 * walau secara praktik aman (hasilnya selalu string literal tetap).
 */
function field_error_class(array $errors, string $field): string
{
    return isset($errors[$field]) && $errors[$field] !== [] ? 'field--invalid' : '';
}

/**
 * Helper template: fragmen atribut `aria-invalid` + `aria-describedby` untuk
 * satu field, atau string kosong bila field itu valid (T-25). SEBELUM T-25,
 * `<p class="field__error" id="xxx-error">` sudah punya id, tapi `<input>`-nya
 * TIDAK PERNAH mereferensikannya lewat aria-describedby pada render server
 * pertama setelah validasi gagal -- assets/js/validation.js hanya
 * menambahkannya saat pengguna BERINTERAKSI (blur/submit) dengan field itu,
 * bukan saat render awal, jadi pembaca layar yang men-Tab ke field yang SUDAH
 * ditandai error oleh server tidak mendengar apa pun. Dipakai lewat
 * `<?= field_aria($errors, $fn) ?>` di dalam tag input/select/textarea,
 * bergantung pada KONVENSI id error = "{field}-error" yang sudah konsisten di
 * semua form proyek ini.
 */
function field_aria(array $errors, string $field): string
{
    if (!isset($errors[$field]) || $errors[$field] === []) {
        return '';
    }

    return ' aria-invalid="true" aria-describedby="' . e($field) . '-error"';
}

/**
 * Nilai input SEBELUMNYA (dari $_POST request yang gagal validasi dan
 * langsung di-render ulang PADA REQUEST YANG SAMA -- lihat login.php, pola
 * yang sudah dipakai proyek ini sejak T-06) untuk mengisi ulang form, atau
 * $default bila belum ada submit / field tidak ada di POST. TIDAK memakai
 * session: form yang gagal validasi di proyek ini selalu dirender ulang
 * langsung, tidak lewat redirect, jadi $_POST request saat ini sudah cukup.
 */
function old(string $field, string $default = ''): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !array_key_exists($field, $_POST)) {
        return $default;
    }

    $value = $_POST[$field];

    // Edge case T-07: field[]=... tak terduga -- jangan loloskan array ke
    // atribut value="" (akan memicu error e() mengharap string).
    return is_array($value) ? $default : (string) $value;
}

/**
 * Validator koordinat (PRD bagian 7 & 11). Rentang WGS84 (-90..90/-180..180)
 * adalah ERROR keras (konsisten dengan CHECK constraint database/schema.sql).
 * Di luar bounding box Kota Sorong (config/config.php, SORONG_LAT/LNG_*) HANYA
 * peringatan (warning) -- TIDAK memblokir simpan, sesuai spesifikasi T-07
 * (titik tepi batas kota atau galat GPS kecil tetap harus bisa disimpan).
 *
 * @return array{errors: array<string, string[]>, warnings: array<string, string[]>, latitude: ?float, longitude: ?float}
 */
function validate_koordinat($lat_raw, $lng_raw): array
{
    $errors = [];
    $warnings = [];

    $lat_str = normalize_decimal(normalize_string($lat_raw === null ? null : (string) $lat_raw) ?? '');
    $lng_str = normalize_decimal(normalize_string($lng_raw === null ? null : (string) $lng_raw) ?? '');

    if ($lat_str === '' || !is_numeric($lat_str)) {
        $errors['latitude'][] = 'Latitude wajib berupa angka.';
    }
    if ($lng_str === '' || !is_numeric($lng_str)) {
        $errors['longitude'][] = 'Longitude wajib berupa angka.';
    }
    if ($errors !== []) {
        return ['errors' => $errors, 'warnings' => $warnings, 'latitude' => null, 'longitude' => null];
    }

    $lat = (float) $lat_str;
    $lng = (float) $lng_str;

    if ($lat < -90 || $lat > 90) {
        $errors['latitude'][] = 'Latitude harus antara -90 dan 90.';
    }
    if ($lng < -180 || $lng > 180) {
        $errors['longitude'][] = 'Longitude harus antara -180 dan 180.';
    }

    if ($errors === [] && ($lat < SORONG_LAT_MIN || $lat > SORONG_LAT_MAX || $lng < SORONG_LNG_MIN || $lng > SORONG_LNG_MAX)) {
        $warnings['koordinat'][] = 'Koordinat di luar perkiraan wilayah Kota Sorong — periksa kembali sebelum menyimpan.';
    }

    return [
        'errors' => $errors,
        'warnings' => $warnings,
        'latitude' => $errors === [] ? $lat : null,
        'longitude' => $errors === [] ? $lng : null,
    ];
}

/** Produk silang 2D (A->B) x (A->C); tanda menentukan arah belok, dipakai uji potong segmen. */
function cross_sign(array $a, array $b, array $c): float
{
    return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
}

/**
 * True bila segmen p1-p2 BENAR-BENAR memotong p3-p4 (persilangan tegas, bukan
 * cuma bersentuhan di satu titik ujung/kolinear) -- uji orientasi standar.
 */
function segments_intersect(array $p1, array $p2, array $p3, array $p4): bool
{
    $d1 = cross_sign($p3, $p4, $p1);
    $d2 = cross_sign($p3, $p4, $p2);
    $d3 = cross_sign($p1, $p2, $p3);
    $d4 = cross_sign($p1, $p2, $p4);

    return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
        && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
}

/**
 * Deteksi DASAR self-intersection pada satu cincin tertutup (edge case T-07) --
 * cek semua pasangan sisi yang TIDAK bertetangga. Cukup untuk kasus umum sisi
 * saling bersilangan; bukan pustaka geometri lengkap (lihat Risiko tersisa).
 */
function ring_has_self_intersection(array $ring): bool
{
    $n = count($ring) - 1; // titik terakhir == titik pertama (cincin tertutup) -> n = jumlah sisi

    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $jarak = abs($i - $j);
            if ($jarak <= 1 || $jarak === $n - 1) {
                continue; // sisi bertetangga wajar berbagi satu titik ujung
            }
            if (segments_intersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Validator poligon area tambang (FR-09, PRD bagian 7 & 11). Menerima baik
 * {"type":"Feature","geometry":{"type":"Polygon",...}} MAUPUN geometry
 * Polygon telanjang {"type":"Polygon","coordinates":[...]} -- keduanya
 * "struktur Feature/Polygon" yang valid menurut spesifikasi GeoJSON, dan
 * Leaflet.draw (T-10) bisa menghasilkan salah satunya tergantung konfigurasi.
 * Kosong (poligon opsional, FR-09) BUKAN error.
 *
 * @return array{errors: array<string, string[]>, warnings: array<string, string[]>, geojson: ?array}
 */
function validate_geojson_polygon(?string $raw): array
{
    $kosong = ['errors' => [], 'warnings' => [], 'geojson' => null];

    if ($raw === null || trim($raw) === '') {
        return $kosong;
    }

    if (strlen($raw) > GEOJSON_POLYGON_MAX_BYTES) {
        return ['errors' => ['area_geojson' => ['Ukuran data poligon melebihi 200 KB.']], 'warnings' => [], 'geojson' => null];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['errors' => ['area_geojson' => ['Format GeoJSON tidak valid (gagal diuraikan sebagai JSON).']], 'warnings' => [], 'geojson' => null];
    }

    $geometry = ($decoded['type'] ?? null) === 'Feature' ? ($decoded['geometry'] ?? null) : $decoded;
    if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'Polygon') {
        return ['errors' => ['area_geojson' => ['GeoJSON harus berupa Feature atau geometry bertipe Polygon.']], 'warnings' => [], 'geojson' => null];
    }

    $rings = $geometry['coordinates'] ?? null;
    if (!is_array($rings) || count($rings) === 0 || !is_array($rings[0] ?? null)) {
        return ['errors' => ['area_geojson' => ['Polygon tidak memiliki koordinat yang valid.']], 'warnings' => [], 'geojson' => null];
    }

    $errors = [];
    if (count($rings) > 1) {
        $errors['area_geojson'][] = 'Poligon dengan lubang (lebih dari satu cincin) tidak didukung.';
    }

    $ring = $rings[0];
    if (count($ring) < 4) {
        $errors['area_geojson'][] = 'Cincin poligon minimal harus memiliki 4 titik (termasuk titik penutup).';

        return ['errors' => $errors, 'warnings' => [], 'geojson' => null];
    }

    foreach ($ring as $i => $posisi) {
        if (!is_array($posisi) || count($posisi) < 2 || !is_numeric($posisi[0]) || !is_numeric($posisi[1])) {
            $errors['area_geojson'][] = "Titik ke-{$i} pada poligon harus berupa [lng, lat] numerik.";

            return ['errors' => $errors, 'warnings' => [], 'geojson' => null];
        }

        // Urutan GeoJSON WAJIB [lng, lat] (kebalikan konvensi umum "lat, lng").
        $lng = (float) $posisi[0];
        $lat = (float) $posisi[1];
        if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
            $errors['area_geojson'][] = "Titik ke-{$i} di luar rentang koordinat valid ([lng,lat] -180..180, -90..90).";

            return ['errors' => $errors, 'warnings' => [], 'geojson' => null];
        }
    }

    $pertama = $ring[0];
    $terakhir = $ring[count($ring) - 1];
    if ((float) $pertama[0] !== (float) $terakhir[0] || (float) $pertama[1] !== (float) $terakhir[1]) {
        $errors['area_geojson'][] = 'Cincin poligon harus tertutup (titik pertama dan terakhir harus sama).';

        return ['errors' => $errors, 'warnings' => [], 'geojson' => null];
    }

    if (ring_has_self_intersection($ring)) {
        $errors['area_geojson'][] = 'Poligon memiliki sisi yang saling berpotongan (self-intersection) dan tidak valid.';
    }

    if ($errors !== []) {
        return ['errors' => $errors, 'warnings' => [], 'geojson' => null];
    }

    return ['errors' => [], 'warnings' => [], 'geojson' => $decoded];
}
