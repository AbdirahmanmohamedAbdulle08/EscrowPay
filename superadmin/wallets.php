<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Wallet & Deposit Verifications';
$active_page = 'wallets.php';
$pdo         = getDB();

$success = $error = '';

// Handle Deposit Verifications & Balance Adjustments
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Approve Deposit with Screenshot
    if ($action === 'approve_deposit') {
        $tx_id = (int)($_POST['tx_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT wt.*, u.name AS user_name, u.email AS user_email FROM wallet_transactions wt JOIN users u ON wt.user_id=u.id WHERE wt.id=? AND wt.status='pending'");
        $stmt->execute([$tx_id]);
        $tx = $stmt->fetch();

        if ($tx) {
            $amount = (float)$tx['amount'];
            $uid    = (int)$tx['user_id'];

            // Credit user balance
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$amount, $uid]);
            
            // Mark completed
            $pdo->prepare("UPDATE wallet_transactions SET status='completed', balance_after = (SELECT balance FROM users WHERE id=?) WHERE id=?")
                ->execute([$uid, $tx_id]);

            addNotification($uid, 'Deposit Confirmed! 🎉', "Your deposit of " . formatCurrency($amount) . " via " . strtoupper($tx['payment_method']) . " has been verified and added to your wallet!", 'success', APP_URL . '/buyer/wallet.php');
            logAudit('APPROVE_DEPOSIT', "SuperAdmin approved deposit #$tx_id for {$tx['user_name']} ($$amount)", $user['id']);
            $success = "Deposit #$tx_id approved! " . formatCurrency($amount) . " added to {$tx['user_name']}'s wallet.";
        } else {
            $error = 'Deposit record not found or already processed.';
        }
    }

    // 2. Reject Deposit
    if ($action === 'reject_deposit') {
        $tx_id = (int)($_POST['tx_id'] ?? 0);
        $note  = trim(sanitize($_POST['admin_notes'] ?? 'Screenshot could not be verified'));

        $stmt = $pdo->prepare("SELECT wt.*, u.name AS user_name FROM wallet_transactions wt JOIN users u ON wt.user_id=u.id WHERE wt.id=? AND wt.status='pending'");
        $stmt->execute([$tx_id]);
        $tx = $stmt->fetch();

        if ($tx) {
            $pdo->prepare("UPDATE wallet_transactions SET status='failed', description = CONCAT(description, ' [REJECTED: ', ?, ']') WHERE id=?")
                ->execute([$note, $tx_id]);

            addNotification($tx['user_id'], 'Deposit Rejected', "Your deposit of " . formatCurrency($tx['amount']) . " was not approved. Reason: $note", 'danger', APP_URL . '/buyer/wallet.php');
            logAudit('REJECT_DEPOSIT', "SuperAdmin rejected deposit #$tx_id for {$tx['user_name']} ($note)", $user['id']);
            $success = "Deposit #$tx_id rejected.";
        } else {
            $error = 'Deposit record not found.';
        }
    }

    // 3. Manual Balance Adjustment
    if ($action === 'adjust_balance') {
        $target_uid = (int)($_POST['target_uid'] ?? 0);
        $amount     = (float)($_POST['amount'] ?? 0);
        $adj_type   = ($_POST['adj_type'] ?? 'add') === 'add' ? 'topup' : 'withdrawal';
        $note       = sanitize($_POST['note'] ?? 'Admin adjustment');

        if ($target_uid && abs($amount) > 0) {
            $t = $pdo->prepare("SELECT id, balance FROM users WHERE id=?");
            $t->execute([$target_uid]);
            $tuser = $t->fetch();
            if ($tuser) {
                $bal_before = (float)$tuser['balance'];
                if ($adj_type === 'topup') {
                    $bal_after = $bal_before + abs($amount);
                    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([abs($amount), $target_uid]);
                } else {
                    $bal_after = max(0, $bal_before - abs($amount));
                    $pdo->prepare("UPDATE users SET balance = ? WHERE id=?")->execute([$bal_after, $target_uid]);
                }
                $pdo->prepare("INSERT INTO wallet_transactions 
                    (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'admin', 'completed')")
                    ->execute([$target_uid, $adj_type, abs($amount), $bal_before, $bal_after, 'ADM-'.strtoupper(substr(md5(uniqid()),0,6)), $note]);
                addNotification($target_uid, 'Balance Adjusted', "Admin has adjusted your balance. Note: $note", 'info');
                logAudit('ADMIN_BALANCE_ADJUST', "Adjusted user #$target_uid balance by $amount ($adj_type). Note: $note", $user['id']);
                $success = "Balance adjusted successfully for user #$target_uid.";
            }
        } else {
            $error = 'Invalid data.';
        }
    }
}

