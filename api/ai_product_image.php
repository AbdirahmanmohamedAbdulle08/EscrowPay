<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gemini_helper.php';
header('Content-Type: application/json; charset=utf-8');
function imageResponse(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
$user = requireLogin(['seller']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') imageResponse(['success' => false, 'error' => 'POST request required.'], 405);
$title = trim(sanitize($_POST['title'] ?? ''));
if (mb_strlen($title) < 2) imageResponse(['success' => false, 'error' => 'Marka hore geli magaca alaabta.'], 422);
$source = 'real_online_image'; $found = null;
$terms = [$title, preg_replace('/\\b(kit|new|original|brand new)\\b/i', '', $title), preg_replace('/\\s+/', ' ', preg_replace('/[^a-z0-9 ]/i', ' ', $title))];
foreach (array_unique(array_filter(array_map('trim', $terms))) as $term) {
    $search = 'https://commons.wikimedia.org/w/api.php?action=query&generator=search&gsrsearch=' . urlencode($term) . '&gsrnamespace=6&gsrlimit=8&prop=imageinfo&iiprop=url|mime&iiurlwidth=900&format=json';
    $ch = curl_init($search); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_PROXY=>'', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'EscrowPay/1.0']); $raw = curl_exec($ch); curl_close($ch);
    foreach ((json_decode($raw ?: '', true)['query']['pages'] ?? []) as $page) { $info = $page['imageinfo'][0] ?? []; if (!empty($info['url']) && in_array(strtolower($info['mime'] ?? ''), ['image/jpeg','image/png','image/webp'], true)) { $found = $info; break 2; } }
}
if ($found) {
    $ch = curl_init($found['url']); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>15, CURLOPT_PROXY=>'', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'EscrowPay/1.0']); $binary = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$binary || $code < 200 || $code >= 300) $found = null;
}
if (!$found) {
    // Fast public image search fallback (Bing result metadata contains direct image URLs).
    $bing = 'https://www.bing.com/images/search?q=' . urlencode($title) . '&form=HDRSC2&first=1';
    $ch = curl_init($bing); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_PROXY=>'', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'Mozilla/5.0']); $html = curl_exec($ch); curl_close($ch);
    if ($html && preg_match_all('/"murl":"(https?:\\/\\/[^"\\]+)"/i', $html, $matches)) {
        foreach (array_unique($matches[1]) as $remote) {
            $remote = str_replace('\\/', '/', $remote); $ch = curl_init($remote); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>8, CURLOPT_PROXY=>'', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_USERAGENT=>'Mozilla/5.0']); $candidate = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $ctype = strtolower(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ''); curl_close($ch);
            if ($candidate && $code >= 200 && $code < 300 && str_starts_with($ctype, 'image/') && strlen($candidate) <= 5 * 1024 * 1024) { $binary = $candidate; $found = ['mime' => explode(';', $ctype)[0]]; break; }
        }
    }
}
if (!$found) {
    $prompt = "Create a clean, realistic catalogue product photo for: {$title}. Square studio composition, product only, no people, no text, no watermark.";
    $ai = generateGeminiProductImage($prompt);
    if (!$ai['success']) imageResponse(['success' => false, 'error' => 'Sawir dhab ah oo ku habboon magaca lama helin. Fadlan geli sawir ama link online ah.'], 404);
    $binary = base64_decode($ai['data'], true); $source = 'gemini_generated_image';
}
if ($binary === false || strlen($binary) < 100) imageResponse(['success' => false, 'error' => 'Sawirka AI ma saxna.'], 503);
$mimeMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
$extension = $source === 'real_online_image' ? ($mimeMap[strtolower($found['mime'])] ?? 'jpg') : ($mimeMap[strtolower($ai['mime_type'])] ?? 'png');
$dir = __DIR__ . '/../uploads/products';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) imageResponse(['success' => false, 'error' => 'Kaydka sawirka lama diyaarin karo.'], 500);
$filename = 'product_ai_' . (int)$user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
if (file_put_contents($dir . '/' . $filename, $binary) === false) imageResponse(['success' => false, 'error' => 'Sawirka lama kaydin karo.'], 500);
$path = 'uploads/products/' . $filename;
imageResponse(['success' => true, 'image_path' => $path, 'preview_url' => APP_URL . '/' . $path, 'source' => $source]);
