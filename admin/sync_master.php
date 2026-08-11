<?php

declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$types = ['actress' => '女優', 'genre' => 'ジャンル', 'maker' => 'メーカー', 'series' => 'シリーズ', 'author' => '作者'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail(post('_csrf'));
    $type = (string) post('type');
    $floorId = trim((string) post('floor_id', '')) ?: null;

    $hits = (int) post('hits', 100);
    $offset = (int) post('offset', 1);
    $actressParams = [];

    if ($type === 'actress') {
        $allowedActressFilters = [
            'initial',
            'actress_id',
            'keyword',
            'gte_bust',
            'lte_bust',
            'gte_waist',
            'lte_waist',
            'gte_hip',
            'lte_hip',
            'gte_height',
            'lte_height',
            'gte_birthday',
            'lte_birthday',
            'sort',
        ];
        foreach ($allowedActressFilters as $filterName) {
            $value = trim((string) post($filterName, ''));
            if ($value !== '') {
                $actressParams[$filterName] = $value;
            }
        }
    }

    try {
        $count = dmm_sync_service()->syncMaster($type, $floorId, $offset, $hits, $actressParams);
        flash_set('success', "{$types[$type]}同期: {$count}件");
    } catch (Throwable $e) {
        flash_set('error', 'マスタ同期失敗: ' . $e->getMessage());
    }
    app_redirect('admin/sync_master.php');
}

$title = 'Masters';
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>Masters</h1>
  <p class="admin-form-note">必要なマスタのみ都度同期できます。</p>
</section>

<?php foreach ($types as $key => $label): ?>
  <form method="post" class="admin-card">
    <?= csrf_input() ?>
    <input type="hidden" name="type" value="<?= e($key) ?>">

    <?php if ($key !== 'actress'): ?>
      <label><?= e($label) ?> floor_id(任意)
        <input name="floor_id">
      </label>
    <?php else: ?>
      <p class="admin-form-note">女優同期は floor_id 不要です。必要な条件のみ入力してください。</p>
      <div class="admin-form-grid">
        <label>女優ID
          <input name="actress_id" placeholder="15365">
        </label>
        <label>キーワード
          <input name="keyword" placeholder="あさみ">
        </label>
        <label>頭文字(50音)
          <input name="initial" placeholder="あ">
        </label>
        <label>ソート
          <input name="sort" placeholder="-id / name / -bust など">
        </label>
        <label>バスト下限(gte_bust)
          <input name="gte_bust" type="number" min="0">
        </label>
        <label>バスト上限(lte_bust)
          <input name="lte_bust" type="number" min="0">
        </label>
        <label>ウエスト下限(gte_waist)
          <input name="gte_waist" type="number" min="0">
        </label>
        <label>ウエスト上限(lte_waist)
          <input name="lte_waist" type="number" min="0">
        </label>
        <label>ヒップ下限(gte_hip)
          <input name="gte_hip" type="number" min="0">
        </label>
        <label>ヒップ上限(lte_hip)
          <input name="lte_hip" type="number" min="0">
        </label>
        <label>身長下限(gte_height)
          <input name="gte_height" type="number" min="0">
        </label>
        <label>身長上限(lte_height)
          <input name="lte_height" type="number" min="0">
        </label>
        <label>生年月日下限(gte_birthday)
          <input name="gte_birthday" type="date">
        </label>
        <label>生年月日上限(lte_birthday)
          <input name="lte_birthday" type="date">
        </label>
      </div>
    <?php endif; ?>

    <div class="admin-form-grid">
      <label>取得件数(hits)
        <input name="hits" type="number" min="1" max="100" value="100">
      </label>
      <label>開始位置(offset)
        <input name="offset" type="number" min="1" value="1">
      </label>
    </div>

    <button class="button-secondary" type="submit">同期</button>
  </form>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
