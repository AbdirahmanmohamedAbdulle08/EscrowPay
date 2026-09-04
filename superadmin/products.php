<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Marketplace Products';
$active_page = 'products.php';
$pdo         = getDB();

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $prod_id = (int)($_POST['product_id'] ?? 0);

    if ($action === 'toggle_status' && $prod_id) {
        $new_status = ($_POST['status'] ?? 'active') === 'active' ? 'draft' : 'active';
        $pdo->prepare("UPDATE products SET status=? WHERE id=?")->execute([$new_status, $prod_id]);
        logAudit('ADMIN_TOGGLE_PRODUCT', "Superadmin updated product #$prod_id status to $new_status", $user['id']);
        $success = "Product status updated to $new_status.";
    } elseif ($action === 'delete_product' && $prod_id) {
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$prod_id]);
        logAudit('ADMIN_DELETE_PRODUCT', "Superadmin deleted product #$prod_id", $user['id']);
        $success = "Product deleted from marketplace.";
    }
}

// Search & Filter
$search   = trim(sanitize($_GET['q'] ?? ''));
$cat_slug = sanitize($_GET['category'] ?? '');
$type     = sanitize($_GET['type'] ?? '');

$where = ['1=1'];
$params = [];

if (!empty($search)) {
    $where[] = "(p.title LIKE ? OR u.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!empty($cat_slug)) {
    $where[] = "c.slug = ?";
    $params[] = $cat_slug;
}
if (!empty($type) && in_array($type, ['product', 'service'])) {
    $where[] = "p.type = ?";
    $params[] = $type;
}

$whereSQL = implode(' AND ', $where);

$products = $pdo->prepare("
    SELECT p.*, c.name AS category_name, c.icon AS category_icon, u.name AS seller_name, u.email AS seller_email,
           (SELECT COUNT(*) FROM transactions t WHERE t.product_id = p.id) AS orders_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    JOIN users u ON p.seller_id = u.id
    WHERE $whereSQL
    ORDER BY p.created_at DESC
");
$products->execute($params);
$items = $products->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-shopping-bag-3-line" style="color:var(--primary)"></i> Marketplace Products</h1>
        <p class="page-subtitle">Manage all listings posted by sellers across the platform</p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Filters -->
<div class="card fade-in" style="margin-bottom:20px;">
    <div class="card-body" style="padding:16px;">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
                <label class="form-label">Search Title / Seller</label>
                <div class="input-icon-wrap">
                    <i class="ri-search-line input-icon"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search..." value="<?= sanitize($search) ?>">
                </div>
            </div>
            <div>
                <label class="form-label">Category</label>
                <select name="category" class="form-control">
                    <option value="">All</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['slug'] ?>" <?= $cat_slug === $c['slug'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label">Type</label>
                <select name="type" class="form-control">
                    <option value="">All</option>
                    <option value="product" <?= $type === 'product' ? 'selected' : '' ?>>Products</option>
                    <option value="service" <?= $type === 'service' ? 'selected' : '' ?>>Services</option>
                </select>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Filter</button>
                <a href="products.php" class="btn btn-ghost btn-sm"><i class="ri-refresh-line"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Seller</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Price</th>
                    <th>Orders</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="9"><div class="empty-state"><i class="ri-store-2-line"></i><h3>No products found</h3></div></td></tr>
                <?php endif; ?>

                <?php foreach ($items as $p): ?>
                <tr>
                    <td>
                        <strong style="color:var(--neutral-dark);font-size:13px;"><?= sanitize($p['title']) ?></strong>
                        <?php if (!empty($p['video_url'])): ?>
                        <a href="<?= htmlspecialchars($p['video_url']) ?>" target="_blank" style="display:inline-flex;align-items:center;gap:3px;font-size:10px;color:#ef4444;font-weight:700;margin-left:6px;">
                            <i class="ri-play-circle-fill"></i> Video
                        </a>
                        <?php endif; ?>
                        <div style="font-size:11px;color:var(--neutral-light);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= sanitize($p['description']) ?>
                        </div>
                    </td>
                    <td>
                        <strong style="font-size:12px;"><?= sanitize($p['seller_name']) ?></strong>
                        <div style="font-size:10px;color:var(--neutral-light);"><?= sanitize($p['seller_email']) ?></div>
                    </td>
                    <td><span style="font-size:12px;color:var(--neutral);"><?= sanitize($p['category_name'] ?? 'General') ?></span></td>
                    <td>
                        <span class="badge <?= $p['type'] === 'service' ? 'badge-primary' : 'badge-info' ?>" style="font-size:9px;">
                            <?= strtoupper($p['type']) ?>
                        </span>
                    </td>
                    <td><strong style="color:var(--primary);font-size:13px;"><?= formatCurrency($p['price']) ?></strong></td>
                    <td><span class="badge badge-neutral"><?= $p['orders_count'] ?> orders</span></td>
                    <td><?= statusBadge($p['status']) ?></td>
                    <td style="font-size:11px;color:var(--neutral-light);"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="status" value="<?= $p['status'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="padding:4px 8px;font-size:11px;" title="Toggle Visibility">
                                    <i class="<?= $p['status'] === 'active' ? 'ri-eye-off-line' : 'ri-eye-line' ?>"></i>
                                </button>
                            </form>

                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this listing?')">
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:4px 8px;font-size:11px;" title="Delete">
                                    <i class="ri-delete-bin-line"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
