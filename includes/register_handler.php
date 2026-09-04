<?php
// ============================================================
// SHARED REGISTRATION HANDLER
// Used by register-buyer.php, register-seller.php, register-delivery.php
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';

// Already logged in → redirect to dashboard
if (!empty($_SESSION['user_id'])) {
    $u = getCurrentUser();
    if ($u) redirectByRole($u['role']);
}

/**
 * Process a role-specific registration.
 * Validates superadmin block, duplicate email, and inserts the user.
 *
 * @param string $role          Fixed role for this page (buyer|seller|delivery)
 * @param array  $extra_fields  Additional DB columns => values (business_name, etc.)
 * @return array [bool success, string error, string successMsg]
 */
function processRegistration(string $role, array $extra_fields = []): array {
    $error   = '';
    $success = '';

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // --- Validation ---
    if (empty($name) || empty($email) || empty($password)) {
        $error = 'Fadlan buuxi dhammaan meelaha banaan.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email-ka ma ahan mid sax ah.';
    } elseif (strlen($password) < 8) {
        $error = 'Password-ka waa inuu ka badan yahay 8 xaraf.';
    } elseif ($password !== $confirm) {
        $error = 'Password-yadu isma laha (confirm password mismatch).';
    } elseif (!in_array($role, ['buyer', 'seller', 'delivery'], true)) {
        // SECURITY: public registration never allows superadmin
        $error = 'Role lama oggola.';
        logAudit('REGISTER_BLOCKED', "Blocked registration attempt for role: $role ($email)", null);
    } else {
        $pdo = getDB();

        // Duplicate email check
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email-kan waa la isticmaalay horey. Isku day mid kale.';
        } else {
            // Seller and Delivery accounts start as 'pending' awaiting admin approval
            $is_pending = in_array($role, ['seller', 'delivery'], true);
            $initial_status = $is_pending ? 'pending' : 'active';
            $allowed_cols = ['business_name', 'store_name', 'id_number', 'vehicle_type', 'vehicle_plate'];
            $cols  = ['name', 'email', 'password', 'role', 'phone', 'address', 'status'];
            $vals  = [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $phone, $address, $initial_status];

            foreach ($extra_fields as $col => $val) {
                if (in_array($col, $allowed_cols, true)) {
                    $cols[] = $col;
                    $vals[] = $val;
                }
            }

            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $col_list     = implode(', ', $cols);
            $sql = "INSERT INTO users ($col_list) VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql);

            if ($stmt->execute($vals)) {
                $new_id = (int)$pdo->lastInsertId();
                logAudit('REGISTER', "New $role registered ($initial_status): $email", $new_id);
                
                if ($is_pending) {
                    // Notify SuperAdmin of new application
                    $superAdmins = $pdo->query("SELECT id FROM users WHERE role='superadmin'")->fetchAll();
                    foreach ($superAdmins as $sa) {
                        addNotification(
                            $sa['id'],
                            "New " . ucfirst($role) . " Application Requires Approval",
                            ucfirst($role) . " $name ($email) registered. Review and accept/reject under Users.",
                            'info',
                            APP_URL . '/superadmin/users.php?role=' . $role
                        );
                    }
                    $roleLabel = ($role === 'seller') ? 'Seller-ka (Ganacsadaha)' : 'Gaarsiinta (Delivery)';
                    $success = "Diiwaangelintaadu way guuleysatay! Account-kaaga $roleLabel waxaa hadda dib-u-eegaya Admin-ka (Pending Admin Approval). Marka la aqbalo waad geli kartaa.";
                } else {
                    addNotification(
                        $new_id,
                        'Welcome to EscrowPay! 🎉',
                        "Your $role account has been created successfully. Welcome aboard!",
                        'success'
                    );
                    $success = 'Account-ka waa la abuuray! Hadda waad geli kartaa.';
                }
            } else {
                $error = 'Cilad ayaa dhacday markii account-ka la abuurayay.';
            }
        }
    }

    return [$success !== '', $error, $success];
}

/**
 * Shared HTML <head> for register pages.
 */
