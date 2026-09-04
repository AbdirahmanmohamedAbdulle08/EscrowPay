<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Transactions';
$active_page = 'transactions.php';
$pdo         = getDB();

$success = $error = '';

// ── Handle Actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['tx_id'] ?? 0);

    if ($action === 'force_release' && $tid) {
        $tx = $pdo->prepare("SELECT * FROM transactions WHERE id=?")->execute([$tid]);
        $pdo->prepare("UPDATE transactions SET status='released', released_at=NOW() WHERE id=?")->execute([$tid]);
        logAudit('FORCE_RELEASE', "Superadmin force-released transaction id=$tid");
        $success = 'Funds released successfully.';
    }

    if ($action === 'cancel' && $tid) {
        $pdo->prepare("UPDATE transactions SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$tid]);
        logAudit('TX_CANCELLED', "Superadmin cancelled transaction id=$tid");
        $success = 'Transaction cancelled.';
    }

    if ($action === 'resolve_dispute' && $tid) {
        $winner = $_POST['winner'] ?? 'buyer';
        $new_status = 'released';
        $pdo->prepare("UPDATE transactions SET status=?, released_at=NOW() WHERE id=?")->execute([$new_status, $tid]);
        logAudit('DISPUTE_RESOLVED', "Resolved dispute for tx id=$tid, winner=$winner");
        $success = 'Dispute resolved.';
    }
}

// ── Filters ────────────────────────────────────────────────
$status_f = $_GET['status'] ?? '';
$search_f = trim($_GET['q'] ?? '');
$date_from = $_GET['from'] ?? '';
$date_to   = $_GET['to']   ?? '';

$where  = ['1=1'];
$params = [];
if ($status_f) { $where[] = "t.status=?";                              $params[] = $status_f; }
if ($search_f) { $where[] = "(t.ref_code LIKE ? OR u1.name LIKE ? OR u2.name LIKE ?)"; $params[] = "%$search_f%"; $params[] = "%$search_f%"; $params[] = "%$search_f%"; }
if ($date_from){ $where[] = "DATE(t.created_at)>=?";                   $params[] = $date_from; }
if ($date_to)  { $where[] = "DATE(t.created_at)<=?";                   $params[] = $date_to; }
$whereSQL = implode(' AND ', $where);

$per_page    = 15;
$page_num    = max(1,(int)($_GET['page']??1));
$offset      = ($page_num-1)*$per_page;
$cnt_stmt    = $pdo->prepare("SELECT COUNT(*) FROM transactions t JOIN users u1 ON t.buyer_id=u1.id JOIN users u2 ON t.seller_id=u2.id WHERE $whereSQL");
$cnt_stmt->execute($params);
$total_count = (int)$cnt_stmt->fetchColumn();
$total_pages = max(1, ceil($total_count/$per_page));

