<?php

declare(strict_types=1);

/**
 * index.php — redirect ke login/dashboard (PRD bagian 8). Placeholder T-01
 * ditunda sampai dashboard.php benar-benar ada (T-20) -- sebelum itu redirect
 * ke sana hanya memindahkan 404 placeholder ke 404 nyata.
 */

require_once __DIR__ . '/includes/bootstrap.php';

redirect(current_user() !== null ? 'dashboard.php' : 'login.php');
