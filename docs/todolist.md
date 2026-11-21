# TODOLIST - WebGIS Galian C Kota Sorong

Berkas ini adalah kamus tugas proyek. Setiap tugas punya ID tetap `T-01` sampai `T-29`.
Acuan lain: PRD proyek (nomor bagian PRD dirujuk di tiap tugas) dan proposal skripsi.

## 1. Aturan untuk AI agent (baca dulu)

1. **ID tugas adalah rujukan resmi.** Jika pengguna menulis `T-05`, cari bagian `### T-05` di berkas ini dan gunakan isinya sebagai spesifikasi.
2. **"Prasyarat selesai: T-01, T-02"** berarti tugas-tugas itu sudah dikerjakan dan ada di repositori. Anggap artefaknya tersedia (lihat baris *Artefak setelah selesai* tiap tugas) dan **jangan membuat ulang**. Gunakan ulang fungsi, berkas, dan konvensinya.
3. **Rentang** ditulis `T-01 s.d. T-05` atau `T-01 sampai T-05` dan berarti T-01, T-02, T-03, T-04, T-05.
4. **Satu tugas = satu sesi.** Jika pengguna berkata "kerjakan T-10", kerjakan hanya T-10 lalu berhenti dan tunggu konfirmasi.
5. **Cek dependensi.** Setiap tugas punya *Prasyarat langsung* dan *Seluruh rantai prasyarat*. Jika ada tugas dalam rantai itu yang tidak disebut pengguna sebagai selesai dan tidak tampak di repositori, jangan lanjut diam-diam: beri tahu pengguna tugas mana yang belum ada.
6. **Verifikasi cepat sebelum bergantung.** Sebelum memakai artefak prasyarat, periksa singkat bahwa berkasnya ada di repo (mis. `ls`, `grep` nama fungsi). Nama berkas atau fungsi boleh berbeda dari daftar bila ada catatan Keputusan; yang berlaku adalah kode yang benar-benar ada. Jika berbeda, sesuaikan dan sebutkan perbedaannya.
7. **Pesan pengguna menang atas berkas ini.** Jika status di bagian 3 berbeda dengan pesan pengguna, ikuti pesan pengguna dan sebut perbedaannya satu kalimat.
8. **Keputusan di luar PRD** wajib dicatat di akhir jawaban dalam daftar "Keputusan" (alasan singkat), agar bisa dikumpulkan ke `docs/keputusan.md`.

Contoh cara pengguna memanggil:

```
Kerjakan T-05. Prasyarat selesai: T-01, T-02, T-03, T-04.
T-01 sampai T-09 sudah selesai. Lanjut T-10.
```

## 2. Konteks global proyek


PROYEK: WebGIS Galian C - Kota Sorong. Aplikasi web untuk memetakan dan memonitor aktivitas galian C oleh Dinas Perindustrian Kota Sorong (peran Admin) dan kontraktor tambang (peran Kontraktor). Dokumen acuan: PRD terlampir; nomor bagian PRD dirujuk pada tiap prompt.

STACK TERKUNCI (mengikuti skripsi): HTML5, CSS3, JavaScript vanilla, PHP native >= 8.0 tanpa framework, MySQL lewat PDO prepared statement, Leaflet.js + tile OpenStreetMap. Plugin tambahan yang diizinkan hanya Leaflet.draw. DILARANG: Laravel/CodeIgniter/Symfony, jQuery/React/Vue/Angular, Bootstrap/Tailwind, Composer atau npm build step, database selain MySQL.

KONVENSI: struktur folder sesuai PRD bagian 8. Tabel dan kolom snake_case; fungsi PHP snake_case; JavaScript camelCase. Seluruh teks antarmuka bahasa Indonesia; komentar kode bahasa Indonesia singkat. Semua output HTML lewat e(). Semua POST wajib token CSRF. GeoJSON memakai [lng,lat] sedangkan Leaflet memakai [lat,lng]. Error API berbentuk {"error": "..."} dengan kode HTTP yang tepat. Zona waktu Asia/Jayapura. Warna status: hijau #2E9E4F (berizin), merah #D64545 (tidak berizin), kuning #E5A823 (proses).

CARA KERJA AI: (1) baca tugas dan nyatakan asumsi singkat; (2) tulis kode LENGKAP untuk setiap berkas (tanpa '...'); (3) penuhi Kriteria Selesai; (4) di akhir berikan daftar berkas, langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa; (5) berhenti dan tunggu konfirmasi sebelum tugas berikutnya. Bila ragu antara dua pendekatan, pilih yang paling sederhana dalam stack dan jangan menambah dependensi.

## 3. Status (diperbarui pengguna)

Centang `[x]` bila tugas selesai dan teruji. Ini sekadar pengingat; pesan pengguna tetap yang utama.


**Iterasi 1 - Fondasi**

- [ ] **T-01** Bootstrap proyek, konfigurasi, error handling & logging terpusat (P0, 4 jam)
- [ ] **T-02** Skema MySQL final, indeks, constraint, migrasi & seed data realistis (P0, 5 jam)
- [ ] **T-03** Lapisan akses data PDO: koneksi, repository, transaksi bersarang, pagination (P0, 5 jam)
- [ ] **T-04** Layout, design tokens CSS, komponen UI, dan responsif dasar tanpa framework (P1, 6 jam)

**Iterasi 2 - Autentikasi**

- [ ] **T-05** Fondasi keamanan: session hardening, CSRF, XSS, header, throttling login (P0, 5 jam)
- [ ] **T-06** Login/logout, RBAC dua peran, dan guard kepemilikan data (FR-01 s.d. FR-03) (P0, 5 jam)

**Iterasi 3 - Master Data**

- [ ] **T-07** Validator server-side terpusat + validasi GeoJSON poligon + validation.js (P0, 4 jam)
- [ ] **T-08** CRUD Kontraktor + pembuatan akun login atomik (FR-11, FR-12) (P0, 5 jam)
- [ ] **T-09** CRUD Lokasi Tambang: tabel server-side, aturan izin, hapus berantai (FR-07, FR-10) (P0, 6 jam)
- [ ] **T-10** Map picker klik-titik + gambar poligon area dengan Leaflet.draw (FR-08, FR-09) (P1, 8 jam)

**Iterasi 4 - Peta Interaktif**

- [ ] **T-11** Siapkan GeoJSON batas Kota Sorong (EPSG:4326, RFC 7946, disederhanakan) (P1, 3 jam)
- [ ] **T-12** API GeoJSON peta: lokasi, area, detail, cari, statistik (PRD bagian 10) (P0, 6 jam)
- [ ] **T-13** Peta interaktif inti: map.js modular, marker per status, legenda, layer (FR-17, FR-18) (P0, 8 jam)
- [ ] **T-14** Popup detail lazy-load aman XSS + deep-link ?lokasi=ID (FR-19) (P1, 4 jam)
- [ ] **T-15** Pencarian autocomplete + filter status client-side dengan state di URL (FR-20) (P1, 5 jam)
- [ ] **T-16** Benchmark & optimasi render peta terhadap target PRD bagian 11 (P3, 3 jam)

**Iterasi 5 - Aktivitas**

- [ ] **T-17** Modul aktivitas: CRUD dengan guard kepemilikan anti-IDOR (FR-13, 15, 16) (P0, 7 jam)
- [ ] **T-18** Unggah foto aman: validasi berlapis, re-encode GD, penyimpanan terkontrol (FR-14) (P0, 8 jam)
- [ ] **T-19** Gerbang foto terproteksi dengan otorisasi + thumbnail (hardening opsional) (P2, 5 jam)

**Iterasi 6 - Dashboard & Monitoring**

- [ ] **T-20** Dashboard per peran: statistik teragregasi, pratinjau peta, tabel (FR-04 s.d. FR-06) (P1, 5 jam)
- [ ] **T-21** Halaman monitoring: query builder filter aman, pagination, deep-link ke peta (FR-21) (P1, 6 jam)

**Iterasi 7 - Laporan & Finishing**

- [ ] **T-22** Laporan monitoring per periode + layout cetak A4 profesional (FR-22) (P1, 6 jam)
- [ ] **T-23** Ekspor CSV streaming aman dan ramah Excel Indonesia (P1, 3 jam)
- [ ] **T-24** Audit query dan indeks dengan EXPLAIN pada data berukuran realistis (P2, 3 jam)
- [ ] **T-25** Responsif & aksesibilitas menyeluruh (mobile, sentuh, keyboard) (P2, 5 jam)
- [ ] **T-26** Security review menyeluruh & hardening (OWASP, IDOR, konfigurasi produksi) (P1, 6 jam)
- [ ] **T-27** Skrip uji otomatis black box: 11 kasus skripsi + kasus negatif + matriks keterlacakan (P1, 7 jam)
- [ ] **T-28** Tool impor data survei lapangan (CSV ke lokasi_tambang) dengan validasi & deteksi duplikat (P2, 5 jam)
- [ ] **T-29** Dokumentasi, panduan deployment, skrip backup, dan catatan keputusan (P2, 4 jam)

## 4. Peta dependensi