$stmt = $pdo->prepare("SELECT t.*, u1.name AS buyer_name, u2.name AS seller_name, u3.name AS delivery_name
    FROM transactions t
    JOIN users u1 ON t.buyer_id=u1.id
    JOIN users u2 ON t.seller_id=u2.id
    LEFT JOIN users u3 ON t.delivery_id=u3.id
    WHERE $whereSQL
    ORDER BY t.created_at DESC
    LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$txs = $stmt->fetchAll();

// Assign delivery user for modal
$delivery_agents = $pdo->query("SELECT id,name FROM users WHERE role='delivery' AND status='active' ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Transactions</h1>
        <p class="page-subtitle"><?= $total_count ?> total escrow transactions</p>
    </div>
    <a href="reports.php" class="btn btn-ghost btn-sm"><i class="ri-download-line"></i> Export CSV</a>
</div>

<?php if ($success): ?><div class="alert alert-success fade-in"><i class="ri-checkbox-circle-line"></i><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger fade-in"><i class="ri-error-warning-line"></i><?= sanitize($error) ?></div><?php endif; ?>

<!-- Filters -->
<div class="card fade-in" style="margin-bottom:20px">
    <div class="card-body" style="padding:16px">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:180px">
                <label class="form-label" style="margin-bottom:5px">Search</label>
                <div class="input-icon-wrap">
                    <i class="ri-search-line input-icon"></i>
                    <input type="text" name="q" class="form-control" placeholder="Ref, buyer, seller..." value="<?= sanitize($search_f) ?>">
                </div>
            </div>
            <div>
                <label class="form-label" style="margin-bottom:5px">Status</label>
                <select name="status" class="form-control">
                    <option value="">All</option>
                    <?php foreach(['pending','funded','accepted','shipped','delivered','released','disputed','cancelled'] as $s): ?>
                    <option value="<?=$s?>" <?=$status_f===$s?'selected':''?>><?=ucfirst($s)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" style="margin-bottom:5px">From</label>
                <input type="date" name="from" class="form-control" value="<?= sanitize($date_from) ?>">
            </div>
            <div>
                <label class="form-label" style="margin-bottom:5px">To</label>
                <input type="date" name="to" class="form-control" value="<?= sanitize($date_to) ?>">
            </div>
            <div style="display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm"><i class="ri-filter-line"></i> Filter</button>
                <a href="transactions.php" class="btn btn-ghost btn-sm"><i class="ri-refresh-line"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in">
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>Title</th>
                    <th>Buyer</th>
                    <th>Seller</th>
                    <th>Delivery</th>
                    <th>Amount</th>
                    <th>Fee</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($txs)): ?>
                <tr><td colspan="10"><div class="empty-state"><i class="ri-file-list-line"></i><h3>No transactions</h3></div></td></tr>
                <?php endif; ?>
                <?php foreach ($txs as $tx): ?>
                <tr>
                    <td><strong style="color:var(--primary);font-size:12px"><?= sanitize($tx['ref_code']) ?></strong></td>
                    <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($tx['title']) ?></td>
                    <td><?= sanitize($tx['buyer_name']) ?></td>
                    <td><?= sanitize($tx['seller_name']) ?></td>
                    <td><?= sanitize($tx['delivery_name'] ?? '—') ?></td>
                    <td><strong><?= formatCurrency($tx['amount']) ?></strong></td>
                    <td style="color:var(--warning)"><?= formatCurrency($tx['fee']) ?></td>
                    <td><?= statusBadge($tx['status']) ?></td>
                    <td style="font-size:12px;color:var(--neutral-light)"><?= date('M j, Y', strtotime($tx['created_at'])) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <?php if ($tx['status'] === 'delivered'): ?>
                                <span class="badge badge-info" style="font-size:9px;" title="Buyer must inspect and confirm receipt">
                                    <i class="ri-time-line"></i> Awaiting Buyer Release
                                </span>
                                <form method="POST" style="display:inline" onsubmit="return confirm('SuperAdmin Override: Force-release funds without buyer confirmation?')">
                                    <input type="hidden" name="action" value="force_release">
                                    <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--neutral-light);font-size:10px;padding:2px 6px" title="Admin Force Override">Override</button>
                                </form>
                            <?php elseif (in_array($tx['status'], ['funded','accepted','shipped'])): ?>
                                <span style="font-size:11px;color:var(--neutral-light);font-style:italic;">In Escrow</span>
                                <form method="POST" style="display:inline" onsubmit="return confirm('SuperAdmin Override: Force-release funds now?')">
                                    <input type="hidden" name="action" value="force_release">
                                    <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--neutral-light);font-size:10px;padding:2px 6px" title="Admin Force Override">Override</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($tx['status'] === 'disputed'): ?>
                            <button class="btn btn-sm btn-danger" onclick="openModal('resolveModal<?= $tx['id'] ?>')" style="font-size:11px;padding:4px 10px"><i class="ri-gavel-line"></i> Resolve Dispute</button>
                            <?php endif; ?>
                            <?php if ($tx['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Cancel transaction?')">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--danger);font-size:11px;padding:4px 10px"><i class="ri-close-circle-line"></i> Cancel</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <?php if ($tx['status'] === 'disputed'): ?>
                <!-- Resolve Dispute Modal -->
                <div class="modal-overlay" id="resolveModal<?= $tx['id'] ?>">
                    <div class="modal" style="max-width:420px">
                        <div class="modal-header">
                            <span class="modal-title"><i class="ri-gavel-line"></i> Resolve Dispute — <?= sanitize($tx['ref_code']) ?></span>
                            <button class="modal-close" onclick="closeModal('resolveModal<?= $tx['id'] ?>')"><i class="ri-close-line"></i></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning"><i class="ri-alert-line"></i><?= sanitize($tx['dispute_reason'] ?? 'No reason provided.') ?></div>
                            <form method="POST">
                                <input type="hidden" name="action" value="resolve_dispute">
                                <input type="hidden" name="tx_id" value="<?= $tx['id'] ?>">
                                <div class="form-group">
                                    <label class="form-label">Release funds to:</label>
                                    <select name="winner" class="form-control">
                                        <option value="seller">Seller — <?= sanitize($tx['seller_name']) ?></option>
                                        <option value="buyer">Buyer — <?= sanitize($tx['buyer_name']) ?></option>
                                    </select>
                                </div>
                                <div class="modal-footer" style="padding:0;border:none;margin-top:8px">
                                    <button type="button" class="btn btn-ghost" onclick="closeModal('resolveModal<?= $tx['id'] ?>')">Cancel</button>
                                    <button type="submit" class="btn btn-primary"><i class="ri-check-line"></i> Resolve</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer" style="display:flex;align-items:center;justify-content:space-between">
        <span>Showing <?= ($offset+1) ?>–<?= min($offset+$per_page,$total_count) ?> of <?= $total_count ?></span>
        <div class="pagination">
            <?php for ($p=1; $p<=$total_pages; $p++): ?>
            <a href="?page=<?=$p?>&status=<?=urlencode($status_f)?>&q=<?=urlencode($search_f)?>" class="page-btn <?=$p===$page_num?'active':''?>"><?=$p?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
