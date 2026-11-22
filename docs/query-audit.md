# Audit Query & Indeks (T-24)

Acuan: PRD bagian 7 (desain database), 11 (performa). Prasyarat: T-22.

## 1. Metode

Delapan query utama diaudit dengan `EXPLAIN` MySQL terhadap data **realistis**:
300 lokasi + 5.000 aktivitas sintetis (dibangkitkan lewat `WITH RECURSIVE`,
dihapus lagi setelah audit selesai — **bukan** artefak permanen, lihat bagian
5 "Keputusan") ditambah 12 lokasi/36 aktivitas seed asli. Total saat audit:
**312 lokasi, 5.036 aktivitas**.

Slow query log diaktifkan sementara (`SET GLOBAL slow_query_log='ON';
SET GLOBAL long_query_time=0.1;`) selama seluruh halaman aplikasi (bukan
hanya query mentah) dijalankan lewat `curl` terhadap data di atas. **Tidak
ada satu query pun yang tercatat** — file log hanya berisi header startup
MySQL. Ini bukan berarti semua query sudah optimal (lihat Q5 di bawah, yang
melakukan full table scan TAPI masih selesai &lt;100ms pada skala ini); ini
berarti **belum ada yang bisa diukur lambat PADA SKALA SAAT INI** — nilai
`EXPLAIN` (rows diperiksa, tipe akses) dipakai sebagai sinyal awal yang jauh
lebih sensitif terhadap pertumbuhan data di masa depan daripada stopwatch
pada dataset yang masih kecil.

Format `EXPLAIN` pada MySQL 9.6 (server uji) adalah **tree format**
(cost-based, menampilkan langkah akses sebagai teks terstruktur), bukan tabel
klasik `id/select_type/table/type/key/rows/Extra` dari versi MySQL lama.
Kolom "Indeks yang dipakai" pada tabel bagian 2 dibaca langsung dari baris
`Index lookup on ... using <nama_indeks>` / `Covering index ... using
<nama_indeks>` / `Table scan` pada keluaran tersebut.

## 2. Tabel ringkas (8 query)

| # | Query (sumber) | Rencana awal | Rencana akhir | Indeks dipakai |
| - | --- | --- | --- | --- |
| Q1 | Daftar lokasi (`repo_lokasi_list()`, `lokasi/index.php`, filter status) | Index lookup status→nested loop join→**Temporary table + table scan** untuk ORDER BY (109 baris materialized sebelum sort, akibat `SELECT l.*` memuat `area_geojson`/TEXT lebar) | Index lookup status→nested loop join→**Stream results** (sort langsung tanpa materialisasi, baris lebih ramping) | `idx_status` |
| Q2 | GeoJSON peta (`repo_lokasi_list_for_map()`, `api/lokasi.php`) | Index lookup status; subquery `MAX(tanggal)` per lokasi = **covering index** | Tidak berubah (sudah optimal sejak T-12) | `idx_status`; subquery: `idx_lokasi_tanggal` (covering) |
| Q3 | Detail lokasi (`repo_lokasi_find()` + `repo_aktivitas_latest_by_lokasi()`, `api/lokasi_detail.php`) | PK const lookup (lokasi); index lookup **reverse** (5 aktivitas terakhir, tanpa sort tambahan) | Tidak berubah (sudah optimal sejak T-03/T-12) | `PRIMARY`; `idx_lokasi_tanggal` |
| Q4 | Statistik (`repo_lokasi_count_by_status()`, dashboard/`api/statistik.php`) | Admin: **covering index scan**. Kontraktor: index lookup + agregat kecil | Tidak berubah (sudah optimal) | `idx_status` (admin); `idx_kontraktor` (kontraktor) |
| Q5 | Monitoring (`repo_aktivitas_list_for_monitoring()`, `monitoring.php`, filter RENTANG TANGGAL SAJA) | **Table scan penuh** `aktivitas_tambang` (rows≈4.867) lalu `Filter` tanggal — `idx_lokasi_tanggal` TIDAK terpakai (lokasi_id, kolom kiri komposit, tidak ada di WHERE) | **Index range scan** `idx_tanggal` (reverse, rows≈445) — langsung ke rentang tanggal, tanpa menyentuh baris di luar rentang | **BARU:** `idx_tanggal (tanggal_aktivitas)` |
| Q6 | Laporan (`repo_lokasi_list_for_laporan()`, `laporan/index.php`, 3 subquery per periode) | Nested loop kontraktor→lokasi; 3 subquery korelasi = **covering index** / index condition pushdown | Tidak berubah (sudah optimal sejak T-22) | `idx_kontraktor`; subquery: `idx_lokasi_tanggal` (×3) |
| Q7 | Pencarian (`repo_lokasi_list()` filter `search`, `api/cari.php`, `LIKE '%kata%'`) | **Table scan penuh** `lokasi_tambang` (rows=312) + filter LIKE | **Tidak berubah** — keterbatasan inheren, lihat bagian 3 | *(tidak ada — lihat bagian 3)* |
| Q8 | Aktivitas per kontraktor (`repo_aktivitas_list()`, `aktivitas/index.php`, kontraktor login) | Index lookup kontraktor→index lookup tanggal (per lokasi)→**Sort** eksplisit lintas-lokasi (rows≈1.622) | Plan identik (hanya kolom `SELECT` dipangkas — lihat bagian 4) | `idx_kontraktor`; `idx_lokasi_tanggal` |

