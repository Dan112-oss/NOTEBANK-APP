<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

redirect(current_admin() ? 'admin/dashboard.php' : 'admin/login.php');
