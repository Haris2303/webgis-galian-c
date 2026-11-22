<?php

declare(strict_types=1);

/**
 * tests/smoke.php — uji otomatis black box end-to-end (T-27; skripsi Tabel
 * 3.2; PRD bagian 12). CLI murni, TANPA PHPUnit/Composer (PRD bagian 3: tanpa
 * dependensi tambahan) — HTTP sungguhan lewat ekstensi curl bawaan PHP,
 * cookie jar per KASUS (bukan satu sesi global) supaya kasus benar-benar
 * mandiri (edge case wajib T-27).
 *
 * Jalankan: php tests/smoke.php [base_url]
 * Default base_url: http://webgis-galian-c.test (ganti lewat argumen bila
 * domain dev Anda berbeda, mis. php tests/smoke.php http://localhost/webgis-galian-c).
 *
 * WAJIB dijalankan hanya lewat CLI, hanya saat APP_ENV=dev (skrip ini
 * MERESET TOTAL database ke seed sebelum jalan — edge case wajib "tidak
 * boleh menghapus data produksi" ditegakkan lewat DUA gerbang di bawah,
 * bukan diasumsikan dari konteks pemanggilan).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: jalankan hanya lewat command line (php tests/smoke.php).');
}

define('APP_BOOTSTRAPPED', true); // includes/bootstrap.php, di-require belakangan, sudah menjaga diri sendiri terhadap define() ulang
require_once __DIR__ . '/../config/config.php';

if (APP_ENV !== 'dev') {
    fwrite(STDERR, "Ditolak: APP_ENV harus 'dev' (sekarang: '" . APP_ENV . "'). Skrip ini mereset total database -- tidak boleh berjalan di produksi.\n");
    exit(1);
}

$BASE_URL = rtrim($argv[1] ?? 'http://webgis-galian-c.test', '/');

// -----------------------------------------------------------------------------
// 1. Reset database ke seed. Koneksi PDO TERPISAH dari config/database.php
//    (bukan db() aplikasi) -- schema.sql men-DROP+CREATE seluruh database,
//    butuh koneksi tanpa dbname terikat dan MYSQL_ATTR_MULTI_STATEMENTS
//    (dieksekusi sebagai satu exec() per berkas, bukan statement-per-statement),
//    yang sengaja TIDAK diaktifkan di koneksi utama aplikasi (lebih kecil
//    permukaan serangan di luar skrip ini).
// -----------------------------------------------------------------------------
function reset_database(): void
{
    echo "Mereset database ke seed...\n";
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
    $pdo->exec((string) file_get_contents(__DIR__ . '/../database/seed.sql'));
    echo "Database direset.\n\n";
}

require_once __DIR__ . '/../includes/bootstrap.php'; // DB() aplikasi baru connect lazy -- aman dipanggil SETELAH reset_database() di atas

reset_database();

// -----------------------------------------------------------------------------
// 2. Klien HTTP minimal (curl bawaan PHP). FOLLOWLOCATION SENGAJA mati --
//    kasus uji sering perlu memeriksa status 302/419/403 itu sendiri DAN
//    Location-nya, bukan hasil akhir setelah diikuti otomatis.
// -----------------------------------------------------------------------------
function req(string $method, string $url, ?string $jar, ?array $fields = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
    ]);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($fields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }
    $mentah = curl_exec($ch);
    if ($mentah === false) {
        $pesan = curl_error($ch);
        curl_close($ch);
        throw new SmokeGagal("Permintaan HTTP ke {$url} gagal: {$pesan}");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ukuran_header = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status' => $status,
        'headers' => substr($mentah, 0, $ukuran_header),
        'body' => substr($mentah, $ukuran_header),
    ];
}

function new_jar(): string
{
    $jar = tempnam(sys_get_temp_dir(), 'smoke_jar_');
    if ($jar === false) {
        throw new SmokeGagal('Gagal membuat cookie jar sementara.');
    }

    return $jar;
}

/** Ikuti header Location dari respons sebelumnya (GET), pakai jar yang sama -- dipakai memeriksa flash message/halaman tujuan setelah redirect. */
function ikuti(array $resp, string $jar): array
{
    if (!preg_match('/^Location:\s*(.+?)\r?$/mi', $resp['headers'], $m)) {
        throw new SmokeGagal('Respons tidak punya header Location untuk diikuti.');
    }

    return req('GET', trim($m[1]), $jar);
}

