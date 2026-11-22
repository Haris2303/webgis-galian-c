<?php

declare(strict_types=1);

// aktivitas/form.php — tambah & ubah aktivitas tambang; setiap ID dari URL/POST diperiksa ulang ke database, tidak dipercaya mentah dari klien.

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
        // Aktivitas ada tapi bukan milik kontraktor ini maupun admin yang login.
        abort(403);
    }
}
$is_edit = $aktivitas !== null;

if (!$is_edit && $user['role'] !== 'kontraktor') {
    // Hanya kontraktor yang boleh membuat aktivitas baru; admin hanya bisa mengubah.
    abort(403);
}

// Daftar ini hanya membatasi pilihan di klien; otorisasi sesungguhnya diperiksa ulang saat submit.
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
    // Dicek sebelum csrf_require(): saat post_max_size terlampaui, $_POST dan $_FILES kosong.
    $pesan_post_terlampaui = upload_cek_post_terlampaui();
    if ($pesan_post_terlampaui !== null) {
        flash('error', $pesan_post_terlampaui);
        redirect('aktivitas/form.php' . ($is_edit ? '?id=' . $id : ''));
    }

    csrf_require();

    if (!form_token_consume($_POST['_form_token'] ?? null)) {
        // Anggap submit pertama sudah diproses; cegah baris duplikat.
        flash('warning', 'Formulir ini sudah diproses sebelumnya.');
        redirect('aktivitas/index.php');
    }

    $hasil = validate($_POST, $rules, $labels);
    $errors = $hasil['errors'];
    $data = $hasil['data'];

    // lokasi_id divalidasi keberadaannya (dinamis, bukan 'in:' statis) dan kepemilikannya.
    $lokasi_id = (int) ($_POST['lokasi_id'] ?? 0);
    $lokasi = $lokasi_id > 0 ? repo_lokasi_find($lokasi_id) : null;
    if ($lokasi === null) {
        // Lokasi mungkin terhapus setelah form dimuat; tampilkan sebagai error form, bukan 403.
        $errors['lokasi_id'][] = 'Lokasi wajib dipilih dan harus masih ada.';
    } elseif (!can_access_lokasi($user, $lokasi_id)) {
        // Lokasi ada tapi milik kontraktor lain -- percobaan akses, bukan input tidak valid.
        abort(403);
    }

    $material_id = (int) ($_POST['material_id'] ?? 0);
    if ($material_id <= 0 || repo_material_find($material_id) === null) {
        $errors['material_id'][] = 'Jenis material wajib dipilih dan harus valid.';
    }

    // Validator generik tidak punya aturan "sebelum hari ini" dinamis, jadi dicek manual.
    if (($data['tanggal_aktivitas'] ?? '') !== '' && $data['tanggal_aktivitas'] > date('Y-m-d')) {
        $errors['tanggal_aktivitas'][] = 'Tanggal aktivitas tidak boleh di masa depan.';
    }

    $jumlah_foto_lama = $is_edit ? repo_foto_count_by_aktivitas($id) : 0;
    $foto_errors = upload_foto_validate($_FILES['foto'] ?? null, $jumlah_foto_lama);
    if ($foto_errors !== []) {
        $errors['foto'] = $foto_errors;
    }

    if ($errors === [] && $lokasi !== null) {
        // Peringatan saja, bukan blokir; dicek setelah validasi dasar lolos.
        $sudah_ada = repo_aktivitas_exists_same_date($lokasi_id, $data['tanggal_aktivitas'], $is_edit ? $id : null);
        if ($sudah_ada) {
            $status_warning = 'Catatan: sudah ada aktivitas lain tercatat untuk lokasi dan tanggal yang sama. Data ini tetap disimpan sebagai entri terpisah.';
        }
    }

    if ($errors === []) {
        // Dibangun field demi field dari data yang sudah divalidasi, bukan disebar dari $_POST langsung.
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

        // $written_paths diisi setiap file yang sudah ditulis ke disk, dibersihkan di catch bila transaksi gagal.
        $written_paths = [];

        try {
            if ($is_edit) {
                $aktivitas_id = $id;
                // user_id tidak diubah saat edit -- pencatat asli tetap sama.
                db_transaction(function () use ($aktivitas_id, $aktivitas_data, $foto_files, &$written_paths) {
                    repo_aktivitas_update($aktivitas_id, $aktivitas_data);
                    upload_foto_simpan($aktivitas_id, $foto_files, $written_paths);
                });
                flash('success', 'Aktivitas berhasil diperbarui.' . ($status_warning !== null ? ' ' . $status_warning : ''));
            } else {
                // user_id diambil dari sesi login, bukan dari form.
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
            // Pesan asli (bisa berisi path server) hanya ke log; pengguna selalu dapat pesan generik.
            app_log('error', 'aktivitas/form.php: transaksi gagal, ' . count($written_paths) . ' berkas fisik dibersihkan. ' . $e->getMessage());
            flash('error', 'Gagal menyimpan aktivitas karena kesalahan server. Berkas yang sempat terunggah telah dibersihkan, silakan coba lagi.');
            redirect('aktivitas/form.php' . ($is_edit ? '?id=' . $id : ''));
        }

        redirect('aktivitas/index.php');
    }
}

