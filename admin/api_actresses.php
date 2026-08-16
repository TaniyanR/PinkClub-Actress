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
        if (in_array($action, ['save','run_once'], true)) {
            $apiId = trim((string)post('api_id', $apiId));
            $affiliateId = trim((string)post('affiliate_id', $affiliateId));
            api_credential_set('items', $apiId, $affiliateId);
        }
        if ($action === 'save') $message = 'APIID / アフィリエイトIDを保存しました。';
        if ($action === 'run_once') {
            $result = pca_run_sync_cycle();
            $message = 'cronと同じ取得処理を1回実行しました。' . (string)($result['message'] ?? '');
        }
        if ($action === 'delete_actress') {
            $id = (int)post('row_id', 0);
            if ($id > 0) {
                db()->prepare('DELETE FROM actresses WHERE id=:id')->execute([':id'=>$id]);
                $message = '女優を削除しました。';
            }
        }
    } catch (Throwable $e) {
        $message = '処理に失敗しました: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$totalActresses=$totalItems=$totalImages=$linkedActresses=$unlinkedActresses=$amateurActresses=0;
$savedRows=[];
$lastRunAt=site_setting_get('pca_sync_last_run_at','未実行');
$lastMessage=site_setting_get('pca_sync_last_message','');
try {
    $pdo=db();
    $totalActresses=(int)$pdo->query("SELECT COUNT(*) FROM actresses WHERE TRIM(COALESCE(name,''))<>''")->fetchColumn();
    $totalItems=(int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $totalImages=(int)$pdo->query("SELECT COUNT(*) FROM actresses WHERE COALESCE(image_large,'')<>'' OR COALESCE(image_small,'')<>'' OR COALESCE(image_url,'')<>''")->fetchColumn();
    $linkedActresses=(int)$pdo->query("SELECT COUNT(DISTINCT a.id) FROM actresses a WHERE EXISTS(SELECT 1 FROM item_actresses ia WHERE ia.dmm_id=a.dmm_id OR ia.actress_name=a.name)")->fetchColumn();
    $unlinkedActresses=max(0,$totalActresses-$linkedActresses);
    $amateurActresses=(int)$pdo->query("SELECT COUNT(DISTINCT ia.actress_name) FROM item_actresses ia INNER JOIN items i ON i.id=ia.item_id WHERE TRIM(COALESCE(ia.actress_name,''))<>'' AND (i.floor_code='videoc' OR i.floor_name LIKE '%素人%' OR i.floor_name LIKE '%しろうと%' OR i.floor_name LIKE '%シロウト%')")->fetchColumn();
    $savedRows=$pdo->query("SELECT id,name,dmm_id,updated_at FROM actresses WHERE TRIM(COALESCE(name,''))<>'' ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

require __DIR__ . '/includes/header.php';
?>
<section class="card">
<h1>女優・作品 API設定</h1>
<p><strong>自動取得と手動取得は同じ処理です。</strong></p>
<p>1サイクルで「女優情報100件 → 女優画像100人分補完 → 作品未取得の女優を優先して100人分取得 → しろうとフロア100作品取得」の順に実行します。</p>
<?php if($message!==''): ?><div class="admin-notice <?= $messageType==='success'?'admin-notice--success':'admin-notice--error' ?>"><p><?= e($message) ?></p></div><?php endif; ?>
<form method="post" class="stack" style="max-width:760px;">
<?= csrf_input() ?>
<div><label>APIID<br><input type="text" name="api_id" value="<?= e($apiId) ?>" style="width:100%"></label></div>
<div><label>アフィリエイトID<br><input type="text" name="affiliate_id" value="<?= e($affiliateId) ?>" style="width:100%"></label></div>
<div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="submit" name="action" value="save">保存</button><button type="submit" name="action" value="run_once" class="button-secondary">今すぐ1回実行</button></div>
</form>
<div class="admin-status-grid" style="margin-top:20px;">
<article class="admin-card admin-status-card"><strong>保存済み女優</strong><p><?= e(number_format($totalActresses)) ?>人</p></article>
<article class="admin-card admin-status-card"><strong>画像取得済み女優</strong><p><?= e(number_format($totalImages)) ?>人</p></article>
<article class="admin-card admin-status-card"><strong>保存済み作品</strong><p><?= e(number_format($totalItems)) ?>件</p></article>
<article class="admin-card admin-status-card"><strong>作品紐付け済み女優</strong><p><?= e(number_format($linkedActresses)) ?>人</p></article>
<article class="admin-card admin-status-card"><strong>作品未取得女優</strong><p><?= e(number_format($unlinkedActresses)) ?>人</p></article>
<article class="admin-card admin-status-card"><strong>しろうと女性</strong><p><?= e(number_format($amateurActresses)) ?>人</p></article>
</div>
<div class="admin-card" style="margin-top:20px;"><strong>最終同期</strong><p><?= e($lastRunAt!==''?$lastRunAt:'未実行') ?></p><?php if($lastMessage!==''): ?><p><?= e($lastMessage) ?></p><?php endif; ?></div>
<h2 style="margin-top:24px;">保存済み女優（最新50人）</h2>
<table class="admin-table"><tr><th>No.</th><th>名称</th><th>更新日時</th><th>操作</th></tr>
<?php foreach($savedRows as $index=>$row): $rowId=(int)($row['id']??0); ?>
<tr><td><?= e((string)max(1,$totalActresses-(int)$index)) ?></td><td><a href="<?= e(public_url('actress.php?id='.$rowId)) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($row['name']??'')) ?></a></td><td><?= e((string)($row['updated_at']??'')) ?></td><td><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="delete_actress"><input type="hidden" name="row_id" value="<?= e((string)$rowId) ?>"><button type="submit" class="button-secondary">削除</button></form></td></tr>
<?php endforeach; ?>
</table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
