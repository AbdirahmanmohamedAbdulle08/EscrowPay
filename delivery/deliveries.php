<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['delivery']);
$page_title  = 'Manage Deliveries';
$active_page = 'deliveries.php';
$pdo         = getDB();
$uid         = $user['id'];

$success = $error = '';

// ── Handle Delivery Agent Actions ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $did    = (int)($_POST['delivery_id'] ?? 0);
    $tid    = (int)($_POST['tx_id'] ?? 0);

    // 1. Delivery Agent Accepts an Assigned Job
    if ($action === 'accept_job' && $did) {
        $chk = $pdo->prepare("SELECT d.*, t.ref_code, t.seller_id, t.buyer_id FROM deliveries d JOIN transactions t ON d.transaction_id=t.id WHERE d.id=? AND d.delivery_id=?");
        $chk->execute([$did, $uid]);
        $deliv = $chk->fetch();

        if ($deliv) {
            $pdo->prepare("UPDATE deliveries SET delivery_accepted=1, status='assigned' WHERE id=?")->execute([$did]);
            $pdo->prepare("UPDATE transactions SET delivery_id=? WHERE id=?")->execute([$uid, $deliv['transaction_id']]);
            
            addNotification($deliv['seller_id'], 'Delivery Agent Accepted Job!', "Driver {$user['name']} accepted the delivery for order {$deliv['ref_code']} and is on the way for pickup.", 'success', APP_URL . '/seller/orders.php');
            addNotification($deliv['buyer_id'], 'Delivery Agent Dispatched', "Driver {$user['name']} accepted delivery for your order {$deliv['ref_code']}.", 'info', APP_URL . '/buyer/my_orders.php');
            logAudit('DELIVERY_ACCEPT_JOB', "Delivery driver accepted job for tx {$deliv['ref_code']}", $uid);

            $success = "You accepted delivery #{$deliv['ref_code']}! Please proceed to pickup location.";
        } else {
            $error = "Delivery assignment not found or already changed.";
        }
    }

    // 2. Delivery Agent Rejects / Declines an Assigned Job
    if ($action === 'reject_job' && $did) {
        $reason = trim(sanitize($_POST['reject_reason'] ?? 'Driver unavailable'));
        $chk = $pdo->prepare("SELECT d.*, t.ref_code, t.seller_id FROM deliveries d JOIN transactions t ON d.transaction_id=t.id WHERE d.id=? AND d.delivery_id=?");
        $chk->execute([$did, $uid]);
        $deliv = $chk->fetch();

        if ($deliv) {
            // Unassign driver and return to open pool
            $pdo->prepare("UPDATE deliveries SET delivery_id=NULL, status='open_pool', admin_approved=1, delivery_accepted=0, reject_reason=? WHERE id=?")->execute([$reason, $did]);
            $pdo->prepare("UPDATE transactions SET delivery_id=NULL WHERE id=?")->execute([$deliv['transaction_id']]);

            addNotification(1, 'Delivery Driver Declined Job', "Driver {$user['name']} declined order {$deliv['ref_code']}: $reason. Job is now in Open Pool.", 'warning', APP_URL . '/superadmin/deliveries.php');
            addNotification($deliv['seller_id'], 'Delivery Driver Declined', "Driver {$user['name']} was unavailable for order {$deliv['ref_code']}. It has been re-opened to other drivers.", 'info', APP_URL . '/seller/orders.php');
            logAudit('DELIVERY_REJECT_JOB', "Driver rejected tx {$deliv['ref_code']} reason: $reason", $uid);

            $success = "You declined this delivery job. It has been returned to the open pool.";
        }
    }

    // 3. Delivery Agent Applies / Requests an Open Pool Job
    if ($action === 'apply_job' && $did) {
        $chk = $pdo->prepare("SELECT d.*, t.ref_code, t.seller_id FROM deliveries d JOIN transactions t ON d.transaction_id=t.id WHERE d.id=? AND d.status='open_pool'");
        $chk->execute([$did]);
        $deliv = $chk->fetch();

        if ($deliv) {
            $pdo->prepare("UPDATE deliveries SET requested_by=?, status='requested_by_delivery' WHERE id=?")->execute([$uid, $did]);

            // Notify SuperAdmin
            addNotification(1, 'New Delivery Job Request', "Driver {$user['name']} applied for delivery of order {$deliv['ref_code']}. Please approve in Deliveries Hub.", 'info', APP_URL . '/superadmin/deliveries.php');
            logAudit('DELIVERY_APPLY_JOB', "Driver applied for open delivery tx {$deliv['ref_code']}", $uid);

            $success = "Application submitted! SuperAdmin will review and approve your request shortly.";
        } else {
            $error = "This job is no longer available in the open pool.";
        }
    }

    // 4. Mark Picked Up
    if ($action === 'pickup' && $did) {
        $chk = $pdo->prepare("SELECT d.*, t.ref_code, t.buyer_id, t.seller_id FROM deliveries d JOIN transactions t ON d.transaction_id=t.id WHERE d.id=? AND d.delivery_id=? AND d.delivery_accepted=1");
        $chk->execute([$did, $uid]);
        $deliv = $chk->fetch();

        if ($deliv && $deliv['status'] === 'assigned') {
            $proofPath = null;
            if (!empty($_FILES['pickup_proof']['name']) && $_FILES['pickup_proof']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['pickup_proof']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov'])) {
                    $filename = 'proof_pickup_' . $did . '_' . time() . '.' . $ext;
                    $target = __DIR__ . '/../uploads/proofs/' . $filename;
                    if (move_uploaded_file($_FILES['pickup_proof']['tmp_name'], $target)) {
                        $proofPath = 'uploads/proofs/' . $filename;
                    }
                }
            }

            if ($proofPath) {
                $pdo->prepare("UPDATE deliveries SET status='picked_up', picked_up_at=NOW(), pickup_proof=? WHERE id=?")->execute([$proofPath, $did]);
            } else {
                $pdo->prepare("UPDATE deliveries SET status='picked_up', picked_up_at=NOW() WHERE id=?")->execute([$did]);
            }
            
            addNotification($deliv['buyer_id'], 'Item Picked Up!', "Driver {$user['name']} has picked up your package for {$deliv['ref_code']} and is on the way!", 'info', APP_URL . '/buyer/my_orders.php');
            addNotification($deliv['seller_id'], 'Item Picked Up by Driver', "Driver has collected the package for {$deliv['ref_code']}.", 'info');
            logAudit('DELIVERY_PICKUP', "Picked up delivery for tx ID {$deliv['transaction_id']}", $uid);

            $success = "Marked as Picked Up! Visual pickup proof recorded. Proceed to dropoff destination.";
        }
    }

    // 5. Mark Delivered (with Dropoff Visual Proof)
    if ($action === 'deliver' && $did) {
        $chk = $pdo->prepare("SELECT d.*, t.ref_code, t.buyer_id, t.seller_id FROM deliveries d JOIN transactions t ON d.transaction_id=t.id WHERE d.id=? AND d.delivery_id=? AND d.delivery_accepted=1");
        $chk->execute([$did, $uid]);
        $deliv = $chk->fetch();

        if ($deliv && $deliv['status'] === 'picked_up') {
            $proofPath = null;
            if (!empty($_FILES['delivery_proof']['name']) && $_FILES['delivery_proof']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['delivery_proof']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov'])) {
                    $filename = 'proof_dropoff_' . $did . '_' . time() . '.' . $ext;
                    $target = __DIR__ . '/../uploads/proofs/' . $filename;
                    if (move_uploaded_file($_FILES['delivery_proof']['tmp_name'], $target)) {
                        $proofPath = 'uploads/proofs/' . $filename;
                    }
                }
            }

            if ($proofPath) {
                $pdo->prepare("UPDATE deliveries SET status='delivered', delivered_at=NOW(), delivery_proof=? WHERE id=?")->execute([$proofPath, $did]);
            } else {
                $pdo->prepare("UPDATE deliveries SET status='delivered', delivered_at=NOW() WHERE id=?")->execute([$did]);
            }
            $pdo->prepare("UPDATE transactions SET status='delivered', delivered_at=NOW() WHERE id=?")->execute([$deliv['transaction_id']]);

            addNotification($deliv['buyer_id'], 'Package Delivered!', "Driver has delivered your order {$deliv['ref_code']} with dropoff proof. Please inspect and confirm receipt to release escrow funds.", 'success', APP_URL . '/buyer/my_orders.php');
            addNotification($deliv['seller_id'], 'Package Delivered to Buyer', "Driver delivered {$deliv['ref_code']} to the buyer with visual proof.", 'info', APP_URL . '/seller/orders.php');
            logAudit('DELIVERY_DROPOFF', "Delivered tx {$deliv['ref_code']} with visual proof", $uid);

            $success = "Marked as Delivered! Visual dropoff proof recorded. Buyer has been notified to verify and release payment.";
        }
    }
}