function ambil_csrf(string $html): string
{
    if (!preg_match('/name="csrf_token"\s+value="([^"]*)"/', $html, $m)) {
        throw new SmokeGagal('csrf_token tidak ditemukan di halaman (apakah halaman berubah bentuk?).');
    }

    return html_entity_decode($m[1], ENT_QUOTES);
}

function ambil_form_token(string $html): ?string
{
    if (preg_match('/name="_form_token"\s+value="([^"]*)"/', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES);
    }

    return null;
}

/** Login sungguhan lewat HTTP (GET ambil csrf, POST kredensial), kembalikan jar + respons POST (BELUM diikuti redirect-nya). */
function login(string $username, string $password): array
{
    $jar = new_jar();
    $halaman = req('GET', "$GLOBALS[BASE_URL]/login.php", $jar);
    $csrf = ambil_csrf($halaman['body']);
    $resp = req('POST', "$GLOBALS[BASE_URL]/login.php", $jar, [
        'csrf_token' => $csrf,
        'username' => $username,
        'password' => $password,
    ]);

    return ['jar' => $jar, 'resp' => $resp];
}

// -----------------------------------------------------------------------------
// 3. Assertion + pencatat hasil. Satu exception per kasus (bukan per
//    assertion) -- cocok dengan format laporan "1 baris = 1 kasus", dan
//    kegagalan satu kasus tidak menghentikan kasus lain (ditangkap di kasus()).
// -----------------------------------------------------------------------------
class SmokeGagal extends Exception
{
}

function assert_status(int $harap, int $aktual, string $konteks): void
{
    if ($harap !== $aktual) {
        throw new SmokeGagal("{$konteks}: berharap status {$harap}, dapat {$aktual}.");
    }
}

function assert_true(bool $kondisi, string $pesan): void
{
    if (!$kondisi) {
        throw new SmokeGagal($pesan);
    }
}

function assert_contains(string $haystack, string $needle, string $pesan): void
{
    if (!str_contains($haystack, $needle)) {
        throw new SmokeGagal("{$pesan} (potongan dicari: " . substr($needle, 0, 80) . ')');
    }
}

function assert_not_contains(string $haystack, string $needle, string $pesan): void
{
    if (str_contains($haystack, $needle)) {
        throw new SmokeGagal("{$pesan} (potongan yang SEHARUSNYA tidak ada: " . substr($needle, 0, 80) . ')');
    }
}

/** @var array<int, array{id:string, skenario:string, fr:string, harapan:string, lulus:bool, aktual:string}> */
$HASIL = [];
$GAGAL_TOTAL = 0;

function kasus(string $id, string $skenario, string $fr, string $harapan, callable $fn): void
{
    global $HASIL, $GAGAL_TOTAL;

    try {
        $fn();
        $HASIL[] = ['id' => $id, 'skenario' => $skenario, 'fr' => $fr, 'harapan' => $harapan, 'lulus' => true, 'aktual' => 'Sesuai harapan.'];
        echo "[PASS] {$id} — {$skenario}\n";
    } catch (SmokeGagal $e) {
        $GAGAL_TOTAL++;
        $HASIL[] = ['id' => $id, 'skenario' => $skenario, 'fr' => $fr, 'harapan' => $harapan, 'lulus' => false, 'aktual' => $e->getMessage()];
        echo "[FAIL] {$id} — {$skenario}: {$e->getMessage()}\n";
    } catch (Throwable $e) {
        $GAGAL_TOTAL++;
        $pesan = 'Error tak terduga: ' . $e->getMessage();
        $HASIL[] = ['id' => $id, 'skenario' => $skenario, 'fr' => $fr, 'harapan' => $harapan, 'lulus' => false, 'aktual' => $pesan];
        echo "[FAIL] {$id} — {$skenario}: {$pesan}\n";
    }
}

// -----------------------------------------------------------------------------
// 4. Helper data seed (cari lewat NAMA, bukan ID literal -- robust terhadap
//    urutan AUTO_INCREMENT, konsisten dengan prinsip seed.sql sendiri).
// -----------------------------------------------------------------------------
function id_lokasi(string $nama): int
{
    $row = db_one('SELECT id FROM lokasi_tambang WHERE nama_lokasi = :n', ['n' => $nama]);
    assert_true($row !== null, "Lokasi seed '{$nama}' tidak ditemukan -- reset database gagal?");

    return (int) $row['id'];
}

function id_material(string $nama): int
{
    $row = db_one('SELECT id FROM jenis_material WHERE nama = :n', ['n' => $nama]);
    assert_true($row !== null, "Material seed '{$nama}' tidak ditemukan.");

    return (int) $row['id'];
}