function registerPageHead(string $title): void {
    $site_name = getSetting('site_name', APP_NAME);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= sanitize($site_name) ?> — Secure Escrow Payment Platform. <?= sanitize($title) ?>">
    <title><?= sanitize($title) ?> — <?= sanitize($site_name) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/logo/image.png">
    <link rel="shortcut icon" type="image/png" href="<?= APP_URL ?>/assets/logo/image.png">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/logo/image.png">
    <style>
        /* ===== Role-specific register pages ===== */
        .register-steps { display:flex; align-items:center; gap:8px; margin-bottom:24px; }
        .register-steps .step { display:flex; align-items:center; gap:6px; font-size:12px; font-weight:600; color:var(--neutral-light); }
        .register-steps .step.active { color:var(--primary); }
        .register-steps .step i { font-size:16px; }
        .register-steps .sep { width:24px; height:2px; background:var(--neutral-light); opacity:.4; }

        .role-banner {
            display:flex; align-items:center; gap:14px;
            background:linear-gradient(135deg, var(--primary), #3b5bb5);
            color:#fff; padding:16px 18px; border-radius:12px; margin-bottom:24px;
        }
        .role-banner i { font-size:28px; }
        .role-banner .rb-title { font-weight:700; font-size:15px; }
        .role-banner .rb-sub { font-size:12px; opacity:.85; }

        .register-tabs { display:flex; gap:10px; margin-bottom:24px; background:#f3f6fd; padding:6px; border-radius:12px; }
        .register-tab { flex:1; text-align:center; padding:10px; border-radius:8px; cursor:pointer; font-size:13px; font-weight:600; color:var(--neutral); transition:all .2s; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:6px; }
        .register-tab.active { background:var(--primary); color:#fff; box-shadow:0 4px 12px rgba(29,59,139,.15); }
        .register-tab:not(.active):hover { background:#e8edf9; color:var(--primary); }

        .terms-check { display:flex; align-items:flex-start; gap:10px; margin:18px 0 20px; font-size:13px; color:var(--neutral); }
        .terms-check input { margin-top:3px; accent-color:var(--primary); width:16px; height:16px; cursor:pointer; }
        .terms-check a { color:var(--primary); font-weight:600; text-decoration:none; }

        .id-type-group { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
        .id-type-option { position:relative; }
        .id-type-option input { position:absolute; opacity:0; }
        .id-type-option label { display:flex; align-items:center; justify-content:center; gap:8px; padding:12px; border:2px solid #e3e9f5; border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; color:var(--neutral); transition:all .2s; }
        .id-type-option input:checked + label { border-color:var(--primary); background:#eef3ff; color:var(--primary); }

        /* ===== Visual right-panel (branding side) ===== */
        .login-left { position:relative; overflow:hidden; }
        .login-left::before {
            content:''; position:absolute; inset:0;
            background:
                radial-gradient(circle at 20% 20%, rgba(255,255,255,.08) 0%, transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(255,255,255,.06) 0%, transparent 40%);
            pointer-events:none;
        }
        .login-left::after {
            content:''; position:absolute; inset:0; opacity:.35;
            background-image:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.08'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            pointer-events:none;
        }
        .login-left-content { position:relative; z-index:2; }
        .visual-stats { display:flex; gap:14px; margin-top:32px; position:relative; z-index:2; }
        .visual-stat { flex:1; background:rgba(255,255,255,.12); backdrop-filter:blur(8px); border:1px solid rgba(255,255,255,.18); border-radius:14px; padding:14px 16px; }
        .visual-stat .vs-num { font-size:20px; font-weight:800; color:#fff; }
        .visual-stat .vs-label { font-size:11px; color:rgba(255,255,255,.75); margin-top:2px; }
        .trust-badge { display:inline-flex; align-items:center; gap:8px; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.2); border-radius:999px; padding:7px 16px; font-size:12px; font-weight:600; color:#fff; margin-bottom:22px; position:relative; z-index:2; }
        .trust-badge i { color:#6ee7b7; }
        .role-illustration { width:100%; max-width:310px; height:174px; margin:26px auto 0; position:relative; overflow:hidden; border-radius:22px; background:linear-gradient(145deg,rgba(255,255,255,.18),rgba(255,255,255,.05)); border:1px solid rgba(255,255,255,.18); }
        .role-illustration::before, .role-illustration::after { content:''; position:absolute; border-radius:50%; background:rgba(255,255,255,.08); }.role-illustration::before { width:170px; height:170px; top:-65px; right:-45px; }.role-illustration::after { width:105px; height:105px; bottom:-48px; left:-30px; }
        .role-avatar { position:absolute; z-index:1; width:92px; height:92px; left:calc(50% - 46px); top:31px; display:grid; place-items:center; border-radius:30px 30px 24px 24px; background:linear-gradient(145deg,#fff,#dbe8ff); box-shadow:0 16px 28px rgba(0,14,52,.26); color:var(--primary); font-size:46px; }
        .role-avatar .role-shield { position:absolute; right:-14px; bottom:-10px; width:37px; height:37px; display:grid; place-items:center; border-radius:13px; color:#fff; background:#10b981; border:3px solid #17418e; font-size:20px; }
        .role-avatar.seller { color:#0b724e; background:linear-gradient(145deg,#fff,#d6f6e7); }.role-avatar.delivery { color:#be6b00; background:linear-gradient(145deg,#fff,#fff0c7); }
        .register-form-intro { display:flex; align-items:flex-start; gap:11px; margin:0 0 20px; padding:12px 14px; border:1px solid #dce7f7; border-radius:12px; background:#f8fbff; }
        .register-form-intro .intro-icon { width:34px; height:34px; border-radius:10px; display:grid; place-items:center; flex:0 0 auto; color:#fff; background:linear-gradient(135deg,var(--primary),#4f6dbb); font-size:17px; }
        .register-form-intro strong { display:block; color:var(--neutral-dark); font-size:12px; margin:1px 0 3px; }
        .register-form-intro p { margin:0; color:var(--neutral-light); font-size:11px; line-height:1.45; }
    </style>
</head>
<body>
    <?php
}

/**
 * Shared left branding panel for register pages.
 */
function registerBrandingPanel(string $headline, string $subtext, array $features, array $stats = [], string $roleIcon = 'ri-user-smile-line', string $roleClass = ''): void {
    $site_name = getSetting('site_name', APP_NAME);
    ?>
<div class="login-page">
    <div class="login-left">
        <div class="login-left-content">
            <div class="trust-badge"><i class="ri-shield-check-fill"></i> 100% Secure & Trusted</div>
            
            <div class="login-logo">
                <img src="<?= APP_URL ?>/assets/logo/image.png" alt="<?= sanitize($site_name) ?> Logo" style="width:100%;height:auto;object-fit:contain;max-width:140px;">
            </div>
            <h1 class="login-headline"><?= $headline ?></h1>
            <p class="login-subtext"><?= $subtext ?></p>
            <div class="login-features">
                <?php foreach ($features as $icon => $text): ?>
                <div class="login-feature">
                    <i class="<?= $icon ?>"></i>
                    <span class="login-feature-text"><?= $text ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($stats)): ?>
            <div class="visual-stats">
                <?php foreach ($stats as $s): ?>
                <div class="visual-stat">
                    <div class="vs-num"><?= $s['num'] ?></div>
                    <div class="vs-label"><?= $s['label'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Shared role switch tabs (links to the other register pages).
 */
function registerRoleTabs(string $current_role): void {
    $tabs = [
        'buyer'    => ['ri-shopping-cart-line', 'Buyer',    APP_URL . '/register-buyer.php'],
        'seller'   => ['ri-store-line',         'Seller',   APP_URL . '/register-seller.php'],
        'delivery' => ['ri-truck-line',         'Delivery', APP_URL . '/register-delivery.php'],
    ];
    echo '<div class="register-tabs" id="roleTabs">';
    foreach ($tabs as $role => [$icon, $label, $url]) {
        $active = $role === $current_role ? ' active' : '';
        echo '<a href="' . $url . '" class="register-tab' . $active . '"><i class="' . $icon . '"></i> ' . $label . '</a>';
    }
    echo '</div>';
}

/**
 * Shared alert rendering.
 */
function registerAlerts(string $error, string $success): void {
    if ($error): ?>
    <div class="alert alert-danger">
        <i class="ri-error-warning-line"></i>
        <span><?= sanitize($error) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success" style="background:#d4f5e9;color:#0ca868;padding:12px;border-radius:8px;display:flex;align-items:center;gap:10px;margin-bottom:20px;">
        <i class="ri-checkbox-circle-line" style="font-size:20px;"></i>
        <span><?= $success ?></span>
    </div>
    <?php endif;
}

/**
 * Shared footer + scripts for register pages.
 */
function registerPageFooter(): void {
    $site_name = getSetting('site_name', APP_NAME);
    ?>
</div>

<p style="text-align:center;padding:20px;font-size:12px;color:var(--neutral-light)">
    &copy; <?= date('Y') ?> <?= sanitize($site_name) ?>. All rights reserved.
</p>

<script>
    // Password visibility toggle
    document.querySelectorAll('.password-toggle').forEach(toggle => {
        toggle.addEventListener('click', function() {
            const input = this.parentElement.querySelector('input');
            if (input.type === 'password') {
                input.type = 'text';
                this.classList.replace('ri-eye-line', 'ri-eye-off-line');
            } else {
                input.type = 'password';
                this.classList.replace('ri-eye-off-line', 'ri-eye-line');
            }
        });
    });
</script>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
    <?php
}

/** Compact visual guide shown consistently on all public registration forms. */
function registerFormIntro(string $icon, string $title, string $description): void {
    ?>
    <div class="register-form-intro">
        <div class="intro-icon"><i class="<?= sanitize($icon) ?>"></i></div>
        <div><strong><?= sanitize($title) ?></strong><p><?= sanitize($description) ?></p></div>
    </div>
    <?php
}
