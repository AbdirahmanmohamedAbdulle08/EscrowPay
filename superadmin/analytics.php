<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Analytics';
$active_page = 'analytics.php';
$pdo         = getDB();

// Revenue by month (12 months)
$rev_data = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') AS month, COALESCE(SUM(fee),0) AS revenue, COUNT(*) AS tx_count
    FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY YEAR(created_at), MONTH(created_at)
")->fetchAll();

// Status distribution
$status_data = $pdo->query("SELECT status, COUNT(*) AS cnt FROM transactions GROUP BY status ORDER BY cnt DESC")->fetchAll();

// Users by role
$role_data = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users WHERE role!='superadmin' GROUP BY role")->fetchAll();

// Top buyers
$top_buyers = $pdo->query("
    SELECT u.name, COUNT(t.id) AS orders, COALESCE(SUM(t.amount),0) AS total_spent
    FROM transactions t JOIN users u ON t.buyer_id=u.id
    GROUP BY t.buyer_id ORDER BY total_spent DESC LIMIT 5
")->fetchAll();

// Top sellers
$top_sellers = $pdo->query("
    SELECT u.name, COUNT(t.id) AS orders, COALESCE(SUM(t.net_amount),0) AS total_earned
    FROM transactions t JOIN users u ON t.seller_id=u.id WHERE t.status='released'
    GROUP BY t.seller_id ORDER BY total_earned DESC LIMIT 5
")->fetchAll();

// KPIs
$avg_tx   = $pdo->query("SELECT AVG(amount) FROM transactions")->fetchColumn();
$success_rate = $pdo->query("SELECT ROUND(COUNT(CASE WHEN status='released' THEN 1 END)*100.0/NULLIF(COUNT(*),0),1) FROM transactions")->fetchColumn();
$total_volume = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status!='cancelled'")->fetchColumn();
$new_users_month = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND role!='superadmin'")->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Analytics</h1>
        <p class="page-subtitle">Platform performance and insights</p>
    </div>
</div>

<!-- KPI Row -->
<div class="stats-grid stagger fade-in">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Volume</div>
            <div class="stat-value" style="font-size:22px"><?= formatCurrency((float)$total_volume) ?></div>
            <div class="stat-change up"><i class="ri-arrow-up-line"></i> All transactions</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-funds-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Avg. Transaction</div>
            <div class="stat-value" style="font-size:22px"><?= formatCurrency((float)$avg_tx) ?></div>
            <div class="stat-change"><i class="ri-information-line"></i> Per escrow</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-calculator-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Success Rate</div>
            <div class="stat-value" style="font-size:22px"><?= $success_rate ?? 0 ?>%</div>
            <div class="stat-change up"><i class="ri-checkbox-circle-line"></i> Released escrows</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-percent-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">New Users (30d)</div>
            <div class="stat-value" style="font-size:22px"><?= $new_users_month ?></div>
            <div class="stat-change up"><i class="ri-user-add-line"></i> This month</div>
        </div>
        <div class="stat-icon-wrap stat-icon-info"><i class="ri-group-line"></i></div>
    </div>
</div>

<!-- Revenue + Status charts -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px" class="fade-in">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-line-chart-line" style="color:var(--primary)"></i> Monthly Revenue</span>
            <span style="font-size:12px;color:var(--neutral-light)">Last 12 months</span>
        </div>
        <div class="card-body">
            <div class="chart-wrapper" style="height:260px">
                <canvas id="revenueChart"
                    data-labels='<?= json_encode(array_column($rev_data,'month')) ?>'
                    data-values='<?= json_encode(array_map(fn($r)=>round($r['revenue'],2),$rev_data)) ?>'>
                </canvas>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-pie-chart-line" style="color:var(--primary)"></i> By Status</span>
        </div>
        <div class="card-body">
            <div class="chart-wrapper" style="height:260px">
                <canvas id="statusChart"
                    data-labels='<?= json_encode(array_column($status_data,'status')) ?>'
                    data-values='<?= json_encode(array_column($status_data,'cnt')) ?>'>
                </canvas>
            </div>
        </div>
    </div>
</div>

<!-- Roles + Tx count charts -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px" class="fade-in">
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-bar-chart-2-line" style="color:var(--primary)"></i> Users by Role</span>
        </div>
        <div class="card-body">
            <div class="chart-wrapper" style="height:220px">
                <canvas id="rolesChart"
                    data-labels='<?= json_encode(array_map(fn($r)=>ucfirst($r['role']),$role_data)) ?>'
                    data-values='<?= json_encode(array_column($role_data,'cnt')) ?>'>
                </canvas>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-bar-chart-grouped-line" style="color:var(--secondary)"></i> Transactions per Month</span>
        </div>
        <div class="card-body">
            <div class="chart-wrapper" style="height:220px">
                <canvas id="txMonthChart"
                    data-labels='<?= json_encode(array_column($rev_data,'month')) ?>'
                    data-values='<?= json_encode(array_column($rev_data,'tx_count')) ?>'>
                </canvas>
            </div>
        </div>
    </div>
</div>

<!-- Top Buyers + Sellers -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" class="fade-in">
    <div class="card">
        <div class="card-header"><span class="card-title"><i class="ri-vip-crown-line" style="color:var(--warning)"></i> Top Buyers</span></div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>#</th><th>Name</th><th>Orders</th><th>Total Spent</th></tr></thead>
                <tbody>
                    <?php foreach ($top_buyers as $i => $b): ?>
                    <tr>
                        <td><strong style="color:var(--warning)"><?= $i+1 ?></strong></td>
                        <td><?= sanitize($b['name']) ?></td>
                        <td><?= $b['orders'] ?></td>
                        <td><strong style="color:var(--primary)"><?= formatCurrency($b['total_spent']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><span class="card-title"><i class="ri-store-2-line" style="color:var(--secondary)"></i> Top Sellers</span></div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>#</th><th>Name</th><th>Orders</th><th>Total Earned</th></tr></thead>
                <tbody>
                    <?php foreach ($top_sellers as $i => $s): ?>
                    <tr>
                        <td><strong style="color:var(--secondary)"><?= $i+1 ?></strong></td>
                        <td><?= sanitize($s['name']) ?></td>
                        <td><?= $s['orders'] ?></td>
                        <td><strong style="color:var(--secondary)"><?= formatCurrency($s['total_earned']) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
