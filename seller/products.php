<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['seller']);
$page_title  = 'My Products & Services';
$active_page = 'products.php';
$pdo         = getDB();
$uid         = $user['id'];
try { $pdo->exec("ALTER TABLE products ADD COLUMN image_visibility ENUM('public','private') NOT NULL DEFAULT 'public'"); } catch (Throwable $e) { /* already exists */ }

$success = $error = '';

function validatedAiProductImage(string $path): ?string {
    $path = str_replace('\\', '/', trim($path));
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!preg_match('#^uploads/products/product_ai_' . $uid . '_[a-f0-9]{16}\\.(jpg|png|webp)$#', $path)) return null;
    return file_exists(__DIR__ . '/../' . $path) ? $path : null;
}

// Handle Create / Edit / Delete Product
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_product') {
        $title       = trim(sanitize($_POST['title'] ?? ''));
        $category_id = (int)($_POST['category_id'] ?? 0);
        $type        = sanitize($_POST['type'] ?? 'product');
        $price       = (float)($_POST['price'] ?? 0);
        $description = trim(sanitize($_POST['description'] ?? ''));
        $video_url   = trim(sanitize($_POST['video_url'] ?? ''));
        $status      = sanitize($_POST['status'] ?? 'active');
        $image       = validatedAiProductImage($_POST['ai_image_path'] ?? '');
        $image_visibility = in_array($_POST['image_visibility'] ?? 'public', ['public','private'], true) ? $_POST['image_visibility'] : 'public';

        if (empty($title) || $price <= 0) {
            $error = 'Product title and a valid price greater than 0 are required.';
        } else {
            $pdo->prepare("
                INSERT INTO products (seller_id, category_id, title, description, price, type, image, image_visibility, video_url, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$uid, $category_id ?: null, $title, $description, $price, $type, $image, $image_visibility, $video_url ?: null, $status]);

            logAudit('CREATE_PRODUCT', "Seller created product: $title ($$price)", $uid);
            $success = "Product '$title' listed on Marketplace successfully!";
        }
    } elseif ($action === 'edit_product') {
        $prod_id     = (int)($_POST['product_id'] ?? 0);
        $title       = trim(sanitize($_POST['title'] ?? ''));
        $category_id = (int)($_POST['category_id'] ?? 0);
        $type        = sanitize($_POST['type'] ?? 'product');
        $price       = (float)($_POST['price'] ?? 0);
        $description = trim(sanitize($_POST['description'] ?? ''));
        $video_url   = trim(sanitize($_POST['video_url'] ?? ''));
        $status      = sanitize($_POST['status'] ?? 'active');
        $image       = validatedAiProductImage($_POST['ai_image_path'] ?? '');
        $image_visibility = in_array($_POST['image_visibility'] ?? 'public', ['public','private'], true) ? $_POST['image_visibility'] : 'public';

        // Verify ownership
        $chk = $pdo->prepare("SELECT id FROM products WHERE id=? AND seller_id=?");
        $chk->execute([$prod_id, $uid]);
        if ($chk->fetch()) {
            $pdo->prepare("
                UPDATE products 
                SET title=?, category_id=?, type=?, price=?, description=?, video_url=?, status=?, image_visibility=?, image=COALESCE(?, image)
                WHERE id=? AND seller_id=?
            ")->execute([$title, $category_id ?: null, $type, $price, $description, $video_url ?: null, $status, $image_visibility, $image, $prod_id, $uid]);

            logAudit('UPDATE_PRODUCT', "Seller updated product #$prod_id ($title)", $uid);
            $success = "Product updated successfully!";
        } else {
            $error = 'Product not found.';
        }
    } elseif ($action === 'delete_product') {
        $prod_id = (int)($_POST['product_id'] ?? 0);
        $pdo->prepare("DELETE FROM products WHERE id=? AND seller_id=?")->execute([$prod_id, $uid]);
        logAudit('DELETE_PRODUCT', "Seller deleted product #$prod_id", $uid);
        $success = "Product removed from marketplace.";
    }
}

// Fetch categories
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// Fetch seller products
$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name, c.icon AS category_icon,
           (SELECT COUNT(*) FROM transactions t WHERE t.product_id = p.id) AS sales_count,
           (SELECT COALESCE(SUM(t.net_amount),0) FROM transactions t WHERE t.product_id = p.id AND t.status='released') AS total_earned
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.seller_id = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$uid]);
$products = $stmt->fetchAll();

