# PRD — WebGIS Pemetaan & Monitoring Aktivitas Galian C Kota Sorong

Nov 21, 2026 · @KopiJava

## 1. Ringkasan Produk

Sistem ini adalah aplikasi WebGIS berbasis PHP native + MySQL + Leaflet.js untuk memetakan dan memantau aktivitas tambang galian C (pasir, batu, kerikil, tanah urug) di Kota Sorong, Papua Barat Daya. Pengguna utamanya Dinas Perindustrian Kota Sorong (Admin) dan kontraktor tambang (Kontraktor). PRD ini diturunkan langsung dari proposal skripsi Yery Armando Rumbrapuk (NIM 202255202041, Teknik Informatika, Universitas Muhammadiyah Sorong, 2026).

**Nama aplikasi:** WebGIS Galian C — Kota Sorong

**Masalah saat ini:** monitoring galian C di Dinas Perindustrian masih manual. Info datang lewat laporan kontraktor atau pesan singkat, lalu dicatat sebagai dokumen. Akibatnya data tidak terintegrasi, lokasi tambang tidak tampil di peta digital, dan pengawasan makin sulit saat jumlah lokasi bertambah.

**Tujuan produk (dari Tujuan Penelitian 1.3):**

1. Membangun sistem pemetaan dan monitoring aktivitas galian C berbasis WebGIS untuk pengawasan industri yang digital dan terintegrasi.
2. Mengintegrasikan data spasial (koordinat titik, poligon area) dengan data atribut aktivitas tambang, agar lokasi, persebaran, dan aktivitas dapat dimonitor terstruktur.

**Metrik keberhasilan:**

- Semua 11 skenario uji black box (bagian 12) lulus.
- Skor System Usability Scale (SUS) dari pengguna Dinas ≥ 68 (di atas rata-rata).
- Data aktivitas yang diinput kontraktor langsung tampil di peta dan dashboard admin tanpa langkah manual.

## 2. Pengguna & Hak Akses

Sistem punya tepat dua peran, sesuai use case skripsi (Gambar 3.5): **Admin** (Dinas Perindustrian, pengelola dan pengawas) dan **Kontraktor** (pelapor aktivitas tambang). Tidak ada halaman publik tanpa login.

| Fitur | Admin | Kontraktor |
| --- | --- | --- |
| Login / Logout | Ya | Ya |
| Dashboard statistik | Ya (semua lokasi) | Ya (hanya lokasi miliknya) |
| Kelola data lokasi tambang (CRUD + titik + poligon) | Ya | Tidak |
| Kelola data kontraktor & akun | Ya | Tidak |
| Kelola data aktivitas tambang | Ya (lihat, ubah, hapus semua) | Input & ubah miliknya sendiri |
| Unggah foto dokumentasi | Ya | Ya |
| Melihat peta persebaran (semua marker) | Ya | Ya (lihat saja) |
| Pencarian lokasi di peta | Ya | Ya |
| Monitoring aktivitas tambang | Ya | Tidak |
| Cetak / unduh laporan monitoring | Ya | Tidak |

Catatan: use case "Monitoring Aktivitas Tambang" meng-*include* "Cetak Laporan". Kontraktor hanya dapat mengubah aktivitas yang ia input sendiri; filter ini wajib diterapkan di sisi server (query `WHERE kontraktor_id = ?`), bukan hanya disembunyikan di UI.

## 3. Tech Stack (Wajib Mengikuti Skripsi)

Stack dikunci pada teknologi yang disebut skripsi di Bab 2.2.7–2.2.13 dan Bab 3.2.7: HTML, CSS, JavaScript, PHP, MySQL, dan Leaflet.js. Skripsi secara eksplisit memilih PHP native, bukan Laravel, agar sistem ramping dan mudah dijalankan di infrastruktur terbatas Indonesia timur (hal. 15).

