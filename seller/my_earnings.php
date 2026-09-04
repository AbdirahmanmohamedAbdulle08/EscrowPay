<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['seller']);
$page_title  = 'My Earnings';
$active_page = 'my_earnings.php';
$pdo         = getDB();
$uid         = $user['id'];

// Get released transactions
$stmt = $pdo->prepare("
    SELECT t.*, u.name AS buyer_name
    FROM transactions t JOIN users u ON t.buyer_id=u.id
    WHERE t.seller_id=? AND t.status='released' ORDER BY t.released_at DESC
");
$stmt->execute([$uid]);
$earnings = $stmt->fetchAll();

$total = array_sum(array_column($earnings, 'net_amount'));

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">My Earnings</h1>
        <p class="page-subtitle">Track your completed sales and payouts</p>
    </div>
</div>

<div class="stats-grid fade-in">
    <div class="stat-card" style="background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;border:none">
        <div class="stat-info">
            <div class="stat-label" style="color:rgba(255,255,255,.7)">Wallet Balance</div>
            <div class="stat-value" style="color:var(--secondary)"><?= formatCurrency($user['balance']) ?></div>
            <div class="stat-change" style="color:rgba(255,255,255,.6)">Available for withdrawal</div>
        </div>
        <div class="stat-icon-wrap" style="background:rgba(255,255,255,.1);color:#fff"><i class="ri-wallet-3-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Earned</div>
            <div class="stat-value"><?= formatCurrency($total) ?></div>
            <div class="stat-change up"><i class="ri-arrow-up-line"></i> All time</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-money-dollar-box-line"></i></div>
    </div>
</div>

<div class="card fade-in">
    <div class="card-header"><span class="card-title">Earning History</span></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Ref</th><th>Item</th><th>Buyer</th><th>Net Earned</th><th>Released On</th></tr></thead>
            <tbody>
                <?php if(empty($earnings)): ?>
                <tr><td colspan="5"><div class="empty-state"><i class="ri-file-list-3-line"></i><h3>No earnings yet</h3></div></td></tr>
                <?php endif; ?>
                <?php foreach($earnings as $tx): ?>
                <tr>
                    <td><strong style="color:var(--primary);font-size:12px"><?= sanitize($tx['ref_code']) ?></strong></td>
                    <td><?= sanitize($tx['title']) ?></td>
                    <td><?= sanitize($tx['buyer_name']) ?></td>
                    <td><strong style="color:var(--secondary-dark)">+<?= formatCurrency($tx['net_amount']) ?></strong></td>
                    <td style="font-size:12px;color:var(--neutral-light)"><?= date('M j, Y H:i', strtotime($tx['released_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
