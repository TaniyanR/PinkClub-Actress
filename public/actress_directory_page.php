<?php

declare(strict_types=1);

if (!isset($pcaDirectoryAmateur) || !is_bool($pcaDirectoryAmateur)) {
    require __DIR__ . '/404.php';
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/actress_catalog.php';
require_once __DIR__ . '/partials/public_ui.php';

$directoryTitle = $pcaDirectoryAmateur ? 'しろうと女性一覧' : '女優一覧';
$directorySubtitle = $pcaDirectoryAmateur
    ? '気になるしろうと女性のプロフィールと出演作品へ。'
    : '気になる女優のプロフィールと出演作品へ。';

$rows = pca_fetch_actresses($pcaDirectoryAmateur, 10000, 0, false);
$groups = pca_group_actresses($rows);
$order = ['あ','か','さ','た','な','は','ま','や','ら','わ','A-Z','#'];

$title = $directoryTitle;
$pageDescription = $directoryTitle . '。写真と名前からプロフィール、出演作品を確認できます。';
$canonicalUrl = public_url($pcaDirectoryAmateur ? 'amateur_actresses.php' : 'actresses.php');
require __DIR__ . '/partials/header.php';
?>

<style>
.pca-directory-nav{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0 24px}.pca-directory-nav a{display:inline-flex;min-width:38px;height:36px;align-items:center;justify-content:center;padding:0 10px;border-radius:7px;background:#1d2939;color:#fff;text-decoration:none;font-weight:700}.pca-directory-section{margin:0 0 24px}.pca-directory-section h2{margin:0 0 14px;padding:0 0 10px;border-bottom:2px solid #d0d5dd;font-size:22px}.pca-directory-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.pca-directory-person{display:flex;align-items:center;gap:9px;min-width:0;padding:7px;border:1px solid #e4e7ec;border-radius:7px;background:#fff;color:#667085;text-decoration:none}.pca-directory-person:hover{border-color:#98a2b3;color:#344054}.pca-directory-person img{width:44px;height:44px;flex:0 0 44px;border-radius:50%;object-fit:cover;background:#1d2939}.pca-directory-person span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}.pca-directory-count{margin:-6px 0 16px;color:#667085;font-size:13px}@media(max-width:1100px){.pca-directory-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:720px){.pca-directory-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pca-directory-person img{width:40px;height:40px;flex-basis:40px}}
</style>

<?php pcf_render_hero($directoryTitle, $directorySubtitle); ?>
<div class="pca-directory-count">登録 <?= e(number_format(count($rows))) ?> 名</div>

<?php if ($rows !== []): ?>
  <nav class="pca-directory-nav" aria-label="頭文字から探す">
    <?php foreach ($order as $bucket): ?>
      <?php if (($groups[$bucket] ?? []) === []): continue; endif; ?>
      <a href="#pca-<?= e(rawurlencode($bucket)) ?>"><?= e($bucket === '#' ? '他' : $bucket) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($order as $bucket): ?>
    <?php $bucketRows = $groups[$bucket] ?? []; if ($bucketRows === []): continue; endif; ?>
    <section class="pca-directory-section" id="pca-<?= e(rawurlencode($bucket)) ?>">
      <h2><?= e($bucket === 'A-Z' ? 'A-Z' : ($bucket === '#' ? 'その他' : $bucket . '行')) ?></h2>
      <div class="pca-directory-grid">
        <?php foreach ($bucketRows as $row): ?>
          <?php
          $id = (int)($row['id'] ?? 0);
          $name = trim((string)($row['name'] ?? ''));
          if ($id <= 0 || $name === '') { continue; }
          $image = pca_actress_image($row);
          if ($image === '') { $image = pcf_placeholder_data_uri('No Photo'); }
          ?>
          <a class="pca-directory-person" href="<?= e(public_url('actress.php?id=' . $id)) ?>">
            <img src="<?= e($image) ?>" alt="<?= e($name) ?>" loading="lazy" decoding="async">
            <span><?= e($name) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
<?php else: ?>
  <?php pcf_render_empty($directoryTitle . 'のデータがまだありません。女優情報APIを先に同期し、出演作品の分類用として商品APIを補助的に同期してください。'); ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
