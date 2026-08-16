<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/../lib/actress_catalog.php';
require_once __DIR__ . '/partials/public_ui.php';

function pca_detail_profile_value(array $row, string $key): string
{
    $value = trim((string)($row[$key] ?? ''));
    return $value !== '' ? $value : '未登録';
}

$id = max(0, (int)get('id', 0));
if ($id <= 0) {
    require __DIR__ . '/404.php';
}

try {
    $row = fetch_actress($id);
} catch (Throwable $e) {
    error_log('actress fetch failed: ' . $e->getMessage());
    $row = null;
}

if (!is_array($row)) {
    require __DIR__ . '/404.php';
}

$name = trim((string)($row['name'] ?? ''));
$dmmId = trim((string)($row['dmm_id'] ?? ''));
if ($name === '' || !ctype_digit($dmmId)) {
    require __DIR__ . '/404.php';
}

try {
    analytics_log_actress_page_view($id);
} catch (Throwable $e) {
    error_log('actress page view logging failed: ' . $e->getMessage());
}

$profileImage = pca_actress_image($row);
if ($profileImage === '') {
    $profileImage = pcf_placeholder_data_uri('No Photo');
}

$page = max(1, (int)get('page', 1));
$limit = 24;
$offset = ($page - 1) * $limit;
$items = [];
$hasNext = false;
try {
    $loaded = dedupe_items_by_key(fetch_items_by_actress($id, $limit + 1, $offset));
    [$items, $hasNext] = paginate_items($loaded, $limit);
} catch (Throwable $e) {
    error_log('actress items fetch failed: ' . $e->getMessage());
    $items = [];
}

$rankPeriod = trim((string)get('rank_period', 'daily'));
$rankTabs = [
    'daily' => '本日',
    'weekly' => '週間',
    'monthly' => '月間',
    'yearly' => '年間',
];
if (!isset($rankTabs[$rankPeriod])) {
    $rankPeriod = 'daily';
}

try {
    $ranking = pcf_public_weighted_ranking('actresses', $rankPeriod);
} catch (Throwable $e) {
    $ranking = [];
}

$ranking = array_values(array_filter($ranking, static function ($candidate): bool {
    return is_array($candidate)
        && (int)($candidate['id'] ?? 0) > 0
        && trim((string)($candidate['name'] ?? '')) !== '';
}));

$title = $name;
$pageTitle = $name;
$pageDescription = $name . 'のプロフィールと出演作品。';
$canonicalUrl = public_url('actress.php?id=' . $id);
$ogImage = $profileImage;
require __DIR__ . '/partials/header.php';
?>

<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('')],
    ['label' => '女優一覧', 'url' => public_url('actresses.php')],
    ['label' => $name],
]); ?>

<style>
.pca-profile{display:grid;grid-template-columns:minmax(220px,320px) 1fr;gap:24px;align-items:start}.pca-profile__image{width:100%;max-width:320px;aspect-ratio:1/1;object-fit:cover;border-radius:8px;background:#f2f4f7}.pca-profile h1{margin:0 0 16px;padding:0 0 8px 10px;border-left:8px solid #002bff;border-bottom:2px solid #002bff}.pca-profile dl{margin:0;display:grid;grid-template-columns:140px 1fr}.pca-profile dt,.pca-profile dd{margin:0;padding:10px;border-bottom:1px solid #e4e7ec}.pca-profile dt{font-weight:700;background:#f8fafc}.pca-rank-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}.pca-rank-tabs a{display:inline-block;padding:7px 12px;border:1px solid #0b5ed7;border-radius:6px;text-decoration:none}.pca-rank-tabs a.is-active{background:#0b5ed7;color:#fff}.pca-ranking{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.pca-ranking a{display:flex;align-items:center;gap:8px;padding:8px;border:1px solid #e4e7ec;border-radius:7px;text-decoration:none;color:inherit}.pca-ranking img{width:48px;height:48px;border-radius:50%;object-fit:cover;background:#f2f4f7}@media(max-width:720px){.pca-profile{grid-template-columns:1fr}.pca-profile__image{max-width:100%}.pca-profile dl{grid-template-columns:110px 1fr}.pca-ranking{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<section class="pca-profile">
  <img class="pca-profile__image" src="<?= e($profileImage) ?>" alt="<?= e($name) ?>" decoding="async" fetchpriority="high">
  <div>
    <h1><?= e($name) ?></h1>
    <dl>
      <dt>よみ</dt><dd><?= e(pca_detail_profile_value($row, 'ruby')) ?></dd>
      <dt>誕生日</dt><dd><?= e(pca_detail_profile_value($row, 'birthday')) ?></dd>
      <dt>出身地</dt><dd><?= e(pca_detail_profile_value($row, 'prefectures')) ?></dd>
    </dl>
  </div>
</section>

<h2 class="pcf-section-title" style="margin-top:24px;"><?= e($name) ?>の作品</h2>
<?php if ($items !== []): ?>
  <section class="pcf-related-grid pcf-item-related-grid pcf-actress-related-grid">
    <?php foreach ($items as $item): ?>
      <?php pcf_render_item_card(is_array($item) ? $item : []); ?>
    <?php endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if ($page > 1): ?><a class="pcf-pagination__link" href="<?= e(public_url('actress.php?id=' . $id . '&page=' . ($page - 1))) ?>">前へ</a><?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$page) ?></span>
    <?php if ($hasNext): ?><a class="pcf-pagination__link" href="<?= e(public_url('actress.php?id=' . $id . '&page=' . ($page + 1))) ?>">次へ</a><?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('この女優の作品情報はまだ取得されていません。プロフィールは女優APIの保存情報から表示しています。'); ?>
<?php endif; ?>

<section id="access-ranking" class="block" style="margin-top:28px;">
  <h2 class="section-title">人気の女優ランキング！</h2>
  <div class="pca-rank-tabs">
    <?php foreach ($rankTabs as $key => $label): ?>
      <a class="<?= $rankPeriod === $key ? 'is-active' : '' ?>" href="<?= e(public_url('actress.php?id=' . $id . '&rank_period=' . $key . '#access-ranking')) ?>" rel="nofollow"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($ranking !== []): ?>
    <div class="pca-ranking">
      <?php foreach (array_slice($ranking, 0, 20) as $rankRow): ?>
        <?php
        $rankId = (int)($rankRow['id'] ?? 0);
        $rankName = trim((string)($rankRow['name'] ?? ''));
        $rankImage = pca_actress_image($rankRow);
        if ($rankImage === '') { $rankImage = pcf_placeholder_data_uri('No Photo'); }
        ?>
        <a href="<?= e(public_url('actress.php?id=' . $rankId)) ?>">
          <img src="<?= e($rankImage) ?>" alt="<?= e($rankName) ?>" loading="lazy">
          <span><?= e($rankName) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php pcf_render_empty('ランキングデータはまだありません。'); ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
