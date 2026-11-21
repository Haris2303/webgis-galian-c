-- =============================================================================
-- database/schema.sql — WebGIS Galian C Kota Sorong
-- Sumber: PRD bagian 7 (Desain Database). Finalisasi T-02: CHECK constraint,
-- indeks performa, dan komentar query untuk tiap indeks.
--
-- IDEMPOTEN (DEV ONLY): skrip ini men-DROP lalu membuat ulang seluruh database
-- `db_galian_c`, sehingga bisa dijalankan berkali-kali tanpa error — tapi juga
-- berarti SEMUA DATA LAMA HILANG setiap kali dijalankan. Jangan jalankan ini di
-- produksi; di produksi gunakan migrasi inkremental (lihat database/README.md).
--
-- Catatan lintas-lapisan (lihat database/README.md untuk detail):
--   - Kolom DECIMAL(10,7) (latitude/longitude) dikembalikan PDO sebagai STRING,
--     bukan float. Lapisan repository (T-03) wajib men-cast ke float sebelum
--     dipakai untuk hitungan atau dikirim sebagai angka ke JSON/Leaflet.
--   - Collation default tabel ini (utf8mb4_unicode_ci) sudah case-insensitive
--     (akhiran _ci), jadi pencarian `LIKE '%...%'` di kolom VARCHAR/TEXT otomatis
--     tidak sensitif huruf besar-kecil tanpa perlu LOWER()/COLLATE tambahan.
--   - CHECK constraint di bawah ditegakkan penuh di MySQL >= 8.0.16 dan
--     MariaDB >= 10.2.1. Di versi yang lebih lama CHECK di-parse tapi DIABAIKAN
--     (tidak error, tapi juga tidak memvalidasi apa pun) — lihat
--     database/README.md bagian "Fallback validasi PHP" untuk penjelasan
--     lengkap dan salinan kode validasi yang harus diduplikasi di lapisan PHP
--     (T-07) sebagai pertahanan berlapis, terlepas dari versi server.
-- =============================================================================

DROP DATABASE IF EXISTS db_galian_c;
CREATE DATABASE db_galian_c CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_galian_c;

-- -----------------------------------------------------------------------------
-- kontraktor — profil perusahaan kontraktor. 1 kontraktor : N lokasi.
-- -----------------------------------------------------------------------------
CREATE TABLE kontraktor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama_perusahaan VARCHAR(150) NOT NULL,
  penanggung_jawab VARCHAR(100) NOT NULL,
  telepon VARCHAR(20),
  email VARCHAR(100),
  alamat TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- users — akun login Admin & Kontraktor.
-- -----------------------------------------------------------------------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  -- UNIQUE di kolom username = indeks unik otomatis. Melayani query login
  -- (SELECT ... WHERE username = ?) dan mencegah duplikasi akun pada level DB,
  -- bukan hanya di validasi aplikasi.
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(100) NOT NULL,
  role ENUM('admin','kontraktor') NOT NULL,
  kontraktor_id INT NULL,
  is_active TINYINT(1) DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (kontraktor_id) REFERENCES kontraktor(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- jenis_material — master material: Pasir, Batu, Kerikil, Tanah Urug, Sirtu.
-- -----------------------------------------------------------------------------
CREATE TABLE jenis_material (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- lokasi_tambang — titik & area tambang + data izin. N:1 kontraktor; 1:N aktivitas.
-- -----------------------------------------------------------------------------
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

  -- Rentang WGS84 valid secara umum (bukan bounding box Kota Sorong). PRD
  -- bagian 11 meminta validasi lat/lng "idealnya" dalam bounding box Kota
  -- Sorong tapi hanya "beri peringatan" bila di luar — itu artinya aturan
  -- bounding box adalah PERINGATAN lunak di lapisan PHP (T-07, memakai
  -- SORONG_LAT_MIN/MAX & SORONG_LNG_MIN/MAX di config/config.php), bukan
  -- constraint keras di DB. CHECK di sini hanya menolak koordinat yang
  -- secara geografis mustahil (di luar -90..90 / -180..180).
  CONSTRAINT chk_lokasi_latitude CHECK (latitude BETWEEN -90 AND 90),
  CONSTRAINT chk_lokasi_longitude CHECK (longitude BETWEEN -180 AND 180),

  -- NULL diperbolehkan (lokasi tidak_berizin/proses biasanya belum punya
  -- tanggal izin); jika keduanya terisi, tanggal berakhir wajib >= berlaku.
  CONSTRAINT chk_lokasi_tanggal_izin CHECK (
    tanggal_berlaku_izin IS NULL
    OR tanggal_berakhir_izin IS NULL
    OR tanggal_berakhir_izin >= tanggal_berlaku_izin
  ),

  -- idx_status: melayani kartu dashboard per status (COUNT(*) ... GROUP BY
  -- status_izin), filter checkbox status di peta (GET api/lokasi.php?status=),
  -- dan filter status di tabel Data Lokasi / Monitoring.
  INDEX idx_status (status_izin),

  -- idx_kontraktor: melayani "lokasi milik kontraktor X" (dashboard & daftar
  -- aktivitas kontraktor hanya boleh melihat lokasinya sendiri — guard
  -- kepemilikan T-06/T-09), serta JOIN lokasi_tambang-kontraktor di laporan.
  INDEX idx_kontraktor (kontraktor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- aktivitas_tambang — laporan kegiatan dari kontraktor. N:1 lokasi.
-- -----------------------------------------------------------------------------
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

  -- idx_lokasi_tanggal (lokasi_id, tanggal_aktivitas): melayani "5 aktivitas
  -- terakhir per lokasi" di popup peta & api/lokasi_detail.php
  -- (WHERE lokasi_id = ? ORDER BY tanggal_aktivitas DESC LIMIT 5), dan tabel
  -- aktivitas per lokasi di halaman Aktivitas/Monitoring. lokasi_id di posisi
  -- kiri agar predikat kesamaan (=) dipakai dulu, lalu tanggal_aktivitas
  -- melayani urutan DESC tanpa filesort tambahan untuk filter per lokasi.
  -- Catatan residual (lihat database/README.md "Risiko tersisa"): query
  -- Monitoring yang memfilter HANYA rentang tanggal tanpa lokasi_id tidak
  -- diuntungkan indeks ini (bukan kolom kiri); itu didata ulang di T-24.
  INDEX idx_lokasi_tanggal (lokasi_id, tanggal_aktivitas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- foto_aktivitas — file dokumentasi. N:1 aktivitas.
-- -----------------------------------------------------------------------------
CREATE TABLE foto_aktivitas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aktivitas_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (aktivitas_id) REFERENCES aktivitas_tambang(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data referensi tetap (bukan data demo) — dimiliki schema.sql, bukan seed.sql,
-- karena 5 jenis material ini adalah master data aplikasi, bukan sampel dev.
INSERT INTO jenis_material (nama) VALUES ('Pasir'),('Batu'),('Kerikil'),('Tanah Urug'),('Sirtu');