| Lapisan | Teknologi | Peran menurut skripsi |
| --- | --- | --- |
| Struktur halaman | HTML5 (elemen semantik `header`, `nav`, `section`, `footer`) | Login, dashboard, form, peta, laporan |
| Tampilan | CSS3 (flexbox, grid, media queries) | Antarmuka responsif, konsisten, profesional |
| Interaksi klien | JavaScript (vanilla) | Operasi peta, validasi form, tampilan dinamis tanpa reload (`fetch`) |
| Backend | PHP native (prosedural/OOP sederhana, tanpa framework) | Autentikasi, validasi, query DB, kirim JSON ke Leaflet |
| Database | MySQL (RDBMS, tabel ternormalisasi, PK/FK) | Simpan koordinat, kontraktor, izin, material, aktivitas |
| Peta | Leaflet.js + tile OpenStreetMap | Marker, polygon, popup, legenda |
| Model data spasial | Vektor: point (lokasi tambang) & polygon (batas area, batas administrasi Kota Sorong) dalam format GeoJSON | Bab 2.2.4.1 & 3.2.5 |
| Editor | Visual Studio Code | Lingkungan pengembangan |
| Metode | Prototype (iteratif) | Bangun → evaluasi dengan Dinas → perbaiki |

**Aturan untuk AI eksekutor:**

- DILARANG memakai framework PHP (Laravel, CodeIgniter, Symfony), framework JS (React, Vue, Angular, jQuery), CSS framework (Bootstrap, Tailwind), atau database selain MySQL.
- DILARANG memakai Composer/npm build step. Semua aset di-include langsung; Leaflet boleh dari CDN unpkg atau disimpan lokal di `assets/vendor/leaflet/`.
- Plugin Leaflet yang diizinkan karena tetap bagian ekosistem Leaflet.js: `Leaflet.draw` (menggambar poligon area tambang). Tambahan lain harus dicatat sebagai keputusan di bagian 13.
- Akses database wajib memakai PDO dengan prepared statement.
- Lingkungan lokal: XAMPP/Laragon (Apache + PHP ≥ 8.0 + MySQL 8 / MariaDB). Ini asumsi PRD, skripsi tidak menyebut server spesifik.
- Fitur cetak memakai `window.print()` + CSS `@media print`; "Unduh" menghasilkan file CSV dari PHP (dan PDF via Print → Save as PDF di browser), sehingga tidak perlu library PDF.

## 4. Ruang Lingkup

Cakupan mengikuti Batasan Masalah 1.4: hanya galian C di wilayah Kota Sorong, aplikasi web yang diakses lewat browser, fokus pada pemetaan, pengelolaan data aktivitas, dan monitoring digital.

**Termasuk (in scope):**

- Login berbasis peran (Admin, Kontraktor) dan logout.
- CRUD lokasi tambang dengan koordinat titik dan poligon area opsional.
- CRUD kontraktor dan akun pengguna.
- Input, ubah, hapus aktivitas tambang + unggah foto dokumentasi.
- Peta interaktif Leaflet: marker berwarna per status izin, popup atribut, legenda, pencarian, layer batas Kota Sorong.
- Dashboard statistik (total, berizin, tidak berizin, proses) + pratinjau peta + tabel lokasi.
- Halaman monitoring aktivitas terbaru per lokasi.
- Laporan monitoring per periode: cetak dan unduh.

**Tidak termasuk (out of scope):**

- Aplikasi mobile Android/iOS (tampilan web tetap responsif).
- Perhitungan volume produksi, analisis kualitas material, eksplorasi mineral, kajian geologi.
- Integrasi IoT, drone, citra satelit real-time, remote sensing, machine learning.
- Security testing lanjutan dan performance testing server.
- Tambang di luar Kota Sorong atau jenis tambang selain galian C.

## 5. Kebutuhan Fungsional

Ada 22 kebutuhan fungsional dalam 8 modul, diturunkan dari kebutuhan Admin dan Kontraktor (Bab 3.2.6 poin 1), use case, dan tabel testing 3.2. Setiap baris punya kriteria penerimaan yang bisa diuji langsung.