| ID | Tugas | Prioritas | Prasyarat langsung | Seluruh rantai prasyarat |
| --- | --- | --- | --- | --- |
| T-01 | Bootstrap proyek, konfigurasi, error handling & logging terpusat | P0 | - | - |
| T-02 | Skema MySQL final, indeks, constraint, migrasi & seed data realistis | P0 | T-01 | T-01 |
| T-03 | Lapisan akses data PDO: koneksi, repository, transaksi bersarang, pagination | P0 | T-01, T-02 | T-01, T-02 |
| T-04 | Layout, design tokens CSS, komponen UI, dan responsif dasar tanpa framework | P1 | T-01 | T-01 |
| T-05 | Fondasi keamanan: session hardening, CSRF, XSS, header, throttling login | P0 | T-01 | T-01 |
| T-06 | Login/logout, RBAC dua peran, dan guard kepemilikan data (FR-01 s.d. FR-03) | P0 | T-03, T-05 | T-01, T-02, T-03, T-05 |
| T-07 | Validator server-side terpusat + validasi GeoJSON poligon + validation.js | P0 | T-05 | T-01, T-05 |
| T-08 | CRUD Kontraktor + pembuatan akun login atomik (FR-11, FR-12) | P0 | T-03, T-04, T-06, T-07 | T-01, T-02, T-03, T-04, T-05, T-06, T-07 |
| T-09 | CRUD Lokasi Tambang: tabel server-side, aturan izin, hapus berantai (FR-07, FR-10) | P0 | T-07, T-08 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08 |
| T-10 | Map picker klik-titik + gambar poligon area dengan Leaflet.draw (FR-08, FR-09) | P1 | T-07, T-09 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09 |
| T-11 | Siapkan GeoJSON batas Kota Sorong (EPSG:4326, RFC 7946, disederhanakan) | P1 | T-01 | T-01 |
| T-12 | API GeoJSON peta: lokasi, area, detail, cari, statistik (PRD bagian 10) | P0 | T-02, T-03, T-06 | T-01, T-02, T-03, T-05, T-06 |
| T-13 | Peta interaktif inti: map.js modular, marker per status, legenda, layer (FR-17, FR-18) | P0 | T-04, T-11, T-12 | T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12 |
| T-14 | Popup detail lazy-load aman XSS + deep-link ?lokasi=ID (FR-19) | P1 | T-13 | T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12, T-13 |
| T-15 | Pencarian autocomplete + filter status client-side dengan state di URL (FR-20) | P1 | T-13 | T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12, T-13 |
| T-16 | Benchmark & optimasi render peta terhadap target PRD bagian 11 | P3 | T-13, T-15 | T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12, T-13, T-15 |
| T-17 | Modul aktivitas: CRUD dengan guard kepemilikan anti-IDOR (FR-13, 15, 16) | P0 | T-06, T-09 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09 |
| T-18 | Unggah foto aman: validasi berlapis, re-encode GD, penyimpanan terkontrol (FR-14) | P0 | T-17 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-17 |
| T-19 | Gerbang foto terproteksi dengan otorisasi + thumbnail (hardening opsional) | P2 | T-18 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-17, T-18 |
| T-20 | Dashboard per peran: statistik teragregasi, pratinjau peta, tabel (FR-04 s.d. FR-06) | P1 | T-12, T-13 | T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12, T-13 |
| T-21 | Halaman monitoring: query builder filter aman, pagination, deep-link ke peta (FR-21) | P1 | T-14, T-17 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17 |
| T-22 | Laporan monitoring per periode + layout cetak A4 profesional (FR-22) | P1 | T-21 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-21 |
| T-23 | Ekspor CSV streaming aman dan ramah Excel Indonesia | P1 | T-22 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-21, T-22 |
| T-24 | Audit query dan indeks dengan EXPLAIN pada data berukuran realistis | P2 | T-22 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-21, T-22 |
| T-25 | Responsif & aksesibilitas menyeluruh (mobile, sentuh, keyboard) | P2 | T-04, T-10, T-13 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-10, T-11, T-12, T-13 |
| T-26 | Security review menyeluruh & hardening (OWASP, IDOR, konfigurasi produksi) | P1 | T-12, T-18, T-23 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-18, T-21, T-22, T-23 |
| T-27 | Skrip uji otomatis black box: 11 kasus skripsi + kasus negatif + matriks keterlacakan | P1 | T-18, T-22, T-23, T-26 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-18, T-21, T-22, T-23, T-26 |
| T-28 | Tool impor data survei lapangan (CSV ke lokasi_tambang) dengan validasi & deteksi duplikat | P2 | T-07, T-09 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09 |
| T-29 | Dokumentasi, panduan deployment, skrip backup, dan catatan keputusan | P2 | T-26 | T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-18, T-21, T-22, T-23, T-26 |

Prioritas: P0 = kritis (jalur utama), P1 = tinggi (fitur inti), P2 = sedang (penguat kualitas), P3 = rendah (boleh dilewati).

## 5. Daftar tugas

### T-01 - Bootstrap proyek, konfigurasi, error handling & logging terpusat

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 1 - Fondasi | **Modul:** Fondasi
- **Kompleksitas:** Sedang | **Estimasi:** 4 jam | **FR terkait:** -
- **Prasyarat langsung:** tidak ada
- **Seluruh rantai prasyarat:** tidak ada
- **Acuan:** PRD bagian 3, 8, 11
- **Peran AI:** Senior PHP engineer (aplikasi web tanpa framework)

**Tugas:** Buat kerangka proyek webgis-galian-c sesuai struktur PRD bagian 8, lengkap dengan konfigurasi dan penanganan error yang dimuat oleh semua halaman lewat satu berkas bootstrap.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-01 selesai):

- config/config.php, config/config.local.php (di-gitignore)
- includes/bootstrap.php (dimuat semua halaman), includes/functions.php: e(), url(), asset(), redirect(), json_response(), abort()
- .htaccess root, storage/logs/

**Spesifikasi teknis:**

- config/config.php: BASE_URL, APP_ENV (dev/prod), batas upload, konstanta bounding box Kota Sorong (lat -1.05..-0.75, lng 131.15..131.45). Kredensial DB dibaca dari config/config.local.php yang masuk .gitignore.
- includes/bootstrap.php: set timezone Asia/Jayapura, error_reporting dan display_errors menurut APP_ENV, set_error_handler + set_exception_handler yang menulis ke storage/logs/app-YYYYMMDD.log (rotasi harian). Folder storage/ adalah tambahan di luar PRD: catat sebagai Keputusan.
- Helper di includes/functions.php: e(), url(), asset() dengan cache-busting filemtime, redirect(), json_response(), abort(kode).
- Respons error beradaptasi: halaman HTML ramah untuk browser, JSON {"error": "..."} untuk permintaan AJAX (deteksi header Accept atau X-Requested-With).
- .htaccess root: matikan directory listing, blokir akses langsung ke config/, includes/, database/, storage/.

**Edge case dan risiko yang wajib ditangani:**

- BASE_URL benar baik di subfolder XAMPP (htdocs/webgis-galian-c) maupun di root hosting.
- Exception di dalam output buffering tidak boleh menghasilkan halaman setengah jadi.
- Folder log tidak bisa ditulis: aplikasi tetap menampilkan pesan generik, tidak crash berantai.

**Kriteria selesai:** Struktur folder PRD bagian 8 terbentuk. Kredensial terpisah dari kode. Error produksi tidak bocor ke pengguna dan tercatat di file log.

**Verifikasi sebelum menyatakan selesai:** Picu exception sengaja: di dev tampil detail, di prod hanya pesan generik plus satu baris log. Akses langsung /config/config.php ditolak (403).

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-02 - Skema MySQL final, indeks, constraint, migrasi & seed data realistis

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 1 - Fondasi | **Modul:** Database
- **Kompleksitas:** Tinggi | **Estimasi:** 5 jam | **FR terkait:** Semua FR
- **Prasyarat langsung:** T-01
- **Seluruh rantai prasyarat:** T-01
- **Acuan:** PRD bagian 7
- **Peran AI:** Senior database engineer (MySQL 8 / MariaDB)

**Tugas:** Finalisasi database/schema.sql dan database/seed.sql dari DDL PRD bagian 7, lalu perketat integritas data dan performa query.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-02 selesai):

- database/schema.sql, database/seed.sql, database/make_seed_hash.php
- 6 tabel: users, kontraktor, jenis_material, lokasi_tambang, aktivitas_tambang, foto_aktivitas
- Data seed: 1 admin, 3 kontraktor, 12 lokasi (7 berizin, 3 tidak berizin, 2 proses)

**Spesifikasi teknis:**

- Pertahankan 6 tabel PRD (users, kontraktor, jenis_material, lokasi_tambang, aktivitas_tambang, foto_aktivitas), InnoDB, utf8mb4, FK dengan aksi ON DELETE persis seperti PRD.
- Tambahkan CHECK constraint untuk latitude/longitude dan tanggal_berakhir_izin >= tanggal_berlaku_izin. Sediakan fallback validasi PHP untuk MySQL < 8.0.16 dan MariaDB lama; jelaskan pilihannya.
- Indeks: (status_izin), (kontraktor_id), (lokasi_id, tanggal_aktivitas), username UNIQUE. Untuk tiap indeks tulis komentar SQL tentang query yang dilayani.
- seed.sql: 1 admin, 3 kontraktor beserta akun, 12 lokasi dengan koordinat di dalam bounding box Kota Sorong, 2 sampai 4 aktivitas per lokasi dengan tanggal tersebar 90 hari terakhir. Hash password dibangkitkan skrip database/make_seed_hash.php memakai password_hash(), bukan hash tanpa penjelasan.
- Skrip impor idempoten untuk dev (DROP/CREATE bersyarat) sehingga bisa dijalankan ulang tanpa error.

**Edge case dan risiko yang wajib ditangani:**

- Collation untuk pencarian LIKE tidak sensitif huruf besar-kecil.
- DECIMAL(10,7) dikembalikan PDO sebagai string: dokumentasikan agar lapisan atasnya melakukan cast.
- Koordinat seed harus dicek manual di peta oleh pengguna karena AI tidak dapat memverifikasi bahwa titik berada di daratan: tandai di README.

**Kriteria selesai:** schema.sql dapat dijalankan berulang. Seed menghasilkan 12 lokasi (7 berizin, 3 tidak berizin, 2 proses). EXPLAIN query utama memakai indeks.

