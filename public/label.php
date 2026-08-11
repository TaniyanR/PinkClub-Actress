<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/partials/public_ui.php';

$id = trim((string)get('id', ''));
$name = trim((string)get('name', ''));
$labelPage = max(1, (int)get('page', 1));
$limit = 20;
$offset = ($labelPage - 1) * $limit;
$list = [];
$hasNext = false;
$label = fetch_label($id, $name);
if ($label === null) {
    require __DIR__ . '/404.php';
}

$labelName = trim((string)($label['name'] ?? ''));
if ($labelName === '') {
    require __DIR__ . '/404.php';
}

$rows = dedupe_items_by_key(fetch_items_by_label_name($labelName, $limit + 1, $offset));
[$list, $hasNext] = paginate_items($rows, $limit);
if ($labelPage === 1 && $list === []) {
    require __DIR__ . '/404.php';
}

$accessRankingPeriod = trim((string)get('rank_period', 'daily'));
$accessRankingTabs = [
    'daily' => ['label' => '本日'],
    'weekly' => ['label' => '週間'],
    'monthly' => ['label' => '月間'],
    'yearly' => ['label' => '年間'],
];
if (!isset($accessRankingTabs[$accessRankingPeriod])) {
    $accessRankingPeriod = 'daily';
}
$accessRankingRows = pcf_public_weighted_ranking('labels', $accessRankingPeriod);
$accessRankingRows = array_values(array_filter($accessRankingRows, static function (array $row): bool {
    $name = trim((string)($row['name'] ?? ''));
    if ($name === '' || pcf_is_noise_name($name)) {
        return false;
    }

    return preg_match('/[^\s\-_ー－―—–]+/u', $name) === 1;
}));

$title = $labelName;
$pageDescription = mb_strimwidth($labelName . 'レーベルの作品一覧。FANZAで販売中の最新作・人気作品を紹介。', 0, 150, '…', 'UTF-8');
$canonicalUrl = public_url('label.php') . '?' . http_build_query([
    'id' => (string)($label['id'] ?? $id),
    'name' => $labelName,
    'page' => $labelPage > 1 ? $labelPage : null,
]);
if ($labelPage > 1) {
    $relPrev = public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'page' => $labelPage - 1]);
}
if ($hasNext) {
    $relNext = public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'page' => $labelPage + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => 'レーベル一覧', 'url' => public_url('labels.php')],
    ['label' => $labelName],
]); ?>
<?php pcf_render_hero($labelName); ?>

<h2 class="pcf-section-title"><?= e($labelName) ?>一覧</h2>
<?php if ($list !== []): ?>
  <section class="pcf-related-grid pcf-label-related-grid">
    <?php foreach ($list as $item): pcf_render_item_card(is_array($item) ? $item : []); endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if ($labelPage > 1): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'page' => $labelPage - 1])) ?>">前へ</a>
    <?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$labelPage) ?></span>
    <?php if ($hasNext): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'page' => $labelPage + 1])) ?>">次へ</a>
    <?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('このレーベルの商品はありません。'); ?>
<?php endif; ?>

<section id="access-ranking" class="block" style="margin-top:24px;">
  <h2 class="section-title">人気のレーベルランキング！</h2>
  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px;">
    <?php foreach ($accessRankingTabs as $tabKey => $tabConfig): ?>
      <?php $tabUrl = public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? $id), 'name' => $labelName, 'rank_period' => (string)$tabKey]) . '#access-ranking'; ?>
      <?php $tabStyle = $accessRankingPeriod === $tabKey ? 'display:inline-block; padding:6px 12px; border:1px solid #0b5ed7; border-radius:6px; background:#0b5ed7; color:#fff; font-weight:700; text-decoration:none;' : 'display:inline-block; padding:6px 12px; border:1px solid #0b5ed7; border-radius:6px; background:#fff; color:#0b5ed7; font-weight:700; text-decoration:none;'; ?>
      <a href="<?= e($tabUrl) ?>" rel="nofollow" style="<?= e($tabStyle) ?>"><?= e((string)$tabConfig['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($accessRankingRows !== []): ?>
    <div style="max-height:800px; overflow-y:auto; border:1px solid #ddd;">
      <table style="width:100%; border-collapse:collapse; table-layout:fixed;">
        <thead>
          <tr>
            <th style="width:80px; text-align:center; padding:8px; border-bottom:1px solid #ddd; background:#0b5ed7; color:#fff;">順位</th>
            <th style="width:auto; text-align:center; padding:8px; border-bottom:1px solid #ddd; background:#0b5ed7; color:#fff;">レーベル名</th>
            <th style="width:120px; text-align:center; padding:8px; border-bottom:1px solid #ddd; background:#0b5ed7; color:#fff;">ランキング点</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accessRankingRows as $index => $rankingRow): ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid #eee; text-align:center;"><?= e((string)($index + 1)) ?></td>
              <td style="padding:8px; border-bottom:1px solid #eee; text-align:left;">
                <?php
                $rankingLabelUrl = public_url('label.php') . '?' . http_build_query(['id' => (string)($rankingRow['id'] ?? ''), 'name' => (string)($rankingRow['name'] ?? '')]);
                ?>
                <a href="<?= e($rankingLabelUrl) ?>"><?= e((string)($rankingRow['name'] ?? '')) ?></a>
              </td>
              <td style="padding:8px; border-bottom:1px solid #eee; text-align:center;"><?= e((string)((int)($rankingRow['access_count'] ?? 0))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <?php pcf_render_empty('レーベルクリックランキングのデータがありません。'); ?>
  <?php endif; ?>
</section>


<?php pcf_render_sample_movie_modal(); ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
