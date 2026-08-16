<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/app.php';

/** @var string $pageTitle */
/** @var string $testButtonLabel */
/** @var string $apiType */

if (!isset($pageTitle)) {
    throw new RuntimeException('api settings page title is not initialized.');
}

$apiType = isset($apiType) ? (string)$apiType : 'items';
if (!in_array($apiType, ['items', 'actresses'], true)) {
    throw new RuntimeException('unsupported api settings type.');
}

auth_require_admin();

$title = $pageTitle;
$testButtonLabel = (string)($testButtonLabel ?? ($apiType === 'actresses' ? '女優情報を10件テスト取得して保存' : '補助商品を10件テスト取得して保存'));
$message = '';
$messageType = 'success';
$cred = api_credential_get($apiType);
$apiId = (string)($cred['api_id'] ?? '');
$affiliateId = (string)($cred['affiliate_id'] ?? '');
$savedRows = [];
$currentPage = 1;
$perPage = 50;
$totalRows = 0;
$totalPages = 1;

$saveTargets = [
    'actresses' => ['table' => 'actresses', 'label' => '女優', 'id_column' => 'id', 'name_column' => 'name'],
    'items' => ['table' => 'items', 'label' => '補助商品', 'id_column' => 'id', 'name_column' => 'title'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $action = (string)post('action', 'save');

    if ($action === 'save') {
        $apiId = trim((string)post('api_id', ''));
        $affiliateId = trim((string)post('affiliate_id', ''));
        api_credential_set($apiType, $apiId, $affiliateId);
        $message = 'APIID / アフィリエイトIDを保存しました。女優API・補助商品APIで共通利用されます。';
    }

    if ($action === 'test_save') {
        try {
            $apiId = trim((string)post('api_id', $apiId));
            $affiliateId = trim((string)post('affiliate_id', $affiliateId));
            api_credential_set($apiType, $apiId, $affiliateId);
            $sync = dmm_sync_service($apiType);

            if ($apiType === 'actresses') {
                $beforeCount = (int)db()->query('SELECT COUNT(*) FROM actresses')->fetchColumn();
                $testOffset = max(1, (int)site_setting_get('actress_sync_test_offset', '1'));
                $processed = $sync->syncMaster('actress', null, $testOffset, 10);
                $afterCount = (int)db()->query('SELECT COUNT(*) FROM actresses')->fetchColumn();
                $nextOffset = $testOffset + max(10, $processed);
                site_setting_set_many(['actress_sync_test_offset' => (string)$nextOffset]);
                $inserted = max(0, $afterCount - $beforeCount);
                $updated = max(0, $processed - $inserted);

                $message = '女優情報を10件テスト取得して保存しました。処理件数: ' . $processed
                    . ' / 保存済み女優: ' . $afterCount
                    . ' / 新規追加: ' . $inserted
                    . ' / 更新: ' . $updated
                    . ' / 次回offset: ' . $nextOffset;
            } else {
                $s = settings_get();
                $targets = $s['catalog_targets'] ?? [];
                if (!is_array($targets) || $targets === []) {
                    $targets = [[
                        'site' => (string)$s['site'],
                        'service' => (string)$s['service'],
                        'floor' => (string)$s['floor'],
                        'label' => '補助商品',
                    ]];
                }

                $targetIndex = max(0, (int)site_setting_get('item_sync_test_target_index', '0')) % count($targets);
                $targetConfig = is_array($targets[$targetIndex] ?? null) ? $targets[$targetIndex] : $targets[0];
                $targetKey = settings_catalog_target_key($targetConfig);
                $offsetKey = 'item_sync_test_offset.' . preg_replace('/[^a-z0-9_.-]+/i', '_', $targetKey);
                $testOffset = max(1, (int)site_setting_get($offsetKey, '1'));
                $beforeCount = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
                $result = $sync->syncItemsBatch(
                    (string)($targetConfig['site'] ?? 'FANZA'),
                    (string)($targetConfig['service'] ?? 'digital'),
                    (string)($targetConfig['floor'] ?? 'videoa'),
                    10,
                    $testOffset,
                    ['sort' => 'rank']
                );
                $processed = (int)($result['synced_count'] ?? 0);
                $nextOffset = max(1, (int)($result['next_offset'] ?? 1));
                $afterCount = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
                $inserted = max(0, $afterCount - $beforeCount);
                $updated = max(0, $processed - $inserted);
                $nextTargetIndex = ($targetIndex + 1) % count($targets);
                site_setting_set_many([
                    $offsetKey => (string)$nextOffset,
                    'item_sync_test_target_index' => (string)$nextTargetIndex,
                ]);

                $label = trim((string)($targetConfig['label'] ?? '補助商品')) ?: '補助商品';
                $message = $label . 'を10件テスト取得して保存しました。処理件数: ' . $processed
                    . ' / 保存済み商品: ' . $afterCount
                    . ' / 新規追加: ' . $inserted
                    . ' / 更新: ' . $updated
                    . ' / 次回offset: ' . $nextOffset;
            }
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = '保存に失敗しました: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($action === 'delete_row') {
        $target = $saveTargets[$apiType] ?? null;
        if (is_array($target)) {
            $id = (int)post('row_id', 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM ' . $target['table'] . ' WHERE ' . $target['id_column'] . ' = :id')->execute([':id' => $id]);
                $message = $target['label'] . 'を削除しました。';
                $messageType = 'success';
            }
        }
    }
}

$target = $saveTargets[$apiType] ?? null;
if (is_array($target)) {
    $currentPage = max(1, (int)($_GET['page'] ?? 1));
    $countStmt = db()->query('SELECT COUNT(*) AS c FROM ' . $target['table']);
    $totalRows = (int)(($countStmt ? $countStmt->fetch(PDO::FETCH_ASSOC) : ['c' => 0])['c'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;

    $selectExtra = $apiType === 'items' ? ', content_id' : ', dmm_id';
    $sql = 'SELECT ' . $target['id_column'] . ' AS row_id, ' . $target['name_column'] . ' AS row_name, updated_at' . $selectExtra
        . ' FROM ' . $target['table']
        . ' ORDER BY ' . $target['id_column'] . ' DESC'
        . ' LIMIT :limit OFFSET :offset';
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $savedRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1><?= e($pageTitle) ?></h1>
  <?php if ($apiType === 'actresses'): ?>
    <p><strong>PinkClub-Actress のメインAPIです。</strong> 女優プロフィール・女優写真を取得します。商品APIは出演作品を表示するための補助データとして利用します。</p>
  <?php else: ?>
    <p><strong>この商品APIは補助用です。</strong> 女優個別ページに出演作品とFANZA購入リンクを表示するために利用します。サイトの主データは女優APIです。</p>
  <?php endif; ?>
  <p>APIID / アフィリエイトIDは女優API・商品APIで共通です。</p>

  <?php if ($message !== ''): ?>
    <div class="admin-notice <?= $messageType === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>"><p><?= e($message) ?></p></div>
  <?php endif; ?>

  <form method="post" class="stack" style="max-width:700px;">
    <?= csrf_input() ?>
    <div><label>APIID<br><input type="text" name="api_id" value="<?= e($apiId) ?>" style="width:100%"></label></div>
    <div><label>アフィリエイトID<br><input type="text" name="affiliate_id" value="<?= e($affiliateId) ?>" style="width:100%"></label></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <button type="submit" name="action" value="save">保存</button>
      <button type="submit" name="action" value="test_save" class="button-secondary"><?= e($testButtonLabel) ?></button>
    </div>
  </form>

  <?php if (is_array($target)): ?>
    <h2>保存済み<?= e($target['label']) ?>（全<?= e((string)$totalRows) ?>件 / 最新50件）</h2>
    <table class="admin-table">
      <tr><th>No.</th><th>名称</th><th>更新日時</th><th>操作</th></tr>
      <?php foreach ($savedRows as $index => $row): ?>
        <?php
        $rowId = (int)($row['row_id'] ?? 0);
        $rowUrl = $apiType === 'actresses'
            ? public_url('actress.php?id=' . $rowId)
            : public_url('item.php?cid=' . rawurlencode((string)($row['content_id'] ?? '')));
        ?>
        <tr>
          <td><?= e((string)max(1, $totalRows - $offset - (int)$index)) ?></td>
          <td><a href="<?= e($rowUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($row['row_name'] ?? '')) ?></a></td>
          <td><?= e((string)($row['updated_at'] ?? '')) ?></td>
          <td>
            <form method="post">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="delete_row">
              <input type="hidden" name="row_id" value="<?= e((string)$rowId) ?>">
              <button type="submit" class="button-secondary">削除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($totalPages > 1): ?>
      <nav style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php if ($currentPage > 1): ?><a href="<?= e(admin_url(basename((string)$_SERVER['PHP_SELF']) . '?page=' . (string)($currentPage - 1))) ?>">&lt;&lt;前へ</a><?php endif; ?>
        <strong><?= e((string)$currentPage) ?></strong><span>/ <?= e((string)$totalPages) ?></span>
        <?php if ($currentPage < $totalPages): ?><a href="<?= e(admin_url(basename((string)$_SERVER['PHP_SELF']) . '?page=' . (string)($currentPage + 1))) ?>">次へ&gt;&gt;</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
