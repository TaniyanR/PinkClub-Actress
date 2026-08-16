<?php

declare(strict_types=1);

if (!isset($pcaDirectoryAmateur) || !is_bool($pcaDirectoryAmateur)) {
    require __DIR__ . '/404.php';
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/actress_catalog.php';
require_once __DIR__ . '/../lib/actress_directory_cache.php';
require_once __DIR__ . '/partials/public_ui.php';

function pca_directory_work_image(array $row): string
{
    $dmmId = trim((string)($row['dmm_id'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    if ($dmmId === '' && $name === '') {
        return '';
    }

    try {
        $stmt = db()->prepare(
            "SELECT i.*
             FROM items i
             INNER JOIN item_actresses ia ON ia.item_id = i.id
             WHERE (ia.dmm_id = :dmm_id OR ia.actress_name = :name)
             ORDER BY i.release_date DESC, i.id DESC
             LIMIT 1"
        );
        $stmt->execute([':dmm_id' => $dmmId, ':name' => $name]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($item) ? pcf_item_image($item) : '';
    } catch (Throwable $e) {
        error_log('amateur directory work image failed: ' . $e->getMessage());
        return '';
    }
}

$directoryTitle = $pcaDirectoryAmateur ? 'しろうと女性一覧' : '女優一覧';
$directorySubtitle = $pcaDirectoryAmateur ? '気になるしろうと女性のプロフィールと出演作品へ。' : '気になる女優のプロフィールと出演作品へ。';
$directoryGroups = [];
$totalRows = 0;

if (!$pcaDirectoryAmateur) {
    try {
        $manifest = pcf_actress_directory_cache_manifest();
        foreach (($manifest['groups'] ?? []) as $groupMeta) {
            if (!is_array($groupMeta)) continue;
            $key = (string)($groupMeta['key'] ?? '');
            if ($key === '') continue;
            $cachedRows = pcf_actress_directory_cache_group($key);
            $label = (string)($groupMeta['label'] ?? '');
            $type = (string)($groupMeta['type'] ?? '');
            $renderKey = $type === 'alpha' ? 'A-Z' : $label;
            foreach ($cachedRows as $cachedRow) {
                if (!is_array($cachedRow)) continue;
                $id = (int)($cachedRow[0] ?? 0);
                $name = trim((string)($cachedRow[1] ?? ''));
                if ($id <= 0 || $name === '') continue;
                $directoryGroups[$renderKey][] = ['id'=>$id,'name'=>$name,'image'=>trim((string)($cachedRow[2] ?? ''))];
                $totalRows++;
            }
        }
    } catch (Throwable $e) {
        error_log('public actress directory cache read failed: ' . $e->getMessage());
    }
} else {
    try {
        $pdo = db();
        $pdo->exec(
            "INSERT INTO actresses (dmm_id,name,created_at,updated_at)
             SELECT DISTINCT
               CASE WHEN TRIM(COALESCE(ia.dmm_id,'')) <> '' THEN TRIM(ia.dmm_id)
                    ELSE CONCAT('name:', SHA1(LOWER(TRIM(ia.actress_name)))) END,
               TRIM(ia.actress_name), NOW(), NOW()
             FROM item_actresses ia
             INNER JOIN items i ON i.id = ia.item_id
             WHERE TRIM(COALESCE(ia.actress_name,'')) <> ''
               AND (i.floor_code = 'videoc' OR i.floor_name LIKE '%素人%' OR i.floor_name LIKE '%しろうと%' OR i.floor_name LIKE '%シロウト%')
             ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=NOW()"
        );
    } catch (Throwable $e) {
        error_log('amateur actress backfill failed: ' . $e->getMessage());
    }

    $rows = pca_fetch_actresses(true, 10000, 0, false);
    $grouped = pca_group_actresses($rows);
    foreach ($grouped as $key => $groupRows) {
        foreach ($groupRows as $row) {
            $image = pca_actress_image(is_array($row) ? $row : []);
            if ($image === '') {
                $image = pca_directory_work_image(is_array($row) ? $row : []);
            }
            $directoryGroups[$key][] = [
                'id'=>(int)($row['id'] ?? 0),
                'name'=>(string)($row['name'] ?? ''),
                'image'=>$image,
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
.pca-directory-nav{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0 24px}.pca-directory-nav a{display:inline-flex;min-width:38px;height:36px;align-items:center;justify-content:center;padding:0 10px;border-radius:7px;background:#1d2939;color:#fff;text-decoration:none;font-weight:700}.pca-directory-section{margin:0 0 24px}.pca-directory-section h2{margin:0 0 14px;padding:0 0 10px;border-bottom:2px solid #d0d5dd;font-size:22px}.pca-directory-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.pca-directory-person{display:flex;align-items:center;gap:9px;min-width:0;padding:7px;border:1px solid #e4e7ec;border-radius:7px;background:#fff;color:#667085;text-decoration:none}.pca-directory-person img{width:44px;height:44px;flex:0 0 44px;border-radius:50%;object-fit:cover;background:#1d2939}.pca-directory-person span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}.pca-directory-count{margin:-6px 0 16px;color:#667085;font-size:13px}@media(max-width:1100px){.pca-directory-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:720px){.pca-directory-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<?php pcf_render_hero($directoryTitle, $directorySubtitle); ?>
<div class="pca-directory-count">登録 <?= e(number_format($totalRows)) ?> 名</div>
<?php if ($totalRows > 0): ?>
<nav class="pca-directory-nav" aria-label="頭文字から探す">
<?php foreach ($order as $bucket): if (($directoryGroups[$bucket] ?? []) === []) continue; ?>
<a href="#pca-<?= e(rawurlencode($bucket)) ?>"><?= e($bucket === '#' ? '他' : $bucket) ?></a>
<?php endforeach; ?>
</nav>
<?php foreach ($order as $bucket): $bucketRows = $directoryGroups[$bucket] ?? []; if ($bucketRows === []) continue; ?>
<section class="pca-directory-section" id="pca-<?= e(rawurlencode($bucket)) ?>">
<h2><?= e($bucket === 'A-Z' ? 'A-Z' : ($bucket === '#' ? 'その他' : $bucket . '行')) ?></h2>
<div class="pca-directory-grid">
<?php foreach ($bucketRows as $row): $id=(int)($row['id']??0); $name=trim((string)($row['name']??'')); if($id<=0||$name==='')continue; $image=trim((string)($row['image']??'')); if($image==='')$image=pcf_placeholder_data_uri('No Photo'); ?>
<a class="pca-directory-person" href="<?= e(public_url('actress.php?id=' . $id)) ?>"><img src="<?= e($image) ?>" alt="<?= e($name) ?>" loading="lazy" decoding="async"><span><?= e($name) ?></span></a>
<?php endforeach; ?>
</div></section>
<?php endforeach; ?>
<?php else: ?>
<?php pcf_render_empty($pcaDirectoryAmateur ? 'しろうと作品をまだ取得できていません。次回の同期でvideocフロアから100作品を取得して出演者を登録します。' : '女優一覧を読み込めませんでした。'); ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
