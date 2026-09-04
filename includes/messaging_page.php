<?php
// Shared messaging page logic — included by each role's messaging.php
require_once __DIR__ . '/gemini_helper.php';
$pdo = getDB();
$uid = $user['id'];
$moderation_notice = '';
function moderateChatMessage(string $message, string $recentContext = ''): array {
    $inspectionText = trim($recentContext . ' ' . $message);
    $plain = mb_strtolower($message);
    $somaliNumberWords = '(?:eber|ebar|hal|labo|lab|saddex|sadax|afar|shan|lix|todobo|toddobo|sideed|sagaal|toban|labaatan|soddon|afartan|konton|lixdan|todobaatan|sidetan|sagaashan|sagashan|boqol|kun)';
    $patterns = [
        '/(?:https?:\/\/|www\.)/i', '/(?:wa\.me|whatsapp|telegram|signal|imo|facebook\.com|instagram\.com)/i',
        '/(?:@|\[at\]|\(at\)|\bat\b).{2,}\.(?:com|net|org|so)/i',
        '/(?:\+?\d[\d\s().-]{4,}\d)/',
        '/^\s*\d[\d\s().-]*\s*$/',
        '/\b' . $somaliNumberWords . '(?:\s+(?:iyo|oo|&)?\s*' . $somaliNumberWords . '){1,}\b/i',
        '/^\s*' . $somaliNumberWords . '\s*$/i',
        '/\b(?:evc|zaad|zaad services|edahab|paypal|bank transfer|outside escrow|escrow ka baxsan|numberka|telefoonka|contact|la soo xiriir)\b/i'
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern, $plain)) return ['blocked' => true, 'action' => 'block', 'risk_score' => 95, 'source' => 'rules', 'reason' => 'Fariintan waxay ka kooban tahay contact ama heshiis lacag-bixin oo ka baxsan Escrow.'];
    if (hasObfuscatedContactNumber($inspectionText)) return ['blocked' => true, 'action' => 'block', 'risk_score' => 95, 'source' => 'rules', 'reason' => 'Fariimaha isku xiga waxay isku daraan lambarro ama erayo tiro ah, taas oo u ekaan karta telefoon la qariyey.'];
    $ai = moderateChatWithGemini($inspectionText);
    $blocked = ($ai['action'] ?? 'allow') !== 'allow';
    return ['blocked' => $blocked, 'action' => $ai['action'] ?? 'allow', 'risk_score' => (int)($ai['risk_score'] ?? 0), 'source' => $ai['source'] ?? 'gemini', 'reason' => $ai['reason_somali'] ?: ($blocked ? 'AI Scam Shield ayaa fariintan u calaamadisay khatar.' : '')];
}

function recordChatModeration(PDO $pdo, int $senderId, int $receiverId, array $moderation): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_moderation_events (id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, receiver_id INT NOT NULL, action_taken ENUM('allow','review','block') NOT NULL, risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0, reason TEXT DEFAULT NULL, engine VARCHAR(30) NOT NULL DEFAULT 'gemini', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX chat_moderation_sender_idx (sender_id), INDEX chat_moderation_created_idx (created_at)) ENGINE=InnoDB");
    $pdo->prepare('INSERT INTO chat_moderation_events (sender_id,receiver_id,action_taken,risk_score,reason,engine) VALUES (?,?,?,?,?,?)')
        ->execute([$senderId, $receiverId, $moderation['action'] ?? 'allow', $moderation['risk_score'] ?? 0, $moderation['reason'] ?? null, $moderation['source'] ?? 'gemini']);
}

