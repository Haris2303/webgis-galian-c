# Review Keamanan Internal (T-26)

Acuan: PRD bagian 3, 11. **Ini BUKAN pentest formal** — security testing lanjutan
di luar lingkup skripsi (PRD bagian 4). Lihat bagian 6 "Batasan review ini".

## 1. Matriks peran × halaman/endpoint

Diuji nyata lewat `curl` terhadap tiga aktor: tanpa sesi, `kontraktor1`
(peran kontraktor), `admin`. Semua 23 halaman/endpoint diuji; hanya dua sel
yang tampak seperti bug tapi TERBUKTI benar sesuai desain:

| Sel | Hasil | Status |
| --- | --- | --- |
| `aktivitas/form.php` (tanpa `?id=`) oleh **admin** | 403 | **Benar** — FR-13: hanya kontraktor yang boleh MEMBUAT aktivitas baru; admin tetap bisa UBAH lewat `?id=` (diuji: 200). |
| `media.php?id=1` | 404 kedua peran | **Benar** — id=1 tidak ada di `foto_aktivitas` seed (id asli mulai ~43); bukan bug, id uji salah. Diuji ulang dengan id asli → 200. |

Semua halaman admin-only → 403 untuk kontraktor. Semua halaman butuh-login →
302 ke `login.php` (atau 401 untuk `api/*`) untuk tanpa sesi. Tidak ada satu
pun halaman yang seharusnya terlindungi tapi malah bisa diakses lebih
longgar dari yang didesain.

## 2. Uji IDOR (nyata, bukan hanya baca kode)

- `aktivitas/form.php?id=14` (aktivitas milik kontraktor LAIN) sebagai
  `kontraktor1` → **403**.
- `lokasi/form.php?id=5` (lokasi kontraktor lain) sebagai `kontraktor1` →
  **403** (halaman ini memang admin-only, jadi kontraktor ditolak di
  `require_role()` sebelum ID sempat diperiksa — tetap hasil yang benar).
- `api/lokasi_detail.php?id=5` (lokasi kontraktor lain) sebagai
  `kontraktor1` → 200 tapi `"aktivitas_terakhir":[]`,
  `"aktivitas_dibatasi":true` — detail aktivitas ditahan sesuai Keputusan
  T-12 ("ambil yang paling ketat" antara PRD bagian 5 & 10).
- POST `lokasi/hapus.php`, `kontraktor/hapus.php`, `aktivitas/hapus.php`
  sebagai `kontraktor1` (dengan ID nyata) → **403 masing-masing**,
  `require_role('admin')` menolak SEBELUM baris apa pun tersentuh — tidak
  ada data yang terhapus selama pengujian.
- POST `lokasi/hapus.php` sebagai admin TANPA token CSRF → **419**. CSRF
  tetap ditegakkan walau peran valid.
- `aktivitas/hapus_foto.php`: data seed hanya punya 1 baris `foto_aktivitas`,
  tidak ada foto milik kontraktor lain untuk diuji langsung — gerbang
  `can_access_aktivitas()` yang dipakainya SAMA dengan yang sudah dibuktikan
  benar di uji `aktivitas/form.php?id=14` di atas (ekstrapolasi beralasan,
  bukan sel yang diuji langsung).

Tidak ada data seed yang diubah/dihapus selama pengujian ini.

## 3. Pindai kode (pola rentan)

| Pola | Hasil |
| --- | --- |
| SQL digabung string (bukan `:param`) | **Nihil.** Semua query lewat `db_run()`/`db_all()`/`db_one()`/`db_exec()`/`paginate()` dengan placeholder bernama. Satu kasus WHERE dinamis (`includes/repo/lokasi.php`) hanya menyisipkan fragmen literal tetap, bukan nilai mentah. |
| `echo`/`<?=` tanpa `e()` | **Nihil.** Setiap kasus yang lolos grep adalah literal hardcoded (bukan data pengguna) atau lewat helper yang sudah aman (`csrf_field()`, `url()`, `asset()`). `includes/api.php` meng-echo `json_encode()` dengan `Content-Type: application/json` — bukan vektor XSS. |
| `<form method="post">` tanpa `csrf_field()` | **Nihil.** Setiap berkas dengan form POST punya `csrf_field()` yang cocok. |
| Mass assignment (`$_POST` mentah ke repo create/update) | **Nihil.** Semua array disusun field-per-field eksplisit (`lokasi/form.php`, `aktivitas/form.php`, dll.) atau lewat `validate()` yang strukturnya dibatasi `$rules` yang di-hardcode — tidak mungkin meneruskan key sembarangan. |
| `redirect()`/`header('Location:...)` dari input tanpa validasi | **Nihil.** Semua `redirect($variabel)` melalui `validate_next_path()` dulu, dengan fallback literal aman. Tidak ada `header('Location:...)` mentah di luar helper `redirect()`. |