Detail lengkap keluaran `EXPLAIN` "sebelum" dan "sesudah" untuk tiap query ada
di bagian 6 (lampiran).

## 3. LIKE `%kata%` — batas & tindak lanjut (edge case wajib T-24)

**Batas:** indeks B-tree biasa (termasuk `idx_status`/`PRIMARY`/dll. di
`lokasi_tambang`) menyimpan nilai dalam urutan leksikografis. `LIKE
'%kata%'` punya wildcard di KEDUA ujung, sehingga MySQL tidak bisa menentukan
titik awal pencarian di B-tree — satu-satunya cara memastikan semua
kecocokan ditemukan adalah memeriksa **setiap baris** (`Table scan`). Ini
berlaku untuk SEMUA mesin database relasional ber-B-tree, bukan kekhususan
MySQL atau kesalahan desain query di sini.

**Dampak pada skala saat ini:** `Table scan on l (rows=312)` berbiaya
`cost=32.2` menurut optimizer — di bawah ambang yang bisa terukur di slow
log (0,1 detik) bahkan jauh di bawahnya. `lokasi_tambang` dibatasi secara
GEOGRAFIS (satu kota), bukan dibatasi jumlah seperti `aktivitas_tambang` yang
terus bertambah selama aplikasi dipakai — realistis tidak akan pernah
mencapai ribuan baris.

**Tindak lanjut bila suatu saat terbukti perlu** (BUKAN dilakukan sekarang,
karena "tambah indeks HANYA bila terbukti perlu" — lihat bagian 5): indeks
`FULLTEXT` InnoDB bawaan MySQL (tanpa ekstensi/stack tambahan, masih sesuai
batasan PRD bagian 3) pada `nama_lokasi`/`kelurahan`, dipicu memakai sintaks
`MATCH ... AGAINST` bukan `LIKE`. Ambang yang disarankan untuk
mempertimbangkan ini: `lokasi_tambang` melewati ~5.000–10.000 baris DAN
`Table scan` mulai muncul di slow query log pengukuran produksi sungguhan.

## 4. Perubahan kode (bukan hanya indeks)

Dua dari delapan query memakai `SELECT l.*` / `SELECT a.*` (Q1, Q8) —
melanggar spesifikasi T-24 "hindari SELECT *". Diperiksa SATU PER SATU
pemanggil masing-masing fungsi (grep `$row[...]` di setiap halaman yang
memanggilnya) untuk memastikan kolom yang dipangkas benar-benar tidak
terbaca di mana pun sebelum dipangkas:

- `repo_lokasi_list()` (Q1, dipakai `lokasi/index.php`, `dashboard.php`,
  `aktivitas/index.php`, `monitoring.php`, `api/cari.php`): dipangkas ke 7
  kolom asli + 2 kolom JOIN. **Dibuang**: `distrik`, `alamat` (TEXT),
  `area_geojson` (LONGTEXT — bisa besar per poligon), `luas_ha`,
  `nomor_izin`, `tanggal_berlaku_izin`, `keterangan` (TEXT), `kontraktor_id`,
  `material_id`, `created_by`, `created_at`, `updated_at` — tidak satu pun
  dipakai pemanggil manapun.
