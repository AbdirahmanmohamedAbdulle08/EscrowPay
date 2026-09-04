<?php
// ============================================================
// SELLER REGISTRATION PAGE
// ============================================================
require_once __DIR__ . '/includes/register_handler.php';

$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $business = trim($_POST['business_name'] ?? '');
    $store    = trim($_POST['store_name'] ?? '');
    [$ok, $error, $success] = processRegistration('seller', [
        'business_name' => $business,
        'store_name'    => $store,
        'id_number'     => trim($_POST['id_number'] ?? ''),
    ]);
}
registerPageHead('Create your Seller Account');
registerBrandingPanel(
    'Empower Your<br>Business.',
    'Join thousands of sellers growing their business with secure escrow-protected sales and guaranteed payments.',
    [
        'ri-safe-2-line'            => 'Guaranteed payment — funds held in escrow',
        'ri-store-line'             => 'Open your store & list products for free',
        'ri-line-chart-line'        => 'Track earnings & payout instantly',
        'ri-global-line'            => 'Reach buyers everywhere, safely',
    ],
    [
        ['num' => '5K+',    'label' => 'Active Sellers'],
        ['num' => '$1.2M+', 'label' => 'Paid Out'],
        ['num' => '0%',     'label' => 'Fraud Rate'],
    ],
    'ri-store-2-line',
    'seller'
);
?>
<div class="login-right">
    <div class="login-form-wrap">
        <h2 class="login-form-title">Create your Seller Account</h2>
        <p class="login-form-sub">Join EscrowPay to sell with escrow-protected payments</p>

        <?php registerAlerts($error, $success); ?>
        <?php registerRoleTabs('seller'); ?>
        <?php registerFormIntro('ri-store-2-line', 'Business registration', 'Buuxi xogta ganacsigaaga. Seller accounts waxaa hubinaya maamulka ka hor inta aan la hawlgelin.'); ?>
        <form method="POST" action="" id="registerForm" data-validate>
            <div class="form-group">
                <label for="regName" class="form-label">Full Name <span class="required">*</span></label>
                <div class="input-icon-wrap">
                    <i class="ri-user-line input-icon"></i>
                    <input type="text" id="regName" name="name" class="form-control"
                           placeholder="e.g. Jane Ahmed" required autocomplete="name"
                           value="<?= sanitize($_POST['name'] ?? '') ?>" style="padding-left:40px;">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="regBusiness" class="form-label">Business Name</label>
                    <div class="input-icon-wrap">
                        <i class="ri-building-line input-icon"></i>
                        <input type="text" id="regBusiness" name="business_name" class="form-control"
                               placeholder="e.g. Ahmed Trading Co." autocomplete="organization"
                               value="<?= sanitize($_POST['business_name'] ?? '') ?>" style="padding-left:40px;">
                    </div>
                </div>
                <div class="form-group">
                    <label for="regStore" class="form-label">Store Name</label>
                    <div class="input-icon-wrap">
                        <i class="ri-store-line input-icon"></i>
                        <input type="text" id="regStore" name="store_name" class="form-control"
                               placeholder="e.g. AhmedStore" autocomplete="off"
                               value="<?= sanitize($_POST['store_name'] ?? '') ?>" style="padding-left:40px;">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="regEmail" class="form-label">Email Address <span class="required">*</span></label>
                <div class="input-icon-wrap">
                    <i class="ri-mail-line input-icon"></i>
                    <input type="email" id="regEmail" name="email" class="form-control"
                           placeholder="business@example.com" required autocomplete="email"
                           value="<?= sanitize($_POST['email'] ?? '') ?>" style="padding-left:40px;">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="regPhone" class="form-label">Phone Number</label>
                    <div class="input-icon-wrap">
                        <i class="ri-phone-line input-icon"></i>
                        <input type="tel" id="regPhone" name="phone" class="form-control"
                               placeholder="+252 61 234 5678" autocomplete="tel"
                               value="<?= sanitize($_POST['phone'] ?? '') ?>" style="padding-left:40px;">
                    </div>
                </div>
                <div class="form-group">
                    <label for="regIdNumber" class="form-label">ID / License Number</label>
                    <div class="input-icon-wrap">
                        <i class="ri-badge-line input-icon"></i>
                        <input type="text" id="regIdNumber" name="id_number" class="form-control"
                               placeholder="Business ID / License" autocomplete="off"
                               value="<?= sanitize($_POST['id_number'] ?? '') ?>" style="padding-left:40px;">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="regAddress" class="form-label">Business Address</label>
                <div class="input-icon-wrap" style="align-items:flex-start">
                    <i class="ri-map-pin-line input-icon" style="margin-top:12px"></i>
                    <textarea id="regAddress" name="address" class="form-control" rows="2"
                              placeholder="Street, city, postal code" style="padding-left:40px;resize:vertical;"><?= sanitize($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="regPassword" class="form-label">Password <span class="required">*</span></label>
                    <div class="input-icon-wrap" style="position:relative">
                        <i class="ri-lock-line input-icon"></i>
                        <input type="password" id="regPassword" name="password" class="form-control"
                               placeholder="Min. 8 characters" required autocomplete="new-password" minlength="8"
                               style="padding-left:40px;padding-right:44px">
                        <i class="ri-eye-line password-toggle" style="position:absolute;right:13px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--neutral-light)"></i>
                    </div>
                </div>
                <div class="form-group">
                    <label for="regConfirm" class="form-label">Confirm Password <span class="required">*</span></label>
                    <div class="input-icon-wrap" style="position:relative">
                        <i class="ri-lock-password-line input-icon"></i>
                        <input type="password" id="regConfirm" name="confirm_password" class="form-control"
                               placeholder="Re-enter password" required autocomplete="new-password" minlength="8"
                               style="padding-left:40px;padding-right:44px">
                        <i class="ri-eye-line password-toggle" style="position:absolute;right:13px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--neutral-light)"></i>
                    </div>
                </div>
            </div>

            <label class="terms-check">
                <input type="checkbox" name="terms" required>
                <span>I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a> of EscrowPay</span>
            </label>

            <button type="submit" class="btn btn-primary login-btn" id="regSubmitBtn">
                <i class="ri-store-line"></i> Sign Up as Seller
            </button>

            <div style="text-align:center;margin-top:20px;font-size:14px;color:var(--neutral)">
                Already have an account? <a href="login.php" class="text-primary fw-600">Sign In</a>
            </div>
        </form>
    </div>
</div>
<?php registerPageFooter(); ?>
