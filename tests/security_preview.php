<?php

declare(strict_types=1);

/**
 * tests/security_preview.php — pratinjau fondasi keamanan T-05, BUKAN login.php
 * asli (itu artefak T-06: "Login/logout, RBAC dua peran, dan guard kepemilikan
 * data"). Berkas ini sengaja memakai kredensial & tabel users sungguhan (bukan
 * data palsu) lewat includes/repo/users.php, supaya alur login/throttle yang
 * diuji di sini benar-benar representatif -- T-06 nanti bisa mengambil alih
 * logika yang sama ke login.php/logout.php, bukan menulis ulang dari nol.
 *
 * Harus 404 di luar APP_ENV=dev (lihat cek di bawah) dan WAJIB dihapus atau
 * diganti pengujian ke login.php asli begitu T-06 selesai — lihat "Risiko
 * tersisa" pada laporan T-05.
 *
 * Akses: /tests/security_preview.php
 */

if (!defined('APP_BOOTSTRAPPED')) {
    require __DIR__ . '/../includes/bootstrap.php';
}

if (APP_ENV !== 'dev') {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/../includes/repo/users.php';

if (isset($_GET['logout'])) {
    csrf_require();
    auth_logout();
    redirect('tests/security_preview.php');
}

$error_message = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require(); // 419 otomatis bila token tidak ada/tidak cocok -- lihat includes/functions.php

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip = client_ip();

    if (login_throttle_is_blocked($username, $ip)) {
        $error_message = 'Terlalu banyak percobaan gagal. Coba lagi dalam beberapa menit.';
    } else {
        $user = repo_user_find_by_username($username);

        // Pesan SAMA baik username tidak ditemukan, password salah, maupun akun
        // nonaktif -- tidak membedakan sebabnya (edge case wajib T-05).
        if ($user === null || $user['is_active'] != 1 || !password_verify($password, $user['password_hash'])) {
            login_throttle_record_failure($username, $ip);
            $error_message = 'Username atau password salah.';
        } else {
            auth_login($user);
            repo_user_touch_last_login((int) $user['id']);
            flash('success', 'Berhasil masuk sebagai ' . $user['nama_lengkap'] . '.');
            redirect('tests/security_preview.php');
        }
    }
}

$current_user = auth_user();
$page_title = 'Pratinjau Keamanan (T-05)';
$layout = 'auth';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-box">
  <h1 class="auth-box__title">WebGIS Galian C — Kota Sorong</h1>
  <p class="auth-box__subtitle">Pratinjau Keamanan (T-05)</p>

  <?php if ($error_message !== null): ?>
  <p class="field__error"><?= e($error_message) ?></p>
  <?php endif; ?>

  <?php if ($current_user !== null): ?>
  <p>Sedang login sebagai <strong><?= e($current_user['nama_lengkap']) ?></strong> (peran: <?= e($current_user['role']) ?>).</p>
  <p class="field__hint">session_regenerate_id() sudah berjalan saat login ini; buka DevTools → Application → Cookies untuk cek flag <code>webgis_sid</code>.</p>
  <form method="post" action="<?= e(url('tests/security_preview.php?logout=1')) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--secondary btn--block">Keluar</button>
  </form>
  <?php else: ?>
  <form method="post" action="<?= e(url('tests/security_preview.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field">
      <label class="field__label" for="username">Username</label>
      <input class="input" type="text" id="username" name="username" autocomplete="username" required>
    </div>
    <div class="field">
      <label class="field__label" for="password">Password</label>
      <input class="input" type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn--primary btn--block">Login</button>
    </div>
  </form>
  <p class="field__hint">Akun seed T-02: admin/admin123, kontraktor1/kontraktor123, dst. Lima kali salah dari IP+username yang sama akan terkunci 15 menit.</p>
  <?php endif; ?>
</div>
<?php
require __DIR__ . '/../includes/footer.php';