## 4. Konfigurasi produksi — temuan & perbaikan

| Item | Status SEBELUM | Tindakan |
| --- | --- | --- |
| `X-Powered-By: PHP/8.3.4` terkirim | **Bocor** (diverifikasi via curl) | **Diperbaiki**: `header_remove('X-Powered-By')` ditambah di `security_headers()` (`includes/auth.php`) — portabel, berlaku apa pun isi `php.ini` server tujuan. php.ini produksi tetap HARUS `expose_php=Off` sebagai lapis pertama; ini lapis kedua. |
| Directory listing | Sudah off (`Options -Indexes` di `.htaccess` root) | Tidak berubah. |
| `config/`, `includes/`, `database/`, `storage/` tidak bisa diakses web | **SEBAGIAN bocor** — lihat temuan kritis di bawah | **Sebagian diperbaiki + didokumentasikan**, lihat bagian 5. |
| `session.cookie_secure` | Sudah otomatis: `includes/auth.php` men-deteksi HTTPS (`$is_https`) dan men-set `secure` sesuai itu — BUKAN di-hardcode `1` (kalau di-hardcode, cookie session rusak total di dev HTTP lokal). | Tidak diubah — perilaku sudah benar untuk dev DAN prod (asalkan prod sungguhan dijalankan di HTTPS, yang WAJIB diverifikasi manual saat deploy, lihat bagian 6). |
| `display_errors` | Sudah `ini_set('display_errors','0')` otomatis saat `APP_ENV=prod` (`includes/bootstrap.php`). | Tidak diubah. |
| Skrip CLI dev yang BISA DIPICU LEWAT WEB | **Ditemukan 3 kasus baru** (lihat bagian 5) + 1 berkas pratinjau usang dihapus | **Diperbaiki.** |

## 5. Temuan kritis: `.htaccess` TIDAK berlaku di lingkungan dev ini (nginx)

**Ini temuan paling penting dari review ini.** `.htaccess` root proyek (dan
yang saya tambahkan di `config/`, `includes/`, `database/`, `storage/`)
adalah mekanisme **Apache**. Server dev yang sebenarnya menjalankan proyek
ini ber-header `Server: nginx/1.22.0` — nginx **mengabaikan total** semua
berkas `.htaccess`. Dibuktikan nyata:

- `database/seed.sql`, `database/README.md`, `storage/logs/*.log` semua
  **200 OK** lewat browser — padahal `.htaccess` root SUDAH mencoba
  memblokirnya sejak T-01.
- Sampel isi log yang berhasil diambil lewat URL memuat path file sistem
  internal dan jejak operasi admin — bukti nyata kebocoran, bukan teoretis.
- Berkas `.php` (mis. `config/config.php`) tetap 403 — tapi itu karena
  PENJAGA DI DALAM PHP-nya sendiri (`APP_BOOTSTRAPPED`), BUKAN karena
  `.htaccess` berhasil. Lapisan ini portabel (jalan di server apa pun) dan
  TIDAK terpengaruh temuan ini.

**Akar masalah:** `.htaccess` hanya melindungi lewat Apache; berkas
NON-PHP (`.sql`, `.md`, `.log`) di `config/database/storage` tidak punya
lapis perlindungan portabel sama sekali selain `.htaccess` itu sendiri.

**Yang SUDAH dilakukan dalam sesi ini:**
- `.htaccess` ditambahkan ke `config/`, `includes/`, `database/`, `storage/`
  (lapis kedua untuk server Apache — PRD bagian 11 menyebut target deploy
  "shared hosting PHP+MySQL biasa", yang mayoritas memang Apache/cPanel).
- Skrip yang SEHARUSNYA cli-only tapi sebelumnya bisa dieksekusi/dibaca
  lewat web diberi penjaga `PHP_SAPI !== 'cli'` (portabel, tidak bergantung
  server apa pun): `database/make_seed_hash.php` (sebelumnya mencetak
  PASSWORD PLAINTEXT dev ke siapa saja yang membuka URL-nya!),
  `tests/db_smoke.php` (sebelumnya bisa memicu tulis/hapus baris DB oleh
  siapa pun), `tests/validator_test.php`, `database/validate_geojson.php`.
- `tests/rbac_preview.php` dihapus — catatan berkasnya sendiri sudah lama
  bilang "WAJIB dihapus begitu T-08/T-09/T-17 ada" (sudah lama ada).

