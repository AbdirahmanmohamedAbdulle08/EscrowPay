<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['buyer']);
$page_title  = 'New Escrow Order';
$active_page = 'new_order.php';
$pdo         = getDB();
$uid         = $user['id'];

$success = $error = '';
$fee_pct  = (float)getSetting('escrow_fee_pct', '2.5');
$min_tx   = (float)getSetting('min_transaction', '10');
$max_tx   = (float)getSetting('max_transaction', '100000');
$currency = getSetting('currency_symbol', '$');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $seller_id  = (int)($_POST['seller_id'] ?? 0);
    $title      = trim($_POST['title'] ?? '');
    $desc       = trim($_POST['description'] ?? '');
    $amount     = (float)($_POST['amount'] ?? 0);

    if (!$seller_id || empty($title) || $amount <= 0) {
        $error = 'All fields are required.';
    } elseif ($amount < $min_tx || $amount > $max_tx) {
        $error = "Amount must be between {$currency}{$min_tx} and {$currency}{$max_tx}.";
    } elseif ($seller_id === $uid) {
        $error = 'You cannot create an escrow with yourself.';
    } elseif ((float)$user['balance'] < $amount) {
        $error = 'Insufficient balance. Please top up your wallet.';
    } else {
        $fee     = round($amount * $fee_pct / 100, 2);
        $net     = $amount - $fee;
        $ref     = generateRefCode();

        $pdo->prepare("INSERT INTO transactions (ref_code,buyer_id,seller_id,title,description,amount,fee,net_amount,status,funded_at) VALUES (?,?,?,?,?,?,?,?,'funded',NOW())")
            ->execute([$ref,$uid,$seller_id,$title,$desc,$amount,$fee,$net]);

        $tx_id = (int)$pdo->lastInsertId();

        // Deduct balance
        $pdo->prepare("UPDATE users SET balance=balance-? WHERE id=?")->execute([$amount,$uid]);

        // Notify seller
        addNotification($seller_id, 'New Escrow Order!', "Buyer has funded escrow {$ref} for {$currency}{$amount}. Please review.", 'info', APP_URL.'/seller/orders.php');
        logAudit('CREATE_TRANSACTION', "Created escrow $ref for $$amount");

        header("Location: my_orders.php?success=Order+{$ref}+created+and+funded+successfully");
        exit;
    }
}

