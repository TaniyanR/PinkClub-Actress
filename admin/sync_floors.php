<?php

declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail(post('_csrf'));
    try {
        $count = dmm_sync_service()->syncFloors();
        flash_set('success', "Floor同期: {$count}件");
    } catch (Throwable $e) {
        flash_set('error', 'Floor同期失敗: ' . $e->getMessage());
    }
    app_redirect('admin/sync_floors.php');
}

$title = 'Floors';
$floors = db()->query('SELECT * FROM dmm_floors ORDER BY service_code,floor_code')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>Floors</h1>
  <form method="post">
    <?= csrf_input() ?>
    <button type="submit">Floor同期を実行</button>
  </form>
</section>

<section class="admin-card">
  <table class="admin-table">
    <tr><th>service</th><th>floor</th><th>name</th></tr>
    <?php foreach ($floors as $f): ?>
      <tr><td><?= e($f['service_code']) ?></td><td><?= e($f['floor_code']) ?></td><td><?= e($f['name']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
