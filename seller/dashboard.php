<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['seller']);
$page_title  = 'Seller Dashboard';
$active_page = 'dashboard.php';
$pdo         = getDB();
$uid         = $user['id'];

// Seller KPIs
$stmt_orders = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE seller_id=?");
$stmt_orders->execute([$uid]);
$total_orders = $stmt_orders->fetchColumn();

$stmt_pending = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE seller_id=? AND status IN ('funded','accepted')");
$stmt_pending->execute([$uid]);
$pending_orders = $stmt_pending->fetchColumn();

$stmt_shipped = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE seller_id=? AND status IN ('shipped','delivered')");
$stmt_shipped->execute([$uid]);
$in_transit = $stmt_shipped->fetchColumn();

$stmt_earned = $pdo->prepare("SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE seller_id=? AND status='released'");
$stmt_earned->execute([$uid]);
$total_earned = (float)$stmt_earned->fetchColumn();

// Pending release balance
$stmt_pending_funds = $pdo->prepare("SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE seller_id=? AND status IN ('funded','accepted','shipped','delivered')");
$stmt_pending_funds->execute([$uid]);
$pending_funds = (float)$stmt_pending_funds->fetchColumn();

// Recent incoming orders
$recent = $pdo->prepare("
    SELECT t.*, u.name AS buyer_name, d.name AS delivery_name
    FROM transactions t
    JOIN users u ON t.buyer_id=u.id
    LEFT JOIN users d ON t.delivery_id=d.id
    WHERE t.seller_id=? ORDER BY t.created_at DESC LIMIT 8
");
$recent->execute([$uid]);
$recent_orders = $recent->fetchAll();

// Monthly earnings for chart
$chart_stmt = $pdo->prepare("
    SELECT DATE_FORMAT(created_at, '%b') as month, COALESCE(SUM(net_amount), 0) as total
    FROM transactions
    WHERE seller_id=? AND status='released' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY YEAR(created_at), MONTH(created_at)
");
$chart_stmt->execute([$uid]);
$chart_rows = $chart_stmt->fetchAll();
$chart_labels = array_column($chart_rows, 'month');
$chart_values = array_map(fn($v) => (float)$v, array_column($chart_rows, 'total'));

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Seller Hub</h1>
        <p class="page-subtitle">Welcome back, <?= sanitize($user['name']) ?>! Track your marketplace products, orders, and wallet payouts.</p>
    </div>
    <div style="display:flex;gap:10px">
        <a href="products.php" class="btn btn-primary btn-sm"><i class="ri-store-2-line"></i> My Products</a>
        <a href="orders.php" class="btn btn-ghost btn-sm"><i class="ri-shopping-bag-line"></i> Manage Orders</a>
        <a href="wallet.php" class="btn btn-ghost btn-sm"><i class="ri-wallet-3-line"></i> Wallet & Payouts</a>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid stagger fade-in">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Earnings</div>
            <div class="stat-value" data-count="<?= number_format($total_earned, 2) ?>" data-prefix="$"><?= formatCurrency($total_earned) ?></div>
            <div class="stat-change up"><i class="ri-arrow-up-line"></i> Settled &amp; Withdrawn</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-wallet-3-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Pending In Escrow</div>
            <div class="stat-value" data-count="<?= number_format($pending_funds, 2) ?>" data-prefix="$"><?= formatCurrency($pending_funds) ?></div>
            <div class="stat-change"><i class="ri-time-line"></i> Locked in active orders</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-shield-check-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Action Required</div>
            <div class="stat-value" data-count="<?= $pending_orders ?>"><?= $pending_orders ?></div>
            <div class="stat-change <?= $pending_orders > 0 ? 'down' : 'up' ?>">
                <i class="<?= $pending_orders > 0 ? 'ri-alert-line' : 'ri-checkbox-circle-line' ?>"></i>
                <?= $pending_orders > 0 ? 'Needs acceptance / shipping' : 'All clear' ?>
            </div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-inbox-archive-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">In Transit / Shipped</div>
            <div class="stat-value" data-count="<?= $in_transit ?>"><?= $in_transit ?></div>
            <div class="stat-change"><i class="ri-truck-line"></i> Awaiting delivery</div>
        </div>
        <div class="stat-icon-wrap stat-icon-info"><i class="ri-truck-line"></i></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px" class="fade-in">
    <!-- Recent Incoming Orders -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-shopping-bag-3-line" style="color:var(--primary)"></i> Incoming Escrow Orders</span>
            <a href="orders.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Item</th>
                        <th>Buyer</th>
                        <th>Net Payout</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_orders)): ?>
                    <tr><td colspan="6"><div class="empty-state" style="padding:30px"><i class="ri-shopping-bag-line"></i><h3>No orders yet</h3><p>When buyers create escrow deals with you, they will appear here.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($recent_orders as $tx): ?>
                    <tr>
                        <td><strong style="color:var(--primary);font-size:12px"><?= sanitize($tx['ref_code']) ?></strong></td>
                        <td>
                            <div style="font-weight:600;color:var(--neutral-dark)"><?= sanitize($tx['title']) ?></div>
                        </td>
                        <td><?= sanitize($tx['buyer_name']) ?></td>
                        <td><strong style="color:var(--secondary-dark)"><?= formatCurrency($tx['net_amount']) ?></strong></td>
                        <td><?= statusBadge($tx['status']) ?></td>
                        <td style="font-size:12px;color:var(--neutral-light)"><?= date('M j', strtotime($tx['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Monthly Earnings Chart + Fast Actions -->
    <div style="display:flex;flex-direction:column;gap:20px">
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="ri-line-chart-line" style="color:var(--secondary)"></i> Revenue Trend</span>
            </div>
            <div class="card-body">
                <div class="chart-wrapper" style="height:170px">
                    <canvas id="revenueChart"
                        data-labels='<?= json_encode($chart_labels ?: ['Jan','Feb','Mar','Apr','May','Jun']) ?>'
                        data-values='<?= json_encode($chart_values ?: [0,0,0,0,0,0]) ?>'>
                    </canvas>
                </div>
            </div>
        </div>

        <div class="card" style="background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;border:none">
            <div class="card-body" style="padding:22px">
                <div style="font-size:12px;opacity:.7;text-transform:uppercase;letter-spacing:.6px;margin-bottom:6px">Seller Balance</div>
                <div style="font-size:32px;font-weight:800;color:var(--secondary)"><?= formatCurrency($user['balance']) ?></div>
                <p style="font-size:12px;opacity:.8;margin-top:8px;line-height:1.5">Funds from released escrow orders are directly credited to your wallet balance for withdrawal.</p>
                <a href="my_earnings.php" class="btn btn-secondary btn-sm" style="margin-top:14px"><i class="ri-money-dollar-box-line"></i> Withdraw / History</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
