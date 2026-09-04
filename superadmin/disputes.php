<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Dispute Resolution Center';
$active_page = 'disputes.php';
$pdo         = getDB();

$success = $error = '';

// Handle Dispute Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $dispute_id = (int)($_POST['dispute_id'] ?? 0);
    $notes      = trim(sanitize($_POST['admin_notes'] ?? ''));

    $stmt = $pdo->prepare("
        SELECT d.*, t.id AS tx_id, t.ref_code, t.amount, t.fee, t.net_amount, t.buyer_id, t.seller_id,
               b.name AS buyer_name, s.name AS seller_name
        FROM disputes d
        JOIN transactions t ON d.transaction_id = t.id
        JOIN users b ON t.buyer_id = b.id
        JOIN users s ON t.seller_id = s.id
        WHERE d.id = ?
    ");
    $stmt->execute([$dispute_id]);
    $disp = $stmt->fetch();

    if (!$disp) {
        $error = 'Dispute not found.';
    } elseif ($action === 'refund_buyer') {
        // 1. Refund 100% of order amount back to buyer
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$disp['amount'], $disp['buyer_id']]);
        
        // 2. Update transaction status
        $pdo->prepare("UPDATE transactions SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")->execute([$disp['tx_id']]);
        
        // 3. Update dispute record
        $pdo->prepare("
            UPDATE disputes 
            SET status = 'resolved_buyer', resolution_note = ?, resolved_by = ?, resolved_at = NOW()
            WHERE id = ?
        ")->execute([$notes ?: 'SuperAdmin ruled in favor of Buyer. 100% Refund processed.', $user['id'], $dispute_id]);

        // 4. Log in Escrow Ledger
        $pdo->prepare("
            INSERT INTO escrow_ledger (transaction_id, type, amount, from_user_id, to_user_id, reference, note)
            VALUES (?, 'refund', ?, NULL, ?, ?, ?)
        ")->execute([$disp['tx_id'], $disp['amount'], $disp['buyer_id'], $disp['ref_code'], "Dispute refunded to Buyer: $notes"]);

        // 5. Wallet transaction log for buyer
        $pdo->prepare("INSERT INTO wallet_transactions 
            (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
            SELECT ?, 'escrow_credit', ?, balance - ?, balance, ?, ?, 'escrow_refund', 'completed'
            FROM users WHERE id = ?")
            ->execute([$disp['buyer_id'], $disp['amount'], $disp['amount'], $disp['ref_code'], "Escrow Refund for {$disp['ref_code']}", $disp['buyer_id']]);

        // 6. Notifications
        addNotification($disp['buyer_id'], 'Dispute Resolved — Refund Approved', "SuperAdmin resolved dispute on {$disp['ref_code']} in your favor. " . formatCurrency($disp['amount']) . " refunded to your wallet.", 'success', APP_URL . '/buyer/wallet.php');
        addNotification($disp['seller_id'], 'Dispute Closed — Refunded to Buyer', "SuperAdmin resolved dispute on {$disp['ref_code']} with a refund to the buyer. Note: $notes", 'warning', APP_URL . '/seller/orders.php');

        logAudit('DISPUTE_REFUND_BUYER', "SuperAdmin refunded dispute #$dispute_id ({$disp['ref_code']}) to buyer ($" . $disp['amount'] . ")", $user['id']);
        $success = "Dispute #$dispute_id resolved! 100% refund of " . formatCurrency($disp['amount']) . " credited to Buyer {$disp['buyer_name']}.";
    } elseif ($action === 'release_seller') {
        // 1. Release net_amount to seller
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$disp['net_amount'], $disp['seller_id']]);
        
        // 2. Update transaction status
        $pdo->prepare("UPDATE transactions SET status = 'released', released_at = NOW() WHERE id = ?")->execute([$disp['tx_id']]);

        // 3. Update dispute record
        $pdo->prepare("
            UPDATE disputes 
            SET status = 'resolved_seller', resolution_note = ?, resolved_by = ?, resolved_at = NOW()
            WHERE id = ?
        ")->execute([$notes ?: 'SuperAdmin ruled in favor of Seller. Funds released.', $user['id'], $dispute_id]);

        // 4. Log in Escrow Ledger
        $pdo->prepare("
            INSERT INTO escrow_ledger (transaction_id, type, amount, from_user_id, to_user_id, reference, note)
            VALUES (?, 'release', ?, ?, ?, ?, ?)
        ")->execute([$disp['tx_id'], $disp['net_amount'], $disp['buyer_id'], $disp['seller_id'], $disp['ref_code'], "Dispute released to Seller: $notes"]);

        if ((float)$disp['fee'] > 0) {
            $pdo->prepare("
                INSERT INTO escrow_ledger (transaction_id, type, amount, from_user_id, to_user_id, reference, note)
                VALUES (?, 'fee', ?, ?, NULL, ?, ?)
            ")->execute([$disp['tx_id'], $disp['fee'], $disp['buyer_id'], $disp['ref_code'], "Platform fee collected on dispute release"]);
        }

        // 5. Wallet transaction log for seller
        $pdo->prepare("INSERT INTO wallet_transactions 
            (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
            SELECT ?, 'escrow_credit', ?, balance - ?, balance, ?, ?, 'escrow', 'completed'
            FROM users WHERE id = ?")
            ->execute([$disp['seller_id'], $disp['net_amount'], $disp['net_amount'], $disp['ref_code'], "Dispute Release for {$disp['ref_code']}", $disp['seller_id']]);

        // 6. Notifications
        addNotification($disp['seller_id'], 'Dispute Resolved — Payment Released', "SuperAdmin resolved dispute on {$disp['ref_code']} in your favor. " . formatCurrency($disp['net_amount']) . " released to your wallet.", 'success', APP_URL . '/seller/wallet.php');
        addNotification($disp['buyer_id'], 'Dispute Closed — Released to Seller', "SuperAdmin resolved dispute on {$disp['ref_code']} and released funds to the seller. Note: $notes", 'info', APP_URL . '/buyer/my_orders.php');

        logAudit('DISPUTE_RELEASE_SELLER', "SuperAdmin released dispute #$dispute_id ({$disp['ref_code']}) to seller ($" . $disp['net_amount'] . ")", $user['id']);
        $success = "Dispute #$dispute_id resolved! Payout of " . formatCurrency($disp['net_amount']) . " released to Seller {$disp['seller_name']}.";
    }
}

// Fetch all disputes
$filter = sanitize($_GET['status'] ?? '');
$where = '1=1';
$params = [];

if ($filter === 'open') {
    $where = "d.status IN ('open', 'under_review')";
} elseif ($filter === 'resolved') {
    $where = "d.status IN ('resolved_buyer', 'resolved_seller')";
}

$disputes = $pdo->query("
    SELECT d.*, t.id AS tx_id, t.buyer_id, t.seller_id, t.delivery_id, t.ref_code, t.title AS order_title, t.description AS order_desc, t.amount, t.fee, t.net_amount, t.status AS tx_status,
           t.seller_dispatch_proof, t.buyer_received_proof,
           b.name AS buyer_name, b.email AS buyer_email, b.phone AS buyer_phone,
           s.name AS seller_name, s.email AS seller_email, s.store_name,
           del.dispatch_proof, del.pickup_proof, del.delivery_proof,
           drv.name AS driver_name, drv.phone AS driver_phone, drv.email AS driver_email,
           drv.address AS driver_address, drv.vehicle_type, drv.vehicle_plate, drv.id_number AS driver_license,
           (SELECT COUNT(*) FROM deliveries dh WHERE dh.delivery_id = drv.id) AS driver_total_jobs,
           (SELECT COUNT(*) FROM deliveries dh2 WHERE dh2.delivery_id = drv.id AND dh2.status = 'delivered') AS driver_completed_jobs
    FROM disputes d
    JOIN transactions t ON d.transaction_id = t.id
    JOIN users b ON t.buyer_id = b.id
    JOIN users s ON t.seller_id = s.id
    LEFT JOIN deliveries del ON del.transaction_id = t.id
    LEFT JOIN users drv ON COALESCE(del.delivery_id, t.delivery_id) = drv.id
    WHERE $where
    ORDER BY d.created_at DESC
")->fetchAll();

$open_count = $pdo->query("SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review')")->fetchColumn();
$resolved_count = $pdo->query("SELECT COUNT(*) FROM disputes WHERE status IN ('resolved_buyer','resolved_seller')")->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-scales-3-line" style="color:var(--danger)"></i> Dispute Resolution Center</h1>
        <p class="page-subtitle">Mediate and resolve buyer-seller conflicts with authoritative escrow fund distribution</p>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid stagger fade-in" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Active / Open Disputes</div>
            <div class="stat-value" style="color:var(--danger);"><?= $open_count ?></div>
            <div class="stat-change down"><i class="ri-alert-line"></i> Needs Review</div>
        </div>
        <div class="stat-icon-wrap stat-icon-danger"><i class="ri-scales-3-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Resolved Disputes</div>
            <div class="stat-value"><?= $resolved_count ?></div>
            <div class="stat-change up"><i class="ri-check-line"></i> Mediated by Admin</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-checkbox-circle-line"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Escrow Rule Policy</div>
            <div class="stat-value" style="font-size:18px;">100% Hold Guarantee</div>
            <div class="stat-change">Buyer vs Seller Protection</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-shield-check-line"></i></div>
    </div>
</div>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap" class="fade-in">
    <a href="disputes.php" class="btn <?= empty($filter) ? 'btn-primary' : 'btn-ghost' ?> btn-sm">All Disputes</a>
    <a href="?status=open" class="btn <?= $filter === 'open' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Open & Under Review (<?= $open_count ?>)</a>
    <a href="?status=resolved" class="btn <?= $filter === 'resolved' ? 'btn-primary' : 'btn-ghost' ?> btn-sm">Resolved (<?= $resolved_count ?>)</a>
</div>

<!-- Disputes List -->
<?php if (empty($disputes)): ?>
<div class="card fade-in">
    <div class="card-body">
        <div class="empty-state">
            <i class="ri-scales-3-line"></i>
            <h3>No disputes found</h3>
            <p>All escrow transactions are progressing smoothly!</p>
        </div>
    </div>
</div>
<?php else: ?>
<?php foreach ($disputes as $d): 
    $isOpen = in_array($d['status'], ['open', 'under_review']);
    $aiVerdict = !empty($d['ai_verdict']) ? json_decode($d['ai_verdict'], true) : null;
    $sellerProof = $d['seller_dispatch_proof'] ?: $d['dispatch_proof'];
    $pickupProof = $d['pickup_proof'];
    $buyerProof = $d['buyer_received_proof'] ?: $d['evidence_file'];
    $driverContext = sprintf(
        'Darawal: %s | Phone: %s | Email: %s | Moto/Gaari: %s | Plate: %s | License/ID: %s | Cinwaan: %s | Shaqooyin: %d la dhammaystiray / %d guud.',
        $d['driver_name'] ?: 'Lama aqoon', $d['driver_phone'] ?: 'Lama gelin', $d['driver_email'] ?: 'Lama gelin',
        $d['vehicle_type'] ?: 'Lama gelin', $d['vehicle_plate'] ?: 'Lama gelin', $d['driver_license'] ?: 'Lama gelin',
        $d['driver_address'] ?: 'Lama gelin', (int)($d['driver_completed_jobs'] ?? 0), (int)($d['driver_total_jobs'] ?? 0)
    );
?>
<div class="card fade-in" style="margin-bottom:24px;border-radius:16px;overflow:hidden;border:1px solid <?= $isOpen ? '#fecaca' : '#e8edf5' ?>;box-shadow:0 4px 20px rgba(0,0,0,0.04);" id="dispute-card-<?= $d['id'] ?>">
    <!-- Card Header -->
    <div style="background:<?= $isOpen ? '#fef2f2' : '#f8fafd' ?>;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;border-bottom:1px solid <?= $isOpen ? '#fee2e2' : '#e8edf5' ?>;">
        <div style="display:flex;align-items:center;gap:12px;">
            <span class="badge <?= $isOpen ? 'badge-danger' : 'badge-success' ?>" style="font-size:11px;">
                <i class="<?= $isOpen ? 'ri-alert-line' : 'ri-check-line' ?>"></i> <?= strtoupper(str_replace('_', ' ', $d['status'])) ?>
            </span>
            <strong style="color:var(--primary);font-size:15px;">Order #<?= sanitize($d['ref_code']) ?></strong>
            <span style="font-size:12px;color:var(--neutral-light);"><i class="ri-time-line"></i> <?= date('M j, Y H:i', strtotime($d['created_at'])) ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:16px;">
            <div>
                <span style="font-size:12px;color:var(--neutral-light);">Escrow Held: </span>
                <strong style="font-size:17px;color:var(--neutral-dark);"><?= formatCurrency($d['amount']) ?></strong>
            </div>
            <a href="messaging.php?with=<?= $d['buyer_id'] ?>" class="btn btn-ghost btn-sm" title="Audit Live Chat"><i class="ri-chat-voice-line"></i> Audit Chat</a>
        </div>
    </div>

    <!-- Card Body -->
    <div class="card-body" style="padding:20px;">
        
        <!-- 📸 SECTION 1: VISUAL CHAIN-OF-CUSTODY (Chain of Proofs) -->
        <?php 
            $hasDeliv = !empty($d['delivery_id']) || !empty($d['driver_name']) || !empty($d['pickup_proof']) || !empty($d['delivery_proof']);
            $dropoffProof = $d['delivery_proof'];
        ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <strong style="font-size:13px;color:var(--neutral-dark);display:flex;align-items:center;gap:6px;">
                        <i class="ri-camera-lens-fill" style="color:var(--primary);"></i> Chain-of-Custody Visual Timeline (Xaqiijinta 4-ta Heer ee Sawirrada)
                    </strong>
                    <?php if (!$hasDeliv): ?>
                    <span class="badge badge-info" style="font-size:10px;"><i class="ri-code-s-slash-line"></i> Digital Service / Direct — No Moto Driver</span>
                    <?php else: ?>
                    <span class="badge badge-primary" style="font-size:10px;"><i class="ri-e-bike-2-line"></i> Moto Delivery Chain</span>
                    <?php endif; ?>
                </div>
                <span style="font-size:11px;color:var(--neutral-light);">Real-time photos captured across fulfillment milestones</span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:12px;">
                
                <!-- 1. Seller Dispatch Proof -->
                <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:10px;text-align:center;">
                    <div style="font-size:11px;font-weight:700;color:var(--primary);margin-bottom:6px;display:flex;align-items:center;justify-content:center;gap:4px;">
                        <i class="ri-store-2-line"></i> 1. Seller Packaging Proof
                    </div>
                    <?php if ($sellerProof && file_exists(__DIR__ . '/../' . $sellerProof)): ?>
                    <a href="<?= APP_URL ?>/<?= sanitize($sellerProof) ?>" target="_blank" style="display:block;height:110px;border-radius:8px;overflow:hidden;background:#000;">
                        <img src="<?= APP_URL ?>/<?= sanitize($sellerProof) ?>" alt="Seller Dispatch Proof" style="width:100%;height:100%;object-fit:cover;transition:transform .2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </a>
                    <div style="font-size:10px;color:var(--neutral-light);margin-top:6px;">Seller: <?= sanitize($d['seller_name']) ?></div>
                    <?php else: ?>
                    <div style="height:110px;border-radius:8px;background:#f1f5f9;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--neutral-light);font-size:11px;">
                        <i class="ri-image-line" style="font-size:24px;opacity:.5;"></i>
                        <span>No packaging photo</span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($hasDeliv): ?>
                <!-- 2. Moto Driver Pickup Proof -->
                <div style="background:#fff;border:1px solid #fed7aa;border-radius:10px;padding:10px;text-align:center;">
                    <div style="font-size:11px;font-weight:700;color:var(--warning);margin-bottom:6px;display:flex;align-items:center;justify-content:center;gap:4px;">
                        <i class="ri-e-bike-2-line"></i> 2. Driver Pickup ("Waad Qaaday")
                    </div>
                    <?php if ($pickupProof && file_exists(__DIR__ . '/../' . $pickupProof)): ?>
                    <a href="<?= APP_URL ?>/<?= sanitize($pickupProof) ?>" target="_blank" style="display:block;height:110px;border-radius:8px;overflow:hidden;background:#000;">
                        <img src="<?= APP_URL ?>/<?= sanitize($pickupProof) ?>" alt="Driver Pickup Proof" style="width:100%;height:100%;object-fit:cover;transition:transform .2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </a>
                    <div style="font-size:10px;color:var(--neutral-light);margin-top:6px;">Driver: <?= sanitize($d['driver_name'] ?: 'Delivery Agent') ?></div>
                    <?php else: ?>
                    <div style="height:110px;border-radius:8px;background:#fffbeb;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#b45309;font-size:11px;">
                        <i class="ri-image-line" style="font-size:24px;opacity:.5;"></i>
                        <span>No pickup photo</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 3. Moto Driver Dropoff Proof -->
                <div style="background:#fff;border:1px solid #86efac;border-radius:10px;padding:10px;text-align:center;">
                    <div style="font-size:11px;font-weight:700;color:var(--secondary);margin-bottom:6px;display:flex;align-items:center;justify-content:center;gap:4px;">
                        <i class="ri-checkbox-circle-fill"></i> 3. Driver Dropoff ("Waad Geysay")
                    </div>
                    <?php if ($dropoffProof && file_exists(__DIR__ . '/../' . $dropoffProof)): ?>
                    <a href="<?= APP_URL ?>/<?= sanitize($dropoffProof) ?>" target="_blank" style="display:block;height:110px;border-radius:8px;overflow:hidden;background:#000;">
                        <img src="<?= APP_URL ?>/<?= sanitize($dropoffProof) ?>" alt="Driver Dropoff Proof" style="width:100%;height:100%;object-fit:cover;transition:transform .2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </a>
                    <div style="font-size:10px;color:var(--secondary);margin-top:6px;font-weight:600;">Dropoff Verified</div>
                    <?php else: ?>
                    <div style="height:110px;border-radius:8px;background:#f0fdf4;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--neutral-light);font-size:11px;">
                        <i class="ri-image-line" style="font-size:24px;opacity:.5;"></i>
                        <span>No dropoff photo</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- 4. Buyer Received / Dispute Evidence -->
                <div style="background:#fff;border:1px solid #fca5a5;border-radius:10px;padding:10px;text-align:center;">
                    <div style="font-size:11px;font-weight:700;color:var(--danger);margin-bottom:6px;display:flex;align-items:center;justify-content:center;gap:4px;">
                        <i class="ri-user-voice-line"></i> <?= $hasDeliv ? '4' : '2' ?>. Buyer Defect Proof
                    </div>
                    <?php if ($buyerProof && file_exists(__DIR__ . '/../' . $buyerProof)): ?>
                    <a href="<?= APP_URL ?>/<?= sanitize($buyerProof) ?>" target="_blank" style="display:block;height:110px;border-radius:8px;overflow:hidden;background:#000;">
                        <img src="<?= APP_URL ?>/<?= sanitize($buyerProof) ?>" alt="Buyer Received Evidence" style="width:100%;height:100%;object-fit:cover;transition:transform .2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </a>
                    <div style="font-size:10px;color:var(--danger);margin-top:6px;font-weight:600;">Dispute Evidence Attached</div>
                    <?php else: ?>
                    <div style="height:110px;border-radius:8px;background:#fef2f2;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--danger);font-size:11px;">
                        <i class="ri-image-line" style="font-size:24px;opacity:.5;"></i>
                        <span>No buyer photo attached</span>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- 🤖 SECTION 2: GEMINI AI ARBITER & FORENSIC INVESTIGATOR -->
        <div id="ai-verdict-container-<?= $d['id'] ?>" style="background:linear-gradient(135deg, #0f172a, #1e293b);color:#fff;border-radius:14px;padding:18px 20px;margin-bottom:20px;box-shadow:0 6px 25px rgba(15,23,42,.15);">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#38bdf8,#818cf8);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;">
                        <i class="ri-sparkling-fill"></i>
                    </div>
                    <div>
                        <strong style="font-size:14px;letter-spacing:.3px;"> AI Forensic Arbiter (Garsooraha AI)</strong>
                        <div style="font-size:11px;color:#94a3b8;">Multimodal Vision &amp; Cross-Proof Verification Engine</div>
                    </div>
                </div>

                <div>
                    <button class="btn btn-sm" id="btn-run-ai-<?= $d['id'] ?>" onclick="runAiInvestigation(<?= $d['id'] ?>)" style="background:linear-gradient(135deg,#38bdf8,#6366f1);color:#fff;border:none;font-weight:700;padding:7px 14px;border-radius:8px;display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                        <i class="ri-magic-line"></i> <?= $aiVerdict ? 'Re-run AI Analysis' : 'Run Gemini AI Investigation' ?>
                    </button>
                </div>
            </div>

            <!-- AI Verdict Display Area -->
            <div id="ai-content-<?= $d['id'] ?>">
                <?php if ($aiVerdict): ?>
                <div style="display:grid;grid-template-columns:auto 1fr;gap:20px;margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.1);align-items:center;">
                    <!-- Risk & Recommendation Badge -->
                    <div style="background:rgba(255,255,255,.07);border-radius:10px;padding:12px 16px;text-align:center;min-width:180px;">
                        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;">AI Recommendation</div>
                        <div style="font-size:15px;font-weight:800;margin:6px 0;color:<?= $aiVerdict['recommendation']==='refund_buyer'?'#f87171':'#4ade80' ?>;">
                            <i class="<?= $aiVerdict['recommendation']==='refund_buyer'?'ri-arrow-go-back-line':'ri-checkbox-circle-line' ?>"></i>
                            <?= $aiVerdict['recommendation']==='refund_buyer'?'REFUND BUYER':'RELEASE SELLER' ?>
                        </div>
                        <div style="font-size:11px;color:#cbd5e1;">Fraud Risk: <strong><?= $aiVerdict['risk_score'] ?? 0 ?>%</strong> | Conf: <strong><?= $aiVerdict['confidence'] ?? 90 ?>%</strong></div>
                    </div>

                    <!-- Somali Summary & Detailed Findings -->
                    <div>
                        <div style="font-weight:700;font-size:13px;color:#38bdf8;margin-bottom:4px;">
                            <?= sanitize($aiVerdict['verdict_title_somali'] ?? "Go'aanka Garsoorka AI") ?>
                        </div>
                        <p style="font-size:12px;color:#e2e8f0;line-height:1.4;margin:0 0 6px 0;">
                            <?= sanitize($aiVerdict['summary_somali'] ?? '') ?>
                        </p>
                        <div style="font-size:11px;color:#94a3b8;line-height:1.4;white-space:pre-line;background:rgba(0,0,0,.2);padding:8px 12px;border-radius:8px;">
                            <?= sanitize($aiVerdict['detailed_analysis_somali'] ?? '') ?>
                        </div>
                        <?php if (($aiVerdict['culprit'] ?? '') === 'delivery'): ?><div style="margin-top:8px;padding:9px 12px;border-radius:8px;background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);font-size:11px;color:#fecaca;line-height:1.55"><i class="ri-motorbike-line"></i> <strong>Driver Accountability — Full Context:</strong><br><?= sanitize($driverContext) ?></div><?php endif; ?>

                        <?php if ($isOpen): ?>
                        <div style="margin-top:10px;">
                            <button class="btn btn-sm" onclick="applyAiRecommendation(<?= $d['id'] ?>, '<?= $aiVerdict['recommendation'] ?>', '<?= sanitize($d['ref_code']) ?>', '<?= $aiVerdict['recommendation']==='refund_buyer'?sanitize($d['buyer_name']):sanitize($d['seller_name']) ?>', '<?= $aiVerdict['recommendation']==='refund_buyer'?formatCurrency($d['amount']):formatCurrency($d['net_amount']) ?>', <?= htmlspecialchars(json_encode($aiVerdict['admin_ruling_note'] ?? '')) ?>)" style="background:#10b981;color:#fff;border:none;font-weight:700;font-size:11px;padding:6px 14px;">
                                <i class="ri-flashlight-line"></i> ⚡ 1-Click Apply AI Verdict (<?= $aiVerdict['recommendation']==='refund_buyer'?'Refund Buyer':'Release Seller' ?>)
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div style="color:#94a3b8;font-size:12px;padding:8px 0;">
                    <i class="ri-information-line"></i> Click <strong>"Run AI Investigation"</strong> to trigger multimodal vision analysis comparing seller, driver, and buyer visual proofs.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SECTION 3: CLAIM & ESCROW DETAILS GRID -->
        <div style="display:grid;grid-template-columns:1fr 1fr 280px;gap:20px;">
            <!-- Buyer Complaint Box -->
            <div style="background:#fef6f6;border-radius:12px;padding:16px;border:1px solid #fed7d7;">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                    <i class="ri-user-voice-line" style="color:var(--danger);font-size:18px;"></i>
                    <strong style="color:#9b2c2c;font-size:13px;">Buyer's Claim</strong>
                </div>
                <div style="font-size:12px;margin-bottom:6px;"><strong>Buyer:</strong> <?= sanitize($d['buyer_name']) ?> (<?= sanitize($d['buyer_email']) ?>)</div>
                <div style="font-size:12px;margin-bottom:6px;"><strong>Reason:</strong> <span style="color:#c53030;font-weight:700;"><?= sanitize($d['reason']) ?></span></div>
                <div style="font-size:12px;color:var(--neutral-dark);line-height:1.4;margin-bottom:8px;">
                    <strong>Details:</strong> <?= nl2br(sanitize($d['description'] ?: 'No additional description.')) ?>
                </div>
                <?php if (!empty($d['evidence'])): ?>
                <div style="font-size:11px;background:#fff;padding:8px;border-radius:8px;color:var(--neutral);border:1px dashed #fed7d7;">
                    <strong>Evidence Notes:</strong> <?= nl2br(sanitize($d['evidence'])) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Order & Seller Details Box -->
            <div style="background:var(--tertiary);border-radius:12px;padding:16px;border:1px solid #e2eaf8;">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                    <i class="ri-store-2-line" style="color:var(--primary);font-size:18px;"></i>
                    <strong style="color:var(--primary);font-size:13px;">Order & Seller Details</strong>
                </div>
                <div style="font-size:12px;margin-bottom:6px;"><strong>Seller:</strong> <?= sanitize($d['seller_name']) ?> (<?= sanitize($d['seller_email']) ?>)</div>
                <div style="font-size:12px;margin-bottom:6px;"><strong>Item:</strong> <?= sanitize($d['order_title']) ?></div>
                <div style="font-size:12px;margin-bottom:6px;"><strong>Escrow Fee (10%):</strong> <?= formatCurrency($d['fee']) ?></div>
                <div style="font-size:12px;margin-bottom:6px;"><strong>Net Seller Payout:</strong> <strong style="color:var(--secondary);"><?= formatCurrency($d['net_amount']) ?></strong></div>
                <div style="font-size:12px;"><strong>Order Status:</strong> <?= statusBadge($d['tx_status']) ?></div>
            </div>

            <!-- SuperAdmin Manual Decision Panel -->
            <div style="display:flex;flex-direction:column;justify-content:space-between;background:#fff;padding:16px;border-radius:12px;border:1px solid #e8edf5;">
                <?php if ($isOpen): ?>
                <div>
                    <div style="font-size:12px;font-weight:700;color:var(--neutral-dark);margin-bottom:8px;">
                        <i class="ri-gavel-line" style="color:var(--primary);"></i> SuperAdmin Ruling
                    </div>
                    <p style="font-size:11px;color:var(--neutral);line-height:1.4;margin-bottom:12px;">
                        Choose to either refund 100% to the Buyer or release the net funds to the Seller.
                    </p>
                </div>

                <div style="display:flex;flex-direction:column;gap:8px;">
                    <!-- Refund Buyer Button -->
                    <button class="btn btn-danger btn-sm" onclick="openRulingModal(<?= $d['id'] ?>, 'refund_buyer', '<?= sanitize($d['ref_code']) ?>', '<?= sanitize($d['buyer_name']) ?>', '<?= formatCurrency($d['amount']) ?>')" style="padding:10px;font-size:12px;">
                        <i class="ri-arrow-go-back-line"></i> 1. Refund Buyer (<?= formatCurrency($d['amount']) ?>)
                    </button>

                    <!-- Release Seller Button -->
                    <button class="btn btn-primary btn-sm" onclick="openRulingModal(<?= $d['id'] ?>, 'release_seller', '<?= sanitize($d['ref_code']) ?>', '<?= sanitize($d['seller_name']) ?>', '<?= formatCurrency($d['net_amount']) ?>')" style="background:var(--secondary);border-color:var(--secondary);padding:10px;font-size:12px;">
                        <i class="ri-checkbox-circle-line"></i> 2. Release to Seller (<?= formatCurrency($d['net_amount']) ?>)
                    </button>
                </div>
                <?php else: ?>
                <div>
                    <div style="font-size:12px;font-weight:700;color:var(--secondary);margin-bottom:8px;">
                        <i class="ri-check-double-line"></i> Dispute Resolved
                    </div>
                    <div style="font-size:11px;color:var(--neutral-dark);line-height:1.4;margin-bottom:6px;">
                        <strong>Resolution Note:</strong> <?= sanitize($d['resolution_note'] ?? 'Resolved by admin') ?>
                    </div>
                    <div style="font-size:10px;color:var(--neutral-light);">Resolved on <?= date('M j, Y H:i', strtotime($d['resolved_at'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Ruling Modal -->
<div class="modal-overlay" id="rulingModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <span class="modal-title" id="rulingModalTitle"><i class="ri-gavel-line"></i> Confirm Ruling</span>
            <button class="modal-close" onclick="closeModal('rulingModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" id="rulingAction">
                <input type="hidden" name="dispute_id" id="rulingDisputeId">

                <div id="rulingAlertBox" style="padding:14px;border-radius:12px;font-size:13px;line-height:1.4;margin-bottom:16px;"></div>

                <div class="form-group">
                    <label class="form-label">Ruling / Explanation Note <span class="required">*</span></label>
                    <textarea name="admin_notes" id="rulingAdminNotes" class="form-control" rows="4" placeholder="State reason for your ruling to be sent to both parties..." required></textarea>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('rulingModal')">Cancel</button>
                    <button type="submit" class="btn" id="rulingSubmitBtn">Confirm & Execute</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRulingModal(disputeId, action, refCode, targetName, amountStr, prefillNote = '') {
    document.getElementById('rulingDisputeId').value = disputeId;
    document.getElementById('rulingAction').value = action;
    if (prefillNote) {
        document.getElementById('rulingAdminNotes').value = prefillNote;
    }
    
    const alertBox = document.getElementById('rulingAlertBox');
    const submitBtn = document.getElementById('rulingSubmitBtn');
    
    if (action === 'refund_buyer') {
        document.getElementById('rulingModalTitle').innerHTML = '<i class="ri-arrow-go-back-line" style="color:var(--danger)"></i> Refund Buyer — ' + refCode;
        alertBox.style.background = '#fef2f2';
        alertBox.style.border = '1px solid #fee2e2';
        alertBox.style.color = '#991b1b';
        alertBox.innerHTML = 'You are about to refund <strong>' + amountStr + '</strong> to Buyer <strong>' + targetName + '</strong>. Funds will be returned immediately to their wallet.';
        submitBtn.className = 'btn btn-danger';
        submitBtn.textContent = 'Execute Refund to Buyer';
    } else {
        document.getElementById('rulingModalTitle').innerHTML = '<i class="ri-checkbox-circle-line" style="color:var(--secondary)"></i> Release to Seller — ' + refCode;
        alertBox.style.background = '#eefcf6';
        alertBox.style.border = '1px solid #c1f3de';
        alertBox.style.color = '#065f46';
        alertBox.innerHTML = 'You are about to release <strong>' + amountStr + '</strong> to Seller <strong>' + targetName + '</strong>. Funds will be credited to their available wallet balance.';
        submitBtn.className = 'btn btn-primary';
        submitBtn.style.background = 'var(--secondary)';
        submitBtn.style.borderColor = 'var(--secondary)';
        submitBtn.textContent = 'Execute Release to Seller';
    }
    
    openModal('rulingModal');
}

function applyAiRecommendation(disputeId, recommendation, refCode, targetName, amountStr, adminNote) {
    openRulingModal(disputeId, recommendation, refCode, targetName, amountStr, adminNote);
}

async function runAiInvestigation(disputeId) {
    const btn = document.getElementById('btn-run-ai-' + disputeId);
    const content = document.getElementById('ai-content-' + disputeId);
    
    if (!btn || !content) return;
    
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="ri-loader-4-line spin"></i> Analyzing Visual Proofs…';
    content.innerHTML = '<div style="padding:15px;color:#38bdf8;font-size:13px;"><i class="ri-loader-4-line spin"></i> Google Gemini Vision is examining Chain-of-Custody photos & chat history…</div>';
    
    try {
        const response = await fetch(APP_URL + '/api/ai_dispute_analyze.php?dispute_id=' + disputeId);
        const data = await response.json();
        
        if (data.success && data.verdict) {
            const v = data.verdict;
            const isRefund = v.recommendation === 'refund_buyer';
            const escapeHtml = (value) => String(value || '').replace(/[&<>'\"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','\"':'&quot;'}[char]));
            const driverContext = v.culprit === 'delivery' && v.driver_accountability
                ? `<div style="margin-top:8px;padding:9px 12px;border-radius:8px;background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);font-size:11px;color:#fecaca;line-height:1.55"><i class="ri-motorbike-line"></i> <strong>Driver Accountability — Full Context:</strong><br>${escapeHtml(v.driver_accountability)}</div>`
                : '';
            
            content.innerHTML = `
                <div style="display:grid;grid-template-columns:auto 1fr;gap:20px;margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.1);align-items:center;">
                    <div style="background:rgba(255,255,255,.07);border-radius:10px;padding:12px 16px;text-align:center;min-width:180px;">
                        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;">AI Recommendation</div>
                        <div style="font-size:15px;font-weight:800;margin:6px 0;color:${isRefund ? '#f87171' : '#4ade80'};">
                            <i class="${isRefund ? 'ri-arrow-go-back-line' : 'ri-checkbox-circle-line'}"></i>
                            ${isRefund ? 'REFUND BUYER' : 'RELEASE SELLER'}
                        </div>
                        <div style="font-size:11px;color:#cbd5e1;">Fraud Risk: <strong>${v.risk_score || 0}%</strong> | Conf: <strong>${v.confidence || 90}%</strong></div>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:13px;color:#38bdf8;margin-bottom:4px;">
                            ${v.verdict_title_somali || "Go'aanka Garsoorka AI"}
                        </div>
                        <p style="font-size:12px;color:#e2e8f0;line-height:1.4;margin:0 0 6px 0;">
                            ${v.summary_somali || ''}
                        </p>
                        <div style="font-size:11px;color:#94a3b8;line-height:1.4;white-space:pre-line;background:rgba(0,0,0,.2);padding:8px 12px;border-radius:8px;">
                            ${escapeHtml(v.detailed_analysis_somali)}
                        </div>
                        ${driverContext}
                        <div style="margin-top:10px;">
                            <button class="btn btn-sm" onclick="location.reload()" style="background:#10b981;color:#fff;border:none;font-weight:700;font-size:11px;padding:6px 14px;">
                                <i class="ri-refresh-line"></i> Refresh to Apply Ruling
                            </button>
                        </div>
                    </div>
                </div>
            `;
            btn.innerHTML = '<i class="ri-check-line"></i> Analysis Complete';
        } else {
            content.innerHTML = '<div style="color:#f87171;font-size:12px;padding:8px 0;"><i class="ri-error-warning-line"></i> Error: ' + (data.error || 'Unable to complete AI analysis') + '</div>';
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    } catch (err) {
        content.innerHTML = '<div style="color:#f87171;font-size:12px;padding:8px 0;"><i class="ri-error-warning-line"></i> Network error connecting to AI endpoint.</div>';
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
