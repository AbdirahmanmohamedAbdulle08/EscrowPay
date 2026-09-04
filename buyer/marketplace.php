<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/delivery_intelligence.php';
$user        = requireLogin(['buyer']);
$page_title  = 'Marketplace';
$active_page = 'marketplace.php';
$pdo         = getDB();
$uid         = $user['id'];
ensureDeliveryIntelligence($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS product_ratings (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, buyer_id INT NOT NULL, rating TINYINT NOT NULL, review TEXT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY one_rating (product_id,buyer_id)) ENGINE=InnoDB");

$success = $error = '';
$fee_pct  = (float)getSetting('escrow_fee_pct', '10.0');
$currency = getSetting('currency_symbol', '$');

// Handle Buy Now (Instant Escrow Creation with Delivery Fee)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy_product') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $pay_method = sanitize($_POST['payment_method'] ?? 'wallet');
    $notes      = sanitize($_POST['notes'] ?? '');
    $dropoff    = sanitize($_POST['dropoff_address'] ?? '');
    $dropoffLat = isset($_POST['dropoff_latitude']) && is_numeric($_POST['dropoff_latitude']) ? (float)$_POST['dropoff_latitude'] : null;
    $dropoffLng = isset($_POST['dropoff_longitude']) && is_numeric($_POST['dropoff_longitude']) ? (float)$_POST['dropoff_longitude'] : null;

    $stmt = $pdo->prepare("
        SELECT p.*, u.id AS seller_user_id, u.name AS seller_name, u.email AS seller_email
        FROM products p
        JOIN users u ON p.seller_id = u.id
        WHERE p.id = ? AND p.status = 'active'
    ");
    $stmt->execute([$product_id]);
    $prod = $stmt->fetch();

    if (!$prod) {
        $error = 'Product not found or no longer active.';
    } elseif ($prod['seller_user_id'] === $uid) {
        $error = 'You cannot buy your own product.';
    } else {
        $item_price   = (float)$prod['price'];
        // Delivery fee: $1.50 for physical products, $0 for digital services
        $delivery_fee = ($prod['type'] === 'product') ? 1.50 : 0.00;
        $delivery_com = ($delivery_fee > 0) ? round($delivery_fee * 0.002, 2) : 0.00; // 0.2% commission
        
        // Total amount paid by buyer at once (hal mar la bixiyaa)
        $total_amount = $item_price + $delivery_fee;
        
        // Admin escrow fee on item (10%)
        $escrow_fee   = round($item_price * ($fee_pct / 100), 2);
        // Seller net payout
        $seller_net   = $item_price - $escrow_fee;
        
        $ref = generateRefCode();

        // Check wallet balance if paying with wallet
        if ($pay_method === 'wallet') {
            if ((float)$user['balance'] < $total_amount) {
                $error = "Insufficient wallet balance (" . formatCurrency((float)$user['balance']) . "). Total required: " . formatCurrency($total_amount) . " (Item: " . formatCurrency($item_price) . " + Delivery: " . formatCurrency($delivery_fee) . "). Please top up your wallet or select direct payment.";
            } else {
                // Deduct total amount from buyer wallet
                $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?")->execute([$total_amount, $uid]);
                $user['balance'] -= $total_amount;
                
                // Record wallet transaction
                $pdo->prepare("INSERT INTO wallet_transactions 
                    (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
                    VALUES (?, 'escrow_debit', ?, ?, ?, ?, ?, 'wallet', 'completed')")
                    ->execute([$uid, $total_amount, $user['balance'] + $total_amount, $user['balance'], $ref, "Escrow Hold for #$ref ({$prod['title']})"]);
            }
        } else {
            // Direct payment via Gateway (simulated/gateway)
            $pdo->prepare("INSERT INTO wallet_transactions 
                (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
                VALUES (?, 'topup', ?, ?, ?, ?, ?, ?, 'completed')")
                ->execute([$uid, $total_amount, $user['balance'], $user['balance'], 'GW-'.strtoupper(substr(md5(uniqid()),0,8)), "Direct Escrow Payment for {$prod['title']}", $pay_method]);
        }

        if (empty($error)) {
            // Create Transaction with delivery fee breakdown
            $pdo->prepare("
                INSERT INTO transactions 
                (ref_code, buyer_id, seller_id, product_id, title, description, amount, fee, delivery_fee, delivery_commission, net_amount, status, funded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'funded', NOW())
            ")->execute([
                $ref,
                $uid,
                $prod['seller_user_id'],
                $prod['id'],
                $prod['title'],
                $notes ?: $prod['description'],
                $total_amount,
                $escrow_fee,
                $delivery_fee,
                $delivery_com,
                $seller_net
            ]);

            $tx_id = (int)$pdo->lastInsertId();

            // Record in immutable Escrow Ledger (Admin holds total_amount)
            $pdo->prepare("
                INSERT INTO escrow_ledger 
                (transaction_id, type, amount, from_user_id, to_user_id, reference, note)
                VALUES (?, 'hold', ?, ?, ?, ?, ?)
            ")->execute([
                $tx_id,
                $total_amount,
                $uid,
                $prod['seller_user_id'],
                $ref,
                "Escrow hold of " . formatCurrency($total_amount) . " (Item: " . formatCurrency($item_price) . " + Delivery: " . formatCurrency($delivery_fee) . ")"
            ]);

            // If physical product, create delivery assignment stub
            if ($delivery_fee > 0) {
                // Trust, availability, active load, and GPS distance determine the best driver.
                $dropArea = trim((string)($dropoff ?: $user['address']));
                $deliv = smartMatchDelivery($pdo, $dropoffLat, $dropoffLng, $dropArea);
                $deliv_id = $deliv['id'] ?? null;
                if ($deliv_id) {
                    $pdo->prepare("UPDATE transactions SET delivery_id = ? WHERE id = ?")->execute([$deliv_id, $tx_id]);
                    $pdo->prepare("INSERT INTO deliveries (transaction_id, delivery_id, dropoff_address, dropoff_latitude, dropoff_longitude, match_score, status) VALUES (?, ?, ?, ?, ?, ?, 'assigned')")
                        ->execute([$tx_id, $deliv_id, $dropoff ?: $user['address'], $dropoffLat, $dropoffLng, $deliv['match_score']]);
                    addNotification($deliv_id, 'New Delivery Assigned!', "You have a new package delivery #$ref ($1.5 fee).", 'info', APP_URL . '/delivery/deliveries.php');
                }
            }

            // Notify Seller
            addNotification(
                $prod['seller_user_id'],
                'New Escrow Order!',
                "Buyer {$user['name']} placed an escrow order for {$prod['title']} (" . formatCurrency($total_amount) . " held in Escrow). Net Payout: " . formatCurrency($seller_net),
                'info',
                APP_URL . '/seller/orders.php'
            );

            logAudit('MARKETPLACE_ORDER', "Buyer bought product #{$prod['id']} ({$prod['title']}) via Escrow #$ref (Total: $$total_amount, Delivery: $$delivery_fee)", $uid);

            header("Location: my_orders.php?success=" . urlencode("Escrow Order #$ref created! Total " . formatCurrency($total_amount) . " locked safely in Escrow until delivery is confirmed."));
            exit;
        }
    }
}

// Fetch categories for filtering
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

// Filters & Search
$cat_filter  = sanitize($_GET['category'] ?? '');
$type_filter = sanitize($_GET['type'] ?? '');
$search_q    = trim(sanitize($_GET['q'] ?? ''));
$sort        = sanitize($_GET['sort'] ?? 'newest');

$where = ["p.status = 'active'"];
$params = [];

if (!empty($cat_filter)) {
    $where[] = "c.slug = ?";
    $params[] = $cat_filter;
}
if (!empty($type_filter) && in_array($type_filter, ['product', 'service'])) {
    $where[] = "p.type = ?";
    $params[] = $type_filter;
}
if (!empty($search_q)) {
    // Tokenized, case-insensitive search: every meaningful word may match title,
    // description, category, or seller name (e.g. "mobile iphone" finds all iPhones).
    $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($search_q), -1, PREG_SPLIT_NO_EMPTY);
    $tokens = array_values(array_filter(array_unique($tokens), fn($word) => mb_strlen($word) >= 2));
    if ($tokens) {
        $tokenParts = [];
        foreach ($tokens as $word) {
            // Common Somali/English typo tolerance for iPhone searches.
            if ($word === 'ihone') $word = 'iphone';
            $tokenParts[] = "(LOWER(p.title) LIKE ? OR LOWER(p.description) LIKE ? OR LOWER(c.name) LIKE ? OR LOWER(u.name) LIKE ?)";
            $like = '%' . $word . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $where[] = '(' . implode(' OR ', $tokenParts) . ')';
    }
}

$whereSQL = implode(' AND ', $where);

$orderBy = "p.created_at DESC";
if ($sort === 'price_asc')  $orderBy = "p.price ASC";
if ($sort === 'price_desc') $orderBy = "p.price DESC";

$query = "
    SELECT p.*, c.name AS category_name, c.icon AS category_icon, u.name AS seller_name, u.id AS seller_user_id,
           (SELECT ROUND(AVG(rating),1) FROM product_ratings r WHERE r.product_id=p.id) AS avg_rating,
           (SELECT COUNT(*) FROM product_ratings r2 WHERE r2.product_id=p.id) AS rating_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    JOIN users u ON p.seller_id = u.id
    WHERE $whereSQL
    ORDER BY $orderBy
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();
$topSellers = $pdo->query("SELECT u.name, COALESCE(u.store_name,u.name) AS display_name, COUNT(t.id) AS sales, ROUND(AVG(r.rating),1) AS avg_rating FROM users u LEFT JOIN transactions t ON t.seller_id=u.id AND t.status IN ('released','delivered') LEFT JOIN product_ratings r ON r.product_id=t.product_id WHERE u.role='seller' GROUP BY u.id ORDER BY sales DESC, avg_rating DESC LIMIT 5")->fetchAll();
foreach ($products as &$product) { if (($product['image_visibility'] ?? 'public') === 'private') $product['image'] = null; }
unset($product);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-shopping-cart-2-line" style="color:var(--primary)"></i> Escrow Marketplace</h1>
        <p class="page-subtitle">Buy verified products & digital services with 100% money-back escrow guarantee</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <div style="background:#fff;padding:8px 14px;border-radius:12px;box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:8px;">
            <i class="ri-wallet-3-line" style="color:var(--primary);font-size:18px;"></i>
            <span style="font-size:12px;color:var(--neutral)">Wallet: </span>
            <strong style="color:var(--primary);font-size:14px;"><?= formatCurrency((float)$user['balance']) ?></strong>
            <a href="wallet.php" class="btn btn-ghost btn-sm" style="padding:2px 8px;font-size:11px;"><i class="ri-add-line"></i> Top Up</a>
        </div>
        <a href="new_order.php" class="btn btn-ghost btn-sm"><i class="ri-edit-circle-line"></i> Custom Order</a>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Escrow Guarantee Banner -->
<div class="card fade-in" style="background:linear-gradient(135deg, #1D3B8B 0%, #152d6e 100%);color:#fff;border:none;margin-bottom:24px;overflow:hidden;position:relative;">
    <div style="position:absolute;right:-20px;bottom:-30px;opacity:.08;font-size:180px;"><i class="ri-shield-check-fill"></i></div>
    <div class="card-body" style="padding:22px 28px;position:relative;z-index:1;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div>
                <span class="badge" style="background:rgba(16,200,123,.2);color:#10C87B;border:1px solid rgba(16,200,123,.3);margin-bottom:8px;">
                    <i class="ri-shield-check-line"></i> Buyer Protection & Delivery Guarantee
                </span>
                <h2 style="font-size:22px;font-weight:800;color:#fff;margin-bottom:6px;">Single Secure Payment with Full Escrow Hold</h2>
                <p style="font-size:13px;color:rgba(255,255,255,.8);max-width:650px;line-height:1.5;">
                    Buyer pays <strong>Item Price + $1.50 Delivery</strong> in one combined escrow hold. Funds are locked safely until you inspect and approve delivery.
                </p>
            </div>
            <div style="display:flex;gap:16px;align-items:center;">
                <div style="text-align:center;">
                    <div style="width:40px;height:40px;background:rgba(255,255,255,.12);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto 4px;"><i class="ri-lock-2-line" style="font-size:18px;"></i></div>
                    <div style="font-size:10px;color:rgba(255,255,255,.8);font-weight:600;">1. Total Held</div>
                </div>
                <i class="ri-arrow-right-s-line" style="color:rgba(255,255,255,.4);"></i>
                <div style="text-align:center;">
                    <div style="width:40px;height:40px;background:rgba(255,255,255,.12);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto 4px;"><i class="ri-truck-line" style="font-size:18px;"></i></div>
                    <div style="font-size:10px;color:rgba(255,255,255,.8);font-weight:600;">2. Delivery ($1.50)</div>
                </div>
                <i class="ri-arrow-right-s-line" style="color:rgba(255,255,255,.4);"></i>
                <div style="text-align:center;">
                    <div style="width:40px;height:40px;background:rgba(16,200,123,.25);border-radius:10px;display:flex;align-items:center;justify-content:center;margin:0 auto 4px;"><i class="ri-checkbox-circle-line" style="font-size:18px;color:#10C87B;"></i></div>
                    <div style="font-size:10px;color:#10C87B;font-weight:700;">3. Release</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card fade-in" style="margin-bottom:24px;">
    <div class="card-body" style="padding:16px 20px;">
        <form method="GET" style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
            <div style="flex:1;min-width:240px;">
                <div class="input-icon-wrap">
                    <i class="ri-search-line input-icon"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search products, services, tech, watches, dev..." value="<?= sanitize($search_q) ?>">
                </div>
            </div>

            <div style="min-width:180px;">
                <select name="category" class="form-control" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['slug'] ?>" <?= $cat_filter === $cat['slug'] ? 'selected' : '' ?>>
                        <?= sanitize($cat['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:6px;background:var(--tertiary);padding:4px;border-radius:10px;">
                <a href="?category=<?= urlencode($cat_filter) ?>&q=<?= urlencode($search_q) ?>&type=&sort=<?= urlencode($sort) ?>" 
                   class="btn btn-sm <?= empty($type_filter) ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px;font-size:12px;">All</a>
                <a href="?category=<?= urlencode($cat_filter) ?>&q=<?= urlencode($search_q) ?>&type=product&sort=<?= urlencode($sort) ?>" 
                   class="btn btn-sm <?= $type_filter === 'product' ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px;font-size:12px;"><i class="ri-box-3-line"></i> Products</a>
                <a href="?category=<?= urlencode($cat_filter) ?>&q=<?= urlencode($search_q) ?>&type=service&sort=<?= urlencode($sort) ?>" 
                   class="btn btn-sm <?= $type_filter === 'service' ? 'btn-primary' : 'btn-ghost' ?>" style="padding:6px 12px;font-size:12px;"><i class="ri-code-s-slash-line"></i> Services</a>
            </div>

            <div style="min-width:150px;">
                <select name="sort" class="form-control" onchange="this.form.submit()">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                    <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-3-line"></i> Filter</button>
            <?php if (!empty($search_q) || !empty($cat_filter) || !empty($type_filter)): ?>
            <a href="marketplace.php" class="btn btn-ghost btn-sm"><i class="ri-close-line"></i> Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if (!empty($topSellers)): ?><div class="card fade-in" style="margin-bottom:20px"><div class="card-header"><span class="card-title"><i class="ri-trophy-line" style="color:#f59e0b"></i> Top Sellers</span></div><div class="card-body" style="display:flex;gap:12px;overflow:auto;padding:14px 18px"><?php foreach ($topSellers as $rank => $seller): ?><div style="min-width:170px;background:#f8fbff;border:1px solid #e5edf8;border-radius:12px;padding:12px"><div style="font-size:11px;color:#f59e0b;font-weight:800">#<?= $rank+1 ?> · <?= (int)$seller['sales'] ?> sales</div><strong style="display:block;font-size:13px;margin:5px 0;color:var(--neutral-dark)"><?= sanitize($seller['display_name']) ?></strong><span style="font-size:12px;color:#d28a00">★ <?= $seller['avg_rating'] ? number_format((float)$seller['avg_rating'],1) : 'New' ?></span></div><?php endforeach; ?></div></div><?php endif; ?>

<!-- Product Grid -->
<?php if (empty($products)): ?>
<div class="card fade-in">
    <div class="card-body">
        <div class="empty-state">
            <i class="ri-store-2-line"></i>
            <h3>No products found</h3>
            <p>Try resetting filters or search with another keyword.</p>
            <a href="marketplace.php" class="btn btn-primary btn-sm" style="margin-top:14px;"><i class="ri-refresh-line"></i> Clear Filters</a>
        </div>
    </div>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(290px, 1fr));gap:20px;" class="fade-in stagger">
    <?php foreach ($products as $p): 
        $hasVideo = !empty($p['video_url']);
    ?>
    <div class="card product-card" style="display:flex;flex-direction:column;justify-content:space-between;border-radius:16px;overflow:hidden;transition:transform .2s, box-shadow .2s;">
        <div>
            <!-- Card Header / Visual Banner -->
            <div style="background:<?= !empty($p['image']) ? 'linear-gradient(rgba(11,30,69,.3),rgba(11,30,69,.5)), url(\'' . APP_URL . '/' . sanitize($p['image']) . '\') center/cover' : ($p['type'] === 'service' ? 'linear-gradient(135deg, #2a52c2, #1D3B8B)' : 'linear-gradient(135deg, #10C87B, #0ca868)') ?>;padding:22px;color:#fff;display:flex;align-items:center;justify-content:space-between;position:relative;">
                <div style="width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;">
                    <i class="<?= $p['category_icon'] ?: ($p['type'] === 'service' ? 'ri-code-box-line' : 'ri-macbook-line') ?>" style="font-size:24px;color:#fff;"></i>
                </div>
                <div style="text-align:right;">
                    <span class="badge" style="background:rgba(255,255,255,.25);color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:.5px;font-weight:700;">
                        <?= strtoupper($p['type']) ?>
                    </span>
                    <div style="font-size:10px;color:rgba(255,255,255,.8);margin-top:4px;"><?= sanitize($p['category_name'] ?? 'General') ?></div>
                </div>
            </div>

            <!-- Card Body -->
            <div class="card-body" style="padding:18px 20px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
                    <h3 style="font-size:15px;font-weight:700;color:var(--neutral-dark);line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:40px;flex:1;">
                        <?= sanitize($p['title']) ?>
                    </h3>
                </div>

                <div style="font-size:11px;color:#d28a00;margin:-3px 0 10px">★ <?= !empty($p['avg_rating']) ? number_format((float)$p['avg_rating'],1) : 'New' ?> <span style="color:var(--neutral-light)">(<?= (int)($p['rating_count'] ?? 0) ?>)</span></div>

                <p style="font-size:12px;color:var(--neutral);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:12px;min-height:36px;">
                    <?= sanitize($p['description']) ?>
                </p>

                <?php if ($hasVideo): ?>
                <!-- Video Demo Button -->
                <button type="button" class="btn btn-ghost btn-sm" onclick="openVideoModal('<?= addslashes(sanitize($p['title'])) ?>', '<?= htmlspecialchars($p['video_url'], ENT_QUOTES, 'UTF-8') ?>')" style="width:100%;margin-bottom:12px;background:#f0f5ff;color:var(--primary);font-size:11px;font-weight:700;border:1px solid #d0e0fc;padding:6px;">
                    <i class="ri-play-circle-fill" style="font-size:15px;color:#ef4444;"></i> Watch Video Demo
                </button>
                <?php endif; ?>

                <!-- Seller Info & Escrow Tag & Direct Message -->
                <div style="display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid #f0f4fa;margin-bottom:6px;">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div class="avatar-placeholder" style="width:24px;height:24px;font-size:10px;"><?= strtoupper(substr($p['seller_name'],0,1)) ?></div>
                        <span style="font-size:11px;color:var(--neutral);font-weight:600;"><?= sanitize($p['seller_name']) ?></span>
                    </div>
                    <span style="font-size:10px;color:var(--secondary);display:flex;align-items:center;gap:3px;font-weight:700;">
                        <i class="ri-shield-check-fill"></i> Escrow Vault
                    </span>
                </div>
            </div>
        </div>

        <!-- Card Footer -->
        <div style="padding:14px 20px;background:#f9fbfe;border-top:1px solid #eef3fb;display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <div>
                <div style="font-size:9px;color:var(--neutral-light);text-transform:uppercase;">Price <?= $p['type'] === 'product' ? '(+$1.50 Delivery)' : '' ?></div>
                <div style="font-size:18px;font-weight:800;color:var(--primary);"><?= formatCurrency($p['price']) ?></div>
            </div>
            <div style="display:flex;gap:6px;align-items:center;">
                <a href="<?= APP_URL ?>/buyer/product_view.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm" style="padding:8px 10px;border-radius:10px;" title="View full details"><i class="ri-eye-line"></i> View</a>
                <a href="<?= APP_URL ?>/buyer/messaging.php?with=<?= $p['seller_id'] ?>" class="btn btn-ghost btn-sm" style="padding:8px 10px;border-radius:10px;background:#edf3fc;color:var(--primary);border:1px solid #d0e0fc;" title="Message <?= sanitize($p['seller_name']) ?>">
                    <i class="ri-chat-1-line" style="font-size:14px;"></i> Chat
                </a>
                <button class="btn btn-primary btn-sm" onclick="openCheckoutModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" style="padding:8px 14px;border-radius:10px;font-size:12px;white-space:nowrap;">
                    <i class="ri-secure-payment-line"></i> Buy with Escrow
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Escrow Checkout Modal with Delivery Fee ($15) -->
<div class="modal-overlay" id="checkoutModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-shield-check-fill" style="color:var(--secondary);"></i> Escrow Checkout</span>
            <button class="modal-close" onclick="closeModal('checkoutModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="checkoutForm">
                <input type="hidden" name="action" value="buy_product">
                <input type="hidden" name="product_id" id="modalProductId">

                <!-- Item summary box -->
                <div style="background:var(--tertiary);border-radius:14px;padding:14px 16px;margin-bottom:14px;border:1px solid #e2eaf8;">
                    <div style="display:flex;gap:12px;align-items:center;">
                        <div style="width:44px;height:44px;border-radius:10px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">
                            <i class="ri-box-3-line" id="modalIcon"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <span id="modalTypeBadge" class="badge badge-primary" style="font-size:9px;">PRODUCT</span>
                            <h4 id="modalTitle" style="font-size:14px;font-weight:700;color:var(--neutral-dark);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></h4>
                            <div style="font-size:11px;color:var(--neutral-light);">Seller: <span id="modalSeller" style="font-weight:600;color:var(--neutral-dark);"></span></div>
                        </div>
                    </div>
                </div>

                <!-- Escrow Protection Note -->
                <div style="background:#eefcf6;border:1px solid #c1f3de;border-radius:12px;padding:10px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;">
                    <i class="ri-shield-check-line" style="color:var(--secondary);font-size:20px;flex-shrink:0;"></i>
                    <div style="font-size:11px;color:#0b663b;line-height:1.4;">
                        <strong>Escrow Vault Protection:</strong> 100% of your total payment is held in Escrow by Admin. Seller and Delivery agent are only paid after you verify receipt.
                    </div>
                </div>

                <!-- Payment Method Selection -->
                <div class="form-group">
                    <label class="form-label" style="font-weight:700;"><i class="ri-bank-card-line" style="color:var(--primary);"></i> Payment Source</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <label style="cursor:pointer;">
                            <input type="radio" name="payment_method" value="wallet" checked style="display:none" class="chk-pm-radio">
                            <div class="chk-pm-card" style="padding:10px 12px;border:2px solid var(--primary);border-radius:12px;background:#f0f5ff;transition:all .2s;">
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                                    <i class="ri-wallet-3-line" style="color:var(--primary);font-size:16px;"></i>
                                    <span style="font-size:12px;font-weight:700;color:var(--neutral-dark);">My Wallet</span>
                                </div>
                                <div style="font-size:10px;color:var(--neutral-light);">Balance: <strong><?= formatCurrency((float)$user['balance']) ?></strong></div>
                            </div>
                        </label>

                        <label style="cursor:pointer;">
                            <input type="radio" name="payment_method" value="evc_plus" style="display:none" class="chk-pm-radio">
                            <div class="chk-pm-card" style="padding:10px 12px;border:2px solid #e8edf5;border-radius:12px;background:#fff;transition:all .2s;">
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                                    <i class="ri-phone-line" style="color:#1D3B8B;font-size:16px;"></i>
                                    <span style="font-size:12px;font-weight:700;color:var(--neutral-dark);">EVC Plus</span>
                                </div>
                                <div style="font-size:10px;color:var(--neutral-light);">Instant Mobile Escrow</div>
                            </div>
                        </label>

                        <label style="cursor:pointer;">
                            <input type="radio" name="payment_method" value="waafi" style="display:none" class="chk-pm-radio">
                            <div class="chk-pm-card" style="padding:10px 12px;border:2px solid #e8edf5;border-radius:12px;background:#fff;transition:all .2s;">
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                                    <i class="ri-smartphone-line" style="color:#10C87B;font-size:16px;"></i>
                                    <span style="font-size:12px;font-weight:700;color:var(--neutral-dark);">Waafi Pay</span>
                                </div>
                                <div style="font-size:10px;color:var(--neutral-light);">Instant Mobile Escrow</div>
                            </div>
                        </label>

                        <label style="cursor:pointer;">
                            <input type="radio" name="payment_method" value="card" style="display:none" class="chk-pm-radio">
                            <div class="chk-pm-card" style="padding:10px 12px;border:2px solid #e8edf5;border-radius:12px;background:#fff;transition:all .2s;">
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                                    <i class="ri-bank-card-2-line" style="color:#f59e0b;font-size:16px;"></i>
                                    <span style="font-size:12px;font-weight:700;color:var(--neutral-dark);">Debit Card</span>
                                </div>
                                <div style="font-size:10px;color:var(--neutral-light);">Visa / Mastercard</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Delivery Address / Notes -->
                <div class="form-group" id="deliveryAddressGroup">
                    <label class="form-label"><i class="ri-map-pin-line" style="color:var(--primary)"></i> Delivery Drop-off Address</label>
                    <input type="text" name="dropoff_address" class="form-control" placeholder="e.g. Km4, Hodan District, Mogadishu" value="<?= sanitize($user['address'] ?? '') ?>">
                    <input type="hidden" name="dropoff_latitude" id="dropoffLatitude"><input type="hidden" name="dropoff_longitude" id="dropoffLongitude">
                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px" onclick="captureDropoffLocation()"><i class="ri-crosshair-2-line"></i> Use my live location</button><span id="locationStatus" class="form-hint" style="margin-left:8px"></span>
                </div>

                <!-- Pricing & Fee Breakdown (Single Combined Total) -->
                <div style="background:#f8fafd;border-radius:12px;padding:14px;margin-bottom:16px;border:1px solid #e8effa;">
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                        <span style="color:var(--neutral)">Item Price:</span>
                        <strong id="breakdownPrice">$0.00</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;" id="deliveryRow">
                        <span style="color:var(--neutral)">Standard Delivery Fee:</span>
                        <strong style="color:var(--primary);">$1.50</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--neutral-light);margin-bottom:4px;">
                        <span>Admin 10% Platform Fee:</span>
                        <span><span id="breakdownFee">$0.00</span> (included in seller payout)</span>
                    </div>
                    <div style="border-top:1px solid #e2eaf8;margin:8px 0;"></div>
                    <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:800;">
                        <span>Total Paid (Escrow Locked):</span>
                        <span style="color:var(--primary)" id="breakdownTotal">$0.00</span>
                    </div>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('checkoutModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-lg" style="padding:10px 20px;">
                        <i class="ri-lock-2-line"></i> Pay & Lock in Escrow
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Video Demo Player Modal -->
<div class="modal-overlay" id="videoModal">
    <div class="modal" style="max-width:640px;background:#000;color:#fff;padding:0;overflow:hidden;">
        <div style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;background:#111;">
            <span id="videoModalTitle" style="font-size:14px;font-weight:700;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Video Demo</span>
            <button class="modal-close" onclick="closeVideoModal()" style="color:#fff;"><i class="ri-close-line"></i></button>
        </div>
        <div id="videoContainer" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;background:#000;">
            <!-- Iframe or Video inserted here via JS -->
        </div>
    </div>
</div>

<style>
.product-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(29,59,139,.12); }
</style>

<script>
function openCheckoutModal(prod) {
    document.getElementById('modalProductId').value = prod.id;
    document.getElementById('modalTitle').textContent = prod.title;
    document.getElementById('modalSeller').textContent = prod.seller_name;
    document.getElementById('modalTypeBadge').textContent = prod.type.toUpperCase();
    
    const price = parseFloat(prod.price);
    const isPhysical = (prod.type === 'product');
    const deliveryFee = isPhysical ? 1.50 : 0.00;
    const total = price + deliveryFee;
    const feePct = <?= $fee_pct ?>;
    const escrowFee = (price * feePct / 100).toFixed(2);
    
    document.getElementById('breakdownPrice').textContent = '$' + price.toFixed(2);
    document.getElementById('deliveryRow').style.display = isPhysical ? 'flex' : 'none';
    document.getElementById('breakdownFee').textContent = '$' + escrowFee;
    document.getElementById('breakdownTotal').textContent = '$' + total.toFixed(2);
    
    openModal('checkoutModal');
}

// Video Player Modal
function openVideoModal(title, url) {
    document.getElementById('videoModalTitle').textContent = title + ' — Video Demonstration';
    const container = document.getElementById('videoContainer');
    
    // Check if YouTube
    let embedHtml = '';
    if (url.includes('youtube.com') || url.includes('youtu.be')) {
        let videoId = '';
        if (url.includes('v=')) {
            videoId = url.split('v=')[1].split('&')[0];
        } else if (url.includes('youtu.be/')) {
            videoId = url.split('youtu.be/')[1].split('?')[0];
        }
        embedHtml = '<iframe src="https://www.youtube.com/embed/' + videoId + '?autoplay=1" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" allow="autoplay; encrypted-media" allowfullscreen></iframe>';
    } else {
        // Direct MP4 / video
        embedHtml = '<video src="' + url + '" controls autoplay style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain;"></video>';
    }
    
    container.innerHTML = embedHtml;
    openModal('videoModal');
}

function closeVideoModal() {
    document.getElementById('videoContainer').innerHTML = '';
    closeModal('videoModal');
}

function captureDropoffLocation() {
    const status = document.getElementById('locationStatus');
    if (!navigator.geolocation) { status.textContent = 'Browser-kan location ma taageero.'; return; }
    status.textContent = 'Location-ka ayaa la helayaa...';
    navigator.geolocation.getCurrentPosition(p => { document.getElementById('dropoffLatitude').value=p.coords.latitude; document.getElementById('dropoffLongitude').value=p.coords.longitude; status.textContent='Goobtaada waa la kaydiyey si darawalka ugu dhow loo doorto.'; }, () => status.textContent='Location lama helin; address-ka ayaa la adeegsanayaa.', {enableHighAccuracy:true,timeout:10000});
}

document.querySelectorAll('.chk-pm-radio').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.chk-pm-card').forEach(c => {
            c.style.borderColor = '#e8edf5';
            c.style.background = '#fff';
        });
        const card = this.closest('label').querySelector('.chk-pm-card');
        card.style.borderColor = 'var(--primary)';
        card.style.background = '#f0f5ff';
    });
});
document.querySelectorAll('.chk-pm-card').forEach(card => {
    card.addEventListener('click', function() {
        const radio = this.closest('label').querySelector('.chk-pm-radio');
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
