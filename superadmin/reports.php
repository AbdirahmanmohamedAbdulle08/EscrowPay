<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Reports';
$active_page = 'reports.php';
$pdo         = getDB();

// Date filters
$date_from = $_GET['from'] ?? date('Y-m-01');
$date_to   = $_GET['to']   ?? date('Y-m-d');
$type      = $_GET['type'] ?? 'transactions';

// CSV Export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="escrow_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output','w');

    if ($type === 'transactions') {
        fputcsv($out,['Ref Code','Title','Buyer','Seller','Delivery','Amount','Fee','Net','Status','Date']);
        $rows = $pdo->prepare("
            SELECT t.ref_code,t.title,u1.name,u2.name,COALESCE(u3.name,'—'),t.amount,t.fee,t.net_amount,t.status,t.created_at
            FROM transactions t
            JOIN users u1 ON t.buyer_id=u1.id JOIN users u2 ON t.seller_id=u2.id LEFT JOIN users u3 ON t.delivery_id=u3.id
            WHERE DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.created_at DESC
        ");
        $rows->execute([$date_from,$date_to]);
        foreach ($rows->fetchAll(PDO::FETCH_NUM) as $r) fputcsv($out,$r);
    } else {
        fputcsv($out,['Name','Email','Role','Balance','Status','Joined']);
        $rows = $pdo->prepare("SELECT name,email,role,balance,status,created_at FROM users WHERE role!='superadmin' AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at DESC");
        $rows->execute([$date_from,$date_to]);
        foreach ($rows->fetchAll(PDO::FETCH_NUM) as $r) fputcsv($out,$r);
    }
    fclose($out);
    exit;
}

// Report Data
$tx_stats = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(amount),0) AS volume,
        COALESCE(SUM(fee),0) AS fees,
        COUNT(CASE WHEN status='released' THEN 1 END) AS released,
        COUNT(CASE WHEN status='disputed' THEN 1 END) AS disputed,
        COUNT(CASE WHEN status='cancelled' THEN 1 END) AS cancelled
    FROM transactions WHERE DATE(created_at) BETWEEN ? AND ?
");
$tx_stats->execute([$date_from,$date_to]);
$stats = $tx_stats->fetch();

$user_stats = $pdo->prepare("SELECT COUNT(*) AS total, role FROM users WHERE DATE(created_at) BETWEEN ? AND ? AND role!='superadmin' GROUP BY role");
$user_stats->execute([$date_from,$date_to]);
$user_by_role = $user_stats->fetchAll();

$tx_list = $pdo->prepare("
    SELECT t.*,u1.name AS buyer_name,u2.name AS seller_name
    FROM transactions t JOIN users u1 ON t.buyer_id=u1.id JOIN users u2 ON t.seller_id=u2.id
    WHERE DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.created_at DESC LIMIT 50
");
$tx_list->execute([$date_from,$date_to]);
$report_txs = $tx_list->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Reports</h1>
        <p class="page-subtitle">Generate and export data reports</p>
    </div>
    <div style="display:flex;gap:10px">
        <a href="?from=<?=$date_from?>&to=<?=$date_to?>&type=transactions&export=1" class="btn btn-ghost btn-sm"><i class="ri-file-excel-line"></i> Export Transactions</a>
        <a href="?from=<?=$date_from?>&to=<?=$date_to?>&type=users&export=1" class="btn btn-ghost btn-sm"><i class="ri-file-excel-line"></i> Export Users</a>
    </div>
</div>

<!-- Filter -->
<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px">
        <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div>
                <label class="form-label" style="margin-bottom:5px">From</label>
                <input type="date" name="from" class="form-control" value="<?= $date_from ?>">
            </div>
            <div>
                <label class="form-label" style="margin-bottom:5px">To</label>
                <input type="date" name="to" class="form-control" value="<?= $date_to ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Generate Report</button>
        </form>
    </div>
</div>

<!-- Summary Stats -->
<div class="stats-grid stagger fade-in">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Transactions</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-change">In period</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-secure-payment-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Transaction Volume</div>
            <div class="stat-value" style="font-size:20px"><?= formatCurrency($stats['volume']) ?></div>
            <div class="stat-change">Gross</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-funds-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Revenue (Fees)</div>
            <div class="stat-value" style="font-size:20px"><?= formatCurrency($stats['fees']) ?></div>
            <div class="stat-change up"><i class="ri-arrow-up-line"></i></div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-money-dollar-circle-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Released</div>
            <div class="stat-value"><?= $stats['released'] ?></div>
            <div class="stat-change up"><i class="ri-checkbox-circle-line"></i></div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-checkbox-circle-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Disputes</div>
            <div class="stat-value"><?= $stats['disputed'] ?></div>
        </div>
        <div class="stat-icon-wrap stat-icon-danger"><i class="ri-error-warning-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Cancelled</div>
            <div class="stat-value"><?= $stats['cancelled'] ?></div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-close-circle-line"></i></div>
    </div>
</div>

<!-- Transaction List -->
<div class="card fade-in">
    <div class="card-header">
        <span class="card-title">Transactions — <?= date('M j, Y', strtotime($date_from)) ?> to <?= date('M j, Y', strtotime($date_to)) ?></span>
        <span style="font-size:12px;color:var(--neutral-light)"><?= count($report_txs) ?> records (max 50 shown)</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr><th>Ref</th><th>Title</th><th>Buyer</th><th>Seller</th><th>Amount</th><th>Fee</th><th>Status</th><th>Date</th></tr>
            </thead>
            <tbody>
                <?php if (empty($report_txs)): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="ri-file-search-line"></i><h3>No transactions in this period</h3></div></td></tr>
                <?php endif; ?>
                <?php foreach ($report_txs as $tx): ?>
                <tr>
                    <td><strong style="color:var(--primary);font-size:12px"><?= sanitize($tx['ref_code']) ?></strong></td>
                    <td><?= sanitize($tx['title']) ?></td>
                    <td><?= sanitize($tx['buyer_name']) ?></td>
                    <td><?= sanitize($tx['seller_name']) ?></td>
                    <td><strong><?= formatCurrency($tx['amount']) ?></strong></td>
                    <td style="color:var(--warning)"><?= formatCurrency($tx['fee']) ?></td>
                    <td><?= statusBadge($tx['status']) ?></td>
                    <td style="font-size:12px;color:var(--neutral-light)"><?= date('M j, Y', strtotime($tx['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
