<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gemini_helper.php';
header('Content-Type: application/json; charset=utf-8');
function nameResponse(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
requireLogin(['seller']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') nameResponse(['success' => false, 'error' => 'POST request required.'], 405);
$title = trim(sanitize($_POST['title'] ?? ''));
if (mb_strlen($title) < 2) nameResponse(['success' => false, 'error' => 'Magaca alaabta waa waajib.'], 422);
$categories = getDB()->query('SELECT name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
$list = implode(', ', $categories ?: ['General']);
$prompt = "Waxaad tahay Somali marketplace product assistant. Magaca alaabta waa: {$title}. Soo celi JSON oo keliya: {\"category\":\"hal category\",\"price\":25,\"description\":\"qoraal xayeysiin Soomaali ah\",\"type\":\"product\"}. Categories-ka la oggol yahay: {$list}. Qiimaha USD waa qiyaas suuqeed taxaddar leh. Description ha noqdo 2-4 weedhood oo xayeysiin ah, faa'iidooyin iyo baaq dalab leh, laakiin ha samayn wax aan magaca ka muuqan.";
$ai = callGeminiAI($prompt, [], 'gemini-3.6-flash', true);
if (!$ai['success']) nameResponse(['success' => false, 'error' => 'AI lama xiriiri karo.'], 503);
$item = json_decode($ai['text'], true);
if (!is_array($item)) nameResponse(['success' => false, 'error' => 'AI xog sax ah ma soo celin.'], 503);
$category = $categories[0] ?? 'General'; foreach ($categories as $allowed) if (mb_strtolower($allowed) === mb_strtolower(trim((string)($item['category'] ?? '')))) { $category = $allowed; break; }
nameResponse(['success' => true, 'listing' => ['category' => $category, 'price' => max(1, (float)($item['price'] ?? 1)), 'description' => trim((string)($item['description'] ?? '')), 'type' => ($item['type'] ?? 'product') === 'service' ? 'service' : 'product']]);
