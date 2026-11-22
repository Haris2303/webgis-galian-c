# WebGIS Galian C — Kota Sorong

Aplikasi WebGIS untuk memetakan dan memantau aktivitas galian C di Kota Sorong.
Stack: PHP native (>= 8.0, tanpa framework), MySQL (PDO), Leaflet.js. Lihat PRD untuk detail lengkap.

**Status saat ini:** T-01 (bootstrap proyek, konfigurasi, error handling & logging). Modul lain
(database, autentikasi, CRUD, peta, dsb.) menyusul di iterasi berikutnya — lihat `todolist.md`.

## Instalasi dasar (sejauh T-01)

1. Salin proyek ini ke `htdocs/webgis-galian-c` (XAMPP/Laragon) — atau jadikan document root
   langsung di hosting, keduanya didukung karena BASE_URL terdeteksi otomatis.
2. Salin `config/config.local.example.php` menjadi `config/config.local.php`, lalu sesuaikan
   `APP_ENV` (`dev` di lokal, `prod` di produksi) dan kredensial database.
3. Pastikan modul `mod_rewrite` Apache aktif dan `AllowOverride All` (atau minimal `AllowOverride
   FileInfo Indexes`) diizinkan untuk folder proyek, supaya `.htaccess` berfungsi.
4. Buka `http://localhost/webgis-galian-c/` — akan tampil halaman placeholder yang mengonfirmasi
   bootstrap berjalan.

Instalasi lengkap (skema database, seed data, dsb.) akan didokumentasikan di T-29.

## Catatan

- Folder `storage/logs/` berisi log harian `app-YYYYMMDD.log` (lihat Keputusan T-01).
- Modul lain (`lokasi/`, `kontraktor/`, `aktivitas/`, `laporan/`, `api/`, `database/`,
  `assets/`, `uploads/aktivitas/`) sudah berupa folder kosong sesuai struktur PRD bagian 8,
  isinya menyusul di tugas masing-masing.

## TODO — `assets/geojson/batas_kota_sorong.geojson` (T-11)

**Berkas ini BUKAN shapefile resmi BIG/Pemda Kota Sorong.** Karena data batas
administrasi resmi dari dinas/BIG tidak tersedia ke AI pada saat T-11
dikerjakan, berkas ini diturunkan dari **data komunitas OpenStreetMap**
(relasi OSM `14905729`, diambil lewat Nominatim pada 2026-11-21), lalu
disederhanakan (Douglas-Peucker, ε≈0.0003°) dan dirapikan arah cincinnya
sendiri agar sesuai RFC 7946. Ini BUKAN "placeholder poligon kasar" (bentuknya
nyata, bukan kotak/perkiraan sembarangan) — tapi juga BUKAN data survei resmi
yang disebut PRD/skripsi.

**Wajib dilakukan sebelum dipakai untuk sidang/laporan resmi:**
1. Minta shapefile/GeoJSON batas administrasi resmi dari Dinas terkait atau
   BIG (Badan Informasi Geospasial), lalu ekspor ulang dari QGIS mengikuti
   langkah di `docs/rules_commit.md`-style: CRS EPSG:4326, simplify ≤150 KB,
   properti hanya `nama` + `kode_wilayah`, lalu validasi dengan
   `php database/validate_geojson.php`.
2. Verifikasi ulang `kode_wilayah` ("91.71" saat ini) terhadap portal resmi
   Kemendagri ("Kode dan Data Wilayah") — pencarian AI menemukan sumber
   sekunder yang saling bertentangan (91.71 vs 92.71 vs 96.71) dan tidak bisa
   dipastikan 100% benar tanpa portal resminya.
3. **Antarmuka (peta, legenda, dokumentasi) TIDAK BOLEH menyebut layer ini
   sebagai "batas resmi"** sampai poin 1 selesai — sebut sebagai "referensi"
   atau "sumber: OpenStreetMap" bila ditampilkan ke pengguna.

Jalankan `php database/validate_geojson.php` kapan pun berkas ini diganti,
sebelum commit.
