<?php
/** AI Magic Product Lister (Phase 1) */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gemini_helper.php';

header('Content-Type: application/json; charset=utf-8');
function respond(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

requireLogin(['seller']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['success' => false, 'error' => 'POST request required.'], 405);
if (empty($_FILES['product_image']) || $_FILES['product_image']['error'] !== UPLOAD_ERR_OK) respond(['success' => false, 'error' => 'Fadlan dooro sawir sax ah.'], 422);
if (empty(getGeminiApiKey())) respond(['success' => false, 'error' => 'AI key lama helin.'], 503);

$file = $_FILES['product_image'];
if ($file['size'] > 5 * 1024 * 1024) respond(['success' => false, 'error' => 'Sawirku waa inuu ka yar yahay 5MB.'], 422);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extensions[$mime])) respond(['success' => false, 'error' => 'Isticmaal JPG, PNG, ama WEBP.'], 422);

$dir = __DIR__ . '/../uploads/products';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) respond(['success' => false, 'error' => 'Lama diyaarin karo kaydka sawirka.'], 500);
$filename = 'product_ai_' . (int)$_SESSION['user_id'] . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
$absolutePath = $dir . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $absolutePath)) respond(['success' => false, 'error' => 'Sawirka lama kaydin karo.'], 500);
$imagePath = 'uploads/products/' . $filename;

$categories = getDB()->query('SELECT name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_COLUMN);
$categoryList = implode(', ', $categories ?: ['General']);
$prompt = "Waxaad tahay AI Magic Product Lister ee suuq Soomaaliyeed. Falanqee sawirka alaabta ee ku lifaaqan. Soo celi JSON oo keliya, adigoon markdown isticmaalin, qaabkan saxda ah: {\"title\":\"cinwaan sax ah oo Af-Soomaali ah\",\"category\":\"hal category\",\"price\":25,\"description\":\"sharraxaad xayeysiin ah oo Af-Soomaali ah\",\"type\":\"product\"}. Categories-ka la oggol yahay: {$categoryList}. Cinwaanka waa inuu sheegaa nooca, brand/model iyo xaalad keliya haddii sawirka ka muuqato. Qiimaha waa qiime suuqeed oo USD ah oo qiyaas macquul ah; haddii model/condition aanu muuqan, si taxaddar leh u qiyaas. Sharraxaadda had iyo jeer ka dhig xayeysiin soo jiidasho leh oo 2-4 weedhood ah: sheeg faa'iidada iyo cidda ku habboon, una yeer buyer-ka inuu dalbado. Ku dar waxa muuqda oo keliya, hana samayn xaqiiqo aan sawirku caddayn. Nooca ha noqdo product ama service.";
$ai = callGeminiAI($prompt, [$absolutePath], 'gemini-3.6-flash', true);
$ai = $ai['success'] ? $ai : callGeminiAI($prompt, [$absolutePath], 'gemini-flash-latest', true);
$result = null;
if ($ai['success']) { $json = preg_replace('/^```(?:json)?\\s*|\\s*```$/i', '', trim($ai['text'])); $candidate = json_decode($json, true); if (is_array($candidate) && !empty($candidate['title'])) $result = $candidate; }
if (!$result) { @unlink($absolutePath); respond(['success' => false, 'error' => $ai['success'] ? 'AI waxay soo celisay xog aan sax ahayn. Mar kale isku day.' : 'AI lama xiriiri karo: ' . ($ai['error'] ?? 'Fadlan hubi Gemini API key iyo internet-ka server-ka.')], 503); }
$category = trim((string)($result['category'] ?? ''));
foreach ($categories as $allowed) { if (mb_strtolower($allowed) === mb_strtolower($category)) { $category = $allowed; break; } }
if (!in_array($category, $categories, true)) $category = $categories[0] ?? 'General';
respond(['success' => true, 'image_path' => $imagePath, 'preview_url' => APP_URL . '/' . $imagePath, 'listing' => ['title' => mb_substr(trim((string)$result['title']), 0, 255), 'category' => $category, 'price' => max(1, (float)($result['price'] ?? 25)), 'description' => trim((string)($result['description'] ?? '')), 'type' => ($result['type'] ?? 'product') === 'service' ? 'service' : 'product']]);
