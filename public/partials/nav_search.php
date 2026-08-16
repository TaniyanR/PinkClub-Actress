<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../lib/db.php';

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$navItems = [
    ['href' => public_url(''), 'label' => 'TOP'],
    ['href' => public_url('actresses.php'), 'label' => '女優一覧'],
    ['href' => public_url('amateur_actresses.php'), 'label' => 'しろうと女性一覧'],
];

$siteActressCount = null;
try {
    $stmt = db()->query("SELECT COUNT(*) FROM actresses WHERE TRIM(name) <> '' AND dmm_id REGEXP '^[0-9]+$'");
    $siteActressCount = $stmt ? (int)$stmt->fetchColumn() : null;
} catch (Throwable) {
    $siteActressCount = null;
}
?>
<details class="site-mobile-menu only-sp">
    <summary class="site-mobile-menu__summary">メニュー</summary>
    <div class="site-mobile-menu__body">
        <div class="site-mobile-menu__group">
            <?php foreach ($navItems as $item) : ?>
                <a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($siteActressCount !== null): ?>
        <div class="site-mobile-menu__group">
            <a style="color:#000;">公開女優数：<strong><?= e(number_format($siteActressCount)) ?></strong></a>
        </div>
        <?php endif; ?>
    </div>
</details>
<nav class="site-nav" aria-label="グローバルナビゲーション">
    <?php foreach ($navItems as $index => $item) : ?>
        <?php $itemPath = (string)parse_url($item['href'], PHP_URL_PATH); ?>
        <?php $isActive = $path === $itemPath || ($index === 0 && ($path === '' || str_ends_with($path, '/'))); ?>
        <?php if ($index > 0): ?><span class="site-nav__sep" aria-hidden="true"> | </span><?php endif; ?>
        <a class="<?= $isActive ? 'is-active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
    <?php endforeach; ?>
</nav>
