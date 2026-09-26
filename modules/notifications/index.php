<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

// Mark all read
if ($_GET['action'] ?? '' === 'mark_all_read') {
    $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user['id']]);
    header('Location: index.php'); exit;
}

$page = max(1,(int)($_GET['page']??1));
$pg   = paginate((int)$db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?")->execute([$user['id']])?$db->query("SELECT COUNT(*) FROM notifications WHERE user_id={$user['id']}")->fetchColumn():0, $page);
$cnt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=?"); $cnt->execute([$user['id']]); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);

$rows = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute([$user['id']]); $notifs = $rows->fetchAll();

// Mark shown as read
$db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user['id']]);

renderHead('Notifications');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Notifications','Your system notifications');
renderPageStart('Notifications','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Notifications']
]);
renderPageActions('<a href="?action=mark_all_read" class="btn btn-outline-secondary">Mark All Read</a>');
renderFlashMessages();
?>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">🔔</span> Notifications (<?=number_format($total)?>)</h5></div>
  <div class="card-body p-0">
    <?php if ($notifs): ?>
    <?php foreach ($notifs as $n):
      $typeColors = ['info'=>'badge-info','warning'=>'badge-warning','success'=>'badge-success','danger'=>'badge-danger','approval'=>'badge-primary','reminder'=>'badge-secondary'];
    ?>
    <div style="padding:.9rem 1.2rem;border-bottom:1px solid #f0f0f0;display:flex;gap:1rem;align-items:flex-start;background:<?=$n['is_read']?'#fff':'#f0f4ff'?>;">
      <div style="flex:1;">
        <div class="fw-700 fs-sm"><?=htmlspecialchars($n['title'])?></div>
        <div class="fs-sm text-muted" style="margin-top:.2rem;"><?=htmlspecialchars($n['message'])?></div>
        <div class="fs-xs text-muted" style="margin-top:.3rem;"><?=formatDateTime($n['created_at'])?></div>
      </div>
      <span class="badge <?=$typeColors[$n['type']]??'badge-secondary'?>"><?=ucfirst($n['type'])?></span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">🔔</div><h5>No notifications</h5><p>You are all caught up.</p></div>
    <?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?')?></div><?php endif; ?>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
