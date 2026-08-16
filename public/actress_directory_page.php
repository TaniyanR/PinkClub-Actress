<?php

declare(strict_types=1);

if (!isset($pcaDirectoryAmateur) || !is_bool($pcaDirectoryAmateur)) {
    require __DIR__ . '/404.php';
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/actress_catalog.php';
require_once __DIR__ . '/../lib/actress_directory_cache.php';
require_once __DIR__ . '/partials/public_ui.php';

$directoryTitle = $pcaDirectoryAmateur ? 'しろうと女性一覧' : '女優一覧';
$directorySubtitle = $pcaDirectoryAmateur
    ? '気になるしろうと女性のプロフィールと出演作品へ。'
    : '気になる女優のプロフィールと出演作品へ。';

$directoryGroups = [];
$totalRows = 0;

if (!$pcaDirectoryAmateur) {
    // サイドバーの公開女優数と同じ女優一覧キャッシュを表示元にする。
    // 「サイドバーは○千人なのに一覧は0人」という別経路の不整合を防ぐ。
    try {
        $manifest = pcf_actress_directory_cache_manifest();
        foreach (($manifest['groups'] ?? []) as $groupMeta) {
            if (!is_array($groupMeta)) {
                continue;
            }
            $key = (string)($groupMeta['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $cachedRows = pcf_actress_directory_cache_group($key);
            if ($cachedRows === []) {
                continue;
            }
            $label = (string)($groupMeta['label'] ?? '');
            $type = (string)($groupMeta['type'] ?? '');
            $renderKey = $type === 'alpha' ? 'A-Z' : $label;
            if (!isset($directoryGroups[$renderKey])) {
                $directoryGroups[$renderKey] = [];
            }
            foreach ($cachedRows as $cachedRow) {
                if (!is_array($cachedRow)) {
                    continue;
                }
                $id = (int)($cachedRow[0] ?? 0);
                $name = trim((string)($cachedRow[1] ?? ''));
                $image = trim((string)($cachedRow[2] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $directoryGroups[$renderKey][] = [
                    'id' => $id,
                    'name' => $name,
                    'image' => $image,
                ];
                $totalRows++;
            }
        }
    } catch (Throwable $e) {
        error_log('public actress directory cache read failed: ' . $e->getMessage());
    }

    // キャッシュが壊れている場合だけDB直読みにフォールバック。
    if ($totalRows === 0) {
        try {
            $stmt = db()->query("SELECT id, name, image_large, image_small, image_url, ruby FROM actresses WHERE TRIM(COALESCE(name, '')) <> '' ORDER BY name ASC, id ASC LIMIT 10000");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $grouped = pca_group_actresses($rows);
            foreach ($grouped as $key => $groupRows) {
                foreach ($groupRows as $row) {
                    $image = pca_actress_image(is_array($row) ? $row : []);
                    $directoryGroups[$key][] = [
                        'id' => (int)($row['id'] ?? 0),
                        'name' => (string)($row['name'] ?? ''),
                        'image' => $image,
                    ];
                    $totalRows++;
                }
            }
        } catch (Throwable $e) {
            error_log('public actress directory DB fallback failed: ' . $e->getMessage());
        }
    }
} else {
    // しろうと女性は商品フロア情報で分類するためDB関係を利用する。
    $rows = pca_fetch_actresses(true, 10000, 0, false);
    $grouped = pca_group_actresses($rows);
    foreach ($grouped as $key => $groupRows) {
        foreach ($groupRows as $row) {
            $directoryGroups[$key][] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'image' => pca_actress_image(is_array($row) ? $row : []),
            ];
            $totalRows++;
        }
    }
}

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
<div class="pca-directory-count">登録 <?= e(number_format($totalRows)) ?> 名</div>

<?php if ($totalRows > 0): ?>
  <nav class="pca-directory-nav" aria-label="頭文字から探す">
    <?php foreach ($order as $bucket): ?>
      <?php if (($directoryGroups[$bucket] ?? []) === []): continue; endif; ?>
      <a href="#pca-<?= e(rawurlencode($bucket)) ?>"><?= e($bucket === '#' ? '他' : $bucket) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($order as $bucket): ?>
    <?php $bucketRows = $directoryGroups[$bucket] ?? []; if ($bucketRows === []): continue; endif; ?>
    <section class="pca-directory-section" id="pca-<?= e(rawurlencode($bucket)) ?>">
      <h2><?= e($bucket === 'A-Z' ? 'A-Z' : ($bucket === '#' ? 'その他' : $bucket . '行')) ?></h2>
      <div class="pca-directory-grid">
        <?php foreach ($bucketRows as $row): ?>
          <?php
          $id = (int)($row['id'] ?? 0);
          $name = trim((string)($row['name'] ?? ''));
          if ($id <= 0 || $name === '') { continue; }
          $image = trim((string)($row['image'] ?? ''));
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
  <?php pcf_render_empty($pcaDirectoryAmateur
      ? 'しろうと作品との紐付けがまだありません。作品取得を進めると自動で表示されます。'
      : '女優一覧を読み込めませんでした。サーバーログに原因を記録しました。'); ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
