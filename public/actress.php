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

function pca_detail_raw_item_matches(array $item, string $dmmId, string $name, bool $synthetic): bool
{
    $raw = json_decode((string)($item['raw_json'] ?? ''), true);
    if (!is_array($raw)) return false;
    $normalized = DmmNormalizer::normalizeItemsResponse(['result'=>['items'=>[$raw]]]);
    $normalizedItem = $normalized[0] ?? null;
    if (!is_array($normalizedItem)) return false;
    foreach (($normalizedItem['actresses'] ?? []) as $performer) {
        if (!is_array($performer)) continue;
        $performerId = trim((string)($performer['id'] ?? ''));
        $performerName = trim((string)($performer['name'] ?? ''));
        if ($synthetic) {
            if ($performerName !== '' && mb_strtolower($performerName, 'UTF-8') === mb_strtolower($name, 'UTF-8')) return true;
        } elseif ($performerId !== '' && $performerId === $dmmId) {
            return true;
        }
    }
    return false;
}

/**
 * 個別ページの商品はDBのraw_jsonを正として本人確認する。
 * 過去に誤って作られたitem_actressesが残っていても、他人の商品は表示しない。
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
                "SELECT i.* FROM items i
                 WHERE i.raw_json LIKE :name_like
                   AND " . pca_amateur_item_sql('i') . "
                 ORDER BY i.release_date DESC, i.id DESC
                 LIMIT 1000"
            );
            $stmt->execute([':name_like'=>'%'.$name.'%']);
        } else {
            $quoted = '%\"id\":\"' . $dmmId . '\"%';
            $numeric = '%\"id\":' . $dmmId . '%';
            $stmt = $pdo->prepare(
                "SELECT i.* FROM items i
                 WHERE i.raw_json LIKE :quoted OR i.raw_json LIKE :numeric
                 ORDER BY i.release_date DESC, i.id DESC
                 LIMIT 1000"
            );
            $stmt->execute([':quoted'=>$quoted, ':numeric'=>$numeric]);
        }
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $verified = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && pca_detail_raw_item_matches($candidate, $dmmId, $name, $synthetic)) {
                $verified[] = $candidate;
            }
        }
        if ($verified !== []) return array_slice($verified, $offset, $limit);
    } catch (Throwable $e) {
        error_log('actress raw verified item fetch failed: ' . $e->getMessage());
    }

    // raw_jsonが欠損した古いレコードだけ、修復済みrelationをフォールバックにする。
    try {
        if ($synthetic) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT i.* FROM items i
                 INNER JOIN item_actresses ia ON ia.item_id=i.id
                 WHERE ia.dmm_id=:dmm_id AND " . pca_amateur_item_sql('i') . "
                   AND (i.raw_json IS NULL OR i.raw_json='')
                 ORDER BY i.release_date DESC,i.id DESC LIMIT {$limit} OFFSET {$offset}"
            );
        } else {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT i.* FROM items i
                 INNER JOIN item_actresses ia ON ia.item_id=i.id
                 WHERE ia.dmm_id=:dmm_id AND (i.raw_json IS NULL OR i.raw_json='')
                 ORDER BY i.release_date DESC,i.id DESC LIMIT {$limit} OFFSET {$offset}"
            );
        }
        $stmt->execute([':dmm_id'=>$dmmId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('actress relation fallback failed: ' . $e->getMessage());
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
  const endpoint = <?= json_encode(public_url('actress_profile.php?id='.$id), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  fetch(endpoint,{credentials:'same-origin',headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():null).then(data=>{
      if(!data||!data.success||!data.display)return;
      document.querySelectorAll('[data-actress-profile]').forEach(node=>{
        const key=node.getAttribute('data-actress-profile');
        if(key&&Object.prototype.hasOwnProperty.call(data.display,key))node.textContent=String(data.display[key]||'未登録');
      });
      const image=document.getElementById('actress-profile-image');
      if(image&&data.image_url)image.src=String(data.image_url);
    }).catch(()=>{});
})();
</script>
<?php endif; ?>

<h2 class="pcf-section-title" style="margin:15px 0 12px;padding-bottom:10px;border-bottom:2px solid #d7dbe3;"><?= e($name) ?>の作品</h2>
<?php if ($items !== []): ?>
  <section class="pcf-related-grid pcf-item-related-grid pcf-actress-related-grid">
    <?php foreach ($items as $item): pcf_render_item_card(is_array($item)?$item:[]); endforeach; ?>
  </section>
  <nav class="pcf-pagination" aria-label="ページネーション">
    <?php if($page>1): ?><a class="pcf-pagination__link" href="<?= e(public_url('actress.php?id='.$id.'&page='.($page-1))) ?>">前へ</a><?php endif; ?>
    <span class="pcf-pagination__link is-current"><?= e((string)$page) ?></span>
    <?php if($hasNext): ?><a class="pcf-pagination__link" href="<?= e(public_url('actress.php?id='.$id.'&page='.($page+1))) ?>">次へ</a><?php endif; ?>
  </nav>
<?php else: ?>
  <?php pcf_render_empty('この女性の保存済み作品はまだ確認できません。cronまたは「今すぐ1回実行」で順番にAPI確認されます。'); ?>
<?php endif; ?>

<section id="access-ranking" class="block" style="margin-top:24px;">
  <h2 class="section-title">人気の女優ランキング！</h2>
  <div class="pca-rank-tabs">
    <?php foreach($rankTabs as $key=>$label): ?>
      <a class="<?= $rankPeriod===$key?'is-active':'' ?>" href="<?= e(public_url('actress.php?id='.$id.'&rank_period='.$key.'#access-ranking')) ?>" rel="nofollow"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if($ranking!==[]): ?>
    <div class="pca-ranking">
      <?php foreach(array_slice($ranking,0,20) as $rankRow):
        $rankId=(int)($rankRow['id']??0); $rankName=trim((string)($rankRow['name']??''));
        $rankImage=pca_actress_image($rankRow); if($rankImage==='')$rankImage=pcf_placeholder_data_uri('No Photo'); ?>
        <a href="<?= e(public_url('actress.php?id='.$rankId)) ?>"><img src="<?= e($rankImage) ?>" alt="<?= e($rankName) ?>" loading="lazy"><span><?= e($rankName) ?></span></a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php pcf_render_empty('ランキングデータはまだありません。'); ?>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
