<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/partials/public_ui.php';

function is_invalid_actress_name(string $name): bool
{
    if (pcf_is_noise_name($name)) {
        return true;
    }
    $v = mb_strtolower(trim($name), 'UTF-8');
    if ($v === '') {
        return true;
    }
    foreach (['相互リンク', '相互rss', 'お問い合わせ', 'privacy policy', 'プライバシー', 'サイトについて', '公式サイト', 'オフィシャルサイト'] as $ng) {
        if (str_contains($v, mb_strtolower($ng, 'UTF-8'))) {
            return true;
        }
    }
    return false;
}

function actress_profile_value(array $profile, string $key): string
{
    $value = trim((string)($profile[$key] ?? ''));
    return $value !== '' ? $value : '未登録';
}


function actress_api_row_score(array $apiRow): int
{
    $score = 0;
    foreach (['bust', 'cup', 'waist', 'hip', 'height', 'birthday', 'blood_type', 'hobby', 'prefectures', 'ruby'] as $key) {
        if (trim((string)($apiRow[$key] ?? '')) !== '') {
            $score++;
        }
    }

    if (trim((string)($apiRow['imageURL']['large'] ?? $apiRow['image_large'] ?? '')) !== '') {
        $score += 2;
    }
    if (trim((string)($apiRow['imageURL']['small'] ?? $apiRow['image_small'] ?? '')) !== '') {
        $score += 1;
    }

    return $score;
}