| ID | Modul | Kebutuhan | Peran | Kriteria penerimaan |
| --- | --- | --- | --- | --- |
| FR-01 | Autentikasi | Login dengan username + password | Semua | Kredensial valid → redirect ke `dashboard.php`; tidak valid → kembali ke login dengan pesan "Username atau password salah" |
| FR-02 | Autentikasi | Logout | Semua | Session dihancurkan, redirect ke `login.php`; tombol back tidak membuka halaman terproteksi |
| FR-03 | Autentikasi | Proteksi halaman per peran | Semua | Akses halaman Admin oleh Kontraktor → HTTP 403 / redirect ke dashboard |
| FR-04 | Dashboard | Kartu statistik: Total Lokasi, Berizin, Tidak Berizin, Proses | Semua | Angka = hasil `COUNT` per `status_izin`; Kontraktor hanya lokasinya |
| FR-05 | Dashboard | Pratinjau peta kecil persebaran galian C | Semua | Peta Leaflet tinggi ±300px menampilkan semua marker |
| FR-06 | Dashboard | Tabel daftar lokasi + status | Semua | Kolom: nama lokasi, kontraktor, status (badge berwarna) |
| FR-07 | Lokasi Tambang | Tambah lokasi: nama, alamat/kelurahan, distrik, jenis material, kontraktor, lat, lng, status izin, no. izin (SIPB), masa berlaku, luas (ha), keterangan | Admin | Tersimpan di MySQL dan langsung muncul sebagai marker di peta |
| FR-08 | Lokasi Tambang | Pilih koordinat dengan klik peta | Admin | Klik peta mengisi input lat/lng; input manual juga diterima |
| FR-09 | Lokasi Tambang | Gambar poligon batas area (opsional) | Admin | Poligon disimpan sebagai GeoJSON dan tampil di peta |
| FR-10 | Lokasi Tambang | Ubah & hapus lokasi | Admin | Hapus minta konfirmasi; setelah dihapus tidak tampil di peta maupun DB (beserta aktivitas & foto terkait) |
| FR-11 | Kontraktor | CRUD data kontraktor: nama perusahaan, penanggung jawab, telepon, email, alamat | Admin | Kontraktor bisa dipilih di form lokasi |
| FR-12 | Kontraktor | Buat akun login untuk kontraktor | Admin | Akun ber-role `kontraktor` terhubung ke satu `kontraktor_id` |
| FR-13 | Aktivitas | Input aktivitas: lokasi, tanggal, jenis kegiatan, jenis material, status aktivitas, jumlah alat berat, jumlah pekerja, deskripsi | Kontraktor | Tersimpan, terhubung ke lokasi yang benar, tampil di popup & monitoring |
| FR-14 | Aktivitas | Unggah foto dokumentasi (JPG/PNG, maks 2 MB/foto, maks 5 foto) | Kontraktor, Admin | File tersimpan di `uploads/aktivitas/` dengan nama acak; path di tabel `foto_aktivitas` |
| FR-15 | Aktivitas | Ubah aktivitas yang sudah tersimpan | Kontraktor (miliknya), Admin | Perubahan langsung tersimpan; `updated_at` diperbarui |
| FR-16 | Aktivitas | Hapus aktivitas | Admin | Dihapus dari DB beserta file foto |
| FR-17 | Peta | Peta interaktif penuh berpusat di Kota Sorong (≈ -0.876, 131.255, zoom 12) | Semua | Tile OSM tampil; layer batas administrasi Kota Sorong (GeoJSON) tampil |
| FR-18 | Peta | Marker berwarna per status izin: hijau = berizin, merah = tidak berizin, kuning = proses | Semua | Warna sesuai data; legenda tampil di bawah/pojok peta |
| FR-19 | Peta | Popup klik marker: nama, kontraktor, material, koordinat, status, aktivitas terakhir, thumbnail foto | Semua | Data diambil dari endpoint JSON |
| FR-20 | Peta | Pencarian lokasi berdasarkan nama + filter status | Semua | Hasil cocok → peta zoom ke marker dan popup terbuka |
| FR-21 | Monitoring | Daftar aktivitas terbaru semua lokasi, filter per lokasi/kontraktor/status/tanggal | Admin | Data baru dari kontraktor langsung tampil saat halaman dibuka/di-refresh |
| FR-22 | Laporan | Rekap monitoring per periode (tanggal awal–akhir): kop Dinas Perindustrian Kota Sorong, daftar lokasi, status izin, ringkasan aktivitas; tombol Cetak & Unduh | Admin | Cetak membuka dialog print dengan layout A4 bersih; Unduh menghasilkan CSV |

## 6. Alur Sistem

Alur utama mengikuti flowchart dan activity diagram skripsi (Gambar 3.4 dan 3.6): data yang diinput kontraktor disimpan ke MySQL, tampil di peta Leaflet, lalu dianalisis admin dan dicetak sebagai laporan.

&#91;embedded content: activity diagram · 3 lane, sesuai Gambar 3.6 skripsi\]

