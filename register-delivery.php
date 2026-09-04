<?php
// ============================================================
// DELIVERY AGENT REGISTRATION PAGE
// ============================================================
require_once __DIR__ . '/includes/register_handler.php';

$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_num  = trim($_POST['id_number'] ?? '');
    $v_type  = trim($_POST['vehicle_type'] ?? '');
    $v_plate = trim($_POST['vehicle_plate'] ?? '');
    [$ok, $error, $success] = processRegistration('delivery', [
        'id_number'     => $id_num,
        'vehicle_type'  => $v_type,
        'vehicle_plate' => $v_plate,
    ]);
}
registerPageHead('Create your Delivery Account');
registerBrandingPanel(
    'Fast & Reliable<br>Deliveries.',
    'Join EscrowPay as a delivery agent. Earn money delivering escrow-protected packages on your own schedule.',
    [
        'ri-truck-line'              => 'Deliver packages on your own schedule',
        'ri-money-dollar-circle-line'=> 'Earn per delivery — paid instantly',
        'ri-map-2-line'              => 'Optimized routes & clear addresses',
        'ri-shield-check-line'       => 'Every delivery is escrow-protected',
    ],
    [
        ['num' => '2K+',   'label' => 'Delivery Agents'],
        ['num' => '50K+',  'label' => 'Deliveries Done'],
        ['num' => '$500K+','label' => 'Agent Earnings'],
    ],
    'ri-e-bike-2-line',
    'delivery'
);
?>
<div class="login-right">
    <div class="login-form-wrap">
        <h2 class="login-form-title">Create your Delivery Account</h2>
        <p class="login-form-sub">Join EscrowPay to earn by delivering escrow-protected packages</p>

        <?php registerAlerts($error, $success); ?>
        <?php registerRoleTabs('delivery'); ?>
        <?php registerFormIntro('ri-e-bike-2-line', 'Delivery partner verification', 'Geli xogtaada iyo gaadhigaaga si maamulka u xaqiijiyo koontada delivery-ga.'); ?>

        <form method="POST" action="" id="registerForm" data-validate>
            <div class="form-group">
                <label for="regName" class="form-label">Full Name <span class="required">*</span></label>
                <div class="input-icon-wrap">
                    <i class="ri-user-line input-icon"></i>
                    <input type="text" id="regName" name="name" class="form-control"
                           placeholder="e.g. Mike Ali" required autocomplete="name"
                           value="<?= sanitize($_POST['name'] ?? '') ?>" style="padding-left:40px;">
                </div>
            </div>

            <div class="form-grid-2">
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
                    <label for="regPhone" class="form-label">Phone Number <span class="required">*</span></label>
                    <div class="input-icon-wrap">
                        <i class="ri-phone-line input-icon"></i>
                        <input type="tel" id="regPhone" name="phone" class="form-control"
                               placeholder="+252 61 234 5678" required autocomplete="tel"
                               value="<?= sanitize($_POST['phone'] ?? '') ?>" style="padding-left:40px;">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="regAddress" class="form-label">Base Location / City</label>
                <div class="input-icon-wrap" style="align-items:flex-start">
                    <i class="ri-map-pin-line input-icon" style="margin-top:12px"></i>
                    <textarea id="regAddress" name="address" class="form-control" rows="2"
                              placeholder="City / area you operate in" style="padding-left:40px;resize:vertical;"><?= sanitize($_POST['address'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="regIdNumber" class="form-label">National ID Number <span class="required">*</span></label>
                    <div class="input-icon-wrap">
                        <i class="ri-badge-line input-icon"></i>
                        <input type="text" id="regIdNumber" name="id_number" class="form-control"
                               placeholder="ID number" required autocomplete="off"
                               value="<?= sanitize($_POST['id_number'] ?? '') ?>" style="padding-left:40px;">
                    </div>
                </div>
                <div class="form-group">
                    <label for="regVehicleType" class="form-label">Vehicle Type</label>
                    <div class="input-icon-wrap">
                        <i class="ri-motorbike-line input-icon"></i>
                        <select id="regVehicleType" name="vehicle_type" class="form-control" style="padding-left:40px;">
                            <option value="">— Select vehicle —</option>
                            <option value="motorcycle" <?= ($_POST['vehicle_type'] ?? '') === 'motorcycle' ? 'selected' : '' ?>>Motorcycle</option>
                            <option value="car"        <?= ($_POST['vehicle_type'] ?? '') === 'car' ? 'selected' : '' ?>>Car</option>
                            <option value="van"        <?= ($_POST['vehicle_type'] ?? '') === 'van' ? 'selected' : '' ?>>Van</option>
                            <option value="truck"      <?= ($_POST['vehicle_type'] ?? '') === 'truck' ? 'selected' : '' ?>>Truck</option>
                            <option value="bicycle"    <?= ($_POST['vehicle_type'] ?? '') === 'bicycle' ? 'selected' : '' ?>>Bicycle</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="regPlate" class="form-label">Vehicle Plate Number</label>
                <div class="input-icon-wrap">
                    <i class="ri-car-line input-icon"></i>
                    <input type="text" id="regPlate" name="vehicle_plate" class="form-control"
                           placeholder="e.g. XYZ-1234" autocomplete="off"
                           value="<?= sanitize($_POST['vehicle_plate'] ?? '') ?>" style="padding-left:40px;">
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
                <i class="ri-truck-line"></i> Sign Up as Delivery Agent
            </button>

            <div style="text-align:center;margin-top:20px;font-size:14px;color:var(--neutral)">
                Already have an account? <a href="login.php" class="text-primary fw-600">Sign In</a>
            </div>
        </form>
    </div>
</div>
<?php registerPageFooter(); ?>
