<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_rankings.php';
require_once __DIR__ . '/../lib/actress_catalog.php';
require_once __DIR__ . '/../lib/dmm_normalizer.php';
require_once __DIR__ . '/partials/public_ui.php';

function pca_detail_profile_value(array $row, string $key): string
{
    $value = trim((string)($row[$key] ?? ''));
    return $value !== '' ? $value : '未登録';
}

/** @return string[] */
function pca_detail_equivalent_normal_dmm_ids(string $dmmId, string $name): array
{
    $ids = [];
    $dmmId = trim($dmmId);
    if ($dmmId !== '' && preg_match('/^[0-9]+$/', $dmmId)) $ids[$dmmId] = true;
    try {
        $stmt = db()->prepare("SELECT dmm_id,name FROM actresses WHERE dmm_id REGEXP '^[0-9]+$' AND TRIM(COALESCE(name,''))<>''");
        $stmt->execute();
        $target = pca_normalized_person_name($name);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            $candidateName = trim((string)($candidate['name'] ?? ''));
            $candidateId = trim((string)($candidate['dmm_id'] ?? ''));
            if ($candidateId !== '' && pca_normalized_person_name($candidateName) === $target) $ids[$candidateId] = true;
        }
    } catch (Throwable $e) {
        error_log('equivalent actress id lookup failed: '.$e->getMessage());
    }
    return array_keys($ids);
}

/**
 * 個別ページの商品はitem_actressesを正とする。
 * 通常女優は代表DMM女優IDを最優先し、同名統合された旧IDに作品がある場合だけ補完する。
 * しろうと女性は合成IDで厳密に紐付ける。
 */
function pca_detail_items(int $actressId, string $dmmId, string $name, int $limit, int $offset): array
{
    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    $synthetic = pca_is_synthetic_amateur_id($dmmId);
    $pdo = db();

    try {
        if ($synthetic) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT i.*
                 FROM items i
                 INNER JOIN item_actresses ia ON ia.item_id=i.id
                 WHERE ia.dmm_id=:dmm_id
                   AND " . pca_amateur_item_sql('i') . "
                 ORDER BY i.release_date DESC,i.id DESC
                 LIMIT {$limit} OFFSET {$offset}"
            );
            $stmt->execute([':dmm_id'=>$dmmId]);
        } else {
            $equivalentIds = pca_detail_equivalent_normal_dmm_ids($dmmId, $name);
            if ($equivalentIds === []) $equivalentIds = [$dmmId];
            $placeholders = implode(',', array_fill(0, count($equivalentIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT DISTINCT i.*
                 FROM items i
                 INNER JOIN item_actresses ia ON ia.item_id=i.id
                 WHERE ia.dmm_id IN ({$placeholders})
                   AND i.floor_code='videoa'
                 ORDER BY i.release_date DESC,i.id DESC
                 LIMIT {$limit} OFFSET {$offset}"
            );
            $stmt->execute($equivalentIds);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) return $rows;
    } catch (Throwable $e) {
        error_log('actress relation item fetch failed: '.$e->getMessage());
    }

    // 修復前の古いDB向けフォールバック。raw_jsonを正規化し本人IDが実在する作品だけ採用する。
    try {
        $equivalentIds = $synthetic ? [$dmmId] : pca_detail_equivalent_normal_dmm_ids($dmmId, $name);
        $idMap = array_fill_keys($equivalentIds, true);
        $stmt = $pdo->query("SELECT * FROM items WHERE raw_json IS NOT NULL AND raw_json<>'' ORDER BY release_date DESC,id DESC LIMIT 3000");
        $candidates = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $matched = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $raw = json_decode((string)($candidate['raw_json'] ?? ''), true);
            if (!is_array($raw)) continue;
            $normalized = DmmNormalizer::normalizeItemsResponse(['result'=>['items'=>[$raw]]]);
            $item = $normalized[0] ?? null;
            if (!is_array($item)) continue;
            $isAmateurItem = strtolower(trim((string)($item['floor_code'] ?? ''))) === 'videoc';
            if ($synthetic !== $isAmateurItem) continue;
            foreach (($item['actresses'] ?? []) as $performer) {
                if (!is_array($performer)) continue;
                $performerId = trim((string)($performer['id'] ?? ''));
                $performerName = trim((string)($performer['name'] ?? ''));
                if (!$synthetic && $performerId !== '' && isset($idMap[$performerId])) {
                    $matched[] = $candidate;
                    break;
                }
                if ($synthetic) {
                    $syntheticId = 'name:' . sha1(mb_strtolower($performerName, 'UTF-8'));
                    if ($performerName !== '' && $syntheticId === $dmmId) {
                        $matched[] = $candidate;
                        break;
                    }
                }
            }
        }
        return array_slice($matched, $offset, $limit);
    } catch (Throwable $e) {
        error_log('actress raw item fallback failed: '.$e->getMessage());
        return [];
    }
}

