<?php
/**
 * Somali Smart Shopping Assistant (Phase 3).
 * Turns a natural-language or voice transcript into a small, ranked catalogue
 * recommendation. The model is deliberately given only active marketplace data.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

function shoppingResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = requireLogin(['buyer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') shoppingResponse(['success' => false, 'error' => 'POST request required.'], 405);

$request = trim((string)($_POST['query'] ?? ''));
if (mb_strlen($request) < 2 || mb_strlen($request) > 500) {
    shoppingResponse(['success' => false, 'error' => 'Fadlan qor ama ku hadal codsi alaab oo u dhexeeya 2 iyo 500 xaraf.'], 422);
}

$pdo = getDB();
$catalogueStmt = $pdo->query("SELECT p.id, p.title, p.description, p.price, p.type, p.image,
    c.name AS category_name, c.slug AS category_slug, u.name AS seller_name,
    COALESCE((SELECT ROUND(AVG(pr.rating), 1) FROM product_ratings pr WHERE pr.product_id=p.id), 0) AS rating
    FROM products p
    LEFT JOIN categories c ON c.id=p.category_id
    JOIN users u ON u.id=p.seller_id
    WHERE p.status='active' AND u.status='active'
    ORDER BY p.created_at DESC LIMIT 80");
$catalogue = $catalogueStmt->fetchAll();
if (!$catalogue) shoppingResponse(['success' => false, 'error' => 'Hadda wax alaab ah oo la soo jeedin karo ma jiraan.'], 404);

// Keep the prompt compact, deterministic and limited to products actually for sale.
$items = [];
foreach ($catalogue as $item) {
    $items[] = ['id' => (int)$item['id'], 'title' => $item['title'], 'category' => $item['category_name'],
        'type' => $item['type'], 'price' => (float)$item['price'], 'rating' => (float)$item['rating'],
        'description' => mb_substr((string)$item['description'], 0, 160)];
}
$prompt = "Waxaad tahay Somali Smart Shopping Assistant ee EscrowPay. Faham codsiga buyer-ka, ka dibna ka xulo catalogue-ka alaabta ugu habboon. Ha abuuran alaab, qiime, ama ID aan catalogue-ka ku jirin. Tixgeli miisaaniyadda, nooca (product/service), category, iyo baahida user-ka.\n\nCodsiga buyer-ka: " . $request . "\n\nCATALOGUE:\n" . json_encode($items, JSON_UNESCAPED_UNICODE) . "\n\nSoo celi JSON oo keliya qaabkan: {\"reply_somali\":\"jawaab kooban oo Soomaali ah\",\"search_terms\":\"ereyada raadinta ugu habboon\",\"product_ids\":[1,2,3],\"category_slug\":\"slug ama madhan\",\"type\":\"product|service|\",\"sort\":\"price_asc|price_desc|newest\"}. Dooro ugu badnaan 6 product_ids.";

$ai = callGeminiAI($prompt, [], 'gemini-3.6-flash', true);
if (!$ai['success']) $ai = callGeminiAI($prompt, [], 'gemini-flash-latest', true);
if (!$ai['success']) shoppingResponse(['success' => false, 'error' => 'Kaaliyaha AI hadda lama xiriiri karo. Fadlan mar kale isku day.'], 503);

$result = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($ai['text'])), true);
if (!is_array($result)) shoppingResponse(['success' => false, 'error' => 'AI waxay soo celisay jawaab aan sax ahayn. Fadlan mar kale isku day.'], 503);

$byId = [];
foreach ($catalogue as $item) $byId[(int)$item['id']] = $item;
$recommendations = [];
foreach ((array)($result['product_ids'] ?? []) as $id) {
    $id = (int)$id;
    if (isset($byId[$id]) && !isset($recommendations[$id])) {
        $item = $byId[$id];
        $recommendations[$id] = ['id' => $id, 'title' => $item['title'], 'price' => (float)$item['price'],
            'type' => $item['type'], 'category' => $item['category_name'], 'image' => $item['image'],
            'url' => APP_URL . '/buyer/product_view.php?id=' . $id];
    }
}

$validSlugs = array_flip(array_filter(array_column($catalogue, 'category_slug')));
$category = trim((string)($result['category_slug'] ?? ''));
if (!isset($validSlugs[$category])) $category = '';
$type = in_array(($result['type'] ?? ''), ['product', 'service'], true) ? $result['type'] : '';
$sort = in_array(($result['sort'] ?? ''), ['price_asc', 'price_desc', 'newest'], true) ? $result['sort'] : 'newest';
$terms = trim((string)($result['search_terms'] ?? $request));
$marketplaceUrl = APP_URL . '/buyer/marketplace.php?' . http_build_query(['q' => $terms, 'category' => $category, 'type' => $type, 'sort' => $sort]);

logAudit('AI_SHOPPING_ASSISTANT', 'Buyer used AI shopping assistant: ' . mb_substr($request, 0, 180), (int)$user['id']);
shoppingResponse(['success' => true, 'reply' => trim((string)($result['reply_somali'] ?? 'Waxaan kuu soo xulay alaabo ku habboon codsigaaga.')),
    'recommendations' => array_values($recommendations), 'marketplace_url' => $marketplaceUrl]);
