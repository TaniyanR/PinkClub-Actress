<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

auth_require_admin();
app_redirect('admin/api_items.php');
