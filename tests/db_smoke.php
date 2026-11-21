<?php

declare(strict_types=1);

/**
 * tests/db_smoke.php — smoke test manual lapisan akses data (T-03).
 * Jalankan: php tests/db_smoke.php
 *
 * Semua baris yang dibuat skrip ini diberi prefiks SMOKE_/smoke_ dan dihapus
 * sendiri di bagian 2 (atau ikut rollback di bagian 3-4), jadi aman dijalankan
 * berulang kali di database dev tanpa menyisakan sampah permanen.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/repo/users.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';
require_once __DIR__ . '/../includes/repo/foto.php';

$failures = 0;
$total = 0;

function cek(string $label, bool $kondisi): void
{
    global $failures, $total;
    $total++;
    if ($kondisi) {
        echo "  [OK] {$label}\n";
    } else {
        $failures++;
        echo "  [GAGAL] {$label}\n";
    }
}

echo "=== 1. CRUD per repository ===\n";

$material = db_one("SELECT id FROM jenis_material WHERE nama = 'Pasir'");
cek('jenis_material "Pasir" tersedia (seed T-02)', $material !== null);
$material_id = (int) $material['id'];

// --- kontraktor ---
$kontraktor_id = repo_kontraktor_create([
    'nama_perusahaan' => 'SMOKE_CV Uji_Coba%Sorong',
    'penanggung_jawab' => 'Penguji Otomatis',
    'telepon' => '080000000000',
    'email' => 'smoke@example.test',
    'alamat' => 'Alamat uji',
]);
cek('kontraktor_create mengembalikan id baru', $kontraktor_id > 0);

$kontraktor = repo_kontraktor_find($kontraktor_id);
cek('kontraktor_find menemukan baris baru', $kontraktor !== null && $kontraktor['nama_perusahaan'] === 'SMOKE_CV Uji_Coba%Sorong');

repo_kontraktor_update($kontraktor_id, [
    'nama_perusahaan' => 'SMOKE_CV Uji_Coba%Sorong (diubah)',
    'penanggung_jawab' => 'Penguji Otomatis',
    'telepon' => '080000000001',
    'email' => 'smoke@example.test',
    'alamat' => 'Alamat uji diubah',
]);
$kontraktor_setelah_update = repo_kontraktor_find($kontraktor_id);
cek('kontraktor_update mengubah data', $kontraktor_setelah_update['telepon'] === '080000000001');

[$hasil_cari, $total_cari] = repo_kontraktor_list(1, 10, 'Uji_Coba%Sorong');
cek('kontraktor_list dengan search escape LIKE menemukan baris literal % dan _', $total_cari >= 1);

// --- users ---
$username_uji = 'smoke_user_' . bin2hex(random_bytes(4));
$user_id = repo_user_create([
    'username' => $username_uji,
    'password_hash' => password_hash('smoke-password', PASSWORD_BCRYPT),
    'nama_lengkap' => 'Pengguna Uji Otomatis',
    'role' => 'kontraktor',
    'kontraktor_id' => $kontraktor_id,
]);
cek('user_create mengembalikan id baru', $user_id > 0);
cek('user_find_by_username menemukan akun baru', repo_user_find_by_username($username_uji) !== null);

repo_user_update($user_id, [
    'nama_lengkap' => 'Pengguna Uji Otomatis (diubah)',
    'role' => 'kontraktor',
    'kontraktor_id' => $kontraktor_id,
]);
$user_setelah_update = repo_user_find($user_id);
cek('user_update mengubah nama_lengkap', $user_setelah_update['nama_lengkap'] === 'Pengguna Uji Otomatis (diubah)');

repo_user_touch_last_login($user_id);
$user_setelah_login = repo_user_find($user_id);
cek('user_touch_last_login mengisi last_login', $user_setelah_login['last_login'] !== null);

// --- lokasi ---
$lokasi_id = repo_lokasi_create([
    'nama_lokasi' => 'SMOKE_Lokasi Uji',
    'kontraktor_id' => $kontraktor_id,
    'material_id' => $material_id,
    'latitude' => '-0.8700000',
    'longitude' => '131.2500000',
    'status_izin' => 'proses',
    'created_by' => $user_id,
]);
cek('lokasi_create mengembalikan id baru', $lokasi_id > 0);

$lokasi = repo_lokasi_find($lokasi_id);
cek('lokasi_find menemukan baris baru', $lokasi !== null && $lokasi['latitude'] === '-0.8700000');

repo_lokasi_update($lokasi_id, [
    'nama_lokasi' => 'SMOKE_Lokasi Uji (diubah)',
    'kontraktor_id' => $kontraktor_id,
    'material_id' => $material_id,
    'latitude' => '-0.8700000',
    'longitude' => '131.2500000',
    'status_izin' => 'berizin',
]);
$lokasi_setelah_update = repo_lokasi_find($lokasi_id);
cek('lokasi_update mengubah status_izin', $lokasi_setelah_update['status_izin'] === 'berizin');

[$daftar_lokasi_aman] = repo_lokasi_list(['kontraktor_id' => $kontraktor_id], 1, 10, 'id; DROP TABLE users; --', 'asc');
cek('lokasi_list mengabaikan kolom sort tidak sah (whitelist aktif, tidak error)', count($daftar_lokasi_aman) >= 1);

$ringkasan_status = repo_lokasi_count_by_status();
cek('lokasi_count_by_status mengembalikan ketiga status', isset($ringkasan_status['berizin'], $ringkasan_status['tidak_berizin'], $ringkasan_status['proses']));

// --- aktivitas ---
$aktivitas_id = repo_aktivitas_create([
    'lokasi_id' => $lokasi_id,
    'user_id' => $user_id,
    'tanggal_aktivitas' => date('Y-m-d'),
    'jenis_kegiatan' => 'Uji Otomatis',
    'material_id' => $material_id,
    'status_aktivitas' => 'aktif',
    'jumlah_alat_berat' => 1,
    'jumlah_pekerja' => 2,
    'deskripsi' => 'Baris dari tests/db_smoke.php',
]);
cek('aktivitas_create mengembalikan id baru', $aktivitas_id > 0);

$aktivitas = repo_aktivitas_find($aktivitas_id);
cek('aktivitas_find menemukan baris baru', $aktivitas !== null);

repo_aktivitas_update($aktivitas_id, [
    'tanggal_aktivitas' => date('Y-m-d'),
    'jenis_kegiatan' => 'Uji Otomatis (diubah)',
    'material_id' => $material_id,
    'status_aktivitas' => 'selesai',
    'jumlah_alat_berat' => 1,
    'jumlah_pekerja' => 2,
]);
$aktivitas_setelah_update = repo_aktivitas_find($aktivitas_id);
cek('aktivitas_update mengubah status_aktivitas', $aktivitas_setelah_update['status_aktivitas'] === 'selesai');

$terbaru = repo_aktivitas_latest_by_lokasi($lokasi_id, 5);
cek('aktivitas_latest_by_lokasi menemukan aktivitas baru (idx_lokasi_tanggal)', count($terbaru) === 1 && $terbaru[0]['id'] === $aktivitas_id);

// --- foto ---
$foto_id = repo_foto_create([
    'aktivitas_id' => $aktivitas_id,
    'file_path' => 'uploads/aktivitas/smoke-test.jpg',
]);
cek('foto_create mengembalikan id baru', $foto_id > 0);
cek('foto_count_by_aktivitas menghitung 1 foto', repo_foto_count_by_aktivitas($aktivitas_id) === 1);
cek('foto_list_by_aktivitas menemukan foto baru', count(repo_foto_list_by_aktivitas($aktivitas_id)) === 1);

echo "\n=== 2. Pembersihan data uji (urutan sesuai FK) ===\n";
cek('foto_delete berhasil', repo_foto_delete($foto_id));
cek('aktivitas_delete berhasil', repo_aktivitas_delete($aktivitas_id));
cek('lokasi_delete berhasil', repo_lokasi_delete($lokasi_id));
cek('user_delete berhasil', repo_user_delete($user_id));
cek('kontraktor_delete berhasil', repo_kontraktor_delete($kontraktor_id));
cek('kontraktor_find tidak lagi menemukan baris yang sudah dihapus', repo_kontraktor_find($kontraktor_id) === null);

echo "\n=== 3. Transaksi yang sengaja gagal (harus rollback total) ===\n";

$nama_gagal = 'SMOKE_Transaksi_Gagal_' . bin2hex(random_bytes(4));
try {
    db_transaction(function () use ($nama_gagal) {
        repo_kontraktor_create([
            'nama_perusahaan' => $nama_gagal,
            'penanggung_jawab' => 'Harus Hilang',
        ]);
        throw new RuntimeException('sengaja gagal untuk uji rollback');
    });
    cek('db_transaction melempar ulang exception dari callback', false);
} catch (RuntimeException $e) {
    cek('db_transaction melempar ulang exception dari callback', $e->getMessage() === 'sengaja gagal untuk uji rollback');
}

$sisa = db_one('SELECT id FROM kontraktor WHERE nama_perusahaan = :n', ['n' => $nama_gagal]);
cek('rollback menghapus baris yang sempat dibuat dalam transaksi gagal', $sisa === null);

echo "\n=== 4. Transaksi bersarang (SAVEPOINT) ===\n";

$nama_luar = 'SMOKE_Nested_Luar_' . bin2hex(random_bytes(4));
$nama_dalam = 'SMOKE_Nested_Dalam_' . bin2hex(random_bytes(4));

db_transaction(function () use ($nama_luar, $nama_dalam) {
    repo_kontraktor_create(['nama_perusahaan' => $nama_luar, 'penanggung_jawab' => 'Luar']);

    try {
        db_transaction(function () use ($nama_dalam) {
            repo_kontraktor_create(['nama_perusahaan' => $nama_dalam, 'penanggung_jawab' => 'Dalam']);
            throw new RuntimeException('gagal di level dalam');
        });
    } catch (RuntimeException $e) {
        // Ditangkap di sini secara sengaja: transaksi luar harus tetap lanjut
        // dan commit walau transaksi dalam di-rollback ke SAVEPOINT-nya sendiri.
    }
});

$cek_luar = db_one('SELECT id FROM kontraktor WHERE nama_perusahaan = :n', ['n' => $nama_luar]);
$cek_dalam = db_one('SELECT id FROM kontraktor WHERE nama_perusahaan = :n', ['n' => $nama_dalam]);
cek('transaksi luar tetap commit (SAVEPOINT tidak membatalkan level luar)', $cek_luar !== null);
cek('transaksi dalam yang gagal di-rollback ke SAVEPOINT, tidak ikut commit', $cek_dalam === null);

if ($cek_luar !== null) {
    repo_kontraktor_delete((int) $cek_luar['id']);
}

echo "\n=== 5. Deteksi deadlock (unit, tanpa memicu deadlock sungguhan) ===\n";

$deadlock_exception = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
$deadlock_exception->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
cek('db_is_deadlock mengenali SQLSTATE 40001 / errno 1213', db_is_deadlock($deadlock_exception));

$bukan_deadlock = new PDOException('SQLSTATE[23000]: Integrity constraint violation');
$bukan_deadlock->errorInfo = ['23000', 1451, 'Cannot delete or update a parent row'];
cek('db_is_deadlock tidak salah kenali error FK sebagai deadlock', !db_is_deadlock($bukan_deadlock));

echo "\n=== Ringkasan ===\n";
echo "Total cek: {$total}, gagal: {$failures}\n";

exit($failures > 0 ? 1 : 0);
