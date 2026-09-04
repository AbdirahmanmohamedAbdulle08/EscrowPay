<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'My Profile';
$active_page = 'profile.php';
$pdo         = getDB();

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $addr  = trim($_POST['address'] ?? '');

        if (empty($name) || empty($email)) {
            $error = 'Name and email are required.';
        } else {
            $pdo->prepare("UPDATE users SET name=?,email=?,phone=?,address=? WHERE id=?")->execute([$name,$email,$phone,$addr,$user['id']]);
            logAudit('PROFILE_UPDATED','Updated own profile');
            $success = 'Profile updated successfully.';
            $user    = getCurrentUser(); // refresh
        }
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $full = $pdo->prepare("SELECT password FROM users WHERE id=?")->execute([$user['id']]) ? null : null;
        $full = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $full->execute([$user['id']]);
        $full = $full->fetch();

        if (!password_verify($current, $full['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new_pass) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new_pass !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new_pass,PASSWORD_DEFAULT),$user['id']]);
            logAudit('PASSWORD_CHANGED','User changed own password');
            $success = 'Password changed successfully.';
        }
    }
}

// Re-fetch fresh user
$fresh = $pdo->prepare("SELECT * FROM users WHERE id=?");
$fresh->execute([$user['id']]);
$user = $fresh->fetch();

// Stats
$total_actions = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id=?")->execute([$user['id']]) ? 0 : 0;
$ta = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id=?");
$ta->execute([$user['id']]);
$total_actions = $ta->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage your account information and security</p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start" class="fade-in">
    <!-- Profile Card -->
    <div>
        <div class="card" style="text-align:center;padding:32px 20px">
            <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;font-size:32px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
                <?= strtoupper(substr($user['name'],0,1)) ?>
            </div>
            <h2 style="font-size:18px;font-weight:800;color:var(--primary);margin-bottom:4px"><?= sanitize($user['name']) ?></h2>
            <p style="font-size:13px;color:var(--neutral);margin-bottom:8px"><?= sanitize($user['email']) ?></p>
            <span class="role-tag role-<?= $user['role'] ?>" style="font-size:12px;padding:4px 12px"><?= ucfirst($user['role']) ?></span>
            <div style="margin-top:24px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div style="background:var(--tertiary);border-radius:10px;padding:12px">
                    <div style="font-size:20px;font-weight:800;color:var(--primary)"><?= formatCurrency($user['balance']) ?></div>
                    <div style="font-size:11px;color:var(--neutral)">Balance</div>
                </div>
                <div style="background:var(--tertiary);border-radius:10px;padding:12px">
                    <div style="font-size:20px;font-weight:800;color:var(--primary)"><?= $total_actions ?></div>
                    <div style="font-size:11px;color:var(--neutral)">Actions</div>
                </div>
            </div>
            <div style="margin-top:16px;text-align:left;font-size:12px;color:var(--neutral-light)">
                <div style="margin-bottom:6px"><i class="ri-phone-line"></i> <?= sanitize($user['phone'] ?? 'Not set') ?></div>
                <div style="margin-bottom:6px"><i class="ri-calendar-line"></i> Joined <?= date('M j, Y',strtotime($user['created_at'])) ?></div>
                <div><i class="ri-time-line"></i> Last login: <?= $user['last_login'] ? timeAgo($user['last_login']) : 'N/A' ?></div>
            </div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:20px">
        <!-- Edit Profile -->
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="ri-user-settings-line" style="color:var(--primary)"></i> Edit Profile</span></div>
            <div class="card-body">
                <form method="POST" data-validate>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Full Name <span class="required">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= sanitize($user['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= sanitize($user['phone'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Address</label>
                            <input type="text" name="address" class="form-control" value="<?= sanitize($user['address'] ?? '') ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Profile</button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="ri-lock-password-line" style="color:var(--secondary)"></i> Change Password</span></div>
            <div class="card-body">
                <form method="POST" data-validate>
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-grid-2">
                        <div class="form-group" style="grid-column:1/-1">
                            <label class="form-label">Current Password <span class="required">*</span></label>
                            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password <span class="required">*</span></label>
                            <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password <span class="required">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary"><i class="ri-lock-line"></i> Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