// Pending Deposit Verifications
$pending_deposits = $pdo->query("
    SELECT wt.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, u.role AS user_role, u.balance AS current_balance
    FROM wallet_transactions wt
    JOIN users u ON wt.user_id = u.id
    WHERE wt.type = 'topup' AND wt.status = 'pending'
    ORDER BY wt.created_at DESC
")->fetchAll();

// All wallet summary (aggregate per user)
$wallets = $pdo->query("
    SELECT u.id, u.name, u.email, u.role, u.balance, u.status,
           COALESCE(SUM(CASE WHEN wt.type='topup' AND wt.status='completed' THEN wt.amount ELSE 0 END), 0) AS total_topups,
           COALESCE(SUM(CASE WHEN wt.type='withdrawal' AND wt.status='completed' THEN wt.amount ELSE 0 END), 0) AS total_withdrawals,
           COUNT(wt.id) AS tx_count
    FROM users u
    LEFT JOIN wallet_transactions wt ON wt.user_id = u.id
    WHERE u.role != 'superadmin'
    GROUP BY u.id
    ORDER BY u.balance DESC
")->fetchAll();

// Platform totals
$platform_balance = $pdo->query("SELECT COALESCE(SUM(balance),0) FROM users WHERE role!='superadmin'")->fetchColumn();
$platform_topups  = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='topup' AND status='completed'")->fetchColumn();
$platform_wd      = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='withdrawal' AND status='completed'")->fetchColumn();
$revenue          = $pdo->query("SELECT COALESCE(SUM(fee),0) FROM transactions WHERE status='released'")->fetchColumn();

// Recent wallet transactions
$recent_wt = $pdo->query("
    SELECT wt.*, u.name AS user_name, u.role AS user_role
    FROM wallet_transactions wt
    JOIN users u ON wt.user_id = u.id
    ORDER BY wt.created_at DESC LIMIT 20
")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-wallet-3-line" style="color:var(--primary)"></i> Wallet & Payment Gateway Verifications</h1>
        <p class="page-subtitle">Verify user payment screenshots, credit wallet deposits, and oversee platform balances</p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Pending Deposits Approval Box -->
<?php if (!empty($pending_deposits)): ?>
<div class="card fade-in" style="margin-bottom:24px;border:2px solid #fbbf24;border-radius:16px;overflow:hidden;">
    <div class="card-header" style="background:#fffbeb;padding:16px 20px;">
        <span class="card-title" style="color:#b45309;">
            <i class="ri-image-line" style="color:#b45309;"></i> Pending Deposit Screenshot Verifications (<?= count($pending_deposits) ?>)
        </span>
        <span style="font-size:12px;color:#92400e;font-weight:600;">Confirm transfer receipt to credit user wallet</span>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>User</th>
                    <th>Gateway</th>
                    <th>Reference / Sender</th>
                    <th>Amount</th>
                    <th>Screenshot Receipt</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_deposits as $pd): ?>
                <tr>
                    <td style="font-size:12px;color:var(--neutral-light);"><?= date('M j, Y H:i', strtotime($pd['created_at'])) ?></td>
                    <td>
                        <strong style="color:var(--neutral-dark);font-size:13px;"><?= sanitize($pd['user_name']) ?></strong>
                        <div style="font-size:11px;color:var(--neutral-light);"><?= sanitize($pd['user_email']) ?> (Current Bal: <?= formatCurrency($pd['current_balance']) ?>)</div>
                    </td>
                    <td>
                        <span class="badge badge-info" style="font-size:10px;text-transform:uppercase;">
                            <?= sanitize($pd['payment_method']) ?>
                        </span>
                    </td>
                    <td><strong style="font-size:12px;"><?= sanitize($pd['reference']) ?></strong></td>
                    <td><strong style="color:var(--secondary);font-size:15px;"><?= formatCurrency($pd['amount']) ?></strong></td>
                    <td>
                        <?php if (!empty($pd['proof_image'])): ?>
                        <a href="<?= APP_URL ?>/uploads/receipts/<?= sanitize($pd['proof_image']) ?>" target="_blank" class="btn btn-primary btn-sm" style="padding:4px 10px;font-size:11px;">
                            <i class="ri-eye-line"></i> View Receipt
                        </a>
                        <?php else: ?>
                        <span style="font-size:11px;color:var(--neutral-light);">No image uploaded</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <!-- Approve Deposit -->
                            <form method="POST" style="display:inline" onsubmit="return confirm('Verify screenshot and credit <?= formatCurrency($pd['amount']) ?> to <?= sanitize($pd['user_name']) ?>?')">
                                <input type="hidden" name="action" value="approve_deposit">
                                <input type="hidden" name="tx_id" value="<?= $pd['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="background:var(--secondary-light);color:var(--secondary-dark);padding:5px 12px;font-size:11px;font-weight:700;">
                                    <i class="ri-check-line"></i> Verify & Credit
                                </button>
                            </form>

                            <!-- Reject Deposit -->
                            <form method="POST" style="display:inline" onsubmit="return confirm('Reject this deposit request?')">
                                <input type="hidden" name="action" value="reject_deposit">
                                <input type="hidden" name="tx_id" value="<?= $pd['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:5px 8px;font-size:11px;">
                                    <i class="ri-close-line"></i> Reject
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Platform Wallet Stats -->
<div class="stats-grid stagger fade-in" style="margin-bottom:24px">
    <div class="stat-card" style="background:linear-gradient(135deg,#1D3B8B,#3b5bb5);color:#fff;border:none;">
        <div class="stat-info">
            <div class="stat-label" style="color:rgba(255,255,255,.7)">Total Platform Balances</div>
            <div class="stat-value" style="color:#fff"><?= formatCurrency((float)$platform_balance) ?></div>
            <div style="font-size:11px;color:rgba(255,255,255,.6);margin-top:4px">Sum of all user wallets</div>
        </div>
        <div class="stat-icon-wrap" style="background:rgba(255,255,255,.15)"><i class="ri-wallet-3-line" style="color:#fff"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Verified Top-Ups</div>
            <div class="stat-value"><?= formatCurrency((float)$platform_topups) ?></div>
            <div class="stat-change up"><i class="ri-arrow-down-line"></i> Deposited</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-arrow-down-circle-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Withdrawals</div>
            <div class="stat-value"><?= formatCurrency((float)$platform_wd) ?></div>
            <div class="stat-change down"><i class="ri-arrow-up-line"></i> Withdrawn</div>
        </div>
        <div class="stat-icon-wrap stat-icon-danger"><i class="ri-arrow-up-circle-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Platform Revenue (10% Fees)</div>
            <div class="stat-value"><?= formatCurrency((float)$revenue) ?></div>
            <div class="stat-change up"><i class="ri-percent-line"></i> From released escrows</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-money-dollar-circle-line"></i></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:3fr 2fr;gap:20px" class="fade-in">
    <!-- User Wallets Table -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-group-line" style="color:var(--primary)"></i> User Wallets</span>
            <span style="font-size:12px;color:var(--neutral-light)"><?= count($wallets) ?> users</span>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Balance</th>
                        <th>Top-Ups</th>
                        <th>Withdrawals</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wallets as $w): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px">
                                <div class="avatar-placeholder" style="width:32px;height:32px;font-size:12px"><?= strtoupper(substr($w['name'],0,1)) ?></div>
                                <div>
                                    <div style="font-size:13px;font-weight:600"><?= sanitize($w['name']) ?></div>
                                    <div style="font-size:11px;color:var(--neutral-light)"><?= sanitize($w['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="role-tag role-<?= $w['role'] ?>"><?= ucfirst($w['role']) ?></span></td>
                        <td>
                            <strong style="color:<?= $w['balance'] > 0 ? 'var(--secondary)' : 'var(--neutral)' ?>">
                                <?= formatCurrency($w['balance']) ?>
                            </strong>
                        </td>
                        <td style="font-size:12px;color:var(--secondary)">+<?= formatCurrency($w['total_topups']) ?></td>
                        <td style="font-size:12px;color:var(--danger)">-<?= formatCurrency($w['total_withdrawals']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-ghost" onclick="openAdjustModal(<?= $w['id'] ?>, '<?= addslashes($w['name']) ?>', <?= $w['balance'] ?>)"
                                    style="font-size:11px;padding:4px 10px">
                                <i class="ri-edit-line"></i> Adjust
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Wallet Transactions -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-history-line" style="color:var(--primary)"></i> Recent Activity</span>
        </div>
        <div style="max-height:550px;overflow-y:auto">
            <?php if (empty($recent_wt)): ?>
            <div class="empty-state" style="padding:30px"><i class="ri-wallet-line"></i><h3>No wallet activity</h3></div>
            <?php endif; ?>
            <?php foreach ($recent_wt as $wt): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #f5f8fd">
                <div style="width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
                    background:<?= in_array($wt['type'],['topup','escrow_credit']) ? 'var(--secondary-light)' : 'var(--danger-light)' ?>">
                    <i class="<?= in_array($wt['type'],['topup','escrow_credit']) ? 'ri-arrow-down-line' : 'ri-arrow-up-line' ?>"
                       style="color:<?= in_array($wt['type'],['topup','escrow_credit']) ? 'var(--secondary)' : 'var(--danger)' ?>;font-size:16px"></i>
                </div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:12px;font-weight:600;color:var(--neutral-dark)"><?= sanitize($wt['user_name']) ?></div>
                    <div style="font-size:11px;color:var(--neutral-light);"><?= sanitize($wt['description'] ?? ucfirst($wt['type'])) ?></div>
                    <div style="font-size:10px;color:var(--neutral-light);"><?= timeAgo($wt['created_at']) ?></div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:13px;font-weight:700;color:<?= in_array($wt['type'],['topup','escrow_credit']) ? 'var(--secondary)' : 'var(--danger)' ?>">
                        <?= in_array($wt['type'],['topup','escrow_credit']) ? '+' : '-' ?><?= formatCurrency($wt['amount']) ?>
                    </div>
                    <span class="role-tag role-<?= $wt['user_role'] ?>" style="font-size:9px"><?= ucfirst($wt['user_role']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Balance Adjust Modal -->
<div class="modal-overlay" id="adjustModal">
    <div class="modal" style="max-width:400px">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-edit-line"></i> Adjust Balance</span>
            <button class="modal-close" onclick="closeModal('adjustModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <div id="adjustUserInfo" style="background:var(--tertiary);border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:var(--neutral-dark)"></div>
            <form method="POST">
                <input type="hidden" name="action" value="adjust_balance">
                <input type="hidden" name="target_uid" id="adjustUid">
                <div class="form-group">
                    <label class="form-label">Adjustment Type</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                        <label style="cursor:pointer">
                            <input type="radio" name="adj_type" value="add" checked style="display:none" class="adj-radio">
                            <div class="adj-card" style="display:flex;align-items:center;gap:8px;padding:12px;border:2px solid #e8edf5;border-radius:12px;background:#fff;transition:all .2s">
                                <i class="ri-add-circle-line" style="color:var(--secondary);font-size:20px"></i>
                                <span style="font-size:13px;font-weight:600">Add Funds</span>
                            </div>
                        </label>
                        <label style="cursor:pointer">
                            <input type="radio" name="adj_type" value="sub" style="display:none" class="adj-radio">
                            <div class="adj-card" style="display:flex;align-items:center;gap:8px;padding:12px;border:2px solid #e8edf5;border-radius:12px;background:#fff;transition:all .2s">
                                <i class="ri-subtract-line" style="color:var(--danger);font-size:20px"></i>
                                <span style="font-size:13px;font-weight:600">Deduct</span>
                            </div>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount</label>
                    <div class="input-icon-wrap">
                        <i class="ri-money-dollar-circle-line input-icon"></i>
                        <input type="number" name="amount" class="form-control" placeholder="0.00" min="0.01" step="0.01" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason / Note</label>
                    <input type="text" name="note" class="form-control" placeholder="e.g. Refund, Bonus, Correction">
                </div>
                <div class="modal-footer" style="border:none;padding:0;margin-top:8px">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('adjustModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Confirm balance adjustment?')">
                        <i class="ri-check-line"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAdjustModal(uid, name, balance) {
    document.getElementById('adjustUid').value = uid;
    document.getElementById('adjustUserInfo').innerHTML = 
        '<strong>' + name + '</strong><br>' +
        '<span style="color:var(--neutral-light)">Current Balance: </span>' +
        '<strong style="color:var(--primary)">$' + parseFloat(balance).toFixed(2) + '</strong>';
    openModal('adjustModal');
}

document.querySelectorAll('.adj-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.adj-card').forEach(c => {
            c.style.borderColor = '#e8edf5';
            c.style.background = '#fff';
        });
        const card = this.closest('label').querySelector('.adj-card');
        card.style.borderColor = this.value === 'add' ? 'var(--secondary)' : 'var(--danger)';
        card.style.background = this.value === 'add' ? '#f0faf7' : '#fef2f2';
    });
});
document.querySelectorAll('.adj-card').forEach(card => {
    card.addEventListener('click', function() {
        const radio = this.closest('label').querySelector('.adj-radio');
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    });
});
document.querySelector('.adj-radio:checked')?.dispatchEvent(new Event('change'));
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