$id = max(0, (int)get('id', 0));
if ($id <= 0) require __DIR__ . '/404.php';

try { $row = fetch_actress($id); }
catch (Throwable $e) { error_log('actress fetch failed: '.$e->getMessage()); $row = null; }
if (!is_array($row)) require __DIR__ . '/404.php';

$name = trim((string)($row['name'] ?? ''));
$dmmId = trim((string)($row['dmm_id'] ?? ''));
if ($name === '') require __DIR__ . '/404.php';

try { analytics_log_actress_page_view($id); }
catch (Throwable $e) { error_log('actress page view logging failed: '.$e->getMessage()); }

$profile = [
    'name'=>$name,
    'ruby'=>(string)($row['ruby'] ?? ''),
    'birthday'=>(string)($row['birthday'] ?? ''),
    'prefectures'=>(string)($row['prefectures'] ?? ''),
    'hobby'=>'','bust'=>'','cup'=>'','waist'=>'','hip'=>'','height'=>'','blood_type'=>'',
];

$page = max(1, (int)get('page', 1));
$limit = 24;
$offset = ($page - 1) * $limit;
$loaded = pca_detail_items($id, $dmmId, $name, $limit + 1, $offset);
$loaded = dedupe_items_by_key($loaded);
[$items, $hasNext] = paginate_items($loaded, $limit);

$isAmateur = pca_identity_is_amateur($dmmId, $name);
$profileImage = pca_actress_image($row);
if ($isAmateur && $profileImage === '' && $items !== []) $profileImage = pcf_item_image($items[0]);
if ($profileImage === '') $profileImage = pcf_placeholder_data_uri('No Photo');

$rankPeriod = trim((string)get('rank_period', 'daily'));
$rankTabs = ['daily'=>'本日','weekly'=>'週間','monthly'=>'月間','yearly'=>'年間'];
if (!isset($rankTabs[$rankPeriod])) $rankPeriod = 'daily';
try { $ranking = pcf_public_weighted_ranking('actresses', $rankPeriod); }
catch (Throwable) { $ranking = []; }
$ranking = array_values(array_filter($ranking, static fn($candidate): bool => is_array($candidate) && (int)($candidate['id'] ?? 0)>0 && trim((string)($candidate['name'] ?? ''))!==''));

$title = $name;
$pageTitle = $name;
$pageDescription = $name . 'のプロフィールと出演作品。';
$canonicalUrl = public_url('actress.php?id=' . $id);
$ogImage = $profileImage;
require __DIR__ . '/partials/header.php';
?>

<?php pcf_render_breadcrumbs([
    ['label'=>'トップ','url'=>public_url('')],
    ['label'=>$isAmateur ? 'しろうと女性一覧' : '女優一覧','url'=>public_url($isAmateur ? 'amateur_actresses.php' : 'actresses.php')],
    ['label'=>$name],
]); ?>

