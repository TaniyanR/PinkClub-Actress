<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/../lib/public_directory_cache.php';
require_once __DIR__ . '/partials/public_ui.php';

$rows = pcf_public_directory_cache_rows('genres');
$displayRows = [];
$seenRows = [];
foreach ($rows as $r) {
    if (!is_array($r)) {
        continue;
    }

    $name = trim((string)($r['name'] ?? ''));
    $dmmId = trim((string)($r['dmm_id'] ?? ''));
    if ($name === '' || pcf_is_noise_name($name) || str_starts_with($dmmId, 'name:')) {
        continue;
    }

    $id = (int)($r['id'] ?? 0);
    $signature = $id > 0 ? 'id:' . $id : 'name:' . mb_strtolower($name, 'UTF-8');
    if (isset($seenRows[$signature])) {
        continue;
    }

    $seenRows[$signature] = true;
    $displayRows[] = $r;
}

$kanaOrder = ['あ', 'か', 'さ', 'た', 'な', 'は', 'ま', 'や', 'ら', 'わ'];
$kanaGroups = [];
foreach ($kanaOrder as $kana) {
    $kanaGroups[$kana] = [];
}
$alphaGroups = [];
$otherRows = [];

$resolveIndex = static function (array $row): array {
    $name = trim((string)($row['name'] ?? ''));
    $ch = mb_substr($name, 0, 1);
    if ($ch === '') {
        return ['type' => 'other', 'key' => ''];
    }

    $h = mb_convert_kana($ch, 'c', 'UTF-8');
    if (preg_match('/^[ぁ-お]/u', $h)) { return ['type' => 'kana', 'key' => 'あ']; }
    if (preg_match('/^[か-ご]/u', $h)) { return ['type' => 'kana', 'key' => 'か']; }
    if (preg_match('/^[さ-ぞ]/u', $h)) { return ['type' => 'kana', 'key' => 'さ']; }
    if (preg_match('/^[た-ど]/u', $h)) { return ['type' => 'kana', 'key' => 'た']; }
    if (preg_match('/^[な-の]/u', $h)) { return ['type' => 'kana', 'key' => 'な']; }
    if (preg_match('/^[は-ぽ]/u', $h)) { return ['type' => 'kana', 'key' => 'は']; }
    if (preg_match('/^[ま-も]/u', $h)) { return ['type' => 'kana', 'key' => 'ま']; }
    if (preg_match('/^[や-よ]/u', $h)) { return ['type' => 'kana', 'key' => 'や']; }
    if (preg_match('/^[ら-ろ]/u', $h)) { return ['type' => 'kana', 'key' => 'ら']; }
    if (preg_match('/^[わ-ん]/u', $h)) { return ['type' => 'kana', 'key' => 'わ']; }
    if (preg_match('/^[A-Za-z]/', $ch)) { return ['type' => 'alpha', 'key' => strtoupper($ch)]; }

    return ['type' => 'other', 'key' => ''];
};

foreach ($displayRows as $r) {
    $idx = $resolveIndex($r);
    if ($idx['type'] === 'kana' && isset($kanaGroups[$idx['key']])) {
        $kanaGroups[$idx['key']][] = $r;
        continue;
    }
    if ($idx['type'] === 'alpha') {
        $alphaGroups[$idx['key']][] = $r;
        continue;
    }
    $otherRows[] = $r;
}

$sortByName = static function (array &$list): void {
    usort($list, static function (array $a, array $b): int {
        return strcmp(
            mb_strtolower((string)($a['name'] ?? ''), 'UTF-8'),
            mb_strtolower((string)($b['name'] ?? ''), 'UTF-8')
        );
    });
};
foreach ($kanaGroups as &$groupRows) {
    $sortByName($groupRows);
}
unset($groupRows);
ksort($alphaGroups);
foreach ($alphaGroups as &$groupRows) {
    $sortByName($groupRows);
}
unset($groupRows);
$sortByName($otherRows);

$title = 'ジャンル一覧';
require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_hero('ジャンル一覧'); ?>

<?php if ($displayRows !== []): ?>
  <div class="pcf-kana-directory">
    <?php foreach ($kanaGroups as $kana => $groupRows): ?>
      <?php if ($groupRows === []): continue; endif; ?>
      <section class="pcf-index-block" style="content-visibility:auto;contain-intrinsic-size:700px;">
        <h2 class="pcf-section-title"><?= e($kana) ?>行</h2>
        <div class="pcf-list-card__meta pcf-chip-list">
          <?php foreach ($groupRows as $r): ?>
            <a class="pcf-chip" href="<?= e(public_url('genre.php?id=' . (int)($r['id'] ?? 0))) ?>"><?= e((string)($r['name'] ?? '')) ?></a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <?php if ($alphaGroups !== []): ?>
      <section class="pcf-index-block" style="content-visibility:auto;contain-intrinsic-size:700px;">
        <h2 class="pcf-section-title">A~Z</h2>
        <?php foreach ($alphaGroups as $letter => $groupRows): ?>
          <div class="pcf-list-card__meta pcf-chip-list">
            <strong><?= e($letter) ?></strong>
            <?php foreach ($groupRows as $r): ?>
              <a class="pcf-chip" href="<?= e(public_url('genre.php?id=' . (int)($r['id'] ?? 0))) ?>"><?= e((string)($r['name'] ?? '')) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <?php if ($otherRows !== []): ?>
      <section class="pcf-index-block" style="content-visibility:auto;contain-intrinsic-size:700px;">
        <h2 class="pcf-section-title">その他</h2>
        <div class="pcf-list-card__meta pcf-chip-list">
          <?php foreach ($otherRows as $r): ?>
            <a class="pcf-chip" href="<?= e(public_url('genre.php?id=' . (int)($r['id'] ?? 0))) ?>"><?= e((string)($r['name'] ?? '')) ?></a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php pcf_render_empty('ジャンルデータがありません。'); ?>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
