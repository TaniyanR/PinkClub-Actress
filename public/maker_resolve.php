<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

header('X-Robots-Tag: noindex, nofollow', true);

$name = trim((string)get('name', ''));
if ($name === '' || mb_strlen($name, 'UTF-8') > 255 || pcf_is_noise_name($name)) {
    require __DIR__ . '/404.php';
}

$makerId = 0;
foreach ([
    'SELECT id FROM makers WHERE name = :name ORDER BY id DESC LIMIT 1',
    'SELECT m.id FROM item_makers im INNER JOIN makers m ON m.dmm_id = im.dmm_id WHERE im.maker_name = :name ORDER BY m.id DESC LIMIT 1',
] as $sql) {
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute([':name' => $name]);
        $makerId = (int)($stmt->fetchColumn() ?: 0);
        if ($makerId > 0) {
            break;
        }
    } catch (Throwable) {
    }
}

if ($makerId > 0) {
    header('Location: ' . public_url('maker.php') . '?id=' . rawurlencode((string)$makerId), true, 301);
    exit;
}

header('Location: ' . public_url('search.php') . '?' . http_build_query(['q' => $name]), true, 301);
exit;