**Verifikasi sebelum menyatakan selesai:** Impor dua kali berturut-turut tanpa error. Jumlah baris sesuai 7/3/2. EXPLAIN untuk query daftar lokasi per status dan aktivitas terbaru per lokasi menunjukkan penggunaan indeks.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-03 - Lapisan akses data PDO: koneksi, repository, transaksi bersarang, pagination

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 1 - Fondasi | **Modul:** Backend
- **Kompleksitas:** Tinggi | **Estimasi:** 5 jam | **FR terkait:** Semua FR
- **Prasyarat langsung:** T-01, T-02
- **Seluruh rantai prasyarat:** T-01, T-02
- **Acuan:** PRD bagian 3, 7, 11
- **Peran AI:** Senior backend engineer (PHP, PDO, desain lapisan data)

**Tugas:** Bangun config/database.php (koneksi PDO tunggal) dan repository fungsional per entitas di includes/repo/ (users, kontraktor, lokasi, aktivitas, foto). Folder repo adalah tambahan di luar PRD: catat sebagai Keputusan.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-03 selesai):

- config/database.php (koneksi PDO)
- includes/repo/: users, kontraktor, lokasi, aktivitas, foto
- Helper: db_one(), db_all(), db_exec(), db_transaction(), paginate()
- tests/db_smoke.php

**Spesifikasi teknis:**

- Opsi PDO: ERRMODE_EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES=false, charset utf8mb4, SET time_zone agar konsisten dengan PHP.
- Helper db_one(), db_all(), db_exec(), db_transaction(callable) dengan rollback otomatis dan aman dipanggil bersarang (savepoint atau penghitung kedalaman).
- Setiap fungsi repository berisi satu query terparameter. Kolom ORDER BY dan arah sort hanya lewat whitelist, tidak pernah dari input mentah.
- Helper paginate(sql, params, page, per_page) mengembalikan [rows, total]; LIMIT/OFFSET di-bind sebagai integer.

**Edge case dan risiko yang wajib ditangani:**

- bindValue dengan PDO::PARAM_INT untuk LIMIT/OFFSET (kalau tidak, MySQL menolak).
- Karakter % dan _ dari pengguna pada LIKE harus di-escape.
- Koneksi gagal: error terpusat tanpa membocorkan host atau kredensial.
- Deadlock: ulangi transaksi satu kali sebelum menyerah.

**Kriteria selesai:** Tidak ada query dengan variabel yang digabung ke string SQL. Transaksi gagal otomatis rollback. Skrip smoke test CRUD lulus.

**Verifikasi sebelum menyatakan selesai:** Jalankan tests/db_smoke.php (CRUD tiap repo plus transaksi yang sengaja gagal). Jalankan grep untuk memastikan tidak ada query yang menggabungkan variabel.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-04 - Layout, design tokens CSS, komponen UI, dan responsif dasar tanpa framework

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 1 - Fondasi | **Modul:** Frontend
- **Kompleksitas:** Sedang | **Estimasi:** 6 jam | **FR terkait:** Semua halaman
- **Prasyarat langsung:** T-01
- **Seluruh rantai prasyarat:** T-01
- **Acuan:** PRD bagian 9
- **Peran AI:** Senior frontend engineer (CSS murni, aksesibilitas)

**Tugas:** Bangun assets/css/style.css, assets/css/print.css, dan includes/header.php, sidebar.php, footer.php sesuai spesifikasi UI PRD bagian 9, memakai CSS custom properties.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-04 selesai):

- assets/css/style.css, assets/css/print.css
- includes/header.php, sidebar.php, footer.php
- Komponen: kartu statistik, tabel, badge status, form, flash message, modal <dialog>, skeleton

**Spesifikasi teknis:**

- Token CSS: warna utama #1E3A5F, latar #F4F6F9, status hijau #2E9E4F / merah #D64545 / kuning #E5A823, radius 8px, skala spasi, font system-ui.
- Komponen: kartu statistik, tabel, badge status (teks eksplisit, bukan hanya warna), tombol, form dengan pesan error inline, flash message, modal konfirmasi hapus memakai elemen <dialog>, skeleton loader.
- Sidebar berbeda untuk Admin dan Kontraktor dengan penanda halaman aktif (aria-current). Di bawah 768 px menjadi menu hamburger tanpa framework.
- Aksesibilitas: label terhubung ke input, focus ring jelas, kontras teks minimal 4.5:1, urutan tab logis, prefers-reduced-motion dihormati.

**Edge case dan risiko yang wajib ditangani:**

- Tabel lebar di layar kecil (overflow-x terkendali, bukan merusak layout).
- Tinggi peta di mobile (gunakan dvh dengan fallback vh).
- Flash message yang tampil setelah redirect harus hilang setelah dibaca sekali.

**Kriteria selesai:** Layout sidebar menurut peran tampil benar di 360, 768, dan 1280 px. Lighthouse Accessibility minimal 90 pada login dan dashboard.

**Verifikasi sebelum menyatakan selesai:** Uji manual di 360/768/1280 px. Jalankan Lighthouse pada login dan dashboard. Cek navigasi hanya dengan keyboard.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-05 - Fondasi keamanan: session hardening, CSRF, XSS, header, throttling login

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 2 - Autentikasi | **Modul:** Keamanan
- **Kompleksitas:** Tinggi | **Estimasi:** 5 jam | **FR terkait:** FR-01, 02, 03; PRD bagian 11
- **Prasyarat langsung:** T-01
- **Seluruh rantai prasyarat:** T-01
- **Acuan:** PRD bagian 11
- **Peran AI:** Senior application security engineer (PHP)

**Tugas:** Implementasikan fondasi keamanan di includes/auth.php dan includes/functions.php yang akan dipakai seluruh modul berikutnya.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-05 selesai):

- Setup session aman dan header keamanan (CSP dll.) di bootstrap/auth
- csrf_field(), csrf_verify() di includes/functions.php; semua POST wajib token
- Throttling login (maks 5 gagal per 15 menit)

**Spesifikasi teknis:**

- Session: cookie HttpOnly, SameSite=Lax, Secure otomatis bila HTTPS, session.use_strict_mode, nama session kustom, timeout idle 30 menit dan absolut 8 jam, session_regenerate_id(true) saat login.
- CSRF: token acak (random_bytes) per session, csrf_field(), csrf_verify() dengan hash_equals() untuk semua POST; fetch() memakai header X-CSRF-Token.
- Escaping: e() = htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE, UTF-8). Aturan: tidak ada echo variabel tanpa e().
- Header: X-Content-Type-Options, X-Frame-Options DENY, Referrer-Policy, dan Content-Security-Policy yang hanya mengizinkan self, unpkg.com (Leaflet), dan tile.openstreetmap.org untuk gambar. Jelaskan tiap direktif.
- Throttling login: maksimal 5 gagal per 15 menit per kombinasi username + IP, pesan netral. Jika memakai tabel login_attempts, catat sebagai Keputusan karena menambah tabel di luar PRD bagian 7.

**Edge case dan risiko yang wajib ditangani:**

- CSP bentrok dengan skrip inline: pindahkan semua skrip ke berkas .js atau gunakan nonce.
- CSRF pada form multipart (upload).
- Session fixation dan session yang kedaluwarsa saat form masih terbuka.
- Pesan login tidak boleh membedakan username tidak ada dan password salah.

**Kriteria selesai:** Cookie session aman, semua POST wajib token CSRF, header keamanan aktif, brute force login terbatas.

**Verifikasi sebelum menyatakan selesai:** POST tanpa token menghasilkan 419. Flag cookie terlihat di DevTools. Enam percobaan login gagal berturut-turut menyebabkan penguncian sementara.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-06 - Login/logout, RBAC dua peran, dan guard kepemilikan data (FR-01 s.d. FR-03)

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 2 - Autentikasi | **Modul:** Autentikasi
- **Kompleksitas:** Tinggi | **Estimasi:** 5 jam | **FR terkait:** FR-01, 02, 03
- **Prasyarat langsung:** T-03, T-05
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-05
- **Acuan:** PRD bagian 2, 5 (FR-01 s.d. 03)
- **Peran AI:** Senior backend engineer (autentikasi dan otorisasi)

**Tugas:** Bangun login.php, logout.php, includes/auth.php (current_user, require_login, require_role, guard kepemilikan) dan penentuan menu sidebar menurut peran.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-06 selesai):

- login.php, logout.php
- includes/auth.php: current_user(), require_login(), require_role(), can_access_lokasi(), can_access_aktivitas()
- Menu sidebar menurut peran

**Spesifikasi teknis:**

- Login: password_verify() dengan dummy hash saat username tidak ada (cegah timing attack), password_needs_rehash(), cek is_active, update last_login, regenerate session.
- Logout hanya lewat POST + CSRF, menghancurkan session dan cookie, lalu mengirim Cache-Control: no-store agar tombol Back tidak membuka halaman terproteksi.
- require_role('admin') mengembalikan 403 (bukan redirect diam-diam). Fungsi can_access_lokasi($user, $id) dan can_access_aktivitas($user, $id) dipakai semua modul sebagai satu-satunya titik otorisasi data.
- Parameter ?next= setelah login hanya menerima path internal yang divalidasi.

**Edge case dan risiko yang wajib ditangani:**

- Open redirect lewat ?next=.
- Akun dinonaktifkan ketika sesi masih aktif: cek ulang is_active tiap request atau tiap N menit.
- Pengguna berperan kontraktor tanpa kontraktor_id (data rusak): tolak login dengan pesan netral dan catat log.
- ID milik pengguna lain harus menghasilkan respons yang sama dengan ID yang tidak ada (hindari kebocoran informasi).

**Kriteria selesai:** Kriteria penerimaan FR-01 sampai FR-03 lulus. Kontraktor tidak bisa mengakses halaman admin maupun data kontraktor lain.

