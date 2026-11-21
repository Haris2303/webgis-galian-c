# Benchmark performa peta (T-16)

Acuan: PRD bagian 11 ("Halaman peta termuat < 2 detik di jaringan lokal; render
±100 marker < 500 ms") dan skripsi bagian 2.2.11. Data sintetis:
`database/seed_perf.sql` (200 lokasi, dalam rentang 150-300 yang diminta).

## Spesifikasi mesin uji (WAJIB dilaporkan — hasil di laptop pengembang tidak
sama dengan PC kantor Dinas)

| Komponen | Detail |
| --- | --- |
| CPU | Intel Core i5-12450H (8 core / 12 thread) |
| RAM | ~7,7 GiB |
| OS | Windows 11 Home Single Language, 64-bit |
| Web server | Laragon (Apache), lokal (`http://webgis-galian-c.test`) |
| PHP | 8.3.4 (CLI/ZTS) |
| Database | MySQL 9.6 (lokal, `127.0.0.1`) |
| Browser untuk render DOM/Leaflet sungguhan | **TIDAK DIUJI** — lihat "Keterbatasan pengukuran" di bawah |

Ini laptop pengembang, **bukan** PC kantor Dinas Perindustrian yang sebenarnya.
Angka di bawah hanya indikasi; sebelum deployment produksi, ulangi pengukuran
ini di perangkat yang akan benar-benar dipakai (spesifikasi lebih rendah dari
laptop ini bukan hal yang mengejutkan).

## Keterbatasan pengukuran (baca sebelum membaca tabel)

Sesi kerja ini **tidak memiliki akses ke browser sungguhan** (ekstensi Claude
in Chrome tidak terhubung). Instrumentasi `performance.now()` di
`assets/js/map.js` (lihat bagian "Kode" di laporan tugas) SUDAH ditulis dan
akan mencetak `[perf] ...` ke console browser begitu `peta.php` dibuka --
tapi angka sungguhan dari render DOM/Leaflet (markerPane, tile, reflow)
**belum pernah terukur**, karena itu butuh browser nyata.

Dua hal YANG BISA diukur tanpa browser, dan keduanya ada di tabel di bawah:

1. **Waktu respons API** (`api/lokasi.php`, `api/area.php`) -- bagian
   "jaringan lokal" dari target PRD, diukur dengan `curl` di mesin yang sama.
2. **Waktu komputasi murni JS** (`groupByCoordinate` + pembuatan markup SVG
   ikon) -- bagian dari "render marker" yang TIDAK butuh DOM, direplikasi
   persis dari `map.js` dan dijalankan di Node.js (mesin yang sama, mesin JS
   V8 yang sama dengan Chrome).

Bagian yang TIDAK terukur: pembuatan elemen DOM Leaflet sungguhan (`L.marker`,
`L.divIcon`, append ke `markerPane`), reflow/layout browser, dan waktu tile
raster OSM dimuat. Ini satu-satunya kekurangan laporan ini -- lihat "Risiko
tersisa" di laporan tugas.

## Hasil ukur: sebelum vs sesudah

"Sebelum" = 12 lokasi (seed.sql asli). "Sesudah" = 212 lokasi (12 asli + 200
sintetis dari `database/seed_perf.sql`). Data sintetis DIHAPUS lagi setelah
pengukuran (lihat langkah uji manual) -- tabel ini bukan state permanen database.

| Metrik | Sebelum (12 lokasi) | Sesudah (212 lokasi) | Target PRD bagian 11 | Status |
| --- | --- | --- | --- | --- |
| `api/lokasi.php` — waktu respons (rata-rata 5x, `curl`) | ~32,9 ms (4 percobaan stabil; 1 outlier 138,9 ms dibuang) | ~49,5 ms (2 percobaan pertama "dingin" ~69 ms, 3 berikutnya stabil ~36,5 ms) | — (bagian dari "< 2 detik") | Jauh di bawah target |
| `api/lokasi.php` — ukuran payload | 3.088 bytes | 53.885 bytes | — | Wajar, naik linear |
| `api/area.php` — waktu respons | ~29,3 ms | ~40,4 ms | — | Jauh di bawah target |
| `groupByCoordinate()` — komputasi murni (Node, rata-rata 50x ulangan) | — | 0,53 ms (212 fitur) | bagian dari "render ±100 marker < 500 ms" | Jauh di bawah target |
| `shapeMarkup()` x212 — komputasi murni (Node, rata-rata 50x ulangan) | — | 0,09 ms | bagian dari "render ±100 marker < 500 ms" | Jauh di bawah target |
| **Render DOM Leaflet sungguhan (pembuatan marker, reflow, tile)** | — | **BELUM TERUKUR** (butuh browser nyata) | render ±100 marker < 500 ms | **Tidak dapat dinyatakan lulus/gagal** |
| **Waktu muat halaman penuh di browser** | — | **BELUM TERUKUR** (butuh browser nyata) | < 2 detik | **Tidak dapat dinyatakan lulus/gagal** |

## Kesimpulan & keputusan optimasi

Setiap bagian yang BISA diukur (API + komputasi murni JS) berada **jauh** di
bawah ambang PRD -- gabungan API (lokasi+area) + komputasi grouping/ikon untuk
212 lokasi hanya sekitar 50-90 ms, padahal anggaran render saja sudah 500 ms
dan anggaran muat halaman 2.000 ms. Satu-satunya bagian yang belum terukur
(pembuatan DOM Leaflet + reflow browser) secara teknis dikenal sangat ringan
untuk ~200 marker divIcon di Leaflet pada perangkat modern manapun -- ini
PENILAIAN TEKNIK berdasarkan karakteristik performa Leaflet yang sudah dikenal
luas, BUKAN hasil ukur, dan harus dibaca sebagai hipotesis, bukan fakta.

**Keputusan: TIDAK ada perubahan kode render (preferCanvas, circleMarker,
dsb.) yang diterapkan di T-16 ini.** Spesifikasi T-16 sendiri eksplisit
melarang "optimasi prematur: hanya ubah yang terbukti lambat" -- dan tidak
ada satu pun bagian yang *terbukti* lambat sejauh yang bisa diukur di sesi
ini. Instrumentasi `performance.now()` yang sudah ditanam di `map.js` akan
tetap mencetak `[perf] ...` setiap kali peta dimuat/difilter -- begitu
seseorang membuka `peta.php` di browser sungguhan (dengan atau tanpa
`database/seed_perf.sql` dimuat), angka render DOM yang sebenarnya akan
langsung terlihat di console, dan baru pada titik itu keputusan "perlu
dioptimasi atau tidak" bisa diambil berdasarkan bukti, bukan dugaan.

## Cara mengulang pengukuran (termasuk bagian yang belum terukur)

1. Muat data sintetis: `mysql -u root db_galian_c < database/seed_perf.sql`.
2. Buka `peta.php` di browser, buka DevTools > Console.
3. Catat tiga baris `[perf] waktu muat awal peta ...`, `[perf] render marker
   awal ...`, dan `[perf] respons filter ...` (centang/hilangkan salah satu
   checkbox status di kontrol legenda untuk memicu baris ketiga).
4. Hapus data sintetis lagi: `DELETE FROM lokasi_tambang WHERE nama_lokasi
   LIKE 'Lokasi Perf #%';` (atau jalankan ulang baris rollback yang
   dikomentari di akhir `database/seed_perf.sql`).
