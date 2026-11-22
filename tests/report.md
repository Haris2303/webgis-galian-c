# Laporan Uji Otomatis — tests/smoke.php (T-27)

Dijalankan: 2026-11-22 16:52:05 WIT · Base URL: `http://webgis-galian-c.test` · Hasil: **19/19 lulus**

## Hasil per kasus

| ID | Skenario | FR terkait | Hasil diharapkan | Hasil aktual | Status |
| --- | --- | --- | --- | --- | --- |
| T-1 | Login Admin | FR-01 | Kredensial valid -> redirect ke dashboard.php | Sesuai harapan. | PASS |
| T-2 | Kelola Data Lokasi Tambang | FR-07, FR-08 | Input koordinat & data lokasi baru -> tersimpan di MySQL dan tampil di daftar | Sesuai harapan. | PASS |
| T-3 | Kelola Data Aktivitas | FR-13 | Tambah aktivitas (kontraktor, material, status) -> tersimpan dan terhubung ke lokasi yang sesuai | Sesuai harapan. | PASS |
| T-4 | Visualisasi Peta | FR-17, FR-18 | Buka peta -> Leaflet tampil dengan data persebaran lokasi (GeoJSON) | Sesuai harapan. | PASS |
| T-5 | Pencarian Lokasi | FR-20 | Masukkan nama lokasi di pencarian -> lokasi tampil beserta atributnya | Sesuai harapan. | PASS |
| T-6 | Monitoring Aktivitas | FR-21 | Buka halaman monitoring -> aktivitas tampil sesuai data terbaru | Sesuai harapan. | PASS |
| T-7 | Detail Lokasi | FR-19 | Klik marker (lewat endpoint detail) -> detail lokasi, material, koordinat, aktivitas tampil | Sesuai harapan. | PASS |
| T-8 | Update Aktivitas | FR-15 | Ubah aktivitas tersimpan -> perubahan langsung tersimpan di database | Sesuai harapan. | PASS |
| T-9 | Hapus Data Tambang | FR-10 | Pilih data lalu klik hapus -> hilang dari database | Sesuai harapan. | PASS |
| T-10 | Cetak Laporan | FR-22 | Klik cetak laporan -> laporan siap cetak/unduh | Sesuai harapan. | PASS |
| T-11 | Logout | FR-02 | Klik logout -> sesi dihancurkan, kembali ke login; halaman terproteksi tidak lagi terbuka | Sesuai harapan. | PASS |
| N-1 | Login salah | FR-01 | Kredensial tidak valid -> kembali ke login dengan pesan netral, TIDAK redirect | Sesuai harapan. | PASS |
| N-2 | Throttling login | PRD bagian 11 | Lebih dari 5 percobaan gagal (15 menit) -> pesan berubah jadi "terlalu banyak percobaan" | Sesuai harapan. | PASS |
| N-3 | POST tanpa token CSRF | PRD bagian 11 | POST tanpa csrf_token -> 419, bukan diproses | Sesuai harapan. | PASS |
| N-4 | Kontraktor membuka URL admin | FR-03 | Kontraktor mengakses halaman admin -> 403 | Sesuai harapan. | PASS |
| N-5 | IDOR aktivitas milik kontraktor lain | FR-15 | Kontraktor membuka form ubah aktivitas kontraktor LAIN -> 403, bukan datanya ikut terbuka | Sesuai harapan. | PASS |
| N-6 | Unggah berkas .php menyamar sebagai foto | FR-14 | Berkas bukan JPG/PNG (konten, bukan ekstensi) -> ditolak server, aktivitas TIDAK tersimpan | Sesuai harapan. | PASS |
| N-7 | Koordinat tidak valid | FR-08 | Latitude di luar -90..90 -> ditolak validator, tidak tersimpan | Sesuai harapan. | PASS |
| N-8 | Nama lokasi berisi payload XSS | PRD bagian 11 | Nama lokasi berisi tag `<script>` -> tersimpan tapi tampil sebagai TEKS (ter-escape), tidak dieksekusi | Sesuai harapan. | PASS |

## Matriks keterlacakan FR -> kasus uji

| FR | Kasus yang menguji |
| --- | --- |
| FR-01 | T-1, N-1 |
| FR-02 | T-11 |
| FR-03 | N-4 |
| FR-07 | T-2 |
| FR-08 | T-2, N-7 |
| FR-10 | T-9 |
| FR-13 | T-3 |
| FR-14 | N-6 |
| FR-15 | T-8, N-5 |
| FR-17 | T-4 |
| FR-18 | T-4 |
| FR-19 | T-7 |
| FR-20 | T-5 |
| FR-21 | T-6 |
| FR-22 | T-10 |
| PRD bagian 11 | N-2, N-3, N-8 |

*FR yang tidak muncul di tabel ini tidak diuji lewat `tests/smoke.php` (mis. FR-04/05/06 dashboard, FR-09 poligon area, FR-11/12 kontraktor, FR-16 hapus aktivitas) — lihat Risiko tersisa di laporan tugas T-27.*