**Verifikasi sebelum menyatakan selesai:** Jalankan acceptance FR-01, FR-02, FR-03. Kontraktor membuka /kontraktor/index.php menerima 403. Kontraktor membuka aktivitas milik kontraktor lain menerima 403/404.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-07 - Validator server-side terpusat + validasi GeoJSON poligon + validation.js

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 3 - Master Data | **Modul:** Backend
- **Kompleksitas:** Sedang | **Estimasi:** 4 jam | **FR terkait:** FR-07, 08, 09, 13
- **Prasyarat langsung:** T-05
- **Seluruh rantai prasyarat:** T-01, T-05
- **Acuan:** PRD bagian 5, 7, 11
- **Peran AI:** Senior PHP engineer (validasi input dan data spasial)

**Tugas:** Bangun includes/validator.php dan assets/js/validation.js sebagai satu-satunya mekanisme validasi input di seluruh aplikasi.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-07 selesai):

- includes/validator.php (aturan validasi, error bag, old())
- Validator koordinat dan geojson_polygon
- assets/js/validation.js, tests/validator_test.php

**Spesifikasi teknis:**

- Aturan: required, min, max, numeric, between, in, date, after_or_equal, email, phone, regex; hasil berupa error bag per field dengan pesan bahasa Indonesia; helper old() untuk mengisi ulang form.
- Normalisasi: trim, koma desimal diubah ke titik (contoh -0,8762 menjadi -0.8762) sebelum validasi angka.
- Validator koordinat: rentang lat/lng wajib (error), di luar bounding box Kota Sorong berupa peringatan (warning) yang tidak memblokir.
- Validator geojson_polygon: struktur Feature/Polygon, cincin tertutup, minimal 4 titik, tidak berlubang, ukuran maksimal 200 KB, urutan koordinat [lng,lat] dan dalam rentang valid.
- validation.js hanya untuk UX (HTML5 constraint + pesan inline), tidak pernah menggantikan validasi server.

**Edge case dan risiko yang wajib ditangani:**

- Poligon dengan sisi saling berpotongan (deteksi dasar self-intersection).
- Nama berisi Unicode atau karakter kontrol.
- Jumlah pekerja atau alat berat bernilai negatif atau pecahan.
- Input array tak terduga (field[]= pada parameter skalar).

**Kriteria selesai:** Validator lulus minimal 20 kasus uji. GeoJSON tidak valid ditolak server. Server tetap otoritatif atas validasi klien.

**Verifikasi sebelum menyatakan selesai:** Jalankan tests/validator_test.php dengan minimal 20 kasus (valid dan tidak valid) dan tampilkan hasil PASS/FAIL.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-08 - CRUD Kontraktor + pembuatan akun login atomik (FR-11, FR-12)

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 3 - Master Data | **Modul:** Kontraktor
- **Kompleksitas:** Sedang | **Estimasi:** 5 jam | **FR terkait:** FR-11, 12
- **Prasyarat langsung:** T-03, T-04, T-06, T-07
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07
- **Acuan:** PRD bagian 5 (FR-11, 12), 7
- **Peran AI:** Senior full-stack PHP engineer

**Tugas:** Bangun modul kontraktor/ (index.php, form.php, hapus.php) khusus Admin, termasuk pembuatan akun login kontraktor.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-08 selesai):

- kontraktor/index.php, form.php, hapus.php
- Pembuatan akun login kontraktor secara atomik, reset password, nonaktifkan akun

**Spesifikasi teknis:**

- Daftar dengan pencarian dan pagination (memakai helper paginate dari lapisan PDO).
- Tambah kontraktor dan akun login dalam satu transaksi (insert kontraktor, lalu users dengan role kontraktor). Tangkap error duplikat 1062 untuk username unik, bukan hanya cek SELECT sebelum insert.
- Reset password oleh admin: generate acak yang kuat, tampil satu kali di layar, simpan hanya hash-nya.
- Nonaktifkan akun (is_active=0) sebagai pengganti hapus. Hapus kontraktor hanya jika tidak punya lokasi (ON DELETE RESTRICT) dan tampilkan alasan yang jelas.

**Edge case dan risiko yang wajib ditangani:**

- Dua admin menambah username yang sama bersamaan (race condition).
- Menonaktifkan akun yang sedang login.
- Password awal lemah atau terlihat di log atau URL.

**Kriteria selesai:** FR-11 dan FR-12 lulus. Transaksi gagal tidak meninggalkan baris yatim. Hapus kontraktor yang masih punya lokasi ditolak dengan pesan jelas.

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-11 dan FR-12. Simulasikan kegagalan insert kedua dan pastikan tidak ada baris kontraktor yatim.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-09 - CRUD Lokasi Tambang: tabel server-side, aturan izin, hapus berantai (FR-07, FR-10)

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 3 - Master Data | **Modul:** Lokasi Tambang
- **Kompleksitas:** Tinggi | **Estimasi:** 6 jam | **FR terkait:** FR-07, 10
- **Prasyarat langsung:** T-07, T-08
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08
- **Acuan:** PRD bagian 5 (FR-07, 10), 7
- **Peran AI:** Senior full-stack PHP engineer

**Tugas:** Bangun lokasi/index.php, lokasi/form.php, lokasi/hapus.php (tanpa peta dahulu; picker peta menyusul di tugas berikutnya).

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-09 selesai):

- lokasi/index.php, form.php, hapus.php (tanpa peta picker)
- Aturan izin di server, hapus berantai beserta file foto

**Spesifikasi teknis:**

- Tabel: pencarian nama/kelurahan, filter status/kontraktor/material, sorting whitelist, pagination.
- Aturan bisnis di server: status berizin mewajibkan nomor_izin dan tanggal_berlaku; status tidak_berizin mengosongkan field izin; created_by diambil dari sesi, bukan dari form.
- Hapus lewat POST + CSRF dengan konfirmasi. Dalam satu transaksi: kumpulkan path foto semua aktivitas terkait, DELETE lokasi (CASCADE), lalu hapus file fisik setelah commit berhasil.
- Tampilkan peringatan visual 'izin kedaluwarsa' bila tanggal_berakhir_izin lewat, tanpa mengubah nilai enum.

**Edge case dan risiko yang wajib ditangani:**

- File fisik sudah hilang dari disk saat hapus.
- Double submit pada form simpan.
- Mass assignment: hanya field yang di-whitelist yang boleh masuk ke INSERT/UPDATE.
- Perubahan status dari berizin ke tidak_berizin pada lokasi yang sudah punya aktivitas.

**Kriteria selesai:** FR-07 dan FR-10 lulus. Setelah hapus tidak ada file foto yatim di uploads/. Aturan izin dijaga di server.

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-07 dan FR-10. Hapus lokasi yang punya foto lalu cek folder uploads/ untuk memastikan tidak ada file tersisa.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-10 - Map picker klik-titik + gambar poligon area dengan Leaflet.draw (FR-08, FR-09)

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 3 - Master Data | **Modul:** Peta / GIS
- **Kompleksitas:** Sangat Tinggi | **Estimasi:** 8 jam | **FR terkait:** FR-08, 09
- **Prasyarat langsung:** T-07, T-09
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09
- **Acuan:** PRD bagian 5 (FR-08, 09), 9
- **Peran AI:** Senior GIS / frontend engineer (Leaflet)

**Tugas:** Bangun assets/js/map-picker.js dan integrasikan ke lokasi/form.php: pemilihan titik dan penggambaran batas area tambang.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-10 selesai):

- assets/js/map-picker.js terintegrasi di lokasi/form.php
- Marker draggable sinkron lat/lng, poligon via Leaflet.draw disimpan sebagai GeoJSON Feature

**Spesifikasi teknis:**

- Klik peta menempatkan marker draggable yang tersinkron dua arah dengan input lat/lng (event change dan Enter). Peringatan live bila titik di luar bounding box Kota Sorong.
- Leaflet.draw hanya mode polygon (nonaktifkan polyline, circle, rectangle, marker); maksimal satu poligon; edit dan hapus diizinkan.
- Simpan ke input hidden sebagai GeoJSON Feature dengan koordinat [lng,lat]. Hapus poligon lalu simpan berarti area_geojson menjadi NULL.
- Saran luas_ha dihitung dari poligon (rumus geodesik sederhana, spherical excess) dan boleh diubah pengguna.
- Mode ubah: muat marker dan poligon eksisting, fitBounds, tampilkan batas kota sebagai referensi bila tersedia.

**Edge case dan risiko yang wajib ditangani:**

- Tertukar urutan lat/lng vs lng/lat (sumber bug paling umum pada GeoJSON dan Leaflet).
- Poligon berlubang atau multipolygon: tolak dengan pesan jelas.
- Leaflet.draw gagal dimuat dari CDN: form tetap dapat diisi manual.
- Layar sentuh: titik sentuh dan drag tidak bentrok dengan scroll halaman.

**Kriteria selesai:** Round-trip simpan lalu muat poligon identik. Server menolak GeoJSON invalid. Form tetap bisa diisi manual bila CDN gagal.

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-08 dan FR-09. Simpan lalu buka ulang: poligon dan titik identik. Kirim GeoJSON rusak langsung ke server dan pastikan ditolak.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-11 - Siapkan GeoJSON batas Kota Sorong (EPSG:4326, RFC 7946, disederhanakan)

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 4 - Peta Interaktif | **Modul:** Data Spasial
- **Kompleksitas:** Sedang | **Estimasi:** 3 jam | **FR terkait:** FR-17
- **Prasyarat langsung:** T-01
- **Seluruh rantai prasyarat:** T-01
- **Acuan:** PRD bagian 8; skripsi bagian 3.2.4-3.2.5 (peta administrasi sebagai data sekunder)
- **Peran AI:** Senior GIS data engineer (QGIS, GeoJSON)