- `repo_aktivitas_list()` (Q8, dipakai `aktivitas/index.php`): dipangkas ke 4
  kolom asli + 3 kolom JOIN. **Dibuang**: `lokasi_id`, `user_id`,
  `material_id`, `jumlah_alat_berat`, `jumlah_pekerja`, `deskripsi` (TEXT,
  hingga 2.000 karakter), `created_at`, `updated_at`, serta `lt.kontraktor_id`
  yang sebelumnya ikut di-`SELECT` padahal hanya dipakai di `WHERE` (JOIN),
  tidak pernah dibaca dari hasil baris.

**Efek pada `EXPLAIN`:** TIDAK mengubah baris/tipe akses untuk Q8 (plan
identik — trim kolom tidak memengaruhi jalur WHERE/JOIN/ORDER BY). **Q1
JUSTRU berubah strategi sort**: sebelum dipangkas, optimizer memilih
`Temporary table` + `Table scan on <temporary>` untuk ORDER BY (baris lebar
dengan LONGTEXT dimaterialisasi dulu); sesudah dipangkas, `Stream results`
langsung tanpa materialisasi — bukti nyata di `EXPLAIN`, bukan hanya teori
"kolom lebih sedikit = lebih hemat bandwidth".

`repo_lokasi_find()`, `repo_kontraktor_find()`, `repo_user_find*()`, dan
fungsi `_find()` satu-baris lainnya **SENGAJA TIDAK diubah** — semuanya
dipakai untuk mengisi FORM UBAH yang memang butuh semua kolom, dan biaya
`SELECT *` pada SATU baris (bukan daftar berpaginasi) tidak berlipat seiring
jumlah baris seperti Q1/Q8. Tidak termasuk cakupan audit delapan query ini.

Tidak ditemukan satu pun pola `WHERE DATE(kolom) = ...` / `YEAR(kolom)` /
fungsi lain yang membungkus kolom terindeks pada klausa `WHERE` di seluruh
`includes/repo/*.php` (diperiksa lewat grep) — seluruh filter tanggal di
proyek ini sudah memakai perbandingan langsung (`>=`, `<=`, `BETWEEN`) yang
bisa memakai range scan indeks B-tree secara penuh.

## 5. Keputusan di luar PRD

1. **Tambah `idx_tanggal (tanggal_aktivitas)` di `aktivitas_tambang`**
   (`database/schema.sql`). Dibenarkan oleh EXPLAIN: Q5 (Monitoring, filter
   rentang tanggal TANPA `lokasi_id`) berubah dari `Table scan` (rows≈4.867,
   SELURUH tabel) menjadi `Index range scan` (rows≈445, hanya baris dalam
   rentang) — lihat bagian 6.5 untuk keluaran lengkap. **Trade-off yang
   diterima** (edge case wajib T-24 "indeks berlebihan memperlambat INSERT"):
   tabel ini sekarang punya 5 indeks (PRIMARY + 2 FK + `idx_lokasi_tanggal` +
   `idx_tanggal`) — diterima karena indeks baru SATU KOLOM (murah dipelihara
   dibanding komposit lebar) dan volume tulis tabel ini rendah (entri manual
   kontraktor, bukan beban OLTP tinggi).
2. **TIDAK menambah indeks komposit `(kontraktor_id, status_izin)` di
   `lokasi_tambang`** untuk Q4b (statistik ter-scope kontraktor). Dipikirkan,
   lalu DITOLAK: `EXPLAIN` menunjukkan `cost=13.4` untuk ~104 baris via
   `idx_kontraktor` yang sudah ada — perbaikan marjinal (menghindari satu
   lookup balik ke baris dasar per baris) tidak sepadan dengan indeks
   tambahan di tabel yang sering di-UPDATE (status izin berubah).
3. **TIDAK menambah `FULLTEXT` index untuk pencarian** (Q7) sekarang —
   ditolak karena "tambah indeks HANYA bila terbukti perlu" dan skala
   `lokasi_tambang` (dibatasi geografis) tidak pernah realistis mendekati
   ambang di mana `LIKE '%...%'` jadi masalah terukur. Lihat bagian 3 untuk
   ambang & tindak lanjut bila situasi berubah.
