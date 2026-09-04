<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

$user = getCurrentUser();
if (!$user || $user['role'] !== 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized. SuperAdmin access required.']);
    exit;
}

$disputeId = (int)($_GET['dispute_id'] ?? $_POST['dispute_id'] ?? 0);
if (!$disputeId) {
    echo json_encode(['success' => false, 'error' => 'Dispute ID is required.']);
    exit;
}

$result = analyzeDisputeWithGemini($disputeId);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
