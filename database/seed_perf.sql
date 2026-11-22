-- database/seed_perf.sql — data sintetis KHUSUS benchmark performa peta (T-16,
-- PRD bagian 11 / skripsi bagian 2.2.11). TERPISAH dari database/seed.sql --
-- JANGAN digabung ke seed utama, dan JANGAN dijalankan di database produksi.
--
-- Semua baris diberi prefix nama "Lokasi Perf #" supaya mudah dibedakan dari
-- data asli dan mudah dihapus lagi setelah pengukuran selesai (lihat query
-- rollback di baris paling bawah berkas ini, sengaja dikomentari).
--
-- Jalankan SETELAH database/schema.sql DAN database/seed.sql (butuh 3 baris
-- kontraktor + 5 baris jenis_material + 1 user 'admin' sudah ada):
--   mysql -u root db_galian_c < database/seed_perf.sql
--
-- Jumlah baris: 200 (di dalam rentang 150-300 yang diminta spesifikasi T-16).
-- Koordinat & luas dibuat dengan RAND(seed) PER BARIS -- bukan RAND() biasa --
-- supaya hasilnya deterministik/bisa diulang (pengukuran before/after harus
-- memakai data yang identik, bukan sebaran acak baru setiap generate ulang).

START TRANSACTION;

INSERT INTO lokasi_tambang (
  nama_lokasi, kontraktor_id, material_id, distrik, kelurahan, alamat,
  latitude, longitude, status_izin, luas_ha, created_by
)
WITH RECURSIVE seq AS (
  SELECT 1 AS n
  UNION ALL
  SELECT n + 1 FROM seq WHERE n < 200
),
kontraktor_cycle AS (
  SELECT id, ROW_NUMBER() OVER (ORDER BY id) - 1 AS idx FROM kontraktor
),
material_cycle AS (
  SELECT id, ROW_NUMBER() OVER (ORDER BY id) - 1 AS idx FROM jenis_material
)
SELECT
  CONCAT('Lokasi Perf #', LPAD(seq.n, 3, '0')) AS nama_lokasi,
  kc.id AS kontraktor_id,
  mc.id AS material_id,
  'Distrik Sintetis' AS distrik,
  CONCAT('Kelurahan Sintetis ', seq.n) AS kelurahan,
  NULL AS alamat,
  -- Bounding box Kota Sorong (config/config.php: SORONG_LAT/LNG_MIN/MAX),
  -- RAND(seed) dibuat beda per baris (n, n+100000) supaya lat & lng tidak
  -- berkorelasi satu sama lain.
  ROUND(-1.05 + RAND(seq.n) * (-0.75 - (-1.05)), 7) AS latitude,
  ROUND(131.15 + RAND(seq.n + 100000) * (131.45 - 131.15), 7) AS longitude,
  ELT(MOD(seq.n, 3) + 1, 'berizin', 'tidak_berizin', 'proses') AS status_izin,
  ROUND(1 + RAND(seq.n + 200000) * 9, 2) AS luas_ha,
  (SELECT id FROM users WHERE username = 'admin') AS created_by
FROM seq
JOIN kontraktor_cycle kc ON kc.idx = MOD(seq.n, (SELECT COUNT(*) FROM kontraktor))
JOIN material_cycle mc ON mc.idx = MOD(seq.n, (SELECT COUNT(*) FROM jenis_material));

COMMIT;

-- Rollback (hapus HANYA data sintetis T-16, tidak menyentuh seed asli):
-- DELETE FROM lokasi_tambang WHERE nama_lokasi LIKE 'Lokasi Perf #%';