**Tugas:** Siapkan assets/geojson/batas_kota_sorong.geojson dari peta administrasi Kota Sorong (data sekunder skripsi) dan skrip validasinya.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-11 selesai):

- assets/geojson/batas_kota_sorong.geojson (atau placeholder bertanda)
- database/validate_geojson.php

**Spesifikasi teknis:**

- Ekspor dari QGIS ke GeoJSON dengan CRS EPSG:4326 (WGS84), aturan cincin RFC 7946 (eksterior berlawanan jarum jam).
- Simplifikasi (QGIS Simplify atau mapshaper) sampai 150 KB atau kurang, pertahankan bentuk batas yang terlihat nyata pada zoom 12-14. Properti hanya nama dan kode wilayah.
- Skrip database/validate_geojson.php: cek struktur, koordinat dalam bounding box, ukuran file.
- Bila data resmi belum tersedia, buat placeholder poligon kasar, beri properti "placeholder": true, dan tandai TODO di README. Antarmuka tidak boleh menyatakan placeholder sebagai batas resmi.

**Edge case dan risiko yang wajib ditangani:**

- CRS bukan WGS84 (koordinat besar seperti 131xxxx menandakan UTM).
- Topologi rusak akibat simplifikasi berlebihan.
- Aturan arah cincin terbalik.

**Kriteria selesai:** batas_kota_sorong.geojson valid, ukuran 150 KB atau kurang, tampil benar di Leaflet. Jika placeholder, ditandai jelas.

**Verifikasi sebelum menyatakan selesai:** Buka di geojson.io dan di peta aplikasi: batas tampil tepat di Kota Sorong. Skrip validasi lulus.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-12 - API GeoJSON peta: lokasi, area, detail, cari, statistik (PRD bagian 10)

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 4 - Peta Interaktif | **Modul:** API
- **Kompleksitas:** Tinggi | **Estimasi:** 6 jam | **FR terkait:** FR-17 s.d. 20, 04
- **Prasyarat langsung:** T-02, T-03, T-06
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-05, T-06
- **Acuan:** PRD bagian 10
- **Peran AI:** Senior backend engineer (desain API JSON/GeoJSON)

**Tugas:** Bangun api/lokasi.php, api/area.php, api/lokasi_detail.php, api/cari.php, api/statistik.php dengan helper api_boot() bersama.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-12 selesai):

- api/lokasi.php, area.php, lokasi_detail.php, cari.php, statistik.php
- Helper api_boot(); format error {"error": "..."}; ETag pada lokasi.php

**Spesifikasi teknis:**

- api_boot(): require_login, hanya GET, header JSON UTF-8, JSON_THROW_ON_ERROR + JSON_UNESCAPED_UNICODE, format error seragam {"error": "..."} dengan kode HTTP yang tepat.
- lokasi.php: FeatureCollection Point, filter status lewat whitelist. Koordinat [lng,lat] bertipe float (cast dari string DECIMAL). Properti ringan di daftar; detail di endpoint terpisah.
- ETag/If-None-Match berbasis MAX(updated_at) dan COUNT(*) agar peta tidak mengunduh ulang data yang sama (respons 304).
- lokasi_detail.php: kontraktor boleh melihat semua marker tetapi detail aktivitas dan foto hanya miliknya. Cari: q minimal 2 karakter, maksimal 10 hasil, LIKE ter-escape.
- statistik.php: satu query agregat GROUP BY status_izin, ter-scope menurut peran.

**Edge case dan risiko yang wajib ditangani:**

- area_geojson korup di database: lewati fitur itu dan catat log, jangan menjatuhkan seluruh respons.
- id non-numerik atau sangat besar. q berisi % atau _.
- Kebijakan popup untuk kontraktor (aktivitas lokasi orang lain): PRD bagian 5 FR-19 dan bagian 10 sedikit berbeda, ambil yang paling ketat dan catat sebagai Keputusan untuk dikonfirmasi.

**Kriteria selesai:** Skema respons sesuai PRD bagian 10. Kode HTTP 200/400/401/403/404 benar. Payload 100 lokasi di bawah 50 KB.

**Verifikasi sebelum menyatakan selesai:** curl setiap endpoint dengan sesi admin, sesi kontraktor, dan tanpa sesi. Bandingkan bentuk JSON dengan contoh PRD bagian 10.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-13 - Peta interaktif inti: map.js modular, marker per status, legenda, layer (FR-17, FR-18)

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 4 - Peta Interaktif | **Modul:** Peta / GIS
- **Kompleksitas:** Sangat Tinggi | **Estimasi:** 8 jam | **FR terkait:** FR-17, 18
- **Prasyarat langsung:** T-04, T-11, T-12
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12
- **Acuan:** PRD bagian 5 (FR-17, 18), 9
- **Peran AI:** Senior frontend / GIS engineer (Leaflet.js)

**Tugas:** Bangun assets/js/map.js dengan fungsi initWebGISMap(containerId, options) yang dipakai peta.php dan pratinjau dashboard, serta halaman peta.php.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-13 selesai):

- assets/js/map.js: initWebGISMap(containerId, options), konstanta STATUS_CONFIG
- peta.php dengan marker per status, legenda, layer batas kota dan area tambang

**Spesifikasi teknis:**

- Tile OpenStreetMap dengan attribution wajib, maxZoom 19, pusat Kota Sorong (-0.876, 131.255) zoom 12.
- Marker L.divIcon berbasis SVG, warna dari satu konstanta STATUS_CONFIG yang juga membangun legenda (L.control, kanan bawah). Bentuk marker berbeda per status agar tidak bergantung pada warna saja.
- Layer control: Batas Kota Sorong (garis tanpa fill yang menutupi marker) dan Area Tambang (poligon berwarna menurut status). Atur pane agar marker selalu di atas poligon.
- fitBounds ke semua marker; state loading dan error (toast) bila API gagal; invalidateSize saat sidebar di-toggle atau jendela di-resize.

**Edge case dan risiko yang wajib ditangani:**

- Nol lokasi: peta tetap tampil dengan pesan kosong.
- Dua marker dengan koordinat sama saling menutupi: tampilkan jumlah di popup atau offset visual kecil.
- Peta di dalam elemen tersembunyi tampil abu-abu (invalidateSize setelah dibuat terlihat).
- Poligon area besar menangkap klik yang seharusnya mengenai marker.

**Kriteria selesai:** FR-17 dan FR-18 lulus. Warna marker sesuai status, tanpa error console, saat muat hanya satu request lokasi dan satu request area.

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-17 dan FR-18 pada data seed (12 marker berwarna benar). Cek tab Network dan Console.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-14 - Popup detail lazy-load aman XSS + deep-link ?lokasi=ID (FR-19)

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 4 - Peta Interaktif | **Modul:** Peta / GIS
- **Kompleksitas:** Tinggi | **Estimasi:** 4 jam | **FR terkait:** FR-19
- **Prasyarat langsung:** T-13
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12, T-13
- **Acuan:** PRD bagian 5 (FR-19), 10
- **Peran AI:** Senior frontend engineer (keamanan UI, Leaflet)

**Tugas:** Tambahkan popup detail di map.js: data diambil saat marker diklik, dirender aman, dan bisa dibuka lewat URL.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-14 selesai):

- Popup lazy-load di map.js (AbortController, render via DOM API)
- Deep-link peta.php?lokasi=ID

**Spesifikasi teknis:**

- Klik marker menampilkan popup skeleton, lalu fetch api/lokasi_detail.php dengan AbortController (batalkan permintaan sebelumnya bila marker lain diklik).
- Render popup memakai DOM API (createElement dan textContent), tidak memakai innerHTML dari data. Foto thumbnail lazy dengan fallback bila gagal.
- Cache hasil per id dengan TTL 60 detik.
- Deep-link peta.php?lokasi=ID membuka popup dan zoom 16; dipakai halaman monitoring. Tombol Salin koordinat.

**Edge case dan risiko yang wajib ditangani:**

- Nama lokasi berisi <img src=x onerror=alert(1)> atau <script>.
- Sesi habis (401) saat popup diminta: arahkan ke login, bukan menampilkan JSON mentah.
- ID tidak ada atau bukan angka pada deep-link.

**Kriteria selesai:** Payload XSS pada nama lokasi tampil sebagai teks. Klik cepat berurutan tidak menampilkan data lokasi yang salah.

**Verifikasi sebelum menyatakan selesai:** Tambahkan lokasi dengan nama berisi payload XSS dan pastikan muncul sebagai teks. Klik 5 marker berurutan dengan cepat dan pastikan popup akhir sesuai marker terakhir.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-15 - Pencarian autocomplete + filter status client-side dengan state di URL (FR-20)

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 4 - Peta Interaktif | **Modul:** Peta / GIS
- **Kompleksitas:** Tinggi | **Estimasi:** 5 jam | **FR terkait:** FR-20
- **Prasyarat langsung:** T-13
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12, T-13
- **Acuan:** PRD bagian 5 (FR-20), 9
- **Peran AI:** Senior frontend engineer (UX pencarian, aksesibilitas)

**Tugas:** Bangun kotak pencarian dan filter status pada peta.php.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-15 selesai):

- Kotak pencarian autocomplete dan filter status di peta.php/map.js
- State filter di query string

**Spesifikasi teknis:**

- Input dengan debounce 250 ms, minimal 2 karakter, hasil dari api/cari.php, navigasi keyboard (panah, Enter, Esc), pola ARIA combobox/listbox. Memilih hasil memanggil flyTo dan membuka popup.
- Filter status (checkbox) bekerja di sisi klien pada layer yang sudah dimuat dan tersinkron dengan legenda. Kombinasi cari + filter bekerja bersama.
- State filter di query string (?status=berizin,proses) agar tautan bisa dibagikan; tampilkan 'N lokasi tampil'.

**Edge case dan risiko yang wajib ditangani:**

