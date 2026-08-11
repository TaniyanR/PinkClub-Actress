<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_once __DIR__ . '/partials/_helpers.php';
require_once __DIR__ . '/../lib/repository.php';
require_once __DIR__ . '/partials/public_ui.php';

$labels = [];
$displayRows = [];
try {
    for ($labelOffset = 0; ; $labelOffset += 200) {
        $labelPageRows = fetch_labels(200, $labelOffset);
        if ($labelPageRows === []) {
            break;
        }
        $labels = array_merge($labels, $labelPageRows);
        if (count($labelPageRows) < 200) {
            break;
        }
    }
} catch (Throwable) {
    $labels = [];
}

foreach ($labels as $label) {
    if (!is_array($label)) {
        continue;
    }
    $name = trim((string)($label['name'] ?? ''));
    if ($name === '' || pcf_is_noise_name($name)) {
        continue;
    }
    $displayRows[] = $label;
}

$kanaOrder = ['あ', 'か', 'さ', 'た', 'な', 'は', 'ま', 'や', 'ら', 'わ'];
$kanaGroups = [];
foreach ($kanaOrder as $kana) {
    $kanaGroups[$kana] = [];
}
$alphaGroups = [];

$resolveIndex = static function (array $row): array {
    $name = trim((string)($row['name'] ?? ''));
    $ruby = trim((string)($row['ruby'] ?? ''));
    $base = $ruby !== '' ? $ruby : $name;
    $ch = mb_substr($base, 0, 1);
    if ($ch === '') {
        return ['type' => 'none', 'key' => ''];
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
    return ['type' => 'none', 'key' => ''];
};

foreach ($displayRows as $label) {
    $idx = $resolveIndex($label);
    if ($idx['type'] === 'kana' && isset($kanaGroups[$idx['key']])) {
        $kanaGroups[$idx['key']][] = $label;
        continue;
    }
    if ($idx['type'] === 'alpha') {
        $alphaGroups[$idx['key']][] = $label;
    }
}

$sortByName = static function (array &$list): void {
    usort($list, static function (array $a, array $b): int {
        return strcmp(mb_strtolower((string)($a['name'] ?? ''), 'UTF-8'), mb_strtolower((string)($b['name'] ?? ''), 'UTF-8'));
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

$pageTitle = 'レーベル一覧';
$pageDescription = 'レーベル一覧ページです。';
$canonicalUrl = canonical_url('/labels.php');

include __DIR__ . '/partials/header.php';
?>
<?php pcf_render_hero('レーベル一覧'); ?>

<?php if ($displayRows !== []): ?>
  <div class="pcf-kana-directory">
    <?php foreach ($kanaGroups as $kana => $groupRows): ?>
      <?php if ($groupRows === []): continue; endif; ?>
      <section class="pcf-index-block">
        <h2 class="pcf-section-title"><?= e($kana) ?>行</h2>
        <div class="pcf-list-card__meta pcf-chip-list">
          <?php foreach ($groupRows as $i => $label): ?>
            <a class="pcf-chip" href="<?= e(public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? ''), 'name' => (string)($label['name'] ?? '')])) ?>"><?= e((string)($label['name'] ?? '')) ?></a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
    <?php if ($alphaGroups !== []): ?>
      <section class="pcf-index-block">
        <h2 class="pcf-section-title">A~Z</h2>
        <?php foreach ($alphaGroups as $letter => $groupRows): ?>
          <div class="pcf-list-card__meta pcf-chip-list">
            <strong><?= e($letter) ?></strong>
            <?php foreach ($groupRows as $i => $label): ?>
              <a class="pcf-chip" href="<?= e(public_url('label.php') . '?' . http_build_query(['id' => (string)($label['id'] ?? ''), 'name' => (string)($label['name'] ?? '')])) ?>"><?= e((string)($label['name'] ?? '')) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php pcf_render_empty('レーベル情報がありません。'); ?>
<?php endif; ?>
<?php include __DIR__ . '/partials/footer.php'; ?>
