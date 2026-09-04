<?php
// Generic notifications page — shared across all roles
// Caller sets: $user (array), $page_title, $active_page
$pdo = getDB();
$uid = $user['id'];

if (isset($_GET['mark_all'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
    header('Location: notifications.php'); exit;
}
if (!empty($_GET['mark'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$_GET['mark'],$uid]);
}
if (!empty($_GET['delete'])) {
    $pdo->prepare("DELETE FROM notifications WHERE id=? AND user_id=?")->execute([(int)$_GET['delete'],$uid]);
    header('Location: notifications.php'); exit;
}

$filter  = $_GET['filter'] ?? '';
$where   = "user_id=?";
$params  = [$uid];
if ($filter) { $where .= " AND type=?"; $params[] = $filter; }

$notifs = $pdo->prepare("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT 60");
$notifs->execute($params);
$notifications = $notifs->fetchAll();
$unread_count  = array_sum(array_map(fn($n)=>!$n['is_read']?1:0,$notifications));

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';

$iconMap = ['success'=>'ri-checkbox-circle-fill','danger'=>'ri-error-warning-fill','warning'=>'ri-alert-fill','info'=>'ri-information-fill'];
$bgMap   = ['success'=>'badge-success','danger'=>'badge-danger','warning'=>'badge-warning','info'=>'badge-info'];
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Notifications</h1>
        <p class="page-subtitle"><?= count($notifications) ?> total &bull; <strong style="color:var(--primary)"><?= $unread_count ?></strong> unread</p>
    </div>
    <a href="?mark_all=1" class="btn btn-ghost btn-sm"><i class="ri-check-double-line"></i> Mark All Read</a>
</div>

<!-- Filter tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap" class="fade-in">
    <?php foreach(['' => 'All', 'info'=>'Info','success'=>'Success','warning'=>'Warning','danger'=>'Urgent'] as $v => $l): ?>
    <a href="?filter=<?= $v ?>" class="btn <?= $filter===$v?'btn-primary':'btn-ghost' ?> btn-sm"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<div class="card fade-in">
    <div class="card-body" style="padding:0">
        <?php if (empty($notifications)): ?>
        <div class="empty-state" style="padding:60px">
            <i class="ri-notification-off-line" style="font-size:56px"></i>
            <h3>No notifications</h3>
            <p>You're all caught up! Check back later.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($notifications as $n): ?>
        <div style="display:flex;gap:14px;padding:16px 20px;border-bottom:1px solid #f5f8fd;background:<?= !$n['is_read']?'rgba(29,59,139,.03)':'' ?>;transition:background .15s">
            <div class="notif-item-icon badge <?= $bgMap[$n['type']] ?>" style="width:44px;height:44px;border-radius:12px;flex-shrink:0;font-size:20px">
                <i class="<?= $iconMap[$n['type']] ?>"></i>
            </div>
            <div style="flex:1;min-width:0">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                    <div style="font-size:14px;font-weight:<?= !$n['is_read']?'700':'500' ?>;color:var(--neutral-dark)"><?= sanitize($n['title']) ?></div>
                    <?php if (!$n['is_read']): ?><span style="width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:6px;display:block"></span><?php endif; ?>
                </div>
                <div style="font-size:13px;color:var(--neutral);margin-top:4px"><?= sanitize($n['message']) ?></div>
                <div style="display:flex;align-items:center;gap:12px;margin-top:8px">
                    <span style="font-size:11px;color:var(--neutral-light)"><i class="ri-time-line"></i> <?= timeAgo($n['created_at']) ?></span>
                    <?php if (!$n['is_read']): ?><a href="?mark=<?= $n['id'] ?>" style="font-size:11px;color:var(--primary)">Mark read</a><?php endif; ?>
                    <a href="?delete=<?= $n['id'] ?>" style="font-size:11px;color:var(--danger)" onclick="return confirm('Delete this notification?')">Delete</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
