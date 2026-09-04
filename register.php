<?php
// ============================================================
// REGISTER — ROLE CHOOSER (3 CARDS)
// Redirects to role-specific registration pages
// ============================================================
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    $u = getCurrentUser();
    if ($u) redirectByRole($u['role']);
}

$site_name = getSetting('site_name', APP_NAME);

// Ensure sanitize exists
if (!function_exists('sanitize')) {
    function sanitize($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

// ============================================================
// FIRST-TIME SETUP: if 0 users exist, show ONLY Superadmin setup
// ============================================================
$pdo = getDB();
$is_first_user = ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0);

$setup_error = '';
$setup_success = '';
if ($is_first_user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($email) || empty($password)) {
        $setup_error = 'Fadlan buuxi dhammaan meelaha banaan.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $setup_error = 'Email-ka ma ahan mid sax ah.';
    } elseif (strlen($password) < 8) {
        $setup_error = 'Password-ka waa inuu ka badan yahay 8 xaraf.';
    } elseif ($password !== $confirm) {
        $setup_error = 'Password-yadu isma laha.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, 'superadmin', 'active')");
        if ($stmt->execute([$name, $email, $hash])) {
            $new_id = (int)$pdo->lastInsertId();
            logAudit('REGISTER', 'First superadmin created (system setup)', $new_id);
            $setup_success = 'Superadmin-ka si guul leh ayuu loo abuuray! Hadda waad gali kartaa.';
            $is_first_user = false; // hide the form after success
        } else {
            $setup_error = 'Cilad ayaa dhacday. Isku day mar kale.';
        }
    }
}

