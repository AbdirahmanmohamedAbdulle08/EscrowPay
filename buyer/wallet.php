<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['buyer']);
$page_title  = 'My Wallet';
$active_page = 'wallet.php';
$pdo         = getDB();
$uid         = $user['id'];

$success = $error = '';

// Handle Deposit / Top-up request with payment proof screenshot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'topup_request') {
    $amount  = (float)($_POST['amount'] ?? 0);
    $method  = sanitize($_POST['payment_method'] ?? '');
    $ref     = trim(sanitize($_POST['payment_ref'] ?? ''));

    if ($amount < 1) {
        $error = 'Minimum top-up amount is $1.00';
    } elseif (empty($method)) {
        $error = 'Please select a payment method.';
    } else {
        $proof_path = null;
        
        // Handle Screenshot Upload
        if (!empty($_FILES['proof_image']['name']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed)) {
                $dir = __DIR__ . '/../uploads/receipts/';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                
                $filename = 'receipt_' . $uid . '_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $dir . $filename)) {
                    $proof_path = $filename;
                }
            } else {
                $error = 'Invalid screenshot format. Allowed: JPG, PNG, WEBP.';
            }
        }

        if (empty($error)) {
            $ref_code = $ref ?: 'DEP-' . strtoupper(substr(md5(uniqid()), 0, 8));
            $bal_current = (float)$user['balance'];

            // Insert as PENDING for admin verification
            $pdo->prepare("
                INSERT INTO wallet_transactions 
                (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, proof_image, status)
                VALUES (?, 'topup', ?, ?, ?, ?, ?, ?, ?, 'pending')
            ")->execute([
                $uid,
                $amount,
                $bal_current,
                $bal_current,
                $ref_code,
                "Deposit via " . strtoupper($method) . " (Awaiting Admin Confirmation)",
                $method,
                $proof_path
            ]);

            // Notify SuperAdmin
            $superAdmins = $pdo->query("SELECT id FROM users WHERE role='superadmin'")->fetchAll();
            foreach ($superAdmins as $sa) {
                addNotification(
                    $sa['id'],
                    'New Deposit Verification Required',
                    "Buyer {$user['name']} submitted a deposit of " . formatCurrency($amount) . " via " . strtoupper($method) . ". Verify receipt under Wallets.",
                    'info',
                    APP_URL . '/superadmin/wallets.php'
                );
            }

            logAudit('WALLET_DEPOSIT_REQUEST', "Buyer requested deposit of $$amount via $method (Ref: $ref_code)", $uid);
            $success = "Deposit request for " . formatCurrency($amount) . " submitted! Our Admin team will verify your screenshot and credit your wallet shortly.";
        }
    }
}

// Fetch wallet transaction history
$history = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
$history->execute([$uid]);
$transactions = $history->fetchAll();

// Pending deposit check
$pending_deposits = $pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id=? AND type='topup' AND status='pending' ORDER BY created_at DESC");
$pending_deposits->execute([$uid]);
$pending_list = $pending_deposits->fetchAll();

// Stats
$total_topups = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE user_id=? AND type='topup' AND status='completed'");
$total_topups->execute([$uid]);
$total_topups = (float)$total_topups->fetchColumn();

$total_spent_escrow = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE buyer_id=? AND status NOT IN ('cancelled','pending')");
$total_spent_escrow->execute([$uid]);
$total_spent_escrow = (float)$total_spent_escrow->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-wallet-3-line" style="color:var(--primary)"></i> My Wallet</h1>
        <p class="page-subtitle">Deposit funds via Payment Gateways (EVC Plus, Waafi, Zaad, Sahal, Card) to use on Escrow Marketplace</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('topupModal')">
        <i class="ri-add-circle-line"></i> Top Up Balance
    </button>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<?php if (!empty($pending_list)): ?>
<div class="card fade-in" style="background:#fffbeb;border:1px solid #fde68a;margin-bottom:24px;border-radius:14px;">
    <div class="card-body" style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;color:#b45309;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">
                <i class="ri-time-line"></i>
            </div>
            <div>
                <strong style="color:#92400e;font-size:14px;">Deposit Under Admin Review</strong>
                <div style="font-size:12px;color:#b45309;margin-top:2px;">
                    You have <?= count($pending_list) ?> deposit request(s) awaiting admin screenshot confirmation. Total: <strong><?= formatCurrency(array_sum(array_column($pending_list, 'amount'))) ?></strong>
                </div>
            </div>
        </div>
        <span class="badge badge-warning" style="font-size:11px;"><i class="ri-hourglass-line"></i> Processing</span>
    </div>
</div>
<?php endif; ?>