<style>
.pca-profile{display:grid;grid-template-columns:minmax(220px,320px) 1fr;gap:20px;align-items:start}.pca-profile__image{width:100%;max-width:320px;aspect-ratio:1/1;object-fit:cover;border-radius:6px;background:#f2f4f7}.pca-profile h1{margin:0 0 16px;padding:0 0 6px 8px;border-left:8px solid #002bff;border-bottom:2px solid #002bff}.pca-profile__details{display:grid;grid-template-columns:1fr 1fr;gap:24px}.pca-detail-list{margin:0}.pca-detail-list div{display:grid;grid-template-columns:100px 1fr;border-bottom:1px solid #e4e7ec}.pca-detail-list dt,.pca-detail-list dd{margin:0;padding:10px}.pca-detail-list dt{font-weight:700;background:#f8fafc}.pca-rank-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}.pca-rank-tabs a{display:inline-block;padding:7px 12px;border:1px solid #0b5ed7;border-radius:6px;text-decoration:none}.pca-rank-tabs a.is-active{background:#0b5ed7;color:#fff}.pca-ranking{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.pca-ranking a{display:flex;align-items:center;gap:8px;padding:8px;border:1px solid #e4e7ec;border-radius:7px;text-decoration:none;color:inherit}.pca-ranking img{width:48px;height:48px;border-radius:50%;object-fit:cover;background:#f2f4f7}@media(max-width:720px){.pca-profile{grid-template-columns:1fr}.pca-profile__image{max-width:100%}.pca-profile__details{grid-template-columns:1fr;gap:0}.pca-ranking{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<section class="pca-profile">
  <img id="actress-profile-image" class="pca-profile__image" src="<?= e($profileImage) ?>" alt="<?= e($name) ?>" decoding="async" fetchpriority="high">
  <div>
    <h1><?= e($name) ?></h1>
    <div class="pca-profile__details">
      <dl class="pca-detail-list">
        <div><dt>よみ</dt><dd data-actress-profile="ruby"><?= e(pca_detail_profile_value($profile,'ruby')) ?></dd></div>
        <div><dt>誕生日</dt><dd data-actress-profile="birthday"><?= e(pca_detail_profile_value($profile,'birthday')) ?></dd></div>
        <div><dt>出身地</dt><dd data-actress-profile="prefectures"><?= e(pca_detail_profile_value($profile,'prefectures')) ?></dd></div>
        <div><dt>趣味</dt><dd data-actress-profile="hobby"><?= e(pca_detail_profile_value($profile,'hobby')) ?></dd></div>
      </dl>
      <dl class="pca-detail-list">
        <div><dt>バスト</dt><dd data-actress-profile="bust"><?= e(pca_detail_profile_value($profile,'bust')) ?></dd></div>
        <div><dt>カップ</dt><dd data-actress-profile="cup"><?= e(pca_detail_profile_value($profile,'cup')) ?></dd></div>
        <div><dt>ウエスト</dt><dd data-actress-profile="waist"><?= e(pca_detail_profile_value($profile,'waist')) ?></dd></div>
        <div><dt>ヒップ</dt><dd data-actress-profile="hip"><?= e(pca_detail_profile_value($profile,'hip')) ?></dd></div>
        <div><dt>身長</dt><dd data-actress-profile="height"><?= e(pca_detail_profile_value($profile,'height')) ?></dd></div>
        <div><dt>血液型</dt><dd data-actress-profile="blood_type"><?= e(pca_detail_profile_value($profile,'blood_type')) ?></dd></div>
      </dl>
    </div>
  </div>
</section>

<?php if (!$isAmateur): ?>
<script>
(() => {
  const endpoint = <?= json_encode(public_url('actress_profile.php?id=' . $id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  fetch(endpoint, {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then((response) => response.ok ? response.json() : null)
    .then((data) => {
      if (!data || !data.success || !data.display) return;
      document.querySelectorAll('[data-actress-profile]').forEach((node) => {
        const key = node.getAttribute('data-actress-profile');
        if (key && Object.prototype.hasOwnProperty.call(data.display, key)) node.textContent = String(data.display[key] || '未登録');
      });
      const image = document.getElementById('actress-profile-image');
      if (image && data.image_url) image.src = String(data.image_url);
    })
    .catch(() => {});
})();
</script>
<?php endif; ?>

<h2 class="pcf-section-title" style="margin:15px 0 12px;padding-bottom:10px;border-bottom:2px solid #d7dbe3;"><?= e($name) ?>の作品</h2>
<?php if ($items !== []): ?>
  <section class="pcf-related-grid pcf-item-related-grid pcf-actress-related-grid">
    <?php foreach ($items as $item): pcf_render_item_card(is_array($item) ? $item : []); endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if ($page > 1): ?><a class="pcf-pagination__link" href="<?= e(public_url('actress.php?id=' . $id . '&page=' . ($page - 1))) ?>">前へ</a><?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$page) ?></span>
    <?php if ($hasNext): ?><a class="pcf-pagination__link" href="<?= e(public_url('actress.php?id=' . $id . '&page=' . ($page + 1))) ?>">次へ</a><?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('この女性に紐づく保存済み作品はまだありません。cronまたは「今すぐ1回実行」で商品100件ずつ取得されます。'); ?>
<?php endif; ?>

<section id="access-ranking" class="block" style="margin-top:24px;">
  <h2 class="section-title">人気の女優ランキング！</h2>
  <div class="pca-rank-tabs">
    <?php foreach ($rankTabs as $key=>$label): ?>
      <a class="<?= $rankPeriod===$key?'is-active':'' ?>" href="<?= e(public_url('actress.php?id='.$id.'&rank_period='.$key.'#access-ranking')) ?>" rel="nofollow"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($ranking !== []): ?>
    <div class="pca-ranking">
      <?php foreach (array_slice($ranking,0,20) as $rankRow): ?>
        <?php $rankId=(int)($rankRow['id']??0); $rankName=trim((string)($rankRow['name']??'')); $rankImage=pca_actress_image($rankRow); if($rankImage==='')$rankImage=pcf_placeholder_data_uri('No Photo'); ?>
        <a href="<?= e(public_url('actress.php?id='.$rankId)) ?>"><img src="<?= e($rankImage) ?>" alt="<?= e($rankName) ?>" loading="lazy"><span><?= e($rankName) ?></span></a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php pcf_render_empty('ランキングデータはまだありません。'); ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