// Get conversations
$contacts = $pdo->prepare("
    SELECT DISTINCT
        CASE WHEN m.sender_id=? THEN m.receiver_id ELSE m.sender_id END AS partner_id,
        u.name AS partner_name, u.role AS partner_role,
        MAX(m.created_at) AS last_msg_time,
        (SELECT message FROM messages WHERE ((sender_id=? AND receiver_id=u.id) OR (sender_id=u.id AND receiver_id=?)) ORDER BY created_at DESC LIMIT 1) AS last_msg,
        SUM(CASE WHEN m.sender_id != ? AND m.receiver_id=? AND m.is_read=0 THEN 1 ELSE 0 END) AS unread_count
    FROM messages m
    JOIN users u ON u.id = CASE WHEN m.sender_id=? THEN m.receiver_id ELSE m.sender_id END
    WHERE m.sender_id=? OR m.receiver_id=?
    GROUP BY partner_id, u.name, u.role
    ORDER BY last_msg_time DESC
");
$contacts->execute([$uid,$uid,$uid,$uid,$uid,$uid,$uid,$uid]);
$conversations = $contacts->fetchAll();

$active_partner = (int)($_GET['with'] ?? ($conversations[0]['partner_id'] ?? 0));
$messages_list  = [];
$partner_info   = null;

if ($active_partner) {
    $pi = $pdo->prepare("SELECT id,name,role FROM users WHERE id=? LIMIT 1");
    $pi->execute([$active_partner]);
    $partner_info = $pi->fetch();
    if ($partner_info) {
        $found = false;
        foreach ($conversations as $c) {
            if ($c['partner_id'] == $partner_info['id']) { $found = true; break; }
        }
        if (!$found) {
            array_unshift($conversations, [
                'partner_id'    => $partner_info['id'],
                'partner_name'  => $partner_info['name'],
                'partner_role'  => $partner_info['role'],
                'last_msg_time' => date('Y-m-d H:i:s'),
                'last_msg'      => 'New chat started',
                'unread_count'  => 0
            ]);
        }
        $pdo->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")->execute([$active_partner,$uid]);
        $msgs = $pdo->prepare("SELECT m.*, u.name AS sender_name FROM messages m JOIN users u ON m.sender_id=u.id WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?) ORDER BY m.created_at ASC LIMIT 100");
        $msgs->execute([$uid,$active_partner,$active_partner,$uid]);
        $messages_list = $msgs->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message']) && $active_partner) {
    $msg = trim($_POST['message']);
    if ($msg) {
        $recentStmt = $pdo->prepare("SELECT message FROM messages WHERE sender_id=? AND receiver_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY created_at DESC LIMIT 4");
        $recentStmt->execute([$uid, $active_partner]);
        $recentContext = implode(' ', array_reverse(array_column($recentStmt->fetchAll(), 'message')));
        $moderation = moderateChatMessage($msg, $recentContext);
        recordChatModeration($pdo, $uid, $active_partner, $moderation);
        if ($moderation['blocked']) {
            logAudit('CHAT_MESSAGE_' . strtoupper($moderation['action'] ?? 'BLOCK'), 'AI Scam Shield blocked message to user #' . $active_partner . ' (risk ' . (int)($moderation['risk_score'] ?? 0) . '): ' . mb_substr($msg, 0, 120), $uid);
            $moderation_notice = $moderation['reason'];
        } else {
            $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)")->execute([$uid,$active_partner,$msg]);
            header("Location: messaging.php?with=$active_partner"); exit;
        }
    }
}

// All available chat contacts (based on role)
$exclude = [$uid];
$all_users = $pdo->query("SELECT id,name,role FROM users WHERE id NOT IN (".implode(',',$exclude).") AND status='active' AND role!='superadmin' ORDER BY name")->fetchAll();

include __DIR__ . '/header.php';
include __DIR__ . '/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title">Messages</h1>
        <p class="page-subtitle"><?= count($conversations) ?> conversations</p>
    </div>
    <button class="btn btn-primary btn-sm" data-modal-open="newChatModal"><i class="ri-chat-new-line"></i> New Chat</button>
</div>
<?php if ($moderation_notice): ?><div class="alert alert-danger fade-in"><i class="ri-shield-keyhole-line"></i><?= sanitize($moderation_notice) ?> Fadlan isticmaal EscrowPay si lacagtaada loo ilaaliyo.</div><?php endif; ?>

<div class="card fade-in" style="overflow:hidden">
    <div class="chat-container">
        <div class="chat-sidebar">
            <div class="chat-sidebar-header">
                <div class="chat-search"><i class="ri-search-line" style="color:var(--neutral-light)"></i><input type="text" placeholder="Search..." id="chatSearch"></div>
            </div>
            <div class="chat-list">
                <?php if (empty($conversations)): ?>
                <div class="empty-state" style="padding:30px"><i class="ri-message-3-line" style="font-size:36px"></i><p style="margin-top:10px;font-size:13px">No conversations yet</p></div>
                <?php endif; ?>
                <?php foreach ($conversations as $c): ?>
                <a href="?with=<?= $c['partner_id'] ?>" class="chat-item <?= $active_partner===$c['partner_id']?'active':'' ?>">
                    <div class="chat-item-avatar"><?= strtoupper(substr($c['partner_name'],0,1)) ?></div>
                    <div class="chat-item-info">
                        <div style="display:flex;justify-content:space-between;align-items:center">
                            <div class="chat-item-name"><?= sanitize($c['partner_name']) ?></div>
                            <div style="display:flex;align-items:center;gap:6px">
                                <?php if ($c['unread_count'] > 0): ?><span class="badge-dot badge-primary" style="position:static;border:none"><?= $c['unread_count'] ?></span><?php endif; ?>
                                <div class="chat-item-time"><?= $c['last_msg_time'] ? timeAgo($c['last_msg_time']) : '' ?></div>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;margin-top:3px">
                            <span class="role-tag role-<?= $c['partner_role'] ?>" style="font-size:9px;padding:1px 6px"><?= ucfirst($c['partner_role']) ?></span>
                            <div class="chat-item-preview" style="flex:1;font-size:11px"><?= sanitize(mb_substr($c['last_msg']??'',0,35)) ?>...</div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="chat-main">
            <?php if ($partner_info): ?>
            <div class="chat-header">
                <div class="chat-item-avatar" style="width:40px;height:40px"><?= strtoupper(substr($partner_info['name'],0,1)) ?></div>
                <div>
                    <div style="font-weight:700;font-size:14px;color:var(--neutral-dark)"><?= sanitize($partner_info['name']) ?></div>
                    <span class="role-tag role-<?= $partner_info['role'] ?>"><?= ucfirst($partner_info['role']) ?></span>
                </div>
            </div>
            <div class="chat-messages" id="msgList">
                <?php if (empty($messages_list)): ?>
                <div class="empty-state"><i class="ri-chat-1-line"></i><h3>Start the conversation</h3></div>
                <?php endif; ?>
                <?php foreach ($messages_list as $m): ?>
                <?php $is_out = $m['sender_id'] == $uid; ?>
                <div class="msg <?= $is_out ? 'msg-out' : 'msg-in' ?>">
                    <?php if (!$is_out): ?><div class="msg-avatar"><?= strtoupper(substr($m['sender_name'],0,1)) ?></div><?php endif; ?>
                    <div class="msg-content">
                        <div class="msg-bubble"><?= sanitize($m['message']) ?></div>
                        <div class="msg-time"><?= timeAgo($m['created_at']) ?><?php if ($is_out): ?> <span style="color:var(--secondary);font-weight:600">· <?= $m['is_read'] ? 'Read' : 'Sent' ?></span><?php endif; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <form class="chat-input" method="POST" id="msgForm" action="?with=<?= $active_partner ?>">
                <input type="text" id="msgInput" name="message" placeholder="Type a message..." autocomplete="off">
                <button type="submit" class="chat-send-btn"><i class="ri-send-plane-fill"></i></button>
            </form>
            <?php else: ?>
            <div class="empty-state" style="height:100%;justify-content:center">
                <i class="ri-message-3-line" style="font-size:64px"></i>
                <h3>Select a conversation</h3>
                <p>Choose a contact on the left or start a new chat.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Chat Modal -->
<div class="modal-overlay" id="newChatModal">
    <div class="modal" style="max-width:380px">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-chat-new-line"></i> New Chat</span>
            <button class="modal-close" data-modal-close><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Choose a user to message</label>
                <select class="form-control" onchange="if(this.value) window.location='messaging.php?with='+this.value">
                    <option value="">— Select user —</option>
                    <?php foreach ($all_users as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= sanitize($u['name']) ?> (<?= ucfirst($u['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<script>
const ml = document.getElementById('msgList');
if (ml) ml.scrollTop = ml.scrollHeight;
document.getElementById('chatSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.chat-item').forEach(item => item.style.display = item.textContent.toLowerCase().includes(q) ? '' : 'none');
});
document.getElementById('msgForm')?.addEventListener('submit', function(e) {
    const value = document.getElementById('msgInput').value.toLowerCase();
    const risky = /(https?:\/\/|www\.|wa\.me|whatsapp|telegram|signal|imo|\+?\d[\d\s().-]{4,}\d|^\s*\d[\d\s().-]*\s*$|(?:eber|ebar|hal|labo|lab|saddex|sadax|afar|shan|lix|todobo|toddobo|sideed|sagaal|toban|labaatan|soddon|afartan|konton|lixdan|todobaatan|sidetan|sagaashan|sagashan)(?:\s+(?:iyo|oo|&)?\s*(?:eber|ebar|hal|labo|lab|saddex|sadax|afar|shan|lix|todobo|toddobo|sideed|sagaal|toban|labaatan|soddon|afartan|konton|lixdan|todobaatan|sidetan|sagaashan|sagashan)){1,}|^\s*(?:eber|ebar|hal|labo|lab|saddex|sadax|afar|shan|lix|todobo|toddobo|sideed|sagaal|toban|labaatan|soddon|afartan|konton|lixdan|todobaatan|sidetan|sagaashan|sagashan)\s*$|evc|zaad|edahab|paypal|outside escrow|escrow ka baxsan|numberka|telefoonka|contact)/i;
    if (risky.test(value)) { e.preventDefault(); alert('AI Scam Shield: Fariintan waa la xannibay. Ha wadaagin telefoon, contact, ama lacag-bixin ka baxsan EscrowPay.'); document.getElementById('msgInput').value = ''; }
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
