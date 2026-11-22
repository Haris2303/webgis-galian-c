<?php

declare(strict_types=1);

/**
 * login.php — FR-01 (PRD bagian 5). Satu-satunya pintu masuk aplikasi; tidak
 * ada halaman publik lain (PRD bagian 2).
 */

require_once __DIR__ . '/includes/bootstrap.php';

// Sudah login? Tidak perlu lihat form lagi.
if (current_user() !== null) {
    redirect('dashboard.php');
}

$error_message = null;
$next_input = $_GET['next'] ?? ($_POST['next'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip = client_ip();
    $generic_error = 'Username atau password salah.';

    if ($username === '' || $password === '') {
        $error_message = $generic_error;
    } elseif (login_throttle_is_blocked($username, $ip)) {
        $error_message = 'Terlalu banyak percobaan gagal. Coba lagi dalam beberapa menit.';
    } else {
        $user = repo_user_find_by_username($username);

        // password_verify() SELALU dipanggil, bahkan saat $user null, lewat
        // DUMMY_PASSWORD_HASH -- supaya waktu respons untuk "username tidak
        // ada" dan "password salah" setara (cegah timing attack, PRD bagian 11).
        $password_ok = password_verify($password, $user['password_hash'] ?? DUMMY_PASSWORD_HASH);

        if ($user === null || !$password_ok || (int) $user['is_active'] !== 1) {
            login_throttle_record_failure($username, $ip);
            $error_message = $generic_error;
        } elseif ($user['role'] === 'kontraktor' && empty($user['kontraktor_id'])) {
            // Data rusak: akun kontraktor tanpa kontraktor_id. Pesan ke pengguna
            // tetap netral (tidak membocorkan detail struktur data); detail
            // sesungguhnya hanya masuk log untuk admin menindaklanjuti.
            app_log('error', sprintf(
                'Login ditolak: user id=%d username="%s" role=kontraktor tapi kontraktor_id kosong (data rusak)',
                $user['id'],
                $user['username']
            ));
            login_throttle_record_failure($username, $ip);
            $error_message = $generic_error;
        } else {
            if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT)) {
                repo_user_update_password((int) $user['id'], password_hash($password, PASSWORD_BCRYPT));
            }

            auth_login($user);
            repo_user_touch_last_login((int) $user['id']);

            $next = validate_next_path($next_input);
            redirect($next ?? 'dashboard.php');
        }
    }
}

$page_title = 'Masuk ke Sistem';
$layout = 'auth';
// Migrasi UI DESIGN.md -- Roboto & design-system.css sekarang dimuat GLOBAL
// lewat includes/header.php (dipakai topbar/sidebar di semua halaman app),
// jadi tidak perlu diulang di $extra_head lagi di sini.
$auth_shell_class = 'ds-auth-shell';
require __DIR__ . '/includes/header.php';
?>
<div class="ds-auth-shell__content">
  <div class="ds-auth-card">
    <span class="ds-auth-card__eyebrow">Dinas Perindustrian Kota Sorong</span>
    <h1 class="ds-auth-card__title">WebGIS Galian C</h1>
    <p class="ds-auth-card__subtitle">Masuk untuk memantau dan mengelola data tambang galian C.</p>

    <?php if ($error_message !== null): ?>
    <div class="ds-alert-error" role="alert">
      <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false" style="flex-shrink:0;margin-top:2px">
        <circle cx="10" cy="10" r="9" stroke="currentColor" stroke-width="1.6"/>
        <path d="M10 6v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        <circle cx="10" cy="13.5" r="0.9" fill="currentColor"/>
      </svg>
      <span><?= e($error_message) ?></span>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
      <?= csrf_field() ?>
      <?php $next_safe = validate_next_path($next_input); ?>
      <?php if ($next_safe !== null): ?>
      <input type="hidden" name="next" value="<?= e($next_safe) ?>">
      <?php endif; ?>
      <div class="ds-field">
        <label class="ds-field__label" for="username">Username</label>
        <input class="ds-input" type="text" id="username" name="username" autocomplete="username" required autofocus>
      </div>
      <div class="ds-field">
        <label class="ds-field__label" for="password">Password</label>
        <input class="ds-input" type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="ds-btn-primary">Masuk</button>
    </form>
  </div>
</div>
<?php
require __DIR__ . '/includes/footer.php';
