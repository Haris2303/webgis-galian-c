# Database — WebGIS Galian C Kota Sorong (T-02)

Skema final & data dev dari PRD bagian 7. Lihat `docs/todolist.md` → `T-02` untuk
spesifikasi tugas lengkap.

## Cara pakai (dev/lokal)

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p db_galian_c < database/seed.sql
```

`schema.sql` men-**DROP** lalu membuat ulang database `db_galian_c` secara
penuh — aman dijalankan berulang (idempoten) tapi **menghapus semua data**
setiap kali dijalankan. Jangan jalankan `schema.sql` ini di produksi; produksi
harus memakai migrasi inkremental (menyusul di T-29), bukan drop/create.

`seed.sql` men-TRUNCATE tabel transaksional (kontraktor, users, lokasi_tambang,
aktivitas_tambang, foto_aktivitas) lalu mengisi ulang — juga aman dijalankan
berulang. `jenis_material` tidak di-TRUNCATE karena diisi sekali oleh
`schema.sql` sebagai master data.

Untuk membangkitkan ulang hash password seed (misal karena ganti password atau
cost factor bcrypt):

```bash
php database/make_seed_hash.php
```

lalu salin hash yang tercetak ke statement `INSERT INTO users` di `seed.sql`.

## Akun dev yang dibuat seed.sql

| Username | Password | Role | Catatan |
| --- | --- | --- | --- |
| admin | admin123 | admin | Dinas Perindustrian |
| kontraktor1 | kontraktor123 | kontraktor | CV Sorong Jaya Tambang |
| kontraktor2 | kontraktor123 | kontraktor | PT Papua Mineral Sejahtera |
| kontraktor3 | kontraktor123 | kontraktor | CV Mitra Galian Sorong |

Password plaintext di atas **hanya untuk login manual saat testing lokal**.
Jangan dipakai di produksi — buat ulang seed dengan password lain sebelum
deploy, atau ganti password lewat fitur ubah password begitu modul itu ada.

## Verifikasi koordinat seed (WAJIB sebelum demo)

12 lokasi di `seed.sql` dibuat agar berada di dalam bounding box Kota Sorong
(`lat -1.05..-0.75`, `lng 131.15..131.45`, lihat `config/config.php`) dan
diberi nama distrik/kelurahan yang masuk akal secara administratif. **AI yang
menulis data ini tidak punya akses ke peta dan tidak bisa memverifikasi bahwa
titik-titik itu benar-benar jatuh di daratan** — bisa saja sebuah titik jatuh
di laut, rawa, atau area yang secara nyata tidak sesuai nama kelurahannya.

Sebelum data ini dipakai untuk demo ke pembimbing/dinas atau screenshot
laporan skripsi: buka setiap titik satu per satu di Leaflet (setelah T-10/T-13
selesai) atau tempel koordinatnya ke Google Maps/OpenStreetMap, lalu perbaiki
manual titik mana pun yang ternyata jatuh di luar daratan Kota Sorong. Data
riil dari survei lapangan akan menggantikan seed ini nantinya (lihat PRD
bagian 7, catatan di bawah tabel DDL).

## Keputusan desain di luar DDL literal PRD

1. **Idempotensi lewat DROP DATABASE, bukan DROP TABLE per tabel.** PRD hanya
   menulis `CREATE DATABASE IF NOT EXISTS`. Memakai `DROP DATABASE IF EXISTS`
   di depan membuat skrip ini 100% idempoten tanpa harus menjaga urutan DROP
   TABLE sesuai FK (yang mudah salah urutan jika skema berkembang). Trade-off:
   hanya aman untuk dev, tidak untuk produksi — sudah diberi peringatan jelas
   di komentar `schema.sql` dan di README ini.
2. **Indeks tambahan di luar DDL literal PRD** (`idx_kontraktor` pada
   `lokasi_tambang`, dan mengganti `idx_tanggal` tunggal PRD dengan komposit
   `idx_lokasi_tanggal (lokasi_id, tanggal_aktivitas)` di `aktivitas_tambang`)
   — ini sesuai permintaan eksplisit tugas T-02, bukan improvisasi bebas.
   Setiap indeks diberi komentar SQL di `schema.sql` tentang query yang
   dilayani. Lihat juga "Risiko tersisa" di bawah untuk query yang *tidak*
   terbantu indeks ini.
3. **CHECK constraint latitude/longitude memakai rentang WGS84 umum
   (-90..90 / -180..180), bukan bounding box Kota Sorong.** PRD bagian 11
   secara eksplisit meminta bounding box Kota Sorong hanya sebagai
   *peringatan* ("beri peringatan bila di luar"), bukan penolakan keras. Kalau
   DB menolak keras lewat CHECK, kasus sah seperti titik di tepi batas kota
   atau kesalahan GPS kecil yang masih ingin disimpan-dengan-peringatan jadi
   tidak bisa diinput sama sekali. Jadi: CHECK di DB = sanity check mustahil
   secara geografis; bounding box lunak = validasi PHP di T-07 memakai
   `SORONG_LAT_MIN/MAX`, `SORONG_LNG_MIN/MAX` yang sudah didefinisikan di
   `config/config.php`.
4. **`foto_aktivitas` sengaja tidak di-seed** (0 baris). Belum ada file fisik
   di `uploads/aktivitas/` pada tahap T-02; mengisi tabel ini dengan path yang
   tidak merujuk file nyata hanya akan jadi broken image saat UI dibangun.
   T-18 (upload foto) akan mengisi data ini dengan file yang benar-benar ada.
5. **Password dev eksplisit (`admin123`, `kontraktor123`) didokumentasikan
   di README ini**, bukan disembunyikan — supaya siapa pun yang clone proyek
   bisa langsung login untuk testing manual tanpa harus baca `seed.sql` baris
   per baris, dan supaya jelas ini bukan password produksi.
6. **ID relasi di `seed.sql` dicari lewat subquery** (`SELECT id FROM
   kontraktor WHERE nama_perusahaan = '...'`), bukan literal angka. Lebih
   verbose, tapi tidak tergantung asumsi AUTO_INCREMENT mulai dari 1 — seed
   tetap benar walau urutan INSERT berubah atau TRUNCATE tidak mereset counter
   di konfigurasi MySQL tertentu.
7. **Tabel `login_attempts` ditambahkan di T-05** (fondasi keamanan), di luar
   6 tabel PRD bagian 7. Mencatat setiap percobaan login gagal untuk
   throttling (maks 5/15 menit per username+IP, PRD bagian 11). Tidak ber-FK
   ke `users` secara sengaja — throttle harus berlaku juga untuk username yang
   tidak pernah terdaftar, supaya penyerang tidak bisa memakai keberadaan FK
   untuk menebak username mana yang valid.

## Fallback validasi PHP untuk CHECK constraint

MySQL menegakkan (enforce) CHECK constraint penuh sejak **8.0.16**; MariaDB
sejak **10.2.1**. Di versi yang lebih lama dari itu, CHECK **di-parse tapi
diam-diam diabaikan** — `CREATE TABLE` tidak error, tapi `INSERT`/`UPDATE`
yang melanggar aturan juga tidak ditolak. Tidak ada cara mendeteksi ini dari
`CREATE TABLE` saja; harus dicek lewat `SELECT VERSION();` atau dengan
mencoba insert data yang sengaja melanggar lalu melihat apakah ditolak.

Karena itu, aturan yang sama **wajib diduplikasi di lapisan PHP** (T-07,
validator server-side) sebagai pertahanan berlapis — ini juga praktik yang
benar secara umum (defense in depth), terlepas dari versi server:

```php
function validasiLokasiTambang(array $data): array
{
    $errors = [];

    $lat = $data['latitude'] ?? null;
    $lng = $data['longitude'] ?? null;
    if ($lat === null || !is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90) {
        $errors['latitude'] = 'Latitude harus berupa angka antara -90 dan 90.';
    }
    if ($lng === null || !is_numeric($lng) || (float) $lng < -180 || (float) $lng > 180) {
        $errors['longitude'] = 'Longitude harus berupa angka antara -180 dan 180.';
    }

    $berlaku = $data['tanggal_berlaku_izin'] ?? null;
    $berakhir = $data['tanggal_berakhir_izin'] ?? null;
    if ($berlaku !== null && $berlaku !== '' && $berakhir !== null && $berakhir !== '') {
        if (strtotime((string) $berakhir) < strtotime((string) $berlaku)) {
            $errors['tanggal_berakhir_izin'] = 'Tanggal berakhir izin harus sama atau setelah tanggal berlaku izin.';
        }
    }

    // Peringatan lunak (bukan penolakan) bounding box Kota Sorong, PRD bagian 11.
    if (is_numeric($lat) && is_numeric($lng)
        && ((float) $lat < SORONG_LAT_MIN || (float) $lat > SORONG_LAT_MAX
            || (float) $lng < SORONG_LNG_MIN || (float) $lng > SORONG_LNG_MAX)) {
        $errors['_warning_koordinat'] = 'Koordinat di luar perkiraan wilayah Kota Sorong — periksa kembali sebelum menyimpan.';
    }

    return $errors;
}
```

Ini adalah **cuplikan rujukan**, bukan berkas aktif yang diimpor aplikasi —
integrasi nyata ke validator terpusat (`includes/functions.php` atau modul
validator T-07) terjadi di T-07, yang belum dikerjakan saat T-02 ini ditulis.
Menyalin logika ini saat T-07 dikerjakan memastikan aturan yang sama berlaku
apa pun versi MySQL/MariaDB yang dipakai di hosting akhir.

## Catatan lintas-lapisan penting

- **`DECIMAL(10,7)` (latitude/longitude) dikembalikan PDO sebagai STRING**,
  bukan `float`/`int`, meskipun secara SQL tipenya numerik. Ini perilaku
  default driver PDO MySQL (`PDO::ATTR_STRINGIFY_FETCHES` tidak relevan di
  sini — ini soal bagaimana MySQL mengirim tipe DECIMAL di wire protocol).
  Lapisan repository (T-03) dan endpoint JSON (T-12) **wajib** melakukan
  `(float) $row['latitude']` sebelum dipakai untuk hitungan jarak, dikirim
  sebagai angka GeoJSON, atau dibandingkan secara numerik — kalau tidak,
  perbandingan/serialisasi JSON bisa memperlakukannya sebagai string dan
  menghasilkan hasil yang salah (JSON akan menaruh tanda kutip di sekitar
  angka, Leaflet tetap biasanya mentolerir string numerik tapi ini tidak
  dijamin di semua versi/consumer, jadi tetap harus di-cast).
- **Collation `utf8mb4_unicode_ci` (akhiran `_ci` = case-insensitive) sudah
  cukup** untuk pencarian `LIKE '%...%'` yang tidak sensitif huruf besar-kecil
  (misalnya `api/cari.php`) — tidak perlu `LOWER()` atau `COLLATE` tambahan di
  query. Ini berlaku untuk perbandingan sama besar (`=`) dan `LIKE` biasa;
  tidak mengubah fakta bahwa `LIKE '%q%'` (wildcard di depan) tidak bisa
  memakai index seek biasa — lihat risiko di bawah.

## Risiko tersisa

- **Koordinat seed belum diverifikasi visual di peta** — lihat bagian
  "Verifikasi koordinat seed" di atas. Ini risiko yang secara eksplisit tidak
  bisa ditutup oleh AI dan harus dicek manual oleh pengguna.
- **`idx_lokasi_tanggal (lokasi_id, tanggal_aktivitas)` tidak membantu query
  Monitoring yang memfilter hanya rentang tanggal tanpa `lokasi_id`** (bukan
  kolom kiri komposit) — pada volume data seed (36 baris) ini tidak masalah,
  tapi pada data produksi yang besar query semacam itu bisa full table scan.
  Audit indeks lanjutan dijadwalkan di T-24 ("Audit query dan indeks dengan
  EXPLAIN pada data berukuran realistis") — sengaja tidak ditambah index lain
  sekarang untuk menghindari index yang belum terbukti perlu.
- **Pencarian `LIKE '%q%'` di `api/cari.php` (wildcard di depan) tidak bisa
  memakai B-Tree index apa pun** untuk seek langsung, index composite/collation
  di atas hanya menjamin hasilnya case-insensitive, bukan membuatnya cepat
  pada tabel besar. Kalau nanti jumlah lokasi jadi besar (ratusan+), perlu
  dipertimbangkan FULLTEXT index atau pencarian prefix-only — di luar scope
  T-02, catat untuk T-24/T-16.
- **CHECK constraint tidak aktif di MySQL < 8.0.16 / MariaDB < 10.2.1** tanpa
  peringatan dari server (silent no-op) — mitigasi lewat duplikasi validasi di
  PHP sudah didokumentasikan di atas, tapi itu berarti integritas data di DB
  lama bergantung penuh pada lapisan aplikasi (T-07) selalu dipanggil dengan
  benar di semua jalur insert/update, bukan hanya constraint DB sebagai
  pengaman terakhir.
- **Password dev (`admin123`, dst.) ada di `seed.sql` dan README ini** —
  risiko rendah untuk repo privat/lokal, tapi kalau repo ini pernah dibuat
  publik (misal untuk lampiran skripsi), password ini harus diganti dulu.