function id_user(string $username): int
{
    $row = db_one('SELECT id FROM users WHERE username = :u', ['u' => $username]);
    assert_true($row !== null, "User seed '{$username}' tidak ditemukan.");

    return (int) $row['id'];
}

function kontraktor_id_milik(string $username): int
{
    $row = db_one('SELECT kontraktor_id FROM users WHERE username = :u', ['u' => $username]);
    assert_true($row !== null && $row['kontraktor_id'] !== null, "User '{$username}' tidak punya kontraktor_id.");

    return (int) $row['kontraktor_id'];
}

/** Aktivitas pertama milik lokasi tertentu (dipakai kasus update & IDOR). */
function id_aktivitas_pertama(string $nama_lokasi): int
{
    $row = db_one(
        'SELECT a.id FROM aktivitas_tambang a JOIN lokasi_tambang l ON l.id = a.lokasi_id WHERE l.nama_lokasi = :n ORDER BY a.id ASC LIMIT 1',
        ['n' => $nama_lokasi]
    );
    assert_true($row !== null, "Tidak ada aktivitas seed untuk lokasi '{$nama_lokasi}'.");

    return (int) $row['id'];
}

// =============================================================================
// 5. SEBELAS KASUS POSITIF (PRD bagian 12 / skripsi Tabel 3.2)
// =============================================================================

kasus('T-1', 'Login Admin', 'FR-01', 'Kredensial valid -> redirect ke dashboard.php', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    assert_status(302, $r['resp']['status'], 'POST login admin');
    assert_contains($r['resp']['headers'], 'dashboard.php', 'Redirect setelah login admin');
    $dash = ikuti($r['resp'], $r['jar']);
    assert_status(200, $dash['status'], 'GET dashboard.php setelah login');
    unlink($r['jar']);
});

kasus('T-2', 'Kelola Data Lokasi Tambang', 'FR-07, FR-08', 'Input koordinat & data lokasi baru -> tersimpan di MySQL dan tampil di daftar', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $form = req('GET', "$BASE_URL/lokasi/form.php", $r['jar']);
    assert_status(200, $form['status'], 'GET lokasi/form.php');
    $csrf = ambil_csrf($form['body']);
    $ftoken = ambil_form_token($form['body']);
    $nama = 'SMOKE_T27_Lokasi_' . bin2hex(random_bytes(4));

    $post = req('POST', "$BASE_URL/lokasi/form.php", $r['jar'], [
        'csrf_token' => $csrf,
        '_form_token' => (string) $ftoken,
        'nama_lokasi' => $nama,
        'kontraktor_id' => (string) kontraktor_id_milik('kontraktor1'),
        'material_id' => (string) id_material('Pasir'),
        'status_izin' => 'proses',
        'latitude' => '-0.88',
        'longitude' => '131.25',
        'luas_ha' => '1.5',
    ]);
    assert_status(302, $post['status'], 'POST lokasi/form.php (tambah)');

    $row = db_one('SELECT id, status_izin FROM lokasi_tambang WHERE nama_lokasi = :n', ['n' => $nama]);
    assert_true($row !== null, 'Lokasi baru tidak ditemukan di database setelah submit.');
    assert_true($row['status_izin'] === 'proses', 'status_izin tersimpan tidak sesuai.');

    $daftar = req('GET', "$BASE_URL/lokasi/index.php?q=" . urlencode($nama), $r['jar']);
    assert_contains($daftar['body'], htmlspecialchars($nama, ENT_QUOTES), 'Lokasi baru tidak tampil di daftar lokasi.');

    db_exec('DELETE FROM lokasi_tambang WHERE id = :id', ['id' => $row['id']]);
    unlink($r['jar']);
});

