<?php
// Generic profile page logic — included by each role's profile.php
$pdo = getDB();
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
            $avatar = $user['avatar'] ?? null;
            if (!empty($_FILES['avatar']['tmp_name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK && $_FILES['avatar']['size'] <= 2*1024*1024) { $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['avatar']['tmp_name']); $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null; if($ext){$dir=__DIR__.'/../assets/img/avatars';if(!is_dir($dir))mkdir($dir,0755,true);$f='avatar_'.$user['id'].'_'.bin2hex(random_bytes(5)).'.'.$ext;if(move_uploaded_file($_FILES['avatar']['tmp_name'],$dir.'/'.$f))$avatar=$f;}}
            $pdo->prepare("UPDATE users SET name=?,email=?,phone=?,address=?,avatar=? WHERE id=?")->execute([$name,$email,$phone,$addr,$avatar,$user['id']]);
            logAudit('PROFILE_UPDATED', 'Updated own profile', $user['id']);
            $success = 'Profile updated successfully.';
        }
    }

    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        $full = $pdo->prepare("SELECT password FROM users WHERE id=?");
        $full->execute([$user['id']]);
        $full_user = $full->fetch();

        if (!password_verify($current, $full_user['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new_pass) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new_pass !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new_pass, PASSWORD_DEFAULT), $user['id']]);
            logAudit('PASSWORD_CHANGED', 'User changed password', $user['id']);
            $success = 'Password changed successfully.';
        }
    }
}

// Re-fetch fresh user record
$fresh = $pdo->prepare("SELECT * FROM users WHERE id=?");
$fresh->execute([$user['id']]);
$user = $fresh->fetch();

// Dynamic role stats
$stat1_label = 'Balance';
$stat1_value = formatCurrency($user['balance']);
$stat2_label = 'Activity';
$stat2_value = '0';

if ($user['role'] === 'buyer') {
    $stat2_label = 'Orders Placed';
    $st = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE buyer_id=?");
    $st->execute([$user['id']]);
    $stat2_value = (string)$st->fetchColumn();
} elseif ($user['role'] === 'seller') {
    $stat2_label = 'Orders Sold';
    $st = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE seller_id=?");
    $st->execute([$user['id']]);
    $stat2_value = (string)$st->fetchColumn();
} elseif ($user['role'] === 'delivery') {
    $stat2_label = 'Deliveries';
    $st = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE delivery_id=?");
    $st->execute([$user['id']]);
    $stat2_value = (string)$st->fetchColumn();
} else {
    $stat2_label = 'Audit Logs';
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE user_id=?");
    $st->execute([$user['id']]);
    $stat2_value = (string)$st->fetchColumn();
}

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage personal information and security settings</p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start" class="fade-in">
    <!-- Profile Card -->
    <div>
        <div class="card" style="text-align:center;padding:32px 20px">
            <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;font-size:32px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;overflow:hidden"><?php if (!empty($user['avatar'])): ?><img src="<?= APP_URL ?>/assets/img/avatars/<?= sanitize($user['avatar']) ?>" alt="Profile image" style="width:100%;height:100%;object-fit:cover"><?php else: ?><?= strtoupper(substr($user['name'],0,1)) ?><?php endif; ?></div>
            <h2 style="font-size:18px;font-weight:800;color:var(--primary);margin-bottom:4px"><?= sanitize($user['name']) ?></h2>
            <p style="font-size:13px;color:var(--neutral);margin-bottom:8px"><?= sanitize($user['email']) ?></p>
            <span class="role-tag role-<?= $user['role'] ?>" style="font-size:12px;padding:4px 12px"><?= ucfirst($user['role']) ?></span>

            <div style="margin-top:24px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div style="background:var(--tertiary);border-radius:10px;padding:12px">
                    <div style="font-size:18px;font-weight:800;color:var(--secondary-dark)"><?= $stat1_value ?></div>
                    <div style="font-size:11px;color:var(--neutral)"><?= $stat1_label ?></div>
                </div>
                <div style="background:var(--tertiary);border-radius:10px;padding:12px">
                    <div style="font-size:18px;font-weight:800;color:var(--primary)"><?= $stat2_value ?></div>
                    <div style="font-size:11px;color:var(--neutral)"><?= $stat2_label ?></div>
                </div>
            </div>

            <div style="margin-top:20px;text-align:left;font-size:12px;color:var(--neutral-light);display:flex;flex-direction:column;gap:8px">
                <div><i class="ri-phone-line"></i> <?= sanitize($user['phone'] ?? 'Phone not set') ?></div>
                <div><i class="ri-map-pin-line"></i> <?= sanitize($user['address'] ?? 'Address not set') ?></div>
                <div><i class="ri-calendar-line"></i> Joined <?= date('M j, Y',strtotime($user['created_at'])) ?></div>
                <div><i class="ri-time-line"></i> Last login: <?= $user['last_login'] ? timeAgo($user['last_login']) : 'First login' ?></div>
            </div>
        </div>
    </div>

    <!-- Edit Forms -->
    <div style="display:flex;flex-direction:column;gap:20px">
        <!-- Edit Profile -->
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="ri-user-settings-line" style="color:var(--primary)"></i> Personal Information</span></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" data-validate>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Full Name <span class="required">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?= sanitize($user['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email Address <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?= sanitize($user['email']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-control" value="<?= sanitize($user['phone'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Delivery Address / Location</label>
                            <input type="text" name="address" class="form-control" value="<?= sanitize($user['address'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group"><label class="form-label">Profile Image</label><input type="file" name="avatar" class="form-control" accept="image/jpeg,image/png,image/webp"><div class="form-hint">JPG, PNG ama WEBP; ugu badnaan 2MB.</div></div>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Profile</button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-header"><span class="card-title"><i class="ri-lock-password-line" style="color:var(--secondary)"></i> Security &amp; Password</span></div>
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
                            <label class="form-label">Confirm New Password <span class="required">*</span></label>
                            <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary"><i class="ri-lock-line"></i> Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