**Yang TIDAK bisa diselesaikan dari dalam repo ini** (lihat bagian 6):
konfigurasi nginx (`location` block) ada di LUAR direktori proyek, di
konfigurasi sistem Laragon, bukan sesuatu yang aman diubah tanpa izin
eksplisit di luar lingkup "review kode aplikasi". Snippet untuk siapa pun
yang mengelola server (Apache ATAU nginx) — **WAJIB diterapkan sebelum
deploy produksi, siapa pun operatornya**:

```nginx
# Tambahkan di server block nginx (di LUAR repo ini, di konfigurasi server):
location ~ ^/(config|includes|database|storage)/ {
    deny all;
    return 403;
}
```

Konfigurasi Apache (`.htaccess`) yang sudah ada di repo ini SUDAH benar
dan cukup — HANYA bila server produksi benar-benar Apache.

## 6. Keputusan di luar PRD

1. **Tidak mengubah struktur folder** (mis. memindahkan `config/`,
   `database/`, `storage/` ke luar document root) — itu akan menyalahi
   struktur folder PRD bagian 8 yang sudah diikuti konsisten sejak T-01, dan
   di luar lingkup "review", lebih tepat jadi keputusan arsitektur terpisah
   yang perlu persetujuan eksplisit sebelum mengubah struktur yang sudah
   stabil dan diuji di 25 tugas sebelumnya.
2. **Tidak mengubah nilai password seed** (`admin123`, `kontraktor123`) di
   `database/seed.sql` — sudah didokumentasikan jelas sebagai data dev sejak
   T-02 ("Jangan dipakai di produksi"), dan mengubahnya akan merusak alur
   uji manual yang dipakai di SELURUH laporan tugas T-06 s.d. T-25.
   Sebagai gantinya, rotasi password ditulis sebagai item WAJIB di
   checklist pra-deploy (bagian 7), bukan diubah di kode.
3. **`PHP_SAPI` dipilih atas `APP_ENV=dev`** untuk skrip `tests/db_smoke.php`
   dkk. — beda dari `tests/ui_preview.php` yang SENGAJA tetap dev-only-lewat-web
   (berguna untuk pratinjau visual manual). Skrip yang MENULIS/MENGHAPUS
   baris DB (`db_smoke.php`) atau mencetak password plaintext
   (`make_seed_hash.php`) tidak punya alasan sah dipicu lewat web SAMA
   SEKALI, bahkan di dev — jadi cli-only lebih tepat daripada dev-only.
4. **`.htaccess` tambahan tetap disimpan** walau terbukti tidak berlaku di
   lingkungan dev (nginx) — karena target deploy PRD (shared hosting) sering
   Apache, dan tidak merugikan apa pun bila ternyata server nginx (nginx
   mengabaikannya, bukan menolaknya).

## 7. Risiko tersisa & batasan review ini

**Review ini TIDAK mengklaim aplikasi "aman sepenuhnya".** Ini audit kode +
konfigurasi + uji fungsional terarah (bukan pentest formal, sesuai batas
skripsi PRD bagian 4), dengan batasan nyata:

- **Konfigurasi nginx produksi TIDAK diperbaiki** (lihat bagian 5) — hanya
  didokumentasikan, karena perubahannya ada di luar direktori proyek ini.
  **WAJIB diverifikasi manual** (`curl` ke `/database/seed.sql` dkk. harus
  403/404) pada server produksi SUNGGUHAN sebelum go-live, apa pun web
  server-nya.
- **Password seed belum dirotasi** — `admin123`/`kontraktor123` masih aktif
  di database dev ini. WAJIB diganti (lewat `database/make_seed_hash.php`
  via CLI, lalu `UPDATE users SET password_hash=...`) sebelum data nyata
  dimasukkan ke sistem produksi.
- **`Server: nginx/1.22.0` masih terkirim** — ini pengaturan web server
  (`server_tokens off;` di nginx, atau `ServerTokens Prod` di Apache), di
  luar kendali kode PHP, tidak diperbaiki dari repo ini.
- Pengujian matriks peran & IDOR memakai DATA SEED yang ada sekarang (ID
  kecil, terbatas) — belum diuji dengan skala data besar/ganjil (mis. ID
  negatif, ID sangat besar melebihi INT, lihat `includes/api.php` untuk
  penanganan itu yang sudah ada dari T-12 tapi tidak diuji ulang di sini).
- Tidak ada uji terhadap serangan lapisan jaringan (rate-limiting selain
  login throttle yang sudah ada, DDoS, TLS/cipher suite config) — di luar
  lingkup "review kode", murni tanggung jawab infrastruktur hosting.
- Review ini dilakukan oleh AI terhadap kode yang SEBAGIAN juga ditulis AI
  pada sesi-sesi sebelumnya — disarankan tetap ada tinjauan manusia (admin
  Dinas/pembimbing) sebelum dianggap final, khususnya untuk Keputusan #1
  dan #2 di atas.
