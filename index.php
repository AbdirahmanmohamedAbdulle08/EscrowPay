<?php
// / and /index.php are public landing pages. login.php defines this constant
// before loading this file, so the login implementation has a dedicated URL.
if (!defined('ESCROW_RENDER_LOGIN')) {
    require __DIR__ . '/landing.php';
    exit;
}

// ============================================================
// LOGIN PAGE
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    $u = getCurrentUser();
    if ($u) redirectByRole($u['role']);
}

$error   = '';
$success = '';

// Error messages
$error_map = [
    'session_expired' => 'Your session expired. Please log in again.',
    'invalid_user'    => 'Account not found.',
    'suspended'       => 'Your account has been suspended. Contact support.',
    'unauthorized'    => 'You are not authorized to access that page.',
];
if (isset($_GET['error']) && isset($error_map[$_GET['error']])) {
    $error = $error_map[$_GET['error']];
}

// Handle login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            $error = 'Invalid email or password.';
            logAudit('LOGIN_FAILED', "Failed login attempt for: $email", null);
        } elseif ($user['status'] === 'suspended') {
            $error = 'Your account has been suspended or rejected. Contact support.';
        } elseif ($user['status'] === 'pending') {
            $roleName = ucfirst($user['role']);
            $error = "Your $roleName account is pending admin verification and approval. You will be notified once activated by the administrator.";
        } else {
            // Success
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = $user['role'];

            // Update last_login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            logAudit('LOGIN', 'User logged in successfully', $user['id']);

            redirectByRole($user['role']);
        }
    }
}

$site_name = getSetting('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= sanitize($site_name) ?> — Secure Escrow Payment Platform. Login to your account.">
    <title>Login — <?= sanitize($site_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/logo/image.png">
    <link rel="shortcut icon" type="image/png" href="<?= APP_URL ?>/assets/logo/image.png">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/logo/image.png">
</head>
<body>

<div class="login-page">
    <!-- Left: Branding -->
    <div class="login-left">
        <div class="login-left-content">
            <div class="login-logo">
                <img src="<?= APP_URL ?>/assets/logo/image.png" alt="<?= sanitize($site_name) ?> Logo" style="width:100%;height:auto;object-fit:contain;max-width:140px;">
            </div>

            <h1 class="login-headline">Secure Payments,<br>Every Transaction</h1>
            <p class="login-subtext">
                EscrowPay holds funds safely until all parties confirm the transaction is complete.
                Built for buyers, sellers, and delivery agents.
            </p>
            <div class="login-features">
                <div class="login-feature">
                    <i class="ri-shield-check-line"></i>
                    <span class="login-feature-text">Funds held securely until delivery confirmed</span>
                </div>
                <div class="login-feature">
                    <i class="ri-truck-line"></i>
                    <span class="login-feature-text">Real-time delivery tracking &amp; updates</span>
                </div>
                <div class="login-feature">
                    <i class="ri-customer-service-2-line"></i>
                    <span class="login-feature-text">Dispute resolution by our admin team</span>
                </div>
                <div class="login-feature">
                    <i class="ri-money-dollar-circle-line"></i>
                    <span class="login-feature-text">Only <?= getSetting('escrow_fee_pct', '2.5') ?>% escrow fee per transaction</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Login Form -->
    <div class="login-right">
        <div class="login-form-wrap">
            <h2 class="login-form-title">Welcome back</h2>
            <p class="login-form-sub">Sign in to your <?= sanitize($site_name) ?> account</p>

            <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="ri-error-warning-line"></i>
                <span><?= sanitize($error) ?></span>
            </div>
            <?php endif; ?>

            <!-- Role Icons Quick Info -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:28px;">
                <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;background:#fff;border-radius:14px;border:2px solid #e8edf5;transition:border-color .2s;" class="login-role-card">
                    <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#1D3B8B,#3b5bb5);display:flex;align-items:center;justify-content:center;">
                        <i class="ri-shopping-cart-2-line" style="color:#fff;font-size:22px;"></i>
                    </div>
                    <span style="font-size:12px;font-weight:700;color:var(--neutral-dark);">Buyer</span>
                    <span style="font-size:10px;color:var(--neutral-light);text-align:center;">Buy safely with escrow</span>
                </div>
                <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;background:#fff;border-radius:14px;border:2px solid #e8edf5;transition:border-color .2s;" class="login-role-card">
                    <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#10C87B,#34d892);display:flex;align-items:center;justify-content:center;">
                        <i class="ri-store-2-line" style="color:#fff;font-size:22px;"></i>
                    </div>
                    <span style="font-size:12px;font-weight:700;color:var(--neutral-dark);">Seller</span>
                    <span style="font-size:10px;color:var(--neutral-light);text-align:center;">Sell with guarantee</span>
                </div>
                <div style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:14px 10px;background:#fff;border-radius:14px;border:2px solid #e8edf5;transition:border-color .2s;" class="login-role-card">
                    <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#fbbf24);display:flex;align-items:center;justify-content:center;">
                        <i class="ri-e-bike-2-line" style="color:#fff;font-size:22px;"></i>
                    </div>
                    <span style="font-size:12px;font-weight:700;color:var(--neutral-dark);">Delivery</span>
                    <span style="font-size:10px;color:var(--neutral-light);text-align:center;">Deliver & earn</span>
                </div>
            </div>

            <form method="POST" action="" id="loginForm" data-validate>
                <div class="form-group">
                    <label for="loginEmail" class="form-label"><i class="ri-mail-fill" style="color:var(--primary);margin-right:5px;"></i>Email address <span class="required">*</span></label>
                    <div class="input-icon-wrap">
                        <i class="ri-mail-line input-icon"></i>
                        <input type="email" id="loginEmail" name="email" class="form-control"
                               placeholder="you@example.com" required autocomplete="email"
                               value="<?= sanitize($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="form-error"></div>
                </div>

                <div class="form-group">
                    <label for="loginPassword" class="form-label"><i class="ri-lock-fill" style="color:var(--primary);margin-right:5px;"></i>Password <span class="required">*</span></label>
                    <div class="input-icon-wrap" style="position:relative">
                        <i class="ri-lock-line input-icon"></i>
                        <input type="password" id="loginPassword" name="password" class="form-control"
                               placeholder="Enter your password" required autocomplete="current-password"
                               style="padding-left:40px;padding-right:44px">
                        <i class="ri-eye-line password-toggle" style="position:absolute;right:13px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--neutral-light)"></i>
                    </div>
                    <div class="form-error"></div>
                </div>

                <button type="submit" class="btn btn-primary login-btn" id="loginSubmitBtn">
                    <i class="ri-shield-keyhole-line"></i> Secure Sign In
                </button>
                
                <div style="text-align:center;margin-top:20px;font-size:14px;color:var(--neutral)">
                    Don't have an account? <a href="register.php" class="text-primary fw-600"><i class="ri-user-add-line"></i> Register here</a>
                </div>
            </form>

            <!-- Security badges -->
            <div style="display:flex;align-items:center;justify-content:center;gap:20px;margin-top:24px;padding-top:20px;border-top:1px solid #f0f4fa;">
                <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--neutral-light);">
                    <i class="ri-shield-check-fill" style="color:var(--secondary);font-size:16px;"></i> SSL Secured
                </div>
                <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--neutral-light);">
                    <i class="ri-lock-fill" style="color:var(--primary);font-size:16px;"></i> Encrypted
                </div>
                <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--neutral-light);">
                    <i class="ri-award-fill" style="color:var(--warning);font-size:16px;"></i> Trusted
                </div>
            </div>

            <p style="text-align:center;margin-top:16px;font-size:12px;color:var(--neutral-light)">
                &copy; <?= date('Y') ?> <?= sanitize($site_name) ?>. All rights reserved.
            </p>
        </div>
    </div>
