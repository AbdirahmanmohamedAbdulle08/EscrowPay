<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user = requireLogin(['superadmin']);
$page_title = 'AI Scam Shield Center';
$active_page = 'scam_shield.php';
$pdo = getDB();
$pdo->exec("CREATE TABLE IF NOT EXISTS chat_moderation_events (id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, receiver_id INT NOT NULL, action_taken ENUM('allow','review','block') NOT NULL, risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0, reason TEXT DEFAULT NULL, engine VARCHAR(30) NOT NULL DEFAULT 'gemini', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX chat_moderation_sender_idx (sender_id), INDEX chat_moderation_created_idx (created_at)) ENGINE=InnoDB");

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_user_status') {
    $targetId = (int)($_POST['user_id'] ?? 0);
    $newStatus = ($_POST['status'] ?? '') === 'suspended' ? 'suspended' : 'active';
    $stmt = $pdo->prepare("UPDATE users SET status=? WHERE id=? AND role!='superadmin'");
    $stmt->execute([$newStatus, $targetId]);
    if ($stmt->rowCount()) {
        logAudit('SCAM_SHIELD_USER_' . strtoupper($newStatus), "Scam Shield Center set user #{$targetId} to {$newStatus}", (int)$user['id']);
        $success = $newStatus === 'suspended' ? 'Account-ka waxaa loo xannibay baaritaan scam awgiis.' : 'Account-ka waa la hawlgeliyey.';
    } else $error = 'User lama helin ama lama beddeli karo.';
}

$stats = $pdo->query("SELECT COUNT(*) AS total, SUM(action_taken='block') AS blocked, SUM(action_taken='review') AS review, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS today FROM chat_moderation_events")->fetch();
$events = $pdo->query("SELECT e.*, s.name AS sender_name, s.email AS sender_email, s.status AS sender_status, r.name AS receiver_name FROM chat_moderation_events e JOIN users s ON s.id=e.sender_id JOIN users r ON r.id=e.receiver_id WHERE e.action_taken IN ('block','review') ORDER BY e.created_at DESC LIMIT 50")->fetchAll();
$offenders = $pdo->query("SELECT s.id, s.name, s.email, s.role, s.status, COUNT(e.id) AS flags, MAX(e.risk_score) AS max_risk, MAX(e.created_at) AS last_flag FROM chat_moderation_events e JOIN users s ON s.id=e.sender_id WHERE e.action_taken IN ('block','review') GROUP BY s.id, s.name, s.email, s.role, s.status HAVING flags > 0 ORDER BY flags DESC, max_risk DESC, last_flag DESC LIMIT 12")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="page-header fade-in">
    <div class="page-header-left"><h1 class="page-title"><i class="ri-shield-keyhole-line" style="color:var(--danger)"></i> AI Scam Shield Center</h1><p class="page-subtitle">Ogow, baar, oo xannib isku-dayada khiyaanada ee chat-ka.</p></div>
    <a href="messaging.php?mode=monitor" class="btn btn-primary"><i class="ri-eye-line"></i> Audit Chats</a>
</div>
<?php if ($success): ?><div class="alert alert-success fade-in"><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger fade-in"><?= sanitize($error) ?></div><?php endif; ?>

<div class="stats-grid fade-in stagger" style="margin-bottom:22px">
    <div class="stat-card"><div class="stat-info"><div class="stat-label">Total Alerts</div><div class="stat-value"><?= (int)($stats['total'] ?? 0) ?></div></div><div class="stat-icon-wrap stat-icon-danger"><i class="ri-alarm-warning-line"></i></div></div>
    <div class="stat-card"><div class="stat-info"><div class="stat-label">Blocked</div><div class="stat-value" style="color:var(--danger)"><?= (int)($stats['blocked'] ?? 0) ?></div></div><div class="stat-icon-wrap stat-icon-danger"><i class="ri-forbid-2-line"></i></div></div>
    <div class="stat-card"><div class="stat-info"><div class="stat-label">Needs Review</div><div class="stat-value" style="color:#d28a00"><?= (int)($stats['review'] ?? 0) ?></div></div><div class="stat-icon-wrap stat-icon-warning"><i class="ri-eye-line"></i></div></div>
    <div class="stat-card"><div class="stat-info"><div class="stat-label">Last 24 Hours</div><div class="stat-value"><?= (int)($stats['today'] ?? 0) ?></div></div><div class="stat-icon-wrap stat-icon-primary"><i class="ri-time-line"></i></div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:20px" class="fade-in">
 <div class="card"><div class="card-header"><span class="card-title"><i class="ri-user-unfollow-line" style="color:var(--danger)"></i> Repeat Risk Users</span></div><div class="card-body" style="padding:0">
 <?php if (!$offenders): ?><div class="empty-state" style="padding:28px"><i class="ri-shield-check-line"></i><p>No scam alerts yet.</p></div><?php endif; ?>
 <?php foreach ($offenders as $person): ?><div style="padding:14px 16px;border-bottom:1px solid #eef2f7"><div style="display:flex;justify-content:space-between;gap:8px"><strong><?= sanitize($person['name']) ?></strong><span class="badge badge-danger"><?= (int)$person['flags'] ?> flags</span></div><div style="font-size:11px;color:var(--neutral);margin:4px 0"><?= sanitize($person['email']) ?> · <?= ucfirst(sanitize($person['role'])) ?> · Risk <?= (int)$person['max_risk'] ?>%</div><form method="POST" style="margin-top:8px"><input type="hidden" name="action" value="set_user_status"><input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>"><input type="hidden" name="status" value="<?= $person['status']==='suspended'?'active':'suspended' ?>"><button class="btn btn-sm <?= $person['status']==='suspended'?'btn-ghost':'btn-danger' ?>" onclick="return confirm('Ma xaqiijinaysaa?')"><?= $person['status']==='suspended'?'Activate':'Suspend Account' ?></button></form></div><?php endforeach; ?>
 </div></div>
 <div class="card"><div class="card-header"><span class="card-title"><i class="ri-radar-line" style="color:var(--danger)"></i> Recent AI Alerts</span><span class="badge badge-danger"><?= count($events) ?></span></div><div class="table-wrapper"><table class="data-table"><thead><tr><th>Risk</th><th>Sender</th><th>Receiver</th><th>AI Reason</th><th>Time</th></tr></thead><tbody>
 <?php if (!$events): ?><tr><td colspan="5"><div class="empty-state" style="padding:32px"><i class="ri-shield-check-line"></i><p>Scam alerts ma jiraan.</p></div></td></tr><?php endif; ?>
 <?php foreach ($events as $event): ?><tr><td><span class="badge badge-danger"><?= strtoupper(sanitize($event['action_taken'])) ?> <?= (int)$event['risk_score'] ?>%</span></td><td><strong><?= sanitize($event['sender_name']) ?></strong><br><small><?= sanitize($event['sender_email']) ?></small></td><td><?= sanitize($event['receiver_name']) ?></td><td style="max-width:300px;font-size:12px"><?= sanitize($event['reason'] ?: 'AI ayaa calaamadisay fariin khatar ah.') ?></td><td><?= timeAgo($event['created_at']) ?></td></tr><?php endforeach; ?>
 </tbody></table></div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
