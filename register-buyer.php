<?php
// ============================================================
// BUYER REGISTRATION PAGE
// ============================================================
require_once __DIR__ . '/includes/register_handler.php';

$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$ok, $error, $success] = processRegistration('buyer');
}
registerPageHead('Create your Buyer Account');
registerBrandingPanel(
    'Safe & Secure.<br>Every Purchase.',
    'Join thousands of buyers who trust EscrowPay to protect their payments until delivery is confirmed.',
    [
        'ri-shield-check-line'      => 'Payment held securely until you receive your order',
        'ri-truck-line'             => 'Track your delivery in real-time',
        'ri-arrow-right-double-line'=> 'Full refund if the item never arrives',
        'ri-customer-service-2-line'=> 'Dispute resolution by our admin team',
    ],
    [
        ['num' => '10K+',   'label' => 'Active Buyers'],
        ['num' => '$2M+',   'label' => 'Protected Payments'],
        ['num' => '99.9%',  'label' => 'Success Rate'],
    ],
    'ri-shopping-bag-3-line',
    'buyer'
);
?>
<div class="login-right">
    <div class="login-form-wrap">
        <h2 class="login-form-title">Create your Buyer Account</h2>
        <p class="login-form-sub">Join EscrowPay to shop safely with escrow protection</p>

        <?php registerAlerts($error, $success); ?>
        <?php registerRoleTabs('buyer'); ?>
        <?php registerFormIntro('ri-shopping-bag-3-line', 'Buyer account setup', 'Xogtaada waxaa loo isticmaalaa xiriirka order-ka iyo gaarsiinta ammaan ah.'); ?>

        <form method="POST" action="" id="registerForm" data-validate>
            <div class="form-group">
                <label for="regName" class="form-label">Full Name <span class="required">*</span></label>
                <div class="input-icon-wrap">
                    <i class="ri-user-line input-icon"></i>
                    <input type="text" id="regName" name="name" class="form-control"
                           placeholder="e.g. John Doe" required autocomplete="name"
                           value="<?= sanitize($_POST['name'] ?? '') ?>" style="padding-left:40px;">
                </div>
            </div>

            <div class="form-group">
                <label for="regEmail" class="form-label">Email Address <span class="required">*</span></label>
                <div class="input-icon-wrap">
                    <i class="ri-mail-line input-icon"></i>
                    <input type="email" id="regEmail" name="email" class="form-control"
                           placeholder="you@example.com" required autocomplete="email"
                           value="<?= sanitize($_POST['email'] ?? '') ?>" style="padding-left:40px;">
                </div>
            </div>

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
                <label for="regAddress" class="form-label">Delivery Address</label>
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
                <i class="ri-shopping-cart-line"></i> Sign Up as Buyer
            </button>

            <div style="text-align:center;margin-top:20px;font-size:14px;color:var(--neutral)">
                Already have an account? <a href="login.php" class="text-primary fw-600">Sign In</a>
            </div>
        </form>
    </div>
</div>
<?php registerPageFooter(); ?>
