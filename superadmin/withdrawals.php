<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Withdrawals Management';
$active_page = 'withdrawals.php';
$pdo         = getDB();

$success = $error = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $w_id   = (int)($_POST['withdrawal_id'] ?? 0);
    $notes  = trim(sanitize($_POST['admin_notes'] ?? ''));

    $stmt = $pdo->prepare("SELECT w.*, u.name AS user_name, u.email AS user_email, u.role AS user_role FROM withdrawals w JOIN users u ON w.user_id=u.id WHERE w.id=?");
    $stmt->execute([$w_id]);
    $wd = $stmt->fetch();

    if (!$wd) {
        $error = 'Withdrawal request not found.';
    } elseif ($action === 'complete') {
        // Commission deducted at withdrawal: Seller=10%, Delivery=0.2%
        $user_role = $wd['user_role'] ?? 'seller';
        $fee_pct   = ($user_role === 'delivery') ? 0.2 : 10.0;

        $fee_amount = max(0.01, ceil($wd['amount'] * ($fee_pct / 100) * 100) / 100);
        $net_amount = round($wd['amount'] - $fee_amount, 2);

        $role_label    = ucfirst($user_role);
        $default_notes = "Payout via {$wd['payment_method']}. Commission ({$fee_pct}%): " . formatCurrency($fee_amount) . ". Net sent to {$role_label}: " . formatCurrency($net_amount);
        $final_notes   = $notes ?: $default_notes;

        $pdo->prepare("UPDATE withdrawals SET status='completed', fee=?, admin_notes=?, processed_at=NOW() WHERE id=?")
            ->execute([$fee_amount, $final_notes, $w_id]);

        // Deduct commission from user's balance
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id=?")
            ->execute([$fee_amount, $wd['user_id']]);

        // ✅ Credit commission to SuperAdmin balance
        $admin_id = $user['id'];
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")
            ->execute([$fee_amount, $admin_id]);

        // ✅ Record commission income in wallet_transactions for SuperAdmin
        $admin_bal = (float)$pdo->query("SELECT balance FROM users WHERE id=$admin_id")->fetchColumn();
        $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
            VALUES (?, 'commission', ?, ?, ?, ?, ?, 'platform', 'completed')")
            ->execute([
                $admin_id,
                $fee_amount,
                $admin_bal - $fee_amount,
                $admin_bal,
                'COM-WDR-' . $w_id,
                ucfirst($user_role) . " withdrawal commission ({$fee_pct}%) from {$wd['user_name']}",
            ]);

        // ✅ Update wallet_transactions status so delivery/seller wallet shows Completed
        $pdo->prepare("UPDATE wallet_transactions SET status='completed', balance_after = balance_after - ? WHERE reference = ? AND user_id = ?")
            ->execute([$fee_amount, 'WDR-'.$w_id, $wd['user_id']]);

        $wallet_link = ($user_role === 'delivery') ? APP_URL . '/delivery/wallet.php' : APP_URL . '/seller/wallet.php';
        addNotification($wd['user_id'], 'Withdrawal Completed! ✅',
            "Your withdrawal of " . formatCurrency($wd['amount']) . " processed. Platform commission ({$fee_pct}%): " . formatCurrency($fee_amount) . ". You receive: " . formatCurrency($net_amount) . " via {$wd['payment_method']}.",
            'success', $wallet_link);
        logAudit('WITHDRAWAL_COMPLETED', "Payout #$w_id for {$wd['user_name']} [{$user_role}] | Gross: \${$wd['amount']} | Fee({$fee_pct}%): \${$fee_amount} | Net: \${$net_amount}", $user['id']);
        $success = "Withdrawal #$w_id completed! [{$role_label}] Commission {$fee_pct}% = " . formatCurrency($fee_amount) . " — <strong>Net sent: " . formatCurrency($net_amount) . "</strong>";

    } elseif ($action === 'reject') {
        // Refund amount back to user's wallet
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
        $pdo->prepare("UPDATE withdrawals SET status='rejected', admin_notes=?, processed_at=NOW() WHERE id=?")->execute([$notes ?: 'Rejected by administrator', $w_id]);

        $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
            SELECT ?, 'topup', ?, balance - ?, balance, ?, ?, 'admin_refund', 'completed' FROM users WHERE id=?")
            ->execute([$wd['user_id'], $wd['amount'], $wd['amount'], 'WDR-REJ-'.$w_id, "Refund for rejected withdrawal #$w_id", $wd['user_id']]);

        addNotification($wd['user_id'], 'Withdrawal Rejected', "Your withdrawal of " . formatCurrency($wd['amount']) . " was rejected. Reason: $notes. Funds returned to your wallet.", 'danger', APP_URL . '/seller/wallet.php');
        logAudit('WITHDRAWAL_REJECTED', "SuperAdmin rejected withdrawal #$w_id ($notes)", $user['id']);
        $success = "Withdrawal #$w_id rejected and funds returned to user's wallet.";
    }
}

