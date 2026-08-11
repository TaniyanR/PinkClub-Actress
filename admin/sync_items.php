<?php

declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
$itemSettings = settings_get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail(post('_csrf'));
    try {
        $count = dmm_sync_service()->syncItems((string) post('site_code', (string)$itemSettings['site']), (string) post('service_code', (string)$itemSettings['service']), (string) post('floor_code', (string)$itemSettings['floor']));
        flash_set('success', "商品同期: {$count}件");
    } catch (Throwable $e) {
        flash_set('error', '商品同期失敗: ' . $e->getMessage());
    }
    app_redirect('admin/sync_items.php');
}

$title = 'Items';
$logs = db()->query("SELECT * FROM sync_logs WHERE sync_type IN ('item','items') ORDER BY id DESC LIMIT 30")->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>Items</h1>
  <form method="post">
    <?= csrf_input() ?>
    <label>service
      <input name="service_code" value="<?= e((string)$itemSettings['service']) ?>">
    </label>
    <label>floor
      <input name="floor_code" value="<?= e((string)$itemSettings['floor']) ?>">
    </label>
    <button type="submit">同期</button>
  </form>
</section>

<section class="admin-card">
  <h2>同期履歴</h2>
  <table class="admin-table">
    <tr><th>時刻</th><th>結果</th><th>件数</th><th>メッセージ</th></tr>
    <?php foreach ($logs as $l): ?>
      <tr><td><?= e($l['created_at']) ?></td><td><?= $l['is_success'] ? 'OK' : 'NG' ?></td><td><?= e($l['synced_count']) ?></td><td><?= e($l['message']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
