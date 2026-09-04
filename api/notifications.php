<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = getCurrentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? '';
    $id     = (int)($data['id'] ?? 0);

    if ($action === 'mark_read' && $id) {
        $pdo->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$id, $user['id']]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// GET list
$limit = min(20, max(1, (int)($_GET['limit'] ?? 6)));
$stmt  = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?");
$stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

$formatted = array_map(function($n) {
    return [
        'id'       => $n['id'],
        'title'    => $n['title'],
        'message'  => $n['message'],
        'type'     => $n['type'],
        'is_read'  => (bool)$n['is_read'],
        'time_ago' => timeAgo($n['created_at'])
    ];
}, $items);

echo json_encode(['success' => true, 'items' => $formatted]);
