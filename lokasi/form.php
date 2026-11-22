<?php

declare(strict_types=1);

/**
 * lokasi/form.php — tambah & ubah lokasi tambang (FR-07, FR-08, FR-09, FR-10;
 * T-09 + T-10). Picker peta (T-10) murni UX lewat assets/js/map-picker.js --
 * input lat/lng/area_geojson di bawah tetap field biasa yang berfungsi penuh
 * tanpa JS (edge case wajib: Leaflet gagal dimuat dari CDN).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/repo/lokasi.php';
require_once __DIR__ . '/../includes/repo/kontraktor.php';
require_once __DIR__ . '/../includes/repo/material.php';
require_once __DIR__ . '/../includes/repo/aktivitas.php';

$user = require_role('admin');

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$lokasi = null;

if ($id !== null) {
    $lokasi = repo_lokasi_find($id);
    if ($lokasi === null) {
        abort(404, 'Lokasi tidak ditemukan.');
    }
}

$is_edit = $lokasi !== null;
$errors = [];

$labels = [
    'nama_lokasi' => 'nama lokasi',
    'distrik' => 'distrik',
    'kelurahan' => 'kelurahan',
    'alamat' => 'alamat',
    'luas_ha' => 'luas (ha)',
    'status_izin' => 'status izin',
    'nomor_izin' => 'nomor izin',
    'tanggal_berlaku_izin' => 'tanggal berlaku izin',
    'tanggal_berakhir_izin' => 'tanggal berakhir izin',
    'keterangan' => 'keterangan',
];

$rules = [
    'nama_lokasi' => 'required|max:150',
    'distrik' => 'max:100',
    'kelurahan' => 'max:100',
    'alamat' => 'max:1000',
    'luas_ha' => 'numeric|min:0',
    'status_izin' => 'required|in:berizin,tidak_berizin,proses',
    'nomor_izin' => 'max:100',
    'tanggal_berlaku_izin' => 'date',
    'tanggal_berakhir_izin' => 'date|after_or_equal:tanggal_berlaku_izin',
    'keterangan' => 'max:2000',
];

$status_warning = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();

    if (!form_token_consume($_POST['_form_token'] ?? null)) {
        // Edge case T-09: double submit (klik ganda/kirim ulang). Token sudah
        // dipakai -> anggap submit PERTAMA sudah/tengah diproses, jangan buat
        // baris kedua. Diam-diam arahkan ke daftar, bukan pesan error menakutkan.
        flash('warning', 'Formulir ini sudah diproses sebelumnya.');
        redirect('lokasi/index.php');
    }

    $hasil = validate($_POST, $rules, $labels);
    $errors = $hasil['errors'];
    $data = $hasil['data'];

    // kontraktor_id & material_id: FK, bukan field skalar biasa -- divalidasi
    // keberadaannya sendiri (bukan lewat aturan 'in:' yang perlu daftar statis).
    $kontraktor_id = (int) ($_POST['kontraktor_id'] ?? 0);
    if ($kontraktor_id <= 0 || repo_kontraktor_find($kontraktor_id) === null) {
        $errors['kontraktor_id'][] = 'Kontraktor wajib dipilih dan harus valid.';
    }
    $material_id = (int) ($_POST['material_id'] ?? 0);
    if ($material_id <= 0 || repo_material_find($material_id) === null) {
        $errors['material_id'][] = 'Jenis material wajib dipilih dan harus valid.';
    }

    // Aturan bisnis status izin (PRD bagian 7 & spesifikasi T-09), ditegakkan
    // di SERVER terlepas apa pun yang dikirim klien:
    if ($data['status_izin'] === 'berizin') {
        if ($data['nomor_izin'] === '') {
            $errors['nomor_izin'][] = 'Nomor izin wajib diisi untuk status Berizin.';
        }
        if ($data['tanggal_berlaku_izin'] === '') {
            $errors['tanggal_berlaku_izin'][] = 'Tanggal berlaku izin wajib diisi untuk status Berizin.';
        }
    } elseif ($data['status_izin'] === 'tidak_berizin') {
        // "Tidak berizin" MENGOSONGKAN field izin apa pun yang terlanjur
        // terkirim dari form -- bukan sekadar membiarkannya opsional.
        $data['nomor_izin'] = '';
        $data['tanggal_berlaku_izin'] = '';
        $data['tanggal_berakhir_izin'] = '';
    }

    $koordinat = validate_koordinat($_POST['latitude'] ?? null, $_POST['longitude'] ?? null);
    $errors = array_merge($errors, $koordinat['errors']);

    $geojson_hasil = validate_geojson_polygon(is_string($_POST['area_geojson'] ?? null) ? $_POST['area_geojson'] : null);
    $errors = array_merge($errors, $geojson_hasil['errors']);

    $area_geojson_tersimpan = null;
    if ($geojson_hasil['geojson'] !== null) {
        // "properties" GeoJSON WAJIB objek ({}), bukan array ([]) -- json_decode(...,
        // true) tidak bisa membedakan objek kosong dari array kosong, jadi di-cast
        // (object) secara eksplisit sebelum di-encode ulang untuk disimpan.
        $geojson_untuk_simpan = $geojson_hasil['geojson'];
        $geojson_untuk_simpan['properties'] = (object) ($geojson_untuk_simpan['properties'] ?? []);
        $area_geojson_tersimpan = json_encode($geojson_untuk_simpan);
    }

    if ($errors === []) {
        // Edge case T-09 (mass assignment): array di bawah dibangun EKSPLISIT
        // field demi field dari nilai yang SUDAH divalidasi -- tidak pernah
        // $_POST (atau bahkan $data hasil validate() mentah) disebar langsung
        // ke repo_lokasi_create()/update(), yang sendiri juga hanya membaca
        // key bernama persis (lihat includes/repo/lokasi.php) sebagai lapis kedua.
        $lokasi_data = [
            'nama_lokasi' => $data['nama_lokasi'],
            'kontraktor_id' => $kontraktor_id,
            'material_id' => $material_id,
            'distrik' => $data['distrik'] !== '' ? $data['distrik'] : null,
            'kelurahan' => $data['kelurahan'] !== '' ? $data['kelurahan'] : null,
            'alamat' => $data['alamat'] !== '' ? $data['alamat'] : null,
            'latitude' => $koordinat['latitude'],
            'longitude' => $koordinat['longitude'],
            'area_geojson' => $area_geojson_tersimpan,
            'luas_ha' => $data['luas_ha'] !== '' ? $data['luas_ha'] : null,
            'status_izin' => $data['status_izin'],
            'nomor_izin' => $data['nomor_izin'] !== '' ? $data['nomor_izin'] : null,
            'tanggal_berlaku_izin' => $data['tanggal_berlaku_izin'] !== '' ? $data['tanggal_berlaku_izin'] : null,
            'tanggal_berakhir_izin' => $data['tanggal_berakhir_izin'] !== '' ? $data['tanggal_berakhir_izin'] : null,
            'keterangan' => $data['keterangan'] !== '' ? $data['keterangan'] : null,
        ];

        if ($is_edit) {
            // Edge case T-09: lokasi berizin -> tidak_berizin yang SUDAH punya
            // riwayat aktivitas. Tidak diblokir (data lama tetap sah sebagai
            // riwayat), hanya diberi tahu ke admin lewat flash setelah simpan.
            if ($lokasi['status_izin'] === 'berizin' && $lokasi_data['status_izin'] === 'tidak_berizin') {
                $jumlah_aktivitas = repo_aktivitas_count_by_lokasi($id);
                if ($jumlah_aktivitas > 0) {
                    $status_warning = "Catatan: lokasi ini memiliki {$jumlah_aktivitas} aktivitas yang tercatat saat masih berizin. Riwayat itu tetap tersimpan.";
                }
            }

            repo_lokasi_update($id, $lokasi_data);
            flash('success', "Lokasi \"{$lokasi_data['nama_lokasi']}\" berhasil diperbarui." . ($status_warning ? ' ' . $status_warning : ''));
            redirect('lokasi/index.php');
        } else {
            // created_by WAJIB dari sesi admin yang login, bukan dari form
            // (edge case T-09: mass assignment/pemalsuan created_by).
            $lokasi_data['created_by'] = $user['id'];
            repo_lokasi_create($lokasi_data);
            flash('success', "Lokasi \"{$lokasi_data['nama_lokasi']}\" berhasil ditambahkan.");
            redirect('lokasi/index.php');
        }
    }
}

[$daftar_kontraktor] = repo_kontraktor_list(1, 200, null);
$daftar_material = repo_material_list();

$page_title = $is_edit ? 'Ubah Lokasi Tambang' : 'Tambah Lokasi Tambang';
$layout = 'app';
$active_menu = 'lokasi';
$extra_head = [
    '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">',
    '<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">',
];
require __DIR__ . '/../includes/header.php';
?>
<div class="ds-page">
  <div class="ds-page-header">
    <h1 class="ds-page-header__title"><?= e($page_title) ?></h1>
  </div>

  <form method="post" action="<?= e(url('lokasi/form.php' . ($is_edit ? '?id=' . $id : ''))) ?>" data-validate novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="_form_token" value="<?= e(form_token()) ?>">

    <div class="ds-grid-2">
      <div class="ds-card">
        <h2 class="ds-card__title">Data Lokasi</h2>

        <?php $fn = 'nama_lokasi'; ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="nama_lokasi">Nama Lokasi <span class="ds-field__required">*</span></label>
          <input class="ds-input" type="text" id="nama_lokasi" name="nama_lokasi" required maxlength="150"
            value="<?= e($is_edit ? old('nama_lokasi', (string) $lokasi['nama_lokasi']) : old('nama_lokasi')) ?>"<?= field_aria($errors, $fn) ?>>
          <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="nama_lokasi-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>

        <div class="ds-grid-2">
          <?php $fn = 'kontraktor_id'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="kontraktor_id">Kontraktor <span class="ds-field__required">*</span></label>
            <?php $kontraktor_terpilih = (int) ($_POST['kontraktor_id'] ?? ($is_edit ? $lokasi['kontraktor_id'] : 0)); ?>
            <select class="ds-select" id="kontraktor_id" name="kontraktor_id" required<?= field_aria($errors, $fn) ?>>
              <option value="">Pilih kontraktor</option>
              <?php foreach ($daftar_kontraktor as $k): ?>
              <option value="<?= e((string) $k['id']) ?>" <?php if ($kontraktor_terpilih === (int) $k['id']): ?>selected<?php endif; ?>><?= e($k['nama_perusahaan']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="kontraktor_id-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
          <?php $fn = 'material_id'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="material_id">Jenis Material <span class="ds-field__required">*</span></label>
            <?php $material_terpilih = (int) ($_POST['material_id'] ?? ($is_edit ? $lokasi['material_id'] : 0)); ?>
            <select class="ds-select" id="material_id" name="material_id" required<?= field_aria($errors, $fn) ?>>
              <option value="">Pilih material</option>
              <?php foreach ($daftar_material as $m): ?>
              <option value="<?= e((string) $m['id']) ?>" <?php if ($material_terpilih === (int) $m['id']): ?>selected<?php endif; ?>><?= e($m['nama']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="material_id-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="ds-grid-2">
          <?php $fn = 'distrik'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="distrik">Distrik</label>
            <input class="ds-input" type="text" id="distrik" name="distrik" maxlength="100"
              value="<?= e($is_edit ? old('distrik', (string) ($lokasi['distrik'] ?? '')) : old('distrik')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="distrik-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
          <?php $fn = 'kelurahan'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="kelurahan">Kelurahan</label>
            <input class="ds-input" type="text" id="kelurahan" name="kelurahan" maxlength="100"
              value="<?= e($is_edit ? old('kelurahan', (string) ($lokasi['kelurahan'] ?? '')) : old('kelurahan')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="kelurahan-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <?php $fn = 'alamat'; ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="alamat">Alamat</label>
          <textarea class="ds-textarea" id="alamat" name="alamat"<?= field_aria($errors, $fn) ?>><?= e($is_edit ? old('alamat', (string) ($lokasi['alamat'] ?? '')) : old('alamat')) ?></textarea>
          <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="alamat-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>

        <?php $fn = 'status_izin'; ?>
        <?php $status_terpilih = (string) ($_POST['status_izin'] ?? ($is_edit ? $lokasi['status_izin'] : 'proses')); ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="status_izin">Status Izin <span class="ds-field__required">*</span></label>
          <select class="ds-select" id="status_izin" name="status_izin" required<?= field_aria($errors, $fn) ?>>
            <option value="proses" <?php if ($status_terpilih === 'proses'): ?>selected<?php endif; ?>>Proses</option>
            <option value="berizin" <?php if ($status_terpilih === 'berizin'): ?>selected<?php endif; ?>>Berizin</option>
            <option value="tidak_berizin" <?php if ($status_terpilih === 'tidak_berizin'): ?>selected<?php endif; ?>>Tidak Berizin</option>
          </select>
          <p class="ds-field__hint">Status "Berizin" mewajibkan Nomor Izin &amp; Tanggal Berlaku. Status "Tidak Berizin" akan mengosongkan ketiga field izin di bawah.</p>
          <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="status_izin-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>

        <?php $fn = 'nomor_izin'; ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="nomor_izin">Nomor Izin (SIPB)</label>
          <input class="ds-input" type="text" id="nomor_izin" name="nomor_izin" maxlength="100"
            value="<?= e($is_edit ? old('nomor_izin', (string) ($lokasi['nomor_izin'] ?? '')) : old('nomor_izin')) ?>"<?= field_aria($errors, $fn) ?>>
          <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="nomor_izin-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>

        <div class="ds-grid-2">
          <?php $fn = 'tanggal_berlaku_izin'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="tanggal_berlaku_izin">Tanggal Berlaku Izin</label>
            <input class="ds-input" type="date" id="tanggal_berlaku_izin" name="tanggal_berlaku_izin"
              value="<?= e($is_edit ? old('tanggal_berlaku_izin', (string) ($lokasi['tanggal_berlaku_izin'] ?? '')) : old('tanggal_berlaku_izin')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="tanggal_berlaku_izin-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
          <?php $fn = 'tanggal_berakhir_izin'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="tanggal_berakhir_izin">Tanggal Berakhir Izin</label>
            <input class="ds-input" type="date" id="tanggal_berakhir_izin" name="tanggal_berakhir_izin"
              value="<?= e($is_edit ? old('tanggal_berakhir_izin', (string) ($lokasi['tanggal_berakhir_izin'] ?? '')) : old('tanggal_berakhir_izin')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="tanggal_berakhir_izin-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <?php $fn = 'luas_ha'; ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="luas_ha">Luas (ha)</label>
          <input class="ds-input" type="text" id="luas_ha" name="luas_ha"
            value="<?= e($is_edit ? old('luas_ha', (string) ($lokasi['luas_ha'] ?? '')) : old('luas_ha')) ?>"<?= field_aria($errors, $fn) ?>>
          <p class="ds-field__hint">Terisi otomatis dari poligon di peta (bila digambar), boleh diubah manual.</p>
          <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="luas_ha-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>

        <?php $fn = 'keterangan'; ?>
        <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
          <label class="ds-field__label" for="keterangan">Keterangan</label>
          <textarea class="ds-textarea" id="keterangan" name="keterangan"<?= field_aria($errors, $fn) ?>><?= e($is_edit ? old('keterangan', (string) ($lokasi['keterangan'] ?? '')) : old('keterangan')) ?></textarea>
          <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="keterangan-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="ds-card">
        <h2 class="ds-card__title">Titik &amp; Area di Peta</h2>

        <p class="ds-field__hint" id="peta-picker-fallback" hidden>
          Peta tidak dapat dimuat (kemungkinan CDN Leaflet tidak terjangkau). Isi koordinat secara manual di bawah ini.
        </p>
        <p class="ds-field__error" role="alert" id="peta-warning" hidden>Koordinat di luar perkiraan wilayah Kota Sorong, periksa kembali sebelum menyimpan.</p>
        <!-- Edge case wajib T-25: peta ini bisa digeser/zoom dengan satu jari
             (dibutuhkan untuk memilih titik), sehingga ikut menangkap gestur
             gulir satu jari yang dimulai di atasnya. Field latitude/longitude
             di bawah tetap berfungsi penuh tanpa menyentuh peta sama sekali
             (edge case wajib T-10), jadi ini sekadar petunjuk, bukan satu-
             satunya jalan. -->
        <p class="ds-field__hint">Peta bisa digeser/diperbesar langsung dengan sentuhan. Untuk menggulir halaman di perangkat sentuh, mulai gulir dari luar area peta, atau isi Latitude/Longitude secara manual di bawah.</p>

        <div
          id="peta-picker"
          class="map-shell map-shell--form"
          data-lat-input="latitude"
          data-lng-input="longitude"
          data-geojson-input="area_geojson"
          data-luas-input="luas_ha"
          data-warning-el="peta-warning"
          data-sorong-lat-min="<?= e((string) SORONG_LAT_MIN) ?>"
          data-sorong-lat-max="<?= e((string) SORONG_LAT_MAX) ?>"
          data-sorong-lng-min="<?= e((string) SORONG_LNG_MIN) ?>"
          data-sorong-lng-max="<?= e((string) SORONG_LNG_MAX) ?>"
          data-initial-lat="<?= e($is_edit ? (string) $lokasi['latitude'] : '') ?>"
          data-initial-lng="<?= e($is_edit ? (string) $lokasi['longitude'] : '') ?>"
          data-initial-geojson="<?= e($is_edit && !empty($lokasi['area_geojson']) ? (string) $lokasi['area_geojson'] : '') ?>"
          data-boundary-url="<?= e(url('assets/geojson/batas_kota_sorong.geojson')) ?>"
        ></div>

        <div class="ds-grid-2 u-mt-4">
          <?php $fn = 'latitude'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="latitude">Latitude <span class="ds-field__required">*</span></label>
            <input class="ds-input" type="text" id="latitude" name="latitude" required
              value="<?= e($is_edit ? old('latitude', (string) $lokasi['latitude']) : old('latitude')) ?>"<?= field_aria($errors, $fn) ?>>
            <p class="ds-field__hint">Klik peta atau isi manual, lalu tekan Enter/pindah fokus untuk sinkron ke peta.</p>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="latitude-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
          <?php $fn = 'longitude'; ?>
          <div class="ds-field <?= e(field_error_class($errors, $fn)) ?>">
            <label class="ds-field__label" for="longitude">Longitude <span class="ds-field__required">*</span></label>
            <input class="ds-input" type="text" id="longitude" name="longitude" required
              value="<?= e($is_edit ? old('longitude', (string) $lokasi['longitude']) : old('longitude')) ?>"<?= field_aria($errors, $fn) ?>>
            <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert" id="longitude-error"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <?php $fn = 'area_geojson'; ?>
        <input type="hidden" id="area_geojson" name="area_geojson" value="<?= e($is_edit ? old('area_geojson', (string) ($lokasi['area_geojson'] ?? '')) : old('area_geojson')) ?>">
        <?php if (isset($errors[$fn])): ?><p class="ds-field__error" role="alert"><?= e($errors[$fn][0]) ?></p><?php endif; ?>
      </div>
    </div>

    <div class="ds-form-actions">
      <button type="submit" class="ds-btn ds-btn--primary"><?php if ($is_edit): ?>Simpan Perubahan<?php else: ?>Simpan Lokasi<?php endif; ?></button>
      <a class="ds-btn ds-btn--secondary" href="<?= e(url('lokasi/index.php')) ?>">Batal</a>
    </div>
  </form>
</div>

<?php
$extra_scripts = [
    '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>',
    '<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>',
    '<script src="' . e(asset('assets/js/map-picker.js')) . '"></script>',
];
require __DIR__ . '/../includes/footer.php';
