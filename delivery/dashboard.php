<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/delivery_intelligence.php';
$user        = requireLogin(['delivery']);
$page_title  = 'Delivery Dashboard';
$active_page = 'dashboard.php';
$pdo         = getDB();
$uid         = $user['id'];
ensureDeliveryIntelligence($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_presence') {
    $available = !empty($_POST['is_available']) ? 1 : 0;
    $lat = isset($_POST['latitude']) && is_numeric($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $lng = isset($_POST['longitude']) && is_numeric($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $pdo->prepare('INSERT INTO delivery_presence (delivery_id,latitude,longitude,is_available) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE latitude=VALUES(latitude),longitude=VALUES(longitude),is_available=VALUES(is_available)')->execute([$uid,$lat,$lng,$available]);
    logAudit('DELIVERY_PRESENCE_UPDATED', 'Updated delivery availability/location', $uid);
}
$presenceStmt = $pdo->prepare('SELECT * FROM delivery_presence WHERE delivery_id=?'); $presenceStmt->execute([$uid]); $presence = $presenceStmt->fetch() ?: ['is_available'=>1,'latitude'=>null,'longitude'=>null];
$trust = deliveryTrustScore($pdo, $uid); $trustScore = $trust['score']; $trustBadge = $trust['badge'];

// Stats
$active = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_id=? AND status IN ('assigned','picked_up')"); $active->execute([$uid]); $active_deliveries = $active->fetchColumn();
$done = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_id=? AND status='delivered'"); $done->execute([$uid]); $completed = $done->fetchColumn();
$total_tx = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE delivery_id=?"); $total_tx->execute([$uid]); $total_orders = $total_tx->fetchColumn();

// My pending deliveries
$stmt = $pdo->prepare("
    SELECT d.*, t.ref_code, t.title, u_buyer.address AS b_address, u_seller.address AS s_address
    FROM deliveries d
    JOIN transactions t ON d.transaction_id=t.id
    JOIN users u_buyer ON t.buyer_id=u_buyer.id
    JOIN users u_seller ON t.seller_id=u_seller.id
    WHERE d.delivery_id=? AND d.status IN ('assigned','picked_up')
    ORDER BY d.created_at ASC
");
$stmt->execute([$uid]);
$deliveries = $stmt->fetchAll();

// Earnings & Withdrawal Stats
$earnings_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE user_id=? AND type='escrow_credit' AND status='completed'");
$earnings_stmt->execute([$uid]);
$total_earned = (float)$earnings_stmt->fetchColumn();

$withdrawn_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id=? AND status='completed'");
$withdrawn_stmt->execute([$uid]);
$total_withdrawn = (float)$withdrawn_stmt->fetchColumn();

$fees_stmt = $pdo->prepare("SELECT COALESCE(SUM(fee),0) FROM withdrawals WHERE user_id=? AND status='completed'");
$fees_stmt->execute([$uid]);
$total_fees_paid = (float)$fees_stmt->fetchColumn();
$net_received    = max(0, $total_withdrawn - $total_fees_paid);

$deliv_fee_val = (float)getSetting('delivery_fee', '1.50');
$deliv_com_pct = (float)getSetting('delivery_commission_pct', '0.2');
$deliv_net_est = $deliv_fee_val - round($deliv_fee_val * ($deliv_com_pct / 100), 2);

// Pending assignment count
$cnt_assign = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_id=? AND delivery_accepted=0 AND admin_approved=1 AND status='assigned'");
$cnt_assign->execute([$uid]);
$assigned_pending_count = (int)$cnt_assign->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Delivery Dashboard</h1>
        <p class="page-subtitle">Welcome back, <?= sanitize($user['name']) ?>! Track routes, assignments, earnings, and your AI trust badge.</p>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="deliveries.php?tab=open_jobs" class="btn btn-ghost btn-sm"><i class="ri-store-2-line"></i> Browse Open Jobs</a>
        <a href="deliveries.php" class="btn btn-primary btn-sm"><i class="ri-truck-line"></i> Manage Deliveries</a>
    </div>
</div>

<?php if ($assigned_pending_count > 0): ?>
<div class="alert alert-warning fade-in" style="background:#fffaf0;border:1px solid #fbd38d;color:#744210;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div style="display:flex;align-items:center;gap:10px;">
        <i class="ri-notification-3-fill" style="font-size:22px;color:var(--warning)"></i>
        <div>
            <strong>You have <?= $assigned_pending_count ?> new delivery assignment(s) awaiting your action!</strong>
            <div style="font-size:12px;">Review details and click Accept or Decline to proceed with shipment.</div>
        </div>
    </div>
    <a href="deliveries.php?tab=assigned" class="btn btn-warning btn-sm" style="font-weight:700;">Review & Accept ($1.50)</a>
</div>
<?php endif; ?>

<div class="card fade-in" style="margin-bottom:18px"><div class="card-body" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap"><div><strong><i class="ri-radar-line" style="color:var(--primary)"></i> Smart Delivery Matching</strong><div class="form-hint">Marka aad available tahay oo aad location geliso, orders-ka kuugu dhow ayaa laguugu hormarinayaa.</div></div><form method="POST" id="presenceForm" style="display:flex;gap:8px;align-items:center"><input type="hidden" name="action" value="update_presence"><input type="hidden" name="latitude" id="driverLatitude" value="<?= sanitize((string)($presence['latitude'] ?? '')) ?>"><input type="hidden" name="longitude" id="driverLongitude" value="<?= sanitize((string)($presence['longitude'] ?? '')) ?>"><label style="font-size:12px"><input type="checkbox" name="is_available" value="1" <?= $presence['is_available']?'checked':'' ?>> Available</label><button class="btn btn-primary btn-sm" type="button" onclick="saveDriverLocation()"><i class="ri-crosshair-2-line"></i> Update Location</button><button class="btn btn-ghost btn-sm" type="submit">Save</button></form></div></div>

<div style="margin:-8px 0 18px" class="fade-in">
    <button type="button" class="btn btn-primary" onclick="saveDriverLocation()"><i class="ri-map-pin-user-line"></i> Enable GPS Location &amp; Save Availability</button>
    <span style="font-size:12px;color:var(--neutral);margin-left:8px">Riix badhankan, kadib browser-ka oggolow Location.</span>
</div>

<div class="stats-grid fade-in stagger" style="margin-bottom:24px;">
    <div class="stat-card" style="border-left:4px solid #f59e0b"><div class="stat-info"><div class="stat-label">AI Trust Score</div><div class="stat-value" style="color:#d28a00"><?= $trustScore ?>%</div><div class="stat-change"><i class="ri-shield-star-line"></i> <?= $trustBadge ?></div></div><div class="stat-icon-wrap stat-icon-warning"><i class="ri-shield-star-line"></i></div></div>
    <div class="stat-card"><div class="stat-info"><div class="stat-label">Matching Status</div><div class="stat-value" style="font-size:18px;color:<?= $presence['is_available'] ? 'var(--secondary)' : 'var(--neutral)' ?>"><?= $presence['is_available'] ? 'Available' : 'Offline' ?></div><div class="stat-change"><i class="ri-map-pin-line"></i> <?= $presence['latitude'] !== null ? 'Live location ready' : 'Set location for nearest jobs' ?></div></div><div class="stat-icon-wrap stat-icon-primary"><i class="ri-radar-line"></i></div></div>
    <!-- Balance Card -->
    <div class="stat-card" style="background:linear-gradient(135deg,#10C87B,#06a766);color:#fff;border:none;">
        <div class="stat-info">
            <div class="stat-label" style="color:rgba(255,255,255,.8)">Available Balance</div>
            <div class="stat-value" style="color:#fff"><?= formatCurrency((float)$user['balance']) ?></div>
            <a href="wallet.php" style="display:inline-flex;align-items:center;gap:4px;color:#fff;font-size:11px;font-weight:700;margin-top:4px;text-decoration:underline;">
                <i class="ri-bank-line"></i> Withdraw Money
            </a>
        </div>
        <div class="stat-icon-wrap" style="background:rgba(255,255,255,.15)"><i class="ri-wallet-3-line" style="color:#fff"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Delivery Earnings</div>
            <div class="stat-value"><?= formatCurrency($total_earned) ?></div>
            <div class="stat-change up"><i class="ri-money-dollar-circle-line"></i> <?= formatCurrency($deliv_net_est) ?> per delivery</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-money-dollar-circle-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Net Paid Out</div>
            <div class="stat-value"><?= formatCurrency($net_received) ?></div>
            <div class="stat-change"><i class="ri-check-double-line"></i> Gross: <?= formatCurrency($total_withdrawn) ?></div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-bank-line"></i></div>
    </div>

    <div class="stat-card" style="border-left: 4px solid var(--warning);">
        <div class="stat-info">
            <div class="stat-label">Commission Paid (0.2%)</div>
            <div class="stat-value" style="color:var(--warning);"><?= formatCurrency($total_fees_paid) ?></div>
            <div class="stat-change down"><i class="ri-percent-line"></i> Platform payout fee</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-percent-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Active Deliveries</div>
            <div class="stat-value" data-count="<?= $active_deliveries ?>"><?= $active_deliveries ?></div>
            <div class="stat-change"><i class="ri-truck-line"></i> In transit / assigned</div>
        </div>
        <div class="stat-icon-wrap stat-icon-info"><i class="ri-truck-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Completed Deliveries</div>
            <div class="stat-value" data-count="<?= $completed ?>"><?= $completed ?></div>
            <div class="stat-change up"><i class="ri-checkbox-circle-line"></i> Delivered</div>
        </div>
        <div class="stat-icon-wrap stat-icon-success"><i class="ri-checkbox-multiple-line"></i></div>
    </div>
</div>

<div class="card fade-in">
    <div class="card-header"><span class="card-title"><i class="ri-map-pin-user-line" style="color:var(--primary)"></i> Next Deliveries</span></div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Ref</th><th>Item</th><th>Pickup (Seller)</th><th>Dropoff (Buyer)</th><th>Status</th></tr></thead>
            <tbody>
                <?php if (empty($deliveries)): ?>
                <tr><td colspan="5"><div class="empty-state"><i class="ri-truck-line"></i><h3>No active deliveries</h3><p>You're all caught up!</p></div></td></tr>
                <?php endif; ?>
                <?php foreach($deliveries as $d): ?>
                <tr>
                    <td><strong style="color:var(--primary);font-size:12px"><?= sanitize($d['ref_code']) ?></strong></td>
                    <td><?= sanitize($d['title']) ?></td>
                    <td><?= sanitize($d['s_address'] ?: 'Not provided') ?></td>
                    <td><?= sanitize($d['b_address'] ?: 'Not provided') ?></td>
                    <td><span class="badge badge-<?= $d['status']==='assigned'?'warning':'info' ?>"><?= str_replace('_',' ',$d['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function saveDriverLocation(){
 if(!navigator.geolocation){ alert('Browser-kan location ma taageero.'); return; }
 navigator.geolocation.getCurrentPosition(p=>{document.getElementById('driverLatitude').value=p.coords.latitude;document.getElementById('driverLongitude').value=p.coords.longitude;document.getElementById('presenceForm').submit();},()=>alert('Location lama helin. Fadlan oggolow location permission.'),{enableHighAccuracy:true,timeout:10000});
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
