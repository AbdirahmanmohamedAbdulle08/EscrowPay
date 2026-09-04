<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Manage Users';
$active_page = 'users.php';
$pdo         = getDB();

$success = $error = '';

// ── Handle Actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? 'buyer';
        $phone    = trim($_POST['phone'] ?? '');
        $status   = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        $uid      = (int)($_POST['user_id'] ?? 0);

        if (empty($name) || empty($email)) {
            $error = 'Name and email are required.';
        } elseif ($action === 'add' && empty($password)) {
            $error = 'Password is required for new users.';
        } else {
            if ($action === 'add') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name,email,password,role,phone,status) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$name,$email,$hash,$role,$phone,$status]);
                logAudit('USER_CREATED', "Created user: $email ($role)");
                addNotification((int)$pdo->lastInsertId(), 'Welcome to EscrowPay', 'Your account has been created.', 'success');
                $success = 'User created successfully.';
            } else {
                $sets  = "name=?,email=?,role=?,phone=?,status=?";
                $vals  = [$name,$email,$role,$phone,$status];
                if (!empty($password)) { $sets .= ",password=?"; $vals[] = password_hash($password, PASSWORD_DEFAULT); }
                $vals[] = $uid;
                $pdo->prepare("UPDATE users SET $sets WHERE id=?")->execute($vals);
                logAudit('USER_UPDATED', "Updated user id=$uid");
                $success = 'User updated successfully.';
            }
        }
    }

    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("DELETE FROM users WHERE id=? AND role!='superadmin'")->execute([$uid]);
        logAudit('USER_DELETED', "Deleted user id=$uid");
        $success = 'User deleted.';
    }

    if ($action === 'toggle_status') {
        $uid    = (int)($_POST['user_id'] ?? 0);
        $status = $_POST['new_status'] ?? 'active';
        $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status, $uid]);
        logAudit('USER_STATUS', "Changed user id=$uid status to $status");
        $success = "User status updated to $status.";
    }
}

// ── Filters ────────────────────────────────────────────────
$role_filter   = $_GET['role']   ?? '';
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['q'] ?? '');

$where = ["role != 'superadmin'"];
$params = [];
if ($role_filter)   { $where[] = "role=?";                  $params[] = $role_filter; }
if ($status_filter) { $where[] = "status=?";                $params[] = $status_filter; }
if ($search)        { $where[] = "(name LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSQL = implode(' AND ', $where);

// Pagination
$per_page = 12;
$page_num = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page_num - 1) * $per_page;
$total    = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $whereSQL");
$total->execute($params);
$total_count = (int)$total->fetchColumn();
$total_pages = max(1, ceil($total_count / $per_page));

