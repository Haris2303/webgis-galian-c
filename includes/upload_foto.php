<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/upload_foto.php — unggah foto dokumentasi aktivitas yang aman
 * (T-18, FR-14, PRD bagian 5/11). MENGGANTI fungsi sementara T-17
 * (aktivitas_validate_foto()/aktivitas_simpan_foto() di aktivitas/form.php)
 * dengan versi yang dikeraskan penuh: validasi berlapis, re-encode GD
 * (membuang EXIF/GPS & payload apa pun di luar data piksel), thumbnail,
 * rollback file fisik, dan hapus-per-foto.
 *
 * Urutan validasi per berkas SENGAJA mengikuti urutan task secara literal
 * (kode error -> is_uploaded_file -> ukuran -> finfo -> getimagesize ->
 * dimensi) -- yang murah dicek duluan, yang mahal (baca isi berkas) belakangan,
 * dan dimensi SELALU diperiksa lewat getimagesize() SEBELUM imagecreatefrom*()
 * dipanggil (edge case wajib: decompression bomb -- getimagesize() hanya
 * membaca header, imagecreatefrom*() mendekode penuh ke memori).
 */

/** Dicek sekali saat bootstrap (lihat includes/bootstrap.php) DAN sebelum dipakai di sini -- pesan instalasi yang jelas, bukan fatal error "call to undefined function". */
function upload_gd_tersedia(): bool
{
    return extension_loaded('gd')
        && function_exists('imagecreatefromjpeg')
        && function_exists('imagecreatefrompng')
        && function_exists('imagecreatetruecolor');
}

/**
 * Edge case wajib T-18: post_max_size di php.ini TERLAMPAUI oleh seluruh body
 * request -- PHP membuang SELURUH $_POST dan $_FILES tanpa mengisi kode error
 * apa pun yang bisa dibaca kode ini. Satu-satunya sinyal yang tersisa adalah
 * Content-Length header > 0 padahal keduanya kosong. Dipanggil SEBELUM
 * csrf_require() di form, supaya pengguna lihat pesan yang benar (bukan
 * "Token CSRF tidak valid" yang menyesatkan -- token itu sendiri ikut hilang).
 */
function upload_cek_post_terlampaui(): ?string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }

    $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($content_length > 0 && $_POST === [] && $_FILES === []) {
        $batas = ini_get('post_max_size');

        return "Data yang dikirim melebihi batas ukuran server (post_max_size={$batas}). "
            . 'Kurangi ukuran atau jumlah foto, atau hubungi admin untuk menaikkan batas server.';
    }

    return null;
}

/** Kode error $_FILES LENGKAP (spesifikasi T-18) -- bukan hanya OK/NOT-OK. */
function upload_pesan_kode_error(int $kode): string
{
    return match ($kode) {
        UPLOAD_ERR_INI_SIZE => 'Ukuran berkas melebihi batas upload_max_filesize server.',
        UPLOAD_ERR_FORM_SIZE => 'Ukuran berkas melebihi batas MAX_FILE_SIZE formulir.',
        UPLOAD_ERR_PARTIAL => 'Berkas hanya terunggah sebagian. Coba unggah ulang.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server tidak memiliki folder sementara untuk unggahan.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis berkas sementara ke disk.',
        UPLOAD_ERR_EXTENSION => 'Unggahan dihentikan oleh salah satu ekstensi PHP di server.',
        default => 'Berkas gagal diunggah (kode error tidak dikenal: ' . $kode . ').',
    };
}

/**
 * Validasi SEMUA foto baru sebelum apa pun disimpan -- dipanggil dari
 * aktivitas/form.php sebelum db_transaction() dimulai, supaya pengguna lihat
 * pesan error dalam form, bukan kegagalan di tengah transaksi.
 * @return string[] Daftar pesan error; kosong berarti semua foto valid/tidak ada foto baru.
 */