- Respons lama menimpa respons baru (race): pakai AbortController atau id permintaan.
- Hasil kosong dan input panjang.
- Filter menyembunyikan lokasi yang sedang popup terbuka.

**Kriteria selesai:** FR-20 lulus. Mengetik cepat tidak memicu lebih dari 1-2 request. Filter berjalan tanpa request ulang.

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-20. Ketik 'kla' dengan cepat dan hitung request di Network.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-16 - Benchmark & optimasi render peta terhadap target PRD bagian 11

- **Prioritas:** P3 - Rendah | **Iterasi:** Iterasi 4 - Peta Interaktif | **Modul:** Peta / GIS
- **Kompleksitas:** Sedang | **Estimasi:** 3 jam | **FR terkait:** PRD bagian 11
- **Prasyarat langsung:** T-13, T-15
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12, T-13, T-15
- **Acuan:** PRD bagian 11; skripsi bagian 2.2.11 (acuan performa Leaflet)
- **Peran AI:** Senior frontend performance engineer

**Tugas:** Ukur dan optimasi performa peta pada data sintetis yang lebih besar dari seed.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-16 selesai):

- database/seed_perf.sql (data sintetis, terpisah dari seed utama)
- Catatan hasil benchmark sebelum/sesudah

**Spesifikasi teknis:**

- Buat database/seed_perf.sql terpisah (150-300 lokasi sintetis); jangan mencemari seed utama.
- Ukur dengan performance.now(): waktu muat awal, render marker, respons filter. Laporkan angka sebelum dan sesudah.
- Jika lambat: preferCanvas, ikon SVG ringan (atau circleMarker), batching penambahan layer, hindari reflow berulang.

**Edge case dan risiko yang wajib ditangani:**

- Optimasi prematur: hanya ubah yang terbukti lambat.
- Hasil benchmark di laptop pengembang tidak sama dengan PC kantor Dinas: laporkan spesifikasi mesin uji.

**Kriteria selesai:** Muat awal kurang dari 2 detik, render sekitar 100 marker kurang dari 500 ms, respons filter kurang dari 100 ms; angka sebelum/sesudah dilaporkan.

**Verifikasi sebelum menyatakan selesai:** Tabel hasil ukur sebelum/sesudah ditampilkan dan target PRD tercapai atau alasan tidak tercapai dijelaskan.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-17 - Modul aktivitas: CRUD dengan guard kepemilikan anti-IDOR (FR-13, 15, 16)

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 5 - Aktivitas | **Modul:** Aktivitas
- **Kompleksitas:** Tinggi | **Estimasi:** 7 jam | **FR terkait:** FR-13, 15, 16
- **Prasyarat langsung:** T-06, T-09
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09
- **Acuan:** PRD bagian 2, 5 (FR-13, 15, 16), 7
- **Peran AI:** Senior backend engineer (otorisasi tingkat objek)

**Tugas:** Bangun modul aktivitas/ (index.php, form.php, hapus.php) untuk Kontraktor (milik sendiri) dan Admin (semua).

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-17 selesai):

- aktivitas/index.php, form.php, hapus.php
- Guard kepemilikan anti-IDOR pada semua aksi

**Spesifikasi teknis:**

- Dropdown lokasi hanya berisi lokasi milik kontraktor (admin: semua). Field read-only (kontraktor, koordinat, status izin) hanya tampilan: server mengambil ulang dari database dan tidak mempercayai input klien.
- material_id dan user_id ditetapkan server. Tanggal tidak boleh di masa depan. status_aktivitas divalidasi terhadap enum.
- Ubah hanya oleh pemilik atau admin lewat can_access_aktivitas(); hapus hanya admin. updated_at otomatis.
- Daftar dengan filter dan pagination. Simpan aktivitas dan foto dalam satu transaksi (koordinasi dengan tugas upload).

**Edge case dan risiko yang wajib ditangani:**

- Kontraktor mengubah lokasi_id pada request ke lokasi milik orang lain (IDOR): harus 403.
- Aktivitas ganda untuk lokasi dan tanggal yang sama: beri peringatan, jangan blokir.
- Lokasi dihapus saat form masih terbuka.
- Double submit.

**Kriteria selesai:** FR-13, 15, 16 lulus. Tiga skenario IDOR ditolak. Kontraktor A tidak dapat melihat atau mengubah aktivitas kontraktor B.

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-13, 15, 16. Uji tiga skenario IDOR secara manual (ubah ID di URL, ubah lokasi_id di POST, hapus ID orang lain).

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-18 - Unggah foto aman: validasi berlapis, re-encode GD, penyimpanan terkontrol (FR-14)

- **Prioritas:** P0 - Kritis | **Iterasi:** Iterasi 5 - Aktivitas | **Modul:** Aktivitas
- **Kompleksitas:** Sangat Tinggi | **Estimasi:** 8 jam | **FR terkait:** FR-14
- **Prasyarat langsung:** T-17
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-17
- **Acuan:** PRD bagian 5 (FR-14), 11
- **Peran AI:** Senior application security engineer (keamanan unggah berkas PHP)

**Tugas:** Implementasikan unggah foto dokumentasi aktivitas yang aman, termasuk pratinjau multi-file di klien.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-18 selesai):

- Modul unggah foto aman (validasi finfo, re-encode GD, thumbnail), uploads/aktivitas/YYYY/MM
- uploads/.htaccess (larang eksekusi PHP), skrip CLI pembersih file yatim
- Pratinjau multi-file di form aktivitas

**Spesifikasi teknis:**

- Aturan: JPG/PNG, maksimal 2 MB per foto, maksimal 5 foto per aktivitas (hitung foto lama saat ubah).
- Validasi server berlapis: kode error $_FILES lengkap, is_uploaded_file, ukuran, finfo MIME (bukan ekstensi atau Content-Type klien), getimagesize valid, dimensi maksimal (mis. 6000 px) dicek sebelum decode.
- Re-encode lewat GD (imagecreatefrom* lalu imagejpeg/imagepng) untuk membuang EXIF/GPS dan payload polyglot; perkecil sisi terpanjang menjadi 1600 px dan buat thumbnail 320 px.
- Nama file acak bin2hex(random_bytes(16)) dengan ekstensi dari MIME hasil validasi, disimpan di uploads/aktivitas/YYYY/MM. .htaccess di uploads/ melarang eksekusi PHP.
- Rollback file fisik bila transaksi DB gagal. Hapus foto menghapus baris DB dan file. Sediakan skrip CLI pembersih file yatim.

**Edge case dan risiko yang wajib ditangani:**

- Berkas ganda ekstensi (shell.php.jpg) dan file GIF/WebP yang menyamar.
- Gambar korup atau decompression bomb: batasi dimensi sebelum decode.
- upload_max_filesize atau post_max_size di php.ini lebih kecil dari batas aplikasi: tampilkan pesan yang jelas.
- Ekstensi GD tidak aktif: periksa saat bootstrap dan beri pesan instalasi.
- Disk penuh saat menulis.

**Kriteria selesai:** Shell.php, shell.php.jpg, JPG dengan PHP di EXIF, file 3 MB, dan 6 foto semuanya ditolak atau dibersihkan. EXIF GPS tidak ada pada file tersimpan.

**Verifikasi sebelum menyatakan selesai:** Kirim lima berkas uji berbahaya di atas lalu periksa hasil di disk. Periksa foto tersimpan dengan alat EXIF untuk memastikan GPS hilang.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-19 - Gerbang foto terproteksi dengan otorisasi + thumbnail (hardening opsional)

- **Prioritas:** P2 - Sedang | **Iterasi:** Iterasi 5 - Aktivitas | **Modul:** Aktivitas
- **Kompleksitas:** Tinggi | **Estimasi:** 5 jam | **FR terkait:** FR-14, 19
- **Prasyarat langsung:** T-18
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-17, T-18
- **Acuan:** PRD bagian 5 (FR-14, 19), 11
- **Peran AI:** Senior backend engineer (penyajian berkas dengan otorisasi)

**Tugas:** Tambahkan media.php yang menyajikan foto lewat PHP dengan pemeriksaan hak akses sebagai pengganti akses langsung ke uploads/. Jika mengubah PRD bagian 8, catat sebagai Keputusan.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-19 selesai):

- media.php?id=&size=thumb|full (cek otorisasi, 304)
- uploads/ ditutup dari akses langsung

**Spesifikasi teknis:**

- media.php?id=&size=thumb|full: cek login dan hak akses (kontraktor hanya foto aktivitasnya; admin semua), lalu stream dengan Content-Type benar.
- Cache-Control private, ETag, dukung If-None-Match (304). Folder uploads/ ditutup total (Require all denied).
- Selaraskan kebijakan dengan popup peta: tentukan apakah kontraktor boleh melihat thumbnail lokasi lain dan catat sebagai Keputusan.

**Edge case dan risiko yang wajib ditangani:**

- id foto tidak ada atau milik orang lain: respons seragam.
- File ada di DB tetapi hilang di disk.
- Path traversal pada parameter.

**Kriteria selesai:** Folder uploads/ tidak bisa diakses langsung. Foto hanya terkirim bila pengguna berhak. Mendukung 304 Not Modified.

**Verifikasi sebelum menyatakan selesai:** Akses langsung ke /uploads/... mendapat 403. Akses lewat media.php dengan sesi kontraktor lain mendapat 403/404.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-20 - Dashboard per peran: statistik teragregasi, pratinjau peta, tabel (FR-04 s.d. FR-06)

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 6 - Dashboard & Monitoring | **Modul:** Dashboard
- **Kompleksitas:** Sedang | **Estimasi:** 5 jam | **FR terkait:** FR-04, 05, 06
- **Prasyarat langsung:** T-12, T-13
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-11, T-12, T-13
- **Acuan:** PRD bagian 5 (FR-04 s.d. 06), 9
- **Peran AI:** Senior full-stack engineer

