<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Dashboard';
$active_page = 'dashboard.php';
$pdo = getDB();

// ── KPIs ──────────────────────────────────────────────────────
$total_users        = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'superadmin'")->fetchColumn();
$total_transactions = $pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
// Escrow fee from completed orders (10%)
$escrow_revenue     = (float)$pdo->query("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE status IN ('released')")->fetchColumn();
// Delivery commission from completed orders (0.2%)
$delivery_revenue   = (float)$pdo->query("SELECT COALESCE(SUM(delivery_commission),0) FROM transactions WHERE status IN ('released')")->fetchColumn();
// Commission earned from approving withdrawals (seller 10% + delivery 0.2%)
$withdrawal_commission = (float)$pdo->query("SELECT COALESCE(SUM(fee),0) FROM withdrawals WHERE status='completed'")->fetchColumn();
// Total platform revenue = escrow fees + delivery commissions + withdrawal commissions
$total_platform_revenue = $escrow_revenue + $delivery_revenue + $withdrawal_commission;
$total_disputed     = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'disputed'")->fetchColumn();
$pending_count      = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
$active_count       = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status IN ('funded','accepted','shipped','delivered')")->fetchColumn();

// Monthly revenue (last 7 months)
$revenue_months = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b') AS month, COALESCE(SUM(fee),0) AS total
    FROM transactions WHERE status='released' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY YEAR(created_at), MONTH(created_at)
")->fetchAll();

