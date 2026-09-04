<?php
/** Real-time AI Scam Shield pre-flight check. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

function moderationResponse(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
$user = getCurrentUser();
if (!$user || !in_array($user['role'], ['buyer', 'seller', 'delivery'], true)) moderationResponse(['success' => false, 'error' => 'Unauthorized'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') moderationResponse(['success' => false, 'error' => 'POST request required'], 405);
$message = trim((string)($_POST['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 2000) moderationResponse(['success' => false, 'error' => 'Fariin sax ah geli.'], 422);
$receiverId = (int)($_POST['receiver_id'] ?? 0);
$context = $message;
if ($receiverId > 0) {
    // Catch a phone/contact split across several consecutive messages.
    $history = getDB()->prepare("SELECT message FROM messages WHERE sender_id=? AND receiver_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY created_at DESC LIMIT 4");
    $history->execute([(int)$user['id'], $receiverId]);
    $previous = array_reverse(array_column($history->fetchAll(), 'message'));
    $context = trim(implode(' ', $previous) . ' ' . $message);
}
$verdict = moderateChatWithGemini($context);
// A blocked browser-side attempt never reaches messaging.php, so audit it here.
if (($verdict['action'] ?? 'allow') !== 'allow' && $receiverId > 0) {
    $pdo = getDB();
    $check = $pdo->prepare("SELECT id FROM users WHERE id=? AND status='active' LIMIT 1");
    $check->execute([$receiverId]);
    if ($check->fetchColumn()) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS chat_moderation_events (id INT AUTO_INCREMENT PRIMARY KEY, sender_id INT NOT NULL, receiver_id INT NOT NULL, action_taken ENUM('allow','review','block') NOT NULL, risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0, reason TEXT DEFAULT NULL, engine VARCHAR(30) NOT NULL DEFAULT 'gemini', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX chat_moderation_sender_idx (sender_id), INDEX chat_moderation_created_idx (created_at)) ENGINE=InnoDB");
        $pdo->prepare('INSERT INTO chat_moderation_events (sender_id,receiver_id,action_taken,risk_score,reason,engine) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$user['id'], $receiverId, $verdict['action'], $verdict['risk_score'], $verdict['reason_somali'], $verdict['source']]);
        logAudit('CHAT_MESSAGE_' . strtoupper($verdict['action']), 'AI Scam Shield intercepted a chat message (risk ' . (int)$verdict['risk_score'] . ')', (int)$user['id']);
    }
}
moderationResponse(['success' => true, 'verdict' => $verdict]);
