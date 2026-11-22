<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

// Penutup shell halaman; wajib dipasangkan dengan includes/header.php.

$layout = $layout ?? 'app';
?>
<?php if ($layout !== 'auth'): ?>
    </main>
  </div>
</div>
<dialog class="modal" id="dialog-logout" aria-labelledby="dialog-logout-title">
  <div class="modal__body">
    <h2 class="modal__title" id="dialog-logout-title">Keluar dari sistem?</h2>
    <p>Sesi Anda akan diakhiri dan Anda diarahkan kembali ke halaman masuk.</p>
    <div class="modal__actions">
      <form method="dialog"><button type="submit" class="btn btn--secondary" formnovalidate>Batal</button></form>
      <form method="post" action="<?= e(url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--danger">Ya, Keluar</button>
      </form>
    </div>
  </div>
</dialog>
<?php else: ?>
  </main>
</div>
<?php endif; ?>
<script src="<?= e(asset('assets/js/ui.js')) ?>"></script>
<script src="<?= e(asset('assets/js/validation.js')) ?>"></script>
<?= render_raw_tags($extra_scripts ?? []) ?>
</body>
</html>
