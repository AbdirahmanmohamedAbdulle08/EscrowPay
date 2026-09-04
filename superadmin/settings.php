<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'System Settings';
$active_page = 'settings.php';
$pdo         = getDB();

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'site_name','site_email','escrow_fee_pct','delivery_fee','delivery_commission_pct','withdrawal_fee_pct',
        'currency','currency_symbol',
        'min_transaction','max_transaction','maintenance_mode',
        'smtp_host','smtp_port','smtp_user','smtp_pass'
    ];
    foreach ($fields as $f) {
        $val = trim($_POST[$f] ?? '');
        $pdo->prepare("UPDATE settings SET `value`=? WHERE `key`=?")->execute([$val, $f]);
    }
    logAudit('SETTINGS_UPDATED', 'Updated system settings');
    $success = 'Settings saved successfully.';
}

$settings = [];
foreach ($pdo->query("SELECT `key`,`value`,description FROM settings") as $row) {
    $settings[$row['key']] = $row;
}
$s = fn($k) => sanitize($settings[$k]['value'] ?? '');

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">System Settings</h1>
        <p class="page-subtitle">Configure platform-wide settings</p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div><?php endif; ?>

<form method="POST" data-validate>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

    <!-- General -->
    <div class="card fade-in">
        <div class="card-header">
            <span class="card-title"><i class="ri-settings-3-line" style="color:var(--primary)"></i> General</span>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label">Site Name</label>
                <input type="text" name="site_name" class="form-control" value="<?= $s('site_name') ?>" required>
                <div class="form-hint">Used in browser titles and emails</div>
            </div>
            <div class="form-group">
                <label class="form-label">Contact Email</label>
                <input type="email" name="site_email" class="form-control" value="<?= $s('site_email') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Maintenance Mode</label>
                <select name="maintenance_mode" class="form-control">
                    <option value="0" <?= ($settings['maintenance_mode']['value'] ?? '0') ==='0'?'selected':'' ?>>Off — Platform is live</option>
                    <option value="1" <?= ($settings['maintenance_mode']['value'] ?? '0') ==='1'?'selected':'' ?>>On — Under maintenance</option>
                </select>
                <div class="form-hint">Only superadmins can access in maintenance mode</div>
            </div>
        </div>
    </div>

    <!-- Escrow & Delivery Fees -->
    <div class="card fade-in">
        <div class="card-header">
            <span class="card-title"><i class="ri-percent-line" style="color:var(--secondary)"></i> Escrow & Delivery Fees</span>
        </div>
        <div class="card-body">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Platform Escrow Fee (%)</label>
                    <input type="number" name="escrow_fee_pct" class="form-control" step="0.1" min="0" max="30" value="<?= $s('escrow_fee_pct') ?>">
                    <div class="form-hint">Deducted from seller payout (currently 10%)</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Standard Delivery Fee ($)</label>
                    <input type="number" name="delivery_fee" class="form-control" step="0.1" min="0" value="<?= $s('delivery_fee') ?: '1.50' ?>">
                    <div class="form-hint">Charged to buyer on physical items ($1.50)</div>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">SuperAdmin Delivery Commission (%)</label>
                    <input type="number" name="delivery_commission_pct" class="form-control" step="0.01" min="0" max="10" value="<?= $s('delivery_commission_pct') ?: '0.2' ?>">
                    <div class="form-hint">Commission kept by SuperAdmin on deliveries (0.2%)</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Withdrawal Fee (%)</label>
                    <input type="number" name="withdrawal_fee_pct" class="form-control" step="0.1" min="0" max="10" value="<?= $s('withdrawal_fee_pct') ?: '1.0' ?>">
                    <div class="form-hint">Fee deducted when payouts are accepted</div>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Currency Code</label>
                    <input type="text" name="currency" class="form-control" maxlength="3" value="<?= $s('currency') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Currency Symbol</label>
                    <input type="text" name="currency_symbol" class="form-control" maxlength="5" value="<?= $s('currency_symbol') ?>">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Min Transaction</label>
                    <input type="number" name="min_transaction" class="form-control" step="1" min="0" value="<?= $s('min_transaction') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Max Transaction</label>
                    <input type="number" name="max_transaction" class="form-control" step="1" min="0" value="<?= $s('max_transaction') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- SMTP -->
    <div class="card fade-in" style="grid-column:1/-1">
        <div class="card-header">
            <span class="card-title"><i class="ri-mail-send-line" style="color:var(--info)"></i> Email / SMTP Settings</span>
        </div>
        <div class="card-body">
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control" value="<?= $s('smtp_host') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-control" value="<?= $s('smtp_port') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Username</label>
                    <input type="text" name="smtp_user" class="form-control" value="<?= $s('smtp_user') ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label">SMTP Password</label>
                    <input type="password" name="smtp_pass" class="form-control" placeholder="Leave blank to keep current" autocomplete="new-password">
                </div>
            </div>
        </div>
    </div>

</div>

<div style="margin-top:20px;display:flex;justify-content:flex-end">
    <button type="submit" class="btn btn-primary btn-lg"><i class="ri-save-line"></i> Save Settings</button>
</div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
