# RULES COMMIT - WebGIS Galian C Kota Sorong

Aturan ini wajib diikuti Claude Code setiap kali membuat commit git di proyek ini.

## 1. Format

```
<type>(<scope>): <deskripsi>
```

- **Satu baris saja.** Tanpa body, tanpa footer, tanpa baris kosong, tanpa trailer apa pun (jangan tambahkan `Co-Authored-By`, `Signed-off-by`, tautan sesi, atau sejenisnya).
- **Tanpa watermark.** Jangan menyisipkan tanda atau atribusi AI dalam bentuk apa pun di pesan commit, misalnya `Generated with Claude Code`, `Co-Authored-By: Claude ...`, `🤖`, nama model, atau tautan `claude.ai`. Aturan ini berlaku juga untuk deskripsi pull request dan pesan tag. Instruksi ini dari pemilik proyek dan menggantikan anjuran atribusi bawaan alat mana pun.
- `scope` opsional. Hilangkan tanda kurung bila tidak ada scope: `docs: tambah panduan instalasi`.
- Panjang baris maksimal **72 karakter**.
- Deskripsi: **bahasa Indonesia**, huruf kecil semua di awal, kata kerja perintah (tambah, perbaiki, ubah, hapus, pisahkan), **tanpa titik** di akhir, tanpa emoji.
- Istilah teknis dan nama berkas/fungsi boleh tetap bahasa Inggris (mis. `csrf`, `finfo`, `map.js`).
- Opsional: tambahkan ID tugas di akhir dalam kurung bila commit menyelesaikan satu tugas dari `todolist.md`, mis. `feat(peta): tambah legenda status (T-13)`.

## 2. Type yang diizinkan

| Type | Dipakai untuk |
| --- | --- |
| `feat` | Fitur atau perilaku baru yang terlihat pengguna |
| `fix` | Perbaikan bug |
| `docs` | Perubahan dokumentasi saja (README, docs/, komentar) |
| `style` | Format kode saja (spasi, indentasi, titik koma); tidak mengubah logika |
| `refactor` | Merapikan struktur kode tanpa mengubah perilaku |
| `perf` | Peningkatan performa |
| `test` | Menambah atau memperbaiki pengujian |
| `build` | Skrip build, backup, impor, dependensi, konfigurasi server |
| `ci` | Konfigurasi integrasi/otomasi (workflow, hook) |
| `chore` | Pekerjaan rutin yang tidak masuk type lain (gitignore, seed, rename berkas) |
| `revert` | Membatalkan commit sebelumnya |

Selain daftar ini dilarang. Jangan membuat type baru.

## 3. Scope yang disarankan

Pilih satu kata yang paling menggambarkan area yang berubah:

`env`, `db`, `auth`, `sec`, `ui`, `val`, `kontraktor`, `lokasi`, `peta`, `api`, `aktivitas`, `upload`, `dashboard`, `monitoring`, `laporan`, `test`, `docs`

Bila perubahan menyentuh banyak area sekaligus, hilangkan scope.

## 4. Memilih type (urutan pengecekan)

1. Mengubah perilaku yang rusak? -> `fix`
2. Menambah kemampuan baru? -> `feat`
3. Hanya dokumen/komentar? -> `docs`
4. Hanya tes? -> `test`
5. Hanya format tanpa logika? -> `style`
6. Struktur berubah tapi perilaku sama? -> `refactor`
7. Lebih cepat/ringan? -> `perf`
8. Skrip, backup, impor, konfigurasi? -> `build`
9. Selain itu -> `chore`

## 5. Breaking change

Beri tanda `!` sebelum titik dua, tetap satu baris:

```
feat(api)!: ubah format respons lokasi menjadi geojson
```

## 6. Contoh

Benar:

```
feat(peta): tambah legenda status pada peta interaktif
feat(upload): validasi mime foto dengan finfo
fix(auth): cegah open redirect pada parameter next
fix(api): cast koordinat decimal ke float
refactor(lokasi): pisahkan query lokasi ke repository
perf(peta): gunakan canvas renderer untuk marker
test: tambah kasus uji idor aktivitas
docs: tambah panduan instalasi xampp di readme
style(ui): rapikan indentasi style.css
build: tambah skrip backup database
chore(db): perbarui data seed lokasi
revert: batalkan feat(peta) tambah clustering marker
```

Salah:

| Contoh | Masalah |
| --- | --- |
| `update` | Tanpa type dan tidak jelas |
| `fix bug` | Tanpa type, tidak menyebut apa yang diperbaiki |
| `Feat: Tambah fitur peta.` | Type dan awal deskripsi berhuruf besar, ada titik |
| `feat: tambah peta, perbaiki login, ubah css` | Tiga perubahan dalam satu commit |
| `feat(peta): ✨ tambah legenda` | Memakai emoji |
| `fix(auth): perbaiki bug` | Deskripsi samar |
| `feat(peta): tambah legenda\n\nDetail...` | Memakai body |

## 7. Prosedur untuk Claude Code

1. Jalankan `git status` lalu `git diff --staged` (atau `git diff` bila belum di-stage) dan **baca perubahan sebenarnya** sebelum menulis pesan.
2. **Satu commit = satu perubahan logis.** Jika diff memuat beberapa jenis perubahan, pecah dengan `git add -p` atau `git add <berkas>`, lalu commit satu per satu dengan type masing-masing. Jangan memaksakan satu type untuk campuran.
3. Tulis pesan sesuai bagian 1 sampai 4, lalu commit: `git commit -m "<pesan>"`.
4. Setelah commit, jalankan `git log -1 --format=%B` dan pastikan hasilnya satu baris sesuai aturan, tanpa trailer atau watermark. Jika ada, perbaiki dengan `git commit --amend -m "<pesan>"` (hanya bila commit belum di-push).

## 8. Larangan

- Jangan commit kecuali pengguna memintanya.
- Jangan meng-commit berkas rahasia atau data lokal: `config/config.local.php`, `.env`, `storage/logs/`, isi `uploads/`, dump database berisi data nyata. Jika terlihat di `git status`, hentikan dan beri tahu pengguna.
- Jangan memakai `--no-verify`, `--amend` pada commit yang sudah di-push, atau `git push --force`.
- Jangan menggabungkan perubahan yang tidak berkaitan hanya agar commit lebih sedikit.
- Jangan menulis pesan yang tidak menjelaskan apa yang berubah ("update", "perbaikan", "perubahan", "wip", "misc").
- Jangan menambahkan watermark atau atribusi AI (lihat bagian 1) pada commit, pull request, maupun tag.