Versi teks untuk AI eksekutor (diagram bisa hilang saat ekspor):

1. Admin atau Kontraktor login; sistem memverifikasi akun ke MySQL. Tidak valid → kembali ke login; valid → Dashboard WebGIS.
2. Kontraktor memilih menu Aktivitas: "Lihat data" menampilkan daftar aktivitasnya; "Input data" membuka form input/ubah aktivitas + foto.
3. Sistem menyimpan aktivitas ke database dan mengaitkannya dengan lokasi tambang, sehingga langsung tampil di peta dan popup marker.
4. Admin memilih menu Monitoring: "Menu peta" membuka peta persebaran; "Menu laporan" membuka analisis dan monitoring aktivitas.
5. Admin mencetak laporan monitoring berisi lokasi tambang dan aktivitasnya; proses selesai.

## 7. Desain Database (MySQL)

Database `db_galian_c` terdiri dari 6 tabel ternormalisasi dengan relasi PK/FK. Koordinat disimpan sebagai `DECIMAL(10,7)` lat/lng (cukup untuk marker Leaflet), poligon sebagai teks GeoJSON.

| Tabel | Isi | Relasi |
| --- | --- | --- |
| `users` | Akun login Admin & Kontraktor | `kontraktor_id` → `kontraktor.id` (NULL untuk admin) |
| `kontraktor` | Profil perusahaan kontraktor | 1 kontraktor : N lokasi |
| `jenis_material` | Master material: Pasir, Batu, Kerikil, Tanah Urug, Sirtu | 1 : N lokasi & aktivitas |
| `lokasi_tambang` | Titik & area tambang + data izin | N : 1 kontraktor; 1 : N aktivitas |
| `aktivitas_tambang` | Laporan kegiatan dari kontraktor | N : 1 lokasi |
| `foto_aktivitas` | File dokumentasi | N : 1 aktivitas |

```sql
CREATE DATABASE IF NOT EXISTS db_galian_c CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_galian_c;

CREATE TABLE kontraktor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama_perusahaan VARCHAR(150) NOT NULL,
  penanggung_jawab VARCHAR(100) NOT NULL,
  telepon VARCHAR(20),
  email VARCHAR(100),
  alamat TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(100) NOT NULL,
  role ENUM('admin','kontraktor') NOT NULL,
  kontraktor_id INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (kontraktor_id) REFERENCES kontraktor(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE jenis_material (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE lokasi_tambang (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama_lokasi VARCHAR(150) NOT NULL,
  kontraktor_id INT NOT NULL,
  material_id INT NOT NULL,
  distrik VARCHAR(100),
  kelurahan VARCHAR(100),
  alamat TEXT,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  area_geojson LONGTEXT NULL,
  luas_ha DECIMAL(10,2) NULL,
  status_izin ENUM('berizin','tidak_berizin','proses') NOT NULL DEFAULT 'proses',
  nomor_izin VARCHAR(100) NULL,
  tanggal_berlaku_izin DATE NULL,
  tanggal_berakhir_izin DATE NULL,
  keterangan TEXT,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (kontraktor_id) REFERENCES kontraktor(id) ON DELETE RESTRICT,
  FOREIGN KEY (material_id) REFERENCES jenis_material(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_status (status_izin)
) ENGINE=InnoDB;

CREATE TABLE aktivitas_tambang (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lokasi_id INT NOT NULL,
  user_id INT NOT NULL,
  tanggal_aktivitas DATE NOT NULL,
  jenis_kegiatan VARCHAR(100) NOT NULL,
  material_id INT NOT NULL,
  status_aktivitas ENUM('aktif','berhenti_sementara','selesai') NOT NULL DEFAULT 'aktif',
  jumlah_alat_berat INT DEFAULT 0,
  jumlah_pekerja INT DEFAULT 0,
  deskripsi TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (lokasi_id) REFERENCES lokasi_tambang(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (material_id) REFERENCES jenis_material(id),
  INDEX idx_tanggal (tanggal_aktivitas)
) ENGINE=InnoDB;

CREATE TABLE foto_aktivitas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aktivitas_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aktivitas_id) REFERENCES aktivitas_tambang(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO jenis_material (nama) VALUES ('Pasir'),('Batu'),('Kerikil'),('Tanah Urug'),('Sirtu');
```