$roles = [
    'buyer' => [
        'icon'     => 'ri-shopping-cart-2-line',
        'title'    => 'Noqo Buyer',
        'title_en' => 'Join as Buyer',
        'desc'     => 'Wax iibso si ammaan ah. Lacagtaada waxaa ilaalinaya escrow inta aanad dalabka helin.',
        'desc_en'  => 'Shop safely. Your payment is held in escrow until you receive your order.',
        'features' => ['Buyer protection', 'Track deliveries', 'Full refund guarantee'],
        'url'      => APP_URL . '/register-buyer.php',
        'color'    => '#2563eb',
    ],
    'seller' => [
        'icon'     => 'ri-store-2-line',
        'title'    => 'Ku biir Seller',
        'title_en' => 'Join as Seller',
        'desc'     => 'Iibi alaabadaada. Lacagta waa la xaqiijinayaa marka delivery-ka dhaco.',
        'desc_en'  => 'Sell your products. Payments guaranteed once delivery is confirmed.',
        'features' => ['Guaranteed payouts', 'Open free store', 'Grow your business'],
        'url'      => APP_URL . '/register-seller.php',
        'color'    => '#0ca868',
    ],
    'delivery' => [
        'icon'     => 'ri-truck-line',
        'title'    => 'Noqo Delivery',
        'title_en' => 'Join as Delivery Agent',
        'desc'     => 'Gaarsii dalabyada. Ka hel lacag dalab kasta oo aad gaarsiiso.',
        'desc_en'  => 'Deliver packages. Earn money for every completed delivery.',
        'features' => ['Flexible schedule', 'Earn per delivery', 'Escrow-protected jobs'],
        'url'      => APP_URL . '/register-delivery.php',
        'color'    => '#f59e0b',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= sanitize($site_name) ?> — <?= $is_first_user ? 'Initial system setup' : 'Choose how you want to join: Buyer, Seller, or Delivery Agent.' ?>">
    <title><?= $is_first_user ? 'Initial Setup' : 'Register — Choose Your Role' ?> | <?= sanitize($site_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/logo/image.png">
    <link rel="shortcut icon" type="image/png" href="<?= APP_URL ?>/assets/logo/image.png">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/logo/image.png">
    <style>
        body { background:linear-gradient(135deg,#f0f4ff 0%,#e8edf9 100%); min-height:100vh; font-family:'Inter',sans-serif; }
        .chooser-wrap { max-width:1100px; margin:0 auto; padding:48px 24px; }
        .chooser-header { text-align:center; margin-bottom:40px; }
        .chooser-logo { display:inline-flex; align-items:center; justify-content:center; width:64px; height:64px; border-radius:16px; background:#fff; box-shadow:0 8px 24px rgba(29,59,139,.12); margin-bottom:20px; }
        .chooser-logo img { width:44px; height:44px; object-fit:contain; }
        .chooser-header h1 { font-size:30px; font-weight:800; color:#1e2a4a; margin:0 0 10px; }
        .chooser-header p { font-size:15px; color:var(--neutral); margin:0; }

        .role-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:24px; }
        .role-card { background:#fff; border-radius:18px; padding:32px 26px; text-align:center; box-shadow:0 4px 16px rgba(29,59,139,.06); transition:all .25s ease; cursor:pointer; border:2px solid transparent; display:block; text-decoration:none; }
        .role-card:hover { transform:translateY(-6px); box-shadow:0 16px 36px rgba(29,59,139,.14); border-color:var(--card-color); }
        .role-icon { width:72px; height:72px; margin:0 auto 18px; border-radius:20px; display:flex; align-items:center; justify-content:center; font-size:34px; color:#fff; background:var(--card-color); box-shadow:0 8px 20px color-mix(in srgb, var(--card-color) 35%, transparent); }
        .role-card h3 { font-size:19px; font-weight:700; color:#1e2a4a; margin:0 0 6px; }
        .role-card .rc-sub { font-size:12px; font-weight:600; color:var(--card-color); text-transform:uppercase; letter-spacing:.6px; margin-bottom:14px; }
        .role-card p { font-size:13.5px; color:var(--neutral); line-height:1.6; margin:0 0 18px; }
        .role-features { list-style:none; padding:0; margin:0 0 22px; text-align:left; }
        .role-features li { font-size:13px; color:var(--neutral); padding:5px 0; display:flex; align-items:center; gap:8px; }
        .role-features li i { color:var(--card-color); font-weight:bold; }
        .role-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; width:100%; padding:12px; border-radius:10px; font-size:14px; font-weight:700; color:#fff; background:var(--card-color); transition:all .2s; }
        .role-card:hover .role-btn { filter:brightness(1.08); }

        .chooser-footer { text-align:center; margin-top:36px; font-size:14px; color:var(--neutral); }
        .chooser-footer a { color:var(--primary); font-weight:600; text-decoration:none; }

        @media (max-width:860px) { .role-cards { grid-template-columns:1fr; max-width:420px; margin:0 auto; } }
    </style>
</head>
<body>
<div class="chooser-wrap">
<?php if ($is_first_user): ?>
    <!-- ============ FIRST-TIME SETUP: SUPERADMIN ONLY ============ -->
    <div class="chooser-header">
        <div class="chooser-logo">
            <img src="<?= APP_URL ?>/assets/logo/image.png" alt="<?= sanitize($site_name) ?> Logo">
        </div>
        <h1>🔧 Initial System Setup</h1>
        <p>Nidaamkan weli lama rakibin. Abuur Superadmin-ka ugu horreeya si aad u maamusho.</p>
    </div>

    <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:18px;padding:36px 32px;box-shadow:0 8px 32px rgba(29,59,139,.10);border-top:4px solid #7c3aed;">
        <div style="text-align:center;margin-bottom:24px;">
            <div style="width:64px;height:64px;margin:0 auto 14px;border-radius:18px;background:linear-gradient(135deg,#7c3aed,#a78bfa);display:flex;align-items:center;justify-content:center;">
                <i class="ri-shield-star-line" style="font-size:32px;color:#fff;"></i>
            </div>
            <h2 style="margin:0 0 6px;font-size:20px;font-weight:700;color:#1e2a4a;">Create Superadmin Account</h2>
            <p style="margin:0;font-size:13px;color:var(--neutral);">Account-kan wuxuu wataa xilka ugu sarreeya</p>
        </div>

        <?php if ($setup_error): ?>
        <div class="alert alert-danger"><i class="ri-error-warning-line"></i><span><?= sanitize($setup_error) ?></span></div>
        <?php endif; ?>
        <?php if ($setup_success): ?>
        <div class="alert alert-success" style="background:#d4f5e9;color:#0ca868;padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <i class="ri-checkbox-circle-line" style="font-size:20px;"></i><span><?= $setup_success ?></span>
        </div>
        <?php endif; ?>

        <?php if (!$setup_success): ?>
        <form method="POST" action="" data-validate>
            <div class="form-group">
                <label class="form-label">Full Name <span class="required">*</span></label>
                <div class="input-icon-wrap">
                    <i class="ri-user-line input-icon"></i>
                    <input type="text" name="name" class="form-control" placeholder="Admin Name" required style="padding-left:40px;">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Email <span class="required">*</span></label>
                <div class="input-icon-wrap">
                    <i class="ri-mail-line input-icon"></i>
                    <input type="email" name="email" class="form-control" placeholder="admin@example.com" required style="padding-left:40px;">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Password <span class="required">*</span></label>
                <div class="input-icon-wrap" style="position:relative">
                    <i class="ri-lock-line input-icon"></i>
                    <input type="password" name="password" class="form-control" placeholder="Min. 8 characters" required minlength="8" style="padding-left:40px;padding-right:44px">
                    <i class="ri-eye-line password-toggle" style="position:absolute;right:13px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--neutral-light)"></i>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password <span class="required">*</span></label>
                <div class="input-icon-wrap">
                    <i class="ri-lock-password-line input-icon"></i>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required minlength="8" style="padding-left:40px;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary login-btn" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);">
                <i class="ri-shield-star-line"></i> Create Superadmin
            </button>
        </form>
        <?php else: ?>
        <div style="text-align:center;margin-top:16px;">
            <a href="login.php" class="btn btn-primary login-btn" style="display:inline-flex;text-decoration:none;"><i class="ri-login-circle-line"></i> Go to Login</a>
        </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- ============ NORMAL: 3 ROLE CARDS ============ -->
    <div class="chooser-header">
        <div class="chooser-logo">
            <img src="<?= APP_URL ?>/assets/logo/image.png" alt="<?= sanitize($site_name) ?> Logo">
        </div>
        <h1>Sidee loo galo? — Sidee loo bilaabaa?</h1>
        <p>Dooro nooca account-ka aad rabto. Mid kastaa wuxuu leeyahay faa'iidooyin gaar ah.</p>
    </div>

    <div class="role-cards">
        <?php foreach ($roles as $role => $r): ?>
        <a href="<?= $r['url'] ?>" class="role-card" style="--card-color:<?= $r['color'] ?>">
            <div class="role-icon"><i class="<?= $r['icon'] ?>"></i></div>
            <h3><?= $r['title'] ?></h3>
            <div class="rc-sub"><?= $r['title_en'] ?></div>
            <p><?= $r['desc'] ?></p>
            <ul class="role-features">
                <?php foreach ($r['features'] as $f): ?>
                <li><i class="ri-checkbox-circle-fill"></i> <?= $f ?></li>
                <?php endforeach; ?>
            </ul>
            <span class="role-btn"><i class="ri-arrow-right-line"></i> Sii wad — Continue</span>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="chooser-footer">
        Mar hore account ma leedahay? <a href="login.php">Sign In halkan</a>
    </div>
<?php endif; ?>
</div>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
