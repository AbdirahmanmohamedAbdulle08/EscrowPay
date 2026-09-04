<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
$user        = requireLogin(['superadmin']);
$page_title  = 'Platform Messages & Audit';
$active_page = 'messaging.php';
$pdo         = getDB();
$uid         = $user['id'];
$pdo->exec("CREATE TABLE IF NOT EXISTS chat_moderation_events (id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, receiver_id INT NOT NULL, action_taken ENUM('allow','review','block') NOT NULL, risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0, reason TEXT DEFAULT NULL, engine VARCHAR(30) NOT NULL DEFAULT 'gemini', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX chat_moderation_sender_idx (sender_id), INDEX chat_moderation_created_idx (created_at)) ENGINE=InnoDB");
$scamEvents = $pdo->query("SELECT e.*, s.name AS sender_name, r.name AS receiver_name FROM chat_moderation_events e JOIN users s ON s.id=e.sender_id JOIN users r ON r.id=e.receiver_id WHERE e.action_taken IN ('block','review') ORDER BY e.created_at DESC LIMIT 8")->fetchAll();

// Mode: 'monitor' (inspect user-to-user chats) or 'admin_chat' (direct superadmin messages)
$mode = sanitize($_GET['mode'] ?? 'monitor');

// ─────────────────────────────────────────────────────────────
// 1. MONITOR MODE: All conversations between any users
// ─────────────────────────────────────────────────────────────
$all_threads_query = $pdo->query("
    SELECT 
        LEAST(m.sender_id, m.receiver_id) AS u1_id,
        GREATEST(m.sender_id, m.receiver_id) AS u2_id,
        u1.name AS u1_name, u1.role AS u1_role, u1.email AS u1_email,
        u2.name AS u2_name, u2.role AS u2_role, u2.email AS u2_email,
        COUNT(m.id) AS total_messages,
        MAX(m.created_at) AS last_msg_time,
        (SELECT message FROM messages 
         WHERE (sender_id = LEAST(m.sender_id, m.receiver_id) AND receiver_id = GREATEST(m.sender_id, m.receiver_id))
            OR (sender_id = GREATEST(m.sender_id, m.receiver_id) AND receiver_id = LEAST(m.sender_id, m.receiver_id))
         ORDER BY created_at DESC LIMIT 1) AS last_message
    FROM messages m
    JOIN users u1 ON u1.id = LEAST(m.sender_id, m.receiver_id)
    JOIN users u2 ON u2.id = GREATEST(m.sender_id, m.receiver_id)
    GROUP BY u1_id, u2_id, u1.name, u1.role, u1.email, u2.name, u2.role, u2.email
    ORDER BY last_msg_time DESC
");
$all_threads = $all_threads_query ? $all_threads_query->fetchAll() : [];

$selected_u1 = (int)($_GET['u1'] ?? ($all_threads[0]['u1_id'] ?? 0));
$selected_u2 = (int)($_GET['u2'] ?? ($all_threads[0]['u2_id'] ?? 0));

$monitor_messages = [];
$u1_info = null;
$u2_info = null;

if ($mode === 'monitor' && $selected_u1 && $selected_u2) {
    $stmt_u1 = $pdo->prepare("SELECT id, name, role, email, phone FROM users WHERE id=?");
    $stmt_u1->execute([$selected_u1]);
    $u1_info = $stmt_u1->fetch();

    $stmt_u2 = $pdo->prepare("SELECT id, name, role, email, phone FROM users WHERE id=?");
    $stmt_u2->execute([$selected_u2]);
    $u2_info = $stmt_u2->fetch();

    $msgs_stmt = $pdo->prepare("
        SELECT m.*, u.name AS sender_name, u.role AS sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $msgs_stmt->execute([$selected_u1, $selected_u2, $selected_u2, $selected_u1]);
    $monitor_messages = $msgs_stmt->fetchAll();
}

// ─────────────────────────────────────────────────────────────
// 2. ADMIN DIRECT CHAT MODE
// ─────────────────────────────────────────────────────────────
$admin_contacts = $pdo->prepare("
    SELECT DISTINCT
        CASE WHEN m.sender_id=? THEN m.receiver_id ELSE m.sender_id END AS partner_id,
        u.name AS partner_name, u.role AS partner_role,
        MAX(m.created_at) AS last_msg_time,
        (SELECT message FROM messages WHERE ((sender_id=? AND receiver_id=u.id) OR (sender_id=u.id AND receiver_id=?)) ORDER BY created_at DESC LIMIT 1) AS last_msg
    FROM messages m
    JOIN users u ON u.id = CASE WHEN m.sender_id=? THEN m.receiver_id ELSE m.sender_id END
    WHERE m.sender_id=? OR m.receiver_id=?
    GROUP BY partner_id, u.name, u.role
    ORDER BY last_msg_time DESC
");
$admin_contacts->execute([$uid,$uid,$uid,$uid,$uid,$uid]);
$admin_conversations = $admin_contacts->fetchAll();

$active_partner = (int)($_GET['with'] ?? ($admin_conversations[0]['partner_id'] ?? 0));
$admin_messages_list = [];
$admin_partner_info  = null;

if ($mode === 'admin_chat' && $active_partner) {
    $pi = $pdo->prepare("SELECT id,name,role,email,phone FROM users WHERE id=? LIMIT 1");
    $pi->execute([$active_partner]);
    $admin_partner_info = $pi->fetch();

    if ($admin_partner_info) {
        $pdo->prepare("UPDATE messages SET is_read=1 WHERE sender_id=? AND receiver_id=?")->execute([$active_partner, $uid]);
        $msgs = $pdo->prepare("
            SELECT m.*, u.name AS sender_name
            FROM messages m JOIN users u ON m.sender_id=u.id
            WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)
            ORDER BY m.created_at ASC LIMIT 150
        ");
        $msgs->execute([$uid, $active_partner, $active_partner, $uid]);
        $admin_messages_list = $msgs->fetchAll();
    }
}

// Send admin message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['admin_message']) && $active_partner) {
    $msg = trim($_POST['admin_message']);
    if ($msg) {
        $pdo->prepare("INSERT INTO messages (sender_id,receiver_id,message) VALUES (?,?,?)")->execute([$uid, $active_partner, $msg]);
        addNotification($active_partner, 'New Message from SuperAdmin', "SuperAdmin sent you a message: " . mb_substr($msg, 0, 50) . "...", 'info', APP_URL . '/buyer/messaging.php?with=' . $uid);
        header("Location: messaging.php?mode=admin_chat&with=$active_partner");
        exit;
    }
}

$all_users = $pdo->query("SELECT id, name, role, email FROM users WHERE id != {$uid} ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="page-header fade-in">
    <div class="page-header-left">
        <h1 class="page-title"><i class="ri-message-3-line" style="color:var(--primary)"></i> Platform Messaging & Audit</h1>
        <p class="page-subtitle">Monitor user-to-user communications, prevent fraud, and send direct official messages</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <a href="?mode=monitor" class="btn <?= $mode==='monitor'?'btn-primary':'btn-ghost' ?> btn-sm">
            <i class="ri-eye-line"></i> User Conversations Monitor (<?= count($all_threads) ?>)
        </a>
        <a href="?mode=admin_chat" class="btn <?= $mode==='admin_chat'?'btn-primary':'btn-ghost' ?> btn-sm">
            <i class="ri-admin-line"></i> Direct Admin Messages
        </a>
        <button class="btn btn-secondary btn-sm" data-modal-open="newAdminChatModal">
            <i class="ri-chat-new-line"></i> Message Any User
        </button>
    </div>
</div>

<?php if ($scamEvents): ?>
<div class="card fade-in" style="margin-bottom:18px;border:1px solid #fecaca;background:#fffafa">
    <div class="card-header"><span class="card-title" style="color:#b91c1c"><i class="ri-shield-keyhole-line"></i> AI Scam Shield Alerts</span><span class="badge badge-danger"><?= count($scamEvents) ?> recent</span></div>
    <div class="card-body" style="padding:0">
        <?php foreach ($scamEvents as $event): ?>
        <div style="display:flex;gap:12px;align-items:center;padding:11px 16px;border-top:1px solid #fee2e2;font-size:12px">
            <span class="badge badge-danger"><?= strtoupper(sanitize($event['action_taken'])) ?> · <?= (int)$event['risk_score'] ?>%</span>
            <span style="flex:1"><strong><?= sanitize($event['sender_name']) ?></strong> → <?= sanitize($event['receiver_name']) ?>: <?= sanitize($event['reason'] ?: 'AI waxay calaamadisay fariin khatar ah.') ?></span>
            <span style="color:var(--neutral-light)"><?= timeAgo($event['created_at']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($mode === 'monitor'): ?>
<!-- ============================================================ -->
<!-- 1. PLATFORM CONVERSATION AUDIT & MONITOR                     -->
<!-- ============================================================ -->
<div class="card fade-in" style="overflow:hidden;border:1px solid #e2eaf8;">
    <div class="chat-container" style="height:calc(100vh - 250px);min-height:550px;">
        
        <!-- Conversation Threads List -->
        <div class="chat-sidebar" style="width:340px;background:#fbfcfe;border-right:1px solid #eef3fb;">
            <div class="chat-sidebar-header" style="padding:14px;">
                <div style="font-size:12px;font-weight:700;color:var(--neutral-dark);margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
                    <span><i class="ri-radar-line" style="color:var(--secondary)"></i> Live User Chats</span>
                    <span class="badge badge-info" style="font-size:10px;"><?= count($all_threads) ?> Pairs</span>
                </div>
                <div class="chat-search">
                    <i class="ri-search-line" style="color:var(--neutral-light)"></i>
                    <input type="text" placeholder="Search by name, email, or role..." id="threadSearch">
                </div>
            </div>

            <div class="chat-list" style="overflow-y:auto;">
                <?php if (empty($all_threads)): ?>
                <div class="empty-state" style="padding:40px 20px;">
                    <i class="ri-chat-voice-line" style="font-size:40px;color:var(--neutral-light);"></i>
                    <h4 style="margin-top:10px;font-size:14px;">No user chats yet</h4>
                    <p style="font-size:12px;color:var(--neutral-light);">Conversations between buyers, sellers, and delivery agents will appear here in real time.</p>
                </div>
                <?php endif; ?>

                <?php foreach ($all_threads as $t): 
                    $isSelected = ($selected_u1 === (int)$t['u1_id'] && $selected_u2 === (int)$t['u2_id']);
                ?>
                <a href="?mode=monitor&u1=<?= $t['u1_id'] ?>&u2=<?= $t['u2_id'] ?>" 
                   class="chat-item thread-item <?= $isSelected ? 'active' : '' ?>" 
                   style="padding:12px 14px;border-bottom:1px solid #f0f4fa;transition:all .15s;">
                    
                    <div style="display:flex;gap:10px;align-items:flex-start;width:100%;">
                        <!-- Overlapping Dual Avatar -->
                        <div style="position:relative;width:38px;height:38px;flex-shrink:0;">
                            <div style="position:absolute;top:0;left:0;width:24px;height:24px;border-radius:50%;background:var(--primary);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff;">
                                <?= strtoupper(substr($t['u1_name'], 0, 1)) ?>
                            </div>
                            <div style="position:absolute;bottom:0;right:0;width:24px;height:24px;border-radius:50%;background:var(--secondary);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff;">
                                <?= strtoupper(substr($t['u2_name'], 0, 1)) ?>
                            </div>
                        </div>

                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:4px;">
                                <div style="font-size:12px;font-weight:700;color:var(--neutral-dark);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= sanitize($t['u1_name']) ?> <span style="color:var(--neutral-light);font-size:11px;">↔</span> <?= sanitize($t['u2_name']) ?>
                                </div>
                                <span style="font-size:10px;color:var(--neutral-light);white-space:nowrap;"><?= timeAgo($t['last_msg_time']) ?></span>
                            </div>

                            <div style="display:flex;gap:4px;margin:3px 0;flex-wrap:wrap;">
                                <span class="role-tag role-<?= $t['u1_role'] ?>" style="font-size:9px;padding:1px 5px;"><?= ucfirst($t['u1_role']) ?></span>
                                <span style="font-size:10px;color:var(--neutral-light);">+</span>
                                <span class="role-tag role-<?= $t['u2_role'] ?>" style="font-size:9px;padding:1px 5px;"><?= ucfirst($t['u2_role']) ?></span>
                                <span class="badge badge-neutral" style="font-size:9px;margin-left:auto;"><?= $t['total_messages'] ?> msgs</span>
                            </div>

                            <div style="font-size:11px;color:var(--neutral);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px;">
                                <?= sanitize(mb_substr($t['last_message'] ?? '', 0, 36)) ?>...
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Chat Transcript & Participant Inspection -->
        <div class="chat-main" style="display:flex;flex-direction:column;background:#fff;">
            <?php if ($u1_info && $u2_info): ?>
            <!-- Header with participant info -->
            <div class="chat-header" style="background:#fff;border-bottom:1px solid #eef3fb;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
                <div style="display:flex;align-items:center;gap:16px;">
                    <!-- User 1 Card -->
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:34px;height:34px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">
                            <?= strtoupper(substr($u1_info['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:13px;color:var(--neutral-dark);"><?= sanitize($u1_info['name']) ?></div>
                            <div style="display:flex;gap:4px;align-items:center;">
                                <span class="role-tag role-<?= $u1_info['role'] ?>" style="font-size:9px;"><?= ucfirst($u1_info['role']) ?></span>
                                <span style="font-size:11px;color:var(--neutral-light);"><?= sanitize($u1_info['email']) ?></span>
                            </div>
                        </div>
                    </div>

                    <div style="font-size:18px;color:var(--neutral-light);"><i class="ri-arrow-left-right-line"></i></div>

                    <!-- User 2 Card -->
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:34px;height:34px;border-radius:50%;background:var(--secondary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">
                            <?= strtoupper(substr($u2_info['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:13px;color:var(--neutral-dark);"><?= sanitize($u2_info['name']) ?></div>
                            <div style="display:flex;gap:4px;align-items:center;">
                                <span class="role-tag role-<?= $u2_info['role'] ?>" style="font-size:9px;"><?= ucfirst($u2_info['role']) ?></span>
                                <span style="font-size:11px;color:var(--neutral-light);"><?= sanitize($u2_info['email']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:6px;">
                    <a href="?mode=admin_chat&with=<?= $u1_info['id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--primary);" title="Message <?= sanitize($u1_info['name']) ?>">
                        <i class="ri-chat-1-line"></i> Message <?= sanitize(explode(' ', $u1_info['name'])[0]) ?>
                    </a>
                    <a href="?mode=admin_chat&with=<?= $u2_info['id'] ?>" class="btn btn-ghost btn-sm" style="font-size:11px;color:var(--secondary);" title="Message <?= sanitize($u2_info['name']) ?>">
                        <i class="ri-chat-1-line"></i> Message <?= sanitize(explode(' ', $u2_info['name'])[0]) ?>
                    </a>
                </div>
            </div>

            <!-- Messages Log -->
            <div class="chat-messages" id="monitorMsgList" style="flex:1;overflow-y:auto;padding:20px;background:#f7f9fd;">
                <div style="text-align:center;margin-bottom:18px;">
                    <span style="background:#e8edf5;color:var(--neutral);font-size:11px;padding:4px 12px;border-radius:20px;font-weight:600;">
                        <i class="ri-shield-check-line" style="color:var(--secondary)"></i> SuperAdmin Audit Log: <?= count($monitor_messages) ?> Messages Exchanged
                    </span>
                </div>

                <?php foreach ($monitor_messages as $m): 
                    $isU1 = ($m['sender_id'] == $u1_info['id']);
                ?>
                <div style="display:flex;gap:10px;margin-bottom:14px;align-items:flex-start;<?= $isU1 ? '' : 'flex-direction:row-reverse;' ?>">
                    <div style="width:32px;height:32px;border-radius:50%;background:<?= $isU1 ? 'var(--primary)' : 'var(--secondary)' ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;flex-shrink:0;">
                        <?= strtoupper(substr($m['sender_name'], 0, 1)) ?>
                    </div>
                    <div style="max-width:70%;">
                        <div style="display:flex;gap:6px;align-items:center;margin-bottom:3px;<?= $isU1 ? '' : 'justify-content:flex-end;' ?>">
                            <span style="font-size:11px;font-weight:700;color:var(--neutral-dark);"><?= sanitize($m['sender_name']) ?></span>
                            <span class="role-tag role-<?= $m['sender_role'] ?>" style="font-size:8px;padding:0 4px;"><?= ucfirst($m['sender_role']) ?></span>
                            <span style="font-size:10px;color:var(--neutral-light);"><?= date('M j, H:i', strtotime($m['created_at'])) ?></span>
                        </div>
                        <div style="padding:10px 14px;border-radius:12px;background:<?= $isU1 ? '#fff' : '#1D3B8B' ?>;color:<?= $isU1 ? 'var(--neutral-dark)' : '#fff' ?>;border:<?= $isU1 ? '1px solid #e2eaf8' : 'none' ?>;box-shadow:0 1px 4px rgba(0,0,0,.04);font-size:13px;line-height:1.4;word-break:break-word;">
                            <?= nl2br(sanitize($m['message'])) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($monitor_messages)): ?>
                <div class="empty-state" style="padding:40px;">
                    <i class="ri-chat-smile-2-line" style="font-size:40px;"></i>
                    <p>No messages recorded between these two users yet.</p>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="empty-state" style="height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px;">
                <i class="ri-message-3-line" style="font-size:56px;color:var(--neutral-light);"></i>
                <h3 style="margin-top:12px;">Select a conversation to inspect</h3>
                <p style="color:var(--neutral-light);font-size:13px;max-width:360px;text-align:center;">Click on any user pair from the left panel to review their complete chat history.</p>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php else: ?>
<!-- ============================================================ -->
<!-- 2. DIRECT ADMIN MESSAGING                                    -->
<!-- ============================================================ -->
<div class="card fade-in" style="overflow:hidden;border:1px solid #e2eaf8;">
    <div class="chat-container" style="height:calc(100vh - 250px);min-height:550px;">
        <!-- Left Contacts -->
        <div class="chat-sidebar" style="width:320px;background:#fbfcfe;border-right:1px solid #eef3fb;">
            <div class="chat-sidebar-header" style="padding:14px;">
                <div style="font-size:12px;font-weight:700;color:var(--neutral-dark);margin-bottom:8px;">
                    <i class="ri-admin-line" style="color:var(--primary)"></i> Direct Support & Official Chats
                </div>
                <div class="chat-search">
                    <i class="ri-search-line" style="color:var(--neutral-light)"></i>
                    <input type="text" placeholder="Search contacts..." id="adminChatSearch">
                </div>
            </div>
            <div class="chat-list" style="overflow-y:auto;">
                <?php if (empty($admin_conversations)): ?>
                <div class="empty-state" style="padding:30px;">
                    <i class="ri-chat-3-line"></i>
                    <p style="font-size:12px;">No direct messages yet.</p>
                </div>
                <?php endif; ?>

                <?php foreach ($admin_conversations as $c): ?>
                <a href="?mode=admin_chat&with=<?= $c['partner_id'] ?>" class="chat-item <?= $active_partner===$c['partner_id']?'active':'' ?>" style="padding:12px 14px;border-bottom:1px solid #f0f4fa;">
                    <div class="chat-item-avatar"><?= strtoupper(substr($c['partner_name'],0,1)) ?></div>
                    <div class="chat-item-info">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div class="chat-item-name"><?= sanitize($c['partner_name']) ?></div>
                            <div class="chat-item-time"><?= timeAgo($c['last_msg_time']) ?></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;margin-top:2px;">
                            <span class="role-tag role-<?= $c['partner_role'] ?>" style="font-size:9px;"><?= ucfirst($c['partner_role']) ?></span>
                            <div class="chat-item-preview" style="flex:1;"><?= sanitize(mb_substr($c['last_msg'] ?? '',0,30)) ?>...</div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right Main Chat -->
        <div class="chat-main" style="display:flex;flex-direction:column;">
            <?php if ($admin_partner_info): ?>
            <div class="chat-header" style="padding:14px 20px;border-bottom:1px solid #eef3fb;display:flex;align-items:center;gap:12px;">
                <div class="chat-item-avatar" style="width:38px;height:38px;"><?= strtoupper(substr($admin_partner_info['name'],0,1)) ?></div>
                <div>
                    <div style="font-weight:700;font-size:14px;color:var(--neutral-dark);"><?= sanitize($admin_partner_info['name']) ?></div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <span class="role-tag role-<?= $admin_partner_info['role'] ?>"><?= ucfirst($admin_partner_info['role']) ?></span>
                        <span style="font-size:11px;color:var(--neutral-light);"><?= sanitize($admin_partner_info['email']) ?></span>
                    </div>
                </div>
            </div>

            <div class="chat-messages" id="adminMsgList" style="flex:1;overflow-y:auto;padding:20px;background:#f7f9fd;">
                <?php foreach ($admin_messages_list as $m): 
                    $is_out = ($m['sender_id'] == $uid);
                ?>
                <div class="msg <?= $is_out ? 'msg-out' : 'msg-in' ?>">
                    <?php if (!$is_out): ?>
                    <div class="msg-avatar"><?= strtoupper(substr($m['sender_name'],0,1)) ?></div>
                    <?php endif; ?>
                    <div class="msg-content">
                        <div class="msg-bubble"><?= sanitize($m['message']) ?></div>
                        <div class="msg-time"><?= timeAgo($m['created_at']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($admin_messages_list)): ?>
                <div class="empty-state">
                    <i class="ri-chat-1-line"></i>
                    <h3>Send official message</h3>
                    <p>Type below to send an official SuperAdmin message to <?= sanitize($admin_partner_info['name']) ?>.</p>
                </div>
                <?php endif; ?>
            </div>

            <form class="chat-input" method="POST" action="?mode=admin_chat&with=<?= $active_partner ?>">
                <input type="text" name="admin_message" placeholder="Type official message to <?= sanitize($admin_partner_info['name']) ?>..." required autocomplete="off">
                <button type="submit" class="chat-send-btn"><i class="ri-send-plane-fill"></i></button>
            </form>

            <?php else: ?>
            <div class="empty-state" style="height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                <i class="ri-admin-line" style="font-size:56px;color:var(--neutral-light);"></i>
                <h3 style="margin-top:12px;">Direct Admin Messenger</h3>
                <p style="color:var(--neutral-light);font-size:13px;">Select a user from the left or click "Message Any User" above.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- New Admin Chat Modal -->
<div class="modal-overlay" id="newAdminChatModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-header">
            <span class="modal-title"><i class="ri-chat-new-line"></i> Start Chat with User</span>
            <button class="modal-close" data-modal-close><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label">Select Platform User</label>
                <select class="form-control" onchange="if(this.value) window.location='messaging.php?mode=admin_chat&with='+this.value">
                    <option value="">— Choose a User —</option>
                    <?php foreach ($all_users as $u): ?>
                    <option value="<?= $u['id'] ?>">
                        <?= sanitize($u['name']) ?> (<?= ucfirst($u['role']) ?> — <?= sanitize($u['email']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<script>
// Auto scroll
const mml = document.getElementById('monitorMsgList');
if (mml) mml.scrollTop = mml.scrollHeight;

const aml = document.getElementById('adminMsgList');
if (aml) aml.scrollTop = aml.scrollHeight;

// Search live threads
document.getElementById('threadSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.thread-item').forEach(item => {
        item.style.display = item.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Search admin contacts
document.getElementById('adminChatSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.chat-item').forEach(item => {
        item.style.display = item.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