Seeder (`database/seed.sql`): 1 admin (`admin` / `admin123`, di-hash dengan `password_hash()`), 3 kontraktor + akunnya, dan 12 lokasi dummy di sekitar Kota Sorong (7 berizin, 3 tidak berizin, 2 proses, sama dengan angka di sketsa dashboard). Koordinat dummy harus berada di daratan Kota Sorong; data riil dari survei lapangan menggantikannya nanti.

Catatan: skripsi menyebut "volume produksi" sebagai atribut (hal. 26) tetapi Batasan Masalah 1.4 mengecualikan perhitungannya. PRD ini tidak menyimpan volume; tambahkan kolom `volume_m3` hanya jika pembimbing memintanya.

## 8. Arsitektur & Struktur Folder

Arsitekturnya tiga lapis sesuai Bab 2.2.9 dan 2.2.11: browser (HTML/CSS/JS + Leaflet) → PHP (halaman + endpoint JSON) → MySQL. Halaman dirender PHP di server; data peta diambil JavaScript lewat `fetch()` ke endpoint `api/*.php` yang mengembalikan JSON/GeoJSON, lalu dirender Leaflet.

```
webgis-galian-c/
├── index.php                 # redirect ke login/dashboard
├── login.php
├── logout.php
├── dashboard.php
├── peta.php                  # peta interaktif penuh
├── lokasi/
│   ├── index.php             # tabel lokasi (admin)
│   ├── form.php              # tambah/ubah (?id=)
│   └── hapus.php             # POST + CSRF
├── kontraktor/
│   ├── index.php
│   ├── form.php
│   └── hapus.php
├── aktivitas/
│   ├── index.php             # daftar aktivitas (kontraktor: miliknya)
│   ├── form.php              # input/ubah + upload foto
│   └── hapus.php
├── monitoring.php            # admin
├── laporan/
│   ├── index.php             # filter periode + preview cetak
│   └── unduh.php             # output CSV
├── api/
│   ├── lokasi.php            # GeoJSON FeatureCollection titik
│   ├── area.php              # GeoJSON poligon area tambang
│   ├── lokasi_detail.php     # detail + aktivitas terakhir + foto
│   ├── cari.php              # pencarian nama
│   └── statistik.php         # angka kartu dashboard
├── config/
│   ├── config.php            # BASE_URL, kredensial DB, konstanta upload
│   └── database.php          # koneksi PDO (singleton)
├── includes/
│   ├── auth.php              # session, require_login(), require_role()
│   ├── functions.php         # e() escape, csrf_token(), flash(), redirect()
│   ├── header.php            # <head>, topbar
│   ├── sidebar.php           # menu sesuai role
│   └── footer.php
├── assets/
│   ├── css/style.css
│   ├── css/print.css
│   ├── js/map.js             # inisialisasi Leaflet, marker, legenda, search
│   ├── js/map-picker.js      # klik peta → isi lat/lng, Leaflet.draw poligon
│   ├── js/validation.js
│   ├── img/                  # logo, ikon marker hijau/merah/kuning
│   ├── geojson/batas_kota_sorong.geojson
│   └── vendor/leaflet/       # opsional jika tidak memakai CDN
├── uploads/aktivitas/        # foto (dengan .htaccess: larang eksekusi PHP)
├── database/
│   ├── schema.sql
│   └── seed.sql
└── README.md                 # cara instal di XAMPP
```

Berkas `batas_kota_sorong.geojson` disiapkan dari peta administrasi Kota Sorong (data sekunder skripsi), misalnya diekspor dari QGIS dalam proyeksi WGS84 (EPSG:4326). Bila belum tersedia, buat placeholder poligon kasar dan tandai TODO di README.

## 9. Spesifikasi Halaman UI

Tata letak mengikuti sketsa Gambar 3.7–3.11: topbar berjudul, sidebar navigasi kiri, konten di kanan. Semua teks antarmuka berbahasa Indonesia.