// Stats
$total_listings = count($products);
$active_listings = count(array_filter($products, fn($p) => $p['status'] === 'active'));
$total_sold = array_sum(array_column($products, 'sales_count'));

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-store-2-line" style="color:var(--secondary)"></i> My Marketplace Listings</h1>
        <p class="page-subtitle">Manage your products and digital services listed on EscrowPay Marketplace</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('addProductModal')">
        <i class="ri-add-circle-line"></i> Add Product / Service
    </button>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Stats Overview -->
<div class="stats-grid stagger fade-in" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Listings</div>
            <div class="stat-value"><?= $total_listings ?></div>
            <div class="stat-change up"><i class="ri-box-3-line"></i> In Catalog</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-store-2-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Active on Marketplace</div>
            <div class="stat-value"><?= $active_listings ?></div>
            <div class="stat-change up"><i class="ri-check-line"></i> Live for Buyers</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-checkbox-circle-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Escrow Orders</div>
            <div class="stat-value"><?= $total_sold ?></div>
            <div class="stat-change"><i class="ri-shopping-cart-2-line"></i> Lifetime Orders</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-shopping-bag-3-line"></i></div>
    </div>
</div>

<!-- Products Table -->
<div class="card fade-in">
    <div class="card-header">
        <span class="card-title"><i class="ri-list-check-2" style="color:var(--primary)"></i> Product & Service Catalog</span>
        <button class="btn btn-ghost btn-sm" onclick="openModal('addProductModal')"><i class="ri-add-line"></i> Add New</button>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Price</th>
                    <th>Escrow Sales</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <i class="ri-store-2-line"></i>
                            <h3>No listings yet</h3>
                            <p>Start selling your products or services with escrow protection.</p>
                            <button class="btn btn-primary btn-sm" onclick="openModal('addProductModal')" style="margin-top:14px;">
                                <i class="ri-add-circle-line"></i> Create First Listing
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($products as $p): ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="width:40px;height:40px;border-radius:10px;background:<?= $p['type'] === 'service' ? 'linear-gradient(135deg,#1D3B8B,#3b5bb5)' : 'linear-gradient(135deg,#10C87B,#0ca868)' ?>;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;overflow:hidden;">
                                <?php if (!empty($p['image'])): ?><img src="<?= APP_URL ?>/<?= sanitize($p['image']) ?>" alt="<?= sanitize($p['title']) ?>" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?><i class="<?= $p['category_icon'] ?: 'ri-box-3-line' ?>" style="font-size:20px;"></i><?php endif; ?>
                            </div>
                            <div>
                                <strong style="color:var(--neutral-dark);font-size:14px;"><?= sanitize($p['title']) ?></strong>
                                <div style="font-size:11px;color:var(--neutral-light);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= sanitize($p['description']) ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td><span style="font-size:12px;color:var(--neutral);"><?= sanitize($p['category_name'] ?? 'General') ?></span></td>
                    <td>
                        <span class="badge <?= $p['type'] === 'service' ? 'badge-primary' : 'badge-info' ?>" style="font-size:10px;">
                            <?= strtoupper($p['type']) ?>
                        </span>
                    </td>
                    <td><strong style="color:var(--primary);font-size:14px;"><?= formatCurrency($p['price']) ?></strong></td>
                    <td>
                        <div style="font-size:12px;font-weight:600;"><?= $p['sales_count'] ?> orders</div>
                        <div style="font-size:10px;color:var(--secondary);"><?= formatCurrency($p['total_earned']) ?> earned</div>
                    </td>
                    <td>
                        <span class="badge <?= $p['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>">
                            <?= ucfirst($p['status']) ?>
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <button class="btn btn-ghost btn-sm" onclick="openEditModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" style="padding:4px 8px;" title="Edit">
                                <i class="ri-edit-line"></i>
                            </button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this listing?')">
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);padding:4px 8px;" title="Delete">
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