function upload_foto_validate(?array $files, int $jumlah_lama): array
{
    $errors = [];

    if ($files === null || !isset($files['error']) || !is_array($files['error'])) {
        return $errors;
    }

    if (!upload_gd_tersedia()) {
        // Edge case wajib T-18: ekstensi GD tidak aktif. Diperiksa ulang di
        // sini (bukan hanya saat bootstrap) supaya pesannya tampil tepat saat
        // pengguna benar-benar mencoba mengunggah, bukan hanya di log server.
        $ada_file_dipilih = false;
        foreach ($files['error'] as $kode) {
            if ($kode !== UPLOAD_ERR_NO_FILE) {
                $ada_file_dipilih = true;
                break;
            }
        }
        if ($ada_file_dipilih) {
            $errors[] = 'Unggah foto tidak tersedia: ekstensi GD belum terpasang di server. Hubungi admin.';

            return $errors;
        }

        return $errors;
    }

    $jumlah_baru = 0;

    foreach ($files['error'] as $idx => $kode_error) {
        if ($kode_error === UPLOAD_ERR_NO_FILE) {
            continue; // slot <input multiple> kosong -- normal, bukan error
        }
        if ($kode_error !== UPLOAD_ERR_OK) {
            $errors[] = upload_pesan_kode_error($kode_error);
            continue;
        }

        $jumlah_baru++;

        $tmp_path = (string) ($files['tmp_name'][$idx] ?? '');
        if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
            $errors[] = 'Salah satu foto gagal diproses server (bukan hasil unggahan HTTP yang sah).';
            continue;
        }

        $ukuran = (int) ($files['size'][$idx] ?? 0);
        if ($ukuran <= 0) {
            $errors[] = 'Salah satu foto kosong atau tidak terbaca.';
            continue;
        }
        if ($ukuran > UPLOAD_MAX_SIZE) {
            $errors[] = 'Ukuran salah satu foto melebihi ' . (int) (UPLOAD_MAX_SIZE / 1024 / 1024) . ' MB.';
            continue;
        }

        // finfo membaca KONTEN berkas, bukan ekstensi nama file atau header
        // Content-Type yang dikirim klien -- keduanya mudah dipalsukan
        // (edge case wajib: shell.php diberi nama shell.php.jpg).
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp_path);
        if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
            // Juga menolak GIF/WebP yang menyamar (edge case wajib T-18) --
            // keduanya memang bukan JPG/PNG menurut KONTEN, apa pun ekstensinya.
            $errors[] = 'Salah satu foto bukan berkas JPG/PNG yang valid.';
            continue;
        }

        // getimagesize() HANYA membaca header gambar, BUKAN mendekode seluruh
        // bitmap -- aman dipanggil pada berkas apa pun, termasuk upaya
        // decompression bomb (edge case wajib). imagecreatefrom*() yang
        // sungguh mendekode baru dipanggil NANTI di upload_foto_simpan(),
        // SETELAH dimensi di bawah ini dipastikan wajar.
        $info = @getimagesize($tmp_path);
        if ($info === false) {
            $errors[] = 'Salah satu foto rusak atau bukan berkas gambar yang valid.';
            continue;
        }
        if ($info['mime'] !== $mime) {
            // Lapis konsistensi tambahan: finfo dan getimagesize membaca
            // berkas yang sama dengan cara berbeda -- kalau hasilnya tidak
            // sama, lebih aman menolak daripada mempercayai salah satunya.
            $errors[] = 'Salah satu foto memiliki format yang tidak konsisten.';
            continue;
        }
        if ($info[0] > UPLOAD_MAX_DIMENSION || $info[1] > UPLOAD_MAX_DIMENSION) {
            $errors[] = 'Dimensi salah satu foto melebihi ' . UPLOAD_MAX_DIMENSION . ' piksel pada salah satu sisi.';
            continue;
        }
    }

    if (($jumlah_lama + $jumlah_baru) > UPLOAD_MAX_FILES) {
        $errors[] = "Maksimal " . UPLOAD_MAX_FILES . " foto per aktivitas (sudah ada {$jumlah_lama}, memilih {$jumlah_baru} baru).";
    }

    return $errors;
}

/** Orientasi EXIF (1-8) sebelum dibuang selamanya oleh re-encode -- tanpa ini foto kamera ponsel yang disimpan "miring" (rotasi lewat tag, bukan piksel) akan tampak terbalik setelah EXIF dihapus. */
function upload_foto_orientasi_exif(string $tmp_path): int
{
    if (!function_exists('exif_read_data')) {
        return 1; // ext-exif tidak ada -- anggap sudah tegak, jangan menebak
    }

    $exif = @exif_read_data($tmp_path);
    if ($exif === false || !isset($exif['Orientation'])) {
        return 1;
    }

    return (int) $exif['Orientation'];
}