// Panel info read-only di bawah selalu diambil ulang dari DB, tidak pernah dari field tersembunyi.
$lokasi_id_terpilih = (int) ($_POST['lokasi_id'] ?? ($is_edit ? $aktivitas['lokasi_id'] : 0));
$lokasi_terpilih = $lokasi_id_terpilih > 0 ? repo_lokasi_find($lokasi_id_terpilih) : null;
$kontraktor_terpilih = $lokasi_terpilih !== null ? repo_kontraktor_find((int) $lokasi_terpilih['kontraktor_id']) : null;

$page_title = $is_edit ? 'Ubah Aktivitas' : 'Input Aktivitas';
$layout = 'app';
$active_menu = $user['role'] === 'admin' ? 'aktivitas' : 'input_aktivitas';
require __DIR__ . '/../includes/header.php';
?>
<div class="ds-page">
  <div class="ds-page-header">
    <h1 class="ds-page-header__title"><?= e($page_title) ?></h1>
  </div>

  <?php if ($status_warning !== null): ?>
  <p class="flash flash--warning"><?= e($status_warning) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= e(url('aktivitas/form.php' . ($is_edit ? '?id=' . $id : ''))) ?>" enctype="multipart/form-data" data-validate novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="_form_token" value="<?= e(form_token()) ?>">

    <div class="ds-grid-2">
      <div class="ds-card">
        <h2 class="ds-card__title">Data Aktivitas</h2>

        <?php $fn = 'lokasi_id'; ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="lokasi_id">Lokasi <span class="ds-field__required">*</span></label>
          <select class="ds-select" id="lokasi_id" name="lokasi_id" required<?= field_aria($errors, $fn) ?>>
            <option value="">Pilih lokasi</option>
            <?php foreach ($daftar_lokasi as $l): ?>
            <option value="<?= e((string) $l['id']) ?>" <?php if ($lokasi_id_terpilih === (int) $l['id']): ?>selected<?php endif; ?>><?= e($l['nama_lokasi']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($daftar_lokasi === []): ?>
          <p class="ds-field__hint">Belum ada lokasi yang terdaftar untuk perusahaan Anda. Hubungi admin.</p>
          <?php endif; ?>
          <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="lokasi_id-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>

        <div class="ds-field">
          <span class="ds-field__label">Detail Lokasi Terpilih</span>
          <?php if ($lokasi_terpilih !== null): ?>
          <p class="ds-field__hint">
            Kontraktor: <?= e($kontraktor_terpilih['nama_perusahaan'] ?? 'Tidak ada data') ?><br>
            Koordinat: <?= e((string) $lokasi_terpilih['latitude']) ?>, <?= e((string) $lokasi_terpilih['longitude']) ?><br>
            Status Izin:
            <?php if ($lokasi_terpilih['status_izin'] === 'berizin'): ?>
            <span class="ds-badge ds-badge--berizin">Berizin</span>
            <?php elseif ($lokasi_terpilih['status_izin'] === 'tidak_berizin'): ?>
            <span class="ds-badge ds-badge--tidak-berizin">Tidak Berizin</span>
            <?php else: ?>
            <span class="ds-badge ds-badge--proses">Proses</span>
            <?php endif; ?>
          </p>
          <?php else: ?>
          <p class="ds-field__hint">Pilih lokasi untuk melihat detailnya di sini.</p>
          <?php endif; ?>
        </div>

        <div class="ds-grid-2">
          <?php $fn = 'tanggal_aktivitas'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="tanggal_aktivitas">Tanggal Aktivitas <span class="ds-field__required">*</span></label>
            <input class="ds-input" type="date" id="tanggal_aktivitas" name="tanggal_aktivitas" required max="<?= e(date('Y-m-d')) ?>"
              value="<?= e($is_edit ? old('tanggal_aktivitas', (string) $aktivitas['tanggal_aktivitas']) : old('tanggal_aktivitas')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="tanggal_aktivitas-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
          <?php $fn = 'jenis_kegiatan'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="jenis_kegiatan">Jenis Kegiatan <span class="ds-field__required">*</span></label>
            <input class="ds-input" type="text" id="jenis_kegiatan" name="jenis_kegiatan" required maxlength="100"
              value="<?= e($is_edit ? old('jenis_kegiatan', (string) $aktivitas['jenis_kegiatan']) : old('jenis_kegiatan')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="jenis_kegiatan-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="ds-grid-2">
          <?php $fn = 'material_id'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="material_id">Jenis Material <span class="ds-field__required">*</span></label>
            <?php $material_terpilih = (int) ($_POST['material_id'] ?? ($is_edit ? $aktivitas['material_id'] : 0)); ?>
            <select class="ds-select" id="material_id" name="material_id" required<?= field_aria($errors, $fn) ?>>
              <option value="">Pilih material</option>
              <?php foreach ($daftar_material as $m): ?>
              <option value="<?= e((string) $m['id']) ?>" <?php if ($material_terpilih === (int) $m['id']): ?>selected<?php endif; ?>><?= e($m['nama']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="material_id-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
          <?php $fn = 'status_aktivitas'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="status_aktivitas">Status Aktivitas <span class="ds-field__required">*</span></label>
            <?php $status_terpilih = (string) ($_POST['status_aktivitas'] ?? ($is_edit ? $aktivitas['status_aktivitas'] : 'aktif')); ?>
            <select class="ds-select" id="status_aktivitas" name="status_aktivitas" required<?= field_aria($errors, $fn) ?>>
              <option value="aktif" <?php if ($status_terpilih === 'aktif'): ?>selected<?php endif; ?>>Aktif</option>
              <option value="berhenti_sementara" <?php if ($status_terpilih === 'berhenti_sementara'): ?>selected<?php endif; ?>>Berhenti Sementara</option>
              <option value="selesai" <?php if ($status_terpilih === 'selesai'): ?>selected<?php endif; ?>>Selesai</option>
            </select>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="status_aktivitas-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="ds-grid-2">
          <?php $fn = 'jumlah_alat_berat'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="jumlah_alat_berat">Jumlah Alat Berat</label>
            <input class="ds-input" type="number" id="jumlah_alat_berat" name="jumlah_alat_berat" min="0" step="1"
              value="<?= e($is_edit ? old('jumlah_alat_berat', (string) $aktivitas['jumlah_alat_berat']) : old('jumlah_alat_berat', '0')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="jumlah_alat_berat-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
          <?php $fn = 'jumlah_pekerja'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="jumlah_pekerja">Jumlah Pekerja</label>
            <input class="ds-input" type="number" id="jumlah_pekerja" name="jumlah_pekerja" min="0" step="1"
              value="<?= e($is_edit ? old('jumlah_pekerja', (string) $aktivitas['jumlah_pekerja']) : old('jumlah_pekerja', '0')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="jumlah_pekerja-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <?php $fn = 'deskripsi'; ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="deskripsi">Keterangan</label>
          <textarea class="ds-textarea" id="deskripsi" name="deskripsi"<?= field_aria($errors, $fn) ?>><?= e($is_edit ? old('deskripsi', (string) ($aktivitas['deskripsi'] ?? '')) : old('deskripsi')) ?></textarea>
          <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="deskripsi-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="ds-card">
        <h2 class="ds-card__title">Foto Dokumentasi</h2>

        <?php $fn = 'foto'; ?>
        <?php $foto_lama = $is_edit ? repo_foto_list_by_aktivitas($id) : []; ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="foto">Unggah Foto (JPG/PNG, maks <?= e((string) (int) (UPLOAD_MAX_SIZE / 1024 / 1024)) ?> MB/foto, maks <?= e((string) UPLOAD_MAX_FILES) ?> foto)</label>
          <div class="ds-dropzone" id="foto-dropzone">
            <svg class="ds-dropzone__icon" width="28" height="28" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
              <path d="M12 16V4M12 4 7 9M12 4l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p class="ds-dropzone__text">Seret &amp; lepas foto di sini, atau klik untuk memilih berkas</p>
            <input
              type="file" id="foto" name="foto[]" accept="image/jpeg,image/png" multiple
              data-foto-preview
              data-max-files="<?= e((string) UPLOAD_MAX_FILES) ?>"
              data-existing-count="<?= e((string) count($foto_lama)) ?>"
              data-preview-target="foto-preview-grid"
              data-count-target="foto-preview-count"
              aria-describedby="foto-preview-count<?= isset($errors[$fn]) ? ' foto-error' : '' ?>"
              <?= isset($errors[$fn]) ? 'aria-invalid="true"' : '' ?>
            >
          </div>
          <p class="ds-field__hint" id="foto-preview-count" aria-live="polite"></p>
          <ul class="ds-photo-grid" id="foto-preview-grid"></ul>
          <?php if (isset($errors[$fn])): ?>
          <div id="foto-error" role="alert">
          <?php foreach ($errors[$fn] as $pesan): ?>
          <p class="ds-field__error"><?= e($pesan) ?></p>
          <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if ($foto_lama !== []): ?>
        <div class="ds-field">
          <span class="ds-field__label">Foto Tersimpan (<?= e((string) count($foto_lama)) ?>)</span>
          <ul class="ds-photo-grid">
            <?php foreach ($foto_lama as $f): ?>
            <li class="ds-photo-grid__item">
              <img class="ds-photo-grid__thumb" src="<?= e(url('media.php?id=' . $f['id'] . '&size=thumb')) ?>" alt="Foto dokumentasi aktivitas" loading="lazy">
              <button type="button" class="ds-btn ds-btn--danger ds-btn--sm" data-dialog-open="dialog-hapus-foto-<?= e((string) $f['id']) ?>">Hapus</button>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="ds-form-actions">
      <button type="submit" class="ds-btn ds-btn--primary"><?php if ($is_edit): ?>Simpan Perubahan<?php else: ?>Simpan Aktivitas<?php endif; ?></button>
      <a class="ds-btn ds-btn--secondary" href="<?= e(url('aktivitas/index.php')) ?>">Batal</a>
    </div>
  </form>

  <?php // Di luar <form> utama -- form tidak boleh bersarang, atau submit utama rusak akibat parser browser. ?>
  <?php foreach ($foto_lama as $f): ?>
  <dialog class="modal" id="dialog-hapus-foto-<?= e((string) $f['id']) ?>" aria-labelledby="dialog-hapus-foto-<?= e((string) $f['id']) ?>-title">
    <div class="modal__body">
      <h2 class="modal__title" id="dialog-hapus-foto-<?= e((string) $f['id']) ?>-title">Hapus foto ini?</h2>
      <p>Tindakan ini tidak dapat dibatalkan. Foto akan terhapus permanen dari aktivitas ini.</p>
      <div class="modal__actions">
        <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Batal</button></form>
        <form method="post" action="<?= e(url('aktivitas/hapus_foto.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= e((string) $f['id']) ?>">
          <input type="hidden" name="back" value="<?= e('aktivitas/form.php?id=' . $id) ?>">
          <button type="submit" class="btn btn--danger">Ya, Hapus</button>
        </form>
      </div>
    </div>
  </dialog>
  <?php endforeach; ?>
</div>

<?php
$extra_scripts = [
    '<script src="' . e(asset('assets/js/foto-preview.js')) . '"></script>',
];
require __DIR__ . '/../includes/footer.php';
