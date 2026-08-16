<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/actress_item_sync.php';

auth_require_admin();

$title = '女優・作品 API設定';
$message = '';
$messageType = 'success';
$cred = api_credential_get('items');
$apiId = (string)($cred['api_id'] ?? '');
$affiliateId = (string)($cred['affiliate_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $action = (string)post('action', 'save');

    try {
        if (in_array($action, ['save', 'sync_actresses', 'sync_actress_items'], true)) {
            $apiId = trim((string)post('api_id', $apiId));
            $affiliateId = trim((string)post('affiliate_id', $affiliateId));
            api_credential_set('items', $apiId, $affiliateId);
        }

        if ($action === 'save') {
            $message = 'APIID / アフィリエイトIDを保存しました。';
        }

        if ($action === 'sync_actresses') {
            $before = (int)db()->query('SELECT COUNT(*) FROM actresses')->fetchColumn();
            $offset = max(1, (int)site_setting_get('actress_sync_test_offset', '1'));
            $processed = dmm_sync_service('actresses')->syncMaster('actress', null, $offset, 10);
            $after = (int)db()->query('SELECT COUNT(*) FROM actresses')->fetchColumn();
            $nextOffset = $offset + max(10, $processed);
            site_setting_set_many(['actress_sync_test_offset' => (string)$nextOffset]);
            $message = '女優情報を取得しました。処理: ' . $processed . '件 / 保存済み女優: ' . $after . '人 / 新規: ' . max(0, $after - $before) . '人';
        }

        if ($action === 'sync_actress_items') {
            $result = pca_sync_items_for_next_saved_actress(10);
            $message = (string)$result['message'] . ' 新規商品: ' . (int)$result['new_count'] . '件 / 保存済み商品: ' . (int)$result['total_items'] . '件';
        }

        if ($action === 'delete_actress') {
            $id = (int)post('row_id', 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM actresses WHERE id = :id')->execute([':id' => $id]);
                $message = '女優を削除しました。';
            }
        }
    } catch (Throwable $e) {
        $message = '処理に失敗しました: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$totalActresses = 0;
$totalItems = 0;
$savedRows = [];
try {
    $totalActresses = (int)db()->query("SELECT COUNT(*) FROM actresses WHERE name <> '' AND dmm_id REGEXP '^[0-9]+$'")->fetchColumn();
    $totalItems = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $savedRows = db()->query("SELECT id, name, dmm_id, updated_at FROM actresses WHERE name <> '' AND dmm_id REGEXP '^[0-9]+$' ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {
}

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1>女優・作品 API設定</h1>
  <p><strong>この1画面だけでAPI設定と取得を行います。</strong></p>
  <p>まず女優情報を保存し、その後「保存済み女優の作品を取得」を実行します。商品APIはDBに保存された女優を基準に検索し、女優個別ページに表示する作品を補助データとして保存します。</p>

  <?php if ($message !== ''): ?>
    <div class="admin-notice <?= $messageType === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>"><p><?= e($message) ?></p></div>
  <?php endif; ?>

  <form method="post" class="stack" style="max-width:760px;">
    <?= csrf_input() ?>
    <div><label>APIID<br><input type="text" name="api_id" value="<?= e($apiId) ?>" style="width:100%"></label></div>
    <div><label>アフィリエイトID<br><input type="text" name="affiliate_id" value="<?= e($affiliateId) ?>" style="width:100%"></label></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="submit" name="action" value="save">保存</button>
      <button type="submit" name="action" value="sync_actresses" class="button-secondary">女優情報を10件取得して保存</button>
      <button type="submit" name="action" value="sync_actress_items" class="button-secondary">保存済み女優の作品を取得</button>
    </div>
  </form>

  <div class="admin-status-grid" style="margin-top:20px;">
    <article class="admin-card admin-status-card"><strong>保存済み女優</strong><p><?= e(number_format($totalActresses)) ?>人</p></article>
    <article class="admin-card admin-status-card"><strong>女優に紐づける作品データ</strong><p><?= e(number_format($totalItems)) ?>件</p></article>
  </div>

  <h2 style="margin-top:24px;">保存済み女優（最新50人）</h2>
  <table class="admin-table">
    <tr><th>No.</th><th>名称</th><th>更新日時</th><th>操作</th></tr>
    <?php foreach ($savedRows as $index => $row): ?>
      <?php $rowId = (int)($row['id'] ?? 0); ?>
      <tr>
        <td><?= e((string)max(1, $totalActresses - (int)$index)) ?></td>
        <td><a href="<?= e(public_url('actress.php?id=' . $rowId)) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($row['name'] ?? '')) ?></a></td>
        <td><?= e((string)($row['updated_at'] ?? '')) ?></td>
        <td>
          <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="delete_actress">
            <input type="hidden" name="row_id" value="<?= e((string)$rowId) ?>">
            <button type="submit" class="button-secondary">削除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