/** @param resource|\GdImage $image */
function upload_foto_terapkan_orientasi($image, int $orientasi)
{
    switch ($orientasi) {
        case 2:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return $image;
        case 3:
            return imagerotate($image, 180, 0) ?: $image;
        case 4:
            imageflip($image, IMG_FLIP_VERTICAL);
            return $image;
        case 5:
            imageflip($image, IMG_FLIP_VERTICAL);
            return imagerotate($image, 270, 0) ?: $image;
        case 6:
            return imagerotate($image, 270, 0) ?: $image;
        case 7:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return imagerotate($image, 270, 0) ?: $image;
        case 8:
            return imagerotate($image, 90, 0) ?: $image;
        default:
            return $image;
    }
}

/** @param resource|\GdImage $sumber @return resource|\GdImage */
function upload_foto_resize($sumber, int $lebar, int $tinggi, int $sisi_maks)
{
    $skala = min(1.0, $sisi_maks / max($lebar, $tinggi));
    $lebar_baru = max(1, (int) round($lebar * $skala));
    $tinggi_baru = max(1, (int) round($tinggi * $skala));

    $hasil = imagecreatetruecolor($lebar_baru, $tinggi_baru);
    // Pertahankan transparansi PNG -- tanpa ini imagecreatetruecolor() default
    // hitam, area transparan asli akan jadi blok hitam solid setelah resize.
    imagealphablending($hasil, false);
    imagesavealpha($hasil, true);
    imagecopyresampled($hasil, $sumber, 0, 0, 0, 0, $lebar_baru, $tinggi_baru, $lebar, $tinggi);

    return $hasil;
}

function upload_foto_path_thumbnail(string $file_path): string
{
    $ext = pathinfo($file_path, PATHINFO_EXTENSION);
    $tanpa_ext = substr($file_path, 0, -(strlen($ext) + 1));

    return $tanpa_ext . '_thumb.' . $ext;
}

function upload_foto_path_absolut(string $relative_path): string
{
    $relative_path = str_replace('\\', '/', $relative_path);

    return rtrim(PROJECT_ROOT, '/\\') . '/' . ltrim($relative_path, '/');
}

/**
 * Decode -> (koreksi orientasi) -> resize dua ukuran -> re-encode -> simpan
 * dengan nama acak. INILAH yang membuang EXIF/GPS dan payload polyglot apa
 * pun (shell.php.jpg, JPG dengan PHP di EXIF, dll): imagecreatefrom*() hanya
 * membaca GRID PIKSEL, dan imagejpeg()/imagepng() menulis berkas JFIF/PNG
 * BARU dari nol -- tidak ada byte dari berkas asli (EXIF, comment, lampiran
 * di belakang EOF) yang tersalin ke berkas keluaran, apa pun isinya.
 *
 * @return array{0: string, 1: string} [path relatif utama, path relatif thumbnail]
 * @throws RuntimeException Gambar tidak bisa didekode, direktori tak bisa dibuat, atau gagal menulis (disk penuh).
 */
function upload_foto_proses_dan_simpan(string $tmp_path, string $mime): array
{
    $image = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp_path),
        'image/png' => @imagecreatefrompng($tmp_path),
        default => false,
    };

    if ($image === false) {
        // getimagesize() yang lolos TIDAK menjamin imagecreatefrom*() ikut
        // berhasil -- berkas bisa rusak di bagian yang tidak diperiksa
        // getimagesize() (edge case wajib: "gambar korup").
        throw new RuntimeException('Gambar tidak dapat dibaca atau rusak.');
    }

    if ($mime === 'image/jpeg') {
        $orientasi = upload_foto_orientasi_exif($tmp_path);
        if ($orientasi !== 1) {
            $image = upload_foto_terapkan_orientasi($image, $orientasi);
        }
    }

    $lebar = imagesx($image);
    $tinggi = imagesy($image);

    $utama = upload_foto_resize($image, $lebar, $tinggi, UPLOAD_RESIZE_MAX);
    $thumb = upload_foto_resize($image, $lebar, $tinggi, UPLOAD_THUMBNAIL_MAX);
    imagedestroy($image);

    $ext = $mime === 'image/png' ? 'png' : 'jpg';
    $subdir = 'uploads/aktivitas/' . date('Y') . '/' . date('m');
    $full_dir = upload_foto_path_absolut($subdir);

    if (!is_dir($full_dir) && !@mkdir($full_dir, 0755, true) && !is_dir($full_dir)) {
        imagedestroy($utama);
        imagedestroy($thumb);
        throw new RuntimeException("Gagal membuat direktori unggahan: {$full_dir}");
    }

    $nama_dasar = bin2hex(random_bytes(16)); // nama acak dari KONTEN, bukan nama berkas asli klien
    $nama_utama = $nama_dasar . '.' . $ext;
    $nama_thumb = $nama_dasar . '_thumb.' . $ext;
    $path_utama = $full_dir . '/' . $nama_utama;
    $path_thumb = $full_dir . '/' . $nama_thumb;

    $simpan = $mime === 'image/png'
        ? static fn ($img, $path): bool => imagepng($img, $path, 6)
        : static fn ($img, $path): bool => imagejpeg($img, $path, 85);

    $ok_utama = $simpan($utama, $path_utama);
    $ok_thumb = $simpan($thumb, $path_thumb);
    imagedestroy($utama);
    imagedestroy($thumb);

    if (!$ok_utama || !$ok_thumb) {
        // Edge case wajib T-18: disk penuh saat menulis. imagejpeg()/imagepng()
        // mengembalikan false (bukan melempar exception) saat gagal menulis,
        // jadi diperiksa manual -- bersihkan sisa berkas yang SEMPAT tertulis.
        @unlink($path_utama);
        @unlink($path_thumb);
        throw new RuntimeException('Gagal menyimpan berkas gambar ke disk (disk penuh atau tidak dapat ditulis).');
    }

    return [$subdir . '/' . $nama_utama, $subdir . '/' . $nama_thumb];
}