<!-- Wallet Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-bottom:24px" class="fade-in stagger">
    <!-- Balance Card -->
    <div class="stat-card" style="background:linear-gradient(135deg,#1D3B8B,#3b5bb5);color:#fff;border:none;position:relative;overflow:hidden;">
        <div style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.07);border-radius:50%;"></div>
        <div class="stat-info" style="position:relative;z-index:1;">
            <div class="stat-label" style="color:rgba(255,255,255,.7)">Available Balance</div>
            <div class="stat-value" style="color:#fff;font-size:30px"><?= formatCurrency((float)$user['balance']) ?></div>
            <div style="margin-top:10px">
                <button class="btn btn-sm" onclick="openModal('topupModal')" style="background:rgba(255,255,255,.2);color:#fff;border:none;font-size:12px;">
                    <i class="ri-add-line"></i> Top Up Balance
                </button>
            </div>
        </div>
        <div class="stat-icon-wrap" style="background:rgba(255,255,255,.15)"><i class="ri-wallet-3-line" style="color:#fff"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Verified Top-Ups</div>
            <div class="stat-value"><?= formatCurrency($total_topups) ?></div>
            <div class="stat-change up"><i class="ri-arrow-up-line"></i> All time confirmed</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-arrow-down-circle-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Used in Escrow</div>
            <div class="stat-value"><?= formatCurrency($total_spent_escrow) ?></div>
            <div class="stat-change"><i class="ri-secure-payment-line"></i> Total funded</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-secure-payment-line"></i></div>
    </div>
</div>

<!-- Transaction History -->
<div class="card fade-in">
    <div class="card-header">
        <span class="card-title"><i class="ri-history-line" style="color:var(--primary)"></i> Wallet History & Deposit Receipts</span>
        <button class="btn btn-ghost btn-sm" onclick="openModal('topupModal')"><i class="ri-add-line"></i> New Deposit</button>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Method / Ref</th>
                    <th>Amount</th>
                    <th>Receipt</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                <tr><td colspan="7">
                    <div class="empty-state">
                        <i class="ri-wallet-line"></i>
                        <h3>No transactions yet</h3>
                        <p>Top up your wallet to buy on the marketplace</p>
                        <button class="btn btn-primary btn-sm" onclick="openModal('topupModal')" style="margin-top:12px">
                            <i class="ri-add-circle-line"></i> Top Up Now
                        </button>
                    </div>
                </td></tr>
                <?php endif; ?>

                <?php foreach ($transactions as $tx): ?>
                <tr>
                    <td style="font-size:12px;color:var(--neutral-light);"><?= date('M j, Y H:i', strtotime($tx['created_at'])) ?></td>
                    <td>
                        <?php
                        $typeMap = [
                            'topup'         => ['badge-success','<i class="ri-arrow-down-line"></i> Deposit'],
                            'withdrawal'    => ['badge-danger','<i class="ri-arrow-up-line"></i> Withdrawal'],
                            'escrow_debit'  => ['badge-warning','<i class="ri-lock-line"></i> Escrow Hold'],
                            'escrow_credit' => ['badge-info','<i class="ri-unlock-line"></i> Escrow Credit'],
                            'fee'           => ['badge-neutral','<i class="ri-percent-line"></i> Fee'],
                        ];
                        $td = $typeMap[$tx['type']] ?? ['badge-neutral', ucfirst($tx['type'])];
                        echo '<span class="badge '.$td[0].'">'.$td[1].'</span>';
                        ?>
                    </td>
                    <td><?= sanitize($tx['description'] ?? '—') ?></td>
                    <td>
                        <strong style="font-size:12px;text-transform:uppercase;"><?= sanitize($tx['payment_method'] ?? 'Wallet') ?></strong>
                        <div style="font-size:10px;color:var(--neutral-light);"><?= sanitize($tx['reference']) ?></div>
                    </td>
                    <td>
                        <strong style="color:<?= in_array($tx['type'],['topup','escrow_credit']) ? 'var(--secondary)' : 'var(--danger)' ?>">
                            <?= in_array($tx['type'],['topup','escrow_credit']) ? '+' : '-' ?><?= formatCurrency($tx['amount']) ?>
                        </strong>
                    </td>
                    <td>
                        <?php if (!empty($tx['proof_image'])): ?>
                        <a href="<?= APP_URL ?>/uploads/receipts/<?= sanitize($tx['proof_image']) ?>" target="_blank" class="btn btn-ghost btn-sm" style="padding:2px 8px;font-size:11px;">
                            <i class="ri-image-line"></i> View Proof
                        </a>
                        <?php else: ?>
                        <span style="font-size:11px;color:var(--neutral-light);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($tx['status'] === 'pending'): ?>
                        <span class="badge badge-warning"><i class="ri-hourglass-line"></i> Pending Verification</span>
                        <?php elseif ($tx['status'] === 'completed'): ?>
                        <span class="badge badge-success"><i class="ri-check-line"></i> Confirmed</span>
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