kasus('T-3', 'Kelola Data Aktivitas', 'FR-13', 'Tambah aktivitas (kontraktor, material, status) -> tersimpan dan terhubung ke lokasi yang sesuai', function () use ($BASE_URL) {
    $r = login('kontraktor1', 'kontraktor123');
    $lokasi_id = id_lokasi('Lokasi Klasaman');
    $form = req('GET', "$BASE_URL/aktivitas/form.php", $r['jar']);
    assert_status(200, $form['status'], 'GET aktivitas/form.php (kontraktor)');
    $csrf = ambil_csrf($form['body']);
    $ftoken = ambil_form_token($form['body']);
    $marker = 'SMOKE_T27_Aktivitas_' . bin2hex(random_bytes(4));

    $post = req('POST', "$BASE_URL/aktivitas/form.php", $r['jar'], [
        'csrf_token' => $csrf,
        '_form_token' => (string) $ftoken,
        'lokasi_id' => (string) $lokasi_id,
        'tanggal_aktivitas' => date('Y-m-d'),
        'jenis_kegiatan' => $marker,
        'material_id' => (string) id_material('Pasir'),
        'status_aktivitas' => 'aktif',
        'jumlah_alat_berat' => '1',
        'jumlah_pekerja' => '2',
        'deskripsi' => 'Dibuat oleh tests/smoke.php',
    ]);
    assert_status(302, $post['status'], 'POST aktivitas/form.php (tambah)');

    $row = db_one('SELECT id, lokasi_id FROM aktivitas_tambang WHERE jenis_kegiatan = :j', ['j' => $marker]);
    assert_true($row !== null, 'Aktivitas baru tidak ditemukan di database.');
    assert_true((int) $row['lokasi_id'] === $lokasi_id, 'Aktivitas tersimpan dengan lokasi_id yang salah.');

    db_exec('DELETE FROM aktivitas_tambang WHERE id = :id', ['id' => $row['id']]);
    unlink($r['jar']);
});

kasus('T-4', 'Visualisasi Peta', 'FR-17, FR-18', 'Buka peta -> Leaflet tampil dengan data persebaran lokasi (GeoJSON)', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $peta = req('GET', "$BASE_URL/peta.php", $r['jar']);
    assert_status(200, $peta['status'], 'GET peta.php');
    assert_contains($peta['body'], 'id="peta-mapa"', 'Kontainer peta tidak ditemukan di peta.php.');

    $geo = req('GET', "$BASE_URL/api/lokasi.php", $r['jar']);
    assert_status(200, $geo['status'], 'GET api/lokasi.php');
    $data = json_decode($geo['body'], true);
    assert_true(is_array($data) && ($data['type'] ?? null) === 'FeatureCollection', 'api/lokasi.php bukan FeatureCollection GeoJSON yang valid.');
    assert_true(count($data['features'] ?? []) > 0, 'api/lokasi.php tidak mengembalikan satu pun fitur (seed semestinya punya 12 lokasi).');
    unlink($r['jar']);
});

kasus('T-5', 'Pencarian Lokasi', 'FR-20', 'Masukkan nama lokasi di pencarian -> lokasi tampil beserta atributnya', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $hasil = req('GET', "$BASE_URL/api/cari.php?q=Klasaman", $r['jar']);
    assert_status(200, $hasil['status'], 'GET api/cari.php?q=Klasaman');
    $data = json_decode($hasil['body'], true);
    assert_true(is_array($data) && $data !== [], 'Pencarian "Klasaman" tidak mengembalikan hasil apa pun.');
    $cocok = array_filter($data, static fn ($item) => str_contains((string) ($item['nama_lokasi'] ?? ''), 'Klasaman'));
    assert_true($cocok !== [], 'Tidak ada hasil pencarian yang namanya memuat "Klasaman".');
    unlink($r['jar']);
});

kasus('T-6', 'Monitoring Aktivitas', 'FR-21', 'Buka halaman monitoring -> aktivitas tampil sesuai data terbaru', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $mon = req('GET', "$BASE_URL/monitoring.php", $r['jar']);
    assert_status(200, $mon['status'], 'GET monitoring.php');
    // Seed selalu memberi "Lokasi Klasaman" aktivitas tertanggal HARI INI
    // (DATE_SUB(CURDATE(), INTERVAL 0 DAY), database/seed.sql) -- jendela
    // default monitoring.php (30 hari) WAJIB memuatnya, deterministik
    // berapa pun tanggal skrip ini dijalankan.
    assert_contains($mon['body'], 'Lokasi Klasaman', 'Monitoring tidak menampilkan aktivitas terbaru "Lokasi Klasaman" (seed, tanggal hari ini).');
    unlink($r['jar']);
});

kasus('T-7', 'Detail Lokasi', 'FR-19', 'Klik marker (lewat endpoint detail) -> detail lokasi, material, koordinat, aktivitas tampil', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $id = id_lokasi('Lokasi Klasaman');
    $detail = req('GET', "$BASE_URL/api/lokasi_detail.php?id={$id}", $r['jar']);
    assert_status(200, $detail['status'], 'GET api/lokasi_detail.php');
    $data = json_decode($detail['body'], true);
    assert_true(($data['nama_lokasi'] ?? null) === 'Lokasi Klasaman', 'Nama lokasi pada detail tidak sesuai.');
    assert_true(($data['material'] ?? null) === 'Pasir', 'Material pada detail tidak sesuai.');
    assert_true(isset($data['koordinat']['lat'], $data['koordinat']['lng']), 'Koordinat tidak ada pada detail.');
    assert_true(($data['aktivitas_dibatasi'] ?? true) === false, 'Admin seharusnya TIDAK dibatasi melihat riwayat aktivitas.');
    assert_true(count($data['aktivitas_terakhir'] ?? []) > 0, 'Tidak ada riwayat aktivitas pada detail (seed semestinya punya beberapa).');
    unlink($r['jar']);
});

