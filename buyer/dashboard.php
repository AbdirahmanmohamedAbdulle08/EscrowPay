<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['buyer']);
$page_title  = 'Dashboard';
$active_page = 'dashboard.php';
$pdo         = getDB();
$uid         = $user['id'];

// Stats
$my_orders = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE buyer_id=?");
$my_orders->execute([$uid]);
$total_orders = (int)$my_orders->fetchColumn();

$active_stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE buyer_id=? AND status IN ('funded','accepted','shipped','delivered')");
$active_stmt->execute([$uid]);
$active_count = (int)$active_stmt->fetchColumn();

$completed_stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE buyer_id=? AND status='released'");
$completed_stmt->execute([$uid]);
$completed = (int)$completed_stmt->fetchColumn();

$total_spent_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE buyer_id=? AND status='released'");
$total_spent_stmt->execute([$uid]);
$total_spent = (float)$total_spent_stmt->fetchColumn();

// Recent orders
$recent = $pdo->prepare("
    SELECT t.*, u.name AS seller_name
    FROM transactions t JOIN users u ON t.seller_id=u.id
    WHERE t.buyer_id=? ORDER BY t.created_at DESC LIMIT 6
");
$recent->execute([$uid]);
$recent_orders = $recent->fetchAll();
if (!is_array($recent_orders)) {
    $recent_orders = [];
}

// ============ NOTIFICATIONS SECTION ============
try {
    $notifStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
    $notifStmt->execute([$uid]);
    $recent_notifs = $notifStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $recent_notifs = [];
}
// ============ END NOTIFICATIONS SECTION ============

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Welcome, <?= sanitize(explode(' ',$user['name'])[0]) ?>!</h1>
        <p class="page-subtitle">Your Escrow Marketplace & Order Protection Hub</p>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="marketplace.php" class="btn btn-primary"><i class="ri-shopping-cart-2-line"></i> Browse Marketplace</a>
        <a href="new_order.php" class="btn btn-ghost"><i class="ri-add-circle-line"></i> Custom Order</a>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid stagger fade-in">
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value" data-count="<?= $total_orders ?>"><?= $total_orders ?></div>
            <div class="stat-change">All time</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-file-list-3-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Active Escrows</div>
            <div class="stat-value" data-count="<?= $active_count ?>"><?= $active_count ?></div>
            <div class="stat-change"><i class="ri-time-line"></i> In progress</div>
        </div>
        <div class="stat-icon-wrap stat-icon-warning"><i class="ri-loader-2-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Completed</div>
            <div class="stat-value" data-count="<?= $completed ?>"><?= $completed ?></div>
            <div class="stat-change up"><i class="ri-checkbox-circle-line"></i> Released</div>
        </div>
        <div class="stat-icon-wrap stat-icon-secondary"><i class="ri-checkbox-circle-line"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <div class="stat-label">Total Spent</div>
            <div class="stat-value" style="font-size:20px"><?= formatCurrency($total_spent) ?></div>
            <div class="stat-change">Completed orders</div>
        </div>
        <div class="stat-icon-wrap stat-icon-primary"><i class="ri-money-dollar-circle-line"></i></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px" class="fade-in">
    <div class="card" style="grid-column:1/-1;border:1px solid #cfe0ff;background:linear-gradient(135deg,#f4f8ff,#f0fffa)">
        <div class="card-body" style="padding:22px">
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px"><i class="ri-robot-2-line" style="font-size:28px;color:var(--primary)"></i><div><h2 style="font-size:18px;color:var(--neutral-dark);margin:0">Somali Smart Shopping Assistant</h2><div style="font-size:12px;color:var(--neutral)">Cod ama qoraal ku raadi alaabta aad rabto.</div></div></div>
            <form id="smartSearchForm" style="display:flex;gap:9px"><input id="smartSearchInput" name="q" class="form-control" placeholder="Tusaale: iPhone jaban, laptop Dell, ama adeeg design..." required><button type="button" id="voiceSearchBtn" class="btn btn-ghost" title="Ku raadi cod"><i class="ri-mic-line"></i></button><button class="btn btn-primary" type="submit"><i class="ri-sparkling-line"></i> Weydii AI</button></form><div id="voiceSearchStatus" class="form-hint" style="margin-top:8px"></div><div id="smartSearchResults" style="display:none;margin-top:14px"></div>
        </div>
    </div>
    <!-- Recent Orders -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="ri-file-list-3-line" style="color:var(--primary)"></i> Recent Orders</span>
            <a href="my_orders.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Ref</th><th>Title</th><th>Seller</th><th>Amount</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($recent_orders)): ?>
                    <tr><td colspan="5"><div class="empty-state" style="padding:30px"><i class="ri-shopping-bag-line"></i><h3>No orders yet</h3><p><a href="new_order.php" class="btn btn-primary btn-sm" style="margin-top:10px">Place First Order</a></p></div></td></tr>
                    <?php else: ?>
                    <?php foreach ($recent_orders as $tx): ?>
                    <tr>
                        <td><strong style="color:var(--primary);font-size:12px"><?= sanitize($tx['ref_code']) ?></strong></td>
                        <td><?= sanitize($tx['title']) ?></td>
                        <td><?= sanitize($tx['seller_name']) ?></td>
                        <td><strong><?= formatCurrency($tx['amount']) ?></strong></td>
                        <td><?= statusBadge($tx['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Balance + Notifications -->
    <div style="display:flex;flex-direction:column;gap:16px">
        <!-- Balance Card -->
        <div class="card" style="background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;border:none">
            <div class="card-body">
                <div style="font-size:12px;opacity:.7;margin-bottom:8px;text-transform:uppercase;letter-spacing:.6px"><i class="ri-wallet-3-line"></i> Wallet Balance</div>
                <div style="font-size:36px;font-weight:800;letter-spacing:-1px"><?= formatCurrency($user['balance']) ?></div>
                <div style="margin-top:16px;font-size:12px;opacity:.7">Available for escrow funding</div>
                <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;">
                    <a href="wallet.php" style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.2);color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.3)'" onmouseout="this.style.background='rgba(255,255,255,.2)'">
                        <i class="ri-add-line"></i> Top Up
                    </a>
                    <a href="new_order.php" style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.25)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">
                        <i class="ri-secure-payment-line"></i> Fund Escrow
                    </a>
                </div>
            </div>
        </div>

        <!-- Recent Notifications -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="ri-notification-3-line" style="color:var(--primary)"></i> Notifications</span>
                <a href="notifications.php" class="btn btn-ghost btn-sm">All</a>
            </div>
            <div>
                <?php if (empty($recent_notifs)): ?>
                <div class="empty-state" style="padding:24px"><i class="ri-notification-off-line" style="font-size:32px"></i><p style="margin-top:8px">All caught up!</p></div>
                <?php else: ?>
                <?php foreach ($recent_notifs as $n): ?>
                <div style="padding:12px 16px;border-bottom:1px solid #f5f8fd">
                    <div style="font-size:13px;font-weight:600;color:var(--neutral-dark)"><?= sanitize($n['title']) ?></div>
                    <div style="font-size:12px;color:var(--neutral);margin-top:2px"><?= sanitize(substr($n['message'],0,60)) ?>...</div>
                    <div style="font-size:11px;color:var(--neutral-light);margin-top:4px"><?= timeAgo($n['created_at']) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
 const btn=document.getElementById('voiceSearchBtn'), input=document.getElementById('smartSearchInput'), status=document.getElementById('voiceSearchStatus'), form=document.getElementById('smartSearchForm'), results=document.getElementById('smartSearchResults');
 const esc=v=>String(v||'').replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[c]));
 form.addEventListener('submit', async e=>{e.preventDefault(); const query=input.value.trim(); if(!query)return; const submit=form.querySelector('[type="submit"]'); submit.disabled=true; btn.disabled=true; status.textContent='AI waxay fahmaysaa codsigaaga oo waxay baaraysaa marketplace-ka...'; results.style.display='none'; try { const body=new FormData(); body.append('query',query); const res=await fetch('../api/ai_shopping_assistant.php',{method:'POST',body}); const data=await res.json(); if(!res.ok||!data.success)throw new Error(data.error||'AI wax jawaab ah ma soo celin.'); const cards=(data.recommendations||[]).map(p=>`<a href="${esc(p.url)}" style="display:flex;align-items:center;gap:9px;padding:9px;background:#fff;border:1px solid #dbe7f6;border-radius:9px;color:inherit;text-decoration:none"><i class="ri-shopping-bag-3-line" style="color:var(--primary);font-size:18px"></i><span style="flex:1;min-width:0"><strong style="display:block;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(p.title)}</strong><small>${esc(p.category)} · $${Number(p.price).toFixed(2)}</small></span><i class="ri-arrow-right-s-line"></i></a>`).join(''); results.innerHTML=`<div style="padding:12px;border-radius:10px;background:#eef7ff;border:1px solid #cfe0ff"><strong style="font-size:13px;color:var(--primary)"><i class="ri-robot-2-line"></i> AI: </strong><span style="font-size:13px">${esc(data.reply)}</span>${cards?`<div style="display:grid;gap:7px;margin-top:10px">${cards}</div>`:''}<a class="btn btn-ghost btn-sm" style="margin-top:10px" href="${esc(data.marketplace_url)}">Dhammaan natiijooyinka <i class="ri-arrow-right-line"></i></a></div>`; results.style.display='block'; status.textContent='Talooyinka AI waa diyaar.'; } catch(err) { status.textContent=err.message; status.style.color='var(--danger)'; } finally {submit.disabled=false;btn.disabled=false;} });
 const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition, voiceSupported=!!(window.SpeechRecognition||window.webkitSpeechRecognition);
 if(!Recognition){btn.disabled=true; status.textContent='Cod raadintu browser-kan ma taageerno; isticmaal qoraalka.'; return;}
 const rec=new Recognition(); rec.lang='so-SO'; rec.interimResults=false; rec.maxAlternatives=1;
 btn.addEventListener('click',()=>{status.textContent='Dhageysanayaa... ku hadal magaca alaabta.'; btn.classList.add('active'); rec.start();});
 rec.onresult=e=>{input.value=e.results[0][0].transcript; status.textContent='Codka waa la fahmay. Riix Weydii AI.'; btn.classList.remove('active');};
 rec.onerror=()=>{status.textContent='Codka lama fahmin; fadlan mar kale isku day ama qor.'; btn.classList.remove('active');};
 rec.onend=()=>btn.classList.remove('active');
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