function actress_profile_image(array $profile): string
{
    foreach (['image_large', 'image_small', 'image_url'] as $key) {
        $value = trim((string)($profile[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return pcf_placeholder_data_uri('No Photo');
}

$id = (int)get('id', 0);
if ($id <= 0) {
    require __DIR__ . '/404.php';
}

$row = null;
$list = [];
try {
    $row = fetch_actress($id);
} catch (Throwable) {
    $row = null;
}

if (!is_array($row)) {
    require __DIR__ . '/404.php';
}

$actressDisplayName = trim((string)($row['name'] ?? ''));
$dmmId = trim((string)($row['dmm_id'] ?? ''));
if ($actressDisplayName === '' || is_invalid_actress_name($actressDisplayName) || str_starts_with($dmmId, 'name:') || !ctype_digit($dmmId)) {
    require __DIR__ . '/404.php';
}

try {
    analytics_log_actress_page_view((int)$row['id']);
} catch (Throwable $e) {
    error_log('actress page view logging failed: ' . $e->getMessage());
}

$apiSyncStatus = ['attempted' => false, 'success' => false, 'message' => ''];
$actressPage = max(1, (int)get('page', 1));
$limit = 24;
$offset = ($actressPage - 1) * $limit;
$hasNext = false;
$actressItemsLoaded = false;

$profile = [
    'dmm_id' => $dmmId,
    'name' => $actressDisplayName,
    'ruby' => (string)($row['ruby'] ?? ''),
    'birthday' => (string)($row['birthday'] ?? ''),
    'prefectures' => (string)($row['prefectures'] ?? ''),
    'image_url' => (string)($row['image_url'] ?? ''),
    'image_small' => (string)($row['image_small'] ?? ''),
    'image_large' => (string)($row['image_large'] ?? ''),
    'bust' => '',
    'cup' => '',
    'waist' => '',
    'hip' => '',
    'height' => '',
    'blood_type' => '',
    'hobby' => '',
];

$profileCacheKey = 'public.actress.profile.v1.' . $id;
$profileCacheTtl = 7 * 24 * 60 * 60;
$cachedProfilePayload = null;
try {
    $decodedProfileCache = json_decode((string)(setting_get($profileCacheKey, '') ?? ''), true);
    if (is_array($decodedProfileCache)) {
        $cachedAt = (int)($decodedProfileCache['cached_at'] ?? 0);
        $cachedProfile = $decodedProfileCache['profile'] ?? null;
        if ($cachedAt > 0 && $cachedAt >= time() - $profileCacheTtl && is_array($cachedProfile)) {
            $cachedProfilePayload = $cachedProfile;
        }
    }
} catch (Throwable) {
    $cachedProfilePayload = null;
}

$shouldRefreshProfile = !is_array($cachedProfilePayload);
if (is_array($cachedProfilePayload)) {
    foreach (array_keys($profile) as $profileKey) {
        if (array_key_exists($profileKey, $cachedProfilePayload)) {
            $profile[$profileKey] = (string)$cachedProfilePayload[$profileKey];
        }
    }
    $apiSyncStatus['success'] = true;
    $apiSyncStatus['message'] = '保存済みの女優プロフィールを表示しています。';
} else {
    $apiSyncStatus['message'] = '保存済み情報を先に表示し、プロフィール詳細を後から取得します。';
}

try {
    [$list, $hasNext] = paginate_items(dedupe_items_by_key(fetch_items_by_actress((int)$row['id'], $limit + 1, $offset)), $limit);
    $actressItemsLoaded = true;
} catch (Throwable) {
    $list = [];
    $hasNext = false;
}
if ($actressItemsLoaded && $actressPage === 1 && $list === []) {
    require __DIR__ . '/404.php';
}

$profileImage = actress_profile_image($profile);
$actressDisplayName = $profile['name'];

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
$accessRankingRows = array_values(array_filter(
    pcf_public_weighted_ranking('actresses', $accessRankingPeriod),
    static function ($rankingRow): bool {
        if (!is_array($rankingRow)) {
            return false;
        }
        $rankingName = trim((string)($rankingRow['name'] ?? ''));
        $rankingDmmId = trim((string)($rankingRow['dmm_id'] ?? ''));
        return $rankingName !== '' && !is_invalid_actress_name($rankingName) && ctype_digit($rankingDmmId);
    }
));

unset($title, $pageTitle);
$title = $actressDisplayName;
$pageTitle = $actressDisplayName;
$pageDescription = mb_strimwidth($actressDisplayName . 'の出演作品一覧。FANZAで人気の' . $actressDisplayName . '作品をチェック。', 0, 150, '…', 'UTF-8');
$canonicalUrl = public_url('actress.php') . '?' . http_build_query([
    'id' => $id,
    'page' => $actressPage > 1 ? $actressPage : null,
]);
$ogImage = $profileImage;
if ($actressPage > 1) {
    $relPrev = public_url('actress.php') . '?' . http_build_query(['id' => $id, 'page' => $actressPage - 1]);
}
if ($hasNext) {
    $relNext = public_url('actress.php') . '?' . http_build_query(['id' => $id, 'page' => $actressPage + 1]);
}
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => '女優一覧', 'url' => public_url('actresses.php')],
    ['label' => $actressDisplayName],
]); ?>

<section class="pcf-profile pcf-profile--plain pcf-actress-profile" style="display:grid;grid-template-columns:minmax(220px,320px) 1fr;gap:20px;align-items:start;">
  <img id="actress-profile-image" class="pcf-actress-profile__image" src="<?= e($profileImage) ?>" alt="<?= e($actressDisplayName) ?>" decoding="async" fetchpriority="high" style="width:100%;max-width:320px;aspect-ratio:1/1;object-fit:cover;border-radius:6px;">
  <div class="pcf-profile__body pcf-actress-profile__body">
    <h1 class="pcf-hero__title" style="margin:0 0 16px;padding:0 0 6px 8px;border-left:8px solid #002bff;border-bottom:2px solid #002bff;"><?= e($actressDisplayName) ?></h1>
    <div class="pcf-actress-profile__details" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
      <dl class="pcf-detail-list" style="margin:0;">
        <div><dt>よみ</dt><dd data-actress-profile="ruby"><?= e(actress_profile_value($profile, 'ruby')) ?></dd></div>
        <div><dt>誕生日</dt><dd data-actress-profile="birthday"><?= e(!empty($profile['birthday']) ? format_date((string)$profile['birthday']) : '未登録') ?></dd></div>
        <div><dt>出身地</dt><dd data-actress-profile="prefectures"><?= e(actress_profile_value($profile, 'prefectures')) ?></dd></div>
        <div><dt>趣味</dt><dd data-actress-profile="hobby"><?= e(actress_profile_value($profile, 'hobby')) ?></dd></div>
      </dl>
      <dl class="pcf-detail-list" style="margin:0;">
        <div><dt>バスト</dt><dd data-actress-profile="bust"><?= e(actress_profile_value($profile, 'bust')) ?></dd></div>
        <div><dt>カップ</dt><dd data-actress-profile="cup"><?= e(actress_profile_value($profile, 'cup')) ?></dd></div>
        <div><dt>ウエスト</dt><dd data-actress-profile="waist"><?= e(actress_profile_value($profile, 'waist')) ?></dd></div>
        <div><dt>ヒップ</dt><dd data-actress-profile="hip"><?= e(actress_profile_value($profile, 'hip')) ?></dd></div>
        <div><dt>身長</dt><dd data-actress-profile="height"><?= e(actress_profile_value($profile, 'height')) ?></dd></div>
        <div><dt>血液型</dt><dd data-actress-profile="blood_type"><?= e(actress_profile_value($profile, 'blood_type')) ?></dd></div>
      </dl>
    </div>
  </div>
</section>

<?php if ($shouldRefreshProfile): ?>
<script>
(() => {
  const endpoint = <?= json_encode(public_url('actress_profile.php') . '?id=' . $id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  fetch(endpoint, {
    credentials: 'same-origin',
    headers: {'Accept': 'application/json'}
  })
    .then((response) => response.ok ? response.json() : null)
    .then((data) => {
      if (!data || !data.success || !data.display) return;
      document.querySelectorAll('[data-actress-profile]').forEach((node) => {
        const key = node.getAttribute('data-actress-profile');
        if (key && Object.prototype.hasOwnProperty.call(data.display, key)) {
          node.textContent = String(data.display[key] || '未登録');
        }
      });
      const image = document.getElementById('actress-profile-image');
      if (image && data.image_url) {
        image.src = String(data.image_url);
      }
    })
    .catch(() => {});
})();
</script>
<?php endif; ?>

<h2 class="pcf-section-title" style="margin:15px 0 12px;padding-bottom:10px;border-bottom:2px solid #d7dbe3;"><?= e($actressDisplayName) ?>の作品</h2>
<?php if ($list !== []): ?>
  <section class="pcf-related-grid pcf-item-related-grid pcf-actress-related-grid">
    <?php foreach ($list as $item): pcf_render_item_card(is_array($item) ? $item : []); endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if ($actressPage > 1): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('actress.php') . '?' . http_build_query(['id' => $id, 'page' => $actressPage - 1])) ?>">前へ</a>
    <?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$actressPage) ?></span>
    <?php if ($hasNext): ?>
      <a class="pcf-pagination__link" href="<?= e(public_url('actress.php') . '?' . http_build_query(['id' => $id, 'page' => $actressPage + 1])) ?>">次へ</a>
    <?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('関連作品はまだありません。'); ?>