kasus('T-8', 'Update Aktivitas', 'FR-15', 'Ubah aktivitas tersimpan -> perubahan langsung tersimpan di database', function () use ($BASE_URL) {
    $r = login('kontraktor1', 'kontraktor123');
    $lokasi_id = id_lokasi('Lokasi Klasaman');
    $aktivitas_id = repo_aktivitas_create([
        'lokasi_id' => $lokasi_id,
        'user_id' => id_user('kontraktor1'),
        'tanggal_aktivitas' => date('Y-m-d'),
        'jenis_kegiatan' => 'SMOKE_T27_SebelumUbah',
        'material_id' => id_material('Pasir'),
        'status_aktivitas' => 'aktif',
    ]);

    $form = req('GET', "$BASE_URL/aktivitas/form.php?id={$aktivitas_id}", $r['jar']);
    assert_status(200, $form['status'], 'GET aktivitas/form.php?id=... (ubah)');
    $csrf = ambil_csrf($form['body']);
    $ftoken = ambil_form_token($form['body']);
    $marker_baru = 'SMOKE_T27_SesudahUbah_' . bin2hex(random_bytes(4));

    $post = req('POST', "$BASE_URL/aktivitas/form.php?id={$aktivitas_id}", $r['jar'], [
        'csrf_token' => $csrf,
        '_form_token' => (string) $ftoken,
        'lokasi_id' => (string) $lokasi_id,
        'tanggal_aktivitas' => date('Y-m-d'),
        'jenis_kegiatan' => $marker_baru,
        'material_id' => (string) id_material('Pasir'),
        'status_aktivitas' => 'selesai',
        'jumlah_alat_berat' => '0',
        'jumlah_pekerja' => '0',
        'deskripsi' => 'Diubah oleh tests/smoke.php',
    ]);
    assert_status(302, $post['status'], 'POST aktivitas/form.php?id=... (ubah)');

    $row = db_one('SELECT jenis_kegiatan, status_aktivitas FROM aktivitas_tambang WHERE id = :id', ['id' => $aktivitas_id]);
    assert_true($row !== null, 'Aktivitas hilang setelah diubah (seharusnya tetap ada).');
    assert_true($row['jenis_kegiatan'] === $marker_baru, 'jenis_kegiatan tidak berubah setelah update.');
    assert_true($row['status_aktivitas'] === 'selesai', 'status_aktivitas tidak berubah setelah update.');

    db_exec('DELETE FROM aktivitas_tambang WHERE id = :id', ['id' => $aktivitas_id]);
    unlink($r['jar']);
});

kasus('T-9', 'Hapus Data Tambang', 'FR-10', 'Pilih data lalu klik hapus -> hilang dari database', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $nama = 'SMOKE_T27_UntukDihapus_' . bin2hex(random_bytes(4));
    $lokasi_id = repo_lokasi_create([
        'nama_lokasi' => $nama,
        'kontraktor_id' => kontraktor_id_milik('kontraktor1'),
        'material_id' => id_material('Pasir'),
        'latitude' => '-0.88',
        'longitude' => '131.25',
        'status_izin' => 'proses',
    ]);

    // Token CSRF per-sesi (bukan per-halaman) -- cukup ambil dari halaman
    // manapun yang sudah login, konsisten dengan desain csrf_token() (T-05).
    $halaman = req('GET', "$BASE_URL/lokasi/index.php", $r['jar']);
    $csrf = ambil_csrf($halaman['body']);

    $hapus = req('POST', "$BASE_URL/lokasi/hapus.php", $r['jar'], [
        'csrf_token' => $csrf,
        'id' => (string) $lokasi_id,
    ]);
    assert_status(302, $hapus['status'], 'POST lokasi/hapus.php');

    $row = db_one('SELECT id FROM lokasi_tambang WHERE id = :id', ['id' => $lokasi_id]);
    assert_true($row === null, 'Lokasi masih ada di database setelah dihapus.');
    unlink($r['jar']);
});