// ── Tab Management ────────────────────────────────────────────
$tab = sanitize($_GET['tab'] ?? 'assigned');

// Count Pending Assignments (Needs driver accept/reject)
$assigned_pending_count = (int)$pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_id=? AND delivery_accepted=0 AND admin_approved=1 AND status='assigned'")->execute([$uid]) ? $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_id=? AND delivery_accepted=0 AND admin_approved=1 AND status='assigned'")->fetchColumn() : 0;

// Re-query cleanly for count
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_id=? AND delivery_accepted=0 AND admin_approved=1 AND status='assigned'");
$cnt_stmt->execute([$uid]);
$assigned_pending_count = (int)$cnt_stmt->fetchColumn();

// Active Deliveries (Accepted & In progress)
$cnt_act = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_id=? AND delivery_accepted=1 AND status IN ('assigned', 'picked_up', 'in_transit')");
$cnt_act->execute([$uid]);
$active_in_progress_count = (int)$cnt_act->fetchColumn();

// Open Jobs Pool
$open_jobs_count = (int)$pdo->query("SELECT COUNT(*) FROM deliveries WHERE status='open_pool'")->fetchColumn();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-truck-line" style="color:var(--primary)"></i> Delivery Jobs & Shipments</h1>
        <p class="page-subtitle">Manage assigned shipments, browse open marketplace delivery jobs, and complete pickups</p>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div><?php endif; ?>

