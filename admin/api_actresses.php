<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/actress_sync_cycle.php';

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
        if ($action === 'save') {
            $apiId = trim((string)post('api_id', $apiId));
            $affiliateId = trim((string)post('affiliate_id', $affiliateId));
            if ($apiId === '' || $affiliateId === '') {
                throw new RuntimeException('APIIDとアフィリエイトIDを両方入力してください。');
            }
            api_credential_set('items', $apiId, $affiliateId);
            $message = 'APIID / アフィリエイトIDを保存しました。';
        } elseif ($action === 'run_once') {
            $savedCredential = api_credential_get('items');
            if (trim((string)($savedCredential['api_id'] ?? '')) === ''
                || trim((string)($savedCredential['affiliate_id'] ?? '')) === '') {
                throw new RuntimeException('APIID / アフィリエイトIDを先に保存してください。');
            }

            $result = pca_run_sync_cycle();
            $message = 'cronと同じ取得処理を1回実行しました。' . (string)($result['message'] ?? '');
        } elseif ($action === 'delete_actress') {
            $id = (int)post('row_id', 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM actresses WHERE id=:id')->execute([':id' => $id]);
                $message = '女優を削除しました。この女優だけに紐づいていた作品は公開対象から自動的に外れます。';
            }
        }
    } catch (Throwable $e) {
        $message = '処理に失敗しました: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$totalActresses = 0;
$totalItems = 0;
$totalImages = 0;
$linkedActresses = 0;
$checkedActresses = 0;
$completeActresses = 0;
$savedRows = [];
$lastRunAt = site_setting_get('pca_sync_last_run_at', '未実行');
$lastMessage = site_setting_get('pca_sync_last_message', '');

try {
    $pdo = db();
    pca_product_coverage_ensure_state_table();

    $totalActresses = (int)$pdo->query(
        "SELECT COUNT(*) FROM actresses
         WHERE dmm_id REGEXP '^[0-9]+$' AND TRIM(COALESCE(name,''))<>''"
    )->fetchColumn();

    $totalImages = (int)$pdo->query(
        "SELECT COUNT(*) FROM actresses
         WHERE dmm_id REGEXP '^[0-9]+$'
           AND (COALESCE(image_large,'')<>'' OR COALESCE(image_small,'')<>'' OR COALESCE(image_url,'')<>'')"
    )->fetchColumn();

    $totalItems = (int)$pdo->query(
        "SELECT COUNT(DISTINCT i.id)
         FROM items i
         WHERE i.item_source='fanza_product'
           AND EXISTS (
             SELECT 1
             FROM item_actresses ia
             INNER JOIN actresses a ON a.dmm_id=ia.dmm_id
             WHERE ia.item_id=i.id
               AND a.dmm_id REGEXP '^[0-9]+$'
           )"
    )->fetchColumn();

    $linkedActresses = pca_product_coverage_count_linked_actresses();
    $checkedActresses = (int)$pdo->query('SELECT COUNT(*) FROM actress_product_sync_state WHERE checked_at IS NOT NULL')->fetchColumn();
    $completeActresses = (int)$pdo->query('SELECT COUNT(*) FROM actress_product_sync_state WHERE is_complete=1')->fetchColumn();

    $savedRows = $pdo->query(
        "SELECT id,name,dmm_id,updated_at
         FROM actresses
         WHERE dmm_id REGEXP '^[0-9]+$' AND TRIM(COALESCE(name,''))<>''
         ORDER BY id DESC
         LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('api actresses dashboard count failed: ' . $e->getMessage());
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>女優・作品 API設定</h1>

  <p><strong>PinkClub-Actressでは女優情報APIを女優登録の正データにします。</strong></p>
  <p>商品情報APIは、すでに女優情報APIで登録された女優の出演作品だけを取得・DB保存します。登録済み女優が1人も紐づかない作品は保存・公開対象にしません。</p>
  <p>1サイクルは「女優情報100件 → プロフィール不足10人を補完 → 登録済み女優5人×最大20作品」です。公開ページ表示中に外部APIは呼びません。</p>

  <?php if ($message !== ''): ?>
    <div class="admin-notice <?= $messageType === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>">
      <p><?= e($message) ?></p>
    </div>
  <?php endif; ?>

  <form method="post" class="stack" style="max-width:760px;">
    <?= csrf_input() ?>
    <div><label>APIID<br><input type="text" name="api_id" value="<?= e($apiId) ?>" style="width:100%" autocomplete="off"></label></div>
    <div><label>アフィリエイトID<br><input type="text" name="affiliate_id" value="<?= e($affiliateId) ?>" style="width:100%" autocomplete="off"></label></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="submit" name="action" value="save">保存</button>
      <button type="submit" name="action" value="run_once" class="button-secondary">今すぐ1回実行</button>
    </div>
    <p style="margin:0;color:#667085;">※「今すぐ1回実行」では入力欄を保存しません。API情報を変更した場合は先に「保存」を押してください。</p>
  </form>

  <div class="admin-status-grid" style="margin-top:20px;">
    <article class="admin-card admin-status-card"><strong>登録済み女優</strong><p><?= e(number_format($totalActresses)) ?>人</p></article>
    <article class="admin-card admin-status-card"><strong>画像取得済み女優</strong><p><?= e(number_format($totalImages)) ?>人</p></article>
    <article class="admin-card admin-status-card"><strong>公開対象作品</strong><p><?= e(number_format($totalItems)) ?>件</p></article>
    <article class="admin-card admin-status-card"><strong>作品あり女優</strong><p><?= e(number_format($linkedActresses)) ?>人</p></article>
    <article class="admin-card admin-status-card"><strong>商品API確認済み</strong><p><?= e(number_format($checkedActresses)) ?>人</p></article>
    <article class="admin-card admin-status-card"><strong>最終ページ到達済み</strong><p><?= e(number_format($completeActresses)) ?>人</p></article>
  </div>

  <div class="admin-card" style="margin-top:20px;">
    <strong>最終同期</strong>
    <p><?= e($lastRunAt !== '' ? $lastRunAt : '未実行') ?></p>
    <?php if ($lastMessage !== ''): ?><p><?= e($lastMessage) ?></p><?php endif; ?>
  </div>

  <h2 style="margin-top:24px;">登録済み女優（最新50人）</h2>
  <table class="admin-table">
    <thead><tr><th>No.</th><th>名称</th><th>更新日時</th><th>操作</th></tr></thead>
    <tbody>
    <?php foreach ($savedRows as $index => $row): $rowId = (int)($row['id'] ?? 0); ?>
      <tr>
        <td><?= e((string)max(1, $totalActresses - (int)$index)) ?></td>
        <td><a href="<?= e(public_url('actress.php?id=' . $rowId)) ?>" target="_blank" rel="noopener"><?= e((string)($row['name'] ?? '')) ?></a></td>
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
    </tbody>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
