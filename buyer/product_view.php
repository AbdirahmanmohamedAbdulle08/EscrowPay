<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user = requireLogin(['buyer']);
$page_title = 'Product Details';
$active_page = 'marketplace.php';
$pdo = getDB();
$pdo->exec("CREATE TABLE IF NOT EXISTS product_ratings (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, buyer_id INT NOT NULL, rating TINYINT NOT NULL, review TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY one_rating (product_id,buyer_id)) ENGINE=InnoDB");
$productId = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rate_product') {
    $ratingValue = max(1, min(5, (int)($_POST['rating'] ?? 0))); $review = trim(sanitize($_POST['review'] ?? ''));
    $check = $pdo->prepare("SELECT id FROM transactions WHERE product_id=? AND buyer_id=? AND status='released' LIMIT 1"); $check->execute([$productId, $user['id']]);
    if ($check->fetch()) $pdo->prepare("INSERT INTO product_ratings (product_id,buyer_id,rating,review) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), review=VALUES(review)")->execute([$productId,$user['id'],$ratingValue,$review]);
    header('Location: product_view.php?id=' . $productId . '&rated=1'); exit;
}

$stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.icon AS category_icon, u.name AS seller_name, u.store_name, u.phone AS seller_phone
    FROM products p JOIN users u ON u.id=p.seller_id LEFT JOIN categories c ON c.id=p.category_id
    WHERE p.id=? AND p.status='active' LIMIT 1");
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) { header('Location: ' . APP_URL . '/buyer/marketplace.php?error=product_not_found'); exit; }
$ratingStmt = $pdo->prepare("SELECT ROUND(AVG(rating),1) avg_rating, COUNT(*) rating_count FROM product_ratings WHERE product_id=?"); $ratingStmt->execute([$productId]); $rating = $ratingStmt->fetch();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
$imageUrl = !empty($product['image']) ? APP_URL . '/' . sanitize($product['image']) : '';
$isVideo = !empty($product['video_url']) && filter_var($product['video_url'], FILTER_VALIDATE_URL);
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-shopping-bag-3-line" style="color:var(--secondary)"></i> Product Details</h1>
        <p class="page-subtitle">Eeg xogta oo dhan ka hor intaadan lacagta escrow gelin.</p>
    </div>
    <a href="<?= APP_URL ?>/buyer/marketplace.php" class="btn btn-ghost"><i class="ri-arrow-left-line"></i> Back to Marketplace</a>
</div>

<div style="display:grid;grid-template-columns:minmax(280px, .9fr) minmax(340px, 1.1fr);gap:24px;align-items:start" class="fade-in">
    <div class="card" style="overflow:hidden">
        <?php if ($imageUrl): ?>
            <img src="<?= $imageUrl ?>" alt="<?= sanitize($product['title']) ?>" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block;">
        <?php else: ?>
            <div style="aspect-ratio:1/1;background:<?= $product['type']==='service' ? 'linear-gradient(135deg,#2a52c2,#1D3B8B)' : 'linear-gradient(135deg,#10C87B,#0ca868)' ?>;display:flex;align-items:center;justify-content:center;color:#fff;">
                <i class="<?= $product['category_icon'] ?: 'ri-box-3-line' ?>" style="font-size:80px"></i>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-body" style="padding:26px">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px">
                <div><span class="badge <?= $product['type']==='service' ? 'badge-primary' : 'badge-info' ?>"><?= strtoupper(sanitize($product['type'])) ?></span><div style="font-size:12px;color:var(--neutral);margin-top:8px"><i class="<?= $product['category_icon'] ?: 'ri-price-tag-3-line' ?>"></i> <?= sanitize($product['category_name'] ?? 'General') ?></div></div>
                <span style="font-size:25px;color:var(--primary);font-weight:800"><?= formatCurrency((float)$product['price']) ?></span>
            </div>
            <h2 style="font-size:24px;color:var(--neutral-dark);line-height:1.25;margin-bottom:18px"><?= sanitize($product['title']) ?></h2>
            <div style="color:#d28a00;font-size:14px;margin-bottom:14px">★ <?= $rating['avg_rating'] ? number_format((float)$rating['avg_rating'],1) : 'No ratings yet' ?> <span style="color:var(--neutral-light);font-size:11px">(<?= (int)$rating['rating_count'] ?> reviews)</span></div>
            <div style="border-top:1px solid #e9eff7;padding-top:16px;margin-bottom:18px">
                <div style="font-size:12px;font-weight:800;color:var(--neutral-dark);margin-bottom:7px">Faahfaahinta Alaabta</div>
                <div style="font-size:14px;line-height:1.7;color:var(--neutral);white-space:pre-line"><?= sanitize($product['description'] ?: 'Faahfaahin lama gelin.') ?></div>
            </div>
            <?php if ($isVideo): ?><a href="<?= htmlspecialchars($product['video_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-ghost" style="margin-bottom:18px"><i class="ri-play-circle-line"></i> Daawo Demo Video</a><?php endif; ?>
            <div style="background:#f5f9ff;border-radius:12px;padding:13px 15px;margin-bottom:18px;display:flex;align-items:center;gap:10px"><div class="avatar-placeholder"><?= strtoupper(substr($product['seller_name'],0,1)) ?></div><div><div style="font-size:11px;color:var(--neutral-light)">Seller</div><strong style="font-size:13px"><?= sanitize($product['store_name'] ?: $product['seller_name']) ?></strong></div><span style="margin-left:auto;font-size:11px;color:var(--secondary);font-weight:700"><i class="ri-shield-check-fill"></i> Escrow Protected</span></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap"><a href="<?= APP_URL ?>/buyer/messaging.php?with=<?= $product['seller_id'] ?>" class="btn btn-ghost"><i class="ri-chat-1-line"></i> La hadal Seller</a><a href="<?= APP_URL ?>/buyer/marketplace.php" class="btn btn-primary"><i class="ri-secure-payment-line"></i> Buy with Escrow</a></div>
            <form method="POST" style="margin-top:20px;border-top:1px solid #e9eff7;padding-top:15px"><input type="hidden" name="action" value="rate_product"><label class="form-label">Rate product-kaaga kadib order-ka</label><div style="display:flex;gap:8px;align-items:center"><select name="rating" class="form-control" style="max-width:130px"><option value="5">★★★★★ 5</option><option value="4">★★★★ 4</option><option value="3">★★★ 3</option><option value="2">★★ 2</option><option value="1">★ 1</option></select><input name="review" class="form-control" placeholder="Faallo kooban (optional)"><button class="btn btn-ghost" type="submit">Submit</button></div><div class="form-hint">Rating-ku wuxuu shaqaynayaa kaliya escrow la sii daayay kadib.</div></form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
