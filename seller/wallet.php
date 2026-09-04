<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['seller']);
$page_title  = 'My Wallet';
$active_page = 'wallet.php';
$pdo         = getDB();
$uid         = $user['id'];

$success = $error = '';

// Handle withdrawal
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
        $error = 'Please select a withdrawal method and enter account details.';
    } else {
        $bal_before = $current_balance;
        $bal_after  = $bal_before - $amount;

        // Deduct from balance
        $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$amount, $uid]);

        // Insert into withdrawals table
        $pdo->prepare("INSERT INTO withdrawals (user_id, amount, payment_method, account_info, status)
            VALUES (?, ?, ?, ?, 'pending')")
            ->execute([$uid, $amount, $method, $dest]);
        $w_id = $pdo->lastInsertId();

        // Record in wallet transactions
        $pdo->prepare("INSERT INTO wallet_transactions 
            (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
            VALUES (?, 'withdrawal', ?, ?, ?, ?, 'Payout Request via ' + ?, ?, 'pending')")
            ->execute([$uid, $amount, $bal_before, $bal_after, 'WDR-' . $w_id, $method, $method]);

        // Notify SuperAdmin
        $superAdmins = $pdo->query("SELECT id FROM users WHERE role='superadmin'")->fetchAll();
        foreach ($superAdmins as $sa) {
            addNotification($sa['id'], 'New Withdrawal Request', "Seller {$user['name']} requested " . formatCurrency($amount) . " via $method.", 'info', APP_URL . '/superadmin/withdrawals.php');
        }

        addNotification($uid, 'Withdrawal Requested', "Your payout request of " . formatCurrency($amount) . " via $method is being processed by SuperAdmin.", 'info');
        logAudit('WALLET_WITHDRAWAL_REQUEST', "Seller requested payout of $$amount via $method ($dest)", $uid);
        
        $user = getCurrentUser();
        $success = 'Withdrawal request of ' . formatCurrency($amount) . ' submitted successfully! SuperAdmin will process your payout shortly.';
    }
}

// Wallet history
$history = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
$history = $pdo->prepare("
    SELECT wt.*, w.fee AS wd_fee, w.admin_notes AS wd_notes
    FROM wallet_transactions wt
    LEFT JOIN withdrawals w ON wt.reference = CONCAT('WDR-', w.id)
    WHERE wt.user_id = ?
    ORDER BY wt.created_at DESC LIMIT 50
");
$history->execute([$uid]);
$transactions = $history->fetchAll();

// Earnings stats
$total_earned = $pdo->prepare("SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE seller_id=? AND status='released'");
$total_earned->execute([$uid]); $total_earned = (float)$total_earned->fetchColumn();

$total_withdrawn_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id=? AND status='completed'");
$total_withdrawn_stmt->execute([$uid]); $total_withdrawn = (float)$total_withdrawn_stmt->fetchColumn();

$total_fee_stmt = $pdo->prepare("SELECT COALESCE(SUM(fee),0) FROM withdrawals WHERE user_id=? AND status='completed'");
$total_fee_stmt->execute([$uid]); $total_fees_paid = (float)$total_fee_stmt->fetchColumn();
$net_withdrawn = max(0, $total_withdrawn - $total_fees_paid);

$pending_release = $pdo->prepare("SELECT COALESCE(SUM(net_amount),0) FROM transactions WHERE seller_id=? AND status IN ('funded','accepted','shipped','delivered')");
$pending_release->execute([$uid]); $pending_release = (float)$pending_release->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-wallet-3-line" style="color:var(--secondary)"></i> My Wallet</h1>
        <p class="page-subtitle">Manage your earnings and withdrawals</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('withdrawModal')">
        <i class="ri-bank-line"></i> Withdraw Funds
    </button>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px;margin-bottom:24px" class="fade-in stagger">

    <!-- Balance Card -->
    <div class="stat-card" style="background:linear-gradient(135deg,#10C87B,#06a766);color:#fff;border:none;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.07);border-radius:50%;"></div>
        <div class="stat-info" style="position:relative;z-index:1;">
            <div class="stat-label" style="color:rgba(255,255,255,.7)">Available Balance</div>
            <div class="stat-value" style="color:#fff;font-size:28px"><?= formatCurrency((float)$user['balance']) ?></div>
            <button class="btn btn-sm" onclick="openModal('withdrawModal')" style="margin-top:10px;background:rgba(255,255,255,.2);color:#fff;border:none;font-size:12px;">
                <i class="ri-bank-line"></i> Withdraw
            </button>
        </div>
        <div class="stat-icon-wrap" style="background:rgba(255,255,255,.15)"><i class="ri-wallet-3-line" style="color:#fff"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Earned</div>
            <div class="stat-value"><?= formatCurrency($total_earned) ?></div>
            <div class="stat-change up"><i class="ri-arrow-up-line"></i> Released escrows</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-money-dollar-circle-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Net Paid Out</div>
            <div class="stat-value"><?= formatCurrency($net_withdrawn) ?></div>
            <div class="stat-change"><i class="ri-bank-line"></i> Gross: <?= formatCurrency($total_withdrawn) ?></div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-arrow-up-circle-line"></i></div>
    </div>

    <div class="stat-card" style="border-left: 4px solid var(--warning);">
        <div class="stat-info">
            <div class="stat-label">Commission Paid (10%)</div>
            <div class="stat-value" style="color:var(--warning);"><?= formatCurrency($total_fees_paid) ?></div>
            <div class="stat-change down"><i class="ri-percent-line"></i> Platform payout fee</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-percent-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Pending Release</div>
            <div class="stat-value"><?= formatCurrency($pending_release) ?></div>
            <div class="stat-change"><i class="ri-time-line"></i> In active escrows</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-loader-2-line"></i></div>
    </div>
</div>

<!-- Transaction History -->
<div class="card fade-in">
    <div class="card-header">
        <span class="card-title"><i class="ri-history-line" style="color:var(--secondary)"></i> Wallet History</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description & Notes</th>
                    <th>Method</th>
                    <th>Amount & Breakdown</th>
                    <th>Balance After</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                <tr><td colspan="7">
                    <div class="empty-state">
                        <i class="ri-wallet-line"></i>
                        <h3>No wallet transactions yet</h3>
                        <p>Earnings from released escrows appear here</p>
                    </div>
                </td></tr>
                <?php endif; ?>
                <?php foreach ($transactions as $tx): ?>
                <tr>
                    <td style="font-size:12px;color:var(--neutral-light)"><?= date('M j, Y H:i', strtotime($tx['created_at'])) ?></td>
                    <td>
                        <?php
                        $typeMap = [
                            'topup'         => ['badge-success','<i class="ri-arrow-down-line"></i> Top-Up'],
                            'withdrawal'    => ['badge-danger','<i class="ri-arrow-up-line"></i> Withdrawal'],
                            'escrow_debit'  => ['badge-warning','<i class="ri-lock-line"></i> Escrow Debit'],
                            'escrow_credit' => ['badge-info','<i class="ri-unlock-line"></i> Escrow Credit'],
                            'fee'           => ['badge-neutral','<i class="ri-percent-line"></i> Fee'],
                        ];
                        $td = $typeMap[$tx['type']] ?? ['badge-neutral', ucfirst($tx['type'])];
                        echo '<span class="badge '.$td[0].'">'.$td[1].'</span>';
                        ?>
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:13px;color:var(--neutral-dark);"><?= sanitize($tx['description'] ?? '—') ?></div>
                        <?php if ($tx['type'] === 'withdrawal' && !empty($tx['wd_notes'])): ?>
                        <div style="font-size:11px;color:var(--primary);margin-top:2px;background:#f0f4fb;padding:3px 8px;border-radius:6px;display:inline-block;">
                            <i class="ri-information-line"></i> <?= sanitize($tx['wd_notes']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px"><?= sanitize($tx['payment_method'] ?? '—') ?></td>
                    <td>
                        <strong style="color:<?= $tx['type'] === 'withdrawal' ? 'var(--danger)' : 'var(--secondary)' ?>;font-size:14px;">
                            <?= $tx['type'] === 'withdrawal' ? '-' : '+' ?><?= formatCurrency($tx['amount']) ?>
                        </strong>
                        <?php if ($tx['type'] === 'withdrawal' && (float)($tx['wd_fee'] ?? 0) > 0): ?>
                        <div style="font-size:11px;color:var(--warning);font-weight:600;margin-top:2px;">
                            Fee: -<?= formatCurrency((float)$tx['wd_fee']) ?> (10%)
                        </div>
                        <div style="font-size:11px;color:var(--success);font-weight:700;">
                            Net: <?= formatCurrency($tx['amount'] - (float)$tx['wd_fee']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= formatCurrency($tx['balance_after']) ?></strong></td>
                    <td><?= statusBadge($tx['status']) ?></td>
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
            <span class="modal-title"><i class="ri-bank-line"></i> Withdraw Funds</span>
            <button class="modal-close" onclick="closeModal('withdrawModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <div style="background:var(--secondary-light);border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
                <i class="ri-wallet-3-line" style="color:var(--secondary);font-size:20px;"></i>
                <div>
                    <div style="font-size:12px;color:var(--neutral)">Available Balance</div>
                    <div style="font-size:20px;font-weight:800;color:var(--secondary)"><?= formatCurrency((float)$user['balance']) ?></div>
                </div>
            </div>
            <form method="POST" onsubmit="return validateWithdraw()">
                <input type="hidden" name="action" value="withdraw">
                <div class="form-group">
                    <label class="form-label"><i class="ri-money-dollar-circle-line" style="color:var(--secondary)"></i> Withdrawal Amount</label>
                    <div class="input-icon-wrap">
                        <i class="ri-money-dollar-circle-line input-icon"></i>
                        <input type="number" name="amount" id="wdAmount" class="form-control" 
                               placeholder="0.00" min="1" step="0.01" max="<?= (float)$user['balance'] ?>" required>
                    </div>
                    <div class="form-hint">Max: <?= formatCurrency((float)$user['balance']) ?></div>
                    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                        <?php foreach ([10, 25, 50, 100] as $q): ?>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('wdAmount').value=<?= min($q, (float)$user['balance']) ?>">
                            $<?= $q ?>
                        </button>
                        <?php endforeach; ?>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('wdAmount').value=<?= (float)$user['balance'] ?>">
                            All
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="ri-bank-card-line" style="color:var(--secondary)"></i> Withdrawal Method</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <?php
                        $methods = [
                            ['evc',   'ri-phone-line',       'EVC Plus',   'linear-gradient(135deg,#1D3B8B,#3b5bb5)'],
                            ['waafi', 'ri-smartphone-line',  'Waafi',      'linear-gradient(135deg,#10C87B,#06a766)'],
                            ['zaad',  'ri-sim-card-line',    'Zaad',       'linear-gradient(135deg,#f59e0b,#d97706)'],
                            ['bank',  'ri-bank-line',        'Bank Wire',  'linear-gradient(135deg,#3b82f6,#2563eb)'],
                        ];
                        foreach ($methods as [$val, $icon, $label, $grad]):
                        ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="payment_method" value="<?= $val ?>" style="display:none" class="wd-pm-radio">
                            <div class="wd-pm-card" style="display:flex;align-items:center;gap:10px;padding:12px 14px;border:2px solid #e8edf5;border-radius:12px;transition:all .2s;background:#fff;">
                                <div style="width:36px;height:36px;border-radius:10px;background:<?= $grad ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="<?= $icon ?>" style="color:#fff;font-size:18px;"></i>
                                </div>
                                <span style="font-size:13px;font-weight:600;color:var(--neutral-dark)"><?= $label ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="ri-phone-line" style="color:var(--secondary)"></i> Destination (Phone/Account)</label>
                    <div class="input-icon-wrap">
                        <i class="ri-phone-line input-icon"></i>
                        <input type="text" name="destination" class="form-control" placeholder="+252 6X XXX XXXX">
                    </div>
                </div>
                <div class="modal-footer" style="border:none;padding:0;margin-top:4px;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('withdrawModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background:var(--secondary)"><i class="ri-bank-line"></i> Withdraw</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.wd-pm-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.wd-pm-card').forEach(c => {
            c.style.borderColor = '#e8edf5';
            c.style.background = '#fff';
        });
        this.closest('label').querySelector('.wd-pm-card').style.borderColor = 'var(--secondary)';
        this.closest('label').querySelector('.wd-pm-card').style.background = '#f0faf7';
    });
});
document.querySelectorAll('.wd-pm-card').forEach(card => {
    card.addEventListener('click', function() {
        const radio = this.closest('label').querySelector('.wd-pm-radio');
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    });
});
function validateWithdraw() {
    const method = document.querySelector('.wd-pm-radio:checked');
    if (!method) { alert('Please select a withdrawal method.'); return false; }
    const amt = parseFloat(document.getElementById('wdAmount').value);
    if (!amt || amt < 1) { alert('Please enter a valid amount.'); return false; }
    return confirm('Confirm withdrawal of $' + amt.toFixed(2) + '?');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