// Status distribution
$status_dist = $pdo->query("
    SELECT status, COUNT(*) AS cnt FROM transactions GROUP BY status
")->fetchAll();

// Users by role
$role_dist = $pdo->query("
    SELECT role, COUNT(*) AS cnt FROM users WHERE role != 'superadmin' GROUP BY role
")->fetchAll();

// Monthly transactions count
$tx_months = $pdo->query("
    SELECT DATE_FORMAT(created_at,'%b') AS month, COUNT(*) AS cnt
    FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at) ORDER BY YEAR(created_at), MONTH(created_at)
")->fetchAll();

// Recent transactions
$recent_txs = $pdo->query("
    SELECT t.*, u1.name AS buyer_name, u2.name AS seller_name
    FROM transactions t
    JOIN users u1 ON t.buyer_id = u1.id
    JOIN users u2 ON t.seller_id = u2.id
    ORDER BY t.created_at DESC LIMIT 8
")->fetchAll();

// Recent users
$recent_users = $pdo->query("
    SELECT * FROM users WHERE role != 'superadmin' ORDER BY created_at DESC LIMIT 5
")->fetchAll();

// Additional stats
$total_products      = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$open_disputes       = $pdo->query("SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review')")->fetchColumn();
$pending_withdrawals = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
$pending_deliveries  = $pdo->query("SELECT COUNT(*) FROM deliveries WHERE status IN ('pending_admin', 'requested_by_delivery') OR (status='assigned' AND admin_approved=0)")->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">SuperAdmin Command Center</h1>
        <p class="page-subtitle">Welcome back, <?= sanitize($user['name']) ?>! Escrow Marketplace Live Overview</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/superadmin/deliveries.php" class="btn btn-ghost btn-sm" style="color:var(--warning);font-weight:700;"><i class="ri-truck-line"></i> Deliveries (<?= $pending_deliveries ?>)</a>
        <a href="<?= APP_URL ?>/superadmin/disputes.php" class="btn btn-ghost btn-sm" style="color:var(--danger);"><i class="ri-scales-3-line"></i> Disputes (<?= $open_disputes ?>)</a>
        <a href="<?= APP_URL ?>/superadmin/withdrawals.php" class="btn btn-ghost btn-sm" style="color:var(--secondary);"><i class="ri-bank-line"></i> Payouts (<?= $pending_withdrawals ?>)</a>
        <a href="<?= APP_URL ?>/superadmin/reports.php" class="btn btn-primary btn-sm"><i class="ri-download-line"></i> Reports</a>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions fade-in" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:28px;">
    <a href="<?= APP_URL ?>/superadmin/products.php" class="qa-card" style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(29,59,139,.07);transition:all .2s;text-decoration:none;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="ri-shopping-bag-3-line" style="color:#fff;font-size:20px;"></i></div>
        <div><div style="font-size:13px;font-weight:700;color:var(--neutral-dark);">Products</div><div style="font-size:11px;color:var(--neutral-light);"><?= $total_products ?> listings</div></div>
    </a>
    <a href="<?= APP_URL ?>/superadmin/transactions.php" class="qa-card" style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(29,59,139,.07);transition:all .2s;text-decoration:none;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#10C87B,#34d892);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="ri-secure-payment-line" style="color:#fff;font-size:20px;"></i></div>
        <div><div style="font-size:13px;font-weight:700;color:var(--neutral-dark);">Escrow Orders</div><div style="font-size:11px;color:var(--neutral-light);"><?= $total_transactions ?> total</div></div>
    </a>
    <a href="<?= APP_URL ?>/superadmin/deliveries.php" class="qa-card" style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(29,59,139,.07);transition:all .2s;text-decoration:none;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#fbbf24);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="ri-truck-line" style="color:#fff;font-size:20px;"></i></div>
        <div><div style="font-size:13px;font-weight:700;color:var(--neutral-dark);">Deliveries</div><div style="font-size:11px;color:<?= $pending_deliveries > 0 ? 'var(--warning)' : 'var(--neutral-light)' ?>;font-weight:700;"><?= $pending_deliveries ?> need dispatch</div></div>
    </a>
    <a href="<?= APP_URL ?>/superadmin/disputes.php" class="qa-card" style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(29,59,139,.07);transition:all .2s;text-decoration:none;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#ef4444,#f87171);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="ri-scales-3-line" style="color:#fff;font-size:20px;"></i></div>
        <div><div style="font-size:13px;font-weight:700;color:var(--neutral-dark);">Disputes</div><div style="font-size:11px;color:<?= $open_disputes > 0 ? 'var(--danger)' : 'var(--neutral-light)' ?>;font-weight:600;"><?= $open_disputes ?> open</div></div>
    </a>
    <a href="<?= APP_URL ?>/superadmin/withdrawals.php" class="qa-card" style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(29,59,139,.07);transition:all .2s;text-decoration:none;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#fbbf24);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="ri-bank-line" style="color:#fff;font-size:20px;"></i></div>
        <div><div style="font-size:13px;font-weight:700;color:var(--neutral-dark);">Payouts</div><div style="font-size:11px;color:var(--neutral-light);"><?= $pending_withdrawals ?> pending</div></div>
    </a>
    <a href="<?= APP_URL ?>/superadmin/wallets.php" class="qa-card" style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(29,59,139,.07);transition:all .2s;text-decoration:none;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#8b5cf6,#a78bfa);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="ri-wallet-3-line" style="color:#fff;font-size:20px;"></i></div>
        <div><div style="font-size:13px;font-weight:700;color:var(--neutral-dark);">Wallets</div><div style="font-size:11px;color:var(--neutral-light);">User balances</div></div>
    </a>
    <a href="<?= APP_URL ?>/superadmin/users.php" class="qa-card" style="display:flex;align-items:center;gap:12px;background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(29,59,139,.07);transition:all .2s;text-decoration:none;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#1D3B8B,#3b5bb5);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="ri-group-line" style="color:#fff;font-size:20px;"></i></div>
        <div><div style="font-size:13px;font-weight:700;color:var(--neutral-dark);">Users</div><div style="font-size:11px;color:var(--neutral-light);"><?= $total_users ?> accounts</div></div>
    </a>
</div>

<!-- Stats Grid -->
<div class="stats-grid stagger fade-in">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Marketplace Products</div>
            <div class="stat-value" data-count="<?= $total_products ?>"><?= $total_products ?></div>
            <div class="stat-change up"><i class="ri-store-2-line"></i> Active listings</div>
        </div>
        <div class="stat-icon-wrap stat-icon-info"><i class="ri-shopping-bag-3-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Escrow Volume</div>
            <div class="stat-value" data-count="<?= $total_transactions ?>"><?= $total_transactions ?></div>
            <div class="stat-change up"><i class="ri-arrow-up-line"></i> All orders</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-secure-payment-line"></i></div>
    </div>
    <div class="stat-card" style="border-left: 4px solid var(--secondary);">
        <div class="stat-info">
            <div class="stat-label">Total Platform Revenue</div>
            <div class="stat-value" data-count="<?= number_format($total_platform_revenue, 2) ?>" data-prefix="$"><?= formatCurrency($total_platform_revenue) ?></div>
            <div class="stat-change up"><i class="ri-coins-line"></i> Escrow + Delivery + Payout fees</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-money-dollar-circle-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Withdrawal Commissions</div>
            <div class="stat-value" data-count="<?= number_format($withdrawal_commission, 2) ?>" data-prefix="$"><?= formatCurrency($withdrawal_commission) ?></div>
            <div class="stat-change up"><i class="ri-percent-line"></i> Seller 10% + Delivery 0.2%</div>
        </div>
        <div class="stat-icon-wrap stat-icon-success"><i class="ri-bank-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Active Escrow Holds</div>
            <div class="stat-value" data-count="<?= $active_count ?>"><?= $active_count ?></div>
            <div class="stat-change"><i class="ri-lock-2-line"></i> Secured in vault</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-lock-2-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Open Disputes</div>
            <div class="stat-value" data-count="<?= $open_disputes ?>"><?= $open_disputes ?></div>
            <?php if ($open_disputes > 0): ?>
            <div class="stat-change down"><i class="ri-alert-line"></i> Requires ruling</div>
            <?php else: ?>
            <div class="stat-change up"><i class="ri-check-line"></i> All resolved</div>
            <?php endif; ?>
        </div>
        <div class="stat-icon-wrap stat-icon-danger"><i class="ri-scales-3-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Users</div>
            <div class="stat-value" data-count="<?= $total_users ?>"><?= $total_users ?></div>
            <div class="stat-change up"><i class="ri-group-line"></i> Buyers & Sellers</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-group-line"></i></div>
    </div>
</div>

<!-- Charts Row -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px" class="fade-in">
    <!-- Revenue Chart -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-line-chart-line" style="color:var(--primary)"></i> Revenue Over Time</span>
            <span style="font-size:12px;color:var(--neutral-light)">Last 7 months</span>
        </div>
        <div class="card-body">
            <div class="chart-wrapper" style="height:220px">
                <canvas id="revenueChart"
                    data-labels='<?= json_encode(array_column($revenue_months, 'month')) ?>'
                    data-values='<?= json_encode(array_map(fn($r) => round($r['total'],2), $revenue_months)) ?>'>
                </canvas>
            </div>
        </div>
    </div>

    <!-- Status Donut -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-pie-chart-line" style="color:var(--primary)"></i> Transaction Status</span>
        </div>
        <div class="card-body">
            <div class="chart-wrapper" style="height:220px">
                <canvas id="statusChart"
                    data-labels='<?= json_encode(array_column($status_dist, 'status')) ?>'
                    data-values='<?= json_encode(array_column($status_dist, 'cnt')) ?>'>
                </canvas>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px" class="fade-in">
    <!-- Users by role -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-bar-chart-2-line" style="color:var(--primary)"></i> Users by Role</span>
        </div>
        <div class="card-body">
            <div class="chart-wrapper" style="height:200px">
                <canvas id="rolesChart"
                    data-labels='<?= json_encode(array_map(fn($r) => ucfirst($r['role']), $role_dist)) ?>'
                    data-values='<?= json_encode(array_column($role_dist, 'cnt')) ?>'>
                </canvas>
            </div>
        </div>
    </div>
    <!-- Monthly tx -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-bar-chart-grouped-line" style="color:var(--secondary)"></i> Monthly Transactions</span>
        </div>
        <div class="card-body">
            <div class="chart-wrapper" style="height:200px">
                <canvas id="txMonthChart"
                    data-labels='<?= json_encode(array_column($tx_months, 'month')) ?>'
                    data-values='<?= json_encode(array_column($tx_months, 'cnt')) ?>'>
                </canvas>
            </div>
        </div>
    </div>
</div>

<!-- Recent Transactions + Recent Users -->
<div style="display:grid;grid-template-columns:3fr 2fr;gap:20px" class="fade-in">
    <!-- Recent Transactions -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-secure-payment-line" style="color:var(--primary)"></i> Recent Transactions</span>
            <a href="<?= APP_URL ?>/superadmin/transactions.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ref</th>
                        <th>Buyer</th>
                        <th>Seller</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_txs as $tx): ?>
                    <tr>
                        <td><strong style="color:var(--primary)"><?= sanitize($tx['ref_code']) ?></strong></td>
                        <td><?= sanitize($tx['buyer_name']) ?></td>
                        <td><?= sanitize($tx['seller_name']) ?></td>
                        <td><strong><?= formatCurrency($tx['amount']) ?></strong></td>
                        <td><?= statusBadge($tx['status']) ?></td>
                        <td style="color:var(--neutral-light)"><?= date('M j, Y', strtotime($tx['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Users -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-group-line" style="color:var(--primary)"></i> New Users</span>
            <a href="<?= APP_URL ?>/superadmin/users.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="card-body" style="padding:0">
            <?php foreach ($recent_users as $u): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid #f5f8fd">
                <div class="avatar-placeholder" style="width:38px;height:38px;font-size:14px"><?= strtoupper(substr($u['name'],0,1)) ?></div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600;color:var(--neutral-dark)"><?= sanitize($u['name']) ?></div>
                    <div style="font-size:11px;color:var(--neutral-light)"><?= sanitize($u['email']) ?></div>
                </div>
                <div>
                    <span class="role-tag role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span>
                    <?= statusBadge($u['status']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
