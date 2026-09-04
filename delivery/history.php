<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['delivery']);
$page_title  = 'Delivery History';
$active_page = 'history.php';
$pdo         = getDB();
$uid         = $user['id'];

$stmt = $pdo->prepare("
    SELECT d.*, t.ref_code, t.title, u_buyer.name AS b_name, u_seller.name AS s_name
    FROM deliveries d
    JOIN transactions t ON d.transaction_id=t.id
    JOIN users u_buyer ON t.buyer_id=u_buyer.id
    JOIN users u_seller ON t.seller_id=u_seller.id
    WHERE d.delivery_id=? AND d.status='delivered'
    ORDER BY d.delivered_at DESC LIMIT 50
");
$stmt->execute([$uid]);
$history = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Delivery History</h1>
        <p class="page-subtitle">Log of your past completed deliveries</p>
    </div>
</div>

<div class="card fade-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>Item</th>
                    <th>Seller</th>
                    <th>Buyer</th>
                    <th>Delivered On</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr><td colspan="5"><div class="empty-state"><i class="ri-history-line"></i><h3>No history found</h3></div></td></tr>
                <?php endif; ?>
                <?php foreach($history as $d): ?>
                <tr>
                    <td><strong style="color:var(--primary);font-size:12px"><?= sanitize($d['ref_code']) ?></strong></td>
                    <td><?= sanitize($d['title']) ?></td>
                    <td><?= sanitize($d['s_name']) ?></td>
                    <td><?= sanitize($d['b_name']) ?></td>
                    <td style="font-size:12px;color:var(--neutral-light)"><i class="ri-calendar-check-line" style="color:var(--success)"></i> <?= date('M j, Y H:i', strtotime($d['delivered_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
