-- =============================================================================
-- database/seed.sql — data dev/demo untuk WebGIS Galian C Kota Sorong
--
-- IDEMPOTEN (DEV ONLY): TRUNCATE tabel transaksional lalu INSERT ulang, jadi
-- bisa dijalankan berkali-kali tanpa error duplicate-key. jenis_material TIDAK
-- di-truncate di sini karena itu master data yang sudah diisi oleh schema.sql.
--
-- Jalankan SETELAH schema.sql (butuh 6 tabel & 5 jenis_material sudah ada):
--   mysql -u root -p db_galian_c < database/schema.sql
--   mysql -u root -p db_galian_c < database/seed.sql
--
-- PERINGATAN KOORDINAT: 12 titik lokasi di bawah dibuat AI agar berada di
-- dalam bounding box Kota Sorong (lat -1.05..-0.75, lng 131.15..131.45, lihat
-- config/config.php) dan diberi nama distrik/kelurahan yang masuk akal, TAPI
-- AI tidak bisa membuka peta untuk memastikan titik itu benar-benar jatuh di
-- daratan (bisa saja jatuh di laut/rawa). WAJIB cek manual satu per satu di
-- Leaflet/Google Maps sebelum dipakai sebagai acuan demo ke pembimbing/dinas —
-- lihat database/README.md bagian "Verifikasi koordinat seed".
--
-- PASSWORD DEV: admin/admin123, kontraktor1..3/kontraktor123 (plaintext ini
-- HANYA untuk login manual saat testing lokal). Hash di bawah dibangkitkan
-- oleh database/make_seed_hash.php (password_hash(), bcrypt) — lihat berkas
-- itu untuk cara membangkitkan ulang jika password berubah. JANGAN gunakan
-- akun/password ini di produksi.
-- =============================================================================

USE db_galian_c;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE foto_aktivitas;
TRUNCATE TABLE aktivitas_tambang;
TRUNCATE TABLE lokasi_tambang;
TRUNCATE TABLE users;
TRUNCATE TABLE kontraktor;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- Kontraktor (3)
-- -----------------------------------------------------------------------------
INSERT INTO kontraktor (nama_perusahaan, penanggung_jawab, telepon, email, alamat) VALUES
('CV Sorong Jaya Tambang', 'Yohanes Kambu', '081340011122', 'info@sorongjayatambang.co.id', 'Jl. Basuki Rahmat, Sorong Timur, Kota Sorong'),
('PT Papua Mineral Sejahtera', 'Maria Oktovina Sani', '081380022233', 'admin@papuamineralsejahtera.co.id', 'Jl. Jenderal Sudirman, Sorong Kota, Kota Sorong'),
('CV Mitra Galian Sorong', 'Abdul Rahman Hasan', '081290033344', 'kontak@mitragaliansorong.co.id', 'Jl. Kesehatan, Sorong Barat, Kota Sorong');