<!-- Tabs Navigation -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;" class="fade-in">
    <a href="?tab=assigned" class="btn <?= $tab==='assigned'?'btn-primary':'btn-ghost' ?> btn-sm">
        <i class="ri-inbox-archive-line"></i> Assigned to Me (Needs Response)
        <?php if ($assigned_pending_count > 0): ?>
            <span class="badge badge-warning" style="margin-left:6px;font-size:10px;"><?= $assigned_pending_count ?> New</span>
        <?php endif; ?>
    </a>
    <a href="?tab=active" class="btn <?= $tab==='active'?'btn-primary':'btn-ghost' ?> btn-sm">
        <i class="ri-route-line"></i> Active In-Progress
        <span class="badge badge-info" style="margin-left:6px;font-size:10px;"><?= $active_in_progress_count ?></span>
    </a>
    <a href="?tab=open_jobs" class="btn <?= $tab==='open_jobs'?'btn-primary':'btn-ghost' ?> btn-sm">
        <i class="ri-store-2-line"></i> Available Open Jobs Pool
        <span class="badge badge-neutral" style="margin-left:6px;font-size:10px;"><?= $open_jobs_count ?></span>
    </a>
</div>

<?php if ($tab === 'assigned'): ?>
<!-- ============================================================ -->
<!-- 1. ASSIGNED JOBS (NEEDS DRIVER ACCEPT OR REJECT)              -->
<!-- ============================================================ -->
<?php
$assigned_stmt = $pdo->prepare("
    SELECT d.*, t.ref_code, t.title, t.amount, t.buyer_id, t.seller_id,
           u_buyer.name AS b_name, u_buyer.phone AS b_phone, u_buyer.address AS b_address,
           u_seller.name AS s_name, u_seller.phone AS s_phone, u_seller.address AS s_address
    FROM deliveries d
    JOIN transactions t ON d.transaction_id = t.id
    JOIN users u_buyer ON t.buyer_id = u_buyer.id
    JOIN users u_seller ON t.seller_id = u_seller.id
    WHERE d.delivery_id = ? AND d.delivery_accepted = 0 AND d.admin_approved = 1 AND d.status = 'assigned'
    ORDER BY d.id DESC
");
$assigned_stmt->execute([$uid]);
$assigned_jobs = $assigned_stmt->fetchAll();
?>

<div class="stagger">
    <?php if (empty($assigned_jobs)): ?>
    <div class="card fade-in">
        <div class="card-body">
            <div class="empty-state" style="padding:40px;">
                <i class="ri-checkbox-circle-line" style="font-size:48px;color:var(--secondary);"></i>
                <h3 style="margin-top:12px;">No pending assignments</h3>
                <p style="color:var(--neutral-light);font-size:13px;">You have responded to all assigned delivery requests. Check "Active In-Progress" or "Available Open Jobs".</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($assigned_jobs as $j): ?>
    <div class="card fade-in" style="margin-bottom:20px;border:2px solid #fed7aa;background:#fffaf5;">
        <div class="card-body" style="padding:22px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <span class="badge badge-warning" style="font-size:11px;padding:4px 10px;">
                        <i class="ri-notification-3-line"></i> Assignment Action Required
                    </span>
                    <h3 style="font-size:18px;font-weight:800;color:var(--neutral-dark);margin-top:8px;">
                        <?= sanitize($j['title']) ?> <span style="color:var(--primary);font-size:14px;">(#<?= sanitize($j['ref_code']) ?>)</span>
                    </h3>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px;color:var(--neutral-light);text-transform:uppercase;">Delivery Earnings</div>
                    <div style="font-size:22px;font-weight:800;color:var(--secondary);">$1.50</div>
                </div>
            </div>

            <!-- Locations Grid -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                <!-- Pickup -->
                <div style="background:#fff;border:1px solid #fed7aa;border-radius:10px;padding:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <div style="font-weight:700;font-size:12px;color:var(--neutral-dark);display:flex;align-items:center;gap:6px;">
                            <i class="ri-store-2-line" style="color:var(--warning);font-size:16px;"></i> Pickup (Seller)
                        </div>
                        <a href="messaging.php?with=<?= $j['seller_id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:2px 8px;">
                            <i class="ri-chat-1-line"></i> Chat
                        </a>
                    </div>
                    <div style="font-size:13px;font-weight:700;color:var(--neutral-dark);"><?= sanitize($j['s_name']) ?></div>
                    <div style="font-size:12px;color:var(--neutral);margin:4px 0;"><i class="ri-phone-line"></i> <?= sanitize($j['s_phone'] ?: 'No phone') ?></div>
                    <div style="font-size:12px;color:var(--neutral);"><i class="ri-map-pin-line"></i> <?= sanitize($j['pickup_address'] ?: ($j['s_address'] ?: 'Seller Address')) ?></div>
                </div>

                <!-- Dropoff -->
                <div style="background:#fff;border:1px solid #fed7aa;border-radius:10px;padding:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <div style="font-weight:700;font-size:12px;color:var(--neutral-dark);display:flex;align-items:center;gap:6px;">
                            <i class="ri-user-location-line" style="color:var(--primary);font-size:16px;"></i> Dropoff (Buyer)
                        </div>
                        <a href="messaging.php?with=<?= $j['buyer_id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:2px 8px;">
                            <i class="ri-chat-1-line"></i> Chat
                        </a>
                    </div>
                    <div style="font-size:13px;font-weight:700;color:var(--neutral-dark);"><?= sanitize($j['b_name']) ?></div>
                    <div style="font-size:12px;color:var(--neutral);margin:4px 0;"><i class="ri-phone-line"></i> <?= sanitize($j['b_phone'] ?: 'No phone') ?></div>
                    <div style="font-size:12px;color:var(--neutral);"><i class="ri-map-pin-line"></i> <?= sanitize($j['dropoff_address'] ?: ($j['b_address'] ?: 'Buyer Address')) ?></div>
                </div>
            </div>

            <!-- Notes -->
            <?php if (!empty($j['notes'])): ?>
            <div style="background:#fef3c7;color:#92400e;padding:8px 12px;border-radius:8px;font-size:12px;margin-bottom:18px;">
                <strong><i class="ri-information-line"></i> Package Notes:</strong> <?= sanitize($j['notes']) ?>
            </div>
            <?php endif; ?>

            <!-- Action Buttons: Accept / Reject -->
            <div style="display:flex;gap:12px;justify-content:flex-end;border-top:1px solid #fde68a;padding-top:16px;">
                <form method="POST" onsubmit="return confirm('Confirm you want to decline this delivery job?')">
                    <input type="hidden" name="action" value="reject_job">
                    <input type="hidden" name="delivery_id" value="<?= $j['id'] ?>">
                    <button type="submit" class="btn btn-ghost" style="color:var(--danger);font-size:13px;">
                        <i class="ri-close-circle-line"></i> Decline Job
                    </button>
                </form>

                <form method="POST">
                    <input type="hidden" name="action" value="accept_job">
                    <input type="hidden" name="delivery_id" value="<?= $j['id'] ?>">
                    <button type="submit" class="btn btn-primary" style="background:var(--secondary);border-color:var(--secondary);padding:8px 20px;font-size:13px;">
                        <i class="ri-checkbox-circle-line"></i> Accept Delivery Job ($1.50)
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php elseif ($tab === 'open_jobs'): ?>
<!-- ============================================================ -->
<!-- 2. OPEN MARKETPLACE JOBS POOL (APPLY / REQUEST)              -->
<!-- ============================================================ -->
<?php
$open_stmt = $pdo->query("
    SELECT d.*, t.ref_code, t.title, t.amount, t.buyer_id, t.seller_id,
           u_buyer.name AS b_name, u_buyer.phone AS b_phone, u_buyer.address AS b_address,
           u_seller.name AS s_name, u_seller.phone AS s_phone, u_seller.address AS s_address
    FROM deliveries d
    JOIN transactions t ON d.transaction_id = t.id
    JOIN users u_buyer ON t.buyer_id = u_buyer.id
    JOIN users u_seller ON t.seller_id = u_seller.id
    WHERE d.status = 'open_pool'
    ORDER BY d.id DESC
");
$open_jobs = $open_stmt->fetchAll();
?>

<div class="stagger">
    <?php if (empty($open_jobs)): ?>
    <div class="card fade-in">
        <div class="card-body">
            <div class="empty-state" style="padding:40px;">
                <i class="ri-store-2-line" style="font-size:48px;color:var(--neutral-light);"></i>
                <h3 style="margin-top:12px;">No open delivery jobs right now</h3>
                <p style="color:var(--neutral-light);font-size:13px;">New orders placed by buyers across the platform will appear here for you to apply.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($open_jobs as $oj): ?>
    <div class="card fade-in" style="margin-bottom:16px;">
        <div class="card-body" style="padding:20px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                <div>
                    <span class="badge badge-neutral" style="font-size:10px;">Open Pool</span>
                    <h3 style="font-size:16px;font-weight:700;color:var(--neutral-dark);margin-top:4px;">
                        <?= sanitize($oj['title']) ?> <span style="color:var(--primary);font-size:13px;">(#<?= sanitize($oj['ref_code']) ?>)</span>
                    </h3>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:10px;color:var(--neutral-light);text-transform:uppercase;">Payout</div>
                    <div style="font-size:20px;font-weight:800;color:var(--secondary);">$1.50</div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
                <div style="background:#f8fafd;padding:12px;border-radius:8px;border:1px solid #eef3fb;">
                    <div style="font-size:11px;font-weight:700;color:var(--warning);margin-bottom:4px;"><i class="ri-store-2-line"></i> Pickup Area</div>
                    <div style="font-size:13px;font-weight:600;"><?= sanitize($oj['s_name']) ?></div>
                    <div style="font-size:11px;color:var(--neutral);"><i class="ri-map-pin-line"></i> <?= sanitize($oj['pickup_address'] ?: ($oj['s_address'] ?: 'Seller location')) ?></div>
                </div>
                <div style="background:#f8fafd;padding:12px;border-radius:8px;border:1px solid #eef3fb;">
                    <div style="font-size:11px;font-weight:700;color:var(--primary);margin-bottom:4px;"><i class="ri-user-location-line"></i> Dropoff Destination</div>
                    <div style="font-size:13px;font-weight:600;"><?= sanitize($oj['b_name']) ?></div>
                    <div style="font-size:11px;color:var(--neutral);"><i class="ri-map-pin-line"></i> <?= sanitize($oj['dropoff_address'] ?: ($oj['b_address'] ?: 'Buyer location')) ?></div>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;border-top:1px solid #f0f4fa;padding-top:12px;">
                <form method="POST">
                    <input type="hidden" name="action" value="apply_job">
                    <input type="hidden" name="delivery_id" value="<?= $oj['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 16px;font-size:12px;">
                        <i class="ri-send-plane-fill"></i> Apply for this Delivery ($1.50)
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<!-- ============================================================ -->
<!-- 3. ACTIVE IN-PROGRESS DELIVERIES                             -->
<!-- ============================================================ -->
<?php
$active_stmt = $pdo->prepare("
    SELECT d.*, t.ref_code, t.title, t.buyer_id, t.seller_id,
           u_buyer.name AS b_name, u_buyer.phone AS b_phone, u_buyer.address AS b_address,
           u_seller.name AS s_name, u_seller.phone AS s_phone, u_seller.address AS s_address
    FROM deliveries d
    JOIN transactions t ON d.transaction_id = t.id
    JOIN users u_buyer ON t.buyer_id = u_buyer.id
    JOIN users u_seller ON t.seller_id = u_seller.id
    WHERE d.delivery_id = ? AND d.delivery_accepted = 1 AND d.status IN ('assigned', 'picked_up', 'in_transit')
    ORDER BY d.created_at ASC
");
$active_stmt->execute([$uid]);
$active_deliveries = $active_stmt->fetchAll();
?>

<div class="stagger">
    <?php if (empty($active_deliveries)): ?>
    <div class="card fade-in">
        <div class="card-body">
            <div class="empty-state" style="padding:40px;">
                <i class="ri-truck-line" style="font-size:48px;color:var(--neutral-light);"></i>
                <h3 style="margin-top:12px;">No active deliveries in progress</h3>
                <p style="color:var(--neutral-light);font-size:13px;">Accept assigned shipments or apply for open delivery jobs to start delivery.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($active_deliveries as $ad): ?>
    <div class="card fade-in" style="margin-bottom:16px;">
        <div class="card-body" style="padding:20px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <span style="font-size:12px;font-weight:700;color:var(--primary);background:rgba(29,59,139,.08);padding:3px 10px;border-radius:20px;">
                        <?= sanitize($ad['ref_code']) ?>
                    </span>
                    <h3 style="font-size:16px;font-weight:700;color:var(--neutral-dark);margin-top:8px;">
                        <?= sanitize($ad['title']) ?>
                    </h3>
                </div>
                <div>
                    <span class="badge badge-<?= $ad['status']==='assigned'?'warning':'info' ?>" style="font-size:12px;padding:6px 12px;">
                        <?= $ad['status']==='assigned' ? 'Ready for Pickup' : 'In Transit / Picked Up' ?>
                    </span>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                <!-- Pickup -->
                <div style="background:var(--tertiary);border-radius:10px;padding:16px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <div style="font-weight:700;color:var(--neutral-dark);display:flex;align-items:center;gap:6px;">
                            <i class="ri-store-2-line" style="color:var(--warning)"></i> Pickup (Seller)
                        </div>
                        <a href="messaging.php?with=<?= $ad['seller_id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:3px 8px;background:#fff;border:1px solid #d0e0fc;color:var(--primary);">
                            <i class="ri-chat-1-line"></i> Chat
                        </a>
                    </div>
                    <div style="font-size:13px;color:var(--neutral);margin-bottom:6px;"><strong><?= sanitize($ad['s_name']) ?></strong></div>
                    <div style="font-size:13px;color:var(--neutral);margin-bottom:6px;"><i class="ri-phone-line"></i> <?= sanitize($ad['s_phone'] ?: 'No phone') ?></div>
                    <div style="font-size:13px;color:var(--neutral);"><i class="ri-map-pin-line"></i> <?= sanitize($ad['pickup_address'] ?: ($ad['s_address'] ?: 'Seller Address')) ?></div>
                </div>

                <!-- Dropoff -->
                <div style="background:var(--tertiary);border-radius:10px;padding:16px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                        <div style="font-weight:700;color:var(--neutral-dark);display:flex;align-items:center;gap:6px;">
                            <i class="ri-user-location-line" style="color:var(--primary)"></i> Dropoff (Buyer)
                        </div>
                        <a href="messaging.php?with=<?= $ad['buyer_id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px;padding:3px 8px;background:#fff;border:1px solid #c1f3de;color:var(--secondary);">
                            <i class="ri-chat-1-line"></i> Chat
                        </a>
                    </div>
                    <div style="font-size:13px;color:var(--neutral);margin-bottom:6px;"><strong><?= sanitize($ad['b_name']) ?></strong></div>
                    <div style="font-size:13px;color:var(--neutral);margin-bottom:6px;"><i class="ri-phone-line"></i> <?= sanitize($ad['b_phone'] ?: 'No phone') ?></div>
                    <div style="font-size:13px;color:var(--neutral);"><i class="ri-map-pin-line"></i> <?= sanitize($ad['dropoff_address'] ?: ($ad['b_address'] ?: 'Buyer Address')) ?></div>
                </div>
            </div>

            <!-- Milestone Actions -->
            <div style="display:flex;gap:10px;border-top:1px solid #f0f4fb;padding-top:16px;justify-content:flex-end;">
                <?php if ($ad['status'] === 'assigned'): ?>
                <button type="button" class="btn btn-warning" onclick="openPickupModal(<?= $ad['id'] ?>, '<?= sanitize($ad['ref_code']) ?>', '<?= sanitize($ad['s_name']) ?>')" style="font-weight:700;padding:8px 18px;">
                    <i class="ri-camera-fill"></i> Mark as Picked Up (Photo Proof)
                </button>
                <?php endif; ?>

                <?php if ($ad['status'] === 'picked_up'): ?>
                <button type="button" class="btn btn-primary" onclick="openDropoffModal(<?= $ad['id'] ?>, '<?= sanitize($ad['ref_code']) ?>', '<?= sanitize($ad['b_name']) ?>')" style="background:var(--secondary);border-color:var(--secondary);font-weight:700;padding:8px 18px;">
                    <i class="ri-camera-lens-fill"></i> Mark as Delivered (Dropoff Photo Proof)
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 1. Pickup Modal with Real-time Visual Proof -->
<div class="modal-overlay" id="pickupProofModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-camera-lens-fill" style="color:var(--warning)"></i> 1. Pickup Visual Proof — <span id="pickupModalRef"></span></span>
            <button class="modal-close" onclick="closeModal('pickupProofModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="pickup">
                <input type="hidden" name="delivery_id" id="pickupModalDelivId">

                <div style="background:#fffbeb;border:1px solid #fde68a;padding:12px;border-radius:10px;margin-bottom:16px;">
                    <div style="font-size:12px;color:#92400e;line-height:1.4;">
                        <strong>📦 Qaadista Alaabta Seller-ka:</strong> <span id="pickupModalSeller"></span><br>
                        Fadlan sawir ka qaad xirmada/alaabta markaad ka guddoonto seller-ka si aad u caddeyso inaad badqab ku soo qaadday.
                    </div>
                </div>

                <div class="form-group" style="background:#f8fafd;border:1.5px dashed #fed7aa;padding:14px;border-radius:12px;">
                    <label class="form-label" style="font-weight:700;color:var(--neutral-dark);display:flex;align-items:center;gap:6px;">
                        <i class="ri-camera-fill" style="color:var(--warning)"></i> 📸 Live Package Condition Photo (Mandatory)
                    </label>
                    <p style="font-size:11px;color:var(--neutral-light);margin-bottom:8px;">
                        Sawirkaan wuxuu difaacayaa darawalka haddii alaabta la geeyo iyadoo horay u jabnayd.
                    </p>
                    <input type="file" name="pickup_proof" id="pickupProofInput" class="form-control" accept="image/*" capture="environment" required onchange="showProofSelection(this, 'pickupProofStatus')">
                    <div style="display:flex;gap:8px;margin-top:9px;flex-wrap:wrap;">
                        <button type="button" class="btn btn-sm btn-warning" onclick="openProofSource('pickupProofInput', true)"><i class="ri-camera-line"></i> Fur Kaamirada</button>
                        <button type="button" class="btn btn-sm btn-ghost" onclick="openProofSource('pickupProofInput', false)"><i class="ri-upload-2-line"></i> Upload Sawir</button>
                        <span id="pickupProofStatus" style="font-size:11px;color:var(--secondary);align-self:center;"></span>
                    </div>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('pickupProofModal')">Cancel</button>
                    <button type="submit" class="btn btn-warning" style="font-weight:700;">
                        <i class="ri-check-line"></i> Confirm & Collect Package
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2. Dropoff Modal with Real-time Visual Proof -->
<div class="modal-overlay" id="dropoffProofModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-checkbox-circle-fill" style="color:var(--secondary)"></i> 2. Dropoff Visual Proof — <span id="dropoffModalRef"></span></span>
            <button class="modal-close" onclick="closeModal('dropoffProofModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="deliver">
                <input type="hidden" name="delivery_id" id="dropoffModalDelivId">

                <div style="background:#eefcf6;border:1px solid #c1f3de;padding:12px;border-radius:10px;margin-bottom:16px;">
                    <div style="font-size:12px;color:#065f46;line-height:1.4;">
                        <strong>🛵 Gaarsiinta Iibsadaha:</strong> <span id="dropoffModalBuyer"></span><br>
                        Fadlan sawir ka qaad alaabta markaad gaarsiiso iibsadaha si loo xaqiijiyo in alaabtu aysan is beddelin jidka intii aad socotay.
                    </div>
                </div>

                <div class="form-group" style="background:#f8fafd;border:1.5px dashed #86efac;padding:14px;border-radius:12px;">
                    <label class="form-label" style="font-weight:700;color:var(--neutral-dark);display:flex;align-items:center;gap:6px;">
                        <i class="ri-camera-fill" style="color:var(--secondary)"></i> 📸 Dropoff Delivery Proof Photo (Mandatory)
                    </label>
                    <p style="font-size:11px;color:var(--neutral-light);margin-bottom:8px;">
                        Sawirka xilliga gaarsiinta wuxuu caddeynayaa inaadan waxba ka beddelin alaabta.
                    </p>
                    <input type="file" name="delivery_proof" id="dropoffProofInput" class="form-control" accept="image/*" capture="environment" required onchange="showProofSelection(this, 'dropoffProofStatus')">
                    <div style="display:flex;gap:8px;margin-top:9px;flex-wrap:wrap;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="openProofSource('dropoffProofInput', true)"><i class="ri-camera-line"></i> Fur Kaamirada</button>
                        <button type="button" class="btn btn-sm btn-ghost" onclick="openProofSource('dropoffProofInput', false)"><i class="ri-upload-2-line"></i> Upload Sawir</button>
                        <span id="dropoffProofStatus" style="font-size:11px;color:var(--secondary);align-self:center;"></span>
                    </div>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('dropoffProofModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background:var(--secondary);border-color:var(--secondary);font-weight:700;">
                        <i class="ri-checkbox-circle-line"></i> Confirm & Mark Delivered ($1.50 Earned)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openPickupModal(delivId, refCode, sellerName) {
    document.getElementById('pickupModalDelivId').value = delivId;
    document.getElementById('pickupModalRef').textContent = '#' + refCode;
    document.getElementById('pickupModalSeller').textContent = sellerName;
    openModal('pickupProofModal');
}

function openDropoffModal(delivId, refCode, buyerName) {
    document.getElementById('dropoffModalDelivId').value = delivId;
    document.getElementById('dropoffModalRef').textContent = '#' + refCode;
    document.getElementById('dropoffModalBuyer').textContent = buyerName;
    openModal('dropoffProofModal');
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
