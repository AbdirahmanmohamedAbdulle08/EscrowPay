<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Deliveries Hub & Dispatch';
$active_page = 'deliveries.php';
$pdo         = getDB();

$success = $error = '';

// ── Handle SuperAdmin Actions ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $did    = (int)($_POST['delivery_id'] ?? 0);
    $tid    = (int)($_POST['tx_id'] ?? 0);

    // 1. Approve Delivery Request or Seller Assignment
    if ($action === 'approve_dispatch' && $did) {
        $deliv_stmt = $pdo->prepare("
            SELECT d.*, t.ref_code, t.title, t.buyer_id, t.seller_id, u_agent.name AS agent_name, u_agent.email AS agent_email
            FROM deliveries d
            JOIN transactions t ON d.transaction_id = t.id
            LEFT JOIN users u_agent ON (d.delivery_id = u_agent.id OR d.requested_by = u_agent.id)
            WHERE d.id = ?
        ");
        $deliv_stmt->execute([$did]);
        $d = $deliv_stmt->fetch();

        if ($d) {
            $final_agent_id = $d['delivery_id'] ?: $d['requested_by'];
            
            if (!$final_agent_id && !empty($_POST['assign_agent_id'])) {
                $final_agent_id = (int)$_POST['assign_agent_id'];
            }

            if ($final_agent_id) {
                $pdo->prepare("
                    UPDATE deliveries 
                    SET delivery_id = ?, requested_by = NULL, status = 'assigned', admin_approved = 1, delivery_accepted = 0 
                    WHERE id = ?
                ")->execute([$final_agent_id, $did]);

                // Also link delivery agent to transaction
                $pdo->prepare("UPDATE transactions SET delivery_id = ? WHERE id = ?")->execute([$final_agent_id, $d['transaction_id']]);

                // Notifications
                addNotification($final_agent_id, 'Delivery Job Assigned!', "SuperAdmin approved/assigned you for delivery {$d['ref_code']}. Please review and accept the job in your Deliveries tab.", 'success', APP_URL . '/delivery/deliveries.php');
                addNotification($d['seller_id'], 'Delivery Dispatched', "SuperAdmin approved delivery agent for your order {$d['ref_code']}.", 'info', APP_URL . '/seller/orders.php');
                addNotification($d['buyer_id'], 'Delivery Agent Assigned', "Delivery has been dispatched for your order {$d['ref_code']}.", 'info', APP_URL . '/buyer/my_orders.php');

                logAudit('APPROVE_DELIVERY_DISPATCH', "SuperAdmin approved delivery for tx {$d['ref_code']} to agent #$final_agent_id");
                $success = "Delivery dispatch approved successfully for order {$d['ref_code']}!";
            } else {
                $error = "Please select a valid delivery agent to assign.";
            }
        }
    }

    // 2. Reject Delivery Request / Assignment
    if ($action === 'reject_dispatch' && $did) {
        $reason = trim(sanitize($_POST['reject_reason'] ?? 'Rejected by Admin'));
        
        $deliv_stmt = $pdo->prepare("SELECT d.*, t.ref_code, t.seller_id FROM deliveries d JOIN transactions t ON d.transaction_id=t.id WHERE d.id=?");
        $deliv_stmt->execute([$did]);
        $d = $deliv_stmt->fetch();

        if ($d) {
            $prev_agent = $d['requested_by'] ?: $d['delivery_id'];
            // Reset to open pool so other delivery agents can apply
            $pdo->prepare("
                UPDATE deliveries 
                SET delivery_id = NULL, requested_by = NULL, status = 'open_pool', admin_approved = 1, delivery_accepted = 0, reject_reason = ? 
                WHERE id = ?
            ")->execute([$reason, $did]);

            $pdo->prepare("UPDATE transactions SET delivery_id = NULL WHERE id = ?")->execute([$d['transaction_id']]);

            if ($prev_agent) {
                addNotification($prev_agent, 'Delivery Request Rejected', "Your request/assignment for order {$d['ref_code']} was rejected: $reason", 'warning');
            }
            addNotification($d['seller_id'], 'Delivery Agent Re-opened', "Delivery assignment for order {$d['ref_code']} was rejected and re-opened to all agents: $reason", 'info');

            logAudit('REJECT_DELIVERY_DISPATCH', "SuperAdmin rejected delivery #$did reason: $reason");
            $success = "Delivery request rejected and re-opened to open delivery pool.";
        }
    }

    // 3. Manual Direct Assignment by SuperAdmin
    if ($action === 'manual_assign' && $tid) {
        $agent_id = (int)$_POST['agent_id'];
        $chk_tx = $pdo->prepare("SELECT * FROM transactions WHERE id=?");
        $chk_tx->execute([$tid]);
        $tx = $chk_tx->fetch();

        if ($tx && $agent_id) {
            $pdo->prepare("UPDATE transactions SET delivery_id = ? WHERE id = ?")->execute([$agent_id, $tid]);

            // Check if delivery record exists
            $ex = $pdo->prepare("SELECT id FROM deliveries WHERE transaction_id=?");
            $ex->execute([$tid]);
            $del_id = $ex->fetchColumn();

            if ($del_id) {
                $pdo->prepare("UPDATE deliveries SET delivery_id=?, status='assigned', admin_approved=1, delivery_accepted=0 WHERE id=?")->execute([$agent_id, $del_id]);
            } else {
                $pdo->prepare("INSERT INTO deliveries (transaction_id, delivery_id, status, admin_approved, delivery_accepted) VALUES (?, ?, 'assigned', 1, 0)")->execute([$tid, $agent_id]);
            }

            addNotification($agent_id, 'Delivery Job Assigned by SuperAdmin', "SuperAdmin assigned you delivery for order {$tx['ref_code']}. Please accept the job.", 'success', APP_URL . '/delivery/deliveries.php');
            logAudit('MANUAL_DELIVERY_ASSIGN', "SuperAdmin assigned tx {$tx['ref_code']} to agent #$agent_id");
            $success = "Assigned Delivery Agent to order {$tx['ref_code']} successfully!";
        }
    }
}

// ── Tab & Filter ──────────────────────────────────────────────
$tab = sanitize($_GET['tab'] ?? 'pending');

// Stats Counters
$pending_count = (int)$pdo->query("SELECT COUNT(*) FROM deliveries WHERE status IN ('pending_admin', 'requested_by_delivery') OR (status='assigned' AND admin_approved=0)")->fetchColumn();
$active_count  = (int)$pdo->query("SELECT COUNT(*) FROM deliveries WHERE status IN ('assigned', 'picked_up', 'in_transit') AND admin_approved=1")->fetchColumn();
$open_count    = (int)$pdo->query("SELECT COUNT(*) FROM deliveries WHERE status='open_pool'")->fetchColumn();
$done_count    = (int)$pdo->query("SELECT COUNT(*) FROM deliveries WHERE status='delivered'")->fetchColumn();

// List of all active delivery agents for dropdowns
$delivery_agents = $pdo->query("SELECT id, name, email, phone FROM users WHERE role='delivery' AND status='active' ORDER BY name")->fetchAll();

// Main Query according to Tab
$where = '1=1';
$params = [];

if ($tab === 'pending') {
    $where = "d.status IN ('pending_admin', 'requested_by_delivery') OR (d.status='assigned' AND d.admin_approved=0)";
} elseif ($tab === 'open_pool') {
    $where = "d.status = 'open_pool'";
} elseif ($tab === 'active') {
    $where = "d.status IN ('assigned', 'picked_up', 'in_transit') AND d.admin_approved=1";
} elseif ($tab === 'completed') {
    $where = "d.status = 'delivered'";
}

$deliveries_query = $pdo->prepare("
    SELECT 
        d.*,
        t.ref_code, t.title AS item_title, t.amount AS order_amount, t.created_at AS order_date,
        u_seller.name AS seller_name, u_seller.email AS seller_email, u_seller.phone AS seller_phone,
        u_buyer.name AS buyer_name, u_buyer.email AS buyer_email, u_buyer.phone AS buyer_phone,
        u_agent.name AS agent_name, u_agent.email AS agent_email, u_agent.phone AS agent_phone,
        u_req.name AS req_name, u_req.email AS req_email, u_req.phone AS req_phone
    FROM deliveries d
    JOIN transactions t ON d.transaction_id = t.id
    JOIN users u_seller ON t.seller_id = u_seller.id
    JOIN users u_buyer ON t.buyer_id = u_buyer.id
    LEFT JOIN users u_agent ON d.delivery_id = u_agent.id
    LEFT JOIN users u_req ON d.requested_by = u_req.id
    WHERE $where
    ORDER BY d.id DESC
");
$deliveries_query->execute($params);
$deliveries = $deliveries_query->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-truck-line" style="color:var(--primary)"></i> Deliveries Hub & Dispatch</h1>
        <p class="page-subtitle">Approve delivery requests, dispatch assigned drivers, and monitor platform logistics</p>
    </div>
    <button class="btn btn-primary btn-sm" data-modal-open="manualAssignModal">
        <i class="ri-user-add-line"></i> Assign Driver to Order
    </button>
</div>

<?php if ($success): ?><div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= $success ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= $error ?></div><?php endif; ?>

<!-- Stat Navigation Tabs -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;" class="fade-in">
    <a href="?tab=pending" class="btn <?= $tab==='pending'?'btn-primary':'btn-ghost' ?> btn-sm">
        <i class="ri-time-line"></i> Awaiting Admin Approval
        <span class="badge badge-warning" style="margin-left:6px;font-size:10px;"><?= $pending_count ?></span>
    </a>
    <a href="?tab=open_pool" class="btn <?= $tab==='open_pool'?'btn-primary':'btn-ghost' ?> btn-sm">
        <i class="ri-share-forward-line"></i> Open Job Pool
        <span class="badge badge-neutral" style="margin-left:6px;font-size:10px;"><?= $open_count ?></span>
    </a>
    <a href="?tab=active" class="btn <?= $tab==='active'?'btn-primary':'btn-ghost' ?> btn-sm">
        <i class="ri-route-line"></i> Active In-Transit
        <span class="badge badge-info" style="margin-left:6px;font-size:10px;"><?= $active_count ?></span>
    </a>
    <a href="?tab=completed" class="btn <?= $tab==='completed'?'btn-primary':'btn-ghost' ?> btn-sm">
        <i class="ri-checkbox-circle-line"></i> Delivered & Completed
        <span class="badge badge-success" style="margin-left:6px;font-size:10px;"><?= $done_count ?></span>
    </a>
</div>

<div class="card fade-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order Ref</th>
                    <th>Product & Payout</th>
                    <th>Seller (Pickup)</th>
                    <th>Buyer (Dropoff)</th>
                    <th>Delivery Agent</th>
                    <th>Status / Stage</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deliveries)): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state" style="padding:40px;">
                            <i class="ri-truck-line" style="font-size:48px;color:var(--neutral-light);"></i>
                            <h3 style="margin-top:12px;">No deliveries found in this section</h3>
                            <p style="color:var(--neutral-light);font-size:13px;">Incoming delivery requests and assignments will appear here.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($deliveries as $d): ?>
                <tr>
                    <td>
                        <strong style="color:var(--primary);font-size:13px;"><?= sanitize($d['ref_code']) ?></strong>
                        <div style="font-size:10px;color:var(--neutral-light);"><?= date('M j, H:i', strtotime($d['assigned_at'] ?: $d['created_at'])) ?></div>
                    </td>
                    <td>
                        <div style="font-weight:700;font-size:13px;color:var(--neutral-dark);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= sanitize($d['item_title']) ?>
                        </div>
                        <div style="font-size:11px;color:var(--secondary);font-weight:700;">
                            Delivery Fee: $1.50
                        </div>
                    </td>
                    <td>
                        <div style="font-size:12px;font-weight:700;"><?= sanitize($d['seller_name']) ?></div>
                        <div style="font-size:11px;color:var(--neutral-light);"><?= sanitize($d['seller_phone'] ?: 'No phone') ?></div>
                        <div style="font-size:10px;color:var(--neutral);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($d['pickup_address'] ?: 'No address') ?>">
                            📍 <?= sanitize($d['pickup_address'] ?: 'Seller Address') ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:12px;font-weight:700;"><?= sanitize($d['buyer_name']) ?></div>
                        <div style="font-size:11px;color:var(--neutral-light);"><?= sanitize($d['buyer_phone'] ?: 'No phone') ?></div>
                        <div style="font-size:10px;color:var(--neutral);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= sanitize($d['dropoff_address'] ?: 'No address') ?>">
                            📍 <?= sanitize($d['dropoff_address'] ?: 'Buyer Address') ?>
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($d['req_name'])): ?>
                            <div style="background:#fef3c7;border:1px solid #fde68a;padding:4px 8px;border-radius:6px;">
                                <div style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;">Applied by:</div>
                                <div style="font-size:12px;font-weight:700;color:var(--neutral-dark);"><?= sanitize($d['req_name']) ?></div>
                                <div style="font-size:10px;color:var(--neutral);"><?= sanitize($d['req_phone'] ?: $d['req_email']) ?></div>
                            </div>
                        <?php elseif (!empty($d['agent_name'])): ?>
                            <div style="font-size:12px;font-weight:700;color:var(--neutral-dark);"><?= sanitize($d['agent_name']) ?></div>
                            <div style="font-size:10px;color:var(--neutral-light);"><?= sanitize($d['agent_phone'] ?: $d['agent_email']) ?></div>
                            <?php if ($d['delivery_accepted']): ?>
                                <span class="badge badge-success" style="font-size:9px;margin-top:3px;"><i class="ri-check-line"></i> Driver Accepted</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size:9px;margin-top:3px;"><i class="ri-time-line"></i> Awaiting Driver Accept</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-neutral" style="font-size:10px;">Unassigned (Open Pool)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($d['status'] === 'pending_admin' || ($d['status'] === 'assigned' && !$d['admin_approved'])): ?>
                            <span class="badge badge-warning" style="font-size:10px;"><i class="ri-time-line"></i> Pending Admin Approval</span>
                        <?php elseif ($d['status'] === 'requested_by_delivery'): ?>
                            <span class="badge badge-warning" style="font-size:10px;"><i class="ri-user-follow-line"></i> Driver Applied (Approval Needed)</span>
                        <?php elseif ($d['status'] === 'open_pool'): ?>
                            <span class="badge badge-neutral" style="font-size:10px;"><i class="ri-broadcast-line"></i> Open Job Pool</span>
                        <?php elseif ($d['status'] === 'assigned'): ?>
                            <span class="badge badge-info" style="font-size:10px;"><i class="ri-user-received-line"></i> Driver Assigned</span>
                        <?php elseif ($d['status'] === 'picked_up'): ?>
                            <span class="badge badge-warning" style="font-size:10px;background:#fef3c7;color:#92400e;"><i class="ri-hand-coin-line"></i> Picked Up</span>
                        <?php elseif ($d['status'] === 'delivered'): ?>
                            <span class="badge badge-success" style="font-size:10px;"><i class="ri-check-double-line"></i> Delivered</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <?php if ($d['status'] === 'pending_admin' || $d['status'] === 'requested_by_delivery' || ($d['status'] === 'assigned' && !$d['admin_approved'])): ?>
                                <!-- Approve Button -->
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this delivery dispatch for <?= sanitize($d['ref_code']) ?>?')">
                                    <input type="hidden" name="action" value="approve_dispatch">
                                    <input type="hidden" name="delivery_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size:11px;padding:5px 10px;background:var(--secondary);border-color:var(--secondary);">
                                        <i class="ri-check-line"></i> Approve
                                    </button>
                                </form>
                                <!-- Reject Button -->
                                <button type="button" class="btn btn-ghost btn-sm" onclick="openRejectModal(<?= $d['id'] ?>, '<?= sanitize($d['ref_code']) ?>')" style="font-size:11px;padding:5px 8px;color:var(--danger);">
                                    <i class="ri-close-line"></i> Reject
                                </button>
                            <?php elseif ($d['status'] === 'open_pool'): ?>
                                <!-- Assign Directly -->
                                <button type="button" class="btn btn-ghost btn-sm" onclick="openQuickAssignModal(<?= $d['id'] ?>, '<?= sanitize($d['ref_code']) ?>')" style="font-size:11px;padding:5px 10px;background:#edf3fc;color:var(--primary);border:1px solid #d0e0fc;">
                                    <i class="ri-user-add-line"></i> Assign Driver
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="openQuickAssignModal(<?= $d['id'] ?>, '<?= sanitize($d['ref_code']) ?>')" style="font-size:11px;padding:5px 8px;color:var(--neutral);" title="Re-assign driver">
                                    <i class="ri-restart-line"></i> Re-assign
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reject Dispatch Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-close-circle-line" style="color:var(--danger)"></i> Reject Delivery Request</span>
            <button class="modal-close" data-modal-close><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="reject_dispatch">
                <input type="hidden" name="delivery_id" id="rejectDelivId">

                <div class="form-group">
                    <label class="form-label">Reason for Rejection</label>
                    <textarea name="reject_reason" class="form-control" rows="3" placeholder="Provide reason for rejecting this driver or dispatch request..." required></textarea>
                </div>
                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="ri-close-line"></i> Reject & Reopen</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Assign Driver Modal -->
