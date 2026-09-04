<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['buyer']);
$page_title  = 'My Orders';
$active_page = 'my_orders.php';
$pdo         = getDB();
$uid         = $user['id'];

$success = sanitize($_GET['success'] ?? '');
$error   = '';

// Safety auto-release: a delivered order left untouched for 3 days is
// considered accepted, then seller is paid once and buyer is notified.
$stale = $pdo->query("SELECT * FROM transactions WHERE buyer_id=" . (int)$uid . " AND status='delivered' AND delivered_at IS NOT NULL AND delivered_at <= DATE_SUB(NOW(), INTERVAL 3 DAY)")->fetchAll();
foreach ($stale as $autoTx) {
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT * FROM transactions WHERE id=? AND status='delivered' FOR UPDATE"); $lock->execute([$autoTx['id']]); $row = $lock->fetch();
        if ($row) {
            $pdo->prepare("UPDATE transactions SET status='released', released_at=NOW() WHERE id=?")->execute([$row['id']]);
            $pdo->prepare("UPDATE users SET balance=balance+? WHERE id=?")->execute([$row['net_amount'], $row['seller_id']]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id,type,amount,balance_before,balance_after,reference,description,payment_method,status) SELECT ?, 'escrow_credit', ?, balance-?, balance, ?, ?, 'escrow', 'completed' FROM users WHERE id=?")->execute([$row['seller_id'],$row['net_amount'],$row['net_amount'],$row['ref_code'],'Auto-released after 3 days without buyer response',$row['seller_id']]);
            addNotification((int)$row['seller_id'], 'Escrow Auto-Released', "Funds for {$row['ref_code']} were released automatically after 3 days without a buyer response.", 'success', APP_URL.'/seller/orders.php');
            logAudit('AUTO_RELEASE_3_DAYS', "Auto-released escrow {$row['ref_code']} after 3 days", $uid);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
}

// ── Actions ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['tx_id'] ?? 0);

    // Verify we own this transaction
    $chk = $pdo->prepare("SELECT * FROM transactions WHERE id=? AND buyer_id=?");
    $chk->execute([$tid, $uid]);
    $tx = $chk->fetch();

    if (!$tx) {
        $error = 'Transaction not found.';
    } elseif ($action === 'release' && in_array($tx['status'], ['funded', 'accepted', 'shipped', 'delivered'])) {
        // 1. Release funds to seller
        $pdo->prepare("UPDATE transactions SET status='released', released_at=NOW() WHERE id=?")->execute([$tid]);
        
        // 2. Credit seller available balance (net_amount)
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$tx['net_amount'], $tx['seller_id']]);
        
        // 3. Record in seller wallet transactions
        $pdo->prepare("INSERT INTO wallet_transactions 
            (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
            SELECT ?, 'escrow_credit', ?, balance - ?, balance, ?, ?, 'escrow', 'completed'
            FROM users WHERE id = ?")
            ->execute([
                $tx['seller_id'],
                $tx['net_amount'],
                $tx['net_amount'],
                $tx['ref_code'],
                "Funds released from escrow for {$tx['ref_code']}",
                $tx['seller_id']
            ]);

        // 4. If delivery agent assigned, credit delivery agent payout ($1.50)
        if (!empty($tx['delivery_id'])) {
            $deliv_fee = (float)($tx['delivery_fee'] ?? 1.50);
            if ($deliv_fee <= 0 || $deliv_fee > 5.00) { $deliv_fee = 1.50; }
            $deliv_net = $deliv_fee; // $1.50 paid in full (0.2% commission applies on withdrawal)

            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$deliv_net, $tx['delivery_id']]);
            $pdo->prepare("INSERT INTO wallet_transactions (user_id, type, amount, balance_before, balance_after, reference, description, payment_method, status)
                SELECT ?, 'escrow_credit', ?, balance - ?, balance, ?, ?, 'delivery_payout', 'completed' FROM users WHERE id=?")
                ->execute([$tx['delivery_id'], $deliv_net, $deliv_net, $tx['ref_code'], "Delivery Payout for {$tx['ref_code']}", $tx['delivery_id']]);
            
            addNotification($tx['delivery_id'], 'Delivery Completed & Paid!', "Delivery of {$tx['ref_code']} confirmed by buyer! " . formatCurrency($deliv_net) . " added to your wallet.", 'success', APP_URL . '/delivery/dashboard.php');
        }

        // 5. Record in immutable Escrow Ledger
        $pdo->prepare("INSERT INTO escrow_ledger (transaction_id, type, amount, from_user_id, to_user_id, reference, note)
            VALUES (?, 'release', ?, ?, ?, ?, ?)")
            ->execute([$tid, $tx['net_amount'], $uid, $tx['seller_id'], $tx['ref_code'], "Buyer confirmed delivery & released payment"]);

        // 6. Fee ledger entry
        if ((float)$tx['fee'] > 0) {
            $pdo->prepare("INSERT INTO escrow_ledger (transaction_id, type, amount, from_user_id, to_user_id, reference, note)
                VALUES (?, 'fee', ?, ?, NULL, ?, ?)")
                ->execute([$tid, $tx['fee'], $uid, $tx['ref_code'], "Platform escrow fee (10%)"]);
        }

        // 7. Notify seller
        addNotification($tx['seller_id'], 'Funds Released!', "Buyer {$user['name']} has confirmed delivery for {$tx['ref_code']}. " . formatCurrency($tx['net_amount']) . " has been added to your available balance.", 'success', APP_URL . '/seller/wallet.php');
        logAudit('RELEASE_FUNDS', "Buyer released funds for escrow order {$tx['ref_code']} ($" . $tx['net_amount'] . ")", $uid);

        $success = "Funds released successfully! " . formatCurrency($tx['net_amount']) . " transferred to seller.";
    } elseif ($action === 'dispute' && in_array($tx['status'], ['funded', 'accepted', 'shipped', 'delivered'])) {
        $reason      = trim(sanitize($_POST['reason'] ?? ''));
        $description = trim(sanitize($_POST['description'] ?? ''));
        $evidence    = trim(sanitize($_POST['evidence'] ?? ''));

        if (empty($reason)) {
            $error = 'Please select a reason for the dispute.';
        } else {
            // Handle Evidence File Upload
            $proofPath = null;
            if (!empty($_FILES['evidence_file']['name']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['evidence_file']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov'])) {
                    $filename = 'proof_dispute_' . $tid . '_' . time() . '.' . $ext;
                    $target = __DIR__ . '/../uploads/proofs/' . $filename;
                    if (move_uploaded_file($_FILES['evidence_file']['tmp_name'], $target)) {
                        $proofPath = 'uploads/proofs/' . $filename;
                    }
                }
            }

            // Update transaction status & received proof
            if ($proofPath) {
                $pdo->prepare("UPDATE transactions SET status='disputed', disputed_at=NOW(), dispute_reason=?, buyer_received_proof=? WHERE id=?")
                    ->execute([$reason . ($description ? ': ' . $description : ''), $proofPath, $tid]);
            } else {
                $pdo->prepare("UPDATE transactions SET status='disputed', disputed_at=NOW(), dispute_reason=? WHERE id=?")
                    ->execute([$reason . ($description ? ': ' . $description : ''), $tid]);
            }

            // Create dispute record
            $pdo->prepare("INSERT INTO disputes (transaction_id, opened_by, reason, description, evidence, evidence_file, status)
                VALUES (?, ?, ?, ?, ?, ?, 'open')")
                ->execute([$tid, $uid, $reason, $description, $evidence, $proofPath]);

            // Notify seller & Superadmin
            addNotification($tx['seller_id'], 'Dispute Opened', "Buyer raised a dispute on order {$tx['ref_code']}. Funds are locked pending admin review.", 'danger', APP_URL . '/seller/orders.php');
            
            // Notify SuperAdmin
            $superAdmins = $pdo->query("SELECT id FROM users WHERE role='superadmin'")->fetchAll();
            foreach ($superAdmins as $sa) {
                addNotification($sa['id'], 'New Dispute Requires Review', "Dispute opened on escrow {$tx['ref_code']} by {$user['name']}.", 'danger', APP_URL . '/superadmin/disputes.php');
            }

            logAudit('RAISE_DISPUTE', "Buyer opened dispute on {$tx['ref_code']}: $reason", $uid);
            $success = 'Dispute opened with visual evidence recorded! SuperAdmin & AI Mediator will inspect your case.';
        }
    } elseif ($action === 'cancel' && $tx['status'] === 'pending') {
        $pdo->prepare("UPDATE transactions SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id=?")->execute([$tx['amount'], $uid]);
        logAudit('CANCEL_ORDER', "Buyer cancelled pending order {$tx['ref_code']}", $uid);
        $success = 'Order cancelled. Amount refunded to your wallet.';
    }
}

// ── Filters ───────────────────────────────────────────────
$status_f = $_GET['status'] ?? '';
$params   = [$uid];
$where    = "t.buyer_id=?";
if ($status_f) {
    $where .= " AND t.status=?";
    $params[] = $status_f;
}

$per_page    = 10;
$page_num    = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page_num - 1) * $per_page;
$cnt         = $pdo->prepare("SELECT COUNT(*) FROM transactions t WHERE $where");
$cnt->execute($params);
$total_count = (int)$cnt->fetchColumn();
$total_pages = max(1, ceil($total_count / $per_page));

$stmt = $pdo->prepare("
    SELECT t.*, u.name AS seller_name, u.email AS seller_email, u3.name AS delivery_name, p.type AS product_type
    FROM transactions t
    JOIN users u ON t.seller_id = u.id
    LEFT JOIN users u3 ON t.delivery_id = u3.id
    LEFT JOIN products p ON t.product_id = p.id
    WHERE $where
    ORDER BY t.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-file-list-3-line" style="color:var(--primary)"></i> My Escrow Orders</h1>
        <p class="page-subtitle"><?= $total_count ?> total escrow transactions</p>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="marketplace.php" class="btn btn-primary btn-sm"><i class="ri-shopping-cart-2-line"></i> Browse Marketplace</a>
        <a href="new_order.php" class="btn btn-ghost btn-sm"><i class="ri-add-circle-line"></i> Custom Order</a>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Status Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap" class="fade-in">
    <?php
    $tabs = [
        ''          => 'All Orders',
        'funded'    => 'In Escrow (Funded)',
        'accepted'  => 'Accepted / In Progress',
        'shipped'   => 'Shipped',
        'delivered' => 'Delivered (Awaiting Confirmation)',
        'released'  => 'Completed (Released)',
        'disputed'  => 'Disputed',
        'cancelled' => 'Cancelled'
    ];
    foreach ($tabs as $k => $l):
    ?>
    <a href="?status=<?= $k ?>" class="btn <?= $status_f === $k ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<!-- Orders List -->
<?php if (empty($orders)): ?>
<div class="card fade-in">
    <div class="card-body">
        <div class="empty-state">
            <i class="ri-shopping-bag-line"></i>
            <h3>No orders found</h3>
            <p>You haven't placed any escrow orders yet.</p>
            <div style="display:flex;gap:10px;margin-top:14px;">
                <a href="marketplace.php" class="btn btn-primary btn-sm"><i class="ri-shopping-cart-2-line"></i> Browse Marketplace</a>
                <a href="new_order.php" class="btn btn-ghost btn-sm"><i class="ri-edit-circle-line"></i> Create Custom Order</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php foreach ($orders as $tx): ?>
<div class="card fade-in" style="margin-bottom:20px;border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);">
    <!-- Card Header -->
    <div style="background:#f8fafd;padding:16px 20px;border-bottom:1px solid #eef3fb;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <strong style="color:var(--primary);font-size:15px;letter-spacing:.3px;"><?= sanitize($tx['ref_code']) ?></strong>
            <span style="font-size:12px;color:var(--neutral-light);"><?= date('M j, Y H:i', strtotime($tx['created_at'])) ?></span>
            <?php if (!empty($tx['product_type'])): ?>
            <span class="badge badge-primary" style="font-size:9px;"><?= strtoupper($tx['product_type']) ?></span>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="text-align:right;">
                <span style="font-size:11px;color:var(--neutral-light);">Escrow Hold</span>
                <div style="font-size:16px;font-weight:800;color:var(--primary);"><?= formatCurrency($tx['amount']) ?></div>
            </div>
            <?= statusBadge($tx['status']) ?>
        </div>
    </div>

    <!-- Card Body -->
    <div class="card-body" style="padding:20px;">
        <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">
            <!-- Left Info -->
            <div>
                <h3 style="font-size:16px;font-weight:700;color:var(--neutral-dark);margin-bottom:6px;"><?= sanitize($tx['title']) ?></h3>
                <p style="font-size:13px;color:var(--neutral);line-height:1.5;margin-bottom:14px;"><?= nl2br(sanitize($tx['description'] ?? 'No description provided.')) ?></p>

                <!-- Order Lifecycle Tracker -->
                <div style="background:var(--tertiary);border-radius:12px;padding:14px 18px;margin-top:14px;">
                    <div style="font-size:11px;font-weight:700;color:var(--neutral);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">
                        Escrow Progress
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;position:relative;">
                        <?php
                        $steps = [
                            ['funded', 'Payment Locked', 'ri-lock-2-line'],
                            ['accepted', 'Seller Accepted', 'ri-check-line'],
                            ['shipped', 'Shipped / Working', 'ri-truck-line'],
                            ['delivered', 'Delivered', 'ri-gift-line'],
                            ['released', 'Funds Released', 'ri-checkbox-circle-line'],
                        ];
                        $statusOrder = ['pending'=>0, 'funded'=>1, 'accepted'=>2, 'shipped'=>3, 'delivered'=>4, 'released'=>5, 'disputed'=>2];
                        $currStep = $statusOrder[$tx['status']] ?? 1;

                        foreach ($steps as $idx => [$stKey, $stLabel, $stIcon]):
                            $isPassed = ($currStep >= ($idx + 1));
                            $isCurrent = ($currStep === ($idx + 1));
                        ?>
                        <div style="display:flex;flex-direction:column;align-items:center;text-align:center;position:relative;z-index:2;flex:1;">
                            <div style="width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;
                                background:<?= $isPassed ? 'var(--secondary)' : '#e2eaf8' ?>;
                                color:<?= $isPassed ? '#fff' : 'var(--neutral-light)' ?>;
                                <?= $isCurrent ? 'box-shadow: 0 0 0 4px rgba(16,200,123,.2);' : '' ?>">
                                <i class="<?= $stIcon ?>"></i>
                            </div>
                            <span style="font-size:10px;font-weight:<?= $isPassed ? '700' : '500' ?>;color:<?= $isPassed ? 'var(--neutral-dark)' : 'var(--neutral-light)' ?>;margin-top:6px;">
                                <?= $stLabel ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right Details & Action Buttons -->
            <div style="border-left:1px solid #f0f4fa;padding-left:20px;display:flex;flex-direction:column;justify-content:space-between;">
                <div>
                    <div style="margin-bottom:12px;">
                        <span style="font-size:11px;color:var(--neutral-light);">Seller</span>
                        <div style="font-size:13px;font-weight:700;color:var(--neutral-dark);"><?= sanitize($tx['seller_name']) ?></div>
                        <div style="font-size:11px;color:var(--neutral);"><?= sanitize($tx['seller_email']) ?></div>
                        <a href="<?= APP_URL ?>/buyer/messaging.php?with=<?= $tx['seller_id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:3px 8px;margin-top:4px;color:var(--primary);background:#f0f5ff;display:inline-flex;align-items:center;gap:4px;border:1px solid #d0e0fc;">
                            <i class="ri-chat-1-line"></i> Chat with Seller
                        </a>
                    </div>

                    <?php if (!empty($tx['delivery_name'])): ?>
                    <div style="margin-bottom:12px;">
                        <span style="font-size:11px;color:var(--neutral-light);">Delivery Agent</span>
                        <div style="font-size:13px;font-weight:600;color:var(--neutral-dark);"><?= sanitize($tx['delivery_name']) ?></div>
                        <?php if (!empty($tx['delivery_id'])): ?>
                        <a href="<?= APP_URL ?>/buyer/messaging.php?with=<?= $tx['delivery_id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:3px 8px;margin-top:4px;color:var(--secondary);background:#eefcf6;display:inline-flex;align-items:center;gap:4px;border:1px solid #c1f3de;">
                            <i class="ri-chat-1-line"></i> Chat with Delivery
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div style="background:#f9fbfe;padding:10px;border-radius:10px;font-size:12px;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="color:var(--neutral);">Escrow Fee:</span>
                            <strong><?= formatCurrency($tx['fee']) ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--neutral);">Seller Payout:</span>
                            <strong style="color:var(--secondary);"><?= formatCurrency($tx['net_amount']) ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div style="margin-top:16px;display:flex;flex-direction:column;gap:8px;">
                    <?php if ($tx['status'] === 'delivered'): ?>
                    <!-- Highlighted Buyer Final Acceptance -->
                    <div style="background:#eefcf6;border:1.5px solid #10C87B;padding:12px;border-radius:10px;text-align:center;">
                        <div style="font-size:12px;font-weight:700;color:var(--secondary-dark);margin-bottom:4px;">
                            <i class="ri-gift-line"></i> Package Delivered!
                        </div>
                        <div style="font-size:11px;color:var(--neutral);margin-bottom:8px;">
                            Please inspect your item. If satisfied, confirm below to release payment to the Seller & Delivery driver.
                        </div>
                        <form method="POST" onsubmit="return confirm('Confirm receipt and release <?= formatCurrency($tx['net_amount']) ?> to <?= sanitize($tx['seller_name']) ?>?')">
                            <input type="hidden" name="action" value="release">
                            <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
                            <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;font-size:13px;background:var(--secondary);border-color:var(--secondary);font-weight:700;">
                                <i class="ri-checkbox-circle-line"></i> Confirm & Release Payment
                            </button>
                        </form>
                    </div>

                    <!-- Open Dispute Button -->
                    <button type="button" class="btn btn-ghost btn-sm" onclick="openDisputeModal(<?= $tx['id'] ?>, '<?= sanitize($tx['ref_code']) ?>', '<?= addslashes(sanitize($tx['title'])) ?>')" style="color:var(--danger);font-size:12px;">
                        <i class="ri-scales-3-line"></i> Problem with Item? Open Dispute
                    </button>

                    <?php elseif (in_array($tx['status'], ['funded', 'accepted', 'shipped'])): ?>
                    <div style="background:#f8fafd;border:1px solid #e2eaf8;padding:12px;border-radius:10px;font-size:12px;color:var(--neutral);text-align:center;line-height:1.4;">
                        <i class="ri-truck-line" style="color:var(--primary);font-size:16px;"></i><br>
                        <strong>Socdaalka Wuu Socdaa (In Progress)</strong><br>
                        <span style="font-size:11px;color:var(--neutral-light);">Badhanka xaqiijinta & lacag-bixinta wuxuu soo baxayaa marka darawalku ama iibiyuhu ku soo wareejiyo alaabta (Delivered).</span>
                    </div>
                    
                    <button type="button" class="btn btn-ghost btn-sm" onclick="openDisputeModal(<?= $tx['id'] ?>, '<?= sanitize($tx['ref_code']) ?>', '<?= addslashes(sanitize($tx['title'])) ?>')" style="color:var(--danger);font-size:11px;margin-top:4px;">
                        <i class="ri-scales-3-line"></i> Open Dispute (Khilaaf Fur)
                    </button>

                    <?php elseif ($tx['status'] === 'released'): ?>
                    <div style="background:#eefcf6;color:var(--secondary);padding:10px 12px;border-radius:8px;font-size:12px;text-align:center;font-weight:700;">
                        <i class="ri-checkbox-circle-fill"></i> Completed & Released by You
                    </div>
                    <?php elseif ($tx['status'] === 'disputed'): ?>
                    <div style="background:#fef2f2;color:var(--danger);padding:10px 12px;border-radius:8px;font-size:12px;text-align:center;font-weight:700;">
                        <i class="ri-scales-3-line"></i> Under SuperAdmin Review
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="pagination" style="justify-content:center;margin-top:20px;">
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
    <a href="?page=<?= $p ?>&status=<?= urlencode($status_f) ?>" class="page-btn <?= $p === $page_num ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<!-- Dispute Modal -->
<div class="modal-overlay" id="disputeModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <span class="modal-title" style="color:var(--danger);"><i class="ri-scales-3-line"></i> Open Dispute — <span id="dispRef"></span></span>
            <button class="modal-close" onclick="closeModal('disputeModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <div style="background:#fef2f2;border:1px solid #fee2e2;border-radius:12px;padding:12px;margin-bottom:16px;font-size:12px;color:#991b1b;line-height:1.4;">
                <strong>Escrow Protection Notice:</strong> Opening a dispute immediately freezes all funds. Our SuperAdmin team will review evidence from both parties and decide to either <strong>Refund Buyer</strong> or <strong>Release to Seller</strong>.
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="dispute">
                <input type="hidden" name="tx_id" id="dispTxId">

                <div class="form-group">
                    <label class="form-label">Sababta Khilaafka <span class="required">*</span></label>
                    <select name="reason" class="form-control" required>
                        <option value="">— Select a reason —</option>
                        <option value="Alaabta lama helin / Delivery ayaa dib u dhacday">Alaabta lama helin / Delivery ayaa dib u dhacday</option>
                        <option value="Alaabtu kama waafaqsana sharraxaadda / Way cilladaysan tahay">Alaabtu kama waafaqsana sharraxaadda / Way cilladaysan tahay</option>
                        <option value="Adeeg aan dhammaystirnayn / Tayo liidata">Adeeg aan dhammaystirnayn / Tayo liidata</option>
                        <option value="Iibiyuhu kama jawaabayo">Iibiyuhu kama jawaabayo</option>
                        <option value="Dhibaato kale">Dhibaato kale</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Detailed Explanation <span class="required">*</span></label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Explain clearly what went wrong..." required></textarea>
                </div>

                <!-- Visual Defect / Evidence Upload for AI Mediation -->
                <div class="form-group" style="background:#fef6f6;border:1.5px dashed #fca5a5;padding:14px;border-radius:12px;">
                    <label class="form-label" style="font-weight:700;color:var(--danger);display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                        <i class="ri-camera-fill"></i> 📸 Real-time Photo / Video of Defect or Received Item
                    </label>
                    <p style="font-size:11px;color:var(--neutral);margin-bottom:8px;line-height:1.4;">
                        Soo geli sawir cad oo muujinaya cilladda alaabta ama waxa ka khaldan. Garsooraha AI-da (Gemini Vision) ayaa toos u barbardhigaya sawirkii iibiyaha & darawalka.
                    </p>
                    <input type="file" name="evidence_file" id="disputeEvidenceInput" class="form-control" accept="image/*" capture="environment" onchange="showProofSelection(this, 'disputeEvidenceStatus')">
                    <div style="display:flex;gap:8px;margin-top:9px;flex-wrap:wrap;">
                        <button type="button" class="btn btn-sm btn-danger" onclick="openProofSource('disputeEvidenceInput', true)"><i class="ri-camera-line"></i> Fur Kaamirada</button>
                        <button type="button" class="btn btn-sm btn-ghost" onclick="openProofSource('disputeEvidenceInput', false)"><i class="ri-upload-2-line"></i> Upload Sawir</button>
                        <span id="disputeEvidenceStatus" style="font-size:11px;color:var(--secondary);align-self:center;"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Supporting Evidence Notes / Links</label>
                    <textarea name="evidence" class="form-control" rows="2" placeholder="Tracking numbers, chat logs summary, additional details..."></textarea>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('disputeModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="ri-scales-3-line"></i> Submit Dispute to Admin</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDisputeModal(txId, refCode, title) {
    document.getElementById('dispTxId').value = txId;
    document.getElementById('dispRef').textContent = refCode;
    openModal('disputeModal');
}

function openProofSource(inputId, useCamera) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (useCamera) input.setAttribute('capture', 'environment');
    else input.removeAttribute('capture');
    input.click();
}

function showProofSelection(input, statusId) {
    const status = document.getElementById(statusId);
    if (status && input.files && input.files[0]) status.textContent = '✓ ' + input.files[0].name;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
