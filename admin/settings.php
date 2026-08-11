<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'Settings';
$settings = settings_get();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail(post('_csrf'));
    $action = (string) post('action');
    $apiId = trim((string) post('api_id', ''));
    $affiliateId = trim((string) post('affiliate_id', ''));
    settings_save($apiId, $affiliateId);

    try {
        $client = dmm_client_from_settings();
        if ($action === 'test') {
            $client->fetchFloorList();
            $result = '接続テスト成功（API疎通OK）';
        } elseif ($action === 'sync_floor') {
            $count = dmm_sync_service()->syncFloors();
            $result = "Floor同期完了: {$count}件";
        } else {
            $result = '設定を保存しました。';
        }
    } catch (Throwable $e) {
        $result = 'エラー: ' . $e->getMessage();
    }
    $settings = settings_get();
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>Settings</h1>
  <?php if ($result): ?><div class="admin-notice admin-notice--success"><p><?= e($result) ?></p></div><?php endif; ?>
  <form method="post">
    <?= csrf_input() ?>
    <label>API ID
      <input name="api_id" value="<?= e($settings['api_id'] ?? '') ?>">
    </label>
    <label>Affiliate ID
      <input name="affiliate_id" value="<?= e($settings['affiliate_id'] ?? '') ?>">
    </label>
    <div class="admin-actions">
      <button name="action" value="save" type="submit">保存</button>
      <button class="button-secondary" name="action" value="test" type="submit">接続テスト</button>
      <button class="button-secondary" name="action" value="sync_floor" type="submit">Floor同期</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