**Tugas:** Bangun dashboard.php dengan kartu statistik, pratinjau peta, dan tabel daftar lokasi.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-20 selesai):

- dashboard.php: kartu statistik, pratinjau peta (mode preview), tabel lokasi

**Spesifikasi teknis:**

- Satu query agregat GROUP BY status_izin (bukan empat query); kontraktor hanya menghitung lokasinya sendiri.
- Pratinjau peta memakai initWebGISMap dalam mode 'preview' (kontrol minimal, klik membuka peta.php?lokasi=ID), diinisialisasi malas dengan IntersectionObserver.
- Tabel 10 lokasi dengan badge status (teks + warna).

**Edge case dan risiko yang wajib ditangani:**

- Data kosong: kartu menampilkan 0 dan tabel menampilkan pesan kosong.
- Nilai status tidak terduga atau NULL.
- Peta pratinjau terhitung ukuran nol karena berada di kontainer yang belum terlihat.

**Kriteria selesai:** Angka kartu sama dengan hitungan manual di database (seed: total 12, berizin 7, tidak berizin 3, proses 2).

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-04, 05, 06. Bandingkan angka kartu dengan SELECT COUNT manual.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-21 - Halaman monitoring: query builder filter aman, pagination, deep-link ke peta (FR-21)

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 6 - Dashboard & Monitoring | **Modul:** Monitoring
- **Kompleksitas:** Tinggi | **Estimasi:** 6 jam | **FR terkait:** FR-21
- **Prasyarat langsung:** T-14, T-17
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17
- **Acuan:** PRD bagian 5 (FR-21)
- **Peran AI:** Senior backend engineer (query dinamis dan performa)

**Tugas:** Bangun monitoring.php (khusus Admin): daftar aktivitas terbaru semua lokasi dengan filter.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-21 selesai):

- monitoring.php: filter, pagination, tautan Lihat di Peta

**Spesifikasi teknis:**

- Filter: lokasi, kontraktor, status aktivitas, rentang tanggal (default 30 hari). Query dinamis memakai array kondisi + parameter terikat; kolom sort lewat whitelist.
- Satu query dengan JOIN lokasi, kontraktor, dan jumlah foto (subquery agregat) untuk menghindari N+1.
- Pagination 20 baris; filter tersimpan di query string; kolom Lihat di Peta mengarah ke peta.php?lokasi=ID.
- Opsional: badge 'tanpa aktivitas > 30 hari' pada lokasi, sebagai penanda kandidat pengawasan; catat sebagai Keputusan karena tidak ada di PRD.

**Edge case dan risiko yang wajib ditangani:**

- Rentang tanggal terbalik atau kosong.
- Sepuluh ribu baris: tetap harus terpaginasi.
- Kontraktor mengakses URL ini: 403.

**Kriteria selesai:** FR-21 lulus. EXPLAIN memakai idx_tanggal. Tidak ada pola N+1.

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-21. Hitung jumlah query per halaman lewat counter debug dan pastikan tidak tumbuh seiring jumlah baris.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-22 - Laporan monitoring per periode + layout cetak A4 profesional (FR-22)

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 7 - Laporan & Finishing | **Modul:** Laporan
- **Kompleksitas:** Tinggi | **Estimasi:** 6 jam | **FR terkait:** FR-22
- **Prasyarat langsung:** T-21
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-21
- **Acuan:** PRD bagian 5 (FR-22), 9
- **Peran AI:** Senior frontend / backend engineer (laporan cetak)

**Tugas:** Bangun laporan/index.php dan assets/css/print.css untuk laporan monitoring Dinas Perindustrian.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-22 selesai):

- laporan/index.php (filter periode, kop Dinas, tanda tangan)
- Aturan cetak A4 di print.css

**Spesifikasi teknis:**

- Filter periode (default bulan berjalan). Agregasi per lokasi: status izin, jumlah aktivitas dalam periode, tanggal dan status aktivitas terakhir.
- Kop: Laporan Monitoring Galian C, Dinas Perindustrian Kota Sorong, periode, tanggal cetak. Nama pejabat penandatangan dibaca dari config, bukan di-hardcode.
- print.css: @page A4, sembunyikan sidebar/topbar/tombol, thead berulang, break-inside: avoid pada baris, nomor halaman via counter(page), status ditulis sebagai teks agar terbaca tanpa warna.
- Tombol Cetak memanggil window.print(); PDF diperoleh lewat Print lalu Save as PDF.

**Edge case dan risiko yang wajib ditangani:**

- Periode tanpa data.
- Ratusan baris dan nama yang sangat panjang.
- Printer hitam-putih: badge status tidak boleh hanya dibedakan warna.
- Zona waktu pada tanggal cetak.

**Kriteria selesai:** Pratinjau cetak A4 rapi: header tabel berulang di tiap halaman, tanpa elemen navigasi, terbaca di hitam-putih.

**Verifikasi sebelum menyatakan selesai:** Acceptance FR-22. Buka print preview Chrome A4 dan periksa pemotongan halaman serta header berulang.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-23 - Ekspor CSV streaming aman dan ramah Excel Indonesia

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 7 - Laporan & Finishing | **Modul:** Laporan
- **Kompleksitas:** Sedang | **Estimasi:** 3 jam | **FR terkait:** FR-22
- **Prasyarat langsung:** T-22
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-21, T-22
- **Acuan:** PRD bagian 3, 5 (FR-22)
- **Peran AI:** Senior backend engineer

**Tugas:** Bangun laporan/unduh.php yang menghasilkan CSV dari data laporan periode.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-23 selesai):

- laporan/unduh.php (CSV streaming, BOM UTF-8, titik koma, proteksi CSV injection)

**Spesifikasi teknis:**

- Streaming dengan fputcsv() ke php://output sehingga memori konstan; BOM UTF-8; pemisah titik koma karena Excel berlokal Indonesia; nama file laporan-galian-c-YYYYMMDD.csv lewat Content-Disposition.
- Perlindungan CSV injection: sel yang diawali =, +, -, @, tab, atau CR diberi awalan apostrof.
- Tanggal format ISO, koordinat memakai titik desimal, header kolom bahasa Indonesia. Hanya Admin.

**Edge case dan risiko yang wajib ditangani:**

- Deskripsi berisi newline dan tanda kutip.
- Dataset besar (ribuan baris).
- Nilai =HYPERLINK(...) pada nama lokasi.

**Kriteria selesai:** File terbuka benar di Excel (kolom terpisah, huruf non-ASCII benar). Sel berawalan = + - @ tidak dieksekusi sebagai rumus.

**Verifikasi sebelum menyatakan selesai:** Buka hasil di Excel. Buat lokasi bernama =1+1 dan pastikan tampil sebagai teks.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-24 - Audit query dan indeks dengan EXPLAIN pada data berukuran realistis

- **Prioritas:** P2 - Sedang | **Iterasi:** Iterasi 7 - Laporan & Finishing | **Modul:** Database
- **Kompleksitas:** Sedang | **Estimasi:** 3 jam | **FR terkait:** PRD bagian 11
- **Prasyarat langsung:** T-22
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-21, T-22
- **Acuan:** PRD bagian 7, 11
- **Peran AI:** Senior database engineer (MySQL tuning)

**Tugas:** Audit performa query utama pada data 300 lokasi dan 5.000 aktivitas sintetis.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-24 selesai):

- docs/query-audit.md; indeks tambahan di database/schema.sql bila perlu

**Spesifikasi teknis:**

- Aktifkan slow query log di dev (long_query_time=0.1) dan jalankan EXPLAIN pada query: daftar lokasi, GeoJSON peta, detail lokasi, statistik, monitoring, laporan, pencarian, aktivitas per kontraktor.
- Hindari SELECT * dan fungsi pada kolom terindeks (contoh DATE(kolom)); tambah atau ubah indeks hanya bila terbukti perlu.
- Tulis docs/query-audit.md berupa tabel: query, rencana awal, rencana akhir, indeks yang dipakai.

**Edge case dan risiko yang wajib ditangani:**

- Indeks berlebihan memperlambat INSERT dan memakan ruang.
- LIKE '%kata%' tidak dapat memakai indeks B-tree biasa: nyatakan batas dan tindak lanjutnya.

**Kriteria selesai:** Delapan query utama diaudit, hasil sebelum/sesudah tertulis di docs/query-audit.md, tidak ada full table scan yang tidak perlu.

**Verifikasi sebelum menyatakan selesai:** Tabel audit terisi untuk 8 query dan setiap keputusan indeks dibenarkan dengan hasil EXPLAIN.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-25 - Responsif & aksesibilitas menyeluruh (mobile, sentuh, keyboard)

- **Prioritas:** P2 - Sedang | **Iterasi:** Iterasi 7 - Laporan & Finishing | **Modul:** Frontend
- **Kompleksitas:** Sedang | **Estimasi:** 5 jam | **FR terkait:** PRD bagian 9, 11
- **Prasyarat langsung:** T-04, T-10, T-13
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-10, T-11, T-12, T-13
- **Acuan:** PRD bagian 9, 11
- **Peran AI:** Senior frontend engineer (a11y dan responsif)

**Tugas:** Audit dan perbaiki responsivitas serta aksesibilitas seluruh halaman, termasuk peta.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-25 selesai):

- Perbaikan responsif dan aksesibilitas pada CSS dan JS seluruh halaman

**Spesifikasi teknis:**

- Uji di 360, 768, 1280 px. Peta memakai tinggi dinamis (dvh); kontrol Leaflet cukup besar untuk sentuhan (minimal 44 px).
- Tabel besar: overflow-x atau tampilan kartu di mobile. Fokus dikelola pada <dialog>. aria-live untuk pesan error form dan flash.
- Alt teks pada foto, prefers-reduced-motion, kontras warna status pada latar apa pun.

