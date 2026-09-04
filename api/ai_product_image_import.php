<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
function importResponse(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
$user = requireLogin(['seller']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') importResponse(['success' => false, 'error' => 'POST request required.'], 405);
$url = trim($_POST['image_url'] ?? '');
if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) importResponse(['success' => false, 'error' => 'Geli link sawir oo sax ah (http/https).'], 422);
$host = parse_url($url, PHP_URL_HOST);
if (!$host || filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) importResponse(['success' => false, 'error' => 'Link-ga sawirka lama oggola.'], 422);
function downloadRemote(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'EscrowPay Product Import/1.0']);
    $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: ''; $err = curl_error($ch); curl_close($ch);
    return [$data, $code, $type, $err];
}
[$data, $code, $type, $err] = downloadRemote($url);
if ($err || $code < 200 || $code >= 300 || !$data) importResponse(['success' => false, 'error' => 'Link-ga rasmiga ah lama soo dejin karo.'], 422);
if (str_contains(strtolower($type), 'text/html')) {
    $match = [];
    $found = preg_match('/<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]+content=["\']([^"\']+)["\']/i', $data, $match)
        || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\']/i', $data, $match);
    if (!$found) importResponse(['success' => false, 'error' => 'Boggan ma laha sawir product oo rasmi ah. Geli link sawirka tooska ah.'], 422);
    $imageUrl = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    if (str_starts_with($imageUrl, '//')) $imageUrl = 'https:' . $imageUrl;
    if (str_starts_with($imageUrl, '/')) $imageUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . $imageUrl;
    if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) importResponse(['success' => false, 'error' => 'Sawirka product-ka ee bogga lama aqbali karo.'], 422);
    [$data, $code, $type, $err] = downloadRemote($imageUrl);
    if ($err || $code < 200 || $code >= 300 || !$data) importResponse(['success' => false, 'error' => 'Sawirka rasmiga ah lama soo dejin karo.'], 422);
}
if (strlen($data) > 5 * 1024 * 1024) importResponse(['success' => false, 'error' => 'Sawirka online waa ka weyn yahay 5MB.'], 422);
$image = @imagecreatefromstring($data);
if (!$image) importResponse(['success' => false, 'error' => 'Link-gu ma aha sawir sax ah.'], 422);
imagedestroy($image);
$type = strtolower(trim(explode(';', $type)[0])); $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$type] ?? 'jpg';
$dir = __DIR__ . '/../uploads/products'; if (!is_dir($dir) && !mkdir($dir, 0755, true)) importResponse(['success' => false, 'error' => 'Kaydka sawirka lama diyaarin karo.'], 500);
$name = 'product_ai_' . (int)$user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
if (file_put_contents($dir . '/' . $name, $data) === false) importResponse(['success' => false, 'error' => 'Sawirka lama kaydin karo.'], 500);
$path = 'uploads/products/' . $name;
importResponse(['success' => true, 'image_path' => $path, 'preview_url' => APP_URL . '/' . $path]);