**Gaya visual:** profesional instansi pemerintah. Warna utama biru tua (#1E3A5F) untuk sidebar/topbar, latar konten abu terang (#F4F6F9), kartu putih ber-radius 8px. Warna status konsisten di marker, badge, dan legenda: hijau #2E9E4F (Berizin), merah #D64545 (Tidak Berizin), kuning #E5A823 (Proses). Font sistem (`system-ui, Segoe UI, Roboto, sans-serif`). Responsif: di layar < 768px sidebar menjadi menu hamburger.

**Menu sidebar:** Admin = Dashboard, Data Lokasi, Data Kontraktor, Aktivitas, Peta Interaktif, Monitoring, Laporan, Logout. Kontraktor = Dashboard, Aktivitas Saya, Input Aktivitas, Peta Lokasi, Logout.

1. **Login (`login.php`)** — Header "WebGIS Galian C — Kota Sorong", kotak "Masuk ke Sistem" berisi input Username, Password, tombol Login. Pesan error di atas form. Tanpa sidebar.
2. **Dashboard (`dashboard.php`)** — Judul "Dashboard — Admin Dinas Perindustrian" (atau nama kontraktor). Baris 4 kartu: Total Lokasi Tambang, Berizin, Tidak Berizin, Proses. Di bawahnya grid 2 kolom: kiri "Pratinjau Peta Persebaran Galian C" (Leaflet kecil), kanan "Daftar Lokasi Tambang" (tabel nama, kontraktor, badge status).
3. **Peta Interaktif (`peta.php`)** — Judul "Peta Aktivitas Galian C". Peta memenuhi area konten (tinggi ≈ calc(100vh − 120px)). Panel kanan atas: kotak "Cari" (input nama lokasi) + checkbox filter status. Legenda di pojok kanan bawah dan baris legenda di bawah peta: ● Berizin ● Tidak Berizin ● Proses. Kontrol layer: OSM Standar / Batas Kota Sorong / Area Tambang. Popup marker berisi nama, kontraktor, material, koordinat, badge status, aktivitas terakhir (tanggal + status), thumbnail foto, link "Detail".
4. **Data Lokasi (`lokasi/index.php`, `lokasi/form.php`)** — Tabel dengan pencarian, tombol Tambah, aksi Ubah/Hapus. Form dua kolom: kiri field atribut, kanan peta picker (klik untuk set titik, toolbar draw poligon).
5. **Form Input Data Aktivitas (`aktivitas/form.php`)** — Judul "Input Data Aktivitas". Field berurutan seperti sketsa: Nama Lokasi Tambang (dropdown lokasi milik kontraktor), Nama Kontraktor (terisi otomatis, read-only), Jenis Material, Koordinat (Lat, Long) (terisi otomatis dari lokasi, read-only), Status Izin (read-only dari lokasi), lalu Tanggal, Jenis Kegiatan, Status Aktivitas, Alat Berat, Pekerja, Deskripsi, Unggah Dokumentasi (multi-file + pratinjau), tombol Simpan Data.
6. **Monitoring (`monitoring.php`)** — Filter bar (lokasi, kontraktor, status aktivitas, rentang tanggal) + tabel aktivitas terbaru dengan kolom tanggal, lokasi, kontraktor, kegiatan, status, foto, aksi Lihat di Peta.
7. **Cetak Laporan (`laporan/index.php`)** — Judul "Laporan Monitoring". Filter periode. Pratinjau: "Laporan Monitoring Galian C", "Dinas Perindustrian Kota Sorong", "Periode: …", tabel lokasi + badge status + jumlah aktivitas periode itu, area tanda tangan Kepala Bidang. Tombol Cetak dan Unduh. Saat print, sidebar/topbar/tombol disembunyikan.

Semua aksi simpan/hapus menampilkan flash message sukses/gagal di atas konten.

## 10. Endpoint JSON (PHP → Leaflet)

Sesuai Bab 2.2.11, PHP mengambil data dari MySQL lalu mengirimkannya ke Leaflet dalam format JSON. Semua endpoint di `api/` wajib login (session), hanya menerima GET, dan mengirim header `Content-Type: application/json; charset=utf-8`. Kontraktor tetap boleh melihat semua marker (use case "Melihat Peta Lokasi"), tetapi detail aktivitas hanya miliknya.

| Endpoint | Parameter | Respons |
| --- | --- | --- |
| `GET api/lokasi.php` | `status` (opsional: berizin, tidak\_berizin, proses) | GeoJSON FeatureCollection titik |
| `GET api/area.php` | — | GeoJSON FeatureCollection poligon dari `area_geojson` |
| `GET api/lokasi_detail.php` | `id` (wajib) | Objek lokasi + 5 aktivitas terakhir + URL foto |
| `GET api/cari.php` | `q` (min 2 karakter) | Array maks 10 `{id, nama_lokasi, lat, lng, status_izin}` (`LIKE %q%`) |
| `GET api/statistik.php` | — | `{total, berizin, tidak_berizin, proses}` |

Contoh respons `api/lokasi.php`:

```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [131.2551, -0.8762] },
      "properties": {
        "id": 1,
        "nama_lokasi": "Lokasi Klasaman",
        "kontraktor": "CV Contoh Jaya",
        "material": "Pasir",
        "status_izin": "berizin",
        "aktivitas_terakhir": "2026-11-18"
      }
    }
  ]
}
```

Penting: urutan koordinat GeoJSON adalah `[longitude, latitude]`, sedangkan `L.marker()` memakai `[lat, lng]`. Gunakan `L.geoJSON(data, { pointToLayer })` agar konversi otomatis. Error dikembalikan sebagai `{"error": "pesan"}` dengan HTTP 400/401/404.

## 11. Kebutuhan Non-Fungsional

Skripsi mengecualikan security dan performance testing lanjutan, jadi target di bawah adalah praktik dasar yang wajar, bukan target uji formal.

| Aspek | Ketentuan |
| --- | --- |
| Performa | Halaman peta termuat < 2 detik di jaringan lokal; render ±100 marker < 500 ms (acuan Bab 2.2.11) |
| Kompatibilitas | Chrome, Firefox, Edge versi terbaru; responsif 360px–1920px |
| Keamanan dasar | `password_hash()`/`password_verify()`; PDO prepared statement; escape output `htmlspecialchars()`; token CSRF di semua form POST; `session_regenerate_id()` saat login; hapus hanya via POST |
| Upload | Validasi MIME dengan `finfo` (image/jpeg, image/png), maks 2 MB, nama file acak, `.htaccess` di `uploads/` melarang eksekusi skrip |
| Integritas data | Validasi server: lat −90..90, lng −180..180, idealnya dalam bounding box Kota Sorong (lat −1.05..−0.75, lng 131.15..131.45, beri peringatan bila di luar); field wajib tidak kosong; FK konsisten |
| Usability | Bahasa Indonesia, label jelas, konfirmasi sebelum hapus, flash message setelah aksi |
| Pemeliharaan | Kode PHP terstruktur per modul, konfigurasi terpusat di `config/`, komentar singkat di fungsi utama |
| Deploy | Berjalan di XAMPP lokal dan shared hosting PHP + MySQL biasa tanpa akses SSH |

## 12. Rencana Pengujian

Pengujian memakai dua metode dari Bab 3.2.9: Black Box Testing untuk fungsi, lalu Usability Testing dengan System Usability Scale (SUS). Ke-11 kasus di bawah diambil dari Tabel 3.2 skripsi dan harus lulus semua.

| No | Uji kasus | Skenario | Hasil yang diharapkan | FR |
| --- | --- | --- | --- | --- |
| 1 | Login Admin | Masukkan username & password valid | Masuk ke Dashboard utama | FR-01 |
| 2 | Kelola Data Lokasi Tambang | Input koordinat & data lokasi baru | Tersimpan di MySQL dan tampil di peta | FR-07, FR-08 |
| 3 | Kelola Data Aktivitas | Tambah aktivitas (kontraktor, material, status) | Tersimpan dan terhubung ke lokasi yang sesuai | FR-13 |
| 4 | Visualisasi Peta | Pilih menu peta | Peta Leaflet tampil dengan persebaran lokasi | FR-17, FR-18 |
| 5 | Pencarian Lokasi | Masukkan nama lokasi di pencarian | Lokasi tampil beserta atributnya | FR-20 |
| 6 | Monitoring Aktivitas | Buka halaman monitoring | Aktivitas tampil sesuai data terbaru | FR-21 |
| 7 | Detail Lokasi | Klik marker | Popup: detail lokasi, material, koordinat, aktivitas | FR-19 |
| 8 | Update Aktivitas | Ubah aktivitas tersimpan | Perubahan langsung tersimpan di DB | FR-15 |
| 9 | Hapus Data Tambang | Pilih data lalu klik hapus | Hilang dari peta dan DB | FR-10 |
| 10 | Cetak Laporan | Klik cetak laporan | Laporan siap cetak/unduh | FR-22 |
| 11 | Logout | Klik logout | Keluar dan kembali ke login | FR-02 |

Kasus negatif tambahan yang disarankan: login salah, kontraktor membuka URL admin, upload file .php, koordinat kosong/tidak valid.

**SUS:** 10 pernyataan standar, skala 1–5, diisi pegawai Dinas Perindustrian (dan kontraktor bila memungkinkan). Konversi skor: butir ganjil S = X − 1, butir genap S = 5 − X.

```latex
\text{Skor SUS} = \left(\sum_{i=1}^{10} S_i\right) \times 2.5
```

Rentang hasil 0–100; target ≥ 68. Aplikasi tidak perlu menghitung SUS, cukup disiapkan sebagai kuesioner terpisah.

## 13. Tahapan Implementasi untuk AI Agent

Bangun dalam 7 iterasi sesuai metode Prototype. Setelah setiap iterasi, AI berhenti, merangkum file yang dibuat dan cara mengujinya, lalu menunggu konfirmasi sebelum lanjut.

1. **Fondasi** — `database/schema.sql`, `seed.sql`, `config/`, `includes/` (auth, functions, header, sidebar, footer), `assets/css/style.css`, README instalasi XAMPP. Uji: impor SQL berhasil, halaman kosong ber-layout tampil.
2. **Autentikasi** — login, logout, proteksi per peran (FR-01–FR-03). Uji kasus 1 dan 11.
3. **Master data** — CRUD kontraktor + akun (FR-11, FR-12), CRUD lokasi dengan peta picker dan poligon (FR-07–FR-10). Uji kasus 2 dan 9.
4. **Peta interaktif** — endpoint `api/`, `peta.php`, `map.js`: marker berwarna, popup, legenda, layer batas kota, pencarian & filter (FR-17–FR-20). Uji kasus 4, 5, 7.
5. **Aktivitas kontraktor** — input/ubah/hapus aktivitas + upload foto (FR-13–FR-16). Uji kasus 3 dan 8.
6. **Dashboard & monitoring** — kartu statistik, pratinjau peta, tabel lokasi, halaman monitoring (FR-04–FR-06, FR-21). Uji kasus 6.
7. **Laporan & finishing** — laporan periode, print CSS, unduh CSV (FR-22), responsif, flash message, review keamanan dasar. Jalankan ulang ke-11 kasus uji.

**Definition of Done:** semua 22 FR terpenuhi, 11 kasus black box lulus, tidak ada error/warning PHP dengan `display_errors=On`, tidak ada error di console browser, dan README menjelaskan instalasi dari nol.

**Prompt siap tempel untuk Claude Sonnet:**

```markdown
Kamu adalah full-stack developer PHP. Bangun aplikasi "WebGIS Galian C — Kota Sorong" persis sesuai PRD terlampir.

Aturan wajib:
1. Tech stack HANYA: HTML5, CSS3, JavaScript vanilla, PHP native (>= 8.0, tanpa framework), MySQL (PDO + prepared statement), Leaflet.js + OpenStreetMap, plugin Leaflet.draw. Tanpa Laravel, jQuery, React, Bootstrap, Tailwind, Composer, atau npm.
2. Ikuti struktur folder di bagian 8, skema database di bagian 7, dan endpoint di bagian 10 tanpa mengganti nama.
3. Kerjakan per iterasi seperti bagian 13. Di akhir tiap iterasi: tampilkan daftar file, langkah uji manual, lalu BERHENTI dan tunggu konfirmasi saya.
4. Seluruh teks antarmuka berbahasa Indonesia. Warna status: hijau #2E9E4F berizin, merah #D64545 tidak berizin, kuning #E5A823 proses.
5. Tulis kode lengkap setiap file (jangan disingkat "..."). Beri komentar singkat berbahasa Indonesia di fungsi penting.
6. Jika ada hal yang tidak dijelaskan PRD, pilih solusi paling sederhana yang tetap dalam tech stack, lalu catat sebagai "Keputusan" di ringkasan iterasi.

Mulai dari Iterasi 1.
```

Cara pakai: ekspor dokumen ini ke Markdown (menu unduh dokumen), lampirkan ke Sonnet bersama prompt di atas.