<!-- Add Product Modal -->
<div class="modal-overlay" id="addProductModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-add-circle-line"></i> Add Product / Service</span>
            <button class="modal-close" onclick="closeModal('addProductModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="createProductForm">
                <input type="hidden" name="action" value="create_product">
                <input type="hidden" name="ai_image_path" id="aiImagePath">

                <div style="padding:16px;border:1px solid #cfe0ff;border-radius:12px;background:linear-gradient(135deg,#f4f8ff,#f0fffa);margin-bottom:18px;">
                    <div style="display:flex;gap:10px;align-items:flex-start;">
                        <i class="ri-magic-line" style="font-size:24px;color:var(--primary)"></i>
                        <div style="flex:1"><strong style="color:var(--neutral-dark)">AI Magic Product Lister</strong><div class="form-hint" style="margin-top:3px">Geli sawirka alaabta; AI waxay kuu soo jeedinaysaa cinwaan, category, qiime iyo sharraxaad Af-Soomaali ah.</div></div>
                    </div>
                    <div class="form-grid-2" style="margin-top:12px;align-items:end;">
                        <div class="form-group" style="margin:0"><label class="form-label">Sawirka Alaabta</label><input type="file" id="aiProductImage" class="form-control" accept="image/jpeg,image/png,image/webp"></div>
                        <button type="button" class="btn btn-primary" id="analyzeProductBtn" onclick="analyzeProductImage()"><i class="ri-sparkling-line"></i> Generate AI</button>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="url" id="aiImageUrl" class="form-control" placeholder="Link-ga rasmiga ah: sawir ama product page" style="font-size:12px"><button type="button" class="btn btn-ghost btn-sm" onclick="importOnlineImage()"><i class="ri-download-cloud-line"></i> Ka keen link</button></div>
                    <div id="aiProductStatus" class="form-hint" style="margin-top:10px"></div>
                    <img id="aiProductPreview" alt="Product preview" style="display:none;width:72px;height:72px;object-fit:cover;border-radius:10px;margin-top:10px;border:1px solid #dbe4f0;">
                </div>

                <div class="form-group">
                    <label class="form-label">Listing Title <span class="required">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Dell XPS 15, Logo Design, Web App Development..." required>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-control">
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Listing Type</label>
                        <select name="type" class="form-control">
                            <option value="product">Physical Product</option>
                            <option value="service">Digital Service / Freelance</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Sawirka</label>
                    <select name="image_visibility" class="form-control"><option value="public">Public — Buyer-ku wuu arkaa</option><option value="private">Private — Buyer-ku ma arko</option></select>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Price ($ USD) <span class="required">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="ri-money-dollar-circle-line input-icon"></i>
                            <input type="number" name="price" class="form-control" placeholder="0.00" min="1" step="0.01" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active (Visible)</option>
                            <option value="draft">Draft (Hidden)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Sawirka</label><select name="image_visibility" id="editImageVisibility" class="form-control"><option value="public">Public — Buyer-ku wuu arkaa</option><option value="private">Private — Buyer-ku ma arko</option></select></div>

                <div class="form-group">
                    <label class="form-label">Description & Specifications</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Describe the item condition, specs, or service deliverables..."></textarea>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('addProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Publish Listing</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal-overlay" id="editProductModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-edit-line"></i> Edit Listing</span>
            <button class="modal-close" onclick="closeModal('editProductModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="editProductForm">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="product_id" id="editProductId">
                <input type="hidden" name="ai_image_path" id="editAiImagePath">

                <div id="editImageWrap" style="display:none;align-items:center;gap:12px;padding:12px;margin-bottom:16px;border:1px solid #dbe4f0;border-radius:12px;background:#f8fbff;">
                    <img id="editProductImage" src="" alt="Sawirka alaabta" style="width:62px;height:62px;object-fit:cover;border-radius:9px;border:1px solid #dbe4f0;">
                    <div>
                        <strong style="font-size:13px;color:var(--neutral-dark)">Sawirka Listing-ka</strong>
                        <div class="form-hint">Sawirkan ayaa ka muuqanaya marketplace-ka. Edit-ka xogta ayuu beddelaa, sawirkuna siduu yahay ayuu sii ahaanayaa.</div>
                    </div>
                </div>

                <div style="padding:12px;margin-bottom:16px;border:1px dashed #b8cbed;border-radius:12px;background:#f8fbff;">
                    <div style="font-size:13px;font-weight:700;color:var(--neutral-dark);margin-bottom:8px;"><i class="ri-image-add-line" style="color:var(--primary)"></i> Ku dar sawir ama AI ha qorto</div>
                    <div class="form-grid-2" style="align-items:end;">
                        <div class="form-group" style="margin:0"><input type="file" id="editAiProductImage" class="form-control" accept="image/jpeg,image/png,image/webp"></div>
                        <button type="button" class="btn btn-primary" id="editAnalyzeProductBtn" onclick="analyzeProductImage('edit')"><i class="ri-sparkling-line"></i> Generate AI</button>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:9px"><input type="url" id="editAiImageUrl" class="form-control" placeholder="Link-ga rasmiga ah: sawir ama product page" style="font-size:12px"><button type="button" class="btn btn-ghost btn-sm" onclick="importOnlineImage('edit')"><i class="ri-download-cloud-line"></i> Ka keen link</button></div>
                    <div id="editAiProductStatus" class="form-hint" style="margin-top:8px">Haddii product-ku sawir lahayn, geli sawir kadib Generate AI.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Listing Title <span class="required">*</span></label>
                    <input type="text" name="title" id="editTitle" class="form-control" required>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="editCategory" class="form-control">
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Listing Type</label>
                        <select name="type" id="editType" class="form-control">
                            <option value="product">Physical Product</option>
                            <option value="service">Digital Service / Freelance</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Price ($ USD) <span class="required">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="ri-money-dollar-circle-line input-icon"></i>
                            <input type="number" name="price" id="editPrice" class="form-control" min="1" step="0.01" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="editStatus" class="form-control">
                            <option value="active">Active (Visible)</option>
                            <option value="draft">Draft (Hidden)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="ri-video-line" style="color:var(--primary)"></i> Demo Video Link</label>
                    <input type="url" name="video_url" id="editVideoUrl" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="editDescription" class="form-control" rows="3"></textarea>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('editProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-save-line"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
async function analyzeProductImage(mode = 'create') {
    const isEdit = mode === 'edit';
    const form = document.getElementById(isEdit ? 'editProductForm' : 'createProductForm');
    const image = document.getElementById(isEdit ? 'editAiProductImage' : 'aiProductImage').files[0];
    const status = document.getElementById(isEdit ? 'editAiProductStatus' : 'aiProductStatus');
    const button = document.getElementById(isEdit ? 'editAnalyzeProductBtn' : 'analyzeProductBtn');
    if (!image) { generateImageFromTitle(mode); return; }
    const payload = new FormData();
    payload.append('product_image', image);
    button.disabled = true;
    status.style.color = 'var(--primary)'; status.textContent = 'AI waxay falanqaynaysaa sawirkaaga...';
    try {
        const response = await fetch('../api/ai_product_lister.php', { method: 'POST', body: payload });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Falanqaynta AI ma suurtagelin.');
        applyListing(form, data.listing, true);
        document.getElementById(isEdit ? 'editAiImagePath' : 'aiImagePath').value = data.image_path;
        if (isEdit) {
            const editPreview = document.getElementById('editProductImage');
            editPreview.src = data.preview_url;
            document.getElementById('editImageWrap').style.display = 'flex';
        } else {
            const preview = document.getElementById('aiProductPreview'); preview.src = data.preview_url; preview.style.display = 'block';
        }
        status.style.color = 'var(--secondary)';
        status.textContent = 'AI ayaa xogta buuxisay — dib u eeg oo daabac.';
    } catch (error) { status.style.color = 'var(--danger)'; status.textContent = error.message; }
    finally { button.disabled = false; }
}

async function generateImageFromTitle(mode = 'create') {
    const isEdit = mode === 'edit';
    const form = document.getElementById(isEdit ? 'editProductForm' : 'createProductForm');
    const title = form.querySelector('[name="title"]').value.trim();
    const status = document.getElementById(isEdit ? 'editAiProductStatus' : 'aiProductStatus');
    if (!title) { status.style.color = 'var(--danger)'; status.textContent = 'Marka hore geli magaca alaabta, kadib sawirka ka samee.'; return; }
    status.style.color = 'var(--primary)'; status.textContent = 'AI waxay dhammaystiraysaa xogta magaca alaabta...';
    try {
        await completeListingFromName(mode);
        const request = new FormData(); request.append('title', title);
        const imageResponse = await fetch('../api/ai_product_image.php', { method: 'POST', body: request });
        const imageData = await imageResponse.json();
        if (!imageResponse.ok || !imageData.success) throw new Error(imageData.error || 'Xogta waa la buuxiyey, laakiin sawir dhab ah lama helin.');
        document.getElementById(isEdit ? 'editAiImagePath' : 'aiImagePath').value = imageData.image_path;
        if (isEdit) { document.getElementById('editProductImage').src = imageData.preview_url; document.getElementById('editImageWrap').style.display = 'flex'; }
        else { const preview = document.getElementById('aiProductPreview'); preview.src = imageData.preview_url; preview.style.display = 'block'; }
        status.style.color = 'var(--secondary)'; status.textContent = imageData.source === 'real_online_image' ? 'Xogtii iyo sawir dhab ah oo online ah waa la helay.' : 'Xogtii iyo sawir AI ah waa la helay.';
    } catch (error) { status.style.color = 'var(--danger)'; status.textContent = error.message; }
}

function applyListing(form, item, replaceTitle = false) {
    if (replaceTitle && item.title) form.querySelector('[name="title"]').value = item.title;
    if (item.price) form.querySelector('[name="price"]').value = item.price;
    if (item.description) form.querySelector('[name="description"]').value = item.description;
    if (item.type) form.querySelector('[name="type"]').value = item.type;
    const category = form.querySelector('[name="category_id"]');
    if (item.category) [...category.options].some(option => { if (option.text.trim().toLowerCase() === item.category.trim().toLowerCase()) { category.value = option.value; return true; } });
}

async function completeListingFromName(mode = 'create') {
    const isEdit = mode === 'edit'; const form = document.getElementById(isEdit ? 'editProductForm' : 'createProductForm');
    const request = new FormData(); request.append('title', form.querySelector('[name="title"]').value.trim());
    const response = await fetch('../api/ai_product_from_name.php', { method: 'POST', body: request });
    const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.error || 'AI xogta ma dhammaystiri karto.');
    applyListing(form, data.listing);
}

