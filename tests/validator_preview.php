<?php

declare(strict_types=1);

/**
 * tests/validator_preview.php — pratinjau T-07: includes/validator.php +
 * assets/js/validation.js terpasang ke satu form nyata (mirip form lokasi,
 * FR-07/08/09). BUKAN halaman asli -- lokasi/form.php sungguhan baru di T-09.
 * Harus 404 di luar APP_ENV=dev. WAJIB dihapus/diarahkan ulang begitu T-09 ada.
 *
 * Akses: /tests/validator_preview.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (APP_ENV !== 'dev') {
    http_response_code(404);
    exit('Not Found');
}

$labels = [
    'nama_lokasi' => 'nama lokasi',
    'latitude' => 'latitude',
    'longitude' => 'longitude',
    'luas_ha' => 'luas (ha)',
    'tanggal_berlaku_izin' => 'tanggal berlaku izin',
    'tanggal_berakhir_izin' => 'tanggal berakhir izin',
    'email_kontraktor' => 'email kontraktor',
    'telepon_kontraktor' => 'telepon kontraktor',
];

$rules = [
    'nama_lokasi' => 'required|max:150',
    'luas_ha' => 'numeric|min:0',
    'tanggal_berlaku_izin' => 'date',
    'tanggal_berakhir_izin' => 'date|after_or_equal:tanggal_berlaku_izin',
    'email_kontraktor' => 'email',
    'telepon_kontraktor' => 'phone',
];

$errors = [];
$warnings = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();

    $hasil = validate($_POST, $rules, $labels);
    $errors = $hasil['errors'];

    $koordinat = validate_koordinat($_POST['latitude'] ?? null, $_POST['longitude'] ?? null);
    if ($koordinat['errors'] !== []) {
        $errors = array_merge_recursive($errors, $koordinat['errors']);
    }
    $warnings = $koordinat['warnings'];

    if ($errors === []) {
        flash('success', 'Validasi lolos. Data TIDAK disimpan (ini hanya pratinjau T-07).');
        redirect('tests/validator_preview.php');
    }
}

$page_title = 'Pratinjau Validator (T-07)';
$layout = 'app';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="app-main__title">Pratinjau Validator — T-07</h1>

<?php foreach ($warnings as $field_warnings): ?>
  <?php foreach ($field_warnings as $pesan): ?>
  <div class="flash flash--warning"><span class="flash__message"><?= e($pesan) ?></span></div>
  <?php endforeach; ?>
<?php endforeach; ?>

<form method="post" action="<?= e(url('tests/validator_preview.php')) ?>" class="card" data-validate novalidate>
  <?= csrf_field() ?>

  <?php $fn = 'nama_lokasi'; ?>
  <div class="field <?= e(field_error_class($errors, $fn)) ?>">
    <label class="field__label" for="nama_lokasi">Nama Lokasi <span class="field__required">*</span></label>
    <input class="input" type="text" id="nama_lokasi" name="nama_lokasi" value="<?= e(old('nama_lokasi')) ?>" required maxlength="150">
    <?php if (isset($errors[$fn])): ?><p class="field__error" id="nama_lokasi-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
  </div>

  <div class="grid-2">
    <?php $fn = 'latitude'; ?>
    <div class="field <?= e(field_error_class($errors, $fn)) ?>">
      <label class="field__label" for="latitude">Latitude</label>
      <input class="input" type="text" id="latitude" name="latitude" value="<?= e(old('latitude', '-0,8762')) ?>">
      <p class="field__hint">Terima koma desimal, contoh: -0,8762.</p>
      <?php if (isset($errors[$fn])): ?><p class="field__error" id="latitude-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
    </div>
    <?php $fn = 'longitude'; ?>
    <div class="field <?= e(field_error_class($errors, $fn)) ?>">
      <label class="field__label" for="longitude">Longitude</label>
      <input class="input" type="text" id="longitude" name="longitude" value="<?= e(old('longitude', '131,2551')) ?>">
      <?php if (isset($errors[$fn])): ?><p class="field__error" id="longitude-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
    </div>
  </div>

  <?php $fn = 'luas_ha'; ?>
  <div class="field <?= e(field_error_class($errors, $fn)) ?>">
    <label class="field__label" for="luas_ha">Luas (ha)</label>
    <input class="input" type="text" id="luas_ha" name="luas_ha" value="<?= e(old('luas_ha')) ?>">
    <?php if (isset($errors[$fn])): ?><p class="field__error" id="luas_ha-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
  </div>

  <div class="grid-2">
    <?php $fn = 'tanggal_berlaku_izin'; ?>
    <div class="field <?= e(field_error_class($errors, $fn)) ?>">
      <label class="field__label" for="tanggal_berlaku_izin">Tanggal Berlaku Izin</label>
      <input class="input" type="date" id="tanggal_berlaku_izin" name="tanggal_berlaku_izin" value="<?= e(old('tanggal_berlaku_izin')) ?>">
      <?php if (isset($errors[$fn])): ?><p class="field__error" id="tanggal_berlaku_izin-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
    </div>
    <?php $fn = 'tanggal_berakhir_izin'; ?>
    <div class="field <?= e(field_error_class($errors, $fn)) ?>">
      <label class="field__label" for="tanggal_berakhir_izin">Tanggal Berakhir Izin</label>
      <input class="input" type="date" id="tanggal_berakhir_izin" name="tanggal_berakhir_izin" value="<?= e(old('tanggal_berakhir_izin')) ?>">
      <?php if (isset($errors[$fn])): ?><p class="field__error" id="tanggal_berakhir_izin-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="grid-2">
    <?php $fn = 'email_kontraktor'; ?>
    <div class="field <?= e(field_error_class($errors, $fn)) ?>">
      <label class="field__label" for="email_kontraktor">Email Kontraktor</label>
      <input class="input" type="email" id="email_kontraktor" name="email_kontraktor" value="<?= e(old('email_kontraktor')) ?>">
      <?php if (isset($errors[$fn])): ?><p class="field__error" id="email_kontraktor-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
    </div>
    <?php $fn = 'telepon_kontraktor'; ?>
    <div class="field <?= e(field_error_class($errors, $fn)) ?>">
      <label class="field__label" for="telepon_kontraktor">Telepon Kontraktor</label>
      <input class="input" type="text" id="telepon_kontraktor" name="telepon_kontraktor" value="<?= e(old('telepon_kontraktor')) ?>">
      <?php if (isset($errors[$fn])): ?><p class="field__error" id="telepon_kontraktor-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn--primary">Validasi (tidak disimpan)</button>
  </div>
</form>

<?php
require __DIR__ . '/../includes/footer.php';