// Fetch all withdrawals
$filter = sanitize($_GET['status'] ?? '');
$where = '1=1';
if (!empty($filter)) {
    $where = "w.status = " . $pdo->quote($filter);
}

$withdrawals = $pdo->query("
    SELECT w.*, u.name AS user_name, u.email AS user_email, u.role AS user_role, u.balance AS current_balance
    FROM withdrawals w
    JOIN users u ON w.user_id = u.id
    WHERE $where
    ORDER BY w.created_at DESC
")->fetchAll();

$pending_count     = $pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
$total_paid        = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status='completed'")->fetchColumn();
$total_fee_earned  = (float)$pdo->query("SELECT COALESCE(SUM(fee),0) FROM withdrawals WHERE status='completed'")->fetchColumn();
$total_net_sent    = max(0, $total_paid - $total_fee_earned);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-bank-line" style="color:var(--primary)"></i> Seller & Delivery Withdrawals</h1>
        <p class="page-subtitle">Process payout requests to Mobile Money (EVC Plus, Waafi, Zaad) or Bank accounts with automatic commission deduction</p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid stagger fade-in" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Pending Payout Requests</div>
            <div class="stat-value" style="color:var(--warning);"><?= $pending_count ?></div>
            <div class="stat-change"><i class="ri-time-line"></i> Awaiting Transfer</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-hourglass-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Payouts (Gross)</div>
            <div class="stat-value"><?= formatCurrency($total_paid) ?></div>
            <div class="stat-change up"><i class="ri-check-double-line"></i> All Time Processed</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-checkbox-circle-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Net Sent to Users</div>
            <div class="stat-value" style="color:var(--secondary);"><?= formatCurrency($total_net_sent) ?></div>
            <div class="stat-change up"><i class="ri-arrow-right-up-line"></i> Transferred to Mobile/Bank</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-wallet-3-line"></i></div>
    </div>

    <div class="stat-card" style="border-left: 4px solid var(--success);">
        <div class="stat-info">
            <div class="stat-label">Commissions Earned</div>
            <div class="stat-value" style="color:var(--success);"><?= formatCurrency($total_fee_earned) ?></div>
            <div class="stat-change up"><i class="ri-percent-line"></i> Seller 10% + Delivery 0.2%</div>
        </div>
        <div class="stat-icon-wrap stat-icon-success"><i class="ri-money-dollar-circle-line"></i></div>
    </div>
</div>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap" class="fade-in">
    <a href="withdrawals.php" class="btn <?= empty($filter) ? 'btn-primary' : 'btn-ghost' ?> btn-sm">All Requests</a>
    <a href="?status=pending" class="btn <?= $filter === 'pending' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Pending (<?= $pending_count ?>)</a>
    <a href="?status=completed" class="btn <?= $filter === 'completed' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Completed</a>
    <a href="?status=rejected" class="btn <?= $filter === 'rejected' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Rejected</a>
</div>

<!-- Table -->
<div class="card fade-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>User</th>
                    <th>Method</th>
                    <th>Account Info</th>
                    <th>Amount & Breakdown</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($withdrawals)): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="ri-bank-line"></i><h3>No withdrawal requests</h3></div></td></tr>
                <?php endif; ?>

                <?php foreach ($withdrawals as $w): 
                    $role = $w['user_role'] ?? 'seller';
                    $pct = ($role === 'delivery') ? 0.2 : 10.0;
                    $calc_fee = ($w['status'] === 'completed') 
                        ? (float)($w['fee'] ?? 0) 
                        : max(0.01, ceil($w['amount'] * ($pct / 100) * 100) / 100);
                    $net_pay = round($w['amount'] - $calc_fee, 2);
                ?>
                <tr>
                    <td style="font-size:12px;color:var(--neutral-light);"><?= date('M j, Y H:i', strtotime($w['created_at'])) ?></td>
                    <td>
                        <strong style="font-size:13px;color:var(--neutral-dark);"><?= sanitize($w['user_name']) ?></strong>
                        <span class="role-tag role-<?= $w['user_role'] ?>" style="font-size:9px;margin-left:4px;"><?= ucfirst($w['user_role']) ?></span>
                        <div style="font-size:11px;color:var(--neutral-light);"><?= sanitize($w['user_email']) ?></div>
                    </td>
                    <td>
                        <span class="badge badge-info" style="font-size:10px;text-transform:uppercase;">
                            <?= sanitize($w['payment_method']) ?>
                        </span>
                    </td>
                    <td><strong style="font-size:12px;"><?= sanitize($w['account_info']) ?></strong></td>
                    <td>
                        <div style="font-size:13px;font-weight:700;color:var(--neutral-dark);">Gross: <?= formatCurrency($w['amount']) ?></div>
                        <div style="font-size:11px;color:var(--warning);font-weight:600;">Fee (<?= $pct ?>%): -<?= formatCurrency($calc_fee) ?></div>
                        <div style="font-size:12px;color:var(--success);font-weight:800;margin-top:1px;">Net Sent: <?= formatCurrency($net_pay) ?></div>
                    </td>
                    <td><?= statusBadge($w['status']) ?></td>
                    <td style="font-size:11px;color:var(--neutral);max-width:200px;"><?= sanitize($w['admin_notes'] ?? '—') ?></td>
                    <td>
                        <?php if ($w['status'] === 'pending'): ?>
                        <div style="display:flex;gap:6px;">
                            <form method="POST" style="display:inline" onsubmit="return confirm('Confirm payout completed? Net amount to send: $<?= number_format($net_pay, 2) ?>')">
                                <input type="hidden" name="action" value="complete">
                                <input type="hidden" name="withdrawal_id" value="<?= $w['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="background:var(--secondary-light);color:var(--secondary-dark);padding:5px 10px;font-size:11px;font-weight:700;">
                                    <i class="ri-check-line"></i> Pay $<?= number_format($net_pay, 2) ?>
                                </button>
                            </form>
                            <button type="button" class="btn btn-sm btn-ghost" onclick="openRejectModal(<?= $w['id'] ?>, '<?= sanitize($w['user_name']) ?>', '<?= formatCurrency($w['amount']) ?>')" style="color:var(--danger);padding:5px 8px;font-size:11px;">
                                <i class="ri-close-line"></i> Reject
                            </button>
                        </div>
                        <?php else: ?>
                        <span style="font-size:11px;color:var(--neutral-light);"><?= date('M j, Y', strtotime($w['processed_at'] ?? $w['created_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <span class="modal-title" style="color:var(--danger);"><i class="ri-close-circle-line"></i> Reject Withdrawal</span>
            <button class="modal-close" onclick="closeModal('rejectModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <div id="rejectInfo" style="font-size:13px;margin-bottom:14px;color:var(--neutral-dark);"></div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="withdrawal_id" id="rejectWId">

                <div class="form-group">
                    <label class="form-label">Rejection Reason <span class="required">*</span></label>
                    <textarea name="admin_notes" class="form-control" rows="3" placeholder="e.g. Invalid account number, suspicious activity..." required></textarea>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm & Return Funds</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRejectModal(wId, name, amount) {
    document.getElementById('rejectWId').value = wId;
    document.getElementById('rejectInfo').innerHTML = 'Rejecting withdrawal of <strong>' + amount + '</strong> for <strong>' + name + '</strong>. The amount will be refunded to their wallet.';
    openModal('rejectModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
