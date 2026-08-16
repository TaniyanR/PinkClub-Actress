<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/app.php';

auth_require_admin();

$title = '自動設定';
$message = '';
$messageType = 'success';
$intervalOptions = [10, 20, 30, 60, 120, 180, 360, 720];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $enabledValue = post('item_sync_enabled', '0') === '1' ? '1' : '0';
    $interval = (int)post('item_sync_interval_minutes', 60);
    if (!in_array($interval, $intervalOptions, true)) {
        $interval = 60;
    }
    site_setting_set_many([
        'item_sync_enabled' => $enabledValue,
        'item_sync_interval_minutes' => (string)$interval,
    ]);
    $message = '自動設定を保存しました。';
}

$enabled = settings_bool('item_sync_enabled', false);
$currentInterval = settings_int('item_sync_interval_minutes', 60);
if (!in_array($currentInterval, $intervalOptions, true)) {
    $currentInterval = 60;
}
$lastRunAt = site_setting_get('pca_sync_last_run_at', '');
$lastMessage = site_setting_get('pca_sync_last_message', '');

$totalActresses = 0;
$totalImages = 0;
$totalItems = 0;
try {
    $totalActresses = (int)db()->query("SELECT COUNT(*) FROM actresses WHERE TRIM(COALESCE(name, '')) <> ''")->fetchColumn();
    $totalImages = (int)db()->query("SELECT COUNT(*) FROM actresses WHERE COALESCE(image_large, '') <> '' OR COALESCE(image_small, '') <> '' OR COALESCE(image_url, '') <> ''")->fetchColumn();
    $totalItems = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
} catch (Throwable) {
}

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1>自動設定</h1>
  <p>PinkClub-Actress の自動取得はcronで実行します。1回の処理は<strong>「女優取得 → 女優画像補完 → 保存済み女優の作品取得」</strong>です。</p>
  <p>「女優・作品 API設定」の「今すぐ1回実行」も、cronとまったく同じ1回分の処理を実行します。</p>

  <?php if ($message !== ''): ?>
    <div class="admin-notice <?= $messageType === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>"><p><?= e($message) ?></p></div>
  <?php endif; ?>

  <form method="post" class="stack" style="max-width:760px;">
    <?= csrf_input() ?>
    <div style="display:grid;grid-template-columns:180px minmax(280px,1fr);gap:14px 18px;align-items:center;">
      <div><strong>自動更新</strong></div>
      <div>
        <input type="hidden" name="item_sync_enabled" value="0">
        <label style="display:inline-flex;align-items:center;gap:10px;"><input type="checkbox" name="item_sync_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> <span>ON</span></label>
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

  <div class="admin-status-grid" style="margin-top:24px;">
    <article class="admin-card admin-status-card"><strong>保存済み女優</strong><p><?= e(number_format($totalActresses)) ?>人</p></article>
    <article class="admin-card admin-status-card"><strong>画像取得済み女優</strong><p><?= e(number_format($totalImages)) ?>人</p></article>
    <article class="admin-card admin-status-card"><strong>保存済み作品</strong><p><?= e(number_format($totalItems)) ?>件</p></article>
  </div>

  <h2 style="margin-top:24px;">cron状態</h2>
  <table class="admin-table">
    <tr><th>状態</th><th>最終実行日時</th><th>最終結果</th></tr>
    <tr>
      <td><?= $enabled ? 'ON' : 'OFF' ?></td>
      <td><?= e($lastRunAt !== '' ? $lastRunAt : '未実行') ?></td>
      <td><?= e($lastMessage !== '' ? $lastMessage : 'まだ実行結果がありません。') ?></td>
    </tr>
  </table>

  <?php if ($enabled): ?>
    <div class="admin-notice admin-notice--success" style="margin-top:18px;"><p>自動更新はONです。サーバーのcronから <code>scripts/auto_import.php</code> が呼ばれた時に、設定間隔を確認して実行します。</p></div>
  <?php else: ?>
    <div class="admin-notice" style="margin-top:18px;"><p>自動更新はOFFです。必要ならONにして保存してください。</p></div>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