async function importOnlineImage(mode = 'create') {
    const isEdit = mode === 'edit';
    const url = document.getElementById(isEdit ? 'editAiImageUrl' : 'aiImageUrl').value.trim();
    const status = document.getElementById(isEdit ? 'editAiProductStatus' : 'aiProductStatus');
    if (!url) { status.style.color = 'var(--danger)'; status.textContent = 'Geli link-ga sawirka online.'; return; }
    status.style.color = 'var(--primary)'; status.textContent = 'Sawirka online ayaa la keenayaa, kadib AI ayaa falanqaynaysa...';
    try {
        const request = new FormData(); request.append('image_url', url);
        const response = await fetch('../api/ai_product_image_import.php', { method: 'POST', body: request });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.error || 'Sawirka lama keeni karo.');
        const localImage = await fetch(data.preview_url); const blob = await localImage.blob();
        const input = document.getElementById(isEdit ? 'editAiProductImage' : 'aiProductImage');
        const transfer = new DataTransfer(); transfer.items.add(new File([blob], 'online-product-image.jpg', { type: blob.type || 'image/jpeg' })); input.files = transfer.files;
        analyzeProductImage(mode);
    } catch (error) { status.style.color = 'var(--danger)'; status.textContent = error.message; }
}

function openEditModal(prod) {
    document.getElementById('editProductId').value = prod.id;
    document.getElementById('editTitle').value = prod.title;
    document.getElementById('editCategory').value = prod.category_id || '';
    document.getElementById('editType').value = prod.type;
    document.getElementById('editPrice').value = prod.price;
    document.getElementById('editStatus').value = prod.status;
    document.getElementById('editVideoUrl').value = prod.video_url || '';
    document.getElementById('editDescription').value = prod.description || '';
    document.getElementById('editImageVisibility').value = prod.image_visibility || 'public';
    const imageWrap = document.getElementById('editImageWrap');
    const image = document.getElementById('editProductImage');
    if (prod.image) {
        image.src = document.documentElement.dataset.appUrl + '/' + prod.image;
        image.alt = prod.title || 'Sawirka alaabta';
        imageWrap.style.display = 'flex';
    } else {
        image.src = '';
        imageWrap.style.display = 'none';
    }
    openModal('editProductModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