-- -----------------------------------------------------------------------------
-- Users (1 admin + 3 akun kontraktor). Hash dari database/make_seed_hash.php.
-- -----------------------------------------------------------------------------
INSERT INTO users (username, password_hash, nama_lengkap, role, kontraktor_id, is_active) VALUES
('admin', '$2y$10$vzlMlIHiArNCer3sHdDxUONjMbm39zcXzCAaN4NN4wR25tcqc1uH6', 'Administrator Dinas Perindustrian', 'admin', NULL, 1),
('kontraktor1', '$2y$10$4KsNM34tOWsoE0z9GkeDZOnc2jZryKFZQQGbsh0SYH7nEaWAlYUYW', 'Yohanes Kambu', 'kontraktor', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Sorong Jaya Tambang'), 1),
('kontraktor2', '$2y$10$cdrLQmrbLDccdYef2xNQfOBSuizJpV6mWeUprPJ/W.XfkCP8GbykO', 'Maria Oktovina Sani', 'kontraktor', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'PT Papua Mineral Sejahtera'), 1),
('kontraktor3', '$2y$10$D809MYlqA5rL/GGhIlhKr.mcfHZqQEzxbosPS0UWO.SiKYrw2/pLK', 'Abdul Rahman Hasan', 'kontraktor', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Mitra Galian Sorong'), 1);

-- -----------------------------------------------------------------------------
-- Lokasi tambang (12): 7 berizin, 3 tidak_berizin, 2 proses.
-- kontraktor_id/material_id/created_by dicari lewat subquery (bukan ID
-- literal) supaya skrip tidak tergantung urutan AUTO_INCREMENT.
-- -----------------------------------------------------------------------------
INSERT INTO lokasi_tambang
  (nama_lokasi, kontraktor_id, material_id, distrik, kelurahan, alamat, latitude, longitude, luas_ha, status_izin, nomor_izin, tanggal_berlaku_izin, tanggal_berakhir_izin, keterangan, created_by)
VALUES
-- CV Sorong Jaya Tambang — 4 lokasi, semua berizin
('Lokasi Klasaman', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Sorong Jaya Tambang'), (SELECT id FROM jenis_material WHERE nama = 'Pasir'),
  'Sorong Timur', 'Klasaman', 'Area tambang pasir Klasaman', -0.8762000, 131.2551000, 4.50, 'berizin', 'SK.503/012/DPMPTSP/2023', '2023-03-01', '2028-03-01', 'Izin aktif, pengawasan rutin triwulan.', (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Remu Selatan', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Sorong Jaya Tambang'), (SELECT id FROM jenis_material WHERE nama = 'Batu'),
  'Sorong Kota', 'Remu Selatan', 'Area tambang batu Remu Selatan', -0.8821000, 131.2605000, 3.20, 'berizin', 'SK.503/019/DPMPTSP/2022', '2022-06-15', '2027-06-15', NULL, (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Tanjung Kasuari', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Sorong Jaya Tambang'), (SELECT id FROM jenis_material WHERE nama = 'Kerikil'),
  'Sorong Barat', 'Tanjung Kasuari', 'Area tambang kerikil Tanjung Kasuari', -0.9104000, 131.2298000, 6.00, 'berizin', 'SK.503/027/DPMPTSP/2024', '2024-01-10', '2029-01-10', NULL, (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Klawalu', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Sorong Jaya Tambang'), (SELECT id FROM jenis_material WHERE nama = 'Sirtu'),
  'Sorong Timur', 'Klawalu', 'Area tambang sirtu Klawalu', -0.8655000, 131.2887000, 2.75, 'berizin', 'SK.503/033/DPMPTSP/2023', '2023-09-05', '2028-09-05', NULL, (SELECT id FROM users WHERE username = 'admin')),

-- PT Papua Mineral Sejahtera — 4 lokasi (3 berizin, 1 tidak_berizin)
('Lokasi Malanu', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'PT Papua Mineral Sejahtera'), (SELECT id FROM jenis_material WHERE nama = 'Tanah Urug'),
  'Sorong Utara', 'Malanu', 'Area tambang tanah urug Malanu', -0.8412000, 131.2706000, 5.10, 'berizin', 'SK.503/041/DPMPTSP/2021', '2021-11-20', '2026-11-20', 'Izin mendekati masa berakhir, perlu perpanjangan.', (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Klasabi', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'PT Papua Mineral Sejahtera'), (SELECT id FROM jenis_material WHERE nama = 'Pasir'),
  'Sorong Timur', 'Klasabi', 'Area tambang pasir Klasabi', -0.8588000, 131.2634000, 3.90, 'berizin', 'SK.503/048/DPMPTSP/2022', '2022-04-12', '2027-04-12', NULL, (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Sawagumu', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'PT Papua Mineral Sejahtera'), (SELECT id FROM jenis_material WHERE nama = 'Batu'),
  'Sorong Kota', 'Sawagumu', 'Area tambang batu Sawagumu', -0.8905000, 131.2502000, 4.25, 'berizin', 'SK.503/055/DPMPTSP/2023', '2023-07-01', '2028-07-01', NULL, (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Matalamagi', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'PT Papua Mineral Sejahtera'), (SELECT id FROM jenis_material WHERE nama = 'Kerikil'),
  'Sorong Utara', 'Matalamagi', 'Area tambang kerikil Matalamagi, belum mengajukan izin', -0.8201000, 131.2778000, 2.00, 'tidak_berizin', NULL, NULL, NULL, 'Ditemukan saat patroli lapangan, belum ada pengajuan izin.', (SELECT id FROM users WHERE username = 'admin')),

-- CV Mitra Galian Sorong — 4 lokasi (2 tidak_berizin, 2 proses)
('Lokasi Klablim', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Mitra Galian Sorong'), (SELECT id FROM jenis_material WHERE nama = 'Sirtu'),
  'Sorong Timur', 'Klablim', 'Area tambang sirtu Klablim, belum berizin', -0.8533000, 131.2952000, 3.00, 'tidak_berizin', NULL, NULL, NULL, 'Dalam proses penindakan oleh dinas.', (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Doom Barat', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Mitra Galian Sorong'), (SELECT id FROM jenis_material WHERE nama = 'Pasir'),
  'Sorong', 'Doom Barat', 'Area tambang pasir Doom Barat, belum berizin', -0.8978000, 131.2456000, 1.80, 'tidak_berizin', NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Mariat Pantai', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Mitra Galian Sorong'), (SELECT id FROM jenis_material WHERE nama = 'Tanah Urug'),
  'Mariat', 'Mariat Pantai', 'Area tambang tanah urug Mariat Pantai, izin dalam proses', -0.9312000, 131.1987000, 4.00, 'proses', NULL, NULL, NULL, 'Berkas pengajuan izin sedang diverifikasi DPMPTSP.', (SELECT id FROM users WHERE username = 'admin')),
('Lokasi Suprau', (SELECT id FROM kontraktor WHERE nama_perusahaan = 'CV Mitra Galian Sorong'), (SELECT id FROM jenis_material WHERE nama = 'Batu'),
  'Sorong Barat', 'Suprau', 'Area tambang batu Suprau, izin dalam proses', -0.9056000, 131.2187000, 3.50, 'proses', NULL, NULL, NULL, NULL, (SELECT id FROM users WHERE username = 'admin'));

-- -----------------------------------------------------------------------------
-- Aktivitas tambang (36 total, 2-4 per lokasi, tanggal tersebar 90 hari
-- terakhir lewat DATE_SUB(CURDATE(), INTERVAL n DAY) agar tetap "terkini"
-- berapa pun seed ini dijalankan ulang di masa depan).
-- -----------------------------------------------------------------------------
INSERT INTO aktivitas_tambang
  (lokasi_id, user_id, tanggal_aktivitas, jenis_kegiatan, material_id, status_aktivitas, jumlah_alat_berat, jumlah_pekerja, deskripsi)
VALUES
-- Lokasi Klasaman (3)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klasaman'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 0 DAY), 'Penggalian Material', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'aktif', 2, 8, 'Aktivitas penggalian rutin sesuai rencana kerja.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klasaman'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Pengangkutan Material', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'aktif', 3, 6, 'Pengangkutan material ke lokasi penampungan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klasaman'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Pemeliharaan Alat Berat', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'berhenti_sementara', 1, 3, 'Alat berat dalam perawatan rutin, operasional dihentikan sementara.'),

-- Lokasi Remu Selatan (2)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Remu Selatan'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Survei & Pengukuran Lokasi', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'aktif', 0, 4, 'Survei ulang batas area sebelum perluasan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Remu Selatan'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Perataan Lahan', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'selesai', 2, 10, 'Perataan lahan pasca penambangan selesai dilaksanakan.'),

-- Lokasi Tanjung Kasuari (4)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Tanjung Kasuari'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 13 DAY), 'Penggalian Material', (SELECT id FROM jenis_material WHERE nama = 'Kerikil'), 'aktif', 2, 8, 'Aktivitas penggalian rutin sesuai rencana kerja.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Tanjung Kasuari'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Pengangkutan Material', (SELECT id FROM jenis_material WHERE nama = 'Kerikil'), 'aktif', 3, 6, 'Pengangkutan material ke lokasi penampungan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Tanjung Kasuari'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Pemeliharaan Alat Berat', (SELECT id FROM jenis_material WHERE nama = 'Kerikil'), 'berhenti_sementara', 1, 3, 'Alat berat dalam perawatan rutin, operasional dihentikan sementara.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Tanjung Kasuari'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Survei & Pengukuran Lokasi', (SELECT id FROM jenis_material WHERE nama = 'Kerikil'), 'aktif', 0, 4, 'Survei ulang batas area sebelum perluasan.'),

-- Lokasi Klawalu (3)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klawalu'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 23 DAY), 'Perataan Lahan', (SELECT id FROM jenis_material WHERE nama = 'Sirtu'), 'selesai', 2, 10, 'Perataan lahan pasca penambangan selesai dilaksanakan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klawalu'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'Penggalian Material', (SELECT id FROM jenis_material WHERE nama = 'Sirtu'), 'aktif', 2, 8, 'Aktivitas penggalian rutin sesuai rencana kerja.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klawalu'), (SELECT id FROM users WHERE username = 'kontraktor1'), DATE_SUB(CURDATE(), INTERVAL 28 DAY), 'Pengangkutan Material', (SELECT id FROM jenis_material WHERE nama = 'Sirtu'), 'aktif', 3, 6, 'Pengangkutan material ke lokasi penampungan.'),

-- Lokasi Malanu (2)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Malanu'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'Pemeliharaan Alat Berat', (SELECT id FROM jenis_material WHERE nama = 'Tanah Urug'), 'berhenti_sementara', 1, 3, 'Alat berat dalam perawatan rutin, operasional dihentikan sementara.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Malanu'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 33 DAY), 'Survei & Pengukuran Lokasi', (SELECT id FROM jenis_material WHERE nama = 'Tanah Urug'), 'aktif', 0, 4, 'Survei ulang batas area sebelum perluasan.'),

-- Lokasi Klasabi (4)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klasabi'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 35 DAY), 'Perataan Lahan', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'selesai', 2, 10, 'Perataan lahan pasca penambangan selesai dilaksanakan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klasabi'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 38 DAY), 'Penggalian Material', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'aktif', 2, 8, 'Aktivitas penggalian rutin sesuai rencana kerja.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klasabi'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 40 DAY), 'Pengangkutan Material', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'aktif', 3, 6, 'Pengangkutan material ke lokasi penampungan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klasabi'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 43 DAY), 'Pemeliharaan Alat Berat', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'berhenti_sementara', 1, 3, 'Alat berat dalam perawatan rutin, operasional dihentikan sementara.'),

-- Lokasi Sawagumu (3)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Sawagumu'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 45 DAY), 'Survei & Pengukuran Lokasi', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'aktif', 0, 4, 'Survei ulang batas area sebelum perluasan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Sawagumu'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 48 DAY), 'Perataan Lahan', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'selesai', 2, 10, 'Perataan lahan pasca penambangan selesai dilaksanakan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Sawagumu'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 50 DAY), 'Penggalian Material', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'aktif', 2, 8, 'Aktivitas penggalian rutin sesuai rencana kerja.'),

-- Lokasi Matalamagi (2)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Matalamagi'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 53 DAY), 'Pengangkutan Material', (SELECT id FROM jenis_material WHERE nama = 'Kerikil'), 'aktif', 3, 6, 'Pengangkutan material ke lokasi penampungan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Matalamagi'), (SELECT id FROM users WHERE username = 'kontraktor2'), DATE_SUB(CURDATE(), INTERVAL 55 DAY), 'Pemeliharaan Alat Berat', (SELECT id FROM jenis_material WHERE nama = 'Kerikil'), 'berhenti_sementara', 1, 3, 'Alat berat dalam perawatan rutin, operasional dihentikan sementara.'),

-- Lokasi Klablim (4)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klablim'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 58 DAY), 'Survei & Pengukuran Lokasi', (SELECT id FROM jenis_material WHERE nama = 'Sirtu'), 'aktif', 0, 4, 'Survei ulang batas area sebelum perluasan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klablim'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 60 DAY), 'Perataan Lahan', (SELECT id FROM jenis_material WHERE nama = 'Sirtu'), 'selesai', 2, 10, 'Perataan lahan pasca penambangan selesai dilaksanakan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klablim'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 63 DAY), 'Penggalian Material', (SELECT id FROM jenis_material WHERE nama = 'Sirtu'), 'aktif', 2, 8, 'Aktivitas penggalian rutin sesuai rencana kerja.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Klablim'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 65 DAY), 'Pengangkutan Material', (SELECT id FROM jenis_material WHERE nama = 'Sirtu'), 'aktif', 3, 6, 'Pengangkutan material ke lokasi penampungan.'),

-- Lokasi Doom Barat (3)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Doom Barat'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 68 DAY), 'Pemeliharaan Alat Berat', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'berhenti_sementara', 1, 3, 'Alat berat dalam perawatan rutin, operasional dihentikan sementara.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Doom Barat'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 70 DAY), 'Survei & Pengukuran Lokasi', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'aktif', 0, 4, 'Survei ulang batas area sebelum perluasan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Doom Barat'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 73 DAY), 'Perataan Lahan', (SELECT id FROM jenis_material WHERE nama = 'Pasir'), 'selesai', 2, 10, 'Perataan lahan pasca penambangan selesai dilaksanakan.'),

-- Lokasi Mariat Pantai (2)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Mariat Pantai'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 75 DAY), 'Penggalian Material', (SELECT id FROM jenis_material WHERE nama = 'Tanah Urug'), 'aktif', 2, 8, 'Aktivitas penggalian rutin sesuai rencana kerja.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Mariat Pantai'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 78 DAY), 'Pengangkutan Material', (SELECT id FROM jenis_material WHERE nama = 'Tanah Urug'), 'aktif', 3, 6, 'Pengangkutan material ke lokasi penampungan.'),

-- Lokasi Suprau (4)
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Suprau'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 80 DAY), 'Pemeliharaan Alat Berat', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'berhenti_sementara', 1, 3, 'Alat berat dalam perawatan rutin, operasional dihentikan sementara.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Suprau'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 83 DAY), 'Survei & Pengukuran Lokasi', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'aktif', 0, 4, 'Survei ulang batas area sebelum perluasan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Suprau'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 85 DAY), 'Perataan Lahan', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'selesai', 2, 10, 'Perataan lahan pasca penambangan selesai dilaksanakan.'),
((SELECT id FROM lokasi_tambang WHERE nama_lokasi = 'Lokasi Suprau'), (SELECT id FROM users WHERE username = 'kontraktor3'), DATE_SUB(CURDATE(), INTERVAL 88 DAY), 'Penggalian Material', (SELECT id FROM jenis_material WHERE nama = 'Batu'), 'aktif', 2, 8, 'Aktivitas penggalian rutin sesuai rencana kerja.');

-- foto_aktivitas SENGAJA TIDAK DI-SEED: belum ada file fisik di
-- uploads/aktivitas/ pada tahap T-02 ini, jadi baris di sini akan mengarah ke
-- file yang tidak ada (broken image) saat UI dibangun. T-18 (upload foto)
-- akan mengisi tabel ini dengan file nyata.
