<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';

auth_require_admin();

// PinkClub-Actressの商品同期は登録済み女優を起点に行う。
// 任意フロアの一括商品同期は使用しない。
app_redirect('admin/api_actresses.php');
