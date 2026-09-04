<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Notifications';
$active_page = 'notifications.php';
$pdo         = getDB();

// Mark all read
if (isset($_GET['mark_all'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user['id']]);
    header('Location: notifications.php'); exit;
}
if (isset($_GET['mark'])) {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$_GET['mark'], $user['id']]);
}

// Broadcast (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['broadcast'])) {
    $title   = trim($_POST['bcast_title'] ?? '');
    $message = trim($_POST['bcast_message'] ?? '');
    $role_f  = $_POST['bcast_role'] ?? '';
    $type_f  = $_POST['bcast_type'] ?? 'info';

    if ($title && $message) {
        $targets = $role_f
            ? $pdo->prepare("SELECT id FROM users WHERE role=?")->execute([$role_f]) && $pdo->prepare("SELECT id FROM users WHERE role=?")->execute([$role_f])
            : [];

        if ($role_f) {
            $tgt = $pdo->prepare("SELECT id FROM users WHERE role=?");
            $tgt->execute([$role_f]);
        } else {
            $tgt = $pdo->query("SELECT id FROM users WHERE role!='superadmin'");
        }
        foreach ($tgt->fetchAll() as $t) {
            addNotification($t['id'], $title, $message, $type_f);
        }
        logAudit('BROADCAST', "Sent broadcast: $title to ".($role_f ?: 'all'));
        $success_msg = 'Broadcast sent!';
    }
}

$notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$notifs->execute([$user['id']]);
$notifications = $notifs->fetchAll();
$unread_count  = array_sum(array_column($notifications, 'is_read') === array_fill(0, count($notifications), 0) ? [] : array_map(fn($n) => !$n['is_read'] ? 1 : 0, $notifications));

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Notifications</h1>
        <p class="page-subtitle"><?= count($notifications) ?> total &bull; <?= array_sum(array_map(fn($n)=>!$n['is_read']?1:0,$notifications)) ?> unread</p>
    </div>
    <div style="display:flex;gap:10px">
        <a href="?mark_all=1" class="btn btn-ghost btn-sm"><i class="ri-check-double-line"></i> Mark All Read</a>
        <button class="btn btn-primary btn-sm" data-modal-open="broadcastModal"><i class="ri-broadcast-line"></i> Broadcast</button>
    </div>
</div>

<?php if (!empty($success_msg)): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success_msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px" class="fade-in">
    <!-- Notification List -->
    <div class="card">
        <div class="card-body" style="padding:0">
            <?php if (empty($notifications)): ?>
            <div class="empty-state"><i class="ri-notification-off-line"></i><h3>No notifications</h3><p>You're all caught up!</p></div>
            <?php endif; ?>
            <?php
            $iconMap = ['success'=>'ri-checkbox-circle-fill','danger'=>'ri-error-warning-fill','warning'=>'ri-alert-fill','info'=>'ri-information-fill'];
            $bgMap   = ['success'=>'badge-success','danger'=>'badge-danger','warning'=>'badge-warning','info'=>'badge-info'];
            foreach ($notifications as $n):
            ?>
            <a href="?mark=<?= $n['id'] ?>" style="display:flex;gap:14px;padding:16px 20px;border-bottom:1px solid #f5f8fd;transition:background .15s;<?= !$n['is_read']?'background:rgba(29,59,139,.03)':'' ?>">
                <div class="notif-item-icon badge <?= $bgMap[$n['type']] ?>" style="width:40px;height:40px;border-radius:10px;flex-shrink:0">
                    <i class="<?= $iconMap[$n['type']] ?>" style="font-size:18px"></i>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
                        <div style="font-size:14px;font-weight:<?= !$n['is_read']?'700':'500' ?>;color:var(--neutral-dark)"><?= sanitize($n['title']) ?></div>
                        <?php if (!$n['is_read']): ?><span style="width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:6px"></span><?php endif; ?>
                    </div>
                    <div style="font-size:13px;color:var(--neutral);margin-top:3px"><?= sanitize($n['message']) ?></div>
                    <div style="font-size:11px;color:var(--neutral-light);margin-top:6px"><?= timeAgo($n['created_at']) ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Broadcast Panel -->
    <div>
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="ri-broadcast-line" style="color:var(--primary)"></i> Quick Broadcast</span></div>
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Title</label>
                        <input type="text" name="bcast_title" class="form-control" placeholder="Notification title" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea name="bcast_message" class="form-control" rows="3" placeholder="Write your message..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Send to</label>
                        <select name="bcast_role" class="form-control">
                            <option value="">All Users</option>
                            <option value="buyer">Buyers only</option>
                            <option value="seller">Sellers only</option>
                            <option value="delivery">Delivery only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="bcast_type" class="form-control">
                            <option value="info">Info</option>
                            <option value="success">Success</option>
                            <option value="warning">Warning</option>
                            <option value="danger">Urgent</option>
                        </select>
                    </div>
                    <button type="submit" name="broadcast" value="1" class="btn btn-primary" style="width:100%"><i class="ri-send-plane-line"></i> Send Broadcast</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
