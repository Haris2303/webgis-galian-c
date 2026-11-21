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
