<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['seller']);
$page_title  = 'Manage Orders';
$active_page = 'orders.php';
$pdo         = getDB();
$uid         = $user['id'];

$success = $error = '';

// List of registered delivery agents
$delivery_agents = $pdo->query("SELECT u.id, u.name, u.email, u.phone, COALESCE((SELECT COUNT(*) FROM deliveries dd WHERE dd.delivery_id=u.id AND dd.status='delivered'),0) AS completed_deliveries FROM users u WHERE u.role='delivery' AND u.status='active' ORDER BY completed_deliveries DESC, u.name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['tx_id'] ?? 0);

    $chk = $pdo->prepare("SELECT * FROM transactions WHERE id=? AND seller_id=?");
    $chk->execute([$tid, $uid]);
    $tx = $chk->fetch();

    if (!$tx) {
        $error = 'Transaction not found.';
    } elseif ($action === 'accept' && $tx['status'] === 'funded') {
        $dispatch_mode   = sanitize($_POST['dispatch_mode'] ?? 'open_pool');
        $chosen_agent_id = (int)($_POST['delivery_agent_id'] ?? 0);
        $pickup_addr     = trim(sanitize($_POST['pickup_address'] ?? ($user['address'] ?? '')));
        $deliv_notes     = trim(sanitize($_POST['delivery_notes'] ?? ''));

        // Handle Visual Proof Upload
        $proofPath = null;
        if (!empty($_FILES['dispatch_proof']['name']) && $_FILES['dispatch_proof']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['dispatch_proof']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov'])) {
                $filename = 'proof_seller_' . $tid . '_' . time() . '.' . $ext;
                $target = __DIR__ . '/../uploads/proofs/' . $filename;
                if (move_uploaded_file($_FILES['dispatch_proof']['tmp_name'], $target)) {
                    $proofPath = 'uploads/proofs/' . $filename;
                }
            }
        }

        // Update transaction to accepted (with proof if uploaded)
        if ($proofPath) {
            $pdo->prepare("UPDATE transactions SET status='accepted', accepted_at=NOW(), seller_dispatch_proof=? WHERE id=?")->execute([$proofPath, $tid]);
        } else {
            $pdo->prepare("UPDATE transactions SET status='accepted', accepted_at=NOW() WHERE id=?")->execute([$tid]);
        }

        // Check if delivery record already exists
        $deliv_chk = $pdo->prepare("SELECT id FROM deliveries WHERE transaction_id=?");
        $deliv_chk->execute([$tid]);
        $existing_deliv_id = $deliv_chk->fetchColumn();

        if ($dispatch_mode === 'assign_specific' && $chosen_agent_id) {
            // Assign specific driver (Pending SuperAdmin Approval)
            if ($existing_deliv_id) {
                $pdo->prepare("
                    UPDATE deliveries 
                    SET delivery_id = ?, pickup_address = ?, status = 'pending_admin', admin_approved = 0, delivery_accepted = 0, dispatch_proof = ?, notes = ? 
                    WHERE id = ?
                ")->execute([$chosen_agent_id, $pickup_addr, $proofPath, $deliv_notes, $existing_deliv_id]);
            } else {
                $pdo->prepare("
                    INSERT INTO deliveries (transaction_id, delivery_id, pickup_address, dropoff_address, status, admin_approved, delivery_accepted, dispatch_proof, notes)
                    VALUES (?, ?, ?, ?, 'pending_admin', 0, 0, ?, ?)
                ")->execute([$tid, $chosen_agent_id, $pickup_addr, '', $proofPath, $deliv_notes]);
            }

            // Notifications
            addNotification(1, 'Delivery Dispatch Approval Needed', "Seller {$user['name']} assigned a delivery agent for order {$tx['ref_code']}. Please approve dispatch.", 'warning', APP_URL . '/superadmin/deliveries.php');
            addNotification($chosen_agent_id, 'Delivery Assigned (Pending Admin)', "You were selected for delivery of order {$tx['ref_code']}, awaiting SuperAdmin approval.", 'info');
            addNotification($tx['buyer_id'], 'Order Accepted!', "Seller {$user['name']} has accepted order {$tx['ref_code']} and dispatched for delivery.", 'success', APP_URL . '/buyer/my_orders.php');

            logAudit('ACCEPT_ORDER_ASSIGN_DELIVERY', "Seller accepted tx {$tx['ref_code']} and assigned driver #$chosen_agent_id (pending admin)", $uid);
            $success = "Order {$tx['ref_code']} accepted! Visual packaging proof recorded & delivery agent assigned.";

        } elseif ($dispatch_mode === 'open_pool') {
            // Open pool for all delivery agents to apply
            if ($existing_deliv_id) {
                $pdo->prepare("
                    UPDATE deliveries 
                    SET delivery_id = NULL, requested_by = NULL, pickup_address = ?, status = 'open_pool', admin_approved = 1, delivery_accepted = 0, dispatch_proof = ?, notes = ? 
                    WHERE id = ?
                ")->execute([$pickup_addr, $proofPath, $deliv_notes, $existing_deliv_id]);
            } else {
                $pdo->prepare("
                    INSERT INTO deliveries (transaction_id, delivery_id, pickup_address, dropoff_address, status, admin_approved, delivery_accepted, dispatch_proof, notes)
                    VALUES (?, NULL, ?, ?, 'open_pool', 1, 0, ?, ?)
                ")->execute([$tid, $pickup_addr, '', $proofPath, $deliv_notes]);
            }

            // Notify all active delivery drivers
            foreach ($delivery_agents as $da) {
                addNotification($da['id'], 'New Delivery Job Available!', "New delivery job for order {$tx['ref_code']} is open ($1.50 payout). Apply now!", 'info', APP_URL . '/delivery/deliveries.php?tab=open_jobs');
            }
            addNotification($tx['buyer_id'], 'Order Accepted!', "Seller {$user['name']} has accepted order {$tx['ref_code']} and published to delivery dispatch.", 'success', APP_URL . '/buyer/my_orders.php');

            logAudit('ACCEPT_ORDER_OPEN_DELIVERY', "Seller accepted tx {$tx['ref_code']} and opened to delivery pool", $uid);
            $success = "Order {$tx['ref_code']} accepted! Visual packaging proof recorded & published to Delivery pool.";

        } else {
            // Self delivery / Direct service
            addNotification($tx['buyer_id'], 'Order Accepted!', "Seller {$user['name']} has accepted order {$tx['ref_code']} and is working on fulfillment.", 'success', APP_URL . '/buyer/my_orders.php');
            logAudit('ACCEPT_ORDER_SELF', "Seller accepted tx {$tx['ref_code']} for direct fulfillment", $uid);
            $success = "Order {$tx['ref_code']} accepted for direct fulfillment!";
        }

    } elseif ($action === 'deliver' && in_array($tx['status'], ['accepted', 'shipped'])) {
        $notes = trim(sanitize($_POST['delivery_notes'] ?? ''));
        
        $pdo->prepare("UPDATE transactions SET status='delivered', delivered_at=NOW() WHERE id=?")->execute([$tid]);
        
        addNotification($tx['buyer_id'], 'Order Marked as Delivered!', "Seller marked {$tx['ref_code']} as delivered. Please inspect and confirm receipt to release funds.", 'info', APP_URL . '/buyer/my_orders.php');
        logAudit('MARK_DELIVERED', "Seller marked tx {$tx['ref_code']} as delivered ($notes)", $uid);
        $success = "Order marked as Delivered! Buyer has been notified to verify and release payment.";
    }
}

// Filters
$status_f = $_GET['status'] ?? '';
$where = "t.seller_id=?";
$params = [$uid];
if ($status_f) {
    $where .= " AND t.status=?";
    $params[] = $status_f;
}

$stmt = $pdo->prepare("
    SELECT 
        t.*, 
        u.name AS buyer_name, u.email AS buyer_email, u.phone AS buyer_phone, u.address AS buyer_address,
        p.type AS product_type,
        d.id AS delivery_row_id, d.status AS delivery_stage, d.delivery_id AS assigned_deliv_id,
        d.delivery_accepted AS deliv_driver_accepted, d.admin_approved AS deliv_admin_approved,
        u_del.name AS delivery_driver_name, u_del.phone AS delivery_driver_phone
    FROM transactions t
    JOIN users u ON t.buyer_id = u.id
    LEFT JOIN products p ON t.product_id = p.id
    LEFT JOIN deliveries d ON d.transaction_id = t.id
    LEFT JOIN users u_del ON d.delivery_id = u_del.id
    WHERE $where
    ORDER BY t.created_at DESC
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-shopping-bag-line" style="color:var(--secondary)"></i> Incoming Escrow Orders</h1>
        <p class="page-subtitle">Accept buyer orders, assign delivery drivers, or open jobs for delivery bids</p>
    </div>
    <a href="products.php" class="btn btn-ghost btn-sm"><i class="ri-store-2-line"></i> Manage Products</a>
</div>

<?php if ($success): ?>
<div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div>
<?php endif; ?>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap" class="fade-in">
    <?php
    $stTabs = [
        ''          => 'All Orders',
        'funded'    => 'New / Needs Acceptance',
        'accepted'  => 'Accepted / In Progress',
        'delivered' => 'Delivered (Awaiting Release)',
        'released'  => 'Completed & Paid',
        'disputed'  => 'Disputed'
    ];
    foreach ($stTabs as $k => $l):
    ?>
    <a href="?status=<?= $k ?>" class="btn <?= $status_f === $k ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<div class="card fade-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Ref Code</th>
                    <th>Product / Service</th>
                    <th>Buyer Details</th>
                    <th>Escrow Payout</th>
                    <th>Delivery Status</th>
                    <th>Escrow Stage</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <i class="ri-shopping-bag-line"></i>
                            <h3>No orders found</h3>
                            <p>Incoming escrow orders from buyers will appear here.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($orders as $tx): ?>
                <tr>
                    <td>
                        <strong style="color:var(--primary);font-size:12px;"><?= sanitize($tx['ref_code']) ?></strong>
                        <div style="font-size:10px;color:var(--neutral-light);"><?= date('M j, Y', strtotime($tx['created_at'])) ?></div>
                    </td>
                    <td>
                        <div style="font-size:13px;font-weight:700;color:var(--neutral-dark);"><?= sanitize($tx['title']) ?></div>
                        <div style="font-size:11px;color:var(--neutral-light);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= sanitize($tx['description']) ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:13px;font-weight:600;"><?= sanitize($tx['buyer_name']) ?></div>
                        <div style="font-size:11px;color:var(--neutral-light);"><?= sanitize($tx['buyer_phone'] ?: $tx['buyer_email']) ?></div>
                        <div style="font-size:10px;color:var(--neutral);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($tx['buyer_address'] ?? '') ?>">
                            📍 <?= sanitize($tx['buyer_address'] ?: 'No address specified') ?>
                        </div>
                    </td>
                    <td>
                        <strong style="color:var(--secondary);font-size:14px;"><?= formatCurrency($tx['net_amount']) ?></strong>
                        <div style="font-size:10px;color:var(--neutral-light);">Total: <?= formatCurrency($tx['amount']) ?></div>
                    </td>
                    <td>
                        <?php if ($tx['delivery_stage'] === 'pending_admin'): ?>
                            <span class="badge badge-warning" style="font-size:9px;" title="Assigned driver awaiting SuperAdmin approval">
                                <i class="ri-time-line"></i> Pending Admin Approval
                            </span>
                        <?php elseif ($tx['delivery_stage'] === 'open_pool'): ?>
                            <span class="badge badge-neutral" style="font-size:9px;">
                                <i class="ri-broadcast-line"></i> Open for Drivers
                            </span>
                        <?php elseif ($tx['delivery_stage'] === 'assigned'): ?>
                            <?php if ($tx['deliv_driver_accepted']): ?>
                                <span class="badge badge-success" style="font-size:9px;">
                                    <i class="ri-check-line"></i> Driver Accepted (<?= sanitize($tx['delivery_driver_name']) ?>)
                                </span>
                            <?php else: ?>
                                <span class="badge badge-info" style="font-size:9px;">
                                    <i class="ri-truck-line"></i> Driver Assigned (Awaiting Driver Accept)
                                </span>
                            <?php endif; ?>
                        <?php elseif ($tx['delivery_stage'] === 'picked_up'): ?>
                            <span class="badge badge-warning" style="font-size:9px;background:#fef3c7;color:#92400e;">
                                <i class="ri-hand-coin-line"></i> Picked Up by Driver
                            </span>
                        <?php elseif ($tx['delivery_stage'] === 'delivered'): ?>
                            <span class="badge badge-success" style="font-size:9px;">
                                <i class="ri-check-double-line"></i> Delivered
                            </span>
                        <?php else: ?>
                            <span style="font-size:11px;color:var(--neutral-light);">Direct Fulfillment</span>
                        <?php endif; ?>
                    </td>
                    <td><?= statusBadge($tx['status']) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <?php if ($tx['status'] === 'funded'): ?>
                            <!-- Accept Order Modal Trigger -->
                            <button type="button" class="btn btn-sm btn-primary" onclick="openAcceptModal(<?= $tx['id'] ?>, '<?= sanitize($tx['ref_code']) ?>', '<?= addslashes(sanitize($tx['title'])) ?>')" style="font-size:11px;padding:6px 12px;">
                                <i class="ri-check-line"></i> Accept & Ship
                            </button>
                            <?php elseif (in_array($tx['status'], ['accepted', 'shipped'])): ?>
                            <!-- Mark Delivered Button -->
                            <button type="button" class="btn btn-sm" onclick="openDeliverModal(<?= $tx['id'] ?>, '<?= sanitize($tx['ref_code']) ?>')" 
                                    style="background:var(--secondary-light);color:var(--secondary-dark);font-size:11px;padding:6px 12px;font-weight:600;">
                                <i class="ri-gift-line"></i> Mark Delivered
                            </button>
                            <?php elseif ($tx['status'] === 'delivered'): ?>
                            <span style="font-size:11px;color:var(--neutral-light);font-style:italic;">Awaiting buyer release</span>
                            <?php elseif ($tx['status'] === 'released'): ?>
                            <span class="badge badge-success" style="font-size:10px;"><i class="ri-check-double-line"></i> Paid in Wallet</span>
                            <?php elseif ($tx['status'] === 'disputed'): ?>
                            <span class="badge badge-danger" style="font-size:10px;"><i class="ri-scales-3-line"></i> Disputed</span>
                            <?php endif; ?>

                            <a href="messaging.php?with=<?= $tx['buyer_id'] ?>" class="btn btn-ghost btn-sm" style="padding:5px 8px;background:#f0f4fa;color:var(--primary);border-radius:8px;" title="Chat with Buyer (<?= sanitize($tx['buyer_name']) ?>)">
                                <i class="ri-chat-1-line"></i> Chat
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Accept & Dispatch Order Modal -->
<div class="modal-overlay" id="acceptOrderModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-truck-line" style="color:var(--primary)"></i> Accept Order & Dispatch Delivery</span>
            <button class="modal-close" onclick="closeModal('acceptOrderModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="tx_id" id="acceptTxId">

                <div style="background:#f0f5ff;border:1px solid #d0e0fc;padding:12px;border-radius:10px;margin-bottom:16px;">
                    <div style="font-size:11px;color:var(--neutral-light);text-transform:uppercase;font-weight:700;">Order Details</div>
                    <div style="font-weight:800;font-size:14px;color:var(--primary);" id="acceptRefCode"></div>
                    <div style="font-size:12px;color:var(--neutral-dark);" id="acceptItemTitle"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight:700;">Delivery Dispatch Method</label>
                    
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                        <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #e2eaf8;border-radius:8px;cursor:pointer;background:#fff;">
                            <input type="radio" name="dispatch_mode" value="assign_specific" checked onchange="toggleDeliveryOptions('assign_specific')" style="margin-top:3px;">
                            <div>
                                <strong style="font-size:13px;color:var(--neutral-dark);">Assign Specific Delivery Agent</strong>
                                <p style="font-size:11px;color:var(--neutral-light);margin:2px 0 0 0;">Choose a specific driver from platform directory (Requires SuperAdmin Approval).</p>
                            </div>
                        </label>

                        <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #e2eaf8;border-radius:8px;cursor:pointer;background:#fff;">
                            <input type="radio" name="dispatch_mode" value="open_pool" onchange="toggleDeliveryOptions('open_pool')" style="margin-top:3px;">
                            <div>
                                <strong style="font-size:13px;color:var(--neutral-dark);">Open Delivery Marketplace (Driver Pool)</strong>
                                <p style="font-size:11px;color:var(--neutral-light);margin:2px 0 0 0;">Broadcast job to all delivery agents so they can apply and get approved by SuperAdmin.</p>
                            </div>
                        </label>

                        <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #e2eaf8;border-radius:8px;cursor:pointer;background:#fff;">
                            <input type="radio" name="dispatch_mode" value="self_deliver" onchange="toggleDeliveryOptions('self_deliver')" style="margin-top:3px;">
                            <div>
                                <strong style="font-size:13px;color:var(--neutral-dark);">Direct / Self Fulfillment (No Delivery Agent)</strong>
                                <p style="font-size:11px;color:var(--neutral-light);margin:2px 0 0 0;">Use this for digital products, consulting services, or in-person delivery.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Specific Driver Select -->
                <div class="form-group" id="driverSelectGroup">
                    <label class="form-label">Select Delivery Agent</label>
                    <select name="delivery_agent_id" class="form-control" id="deliveryAgentSelect">
                        <option value="">— Select a Verified Delivery Driver —</option>
                        <?php foreach ($delivery_agents as $da): ?>
                        <option value="<?= $da['id'] ?>"><?= sanitize($da['name']) ?> (<?= sanitize($da['phone'] ?: $da['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Pickup Address -->
                <div class="form-group" id="pickupAddressGroup">
                    <label class="form-label">Package Pickup Location / Store Address</label>
                    <input type="text" name="pickup_address" class="form-control" placeholder="e.g. Maka Al Mukarama St, Store #4, Mogadishu" value="<?= sanitize($user['address'] ?? '') ?>">
                </div>

                <!-- Visual Packaging Proof (AI Forensic Chain) -->
                <div class="form-group" style="background:#f4f8ff;border:1.5px dashed #93c5fd;padding:14px;border-radius:12px;">
                    <label class="form-label" style="font-weight:700;color:var(--primary);display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                        <i class="ri-camera-fill" style="font-size:16px;"></i> 📸 Live Packaging / Item Visual Proof (AI Mandatory)
                    </label>
                    <p style="font-size:11px;color:var(--neutral);margin-bottom:8px;line-height:1.4;">
                        Soo geli ama toos uga sawir qaad alaabta oo diyaarsan & xirmada. Garsooraha AI-da ayaa hubinaya haddii khilaaf yimaado.
                    </p>
                    <input type="file" name="dispatch_proof" id="dispatchProofInput" class="form-control" accept="image/*" capture="environment" onchange="showProofSelection(this, 'dispatchProofStatus')">
                    <div style="display:flex;gap:8px;margin-top:9px;flex-wrap:wrap;">
                        <button type="button" class="btn btn-sm btn-primary" onclick="openProofSource('dispatchProofInput', true)"><i class="ri-camera-line"></i> Fur Kaamirada</button>
                        <button type="button" class="btn btn-sm btn-ghost" onclick="openProofSource('dispatchProofInput', false)"><i class="ri-upload-2-line"></i> Upload Sawir</button>
                        <span id="dispatchProofStatus" style="font-size:11px;color:var(--secondary);align-self:center;"></span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes for Delivery Driver / Buyer</label>
                    <textarea name="delivery_notes" class="form-control" rows="2" placeholder="Package instructions (e.g. fragile, call before pickup)..."></textarea>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('acceptOrderModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background:var(--secondary);border-color:var(--secondary);">
                        <i class="ri-check-line"></i> Confirm & Accept Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Deliver Modal -->
<div class="modal-overlay" id="deliverModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-gift-line"></i> Confirm Delivery — <span id="delivRef"></span></span>
            <button class="modal-close" onclick="closeModal('deliverModal')"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="deliver">
                <input type="hidden" name="tx_id" id="delivTxId">

                <div class="form-group">
                    <label class="form-label">Delivery Notes / Proof of Completion</label>
                    <textarea name="delivery_notes" class="form-control" rows="3" placeholder="Provide tracking info, project deliverables download link, or completion notes for the buyer..."></textarea>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" onclick="closeModal('deliverModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background:var(--secondary);border-color:var(--secondary);">
                        <i class="ri-checkbox-circle-line"></i> Submit Delivery
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAcceptModal(txId, refCode, title) {
    document.getElementById('acceptTxId').value = txId;
    document.getElementById('acceptRefCode').textContent = '#' + refCode;
    document.getElementById('acceptItemTitle').textContent = title;
    openModal('acceptOrderModal');
}

function openDeliverModal(txId, refCode) {
    document.getElementById('delivTxId').value = txId;
    document.getElementById('delivRef').textContent = refCode;
    openModal('deliverModal');
}

function toggleDeliveryOptions(mode) {
    const driverGroup = document.getElementById('driverSelectGroup');
    const pickupGroup = document.getElementById('pickupAddressGroup');
    
    if (mode === 'assign_specific') {
        driverGroup.style.display = 'block';
        pickupGroup.style.display = 'block';
    } else if (mode === 'open_pool') {
        driverGroup.style.display = 'none';
        pickupGroup.style.display = 'block';
    } else {
        driverGroup.style.display = 'none';
        pickupGroup.style.display = 'none';
    }
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