kasus('T-10', 'Cetak Laporan', 'FR-22', 'Klik cetak laporan -> laporan siap cetak/unduh', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $laporan = req('GET', "$BASE_URL/laporan/index.php", $r['jar']);
    assert_status(200, $laporan['status'], 'GET laporan/index.php');
    assert_contains($laporan['body'], 'Laporan Monitoring Galian C', 'Kop laporan tidak ditemukan.');

    $csv = req('GET', "$BASE_URL/laporan/unduh.php", $r['jar']);
    assert_status(200, $csv['status'], 'GET laporan/unduh.php');
    assert_contains($csv['headers'], 'text/csv', 'laporan/unduh.php tidak mengirim Content-Type CSV.');
    assert_contains($csv['body'], 'Nama Lokasi', 'Header kolom CSV tidak ditemukan.');
    unlink($r['jar']);
});

kasus('T-11', 'Logout', 'FR-02', 'Klik logout -> sesi dihancurkan, kembali ke login; halaman terproteksi tidak lagi terbuka', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $halaman = req('GET', "$BASE_URL/dashboard.php", $r['jar']);
    $csrf = ambil_csrf($halaman['body']);

    $logout = req('POST', "$BASE_URL/logout.php", $r['jar'], ['csrf_token' => $csrf]);
    assert_status(302, $logout['status'], 'POST logout.php');
    assert_contains($logout['headers'], 'login.php', 'Logout tidak mengarahkan ke login.php.');

    // Tombol Back TIDAK boleh membuka halaman terproteksi (PRD FR-02) --
    // jar yang SAMA (sesi sudah dihancurkan) mencoba dashboard.php lagi.
    $coba_lagi = req('GET', "$BASE_URL/dashboard.php", $r['jar']);
    assert_status(302, $coba_lagi['status'], 'GET dashboard.php dengan sesi yang sudah logout');
    assert_contains($coba_lagi['headers'], 'login.php', 'Setelah logout, dashboard.php tidak mengarahkan ke login.php.');
    unlink($r['jar']);
});

// =============================================================================
// 6. KASUS NEGATIF (spesifikasi T-27 + PRD bagian 12 "kasus negatif tambahan")
// =============================================================================

kasus('N-1', 'Login salah', 'FR-01', 'Kredensial tidak valid -> kembali ke login dengan pesan netral, TIDAK redirect', function () use ($BASE_URL) {
    $jar = new_jar();
    $halaman = req('GET', "$BASE_URL/login.php", $jar);
    $csrf = ambil_csrf($halaman['body']);
    $resp = req('POST', "$BASE_URL/login.php", $jar, ['csrf_token' => $csrf, 'username' => 'admin', 'password' => 'password-salah']);
    assert_status(200, $resp['status'], 'POST login.php dengan password salah');
    assert_contains($resp['body'], 'Username atau password salah', 'Pesan error login salah tidak tampil.');
    unlink($jar);
});

kasus('N-2', 'Throttling login', 'PRD bagian 11', 'Lebih dari 5 percobaan gagal (15 menit) -> pesan berubah jadi "terlalu banyak percobaan"', function () use ($BASE_URL) {
    // Username KHAYALAN khusus kasus ini (tidak dipakai kasus lain) supaya
    // penghitung percobaan gagalnya tidak tercampur kasus lain (edge case
    // wajib T-27: kasus harus mandiri).
    $jar = new_jar();
    $username = 'smoke_throttle_' . bin2hex(random_bytes(3));
    $pesan_terakhir = '';
    for ($i = 1; $i <= 6; $i++) {
        $halaman = req('GET', "$BASE_URL/login.php", $jar);
        $csrf = ambil_csrf($halaman['body']);
        $resp = req('POST', "$BASE_URL/login.php", $jar, ['csrf_token' => $csrf, 'username' => $username, 'password' => 'salah']);
        assert_status(200, $resp['status'], "POST login.php percobaan ke-{$i}");
        $pesan_terakhir = $resp['body'];
    }
    assert_contains($pesan_terakhir, 'Terlalu banyak percobaan gagal', 'Percobaan ke-6 seharusnya sudah terkena throttle.');
    unlink($jar);
});

kasus('N-3', 'POST tanpa token CSRF', 'PRD bagian 11', 'POST tanpa csrf_token -> 419, bukan diproses', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $resp = req('POST', "$BASE_URL/lokasi/hapus.php", $r['jar'], ['id' => '999999']); // tanpa csrf_token sama sekali
    assert_status(419, $resp['status'], 'POST lokasi/hapus.php tanpa csrf_token');
    unlink($r['jar']);
});

