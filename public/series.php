<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_once __DIR__ . '/partials/_helpers.php';
require_once __DIR__ . '/../lib/repository.php';

$page = safe_int($_GET['page'] ?? 1, 1, 1, 100000);
$limit = 24;
$offset = ($page - 1) * $limit;
[$series, $hasNext] = paginate_items(fetch_series($limit + 1, $offset), $limit);

$pageStyles = ['/assets/css/series.css'];
$pageTitle = 'シリーズ一覧';
$pageDescription = 'シリーズ一覧です。';
$canonicalUrl = canonical_url('/series.php', ['page' => $page > 1 ? (string)$page : null]);

include __DIR__ . '/partials/header.php';
?>
        <section class="block">
            <div class="section-head"><h1 class="section-title">シリーズ一覧</h1></div>
            <div class="taxonomy-grid">
                <?php foreach ($series as $entry) : ?>
                    <a class="taxonomy-card" href="/series_one.php?id=<?php echo urlencode((string)$entry['id']); ?>">
                        <div class="taxonomy-card__media">#<?php echo e((string)$entry['id']); ?></div>
                        <div class="taxonomy-card__name"><?php echo e((string)$entry['name']); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
        <nav class="pagination">
            <?php if ($page > 1) : ?><a class="page-btn" href="/series.php?page=<?php echo e((string)($page - 1)); ?>">前へ</a><?php else : ?><span class="page-btn">前へ</span><?php endif; ?>
            <span class="page-btn is-current"><?php echo e((string)$page); ?></span>
            <?php if ($hasNext) : ?><a class="page-btn" href="/series.php?page=<?php echo e((string)($page + 1)); ?>">次へ</a><?php else : ?><span class="page-btn">次へ</span><?php endif; ?>
        </nav>
<?php include __DIR__ . '/partials/footer.php'; ?>