$stmt = $pdo->prepare("SELECT * FROM users WHERE $whereSQL ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

// Edit: load single user
$edit_user = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $s->execute([(int)$_GET['edit']]);
    $edit_user = $s->fetch();
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle"><?= $total_count ?> users found</p>
    </div>
    <button class="btn btn-primary" data-modal-open="addUserModal"><i class="ri-user-add-line"></i> Add User</button>
</div>

<?php if ($success): ?><div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div><?php endif; ?>

<!-- Filters -->
<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:200px">
                <label class="form-label" style="margin-bottom:5px">Search</label>
                <div class="input-icon-wrap">
                    <i class="ri-search-line input-icon"></i>
                    <input type="text" name="q" class="form-control" placeholder="Name or email..." value="<?= sanitize($search) ?>">
                </div>
            </div>
            <div>
                <label class="form-label" style="margin-bottom:5px">Role</label>
                <select name="role" class="form-control">
                    <option value="">All Roles</option>
                    <option value="buyer"    <?= $role_filter==='buyer'    ? 'selected':'' ?>>Buyer</option>
                    <option value="seller"   <?= $role_filter==='seller'   ? 'selected':'' ?>>Seller</option>
                    <option value="delivery" <?= $role_filter==='delivery' ? 'selected':'' ?>>Delivery</option>
                </select>
            </div>
            <div>
                <label class="form-label" style="margin-bottom:5px">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="active"    <?= $status_filter==='active'    ? 'selected':'' ?>>Active</option>
                    <option value="suspended" <?= $status_filter==='suspended' ? 'selected':'' ?>>Suspended</option>
                    <option value="pending"   <?= $status_filter==='pending'   ? 'selected':'' ?>>Pending</option>
                </select>
            </div>
            <div style="display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Filter</button>
                <a href="users.php" class="btn btn-ghost btn-sm"><i class="ri-refresh-line"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card fade-in">
    <div class="table-wrapper">
        <table class="data-table" id="usersTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Phone</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="ri-group-line"></i><h3>No users found</h3><p>Try a different filter.</p></div></td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u): ?>
                <tr class="searchable-row">
                    <td style="color:var(--neutral-light);font-size:12px">#<?= $u['id'] ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="avatar-placeholder" style="width:36px;height:36px;font-size:13px;flex-shrink:0"><?= strtoupper(substr($u['name'],0,1)) ?></div>
                            <div>
                                <div style="font-weight:600;color:var(--neutral-dark)"><?= sanitize($u['name']) ?></div>
                                <div style="font-size:11px;color:var(--neutral-light)"><?= sanitize($u['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="role-tag role-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                    <td><?= sanitize($u['phone'] ?? '—') ?></td>
                    <td><strong style="color:var(--secondary)"><?= formatCurrency($u['balance']) ?></strong></td>
                    <td><?= statusBadge($u['status']) ?></td>
                    <td style="color:var(--neutral-light);font-size:12px"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <a href="?edit=<?= $u['id'] ?>" class="btn btn-ghost btn-sm" title="Edit"><i class="ri-edit-line"></i></a>
                            
                            <?php if ($u['status'] === 'pending'): ?>
                            <!-- Delivery / User Approval Actions -->
                            <form method="POST" style="display:inline" onsubmit="return confirm('Approve this delivery agent account?')">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="new_status" value="active">
                                <button type="submit" class="btn btn-sm" style="background:var(--secondary-light);color:var(--secondary-dark);padding:4px 8px;font-size:11px;font-weight:700;" title="Accept / Approve">
                                    <i class="ri-check-line"></i> Accept
                                </button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Reject this delivery agent application?')">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="new_status" value="suspended">
                                <button type="submit" class="btn btn-sm btn-ghost" style="color:var(--danger);padding:4px 8px;font-size:11px;" title="Reject">
                                    <i class="ri-close-line"></i> Reject
                                </button>
                            </form>
                            <?php elseif ($u['status'] === 'active'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="new_status" value="suspended">
                                <button type="submit" class="btn btn-ghost btn-sm" title="Suspend" style="color:var(--warning)"><i class="ri-forbid-line"></i></button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="new_status" value="active">
                                <button type="submit" class="btn btn-ghost btn-sm" title="Activate" style="color:var(--success)"><i class="ri-checkbox-circle-line"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this user?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" title="Delete" style="color:var(--danger)"><i class="ri-delete-bin-line"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer" style="display:flex;align-items:center;justify-content:space-between">
        <span>Showing <?= ($offset+1) ?>–<?= min($offset+$per_page,$total_count) ?> of <?= $total_count ?></span>
        <div class="pagination">
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <a href="?page=<?= $p ?>&q=<?= urlencode($search) ?>&role=<?= urlencode($role_filter) ?>&status=<?= urlencode($status_filter) ?>" class="page-btn <?= $p===$page_num?'active':'' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-user-add-line"></i> Add New User</span>
            <button class="modal-close" data-modal-close><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" data-validate>
                <input type="hidden" name="action" value="add">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="John Doe" required>
                        <div class="form-error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
                        <div class="form-error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <option value="buyer">Buyer</option>
                            <option value="seller">Seller</option>
                            <option value="delivery">Delivery</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" placeholder="+1-555-0000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password <span class="required">*</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Min 8 characters" required>
                        <div class="form-error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:8px">
                    <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($edit_user): ?>
<!-- Edit User Modal (auto-open) -->
<div class="modal-overlay open" id="editUserModal">
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-edit-line"></i> Edit User — <?= sanitize($edit_user['name']) ?></span>
            <a href="users.php" class="modal-close"><i class="ri-close-line"></i></a>
        </div>
        <div class="modal-body">
            <form method="POST" data-validate>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="required">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= sanitize($edit_user['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= sanitize($edit_user['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-control">
                            <?php foreach(['buyer','seller','delivery'] as $r): ?>
                            <option value="<?= $r ?>" <?= $edit_user['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= sanitize($edit_user['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password <span style="color:var(--neutral-light)">(leave blank to keep)</span></label>
                        <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <?php foreach(['active','suspended','pending'] as $s): ?>
                            <option value="<?= $s ?>" <?= $edit_user['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer" style="padding:0;border:none;margin-top:8px">
                    <a href="users.php" class="btn btn-ghost">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
