<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/actress_sync_cycle.php';

auth_require_admin();

$title = '自動設定';
$message = '';
$messageType = 'success';
$intervalOptions = [10, 20, 30, 60, 120, 180, 360, 720];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));

    $enabled = post('item_sync_enabled', '0') === '1' ? '1' : '0';
    $interval = (int)post('item_sync_interval_minutes', 60);
    if (!in_array($interval, $intervalOptions, true)) {
        $interval = 60;
    }

    site_setting_set_many([
        'item_sync_enabled' => $enabled,
        'item_sync_interval_minutes' => (string)$interval,
    ]);
    $message = '自動設定を保存しました。';
}

$enabled = settings_bool('item_sync_enabled', false);
$currentInterval = settings_int('item_sync_interval_minutes', 60);
$lastRunAt = site_setting_get('pca_sync_last_run_at', '未実行');
$lastMessage = site_setting_get('pca_sync_last_message', '');

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1>自動設定</h1>
  <?php if ($message !== ''): ?>
    <div class="admin-notice <?= $messageType === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>"><p><?= e($message) ?></p></div>
  <?php endif; ?>

  <p>cronの1サイクルは手動の「今すぐ1回実行」と同じです。</p>
  <p><strong>女優情報100件 → 女優画像100人分補完 → 保存済み女優100人分の作品取得</strong></p>

  <form method="post" class="stack" style="max-width:760px;">
    <?= csrf_input() ?>
    <div style="display:grid;grid-template-columns:180px minmax(260px,1fr);gap:14px;align-items:center;">
      <div><strong>自動取得</strong></div>
      <div>
        <input type="hidden" name="item_sync_enabled" value="0">
        <label><input type="checkbox" name="item_sync_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> ON</label>
      </div>

      <div><strong>実行間隔</strong></div>
      <div>
        <select name="item_sync_interval_minutes" style="width:100%;">
          <?php foreach ($intervalOptions as $value): ?>
            <option value="<?= e((string)$value) ?>" <?= $currentInterval === $value ? 'selected' : '' ?>><?= e((string)$value) ?>分</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="admin-actions" style="margin-top:20px;"><button type="submit">保存</button></div>
  </form>

  <div class="admin-card" style="margin-top:24px;">
    <strong>最終同期</strong>
    <p><?= e($lastRunAt !== '' ? $lastRunAt : '未実行') ?></p>
    <?php if ($lastMessage !== ''): ?><p><?= e($lastMessage) ?></p><?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