4. **Data sintetis audit (300 lokasi + 5.000 aktivitas) TIDAK disimpan
   sebagai skrip seed permanen** di `database/` — berbeda dari
   `database/seed_perf.sql` (T-16) yang memang artefak permanen untuk
   benchmark peta berulang. Daftar artefak T-24 di `docs/todolist.md` hanya
   menyebut `docs/query-audit.md` + indeks di `schema.sql`, jadi skrip
   pembangkit data diperlakukan sebagai alat bantu audit sekali pakai
   (dijalankan, dipakai, dihapus lagi — database dev sudah dikembalikan ke
   12 lokasi/36 aktivitas semula, diverifikasi lewat `tests/db_smoke.php`).
5. **Perubahan `SELECT *` dibatasi HANYA ke dua fungsi LIST yang termasuk
   delapan query audit** (`repo_lokasi_list()`, `repo_aktivitas_list()`),
   TIDAK ke fungsi `_find()` satu-baris lain yang memakai `SELECT *` secara
   sah untuk form ubah — lihat penjelasan di bagian 4.

## 6. Risiko tersisa

- **Q7 (pencarian) tetap full table scan** by design (lihat bagian 3) —
  bukan bug, tapi tetap risiko laten jika asumsi "`lokasi_tambang` dibatasi
  geografis" ternyata salah (mis. aplikasi diperluas ke kota lain di masa
  depan). Ambang & tindak lanjutnya sudah didokumentasikan.
- **Pengukuran waktu nyata (bukan hanya `EXPLAIN`/slow log) belum dilakukan
  di browser sungguhan** — sesi ini tidak punya akses Chrome (sama seperti
  risiko tersisa T-16). Semua bukti di dokumen ini berasal dari `EXPLAIN`
  (estimasi optimizer) dan slow query log (observasi nyata tapi dataset uji
  masih "hanya" puluhan ribu baris, bukan skala produksi bertahun-tahun).
- **`idx_tanggal` baru hanya diuji pada data SINTETIS** (distribusi tanggal
  rata/seragam 365 hari terakhir) — distribusi tanggal aktivitas NYATA
  (mis. terkonsentrasi di hari kerja, atau berpola musiman) bisa memberi
  selektivitas indeks yang sedikit berbeda, meski arah perbaikannya (range
  scan vs full scan) tidak akan berubah.
- Audit ini TIDAK mencakup query di luar 8 yang diminta (mis. `kontraktor/
  index.php`, `laporan/unduh.php` dari T-23) — di luar cakupan task, bisa
  jadi kandidat audit lanjutan bila ada keluhan performa nyata di modul itu.

## 7. Lampiran — keluaran `EXPLAIN` lengkap (sebelum & sesudah)

### Q1 — Daftar lokasi

**Sebelum** (`SELECT l.*, ...`):

```
-> Limit: 10 row(s)
    -> Sort row IDs: l.nama_lokasi, limit input to 10 row(s) per chunk
        -> Table scan on <temporary>  (cost=108..112 rows=107)
            -> Temporary table  (cost=108..108 rows=107)
                -> Nested loop inner join  (cost=83.7 rows=107)
                    -> Inner hash join (k.id = l.kontraktor_id)  (cost=46.3 rows=107)
                        -> Table scan on k  (cost=0.00527 rows=3)
                        -> Hash
                            -> Index lookup on l using idx_status (status_izin = 'berizin')  (cost=13.7 rows=107)
                    -> Single-row index lookup on m using PRIMARY (id = l.material_id)  (cost=0.251 rows=1)
```

**Sesudah** (kolom eksplisit):

```
-> Limit: 10 row(s)
    -> Sort: l.nama_lokasi, limit input to 10 row(s) per chunk
        -> Stream results  (cost=83.6 rows=107)
            -> Nested loop inner join  (cost=83.6 rows=107)
                -> Inner hash join (k.id = l.kontraktor_id)  (cost=46.2 rows=107)
                    -> Table scan on k  (cost=0.00426 rows=3)
                    -> Hash
                        -> Index lookup on l using idx_status (status_izin = 'berizin')  (cost=13.7 rows=107)
                -> Single-row index lookup on m using PRIMARY (id = l.material_id)  (cost=0.251 rows=1)
```

### Q2 — GeoJSON peta

