<?php

declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * includes/footer.php — penutup shell halaman (T-04). Wajib dipasangkan
 * dengan includes/header.php (lihat variabel $layout di sana).
 */

$layout = $layout ?? 'app';
?>
<?php if ($layout !== 'auth'): ?>
    </main>
  </div>
</div>
<?php else: ?>
  </main>
</div>
<?php endif; ?>
<script src="<?= e(asset('assets/js/ui.js')) ?>"></script>
<script src="<?= e(asset('assets/js/validation.js')) ?>"></script>
</body>
</html>