kasus('N-4', 'Kontraktor membuka URL admin', 'FR-03', 'Kontraktor mengakses halaman admin -> 403', function () use ($BASE_URL) {
    $r = login('kontraktor1', 'kontraktor123');
    $resp = req('GET', "$BASE_URL/lokasi/index.php", $r['jar']);
    assert_status(403, $resp['status'], 'Kontraktor GET lokasi/index.php');
    unlink($r['jar']);
});

kasus('N-5', 'IDOR aktivitas milik kontraktor lain', 'FR-15', 'Kontraktor membuka form ubah aktivitas kontraktor LAIN -> 403, bukan datanya ikut terbuka', function () use ($BASE_URL) {
    $r = login('kontraktor1', 'kontraktor123');
    // "Lokasi Malanu" milik PT Papua Mineral Sejahtera (kontraktor2), BUKAN kontraktor1.
    $aktivitas_id = id_aktivitas_pertama('Lokasi Malanu');
    $resp = req('GET', "$BASE_URL/aktivitas/form.php?id={$aktivitas_id}", $r['jar']);
    assert_status(403, $resp['status'], 'kontraktor1 GET aktivitas/form.php?id=<punya kontraktor2>');
    unlink($r['jar']);
});

kasus('N-6', 'Unggah berkas .php menyamar sebagai foto', 'FR-14', 'Berkas bukan JPG/PNG (konten, bukan ekstensi) -> ditolak server, aktivitas TIDAK tersimpan', function () use ($BASE_URL) {
    $r = login('kontraktor1', 'kontraktor123');
    $lokasi_id = id_lokasi('Lokasi Klasaman');
    $form = req('GET', "$BASE_URL/aktivitas/form.php", $r['jar']);
    $csrf = ambil_csrf($form['body']);
    $ftoken = ambil_form_token($form['body']);
    $marker = 'SMOKE_T27_HarusnyaTidakTersimpan_' . bin2hex(random_bytes(4));

    $tmp = tempnam(sys_get_temp_dir(), 'smoke_php_');
    file_put_contents($tmp, "<?php echo 'pwned'; ?>");

    $post = req('POST', "$BASE_URL/aktivitas/form.php", $r['jar'], [
        'csrf_token' => $csrf,
        '_form_token' => (string) $ftoken,
        'lokasi_id' => (string) $lokasi_id,
        'tanggal_aktivitas' => date('Y-m-d'),
        'jenis_kegiatan' => $marker,
        'material_id' => (string) id_material('Pasir'),
        'status_aktivitas' => 'aktif',
        'jumlah_alat_berat' => '0',
        'jumlah_pekerja' => '0',
        // Nama+MIME klaim dipalsukan jadi gambar -- server WAJIB menolak
        // berdasarkan KONTEN (finfo), bukan percaya nama/MIME dari klien.
        'foto[]' => new CURLFile($tmp, 'image/jpeg', 'evil.php'),
    ]);
    unlink($tmp);

    assert_status(200, $post['status'], 'POST aktivitas/form.php dengan berkas .php (seharusnya ditolak, bukan redirect)');
    assert_contains($post['body'], 'bukan berkas JPG/PNG yang valid', 'Pesan tolak unggah tidak tampil.');

    $row = db_one('SELECT id FROM aktivitas_tambang WHERE jenis_kegiatan = :j', ['j' => $marker]);
    assert_true($row === null, 'Aktivitas TETAP tersimpan walau foto ditolak (seharusnya seluruh transaksi batal).');
    unlink($r['jar']);
});

kasus('N-7', 'Koordinat tidak valid', 'FR-08', 'Latitude di luar -90..90 -> ditolak validator, tidak tersimpan', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $form = req('GET', "$BASE_URL/lokasi/form.php", $r['jar']);
    $csrf = ambil_csrf($form['body']);
    $ftoken = ambil_form_token($form['body']);
    $nama = 'SMOKE_T27_KoordinatSalah_' . bin2hex(random_bytes(4));

    $post = req('POST', "$BASE_URL/lokasi/form.php", $r['jar'], [
        'csrf_token' => $csrf,
        '_form_token' => (string) $ftoken,
        'nama_lokasi' => $nama,
        'kontraktor_id' => (string) kontraktor_id_milik('kontraktor1'),
        'material_id' => (string) id_material('Pasir'),
        'status_izin' => 'proses',
        'latitude' => '999',
        'longitude' => '131.25',
    ]);
    assert_status(200, $post['status'], 'POST lokasi/form.php dengan latitude=999 (seharusnya ditolak, bukan redirect)');
    assert_contains($post['body'], 'Latitude harus antara -90 dan 90', 'Pesan validasi koordinat tidak tampil.');

    $row = db_one('SELECT id FROM lokasi_tambang WHERE nama_lokasi = :n', ['n' => $nama]);
    assert_true($row === null, 'Lokasi dengan koordinat tidak valid TETAP tersimpan.');
    unlink($r['jar']);
});