**Edge case dan risiko yang wajib ditangani:**

- Popup Leaflet melebihi layar kecil.
- Peta menangkap scroll di perangkat sentuh sehingga halaman tidak dapat digulir.
- Zoom browser 200% merusak layout.

**Kriteria selesai:** Alur login sampai input aktivitas dapat diselesaikan hanya dengan keyboard dan di layar 360 px.

**Verifikasi sebelum menyatakan selesai:** Lighthouse Accessibility minimal 90 di semua halaman utama. Uji keyboard-only dari login sampai simpan aktivitas.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-26 - Security review menyeluruh & hardening (OWASP, IDOR, konfigurasi produksi)

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 7 - Laporan & Finishing | **Modul:** Keamanan
- **Kompleksitas:** Tinggi | **Estimasi:** 6 jam | **FR terkait:** PRD bagian 11
- **Prasyarat langsung:** T-12, T-18, T-23
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-18, T-21, T-22, T-23
- **Acuan:** PRD bagian 3, 11
- **Peran AI:** Senior application security engineer

**Tugas:** Lakukan review keamanan internal atas seluruh kode (bukan pentest formal; security testing lanjutan di luar lingkup skripsi).

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-26 selesai):

- docs/security-checklist.md; konfigurasi produksi; akun default sudah diganti

**Spesifikasi teknis:**

- Matriks peran x endpoint/halaman: uji tiap sel (admin, kontraktor, tanpa login) untuk IDOR dan akses tidak berwenang.
- Pindai kode: grep query dengan variabel digabung (SQLi), echo tanpa e() (XSS), form POST tanpa csrf_field() (CSRF), field POST langsung masuk query (mass assignment), redirect dari input (open redirect).
- Konfigurasi produksi: expose_php=Off, display_errors=Off, session.cookie_secure=1, directory listing off, folder database/ dan storage/ tidak dapat diakses web.
- Ganti akun dan password default seed; pastikan tidak ada kredensial di repositori.

**Edge case dan risiko yang wajib ditangani:**

- Hardening bentrok dengan fungsi: uji ulang alur utama setelah setiap perubahan.
- Tidak mengklaim aplikasi 'aman sepenuhnya': tulis batasan review ini di dokumen.

**Kriteria selesai:** docs/security-checklist.md terisi (lulus/gagal + perbaikan). Matriks peran x endpoint teruji. Akun default diganti.

**Verifikasi sebelum menyatakan selesai:** Dokumen checklist terisi lengkap dengan bukti uji, dan semua temuan tingkat tinggi sudah diperbaiki.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-27 - Skrip uji otomatis black box: 11 kasus skripsi + kasus negatif + matriks keterlacakan

- **Prioritas:** P1 - Tinggi | **Iterasi:** Iterasi 7 - Laporan & Finishing | **Modul:** Pengujian
- **Kompleksitas:** Tinggi | **Estimasi:** 7 jam | **FR terkait:** Semua FR
- **Prasyarat langsung:** T-18, T-22, T-23, T-26
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-18, T-21, T-22, T-23, T-26
- **Acuan:** Skripsi Tabel 3.2; PRD bagian 12
- **Peran AI:** Senior QA automation engineer (PHP, cURL)

**Tugas:** Bangun tests/smoke.php (CLI) tanpa PHPUnit atau Composer yang memeriksa aplikasi lewat HTTP dengan cookie jar.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-27 selesai):

- tests/smoke.php (CLI), tests/report.md, matriks keterlacakan FR ke kasus uji

**Spesifikasi teknis:**

- 11 kasus dari PRD bagian 12 (login admin, kelola lokasi, kelola aktivitas, visualisasi peta, pencarian, monitoring, detail lokasi, update aktivitas, hapus lokasi, cetak laporan, logout) dengan assert helper dan keluaran PASS/FAIL per kasus.
- Kasus negatif: login salah, throttling, POST tanpa CSRF, kontraktor ke URL admin, IDOR aktivitas, unggah .php, koordinat tidak valid, nama lokasi berisi payload XSS.
- Database di-reset ke seed sebelum dijalankan; skrip menolak berjalan bila APP_ENV bukan dev.
- Tulis tests/report.md dengan kolom: ID kasus, skenario, hasil diharapkan, hasil aktual, FR terkait. Sertakan matriks keterlacakan FR ke kasus uji. Exit code non-zero bila ada yang gagal.

**Edge case dan risiko yang wajib ditangani:**

- Tes bergantung pada urutan atau data sisa dari tes lain: tiap kasus harus mandiri.
- Token CSRF harus diambil dari halaman sebelum POST.
- Skrip tidak boleh menghapus data pada database produksi.

**Kriteria selesai:** Semua 11 kasus Tabel 3.2 skripsi PASS. Laporan tests/report.md siap dipindahkan ke Bab 4 skripsi.

**Verifikasi sebelum menyatakan selesai:** Jalankan dua kali berturut-turut dengan hasil sama. Rusak satu fitur sengaja dan pastikan kasusnya gagal.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-28 - Tool impor data survei lapangan (CSV ke lokasi_tambang) dengan validasi & deteksi duplikat

- **Prioritas:** P2 - Sedang | **Iterasi:** Iterasi 7 - Laporan & Finishing | **Modul:** Data
- **Kompleksitas:** Tinggi | **Estimasi:** 5 jam | **FR terkait:** FR-07
- **Prasyarat langsung:** T-07, T-09
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09
- **Acuan:** Skripsi bagian 3.2.4-3.2.5 (koordinat hasil survei lapangan); PRD bagian 7
- **Peran AI:** Senior data engineer (ETL, data geospasial)

**Tugas:** Bangun database/import_survei.php (CLI) untuk memasukkan koordinat hasil survei ke tabel lokasi_tambang.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-28 selesai):

- database/import_survei.php (CLI, dry-run default, errors.csv)

**Spesifikasi teknis:**

- Mode --dry-run sebagai default; --commit untuk menyimpan. Pemetaan kolom CSV yang dapat dikonfigurasi.
- Normalisasi koordinat: koma desimal, format derajat-menit-detik (DMS) ke desimal, validasi bounding box Kota Sorong.
- Deteksi duplikat dengan jarak haversine kurang dari 20 m dan kemiripan nama; kunci idempoten dari nama + koordinat dibulatkan 5 desimal.
- Kontraktor dicocokkan dari nama atau dibuat baru bila --create-kontraktor. Transaksi per batch. Baris ditolak ditulis ke errors.csv beserta alasan.

**Edge case dan risiko yang wajib ditangani:**

- Lat/lng tertukar (lat Sorong sekitar -0.8 dan lng sekitar 131.2): deteksi dan tawarkan koreksi.
- Encoding Windows-1252, BOM, dan pemisah titik koma.
- Impor sebagian gagal di tengah file.

**Kriteria selesai:** Dry-run menjadi default. Baris ditolak keluar ke errors.csv. Impor dapat diulang tanpa menggandakan data.

**Verifikasi sebelum menyatakan selesai:** Impor file contoh 30 baris berisi 5 kesalahan sengaja: tepat 5 baris masuk errors.csv, impor kedua tidak menambah baris.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---

### T-29 - Dokumentasi, panduan deployment, skrip backup, dan catatan keputusan

- **Prioritas:** P2 - Sedang | **Iterasi:** Iterasi 7 - Laporan & Finishing | **Modul:** Dokumentasi
- **Kompleksitas:** Sedang | **Estimasi:** 4 jam | **FR terkait:** Semua
- **Prasyarat langsung:** T-26
- **Seluruh rantai prasyarat:** T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08, T-09, T-11, T-12, T-13, T-14, T-17, T-18, T-21, T-22, T-23, T-26
- **Acuan:** PRD bagian 3, 8
- **Peran AI:** Senior engineer dengan keterampilan technical writing

**Tugas:** Tulis README.md, docs/ (ERD, keputusan, deployment), dan skrip backup.

**Artefak setelah selesai** (yang boleh diasumsikan ada bila pengguna menyebut T-29 selesai):

- README.md, docs/deployment.md, docs/keputusan.md, docs/erd.md
- database/backup.sh dan backup.bat

**Spesifikasi teknis:**

- README: prasyarat (PHP >= 8.0 dengan pdo_mysql, gd, fileinfo, mbstring), instalasi XAMPP langkah demi langkah, config.local.php, impor database, akun awal dan kewajiban mengganti password, struktur folder.
- docs/deployment.md: shared hosting (document root, izin file 644/755, uploads/ hanya dapat ditulis, database/ dan storage/ diblokir), checklist go-live, troubleshooting (tile tidak muncul, upload gagal karena php.ini, session tidak jalan).
- database/backup.sh dan backup.bat: mysqldump + arsip uploads/, retensi 7 hari; sertakan langkah restore dan buktikan restore berhasil.
- docs/keputusan.md: kumpulkan semua 'Keputusan' di luar PRD yang dibuat selama pengembangan dengan alasan. docs/erd.md berisi ERD teks (Mermaid).

**Edge case dan risiko yang wajib ditangani:**

- Instruksi yang mengasumsikan Linux padahal pengguna memakai Windows/XAMPP.
- Backup berisi kredensial: jangan disimpan di dalam document root.

**Kriteria selesai:** Orang baru dapat memasang aplikasi dari nol hanya dengan README. Backup dan restore teruji.

**Verifikasi sebelum menyatakan selesai:** Minta orang yang belum pernah melihat proyek mengikuti README sampai aplikasi berjalan, catat langkah yang membingungkan, lalu perbaiki.

**Format keluaran:** kode lengkap per berkas (path lalu isi, tanpa `...`), lalu langkah uji manual, daftar Keputusan di luar PRD, dan risiko tersisa. Setelah itu berhenti dan tunggu konfirmasi.

---
