<?php

declare(strict_types=1);

/**
 * aktivitas/form.php — tambah & ubah aktivitas tambang (FR-13, FR-15; T-17,
 * PRD bagian 2, 5, 7). Dipakai Kontraktor (lokasi miliknya saja) dan Admin
 * (semua lokasi). Otorisasi tingkat objek (PRD bagian 7) ditegakkan di SINI,
 * bukan hanya lewat sidebar/menu -- setiap ID dari URL maupun POST diperiksa
 * ulang ke database, tidak pernah dipercaya mentah dari klien.
 *
 * Foto (FR-14, T-18): validasi berlapis + re-encode GD (buang EXIF/GPS &
 * payload polyglot) + thumbnail + rollback file fisik ditangani penuh oleh
 * includes/upload_foto.php -- berkas ini hanya memanggilnya.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';
require_once __DIR__ . '/../includes/repo/material.php';
require_once __DIR__ . '/../includes/repo/foto.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/upload_foto.php';

$user = require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$aktivitas = null;

if ($id !== null) {
    $aktivitas = repo_aktivitas_find($id);
    if ($aktivitas === null) {
        abort(404, 'Aktivitas tidak ditemukan.');
    }
    if (!can_access_aktivitas($user, $id)) {
        // Edge case wajib T-17 (IDOR #1 -- "ubah ID di URL"): aktivitas ini
        // ada, tapi bukan milik kontraktor ini maupun admin yang login.
        abort(403);
    }
}
$is_edit = $aktivitas !== null;

if (!$is_edit && $user['role'] !== 'kontraktor') {
    // FR-13 (PRD bagian 5): hanya Kontraktor yang boleh MEMBUAT aktivitas
    // baru (kolom "Peran" di PRD hanya mencantumkan Kontraktor untuk FR-13).
    // Admin tetap bisa UBAH aktivitas siapa pun lewat ?id= (FR-15), tapi
    // tidak membuat entri baru atas nama kontraktor yang bukan dirinya.
    abort(403);
}

// Dropdown lokasi: kontraktor hanya lokasi PERUSAHAANNYA, admin semua
// (spesifikasi T-17). Daftar ini jugalah yang membatasi pilihan WAJAR di
// klien -- otorisasi SUNGGUHAN tetap diperiksa ulang saat submit di bawah,
// karena klien bisa mengirim lokasi_id apa pun lewat request mentah.
if ($user['role'] === 'admin') {
    [$daftar_lokasi] = repo_lokasi_list([], 1, 500, 'nama_lokasi', 'asc');
} else {
    [$daftar_lokasi] = repo_lokasi_list(
        ['kontraktor_id' => (int) ($user['kontraktor_id'] ?? 0)],
        1,
        500,
        'nama_lokasi',
        'asc'
    );
}
$daftar_material = repo_material_list();

$errors = [];
$status_warning = null;

$labels = [
    'lokasi_id' => 'lokasi',
    'tanggal_aktivitas' => 'tanggal aktivitas',
    'jenis_kegiatan' => 'jenis kegiatan',
    'material_id' => 'jenis material',
    'status_aktivitas' => 'status aktivitas',
    'jumlah_alat_berat' => 'jumlah alat berat',
    'jumlah_pekerja' => 'jumlah pekerja',
    'deskripsi' => 'keterangan',
];

$rules = [
    'tanggal_aktivitas' => 'required|date',
    'jenis_kegiatan' => 'required|max:100',
    'status_aktivitas' => 'required|in:aktif,berhenti_sementara,selesai',
    'jumlah_alat_berat' => 'integer|min:0',
    'jumlah_pekerja' => 'integer|min:0',
    'deskripsi' => 'max:2000',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Edge case wajib T-18: post_max_size php.ini terlampaui -- $_POST DAN
    // $_FILES keduanya kosong di sini, jadi diperiksa SEBELUM csrf_require()
    // (yang juga akan gagal, tapi dengan pesan yang menyesatkan).
    $pesan_post_terlampaui = upload_cek_post_terlampaui();
    if ($pesan_post_terlampaui !== null) {
        flash('error', $pesan_post_terlampaui);
        redirect('aktivitas/form.php' . ($is_edit ? '?id=' . $id : ''));
    }

    csrf_require();

    if (!form_token_consume($_POST['_form_token'] ?? null)) {
        // Edge case wajib T-17: double submit. Sama seperti lokasi/form.php
        // (T-09) -- anggap submit pertama sudah/tengah diproses, jangan buat
        // baris kedua.
        flash('warning', 'Formulir ini sudah diproses sebelumnya.');
        redirect('aktivitas/index.php');
    }

    $hasil = validate($_POST, $rules, $labels);
    $errors = $hasil['errors'];
    $data = $hasil['data'];

    // lokasi_id: FK yang juga gerbang otorisasi utama task ini -- divalidasi
    // keberadaannya (bukan 'in:' statis, daftarnya dinamis) DAN kepemilikannya.
    $lokasi_id = (int) ($_POST['lokasi_id'] ?? 0);
    $lokasi = $lokasi_id > 0 ? repo_lokasi_find($lokasi_id) : null;
    if ($lokasi === null) {
        // Termasuk edge case wajib T-17: lokasi dihapus saat form masih
        // terbuka -- lokasi_id yang tadinya valid saat halaman dimuat sudah
        // tidak ada lagi saat submit. Diberi tahu sebagai error form biasa,
        // bukan 403 (bukan percobaan akses, cuma kedaluwarsa wajar).
        $errors['lokasi_id'][] = 'Lokasi wajib dipilih dan harus masih ada.';
    } elseif (!can_access_lokasi($user, $lokasi_id)) {
        // Edge case wajib T-17 (IDOR #2 -- "ubah lokasi_id di POST"): lokasi
        // ini ADA, tapi milik kontraktor lain. 403 tegas -- ini percobaan
        // akses ke data pihak lain, bukan kesalahan input wajar yang cukup
        // ditampilkan sebagai pesan error form.
        abort(403);
    }

    $material_id = (int) ($_POST['material_id'] ?? 0);
    if ($material_id <= 0 || repo_material_find($material_id) === null) {
        $errors['material_id'][] = 'Jenis material wajib dipilih dan harus valid.';
    }

    // Tanggal tidak boleh di masa depan (spesifikasi T-17) -- validator
    // generik (includes/validator.php) tidak punya aturan "sebelum hari ini"
    // dinamis, jadi dicek manual di sini, konsisten dengan pola lokasi/form.php.
    if (($data['tanggal_aktivitas'] ?? '') !== '' && $data['tanggal_aktivitas'] > date('Y-m-d')) {
        $errors['tanggal_aktivitas'][] = 'Tanggal aktivitas tidak boleh di masa depan.';
    }

    $jumlah_foto_lama = $is_edit ? repo_foto_count_by_aktivitas($id) : 0;
    $foto_errors = upload_foto_validate($_FILES['foto'] ?? null, $jumlah_foto_lama);
    if ($foto_errors !== []) {
        $errors['foto'] = $foto_errors;
    }

    if ($errors === [] && $lokasi !== null) {
        // Edge case wajib T-17: aktivitas ganda utk lokasi & tanggal yang
        // sama -- PERINGATAN, BUKAN blokir. Dicek setelah validasi dasar
        // lolos supaya tidak menimpa pesan error yang lebih mendasar.
        $sudah_ada = repo_aktivitas_exists_same_date($lokasi_id, $data['tanggal_aktivitas'], $is_edit ? $id : null);
        if ($sudah_ada) {
            $status_warning = 'Catatan: sudah ada aktivitas lain tercatat untuk lokasi dan tanggal yang sama. Data ini tetap disimpan sebagai entri terpisah.';
        }
    }

    if ($errors === []) {
        // Mass assignment: array dibangun EKSPLISIT field demi field dari
        // nilai yang SUDAH divalidasi, bukan $_POST/$data disebar langsung
        // (pola yang sama dengan lokasi/form.php, T-09).
        $aktivitas_data = [
            'lokasi_id' => $lokasi_id,
            'tanggal_aktivitas' => $data['tanggal_aktivitas'],
            'jenis_kegiatan' => $data['jenis_kegiatan'],
            'material_id' => $material_id,
            'status_aktivitas' => $data['status_aktivitas'],
            'jumlah_alat_berat' => $data['jumlah_alat_berat'] !== '' ? (int) $data['jumlah_alat_berat'] : 0,
            'jumlah_pekerja' => $data['jumlah_pekerja'] !== '' ? (int) $data['jumlah_pekerja'] : 0,
            'deskripsi' => $data['deskripsi'] !== '' ? $data['deskripsi'] : null,
        ];

        $foto_files = $_FILES['foto'] ?? null;

        // Edge case wajib T-18: "rollback file fisik bila transaksi DB gagal".
        // $written_paths diisi oleh upload_foto_simpan() SETIAP berkas yang
        // SUDAH tertulis ke disk, SEBELUM baris DB-nya di-insert -- kalau
        // Exception apa pun terlempar di dalam db_transaction() (termasuk
        // dari GD saat memproses berkas lain, atau kegagalan DB), file yang
        // SEMPAT tertulis pada percobaan ini dibersihkan di blok catch.
        $written_paths = [];

        try {
            if ($is_edit) {
                $aktivitas_id = $id;
                // user_id SENGAJA tidak disentuh saat ubah -- pencatat asli
                // tidak berubah hanya karena admin yang mengedit (spesifikasi
                // T-17: "user_id ditetapkan server", bukan sesuatu yang bisa dipindah).
                db_transaction(function () use ($aktivitas_id, $aktivitas_data, $foto_files, &$written_paths) {
                    repo_aktivitas_update($aktivitas_id, $aktivitas_data);
                    upload_foto_simpan($aktivitas_id, $foto_files, $written_paths);
                });
                flash('success', 'Aktivitas berhasil diperbarui.' . ($status_warning !== null ? ' ' . $status_warning : ''));
            } else {
                // created_by/user_id WAJIB dari sesi yang login, bukan dari
                // form (edge case mass assignment -- spesifikasi T-17:
                // "user_id ditetapkan server").
                $aktivitas_data['user_id'] = $user['id'];
                db_transaction(function () use ($aktivitas_data, $foto_files, &$written_paths) {
                    $new_id = repo_aktivitas_create($aktivitas_data);
                    upload_foto_simpan($new_id, $foto_files, $written_paths);
                });
                flash('success', 'Aktivitas berhasil ditambahkan.' . ($status_warning !== null ? ' ' . $status_warning : ''));
            }
        } catch (Throwable $e) {
            foreach ($written_paths as $path) {
                @unlink($path);
            }
            // Pesan asli (bisa berisi path server, dll) HANYA ke log -- yang
            // ditampilkan ke pengguna selalu generik, konsisten dengan
            // exception handler global (includes/bootstrap.php).
            app_log('error', 'aktivitas/form.php: transaksi gagal, ' . count($written_paths) . ' berkas fisik dibersihkan. ' . $e->getMessage());
            flash('error', 'Gagal menyimpan aktivitas karena kesalahan server. Berkas yang sempat terunggah telah dibersihkan, silakan coba lagi.');
            redirect('aktivitas/form.php' . ($is_edit ? '?id=' . $id : ''));
        }

        redirect('aktivitas/index.php');
    }
}

// Lokasi yang SEDANG terpilih (POST gagal validasi -> dari input; mode ubah
// -> dari data tersimpan; mode tambah baru -> belum ada). Dipakai membangun
// panel info read-only (kontraktor/koordinat/status izin) di bawah --
// SELALU diambil ulang dari DB di sini, TIDAK PERNAH dari field tersembunyi
// manapun (spesifikasi T-17: field read-only "server mengambil ulang dari
// database dan tidak mempercayai input klien").
$lokasi_id_terpilih = (int) ($_POST['lokasi_id'] ?? ($is_edit ? $aktivitas['lokasi_id'] : 0));
$lokasi_terpilih = $lokasi_id_terpilih > 0 ? repo_lokasi_find($lokasi_id_terpilih) : null;
$kontraktor_terpilih = $lokasi_terpilih !== null ? repo_kontraktor_find((int) $lokasi_terpilih['kontraktor_id']) : null;

$page_title = $is_edit ? 'Ubah Aktivitas' : 'Input Aktivitas';
$layout = 'app';
$active_menu = $user['role'] === 'admin' ? 'aktivitas' : 'input_aktivitas';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="app-main__title"><?= e($page_title) ?></h1>

<?php if ($status_warning !== null): ?>
<p class="flash flash--warning"><?= e($status_warning) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(url('aktivitas/form.php' . ($is_edit ? '?id=' . $id : ''))) ?>" enctype="multipart/form-data" data-validate novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="_form_token" value="<?= e(form_token()) ?>">

  <div class="grid-2">
    <div class="card">
      <h2 class="card__title">Data Aktivitas</h2>

      <?php $fn = 'lokasi_id'; ?>
      <div class="field <?= e(field_error_class($errors, $fn)) ?>">
        <label class="field__label" for="lokasi_id">Lokasi <span class="field__required">*</span></label>
        <select class="select" id="lokasi_id" name="lokasi_id" required>
          <option value="">— Pilih lokasi —</option>
          <?php foreach ($daftar_lokasi as $l): ?>
          <option value="<?= e((string) $l['id']) ?>" <?php if ($lokasi_id_terpilih === (int) $l['id']): ?>selected<?php endif; ?>><?= e($l['nama_lokasi']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($daftar_lokasi === []): ?>
        <p class="field__hint">Belum ada lokasi yang terdaftar untuk perusahaan Anda. Hubungi admin.</p>
        <?php endif; ?>
        <?php if (isset($errors[$fn])): ?><p class="field__error" id="lokasi_id-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
      </div>

      <div class="field">
        <span class="field__label">Detail Lokasi Terpilih</span>
        <?php if ($lokasi_terpilih !== null): ?>
        <p class="field__hint">
          Kontraktor: <?= e($kontraktor_terpilih['nama_perusahaan'] ?? '—') ?><br>
          Koordinat: <?= e((string) $lokasi_terpilih['latitude']) ?>, <?= e((string) $lokasi_terpilih['longitude']) ?><br>
          Status Izin:
          <?php if ($lokasi_terpilih['status_izin'] === 'berizin'): ?>
          <span class="badge badge--berizin">Berizin</span>
          <?php elseif ($lokasi_terpilih['status_izin'] === 'tidak_berizin'): ?>
          <span class="badge badge--tidak-berizin">Tidak Berizin</span>
          <?php else: ?>
          <span class="badge badge--proses">Proses</span>
          <?php endif; ?>
        </p>
        <?php else: ?>
        <p class="field__hint">Pilih lokasi untuk melihat detailnya di sini.</p>
        <?php endif; ?>
      </div>

      <div class="grid-2">
        <?php $fn = 'tanggal_aktivitas'; ?>
        <div class="field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="field__label" for="tanggal_aktivitas">Tanggal Aktivitas <span class="field__required">*</span></label>
          <input class="input" type="date" id="tanggal_aktivitas" name="tanggal_aktivitas" required max="<?= e(date('Y-m-d')) ?>"
            value="<?= e($is_edit ? old('tanggal_aktivitas', (string) $aktivitas['tanggal_aktivitas']) : old('tanggal_aktivitas')) ?>">
          <?php if (isset($errors[$fn])): ?><p class="field__error" id="tanggal_aktivitas-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>
        <?php $fn = 'jenis_kegiatan'; ?>
        <div class="field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="field__label" for="jenis_kegiatan">Jenis Kegiatan <span class="field__required">*</span></label>
          <input class="input" type="text" id="jenis_kegiatan" name="jenis_kegiatan" required maxlength="100"
            value="<?= e($is_edit ? old('jenis_kegiatan', (string) $aktivitas['jenis_kegiatan']) : old('jenis_kegiatan')) ?>">
          <?php if (isset($errors[$fn])): ?><p class="field__error" id="jenis_kegiatan-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="grid-2">
        <?php $fn = 'material_id'; ?>
        <div class="field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="field__label" for="material_id">Jenis Material <span class="field__required">*</span></label>
          <?php $material_terpilih = (int) ($_POST['material_id'] ?? ($is_edit ? $aktivitas['material_id'] : 0)); ?>
          <select class="select" id="material_id" name="material_id" required>
            <option value="">— Pilih material —</option>
            <?php foreach ($daftar_material as $m): ?>
            <option value="<?= e((string) $m['id']) ?>" <?php if ($material_terpilih === (int) $m['id']): ?>selected<?php endif; ?>><?= e($m['nama']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors[$fn])): ?><p class="field__error" id="material_id-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>
        <?php $fn = 'status_aktivitas'; ?>
        <div class="field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="field__label" for="status_aktivitas">Status Aktivitas <span class="field__required">*</span></label>
          <?php $status_terpilih = (string) ($_POST['status_aktivitas'] ?? ($is_edit ? $aktivitas['status_aktivitas'] : 'aktif')); ?>
          <select class="select" id="status_aktivitas" name="status_aktivitas" required>
            <option value="aktif" <?php if ($status_terpilih === 'aktif'): ?>selected<?php endif; ?>>Aktif</option>
            <option value="berhenti_sementara" <?php if ($status_terpilih === 'berhenti_sementara'): ?>selected<?php endif; ?>>Berhenti Sementara</option>
            <option value="selesai" <?php if ($status_terpilih === 'selesai'): ?>selected<?php endif; ?>>Selesai</option>
          </select>
          <?php if (isset($errors[$fn])): ?><p class="field__error" id="status_aktivitas-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="grid-2">
        <?php $fn = 'jumlah_alat_berat'; ?>
        <div class="field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="field__label" for="jumlah_alat_berat">Jumlah Alat Berat</label>
          <input class="input" type="number" id="jumlah_alat_berat" name="jumlah_alat_berat" min="0" step="1"
            value="<?= e($is_edit ? old('jumlah_alat_berat', (string) $aktivitas['jumlah_alat_berat']) : old('jumlah_alat_berat', '0')) ?>">
          <?php if (isset($errors[$fn])): ?><p class="field__error" id="jumlah_alat_berat-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>
        <?php $fn = 'jumlah_pekerja'; ?>
        <div class="field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="field__label" for="jumlah_pekerja">Jumlah Pekerja</label>
          <input class="input" type="number" id="jumlah_pekerja" name="jumlah_pekerja" min="0" step="1"
            value="<?= e($is_edit ? old('jumlah_pekerja', (string) $aktivitas['jumlah_pekerja']) : old('jumlah_pekerja', '0')) ?>">
          <?php if (isset($errors[$fn])): ?><p class="field__error" id="jumlah_pekerja-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <?php $fn = 'deskripsi'; ?>
      <div class="field <?= e(field_error_class($errors, $fn)) ?>">
        <label class="field__label" for="deskripsi">Keterangan</label>
        <textarea class="textarea" id="deskripsi" name="deskripsi"><?= e($is_edit ? old('deskripsi', (string) ($aktivitas['deskripsi'] ?? '')) : old('deskripsi')) ?></textarea>
        <?php if (isset($errors[$fn])): ?><p class="field__error" id="deskripsi-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2 class="card__title">Foto Dokumentasi</h2>

      <?php $fn = 'foto'; ?>
      <?php $foto_lama = $is_edit ? repo_foto_list_by_aktivitas($id) : []; ?>
      <div class="field <?= e(field_error_class($errors, $fn)) ?>">
        <label class="field__label" for="foto">Unggah Foto (JPG/PNG, maks <?= e((string) (int) (UPLOAD_MAX_SIZE / 1024 / 1024)) ?> MB/foto, maks <?= e((string) UPLOAD_MAX_FILES) ?> foto)</label>
        <input
          class="input" type="file" id="foto" name="foto[]" accept="image/jpeg,image/png" multiple
          data-foto-preview
          data-max-files="<?= e((string) UPLOAD_MAX_FILES) ?>"
          data-existing-count="<?= e((string) count($foto_lama)) ?>"
          data-preview-target="foto-preview-grid"
          data-count-target="foto-preview-count"
        >
        <p class="field__hint" id="foto-preview-count"></p>
        <ul class="photo-grid" id="foto-preview-grid"></ul>
        <?php if (isset($errors[$fn])): ?>
        <?php foreach ($errors[$fn] as $pesan): ?>
        <p class="field__error"><?= e($pesan) ?></p>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($foto_lama !== []): ?>
      <div class="field">
        <span class="field__label">Foto Tersimpan (<?= e((string) count($foto_lama)) ?>)</span>
        <ul class="photo-grid">
          <?php foreach ($foto_lama as $f): ?>
          <li class="photo-grid__item">
            <img class="photo-grid__thumb" src="<?= e(url(upload_foto_path_thumbnail((string) $f['file_path']))) ?>" alt="Foto dokumentasi aktivitas" loading="lazy">
            <form method="post" action="<?= e(url('aktivitas/hapus_foto.php')) ?>" class="photo-grid__hapus-form">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= e((string) $f['id']) ?>">
              <input type="hidden" name="back" value="<?= e('aktivitas/form.php?id=' . $id) ?>">
              <button type="submit" class="btn btn--danger btn--sm">Hapus</button>
            </form>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn--primary"><?php if ($is_edit): ?>Simpan Perubahan<?php else: ?>Simpan Aktivitas<?php endif; ?></button>
    <a class="btn btn--secondary" href="<?= e(url('aktivitas/index.php')) ?>">Batal</a>
  </div>
</form>

<?php
$extra_scripts = [
    '<script src="' . e(asset('assets/js/foto-preview.js')) . '"></script>',
];
require __DIR__ . '/../includes/footer.php';