<!-- Top-Up with Payment Gateways & Screenshot Modal -->
<div class="modal-overlay" id="topupModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-bank-card-line" style="color:var(--primary);"></i> Deposit via Payment Gateway</span>
            <button class="modal-close" onclick="closeModal('topupModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" id="depositForm">
                <input type="hidden" name="action" value="topup_request">

                <!-- 1. Select Payment Method -->
                <div class="form-group">
                    <label class="form-label" style="font-weight:700;">1. Select Payment Method</label>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
                        <?php
                        $gateways = [
                            ['evc',   'ri-phone-line',        'EVC Plus',    '#1D3B8B', '*712*618889900*Amount# / +252 61 888 9900'],
                            ['waafi', 'ri-smartphone-line',   'Waafi Pay',   '#10C87B', '*880*617778800*Amount# / +252 61 777 8800'],
                            ['zaad',  'ri-sim-card-line',     'Zaad',        '#f59e0b', '*220*4445500*Amount# / +252 63 444 5500'],
                            ['sahal', 'ri-cellphone-line',    'Sahal',       '#ec4899', '*789*2223300*Amount# / +252 90 222 3300'],
                            ['bank',  'ri-bank-line',         'Bank Wire',   '#3b82f6', 'Premier Bank A/C: 1002348892 (EscrowPay Ltd)'],
                            ['card',  'ri-bank-card-2-line',  'Debit Card',  '#8b5cf6', 'Online Card Terminal (Merchant: EscrowPay)'],
                        ];
                        foreach ($gateways as $idx => [$val, $icon, $label, $color, $instructions]):
                        ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="payment_method" value="<?= $val ?>" <?= $idx === 0 ? 'checked' : '' ?> style="display:none" class="topup-pm-radio" data-instructions="<?= htmlspecialchars($instructions) ?>" data-label="<?= $label ?>">
                            <div class="topup-pm-card" style="padding:10px 8px;border:2px solid <?= $idx === 0 ? 'var(--primary)' : '#e8edf5' ?>;border-radius:12px;text-align:center;background:<?= $idx === 0 ? '#f0f5ff' : '#fff' ?>;transition:all .2s;">
                                <div style="width:34px;height:34px;border-radius:8px;background:<?= $color ?>;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 6px;">
                                    <i class="<?= $icon ?>" style="font-size:18px;"></i>
                                </div>
                                <div style="font-size:11px;font-weight:700;color:var(--neutral-dark);"><?= $label ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 2. Gateway Transfer Instructions Box -->
                <div style="background:#f0f5ff;border:1px solid #c7dcff;border-radius:12px;padding:12px 16px;margin-bottom:16px;">
                    <div style="font-size:11px;color:var(--primary);font-weight:700;text-transform:uppercase;margin-bottom:4px;">
                        Payment Instructions (<span id="selectedMethodLabel">EVC Plus</span>)
                    </div>
                    <div id="methodInstructions" style="font-size:13px;font-weight:700;color:var(--neutral-dark);">
                        *712*618889900*Amount# / +252 61 888 9900
                    </div>
                    <div style="font-size:11px;color:var(--neutral);margin-top:4px;">
                        Send the exact amount to the number above, then enter the amount and upload your receipt screenshot below.
                    </div>
                </div>

                <!-- 3. Deposit Amount -->
                <div class="form-group">
                    <label class="form-label" style="font-weight:700;">2. Deposit Amount ($ USD) <span class="required">*</span></label>
                    <div class="input-icon-wrap">
                        <i class="ri-money-dollar-circle-line input-icon"></i>
                        <input type="number" name="amount" id="depositAmountInput" class="form-control" placeholder="0.00" min="1" step="0.01" required>
                    </div>
                    <!-- Quick chips -->
                    <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                        <?php foreach ([20, 50, 100, 250, 500, 1000] as $q): ?>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('depositAmountInput').value=<?= $q ?>" style="padding:2px 8px;font-size:11px;">$<?= $q ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 4. Transaction Reference / Sender Phone -->
                <div class="form-group">
                    <label class="form-label">3. Sender Phone / Transaction ID Reference</label>
                    <div class="input-icon-wrap">
                        <i class="ri-hashtag input-icon"></i>
                        <input type="text" name="payment_ref" class="form-control" placeholder="e.g. +252 61 XXX XXXX or Trx #982348">
                    </div>
                </div>

                <!-- 5. Upload Payment Screenshot / Receipt -->
                <div class="form-group">
                    <label class="form-label" style="font-weight:700;"><i class="ri-image-add-line" style="color:var(--secondary)"></i> 4. Upload Payment Screenshot (Receipt) <span class="required">*</span></label>
                    <input type="file" name="proof_image" class="form-control" accept="image/*" required id="receiptFileInput">
                    <div class="form-hint">Upload a screenshot showing the transaction confirmation on your phone</div>
                </div>

                <div class="modal-footer" style="border:none;padding:0;margin-top:16px;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('topupModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-upload-cloud-line"></i> Submit Payment for Verification</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.topup-pm-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.topup-pm-card').forEach(c => {
            c.style.borderColor = '#e8edf5';
            c.style.background = '#fff';
        });
        const card = this.closest('label').querySelector('.topup-pm-card');
        card.style.borderColor = 'var(--primary)';
        card.style.background = '#f0f5ff';
        
        document.getElementById('selectedMethodLabel').textContent = this.dataset.label;
        document.getElementById('methodInstructions').textContent = this.dataset.instructions;
    });
});
document.querySelectorAll('.topup-pm-card').forEach(card => {
    card.addEventListener('click', function() {
        const radio = this.closest('label').querySelector('.topup-pm-radio');
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
