<?php

declare(strict_types=1);

require_once __DIR__ . '/public/_bootstrap.php';
require_once __DIR__ . '/lib/actress_catalog.php';
require_once __DIR__ . '/public/partials/public_ui.php';

function pca_redirect_canonical_home(): void
{
    $basePath = (string)(parse_url(BASE_URL, PHP_URL_PATH) ?: '');
    $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
    $requestPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $canonicalPath = $basePath . '/';
    $aliases = [$canonicalPath, $basePath . '/index.php', $basePath . '/public/', $basePath . '/public/index.php'];

    if ($requestPath !== '' && in_array($requestPath, $aliases, true) && $requestPath !== $canonicalPath) {
        header('Location: ' . rtrim(BASE_URL, '/') . '/', true, 301);
        exit;
    }
}

pca_redirect_canonical_home();

$page = max(1, (int)get('page', 1));
$home = pca_home_page($page, 120);
$actresses = $home['rows'];
$page = (int)$home['page'];
$pages = (int)$home['pages'];
$total = (int)$home['total'];

$title = $page > 1 ? '女優一覧 ' . $page . 'ページ目' : 'トップ';
$pageDescription = 'FANZAの女優・しろうと女性を写真から探せる女優専門サイト。プロフィールと出演作品をチェックできます。';
$canonicalUrl = rtrim(BASE_URL, '/') . '/' . ($page > 1 ? '?page=' . $page : '');
if ($page > 1) {
    $relPrev = rtrim(BASE_URL, '/') . '/' . ($page > 2 ? '?page=' . ($page - 1) : '');
}
if ($page < $pages) {
    $relNext = rtrim(BASE_URL, '/') . '/?page=' . ($page + 1);
}

// The FL header injects product-oriented home widgets only when SCRIPT_NAME is index.php.
// Actress is intentionally a performer-first site, so render the same FL shell without those widgets.
$originalScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
$_SERVER['SCRIPT_NAME'] = '/actress.php';
require __DIR__ . '/public/partials/header.php';
if ($originalScriptName === null) {
    unset($_SERVER['SCRIPT_NAME']);
} else {
    $_SERVER['SCRIPT_NAME'] = $originalScriptName;
}
?>

<style>
.pca-home-meta{display:flex;justify-content:flex-end;margin:0 0 14px;font-size:13px;color:#667085}.pca-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px}.pca-card{min-width:0;border:1px solid #e4e7ec;border-radius:10px;background:#fff;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,.04)}.pca-card__link{display:block;color:inherit;text-decoration:none}.pca-card__image{display:block;width:100%;aspect-ratio:4/5;object-fit:cover;background:#f2f4f7}.pca-card__name{display:block;padding:10px 8px;text-align:center;font-size:14px;font-weight:700;line-height:1.45;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pca-card__tag{display:block;margin:-5px 8px 9px;text-align:center;font-size:11px;color:#667085}.pca-pagination{display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap;margin:28px 0 8px}.pca-pagination a,.pca-pagination span{display:inline-flex;min-width:38px;height:38px;align-items:center;justify-content:center;padding:0 10px;border:1px solid #d0d5dd;border-radius:7px;text-decoration:none}.pca-pagination .is-current{background:#1d2939;color:#fff;border-color:#1d2939;font-weight:700}@media(max-width:1100px){.pca-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}@media(max-width:720px){.pca-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.pca-card__name{font-size:13px}}
</style>

<div class="pca-home-meta">全 <?= e(number_format($total)) ?> 名 / 1ページ120名</div>

<?php if ($actresses !== []): ?>
  <section class="pca-grid" aria-label="女優としろうと女性の一覧">
    <?php foreach ($actresses as $actress): ?>
      <?php
      $id = (int)($actress['id'] ?? 0);
      $name = trim((string)($actress['name'] ?? ''));
      $image = pca_actress_image(is_array($actress) ? $actress : []);
      if ($id <= 0 || $name === '' || $image === '') {
          continue;
      }
      $detailUrl = public_url('actress.php?id=' . $id);
      $isAmateur = ($actress['_audience'] ?? '') === 'amateur';
      ?>
      <article class="pca-card">
        <a class="pca-card__link" href="<?= e($detailUrl) ?>">
          <img class="pca-card__image" src="<?= e($image) ?>" alt="<?= e($name) ?>" loading="lazy" decoding="async">
          <span class="pca-card__name"><?= e($name) ?></span>
          <span class="pca-card__tag"><?= $isAmateur ? 'しろうと女性' : '女優' ?></span>
        </a>
      </article>
    <?php endforeach; ?>
  </section>
<?php else: ?>
  <?php pcf_render_empty('女優データがまだありません。管理画面から女優情報APIを先に同期し、補助として商品APIを同期してください。'); ?>
<?php endif; ?>

<?php if ($pages > 1): ?>
  <nav class="pca-pagination" aria-label="ページネーション">
    <?php if ($page > 1): ?><a href="<?= e($page === 2 ? rtrim(BASE_URL, '/') . '/' : rtrim(BASE_URL, '/') . '/?page=' . ($page - 1)) ?>">前へ</a><?php endif; ?>
    <?php
    $pageNumbers = [1, $pages];
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $pageNumbers[] = $i;
    }
    $pageNumbers = array_values(array_unique($pageNumbers));
    sort($pageNumbers);
    $previous = 0;
    foreach ($pageNumbers as $pageNumber):
        if ($previous > 0 && $pageNumber > $previous + 1): ?><span>…</span><?php endif;
        $pageUrl = $pageNumber === 1 ? rtrim(BASE_URL, '/') . '/' : rtrim(BASE_URL, '/') . '/?page=' . $pageNumber;
        ?>
        <?php if ($pageNumber === $page): ?><span class="is-current"><?= e((string)$pageNumber) ?></span><?php else: ?><a href="<?= e($pageUrl) ?>"><?= e((string)$pageNumber) ?></a><?php endif; ?>
        <?php $previous = $pageNumber; ?>
    <?php endforeach; ?>
    <?php if ($page < $pages): ?><a href="<?= e(rtrim(BASE_URL, '/') . '/?page=' . ($page + 1)) ?>">次へ</a><?php endif; ?>
  </nav>
<?php endif; ?>

<?php require __DIR__ . '/public/partials/footer.php'; ?>