</div>

<style>
.login-role-card:hover { border-color: var(--primary) !important; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(29,59,139,.12); }
.login-role-card { transition: all .2s ease !important; }
.login-door-visual { width:100%; max-width:330px; height:170px; position:relative; margin:28px auto 0; border-radius:22px; overflow:hidden; background:linear-gradient(145deg,rgba(255,255,255,.16),rgba(255,255,255,.05)); border:1px solid rgba(255,255,255,.18); }
.login-door-frame { position:absolute; width:94px; height:124px; left:calc(50% - 3px); bottom:22px; padding:6px 6px 0; border-radius:11px 11px 0 0; background:rgba(255,255,255,.32); }.login-door { height:100%; border-radius:6px 6px 0 0; display:grid; place-items:center; position:relative; color:#dbeafe; background:linear-gradient(90deg,#0b225d,#315cae); font-size:26px; transform:perspective(180px) rotateY(-16deg); transform-origin:left; }.login-door span { width:7px; height:7px; border-radius:50%; position:absolute; right:11px; top:57px; background:#f6c759; }
.login-person { position:absolute; z-index:2; width:78px; height:78px; left:calc(50% - 74px); bottom:26px; display:grid; place-items:center; border-radius:50% 50% 42% 42%; color:#143b89; background:linear-gradient(145deg,#fff,#cddcff); box-shadow:0 12px 22px rgba(0,13,52,.24); font-size:39px; }.login-unlock { position:absolute; z-index:3; width:34px; height:34px; left:calc(50% + 50px); bottom:102px; display:grid; place-items:center; border-radius:11px; background:#10b981; color:#fff; box-shadow:0 6px 15px rgba(0,0,0,.18); }.login-door-visual p { position:absolute; bottom:5px; left:0; right:0; text-align:center; color:rgba(255,255,255,.78); font-size:10px; }
</style>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