<div class="modal-overlay" id="quickAssignModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-truck-line"></i> Dispatch Driver for <span id="qaRef"></span></span>
            <button class="modal-close" data-modal-close><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="approve_dispatch">
                <input type="hidden" name="delivery_id" id="qaDelivId">

                <div class="form-group">
                    <label class="form-label">Select Verified Delivery Agent</label>
                    <select name="assign_agent_id" class="form-control" required>
                        <option value="">— Select Driver —</option>
                        <?php foreach ($delivery_agents as $da): ?>
                        <option value="<?= $da['id'] ?>"><?= sanitize($da['name']) ?> (<?= sanitize($da['phone'] ?: $da['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-send-plane-fill"></i> Approve & Dispatch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manual Order Assign Modal -->
<div class="modal-overlay" id="manualAssignModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-truck-line"></i> Assign Driver to Order</span>
            <button class="modal-close" data-modal-close><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <?php
            $active_orders = $pdo->query("SELECT id, ref_code, title, amount FROM transactions WHERE status IN ('funded', 'accepted', 'shipped') ORDER BY id DESC LIMIT 50")->fetchAll();
            ?>
            <form method="POST">
                <input type="hidden" name="action" value="manual_assign">

                <div class="form-group">
                    <label class="form-label">Select Transaction / Order</label>
                    <select name="tx_id" class="form-control" required>
                        <option value="">— Choose Order —</option>
                        <?php foreach ($active_orders as $ao): ?>
                        <option value="<?= $ao['id'] ?>">#<?= sanitize($ao['ref_code']) ?> — <?= sanitize($ao['title']) ?> (<?= formatCurrency($ao['amount']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Select Delivery Agent</label>
                    <select name="agent_id" class="form-control" required>
                        <option value="">— Choose Delivery Agent —</option>
                        <?php foreach ($delivery_agents as $da): ?>
                        <option value="<?= $da['id'] ?>"><?= sanitize($da['name']) ?> (<?= sanitize($da['phone'] ?: $da['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-footer" style="border:none;padding:0;display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Assign Driver</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRejectModal(delivId, refCode) {
    document.getElementById('rejectDelivId').value = delivId;
    openModal('rejectModal');
}

function openQuickAssignModal(delivId, refCode) {
    document.getElementById('qaDelivId').value = delivId;
    document.getElementById('qaRef').textContent = refCode;
    openModal('quickAssignModal');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
