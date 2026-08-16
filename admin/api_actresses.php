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
                throw new RuntimeException('APIIDとアフィリエイトIDを両方入力してください。空欄では保存しません。');
            }
            api_credential_set('items', $apiId, $affiliateId);
            $message = 'APIID / アフィリエイトIDを保存しました。';
        }
        if ($action === 'run_once') {
            // 実行ボタンでは入力欄の値を保存しない。
            // ブラウザの自動入力や空欄送信で保存済みAPI情報を消さないため。
            $savedCred = api_credential_get('items');
            $savedApiId = trim((string)($savedCred['api_id'] ?? ''));
            $savedAffiliateId = trim((string)($savedCred['affiliate_id'] ?? ''));
            if ($savedApiId === '' || $savedAffiliateId === '') {
                throw new RuntimeException('API設定が保存されていません。先にAPIIDとアフィリエイトIDを入力して「保存」を押してください。');
            }
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

$totalActresses=$totalItems=$totalImages=$linkedActresses=$amateurActresses=$checkedActresses=0;
$savedRows=[];
$recentProductChecks=[];
$lastRunAt=site_setting_get('pca_sync_last_run_at','未実行');
$lastMessage=site_setting_get('pca_sync_last_message','');
try {
    $pdo=db();
    $totalActresses=(int)$pdo->query("SELECT COUNT(*) FROM actresses WHERE TRIM(COALESCE(name,''))<>''")->fetchColumn();
    $totalItems=(int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $totalImages=(int)$pdo->query("SELECT COUNT(*) FROM actresses WHERE COALESCE(image_large,'')<>'' OR COALESCE(image_small,'')<>'' OR COALESCE(image_url,'')<>''")->fetchColumn();
    $linkedActresses=pca_product_coverage_count_linked_actresses();
    $amateurActresses=(int)$pdo->query("SELECT COUNT(DISTINCT ia.actress_name) FROM item_actresses ia INNER JOIN items i ON i.id=ia.item_id WHERE TRIM(COALESCE(ia.actress_name,''))<>'' AND (i.floor_code='videoc' OR i.floor_name LIKE '%素人%' OR i.floor_name LIKE '%しろうと%' OR i.floor_name LIKE '%シロウト%')")->fetchColumn();
    pca_product_coverage_ensure_state_table();
    $checkedActresses=(int)$pdo->query('SELECT COUNT(*) FROM actress_product_sync_state')->fetchColumn();
    $savedRows=$pdo->query("SELECT id,name,dmm_id,updated_at FROM actresses WHERE TRIM(COALESCE(name,''))<>'' ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $recentProductChecks=$pdo->query("SELECT s.actress_id,a.name,a.dmm_id,s.last_api_count,s.last_item_count,s.last_error,s.checked_at FROM actress_product_sync_state s INNER JOIN actresses a ON a.id=s.actress_id ORDER BY s.checked_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

require __DIR__ . '/includes/header.php';
?>
<section class="card">
<h1>女優・作品 API設定</h1>
<p><strong>自動取得と手動取得は同じ処理です。</strong></p>
<p>1サイクルで「女優情報100件 → 女優画像10人補完 → 既存作品100件の出演者関係を修復 → 通常女優10人×最大10作品（最大100作品）を直接取得 → しろうと作品100件」の順に実行します。</p>
<p><strong>通常商品の外部API通信は最大10回に制限しました。</strong> 以前の「100女優を1人ずつ100回通信」は共有サーバーの実行時間を超えるため廃止しています。取得した作品は、検索に使ったDMM女優IDへ必ず紐付けて商品カードへ渡します。</p>
<?php if($message!==''): ?><div class="admin-notice <?= $messageType==='success'?'admin-notice--success':'admin-notice--error' ?>"><p><?= e($message) ?></p></div><?php endif; ?>
<form method="post" class="stack" style="max-width:760px;">
<?= csrf_input() ?>
<div><label>APIID<br><input type="text" name="api_id" value="<?= e($apiId) ?>" style="width:100%" autocomplete="off"></label></div>
<div><label>アフィリエイトID<br><input type="text" name="affiliate_id" value="<?= e($affiliateId) ?>" style="width:100%" autocomplete="off"></label></div>
<div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="submit" name="action" value="save">保存</button><button type="submit" name="action" value="run_once" class="button-secondary">今すぐ1回実行</button></div>
<p style="font-size:13px;color:#566;">※「今すぐ1回実行」では入力欄を保存しません。API情報の変更は必ず「保存」を押してください。</p>
</form>
<div class="admin-status-grid" style="margin-top:20px;">
<article class="admin-card admin-status-card"><strong>保存済み女優</strong><p><?= e(number_format($totalActresses)) ?>人</p></article>
<article class="admin-card admin-status-card"><strong>画像取得済み女優</strong><p><?= e(number_format($totalImages)) ?>人</p></article>
<article class="admin-card admin-status-card"><strong>保存済み作品</strong><p><?= e(number_format($totalItems)) ?>件</p></article>
<article class="admin-card admin-status-card"><strong>商品カード対象女優</strong><p><?= e(number_format($linkedActresses)) ?>人</p></article>
<article class="admin-card admin-status-card"><strong>商品API確認済み女優</strong><p><?= e(number_format($checkedActresses)) ?>人</p></article>
<article class="admin-card admin-status-card"><strong>しろうと女性</strong><p><?= e(number_format($amateurActresses)) ?>人</p></article>
</div>
<div class="admin-card" style="margin-top:20px;"><strong>最終同期</strong><p><?= e($lastRunAt!==''?$lastRunAt:'未実行') ?></p><?php if($lastMessage!==''): ?><p><?= e($lastMessage) ?></p><?php endif; ?></div>

<h2 style="margin-top:24px;">直近の商品API確認（診断用）</h2>
<table class="admin-table">
<tr><th>女優</th><th>API返却</th><th>紐付作品</th><th>確認日時</th><th>状態</th></tr>
<?php if ($recentProductChecks === []): ?>
<tr><td colspan="5">まだ商品APIを個別確認していません。</td></tr>
<?php else: foreach($recentProductChecks as $check): ?>
<tr>
<td><a href="<?= e(public_url('actress.php?id='.(int)($check['actress_id']??0))) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($check['name']??'')) ?></a></td>
<td><?= e(number_format((int)($check['last_api_count']??0))) ?>件</td>
<td><?= e(number_format((int)($check['last_item_count']??0))) ?>件</td>
<td><?= e((string)($check['checked_at']??'')) ?></td>
<td><?= trim((string)($check['last_error']??''))!=='' ? e((string)$check['last_error']) : '正常' ?></td>
</tr>
<?php endforeach; endif; ?>
</table>

<h2 style="margin-top:24px;">保存済み女優（最新50人）</h2>
<table class="admin-table"><tr><th>No.</th><th>名称</th><th>更新日時</th><th>操作</th></tr>
<?php foreach($savedRows as $index=>$row): $rowId=(int)($row['id']??0); ?>
<tr><td><?= e((string)max(1,$totalActresses-(int)$index)) ?></td><td><a href="<?= e(public_url('actress.php?id='.$rowId)) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($row['name']??'')) ?></a></td><td><?= e((string)($row['updated_at']??'')) ?></td><td><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="delete_actress"><input type="hidden" name="row_id" value="<?= e((string)$rowId) ?>"><button type="submit" class="button-secondary">削除</button></form></td></tr>
<?php endforeach; ?>
</table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
