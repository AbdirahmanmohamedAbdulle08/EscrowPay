<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Audit Logs';
$active_page = 'logs.php';
$pdo         = getDB();

// Filters
$user_f   = trim($_GET['user'] ?? '');
$action_f = trim($_GET['action'] ?? '');
$date_f   = trim($_GET['date']  ?? '');

$where = ['1=1'];
$params = [];
if ($user_f)   { $where[] = "(u.name LIKE ? OR u.email LIKE ?)"; $params[] = "%$user_f%"; $params[] = "%$user_f%"; }
if ($action_f) { $where[] = "l.action LIKE ?";                   $params[] = "%$action_f%"; }
if ($date_f)   { $where[] = "DATE(l.created_at)=?";              $params[] = $date_f; }
$whereSQL = implode(' AND ',$where);

$per_page    = 20;
$page_num    = max(1,(int)($_GET['page']??1));
$offset      = ($page_num-1)*$per_page;
$total_count = (int)$pdo->prepare("SELECT COUNT(*) FROM audit_logs l LEFT JOIN users u ON l.user_id=u.id WHERE $whereSQL")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM audit_logs l LEFT JOIN users u ON l.user_id=u.id WHERE $whereSQL")->execute($params) : 0;

// Re-count correctly
$cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs l LEFT JOIN users u ON l.user_id=u.id WHERE $whereSQL");
$cnt->execute($params);
$total_count = (int)$cnt->fetchColumn();
$total_pages = max(1, ceil($total_count/$per_page));

$stmt = $pdo->prepare("
    SELECT l.*, u.name AS user_name, u.email AS user_email, u.role AS user_role
    FROM audit_logs l LEFT JOIN users u ON l.user_id=u.id
    WHERE $whereSQL ORDER BY l.created_at DESC LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Audit Logs</h1>
        <p class="page-subtitle"><?= $total_count ?> log entries found</p>
    </div>
</div>

<!-- Filters -->
<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:160px">
                <label class="form-label" style="margin-bottom:5px">User</label>
                <input type="text" name="user" class="form-control" placeholder="Name or email..." value="<?= sanitize($user_f) ?>">
            </div>
            <div style="flex:1;min-width:160px">
                <label class="form-label" style="margin-bottom:5px">Action</label>
                <input type="text" name="action" class="form-control" placeholder="e.g. LOGIN, CREATE..." value="<?= sanitize($action_f) ?>">
            </div>
            <div>
                <label class="form-label" style="margin-bottom:5px">Date</label>
                <input type="date" name="date" class="form-control" value="<?= sanitize($date_f) ?>">
            </div>
            <div style="display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Filter</button>
                <a href="logs.php" class="btn btn-ghost btn-sm"><i class="ri-refresh-line"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP Address</th></tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6"><div class="empty-state"><i class="ri-file-list-3-line"></i><h3>No logs found</h3></div></td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                <?php
                $actionColors = [
                    'LOGIN' => 'badge-success', 'LOGOUT' => 'badge-neutral',
                    'LOGIN_FAILED' => 'badge-danger', 'USER_CREATED' => 'badge-primary',
                    'USER_UPDATED' => 'badge-info', 'USER_DELETED' => 'badge-danger',
                    'SETTINGS_UPDATED' => 'badge-warning', 'FORCE_RELEASE' => 'badge-success',
                    'DISPUTE_RESOLVED' => 'badge-primary', 'TX_CANCELLED' => 'badge-neutral',
                ];
                $badgeClass = $actionColors[$log['action']] ?? 'badge-neutral';
                ?>
                <tr>
                    <td style="font-size:12px;white-space:nowrap;color:var(--neutral-light)">
                        <?= date('M j, Y', strtotime($log['created_at'])) ?><br>
                        <strong><?= date('H:i:s', strtotime($log['created_at'])) ?></strong>
                    </td>
                    <td>
                        <?php if ($log['user_name']): ?>
                        <div style="font-weight:600;font-size:13px"><?= sanitize($log['user_name']) ?></div>
                        <div style="font-size:11px;color:var(--neutral-light)"><?= sanitize($log['user_email'] ?? '') ?></div>
                        <?php else: ?>
                        <span style="color:var(--neutral-light);font-style:italic">System</span>
                        <?php endif; ?>
                    </td>
                    <td><?php if ($log['user_role']): ?><span class="role-tag role-<?= $log['user_role'] ?>"><?= ucfirst($log['user_role']) ?></span><?php endif; ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= sanitize($log['action']) ?></span></td>
                    <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--neutral)"><?= sanitize($log['details'] ?? '—') ?></td>
                    <td style="font-size:12px;color:var(--neutral-light);font-family:monospace"><?= sanitize($log['ip_address'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer" style="display:flex;align-items:center;justify-content:space-between">
        <span>Showing <?= ($offset+1) ?>–<?= min($offset+$per_page,$total_count) ?> of <?= $total_count ?></span>
        <div class="pagination">
            <?php for($p=1;$p<=$total_pages;$p++): ?>
            <a href="?page=<?=$p?>&user=<?=urlencode($user_f)?>&action=<?=urlencode($action_f)?>" class="page-btn <?=$p===$page_num?'active':''?>"><?=$p?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