kasus('N-8', 'Nama lokasi berisi payload XSS', 'PRD bagian 11', 'Nama lokasi berisi tag `<script>` -> tersimpan tapi tampil sebagai TEKS (ter-escape), tidak dieksekusi', function () use ($BASE_URL) {
    $r = login('admin', 'admin123');
    $form = req('GET', "$BASE_URL/lokasi/form.php", $r['jar']);
    $csrf = ambil_csrf($form['body']);
    $ftoken = ambil_form_token($form['body']);
    $payload = '<script>alert(1)</script>SMOKE_T27_XSS';

    $post = req('POST', "$BASE_URL/lokasi/form.php", $r['jar'], [
        'csrf_token' => $csrf,
        '_form_token' => (string) $ftoken,
        'nama_lokasi' => $payload,
        'kontraktor_id' => (string) kontraktor_id_milik('kontraktor1'),
        'material_id' => (string) id_material('Pasir'),
        'status_izin' => 'proses',
        'latitude' => '-0.88',
        'longitude' => '131.25',
    ]);
    assert_status(302, $post['status'], 'POST lokasi/form.php dengan nama berisi payload XSS');

    $daftar = req('GET', "$BASE_URL/lokasi/index.php?q=SMOKE_T27_XSS", $r['jar']);
    assert_not_contains($daftar['body'], '<script>alert(1)</script>', 'Payload XSS mentah muncul TANPA di-escape di daftar lokasi.');
    assert_contains($daftar['body'], '&lt;script&gt;', 'Payload XSS seharusnya tampil ter-escape (&lt;script&gt;).');

    db_exec("DELETE FROM lokasi_tambang WHERE nama_lokasi = :n", ['n' => $payload]);
    unlink($r['jar']);
});

// =============================================================================
// 7. Ringkasan + tests/report.md
// =============================================================================

$total = count($HASIL);
$lulus = $total - $GAGAL_TOTAL;
echo "\n=== Ringkasan: {$lulus}/{$total} kasus lulus ===\n";

$matriks_fr = [];
foreach ($HASIL as $h) {
    foreach (preg_split('/,\s*/', $h['fr']) as $fr) {
        $matriks_fr[$fr][] = $h['id'];
    }
}

$md = "# Laporan Uji Otomatis — tests/smoke.php (T-27)\n\n";
$md .= 'Dijalankan: ' . date('Y-m-d H:i:s') . " WIT · Base URL: `{$BASE_URL}` · Hasil: **{$lulus}/{$total} lulus**\n\n";
$md .= "## Hasil per kasus\n\n";
$md .= "| ID | Skenario | FR terkait | Hasil diharapkan | Hasil aktual | Status |\n";
$md .= "| --- | --- | --- | --- | --- | --- |\n";
foreach ($HASIL as $h) {
    $md .= sprintf(
        "| %s | %s | %s | %s | %s | %s |\n",
        $h['id'],
        str_replace('|', '\\|', $h['skenario']),
        $h['fr'],
        str_replace('|', '\\|', $h['harapan']),
        str_replace(["\n", '|'], [' ', '\\|'], $h['aktual']),
        $h['lulus'] ? 'PASS' : 'FAIL'
    );
}

$md .= "\n## Matriks keterlacakan FR -> kasus uji\n\n";
$md .= "| FR | Kasus yang menguji |\n| --- | --- |\n";
ksort($matriks_fr);
foreach ($matriks_fr as $fr => $ids) {
    $md .= "| {$fr} | " . implode(', ', $ids) . " |\n";
}
$md .= "\n*FR yang tidak muncul di tabel ini tidak diuji lewat `tests/smoke.php`"
    . " (mis. FR-04/05/06 dashboard, FR-09 poligon area, FR-11/12 kontraktor,"
    . " FR-16 hapus aktivitas) — lihat Risiko tersisa di laporan tugas T-27.*\n";

file_put_contents(__DIR__ . '/report.md', $md);
echo "Laporan ditulis ke tests/report.md\n";

exit($GAGAL_TOTAL > 0 ? 1 : 0);