<?php endif; ?>

<section id="access-ranking" class="block" style="margin-top:24px;">
  <h2 class="section-title">人気の女優ランキング！</h2>
  <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px;">
    <?php foreach ($accessRankingTabs as $tabKey => $tabConfig): ?>
      <?php $tabUrl = public_url('actress.php') . '?id=' . rawurlencode((string)$id) . '&rank_period=' . rawurlencode((string)$tabKey) . '#access-ranking'; ?>
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
            <th style="width:auto; text-align:center; padding:8px; border-bottom:1px solid #ddd; background:#0b5ed7; color:#fff;">女優名</th>
            <th style="width:120px; text-align:center; padding:8px; border-bottom:1px solid #ddd; background:#0b5ed7; color:#fff;">ランキング点</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accessRankingRows as $index => $rankingRow): ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid #eee; text-align:center;"><?= e((string)($index + 1)) ?></td>
              <td style="padding:8px; border-bottom:1px solid #eee; text-align:left;">
                <?php
                $rankingActressUrl = public_url('actress.php') . '?id=' . rawurlencode((string)($rankingRow['id'] ?? ''));
                ?>
                <a href="<?= e($rankingActressUrl) ?>"><?= e((string)($rankingRow['name'] ?? '')) ?></a>
              </td>
              <td style="padding:8px; border-bottom:1px solid #eee; text-align:center;"><?= e((string)((int)($rankingRow['access_count'] ?? 0))) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <?php pcf_render_empty('人気の女優ランキング！のデータがありません。'); ?>
  <?php endif; ?>
</section>

<div id="sample-movie-modal" class="sample-movie-modal" aria-hidden="true">
  <div class="sample-movie-modal__overlay" data-movie-close="1"></div>
  <div class="sample-movie-modal__dialog" role="dialog" aria-modal="true" aria-label="サンプル動画プレイヤー">
    <button type="button" class="sample-movie-modal__close" data-movie-close="1" aria-label="閉じる">×</button>
    <div id="sample-movie-title" class="sample-movie-modal__title">サンプル動画</div>
    <div class="sample-movie-modal__frame-wrap">
      <iframe id="sample-movie-frame" class="sample-movie-modal__frame" src="about:blank" allow="autoplay; fullscreen" referrerpolicy="no-referrer"></iframe>
    </div>
  </div>
</div>
<script>
(() => {
  const modal = document.getElementById('sample-movie-modal');
  const frame = document.getElementById('sample-movie-frame');
  const titleNode = document.getElementById('sample-movie-title');
  if (!modal || !frame || !titleNode) return;

  const openMovie = (url, title) => {
    if (!url) return;
    const normalizedTitle = String(title || '').trim();
    titleNode.textContent = normalizedTitle !== '' ? normalizedTitle : 'サンプル動画';
    modal.style.setProperty('--movie-modal-width', '900px');
    frame.src = url;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  };

  const closeMovie = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    frame.src = 'about:blank';
    modal.style.removeProperty('--movie-modal-width');
    titleNode.textContent = 'サンプル動画';
  };

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('.sample-movie-trigger');
    if (trigger && !trigger.disabled) {
      event.preventDefault();
      const card = trigger.closest('.pcf-dm-card');
      const fallbackTitle = card ? (card.querySelector('.pcf-dm-card__title')?.textContent || '') : '';
      openMovie(trigger.dataset.movieUrl || '', trigger.dataset.movieTitle || fallbackTitle);
      return;
    }

    if (event.target.closest('[data-movie-close="1"]')) {
      event.preventDefault();
      closeMovie();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) {
      closeMovie();
    }
  });
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
