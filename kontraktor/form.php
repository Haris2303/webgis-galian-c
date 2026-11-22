<?php

declare(strict_types=1);

/**
 * kontraktor/form.php — tambah (FR-11+FR-12 sekaligus, atomik) & ubah profil
 * kontraktor (FR-11 saja). Username/password HANYA ada di mode tambah --
 * reset password & nonaktifkan akun ada di kontraktor/index.php (T-08).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/repo/users.php';

require_role('admin');

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$kontraktor = null;

if ($id !== null) {
    $kontraktor = repo_kontraktor_find($id);
    if ($kontraktor === null) {
        abort(404, 'Kontraktor tidak ditemukan.');
    }
}

$is_edit = $kontraktor !== null;
$errors = [];

$labels = [
    'nama_perusahaan' => 'nama perusahaan',
    'penanggung_jawab' => 'penanggung jawab',
    'telepon' => 'telepon',
    'email' => 'email',
    'alamat' => 'alamat',
    'username' => 'username',
];

$rules = [
    'nama_perusahaan' => 'required|max:150',
    'penanggung_jawab' => 'required|max:100',
    'telepon' => 'phone',
    'email' => 'email',
    'alamat' => 'max:1000',
];
if (!$is_edit) {
    $rules['username'] = ['required', 'min:4', 'max:50', 'regex:/^[a-zA-Z0-9_.]+$/'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();

    $hasil = validate($_POST, $rules, $labels);
    $errors = $hasil['errors'];
    $data = $hasil['data'];

    if ($errors === [] && $is_edit) {
        repo_kontraktor_update($id, $data);
        flash('success', "Data kontraktor \"{$data['nama_perusahaan']}\" berhasil diperbarui.");
        redirect('kontraktor/index.php');
    }

    if ($errors === [] && !$is_edit) {
        try {
            $hasil_simpan = db_transaction(function () use ($data) {
                $kontraktor_id = repo_kontraktor_create($data);

                $password_baru = generate_strong_password();
                repo_user_create([
                    'username' => $data['username'],
                    'password_hash' => password_hash($password_baru, PASSWORD_BCRYPT),
                    'nama_lengkap' => $data['penanggung_jawab'],
                    'role' => 'kontraktor',
                    'kontraktor_id' => $kontraktor_id,
                ]);

                return ['kontraktor_id' => $kontraktor_id, 'password' => $password_baru];
            });

            app_log('info', sprintf(
                'Admin menambah kontraktor id=%d "%s" + akun username="%s"',
                $hasil_simpan['kontraktor_id'],
                $data['nama_perusahaan'],
                $data['username']
            ));

            flash(
                'credential',
                sprintf('Kontraktor "%s" + akun login "%s" berhasil dibuat. Salin password sekarang, tidak akan ditampilkan lagi:', $data['nama_perusahaan'], $data['username']),
                ['password' => $hasil_simpan['password']]
            );
            redirect('kontraktor/index.php');
        } catch (PDOException $e) {
            // SQLSTATE 23000 / errno 1062 = duplicate entry pada UNIQUE KEY username
            // (database/schema.sql). Edge case T-08: dua admin menambah username
            // sama bersamaan -- SELECT cek-duluan tidak menutup race ini, hanya
            // constraint DB + penangkapan error di sini yang otoritatif. Transaksi
            // (T-03 db_transaction) SUDAH rollback otomatis sebelum exception ini
            // sampai ke sini, jadi tidak ada baris kontraktor yatim.
            if (($e->errorInfo[1] ?? null) === 1062) {
                $errors['username'][] = 'Username sudah digunakan, silakan pilih username lain.';
            } else {
                throw $e;
            }
        }
    }
}

$page_title = $is_edit ? 'Ubah Kontraktor' : 'Tambah Kontraktor';
$layout = 'app';
$active_menu = 'kontraktor';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="app-main__title"><?= e($page_title) ?></h1>

<form method="post" action="<?= e(url('kontraktor/form.php' . ($is_edit ? '?id=' . $id : ''))) ?>" class="card" data-validate novalidate>
  <?= csrf_field() ?>

  <?php $fn = 'nama_perusahaan'; ?>
  <div class="field <?= e(field_error_class($errors, $fn)) ?>">
    <label class="field__label" for="nama_perusahaan">Nama Perusahaan <span class="field__required">*</span></label>
    <input class="input" type="text" id="nama_perusahaan" name="nama_perusahaan" required maxlength="150"
      value="<?= e($is_edit ? old('nama_perusahaan', (string) $kontraktor['nama_perusahaan']) : old('nama_perusahaan')) ?>"<?= field_aria($errors, $fn) ?>>
    <?php if (isset($errors[$fn])): ?><p class="field__error" role="alert" id="nama_perusahaan-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
  </div>

  <?php $fn = 'penanggung_jawab'; ?>
  <div class="field <?= e(field_error_class($errors, $fn)) ?>">
    <label class="field__label" for="penanggung_jawab">Penanggung Jawab <span class="field__required">*</span></label>
    <input class="input" type="text" id="penanggung_jawab" name="penanggung_jawab" required maxlength="100"
      value="<?= e($is_edit ? old('penanggung_jawab', (string) $kontraktor['penanggung_jawab']) : old('penanggung_jawab')) ?>"<?= field_aria($errors, $fn) ?>>
    <?php if ($is_edit): ?><p class="field__hint">Nama ini juga dipakai sebagai nama akun login kontraktor saat dibuat, tapi TIDAK ikut berubah otomatis di sini.</p><?php endif; ?>
    <?php if (isset($errors[$fn])): ?><p class="field__error" role="alert" id="penanggung_jawab-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
  </div>

  <div class="grid-2">
    <?php $fn = 'telepon'; ?>
    <div class="field <?= e(field_error_class($errors, $fn)) ?>">
      <label class="field__label" for="telepon">Telepon</label>
      <input class="input" type="text" id="telepon" name="telepon"
        value="<?= e($is_edit ? old('telepon', (string) ($kontraktor['telepon'] ?? '')) : old('telepon')) ?>"<?= field_aria($errors, $fn) ?>>
      <?php if (isset($errors[$fn])): ?><p class="field__error" role="alert" id="telepon-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
    </div>
    <?php $fn = 'email'; ?>
    <div class="field <?= e(field_error_class($errors, $fn)) ?>">
      <label class="field__label" for="email">Email</label>
      <input class="input" type="email" id="email" name="email"
        value="<?= e($is_edit ? old('email', (string) ($kontraktor['email'] ?? '')) : old('email')) ?>"<?= field_aria($errors, $fn) ?>>
      <?php if (isset($errors[$fn])): ?><p class="field__error" role="alert" id="email-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
    </div>
  </div>

  <?php $fn = 'alamat'; ?>
  <div class="field <?= e(field_error_class($errors, $fn)) ?>">
    <label class="field__label" for="alamat">Alamat</label>
    <textarea class="textarea" id="alamat" name="alamat"<?= field_aria($errors, $fn) ?>><?= e($is_edit ? old('alamat', (string) ($kontraktor['alamat'] ?? '')) : old('alamat')) ?></textarea>
    <?php if (isset($errors[$fn])): ?><p class="field__error" role="alert" id="alamat-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
  </div>

  <?php if (!$is_edit): ?>
  <?php $fn = 'username'; ?>
  <div class="field <?= e(field_error_class($errors, $fn)) ?>">
    <label class="field__label" for="username">Username Akun Login <span class="field__required">*</span></label>
    <input class="input" type="text" id="username" name="username" required minlength="4" maxlength="50" pattern="[a-zA-Z0-9_.]+"
      value="<?= e(old('username')) ?>" autocomplete="off"<?= field_aria($errors, $fn) ?>>
    <p class="field__hint">Password dibuat otomatis oleh sistem dan ditampilkan sekali setelah kontraktor ini tersimpan — admin tidak mengetik password di sini.</p>
    <?php if (isset($errors[$fn])): ?><p class="field__error" role="alert" id="username-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="form-actions">
    <button type="submit" class="btn btn--primary"><?php if ($is_edit): ?>Simpan Perubahan<?php else: ?>Simpan Kontraktor + Buat Akun<?php endif; ?></button>
    <a class="btn btn--secondary" href="<?= e(url('kontraktor/index.php')) ?>">Batal</a>
  </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
