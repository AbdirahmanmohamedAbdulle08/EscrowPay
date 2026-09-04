<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/delivery_intelligence.php';
$user        = requireLogin(['delivery']);
$page_title  = 'My Delivery Wallet';
$active_page = 'wallet.php';
$pdo         = getDB();
$uid         = $user['id'];
ensureDeliveryIntelligence($pdo);
$walletTrust = deliveryTrustScore($pdo, $uid);

$success = $error = '';

// Handle withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw') {
    $amount  = (float)($_POST['amount'] ?? 0);
    $method  = sanitize($_POST['payment_method'] ?? '');
    $dest    = sanitize($_POST['destination'] ?? '');
    $current_balance = (float)$user['balance'];

    if ($amount < 1) {
        $error = 'Minimum withdrawal is $1.00';
    } elseif ($amount > $current_balance) {
        $error = 'Insufficient balance. Available: ' . formatCurrency($current_balance);
    } elseif (empty($method) || empty($dest)) {
        $error = 'Please select a payout method and provide account/phone details.';
    } else {
        $bal_before = $current_balance;
        $bal_after  = $bal_before - $amount;

        // Deduct from balance
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$amount, $uid]);

        // Insert into withdrawals table (pending admin approval)
        $pdo->prepare("INSERT INTO withdrawals (user_id, amount, payment_method, account_info, status)
            VALUES (?, ?, ?, ?, 'pending')")
            ->execute([$uid, $amount, $method, $dest]);
        $w_id = $pdo->lastInsertId();

        // Record in wallet transactions
        $pdo->prepare("INSERT INTO wallet_transactions 
            (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
            VALUES (?, 'withdrawal', ?, ?, ?, ?, ?, ?, 'pending')")
            ->execute([$uid, $amount, $bal_before, $bal_after, 'WDR-' . $w_id, "Delivery Earnings Payout via " . strtoupper($method), $method]);

        // Notify SuperAdmin
        $superAdmins = $pdo->query("SELECT id FROM users WHERE role='superadmin'")->fetchAll();
        foreach ($superAdmins as $sa) {
            addNotification(
                $sa['id'],
                'New Delivery Payout Request',
                "Delivery agent {$user['name']} requested " . formatCurrency($amount) . " via " . strtoupper($method) . ". Review under Withdrawals.",
                'info',
                APP_URL . '/superadmin/withdrawals.php'
            );
        }

        addNotification($uid, 'Payout Requested', "Your delivery earnings payout of " . formatCurrency($amount) . " via " . strtoupper($method) . " is being processed by SuperAdmin.", 'info');
        logAudit('DELIVERY_WITHDRAWAL_REQUEST', "Delivery agent requested payout of $$amount via $method ($dest)", $uid);

        $user = getCurrentUser();
        $success = "Payout request of " . formatCurrency($amount) . " submitted successfully! SuperAdmin will transfer your earnings shortly.";
    }
}

// Wallet history
$history = $pdo->prepare("
    SELECT wt.*, w.fee AS wd_fee, w.admin_notes AS wd_notes
    FROM wallet_transactions wt
    LEFT JOIN withdrawals w ON wt.reference = CONCAT('WDR-', w.id)
    WHERE wt.user_id = ?
    ORDER BY wt.created_at DESC LIMIT 50
");
$history->execute([$uid]);
$transactions = $history->fetchAll();

// Stats
$total_earned = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE user_id=? AND type='escrow_credit' AND status='completed'");
$total_earned->execute([$uid]);
$total_earned = (float)$total_earned->fetchColumn();

$total_withdrawn_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id=? AND status='completed'");
$total_withdrawn_stmt->execute([$uid]);
$total_withdrawn = (float)$total_withdrawn_stmt->fetchColumn();

$total_fee_stmt = $pdo->prepare("SELECT COALESCE(SUM(fee),0) FROM withdrawals WHERE user_id=? AND status='completed'");
$total_fee_stmt->execute([$uid]);
$total_fees_paid = (float)$total_fee_stmt->fetchColumn();
$net_withdrawn   = max(0, $total_withdrawn - $total_fees_paid);

$pending_withdrawn = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id=? AND status='pending'");
$pending_withdrawn->execute([$uid]);
$pending_withdrawn = (float)$pending_withdrawn->fetchColumn();

$completed_deliveries = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_id=? AND status='delivered'");
$completed_deliveries->execute([$uid]);
$completed_deliveries = (int)$completed_deliveries->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-wallet-3-line" style="color:var(--secondary)"></i> Delivery Wallet & Earnings</h1>
        <p class="page-subtitle">Track your delivery fees earned and request payouts to Mobile Money or Bank accounts</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('withdrawModal')">
        <i class="ri-bank-line"></i> Withdraw Earnings
    </button>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Stats Overview -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px;margin-bottom:24px" class="fade-in stagger">
    <div class="stat-card" style="border-left:4px solid #f59e0b"><div class="stat-info"><div class="stat-label">Delivery Trust Badge</div><div class="stat-value" style="font-size:22px;color:#d28a00"><?= $walletTrust['score'] ?>%</div><div class="stat-change"><i class="ri-shield-star-line"></i> <?= sanitize($walletTrust['badge']) ?> · <?= $walletTrust['completed'] ?> completed</div></div><div class="stat-icon-wrap stat-icon-warning"><i class="ri-shield-star-line"></i></div></div>
    <!-- Available Balance Card -->
    <div class="stat-card" style="background:linear-gradient(135deg,#10C87B,#06a766);color:#fff;border:none;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.07);border-radius:50%;"></div>
        <div class="stat-info" style="position:relative;z-index:1;">
            <div class="stat-label" style="color:rgba(255,255,255,.7)">Available to Withdraw</div>
            <div class="stat-value" style="color:#fff;font-size:28px"><?= formatCurrency((float)$user['balance']) ?></div>
            <button class="btn btn-sm" onclick="openModal('withdrawModal')" style="margin-top:10px;background:rgba(255,255,255,.2);color:#fff;border:none;font-size:12px;">
                <i class="ri-bank-line"></i> Request Payout
            </button>
        </div>
        <div class="stat-icon-wrap" style="background:rgba(255,255,255,.15)"><i class="ri-wallet-3-line" style="color:#fff"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Delivery Earnings</div>
            <div class="stat-value"><?= formatCurrency($total_earned) ?></div>
            <div class="stat-change up"><i class="ri-truck-line"></i> <?= $completed_deliveries ?> delivered</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-money-dollar-circle-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Net Paid Out</div>
            <div class="stat-value"><?= formatCurrency($net_withdrawn) ?></div>
            <div class="stat-change"><i class="ri-check-line"></i> Gross: <?= formatCurrency($total_withdrawn) ?></div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-arrow-up-circle-line"></i></div>
    </div>

    <div class="stat-card" style="border-left: 4px solid var(--warning);">
        <div class="stat-info">
            <div class="stat-label">Commission Paid (0.2%)</div>
            <div class="stat-value" style="color:var(--warning);"><?= formatCurrency($total_fees_paid) ?></div>
            <div class="stat-change down"><i class="ri-percent-line"></i> Platform payout fee</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-percent-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Pending Payouts</div>
            <div class="stat-value"><?= formatCurrency($pending_withdrawn) ?></div>
            <div class="stat-change"><i class="ri-time-line"></i> Under admin processing</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-hourglass-line"></i></div>
    </div>
</div>

<!-- History -->
<div class="card fade-in">
    <div class="card-header">
        <span class="card-title"><i class="ri-history-line" style="color:var(--secondary)"></i> Delivery Earnings & Payout History</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description & Notes</th>
                    <th>Method / Ref</th>
                    <th>Amount & Breakdown</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="ri-wallet-line"></i>
                            <h3>No wallet activity yet</h3>
                            <p>Complete assigned deliveries to earn delivery fees directly into your wallet.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($transactions as $tx): ?>
                <tr>
                    <td style="font-size:12px;color:var(--neutral-light)"><?= date('M j, Y H:i', strtotime($tx['created_at'])) ?></td>
                    <td>
                        <?php if ($tx['type'] === 'escrow_credit'): ?>
                        <span class="badge badge-success"><i class="ri-arrow-down-line"></i> Delivery Fee</span>
                        <?php elseif ($tx['type'] === 'withdrawal'): ?>
                        <span class="badge badge-danger"><i class="ri-arrow-up-line"></i> Payout</span>
                        <?php else: ?>
                        <span class="badge badge-neutral"><?= ucfirst($tx['type']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:13px;color:var(--neutral-dark);"><?= sanitize($tx['description'] ?? '—') ?></div>
                        <?php if ($tx['type'] === 'withdrawal' && !empty($tx['wd_notes'])): ?>
                        <div style="font-size:11px;color:var(--primary);margin-top:2px;background:#f0f4fb;padding:3px 8px;border-radius:6px;display:inline-block;">
                            <i class="ri-information-line"></i> <?= sanitize($tx['wd_notes']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong style="font-size:12px;text-transform:uppercase;"><?= sanitize($tx['payment_method'] ?? '—') ?></strong>
                        <div style="font-size:10px;color:var(--neutral-light);"><?= sanitize($tx['reference']) ?></div>
                    </td>
                    <td>
                        <strong style="color:<?= $tx['type'] === 'withdrawal' ? 'var(--danger)' : 'var(--secondary)' ?>;font-size:14px;">
                            <?= $tx['type'] === 'withdrawal' ? '-' : '+' ?><?= formatCurrency($tx['amount']) ?>
                        </strong>
                        <?php if ($tx['type'] === 'withdrawal' && (float)($tx['wd_fee'] ?? 0) > 0): ?>
                        <div style="font-size:11px;color:var(--warning);font-weight:600;margin-top:2px;">
                            Fee: -<?= formatCurrency((float)$tx['wd_fee']) ?> (0.2%)
                        </div>
                        <div style="font-size:11px;color:var(--success);font-weight:700;">
                            Net: <?= formatCurrency($tx['amount'] - (float)$tx['wd_fee']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($tx['status'] === 'pending'): ?>
                        <span class="badge badge-warning"><i class="ri-hourglass-line"></i> Admin Processing</span>
                        <?php elseif ($tx['status'] === 'completed'): ?>
                        <span class="badge badge-success"><i class="ri-check-line"></i> Completed</span>
                        <?php else: ?>
                        <span class="badge badge-danger"><?= ucfirst($tx['status']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Withdrawal Modal -->
<div class="modal-overlay" id="withdrawModal">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-bank-line"></i> Withdraw Delivery Earnings</span>
            <button class="modal-close" onclick="closeModal('withdrawModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <div style="background:var(--secondary-light);border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
                <i class="ri-wallet-3-line" style="color:var(--secondary);font-size:20px;"></i>
                <div>
                    <div style="font-size:12px;color:var(--neutral)">Available Earnings</div>
                    <div style="font-size:20px;font-weight:800;color:var(--secondary)"><?= formatCurrency((float)$user['balance']) ?></div>
                </div>
            </div>

            <form method="POST" onsubmit="return validateDeliveryWithdraw()">
                <input type="hidden" name="action" value="withdraw">

                <div class="form-group">
                    <label class="form-label"><i class="ri-money-dollar-circle-line" style="color:var(--secondary)"></i> Withdrawal Amount ($ USD)</label>
                    <div class="input-icon-wrap">
                        <i class="ri-money-dollar-circle-line input-icon"></i>
                        <input type="number" name="amount" id="delivWdAmount" class="form-control" 
                               placeholder="0.00" min="1" step="0.01" max="<?= (float)$user['balance'] ?>" required>
                    </div>
                    <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('delivWdAmount').value=<?= min(15, (float)$user['balance']) ?>" style="font-size:11px;">$15</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('delivWdAmount').value=<?= min(50, (float)$user['balance']) ?>" style="font-size:11px;">$50</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('delivWdAmount').value=<?= min(100, (float)$user['balance']) ?>" style="font-size:11px;">$100</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('delivWdAmount').value=<?= (float)$user['balance'] ?>" style="font-size:11px;">All Balance</button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="ri-bank-card-line" style="color:var(--secondary)"></i> Payout Method</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <?php
                        $methods = [
                            ['evc',   'ri-phone-line',       'EVC Plus',   'linear-gradient(135deg,#1D3B8B,#3b5bb5)'],
                            ['waafi', 'ri-smartphone-line',  'Waafi Pay',  'linear-gradient(135deg,#10C87B,#06a766)'],
                            ['zaad',  'ri-sim-card-line',    'Zaad',       'linear-gradient(135deg,#f59e0b,#d97706)'],
                            ['bank',  'ri-bank-line',        'Bank Wire',  'linear-gradient(135deg,#3b82f6,#2563eb)'],
                        ];
                        foreach ($methods as [$val, $icon, $label, $grad]):
                        ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="payment_method" value="<?= $val ?>" style="display:none" class="deliv-pm-radio">
                            <div class="deliv-pm-card" style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:2px solid #e8edf5;border-radius:12px;transition:all .2s;background:#fff;">
                                <div style="width:32px;height:32px;border-radius:8px;background:<?= $grad ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="<?= $icon ?>" style="color:#fff;font-size:16px;"></i>
                                </div>
                                <span style="font-size:12px;font-weight:700;color:var(--neutral-dark)"><?= $label ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="ri-phone-line" style="color:var(--secondary)"></i> Mobile Number / Account Details</label>
                    <div class="input-icon-wrap">
                        <i class="ri-phone-line input-icon"></i>
                        <input type="text" name="destination" class="form-control" placeholder="+252 61 XXX XXXX or Account #" required value="<?= sanitize($user['phone'] ?? '') ?>">
                    </div>
                </div>

                <div class="modal-footer" style="border:none;padding:0;margin-top:8px;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('withdrawModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background:var(--secondary)"><i class="ri-bank-line"></i> Submit Withdrawal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.deliv-pm-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.deliv-pm-card').forEach(c => {
            c.style.borderColor = '#e8edf5';
            c.style.background = '#fff';
        });
        this.closest('label').querySelector('.deliv-pm-card').style.borderColor = 'var(--secondary)';
        this.closest('label').querySelector('.deliv-pm-card').style.background = '#f0faf7';
    });
});
document.querySelectorAll('.deliv-pm-card').forEach(card => {
    card.addEventListener('click', function() {
        const radio = this.closest('label').querySelector('.deliv-pm-radio');
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    });
});
function validateDeliveryWithdraw() {
    const method = document.querySelector('.deliv-pm-radio:checked');
    if (!method) { alert('Please select a payout method.'); return false; }
    const amt = parseFloat(document.getElementById('delivWdAmount').value);
    if (!amt || amt < 1) { alert('Please enter a valid amount.'); return false; }
    return confirm('Confirm payout request of $' + amt.toFixed(2) + '?');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