```
-> Sort: l.id
    -> Stream results  (cost=83.6 rows=107)
        -> Nested loop inner join  (cost=83.6 rows=107)
            -> Inner hash join (k.id = l.kontraktor_id)  (cost=46.1 rows=107)
                -> Table scan on k  (cost=0.00387 rows=3)
                -> Hash
                    -> Filter: (l.material_id is not null)  (cost=13.7 rows=107)
                        -> Index lookup on l using idx_status (status_izin = 'berizin')  (cost=13.7 rows=107)
            -> Single-row index lookup on m using PRIMARY (id = l.material_id)  (cost=0.251 rows=1)
-> Select #2 (subquery in projection; dependent)
    -> Aggregate: max(a.tanggal_aktivitas)  (cost=5.41 rows=1)
        -> Covering index lookup on a using idx_lokasi_tanggal (lokasi_id = l.id)  (cost=1.81 rows=15.6)
```

Tidak berubah sebelum/sesudah — sudah optimal sejak T-12, tidak disentuh T-24.

### Q3 — Detail lokasi

```
-- Q3a: lokasi by id
-> Rows fetched before execution  (cost=0..0 rows=1)

-- Q3b: 5 aktivitas terakhir per lokasi
-> Limit: 5 row(s)  (cost=6.65 rows=5)
    -> Index lookup on aktivitas_tambang using idx_lokasi_tanggal (lokasi_id = 5) (reverse)  (cost=6.65 rows=19)
```

Tidak berubah — sudah optimal sejak T-03/T-12, tidak disentuh T-24.

### Q4 — Statistik

```
-- Q4a: admin (semua lokasi)
-> Group aggregate: count(0)  (cost=104 rows=3)
    -> Covering index scan on lokasi_tambang using idx_status  (cost=32.2 rows=312)

-- Q4b: kontraktor (ter-scope)
-> Table scan on <temporary>
    -> Aggregate using temporary table
        -> Index lookup on lokasi_tambang using idx_kontraktor (kontraktor_id = 1)  (cost=13.4 rows=104)
```

