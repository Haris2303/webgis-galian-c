<?php

declare(strict_types=1);

// Redirect ke login/dashboard.

require_once __DIR__ . '/includes/bootstrap.php';

redirect(current_user() !== null ? 'dashboard.php' : 'login.php');