// Fetch sellers
$sellers = $pdo->query("SELECT id,name,email FROM users WHERE role='seller' AND status='active' ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">New Escrow Order</h1>
        <p class="page-subtitle">Funds will be held securely until you confirm delivery</p>
    </div>
    <a href="my_orders.php" class="btn btn-ghost btn-sm"><i class="ri-arrow-left-line"></i> Back to Orders</a>
</div>

<?php if ($error): ?><div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start" class="fade-in">
    <!-- Form -->
    <div class="card">
        <div class="card-header"><span class="card-title"><i class="ri-add-circle-line" style="color:var(--primary)"></i> Order Details</span></div>
        <div class="card-body">
            <form method="POST" data-validate id="orderForm">
                <div class="form-group">
                    <label class="form-label">Select Seller <span class="required">*</span></label>
                    <select name="seller_id" class="form-control" required id="sellerSelect">
                        <option value="">— Choose a seller —</option>
                        <?php foreach ($sellers as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= sanitize($s['name']) ?> — <?= sanitize($s['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint">The seller will receive funds once you confirm delivery</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Order Title <span class="required">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. MacBook Pro 14, Custom Logo Design..." required maxlength="255"
                           value="<?= sanitize($_POST['title'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Describe the item or service being purchased..."><?= sanitize($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Amount (<?= $currency ?>) <span class="required">*</span></label>
                    <div class="input-icon-wrap">
                        <i class="ri-money-dollar-circle-line input-icon"></i>
                        <input type="number" name="amount" id="amountInput" class="form-control" step="0.01" min="<?= $min_tx ?>" max="<?= $max_tx ?>"
                               placeholder="e.g. 500.00" required value="<?= sanitize($_POST['amount'] ?? '') ?>">
                    </div>
                    <div class="form-hint">Min: <?= $currency ?><?= $min_tx ?> — Max: <?= $currency ?><?= number_format($max_tx) ?></div>
                    <div class="form-error"></div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg" style="width:100%;margin-top:8px">
                    <i class="ri-secure-payment-line"></i> Create &amp; Fund Escrow
                </button>
            </form>
        </div>
    </div>

    <!-- Summary Card -->
    <div style="display:flex;flex-direction:column;gap:16px">
        <!-- Fee Calculator -->
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="ri-calculator-line" style="color:var(--secondary)"></i> Fee Calculator</span></div>
            <div class="card-body">
                <div id="calcResults" style="display:none">
                    <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f4fb">
                        <span style="font-size:13px;color:var(--neutral)">Order Amount</span>
                        <strong id="calcAmount" style="color:var(--neutral-dark)">—</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f4fb">
                        <span style="font-size:13px;color:var(--neutral)">Escrow Fee (<?= $fee_pct ?>%)</span>
                        <strong id="calcFee" style="color:var(--danger)">—</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:10px 0">
                        <span style="font-size:13px;font-weight:700;color:var(--neutral-dark)">Seller Receives</span>
                        <strong id="calcNet" style="font-size:16px;color:var(--secondary)">—</strong>
                    </div>
                    <div style="margin-top:12px;padding:12px;background:var(--secondary-light);border-radius:10px;font-size:12px;color:var(--secondary-dark)">
                        <i class="ri-information-line"></i> Funds deducted from your wallet: <strong id="calcTotal">—</strong>
                    </div>
                </div>
                <div id="calcPlaceholder" style="text-align:center;padding:20px;color:var(--neutral-light)">
                    <i class="ri-calculator-line" style="font-size:36px"></i>
                    <p style="margin-top:8px;font-size:13px">Enter an amount to see fee breakdown</p>
                </div>
            </div>
        </div>

        <!-- How it works -->
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="ri-information-line" style="color:var(--info)"></i> How Escrow Works</span></div>
            <div class="card-body">
                <?php
                $steps = [
                    ['ri-wallet-3-line','Fund','You fund the escrow. Funds are held securely.'],
                    ['ri-store-2-line','Seller Ships','Seller accepts and ships the item.'],
                    ['ri-truck-line','Delivery','Delivery agent picks up and delivers.'],
                    ['ri-checkbox-circle-line','Confirm','You confirm receipt and release funds.'],
                ];
                foreach ($steps as $i => [$icon,$label,$desc]):
                ?>
                <div style="display:flex;gap:12px;margin-bottom:16px">
                    <div style="width:36px;height:36px;border-radius:10px;background:rgba(29,59,139,.1);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0">
                        <i class="<?= $icon ?>"></i>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:700;color:var(--neutral-dark)"><?= $i+1 ?>. <?= $label ?></div>
                        <div style="font-size:12px;color:var(--neutral);margin-top:2px"><?= $desc ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
const FEE_PCT = <?= $fee_pct ?>;
const SYM     = '<?= $currency ?>';
const fmt     = v => SYM + parseFloat(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

document.getElementById('amountInput')?.addEventListener('input', function() {
    const amount = parseFloat(this.value) || 0;
    const ph     = document.getElementById('calcPlaceholder');
    const res    = document.getElementById('calcResults');

    if (amount > 0) {
        const fee = amount * FEE_PCT / 100;
        const net = amount - fee;
        document.getElementById('calcAmount').textContent = fmt(amount);
        document.getElementById('calcFee').textContent    = '−' + fmt(fee);
        document.getElementById('calcNet').textContent    = fmt(net);
        document.getElementById('calcTotal').textContent  = fmt(amount);
        ph.style.display  = 'none';
        res.style.display = 'block';
    } else {
        ph.style.display  = 'block';
        res.style.display = 'none';
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