Tidak berubah — ditolak menambah indeks (lihat Keputusan #2).

### Q5 — Monitoring (perubahan utama audit ini)

**Sebelum** (hanya `idx_lokasi_tanggal`):

```
-> Limit: 20 row(s)
    -> Sort: a.tanggal_aktivitas DESC, limit input to 20 row(s) per chunk
        -> Stream results  (cost=847 rows=541)
            -> Inner hash join (k.id = lt.kontraktor_id)  (cost=847 rows=541)
                -> Table scan on k  (cost=0.00163 rows=3)
                -> Hash
                    -> Nested loop inner join  (cost=684 rows=541)
                        -> Filter: ((a.tanggal_aktivitas >= DATE'2026-10-23') and (a.tanggal_aktivitas <= DATE'2026-11-22'))  (cost=495 rows=541)
                            -> Table scan on a  (cost=495 rows=4867)
                        -> Single-row index lookup on lt using PRIMARY (id = a.lokasi_id)  (cost=0.25 rows=1)
-> Select #2 (subquery in projection; dependent)
    -> Aggregate: count(0)  (cost=0.58 rows=1)
        -> Covering index lookup on f using aktivitas_id (aktivitas_id = a.id)  (cost=0.35 rows=1)
-> Select #3 (subquery in projection; dependent)
    -> Aggregate: max(a2.tanggal_aktivitas)  (cost=5.41 rows=1)
        -> Covering index lookup on a2 using idx_lokasi_tanggal (lokasi_id = lt.id)  (cost=1.81 rows=15.6)
```

**Sesudah** (+ `idx_tanggal`):

```
-> Limit: 20 row(s)
    -> Sort: a.tanggal_aktivitas DESC, limit input to 20 row(s) per chunk
        -> Stream results  (cost=490 rows=445)
            -> Inner hash join (k.id = lt.kontraktor_id)  (cost=490 rows=445)
                -> Table scan on k  (cost=0.00176 rows=3)
                -> Hash
                    -> Nested loop inner join  (cost=356 rows=445)
                        -> Index range scan on a using idx_tanggal over ('2026-10-23' <= tanggal_aktivitas <= '2026-11-22') (reverse), with index condition: ((a.tanggal_aktivitas >= DATE'2026-10-23') and (a.tanggal_aktivitas <= DATE'2026-11-22'))  (cost=201 rows=445)
                        -> Single-row index lookup on lt using PRIMARY (id = a.lokasi_id)  (cost=0.25 rows=1)
-> Select #2 ... (tidak berubah)
-> Select #3 ... (tidak berubah)
```

`Table scan on a (rows=4867)` → `Index range scan ... idx_tanggal (rows=445)`:
dari memeriksa SELURUH tabel menjadi hanya baris dalam rentang tanggal.

### Q6 — Laporan periode

```
-> Sort: lt.nama_lokasi
    -> Stream results  (cost=40.8 rows=312)
        -> Nested loop inner join  (cost=40.8 rows=312)
            -> Table scan on k  (cost=0.55 rows=3)
            -> Index lookup on lt using idx_kontraktor (kontraktor_id = k.id)  (cost=6.47 rows=104)
-> Select #2 (subquery in projection; dependent)
    -> Aggregate: count(0)  (cost=0.828 rows=1)
        -> Filter: (a.tanggal_aktivitas between '2026-11-01' and '2026-11-30')  (cost=0.428 rows=1.73)
            -> Covering index lookup on a using idx_lokasi_tanggal (lokasi_id = lt.id)  (cost=0.428 rows=15.6)
-> Select #3 (subquery in projection; dependent)
    -> Aggregate: max(a.tanggal_aktivitas)  (cost=0.828 rows=1)
        -> Filter: (a.tanggal_aktivitas between '2026-11-01' and '2026-11-30')  (cost=0.428 rows=1.73)
            -> Covering index lookup on a using idx_lokasi_tanggal (lokasi_id = lt.id)  (cost=0.428 rows=15.6)
-> Select #4 (subquery in projection; dependent)
    -> Limit: 1 row(s)  (cost=4.07 rows=1)
        -> Sort: a.tanggal_aktivitas DESC, a.id DESC, limit input to 1 row(s) per chunk  (cost=4.07 rows=15.6)
            -> Index lookup on a using idx_lokasi_tanggal (lokasi_id = lt.id), with index condition: (a.tanggal_aktivitas between '2026-11-01' and '2026-11-30')  (cost=4.07 rows=15.6)
```

Tidak berubah — sudah optimal sejak T-22, tidak disentuh T-24.

### Q7 — Pencarian

```
-> Limit: 10 row(s)
    -> Sort row IDs: l.nama_lokasi, limit input to 10 row(s) per chunk
        -> Table scan on <temporary>  (cost=90.3..93.5 rows=65.5)
            -> Temporary table  (cost=90.2..90.2 rows=65.5)
                -> Nested loop inner join  (cost=75.1 rows=65.5)
                    -> Inner hash join (k.id = l.kontraktor_id)  (cost=52.2 rows=65.5)
                        -> Table scan on k  (cost=0.00735 rows=3)
                        -> Hash
                            -> Filter: ((l.nama_lokasi like '%klas%') or (l.kelurahan like '%klas%'))  (cost=32.2 rows=65.5)
                                -> Table scan on l  (cost=32.2 rows=312)
                    -> Single-row index lookup on m using PRIMARY (id = l.material_id)  (cost=0.252 rows=1)
```

Tidak berubah — keterbatasan inheren `LIKE '%...%'`, lihat bagian 3. (Catatan:
keluaran ini direkam SEBELUM kolom `SELECT` Q1/Q7 — fungsi yang sama,
`repo_lokasi_list()` — dipangkas; pola akses WHERE/JOIN-nya tidak berubah
oleh pemangkasan kolom, hanya strategi sort-nya seperti Q1.)

### Q8 — Aktivitas per kontraktor

```
-> Limit: 10 row(s)
    -> Sort: a.tanggal_aktivitas DESC, limit input to 10 row(s) per chunk
        -> Stream results  (cost=1717 rows=1622)
            -> Nested loop inner join  (cost=1717 rows=1622)
                -> Nested loop inner join  (cost=1149 rows=1622)
                    -> Nested loop inner join  (cost=581 rows=1622)
                        -> Index lookup on lt using idx_kontraktor (kontraktor_id = 1)  (cost=13.4 rows=104)
                        -> Index lookup on a using idx_lokasi_tanggal (lokasi_id = lt.id)  (cost=3.91 rows=15.6)
                    -> Single-row index lookup on u using PRIMARY (id = a.user_id)  (cost=0.25 rows=1)
                -> Single-row index lookup on m using PRIMARY (id = a.material_id)  (cost=0.25 rows=1)
```

Identik sebelum/sesudah pemangkasan kolom `SELECT` (lihat bagian 4) — hanya
byte yang ditransfer per baris yang berubah, bukan jalur aksesnya.