/**
 * Simpan SEMUA foto baru yang SUDAH lolos upload_foto_validate(). Dipanggil
 * HANYA di dalam db_transaction() (spesifikasi T-17/T-18: aktivitas+foto satu
 * transaksi) -- Exception di sini (termasuk dari upload_foto_proses_dan_simpan())
 * ikut membatalkan INSERT/UPDATE aktivitas lewat rollback SAVEPOINT/transaksi.
 *
 * $written_paths diisi (bukan diganti) SETIAP berkas berhasil ditulis ke disk,
 * SEBELUM baris DB-nya di-insert -- supaya kalau baris foto KE-3 gagal di-insert
 * (atau langkah lain dalam transaksi yang sama gagal setelahnya), pemanggil
 * tetap tahu file ke-1 & ke-2 yang SUDAH ada di disk untuk dibersihkan
 * (spesifikasi wajib T-18: "rollback file fisik bila transaksi DB gagal").
 *
 * @param array<string, mixed>|null $files Bentuk asli $_FILES['foto'].
 * @param string[] &$written_paths Diisi path absolut setiap file (utama+thumbnail) yang berhasil ditulis.
 */
function upload_foto_simpan(int $aktivitas_id, ?array $files, array &$written_paths): void
{
    if ($files === null || !isset($files['error']) || !is_array($files['error'])) {
        return;
    }

    foreach ($files['error'] as $idx => $kode_error) {
        if ($kode_error !== UPLOAD_ERR_OK) {
            continue; // sudah ditolak di upload_foto_validate() bila relevan
        }

        $tmp_path = (string) ($files['tmp_name'][$idx] ?? '');
        if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
            continue;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp_path);
        if (!in_array($mime, UPLOAD_ALLOWED_MIME, true)) {
            continue; // jaring pengaman kedua -- seharusnya sudah tersaring di validasi
        }

        [$path_utama, $path_thumb] = upload_foto_proses_dan_simpan($tmp_path, $mime);

        $written_paths[] = upload_foto_path_absolut($path_utama);
        $written_paths[] = upload_foto_path_absolut($path_thumb);

        repo_foto_create(['aktivitas_id' => $aktivitas_id, 'file_path' => $path_utama]);
    }
}

/**
 * Hapus SATU foto: baris DB + berkas utama + thumbnail-nya. Dipanggil dari
 * aktivitas/hapus_foto.php. Urutan: hapus baris DB dulu (bisa di-rollback
 * kalau gagal), baru hapus file fisik SETELAH baris DB benar-benar hilang --
 * pola yang sama dengan lokasi/hapus.php & aktivitas/hapus.php (T-09/T-17).
 */
function upload_foto_hapus(array $foto): void
{
    $file_path = (string) $foto['file_path'];

    repo_foto_delete((int) $foto['id']);

    foreach ([$file_path, upload_foto_path_thumbnail($file_path)] as $relatif) {
        $absolut = upload_foto_path_absolut($relatif);
        if (is_file($absolut) && !@unlink($absolut)) {
            app_log('warning', "Gagal menghapus file foto id={$foto['id']}: {$absolut}");
        }
    }
}
